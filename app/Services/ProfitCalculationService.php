<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Revenue / COGS / gross-profit math for the owner-facing Summary screens.
 *
 * THE CHAIN, in the one order every consumer must present it:
 *
 *   Gross Revenue  sum of order_items.subtotal — the menu price, before any
 *                  discount was applied.
 *   Discounts      sum of orders.discount_amount, counted once per order.
 *                  PWD/Senior and voucher discounts share that one column;
 *                  orders.discount_type says which kind it was.
 *   Net Revenue    Gross Revenue - Discounts. What the till actually took,
 *                  and equal to sum(orders.total) since tax is always 0.
 *   COGS           the snapshotted ingredient cost, see below.
 *   Gross Profit   Net Revenue - COGS.
 *   Margin         Gross Profit / NET Revenue.
 *
 * Gross Profit used to be Gross Revenue - COGS, which reported money the
 * business had given away as money it had kept. On the live database that
 * overstated all-time profit by PHP 1,296. Every figure above now comes out
 * of ONE traversal of ONE dataset, so the screen, the printed report and the
 * CSV export are structurally incapable of disagreeing.
 *
 * COGS is read from order_items.ingredient_cost — the single-unit recipe cost
 * SNAPSHOTTED at the moment InventoryDeductionService::deductWithLock() ran
 * (i.e. when the order was completed). That number never changes after the
 * fact, so a profit report for last month stays correct even after inventory
 * prices move today. See InventoryDeductionService::deductWithLock().
 *
 * Legacy rows predating that column (or any row that slipped through with a
 * NULL/zero snapshot) fall back to today's MenuItemCosting figure for that
 * menu item — an approximation, not a time machine, but better than silently
 * treating the line as free.
 */
class ProfitCalculationService
{
    public function __construct(private MenuItemCosting $costing)
    {
    }

    /**
     * @param  int|string  $branchScope  Branch id, or 'all' (default) for every branch.
     * @return array{
     *     gross_revenue: float,
     *     discounts: float,
     *     net_revenue: float,
     *     cogs: float,
     *     gross_profit: float,
     *     margin_percent: float|null,
     *     order_count: int,
     *     item_count: int,
     *     legacy_fallback_count: int,
     *     period_start: string,
     *     period_end: string,
     *     items: list<array{
     *         menu_item_id: int|null,
     *         name: string,
     *         quantity: int,
     *         revenue: float,
     *         cost: float,
     *         profit: float,
     *         margin_percent: float|null,
     *     }>,
     * }
     */
    public function forRange(CarbonInterface $start, CarbonInterface $end, $branchScope = 'all'): array
    {
        $items = $this->lineItemsQuery($start, $end, $branchScope)
            ->with('menuItem')
            ->get();

        return $this->summarize($items, $start, $end);
    }

    /**
     * The ONE definition of "which order lines count as sales in this period".
     *
     * Every figure this class reports starts here: the Summary KPIs, the
     * printed breakdown, and — since ordersForRange() below — the CSV export
     * too. A printed report and an exported spreadsheet that disagree about
     * the period, the status filter or the branch scope is exactly the bug
     * this method exists to make impossible.
     *
     * The period is bounded by orders.completed_at, NOT created_at: a sale
     * belongs to the day the money was taken, not the day the ticket was
     * opened. An order placed 11:55 PM and completed 12:10 AM is one sale on
     * the second day, in every view of the data.
     *
     * @param  int|string  $branchScope
     * @return \Illuminate\Database\Eloquent\Builder<OrderItem>
     */
    private function lineItemsQuery(CarbonInterface $start, CarbonInterface $end, $branchScope)
    {
        return OrderItem::query()
            // The parent order's discount rides along on every line, so the
            // whole Gross -> Discounts -> Net chain comes out of the ONE
            // traversal below. It is an order-level figure, so summarize()
            // counts it once per distinct order, never once per line.
            ->select('order_items.*', 'orders.discount_amount as order_discount_amount')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'completed')
            ->whereBetween('orders.completed_at', [$start, $end])
            ->when($branchScope !== 'all', fn ($q) => $q->where('orders.branch_id', $branchScope));
    }

    /**
     * The same period, the same lines and the same cost rule as forRange(),
     * rolled up per ORDER instead of per menu item.
     *
     * This is what the CSV export is built from. Because both shapes traverse
     * the rows lineItemsQuery() returns and price them through the one
     * unitIngredientCost() rule, summing this method's revenue / cogs /
     * gross_profit columns reproduces forRange()'s totals exactly. The
     * agreement is structural — not a coincidence anybody has to re-check by
     * hand after every change.
     *
     * Each row carries the whole chain for that one order: gross_revenue,
     * discount, net_revenue, cogs, gross_profit. amount_charged (orders.total)
     * is carried too and equals net_revenue by construction — it is the till's
     * own record of the same sale, kept as a cross-check on the derivation.
     *
     * @param  int|string  $branchScope
     * @return list<array{
     *     order_number: string,
     *     completed_at: \Carbon\CarbonInterface|null,
     *     type: string|null,
     *     table_number: string|null,
     *     items: string,
     *     gross_revenue: float,
     *     discount: float,
     *     net_revenue: float,
     *     cogs: float,
     *     gross_profit: float,
     *     margin_percent: float|null,
     *     amount_charged: float,
     *     payment_method: string|null,
     *     status: string,
     * }>
     */
    public function ordersForRange(CarbonInterface $start, CarbonInterface $end, $branchScope = 'all'): array
    {
        $items = $this->lineItemsQuery($start, $end, $branchScope)
            ->with(['menuItem', 'order'])
            ->get();

        $legacyFallbackCount = 0;
        $orders = [];

        foreach ($items as $item) {
            $order = $item->order;

            if (!$order) {
                // The join guarantees a parent row; this can only fire if one
                // is deleted mid-request. Nothing to attribute the line to.
                continue;
            }

            $id = $order->id;

            if (!isset($orders[$id])) {
                $orders[$id] = [
                    'order_number'   => (string) $order->order_number,
                    'completed_at'   => $order->completed_at,
                    'type'           => $order->type,
                    'table_number'   => $order->table_number,
                    'lines'          => [],
                    'gross_revenue'  => 0.0,
                    'cogs'           => 0.0,
                    'discount'       => (float) $order->discount_amount,
                    'amount_charged' => (float) $order->total,
                    'payment_method' => $order->payment_method,
                    'status'         => (string) $order->status,
                ];
            }

            $orders[$id]['lines'][] = (int) $item->quantity . 'x ' . $item->item_name;
            $orders[$id]['gross_revenue'] += (float) $item->subtotal;
            $orders[$id]['cogs'] += $this->unitIngredientCost($item, $legacyFallbackCount)
                * (int) $item->quantity;
        }

        $rows = array_map(function (array $row): array {
            $grossRevenue = round($row['gross_revenue'], 2);
            $discount = round($row['discount'], 2);
            $netRevenue = round($grossRevenue - $discount, 2);
            $cogs = round($row['cogs'], 2);
            // The same chain as summarize(), one order at a time — which is
            // why summing this column reproduces the period figure exactly.
            $profit = round($netRevenue - $cogs, 2);

            return [
                'order_number'   => $row['order_number'],
                'completed_at'   => $row['completed_at'],
                'type'           => $row['type'],
                'table_number'   => $row['table_number'],
                'items'          => implode(', ', $row['lines']),
                'gross_revenue'  => $grossRevenue,
                'discount'       => $discount,
                'net_revenue'    => $netRevenue,
                'cogs'           => $cogs,
                'gross_profit'   => $profit,
                'margin_percent' => $netRevenue > 0 ? round($profit / $netRevenue * 100, 1) : null,
                'amount_charged' => round($row['amount_charged'], 2),
                'payment_method' => $row['payment_method'],
                'status'         => $row['status'],
            ];
        }, array_values($orders));

        // Newest first, as the export has always read.
        usort(
            $rows,
            fn (array $a, array $b) => (optional($b['completed_at'])->getTimestamp() ?? 0)
                <=> (optional($a['completed_at'])->getTimestamp() ?? 0)
        );

        return $rows;
    }

    /** @param  int|string  $branchScope */
    public function today($branchScope = 'all'): array
    {
        return $this->forRange(Carbon::today()->startOfDay(), Carbon::today()->endOfDay(), $branchScope);
    }

    /** @param  int|string  $branchScope */
    public function thisWeek($branchScope = 'all'): array
    {
        return $this->forRange(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek(), $branchScope);
    }

    /** @param  int|string  $branchScope */
    public function thisMonth($branchScope = 'all'): array
    {
        return $this->forRange(Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), $branchScope);
    }

    /**
     * Custom inclusive date range, e.g. from date-picker inputs
     * ('2026-08-01', '2026-08-31'). Time is normalised to the full day.
     *
     * @param  int|string  $branchScope
     */
    public function custom(string $startDate, string $endDate, $branchScope = 'all'): array
    {
        return $this->forRange(
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay(),
            $branchScope
        );
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     */
    private function summarize(Collection $items, CarbonInterface $start, CarbonInterface $end): array
    {
        $grossRevenue = 0.0;
        $discounts = 0.0;
        $cogs = 0.0;
        $legacyFallbackCount = 0;
        $orderIds = [];

        // Per-menu-item accumulators, filled in the SAME pass as the period
        // totals below. The printed breakdown and the on-screen KPIs are two
        // views of one traversal of one dataset, so they cannot be two
        // different calculations: every peso added to $revenue/$cogs is added
        // to exactly one row here as well.
        $rows = [];

        foreach ($items as $item) {
            $lineRevenue = (float) $item->subtotal;
            $unitCost = $this->unitIngredientCost($item, $legacyFallbackCount);
            $lineCost = $unitCost * (int) $item->quantity;

            $grossRevenue += $lineRevenue;
            $cogs += $lineCost;

            // The discount belongs to the ORDER, not the line. Counting it the
            // first time we meet each order is what keeps a two-line order
            // from having its discount subtracted twice.
            if (!isset($orderIds[$item->order_id])) {
                $discounts += (float) ($item->order_discount_amount ?? 0);
            }

            $orderIds[$item->order_id] = true;

            // Group by menu item where there still is one; a hard-deleted item
            // groups by the order line's own name snapshot so its sales are
            // still reported rather than silently dropped from the document.
            $key = $item->menu_item_id !== null
                ? 'id:' . $item->menu_item_id
                : 'name:' . (string) $item->item_name;

            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'menu_item_id' => $item->menu_item_id,
                    'name'         => (string) ($item->item_name ?: ($item->menuItem->name ?? '(deleted item)')),
                    'quantity'     => 0,
                    'revenue'      => 0.0,
                    'cost'         => 0.0,
                ];
            }

            $rows[$key]['quantity'] += (int) $item->quantity;
            $rows[$key]['revenue']  += $lineRevenue;
            $rows[$key]['cost']     += $lineCost;
        }

        // Net Revenue is what the till actually took: the pre-discount total
        // minus everything given away. Gross Profit and Margin are both built
        // on it, because a peso discounted is a peso the business never had.
        $netRevenue = $grossRevenue - $discounts;
        $grossProfit = $netRevenue - $cogs;

        $breakdown = array_map(function (array $row): array {
            $profit = $row['revenue'] - $row['cost'];

            return [
                'menu_item_id'   => $row['menu_item_id'],
                'name'           => $row['name'],
                'quantity'       => $row['quantity'],
                'revenue'        => round($row['revenue'], 2),
                'cost'           => round($row['cost'], 2),
                'profit'         => round($profit, 2),
                // Same guard as the period margin: no revenue, no margin —
                // and never a division by zero.
                'margin_percent' => $row['revenue'] > 0 ? round($profit / $row['revenue'] * 100, 1) : null,
            ];
        }, array_values($rows));

        // Revenue descending, so the printed table reads as Top Selling
        // extended to everything rather than an arbitrary order.
        usort($breakdown, fn (array $a, array $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'gross_revenue'         => round($grossRevenue, 2),
            'discounts'             => round($discounts, 2),
            'net_revenue'           => round($netRevenue, 2),
            'cogs'                  => round($cogs, 2),
            'gross_profit'          => round($grossProfit, 2),
            // Margin is a share of the money actually taken, never of the
            // pre-discount figure. Every label that prints it says so.
            'margin_percent'        => $netRevenue > 0 ? round($grossProfit / $netRevenue * 100, 1) : null,
            'order_count'           => count($orderIds),
            'item_count'            => $items->count(),
            'legacy_fallback_count' => $legacyFallbackCount,
            'period_start'          => $start->toDateTimeString(),
            'period_end'            => $end->toDateTimeString(),
            'items'                 => $breakdown,
        ];
    }

    /**
     * Single-unit ingredient cost for one order line.
     *
     * Prefers the historical snapshot (order_items.ingredient_cost). Falls
     * back to today's MenuItemCosting figure only when the snapshot is
     * missing (NULL) or exactly zero AND the menu item itself is still
     * resolvable — a genuinely free/zero-cost recipe and "we never recorded
     * a cost" are indistinguishable once the value is 0, so we accept that
     * ambiguity in favour of never treating a real line as free-of-charge.
     */
    private function unitIngredientCost(OrderItem $item, int &$legacyFallbackCount): float
    {
        $snapshot = $item->ingredient_cost;

        if ($snapshot !== null && (float) $snapshot > 0) {
            return (float) $snapshot;
        }

        if ($item->menuItem) {
            $legacyFallbackCount++;
            return $this->costing->costFor($item->menuItem);
        }

        // No snapshot and the menu item is gone too (hard-deleted, not just
        // archived) — nothing left to estimate from.
        return (float) ($snapshot ?? 0);
    }
}

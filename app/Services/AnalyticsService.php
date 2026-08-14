<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * AnalyticsService — read-only descriptive analytics + rule-based
 * recommendations over existing tables (orders, order_items, menu_items,
 * inventory, stock_movements, branches, categories).
 *
 * This class does NOT use machine learning or predictive AI. It uses
 * standard SQL aggregations and threshold rules (see docs/ANALYTICS_METHODOLOGY.md).
 */
class AnalyticsService
{
    /** @var int|string branch id, or the string 'all' for global scope */
    private $branchScope;

    public function __construct($branchScope = 'all')
    {
        $this->branchScope = $branchScope;
    }

    // ══════════ Branch scoping helpers ══════════

    private function applyOrderBranchScope($query)
    {
        if ($this->branchScope !== 'all') {
            $query->where('branch_id', $this->branchScope);
        }
        return $query;
    }

    private function applyInventoryBranchScope($query)
    {
        if ($this->branchScope !== 'all') {
            $query->where('branch_id', $this->branchScope);
        }
        return $query;
    }

    // ══════════ Sales aggregates ══════════

    public function salesToday(): float
    {
        return (float) $this->applyOrderBranchScope(
            Order::where('status', 'completed')->whereDate('completed_at', today())
        )->sum('total');
    }

    public function salesYesterday(): float
    {
        return (float) $this->applyOrderBranchScope(
            Order::where('status', 'completed')->whereDate('completed_at', today()->subDay())
        )->sum('total');
    }

    public function salesThisWeek(): float
    {
        return (float) $this->applyOrderBranchScope(
            Order::where('status', 'completed')
                ->whereBetween('completed_at', [now()->startOfWeek(), now()->endOfWeek()])
        )->sum('total');
    }

    public function salesLastWeek(): float
    {
        return (float) $this->applyOrderBranchScope(
            Order::where('status', 'completed')
                ->whereBetween('completed_at', [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()])
        )->sum('total');
    }

    public function salesThisMonth(): float
    {
        return (float) $this->applyOrderBranchScope(
            Order::where('status', 'completed')
                ->whereMonth('completed_at', now()->month)
                ->whereYear('completed_at', now()->year)
        )->sum('total');
    }

    public function salesLastMonth(): float
    {
        $last = now()->subMonth();
        return (float) $this->applyOrderBranchScope(
            Order::where('status', 'completed')
                ->whereMonth('completed_at', $last->month)
                ->whereYear('completed_at', $last->year)
        )->sum('total');
    }

    /**
     * Percentage change between two periods.
     * Formula: ((current - previous) / previous) × 100
     * Returns null when previous = 0 (undefined growth — avoid divide-by-zero).
     */
    public function percentChange(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }

    // ══════════ Best / Least sellers (last 30 days, completed only) ══════════

    public function bestSellers(int $limit = 5)
    {
        $branchScope = $this->branchScope;
        return OrderItem::query()
            ->select('menu_item_id', DB::raw('SUM(quantity) as total_qty'))
            ->whereNotNull('menu_item_id')
            ->whereHas('order', function ($q) use ($branchScope) {
                $q->where('status', 'completed')
                    ->whereDate('completed_at', '>=', now()->subDays(30));
                if ($branchScope !== 'all') {
                    $q->where('branch_id', $branchScope);
                }
            })
            ->groupBy('menu_item_id')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->with('menuItem')
            ->get();
    }

    public function leastSellers(int $limit = 5)
    {
        $branchScope = $this->branchScope;
        return OrderItem::query()
            ->select('menu_item_id', DB::raw('SUM(quantity) as total_qty'))
            ->whereNotNull('menu_item_id')
            ->whereHas('order', function ($q) use ($branchScope) {
                $q->where('status', 'completed')
                    ->whereDate('completed_at', '>=', now()->subDays(30));
                if ($branchScope !== 'all') {
                    $q->where('branch_id', $branchScope);
                }
            })
            ->whereHas('menuItem', fn($q) => $q->where('is_available', true))
            ->groupBy('menu_item_id')
            ->orderBy('total_qty')
            ->limit($limit)
            ->with('menuItem')
            ->get();
    }

    // ══════════ Inventory analytics ══════════

    public function outOfStock()
    {
        return $this->applyInventoryBranchScope(
            Inventory::query()->where('is_active', true)->where('quantity', '<=', 0)
        )->orderBy('item_name')->get();
    }

    public function lowStock()
    {
        return $this->applyInventoryBranchScope(
            Inventory::query()
                ->where('is_active', true)
                ->where('quantity', '>', 0)
                ->whereColumn('quantity', '<=', 'low_stock_alert')
        )->orderBy('item_name')->get();
    }

    /**
     * Slow movers — items active and with stock, but no stock_movements
     * record in the last 30 days.
     */
    public function slowMovers()
    {
        $cutoff = now()->subDays(30);

        $recentlyMovedIds = StockMovement::query()
            ->where('created_at', '>=', $cutoff)
            ->pluck('inventory_id')
            ->unique()
            ->toArray();

        $base = Inventory::query()
            ->where('is_active', true)
            ->where('quantity', '>', 0)
            ->whereNotIn('id', $recentlyMovedIds);

        if ($this->branchScope !== 'all') {
            $base->where('branch_id', $this->branchScope);
        }

        return $base->orderBy('item_name')->get();
    }

    /**
     * Inventory items linked (via menu_items.inventory_item_id) to the
     * current top-selling menu items.
     */
    public function inventoryLinkedToBestSellers()
    {
        $bestMenuIds = $this->bestSellers(10)->pluck('menu_item_id');
        if ($bestMenuIds->isEmpty()) {
            return collect();
        }

        $linkedInvIds = MenuItem::whereIn('id', $bestMenuIds)
            ->whereNotNull('inventory_item_id')
            ->pluck('inventory_item_id')
            ->unique();

        if ($linkedInvIds->isEmpty()) {
            return collect();
        }

        $base = Inventory::whereIn('id', $linkedInvIds)->where('is_active', true);
        if ($this->branchScope !== 'all') {
            $base->where('branch_id', $this->branchScope);
        }
        return $base->orderBy('item_name')->get();
    }

    // ══════════ Trend chart (last N days) ══════════

    public function dailyTrend(int $days = 14): array
    {
        $labels = [];
        $values = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $labels[] = $date->format('M d');
            $values[] = (float) $this->applyOrderBranchScope(
                Order::where('status', 'completed')->whereDate('completed_at', $date->toDateString())
            )->sum('total');
        }
        return ['labels' => $labels, 'values' => $values];
    }

    // ══════════ Sales per branch (last 30 days) ══════════

    public function salesPerBranch()
    {
        return Branch::query()
            ->withSum(['orders as sales_30d' => function ($q) {
                $q->where('status', 'completed')
                    ->whereDate('completed_at', '>=', now()->subDays(30));
            }], 'total')
            ->orderByDesc('sales_30d')
            ->get();
    }

    // ══════════ Sales by category (last 30 days) ══════════

    public function salesByCategory()
    {
        $q = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->join('categories', 'menu_items.category_id', '=', 'categories.id')
            ->where('orders.status', 'completed')
            ->whereDate('orders.completed_at', '>=', now()->subDays(30))
            ->select('categories.name', DB::raw('SUM(order_items.quantity) as total_qty'))
            ->groupBy('categories.name')
            ->orderByDesc('total_qty');

        if ($this->branchScope !== 'all') {
            $q->where('orders.branch_id', $this->branchScope);
        }

        return $q->get();
    }

    // ══════════ Rule-based recommendations ══════════

    public function recommendations(): array
    {
        $recs = [];

        // Pre-compute inventory linked to best sellers (cross-reference)
        $bestSellers = $this->bestSellers(10);
        $bestMenuIds = $bestSellers->pluck('menu_item_id');
        $linkedInvIds = MenuItem::whereIn('id', $bestMenuIds)
            ->whereNotNull('inventory_item_id')
            ->pluck('inventory_item_id')
            ->unique()
            ->toArray();

        // Rule: out of stock
        foreach ($this->outOfStock() as $item) {
            $linked = in_array($item->id, $linkedInvIds);
            $recs[] = [
                'level'   => 'critical',
                'icon'    => 'bi-exclamation-octagon',
                'title'   => $item->item_name . ' is out of stock',
                'message' => $linked
                    ? 'Out of stock and linked to a high-selling menu item. Restock immediately.'
                    : 'This item is out of stock and should be restocked immediately.',
            ];
        }

        // Rule: low stock
        foreach ($this->lowStock() as $item) {
            $linked = in_array($item->id, $linkedInvIds);
            $recs[] = [
                'level'   => $linked ? 'critical' : 'warning',
                'icon'    => $linked ? 'bi-exclamation-octagon' : 'bi-exclamation-triangle',
                'title'   => $item->item_name . ' is low stock',
                'message' => $linked
                    ? 'This ingredient is linked to a high-selling menu item and should be monitored closely.'
                    : 'This item is low stock. Restocking is recommended.',
            ];
        }

        // Rule: dead / slow stock (cap to first 5 to avoid noise)
        foreach ($this->slowMovers()->take(5) as $item) {
            $recs[] = [
                'level'   => 'info',
                'icon'    => 'bi-hourglass',
                'title'   => $item->item_name . ' has no recent movement',
                'message' => 'This item has no recent movement and may be slow-moving stock.',
            ];
        }

        // Rule: best sellers — increase stock
        foreach ($bestSellers->take(3) as $row) {
            $name = optional($row->menuItem)->name ?? 'Menu item';
            $recs[] = [
                'level'   => 'success',
                'icon'    => 'bi-star',
                'title'   => $name . ' is a best seller',
                'message' => 'This menu item is a best seller. Consider increasing ingredient stock.',
            ];
        }

        // Rule: least sellers — promote / review
        foreach ($this->leastSellers(3) as $row) {
            $name = optional($row->menuItem)->name ?? 'Menu item';
            $recs[] = [
                'level'   => 'info',
                'icon'    => 'bi-graph-down',
                'title'   => $name . ' has low sales',
                'message' => 'This product has low sales. Consider a promotion or menu review.',
            ];
        }

        // Rule: today vs yesterday drop ≥ 20%
        $today = $this->salesToday();
        $yesterday = $this->salesYesterday();
        $deltaToday = $this->percentChange($today, $yesterday);
        if ($deltaToday !== null && $deltaToday <= -20) {
            $recs[] = [
                'level'   => 'warning',
                'icon'    => 'bi-graph-down-arrow',
                'title'   => 'Sales today are ' . $deltaToday . '% vs yesterday',
                'message' => 'Sales are lower than the previous period. Consider vouchers or promos.',
            ];
        }

        // Rule: this week vs last week drop ≥ 15%
        $weekDelta = $this->percentChange($this->salesThisWeek(), $this->salesLastWeek());
        if ($weekDelta !== null && $weekDelta <= -15) {
            $recs[] = [
                'level'   => 'warning',
                'icon'    => 'bi-calendar-week',
                'title'   => 'This week is trending ' . $weekDelta . '% vs last week',
                'message' => 'Weekly sales are softer than the previous period. Consider vouchers or promos.',
            ];
        }

        // Rule: branch leader (only when viewing All Branches)
        if ($this->branchScope === 'all') {
            $branchSales = $this->salesPerBranch();
            $avg = (float) $branchSales->avg('sales_30d');
            if ($avg > 0) {
                foreach ($branchSales as $b) {
                    if ((float) $b->sales_30d > 1.5 * $avg) {
                        $recs[] = [
                            'level'   => 'success',
                            'icon'    => 'bi-trophy',
                            'title'   => $b->name . ' has stronger sales',
                            'message' => 'This branch has stronger sales compared to other branches.',
                        ];
                    }
                }
            }
        }

        return $recs;
    }
}

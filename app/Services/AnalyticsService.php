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
    /**
     * Rolling window salesForecast() regresses over, in days.
     *
     * Public because App\Services\DemoSalesTopUp has to measure exactly the
     * same window this class does. These were previously bare literals in the
     * salesForecast() signature and body; anything measuring "will the forecast
     * render?" from the outside had to duplicate them and hope they stayed in
     * step. Naming them once removes that drift.
     */
    public const FORECAST_LOOKBACK_DAYS = 30;

    /**
     * Days within that window that must carry completed sales before a
     * regression is attempted. Below this, salesForecast() returns
     * insufficient_data instead of fitting a line to almost nothing.
     */
    public const FORECAST_MIN_DAYS_WITH_SALES = 5;

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
            ->select(
                'menu_item_id',
                DB::raw('SUM(quantity) as total_qty'),
                DB::raw('SUM(subtotal) as total_revenue')
            )
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

    /**
     * Menu items that CANNOT be made right now because at least one recipe
     * ingredient is short. Live snapshot, not date-scoped — "can we make this
     * today" only means something as of right now.
     *
     * Base recipe only (quantity 1, no selected options), same convention as
     * MenuItemCosting: an add-on is a per-order choice, not a standing
     * property of the item, so it must not count against the item itself.
     * Items with no recipe and no legacy ingredient link are skipped — stock
     * can't block something that isn't tracked against inventory.
     *
     * @return \Illuminate\Support\Collection<int, MenuItem>
     */
    public function menuItemsOutOfStock()
    {
        $deduction = app(\App\Services\InventoryDeductionService::class);

        $menuItems = MenuItem::query()
            ->where('is_available', true)
            ->when($this->branchScope !== 'all', fn ($q) => $q->where('branch_id', $this->branchScope))
            ->get();

        $short = collect();

        foreach ($menuItems as $menuItem) {
            $needs = $deduction->requirementsForLine($menuItem, 1, []);
            if (empty($needs)) {
                continue;
            }

            $inventory = Inventory::whereIn('id', array_keys($needs))->get()->keyBy('id');

            foreach ($needs as $inventoryId => $amount) {
                $inv = $inventory->get($inventoryId);
                if (!$inv || $amount > (float) $inv->quantity) {
                    $short->push($menuItem);
                    break;
                }
            }
        }

        return $short;
    }

    /**
     * Total ledger value of on-hand stock: SUM(quantity × unit_cost) over
     * active inventory rows. Same is_active scope as outOfStock()/lowStock()
     * so a discontinued ingredient doesn't inflate the figure.
     */
    public function inventoryAssetValue(): float
    {
        return (float) $this->applyInventoryBranchScope(
            Inventory::query()->where('is_active', true)
        )->selectRaw('COALESCE(SUM(quantity * unit_cost), 0) as total')->value('total');
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

    // ══════════ Sales forecast (Simple Linear Regression) ══════════

    /**
     * Forecast upcoming daily sales with Simple Linear Regression (SLR)
     * over a lookback window of completed-order totals.
     *
     * x = sequential day index (0..n-1) within the lookback window,
     * y = that day's completed-order total. See
     * docs/ANALYTICS_METHODOLOGY.md §11 for the formula and rationale.
     */
    public function salesForecast(?int $lookbackDays = null, int $forecastDays = 7): array
    {
        $lookbackDays = $lookbackDays ?? self::FORECAST_LOOKBACK_DAYS;

        $labels = [];
        $values = [];
        for ($i = $lookbackDays - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $labels[] = $date->format('M d');
            $values[] = (float) $this->applyOrderBranchScope(
                Order::where('status', 'completed')->whereDate('completed_at', $date->toDateString())
            )->sum('total');
        }

        $daysWithSales = count(array_filter($values, fn ($v) => $v > 0));

        if ($daysWithSales < self::FORECAST_MIN_DAYS_WITH_SALES) {
            return [
                'insufficient_data' => true,
                'lookback_days'     => $lookbackDays,
                'forecast_days'     => $forecastDays,
                'days_with_sales'   => $daysWithSales,
                'history'           => ['labels' => $labels, 'values' => $values],
            ];
        }

        $n = count($values);
        $xValues = range(0, $n - 1);

        $sumX = array_sum($xValues);
        $sumY = array_sum($values);
        $sumXY = 0;
        $sumX2 = 0;
        foreach ($xValues as $i => $x) {
            $sumXY += $x * $values[$i];
            $sumX2 += $x * $x;
        }

        // Denominator is 0 only when n <= 1, which can't happen here —
        // the insufficient-data guard above already returned for tiny windows.
        $slope     = (($n * $sumXY) - ($sumX * $sumY)) / (($n * $sumX2) - ($sumX * $sumX));
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        $forecast = [];
        for ($i = 0; $i < $forecastDays; $i++) {
            $x = $n + $i;
            $predicted = $intercept + ($slope * $x);
            $forecast[] = [
                'date'  => now()->addDays($i + 1)->toDateString(),
                'label' => now()->addDays($i + 1)->format('M d'),
                'value' => round(max(0, $predicted), 2),
            ];
        }

        // Trend label — epsilon relative to average daily sales (~0.5%)
        // so small day-to-day noise doesn't flip the label.
        $avgDailySales = $sumY / $n;
        $epsilon = $avgDailySales * 0.005;
        if (abs($slope) < $epsilon) {
            $trend = 'flat';
        } elseif ($slope > 0) {
            $trend = 'increasing';
        } else {
            $trend = 'decreasing';
        }

        // Low-sales alert — next day's forecast is more than 20% below
        // the trailing 7-day actual average.
        $trailingWindow = array_slice($values, -min(7, $n));
        $trailingAvg = array_sum($trailingWindow) / count($trailingWindow);
        $nextDayForecast = $forecast[0]['value'];
        $lowSalesAlert = $trailingAvg > 0 && $nextDayForecast < ($trailingAvg * 0.8);

        return [
            'insufficient_data' => false,
            'lookback_days'     => $lookbackDays,
            'forecast_days'     => $forecastDays,
            'slope'             => round($slope, 4),
            'intercept'         => round($intercept, 2),
            'trend'             => $trend,
            'low_sales_alert'   => $lowSalesAlert,
            'trailing_avg'      => round($trailingAvg, 2),
            'history'           => ['labels' => $labels, 'values' => $values],
            'forecast'          => $forecast,
        ];
    }

    // ══════════ Sales per branch (last 30 days) ══════════

    /** Lookback used by salesPerBranch() and branchPerformance(). */
    public const BRANCH_WINDOW_DAYS = 30;

    /**
     * A branch needs at least this many completed orders, spread over at least
     * BRANCH_MIN_DAYS distinct days, before it is ranked against the others.
     *
     * Below that, one large order or a single busy afternoon would decide the
     * "weakest branch" label, so branchPerformance() reports the branch as
     * having too little data rather than printing a percentage that reads as
     * meaningful and is not.
     */
    public const BRANCH_MIN_ORDERS = 5;
    public const BRANCH_MIN_DAYS   = 3;

    public function salesPerBranch()
    {
        return Branch::query()
            ->withSum(['orders as sales_30d' => function ($q) {
                $q->where('status', 'completed')
                    ->whereDate('completed_at', '>=', now()->subDays(self::BRANCH_WINDOW_DAYS));
            }], 'total')
            ->orderByDesc('sales_30d')
            ->get();
    }

    /**
     * Branch comparison over the same window salesPerBranch() already uses.
     *
     * Purely descriptive: every number below is a straight aggregate of
     * completed orders. Nothing is predicted, and no branch is given advice the
     * numbers do not support — a branch that cannot be compared is reported as
     * such instead of being ranked.
     */
    public function branchPerformance(): array
    {
        $since = now()->subDays(self::BRANCH_WINDOW_DAYS)->toDateString();

        $stats = DB::table('orders')
            ->select(
                'branch_id',
                DB::raw('SUM(total) as sales'),
                DB::raw('COUNT(*) as orders'),
                DB::raw('COUNT(DISTINCT DATE(completed_at)) as days_with_sales')
            )
            ->where('status', 'completed')
            ->whereDate('completed_at', '>=', $since)
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        $branches = [];

        foreach (Branch::orderBy('id')->get() as $branch) {
            $row = $stats->get($branch->id);

            $sales  = $row ? (float) $row->sales : 0.0;
            $orders = $row ? (int) $row->orders : 0;
            $days   = $row ? (int) $row->days_with_sales : 0;

            $branches[] = [
                'id'                  => $branch->id,
                'name'                => $branch->name,
                'code'                => strtoupper($branch->code),
                'is_active'           => (bool) $branch->is_active,
                'sales'               => round($sales, 2),
                'orders'              => $orders,
                'days_with_sales'     => $days,
                'comparable'          => $orders >= self::BRANCH_MIN_ORDERS
                                         && $days >= self::BRANCH_MIN_DAYS,
                'is_strongest'        => false,
                'is_weakest'          => false,
                'pct_below_strongest' => null,
            ];
        }

        // Rank on sales, highest first, so the table reads top to bottom.
        usort($branches, fn ($a, $b) => $b['sales'] <=> $a['sales']);

        $comparableKeys = [];
        foreach ($branches as $i => $b) {
            if ($b['comparable']) {
                $comparableKeys[] = $i;
            }
        }

        $strongest = null;
        $weakest   = null;

        if (count($comparableKeys) >= 1) {
            $first = $comparableKeys[0];
            $last  = $comparableKeys[count($comparableKeys) - 1];

            $strongest = $branches[$first];
            $topSales  = $branches[$first]['sales'];

            foreach ($comparableKeys as $i) {
                $branches[$i]['pct_below_strongest'] = $topSales > 0
                    ? round((($topSales - $branches[$i]['sales']) / $topSales) * 100, 1)
                    : 0.0;
            }

            // Strongest/weakest are only meaningful once there are two branches
            // to compare. With one, there is nothing to be strongest against.
            if (count($comparableKeys) >= 2) {
                $branches[$first]['is_strongest'] = true;
                $branches[$last]['is_weakest'] = true;
                $weakest = $branches[$last];
            } else {
                $strongest = null;
            }
        }

        return [
            'window_days'      => self::BRANCH_WINDOW_DAYS,
            'min_orders'       => self::BRANCH_MIN_ORDERS,
            'min_days'         => self::BRANCH_MIN_DAYS,
            'branches'         => $branches,
            'comparable_count' => count($comparableKeys),
            'strongest'        => $strongest,
            'weakest'          => $weakest,
            'insight'          => $this->branchInsight($branches, $comparableKeys, $strongest, $weakest),
        ];
    }

    /**
     * One honest sentence about what the numbers above actually show.
     * Every figure quoted here comes straight out of $branches.
     */
    private function branchInsight(array $branches, array $comparableKeys, ?array $strongest, ?array $weakest): string
    {
        $total = count($branches);
        $thin  = $total - count($comparableKeys);

        $thinNote = $thin > 0
            ? sprintf(
                ' %d of %d branch%s %s too little completed sales history in this window to compare (fewer than %d orders across %d days).',
                $thin,
                $total,
                $total === 1 ? '' : 'es',
                $thin === 1 ? 'has' : 'have',
                self::BRANCH_MIN_ORDERS,
                self::BRANCH_MIN_DAYS
            )
            : '';

        if (count($comparableKeys) === 0) {
            return 'No branch has enough completed sales in the last ' . self::BRANCH_WINDOW_DAYS
                . ' days to compare.' . $thinNote;
        }

        if (count($comparableKeys) === 1) {
            $only = $branches[$comparableKeys[0]];

            return sprintf(
                'Only %s has enough completed sales to report on: %s across %d orders on %d days. There is no second branch to compare it against.%s',
                $only['name'],
                '₱' . number_format($only['sales'], 2),
                $only['orders'],
                $only['days_with_sales'],
                $thinNote
            );
        }

        return sprintf(
            '%s leads with %s over the last %d days. %s is %s%% below it (%s).%s',
            $strongest['name'],
            '₱' . number_format($strongest['sales'], 2),
            self::BRANCH_WINDOW_DAYS,
            $weakest['name'],
            number_format($weakest['pct_below_strongest'], 1),
            '₱' . number_format($weakest['sales'], 2),
            $thinNote
        );
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

    // ══════════ Customer satisfaction (star ratings) ══════════

    /**
     * Average customer star rating over the last $days, scoped by branch.
     * Returns null when there are no ratings in the window (no ratings yet).
     */
    public function averageRating(int $days = 30): ?float
    {
        $q = DB::table('order_ratings')
            ->join('orders', 'order_ratings.order_id', '=', 'orders.id')
            ->where('order_ratings.created_at', '>=', now()->subDays($days));

        if ($this->branchScope !== 'all') {
            $q->where('orders.branch_id', $this->branchScope);
        }

        $avg = $q->avg('order_ratings.rating');

        return $avg !== null ? round((float) $avg, 2) : null;
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

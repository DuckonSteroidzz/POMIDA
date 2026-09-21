<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MenuItem;

/**
 * AnalyticsIntelligenceService — Phase 2d. The JOIN, not a new calculation.
 *
 * Everything this class reports is derived from results that three existing
 * services already produced:
 *
 *   ProductionCapacityService::forBranchScope()  current capacity + bottleneck
 *   DemandForecastService::forMenuItems()        moving-average demand forecast
 *   ProfitCalculationService::forRange()         revenue / COGS / profit / margin
 *
 * It computes NO revenue, NO forecast, NO capacity and NO bottleneck of its
 * own. Given the same three inputs it is a pure function, which is what lets
 * the screen, the print sheet and the CSV export be three renderings of ONE
 * result rather than three calculations that have to be checked against each
 * other by hand. The controller builds this once per request and hands the
 * same array to all three.
 *
 * NOT ARTIFICIAL INTELLIGENCE. There is no model here, trained or otherwise —
 * no ML, no inference, no probability. It is arithmetic plus a documented
 * threshold ladder, and every sentence it emits can be reproduced by hand from
 * the numbers in the same row. The vocabulary the UI must use is "Predictive
 * Analytics", "Automated Insights", "Data-Driven Recommendation", "Inventory
 * Intelligence" or "Decision Support" — never "AI".
 *
 * ────────────────────────────────────────────────────────────────────────
 * THE THREE FORMULAS
 * ────────────────────────────────────────────────────────────────────────
 *
 *   Potential Shortage = forecast demand over the horizon − current capacity
 *                        ONLY when that difference is positive. Capacity at or
 *                        above forecast is NOT a shortage of 0 orders; it is
 *                        the absence of a shortage, and the two are reported
 *                        differently so "0" never has to be interpreted.
 *
 *   Days of Coverage   = current production capacity ÷ average daily demand
 *                        (the menu item's moving-average forecast, in units
 *                        per day — the same number the Menu Demand Forecast
 *                        table prints).
 *
 *   Ingredient Daily   = Σ over the menu items whose recipe uses it of
 *   Usage                (that item's forecast units/day × its required
 *                        quantity per order). Each menu item contributes to
 *                        each ingredient exactly once, because the walk is
 *                        over one deduplicated requirement map per item — so
 *                        an ingredient shared by three items sums three
 *                        distinct demands rather than counting one of them
 *                        three times. Where SOME of the items that use an
 *                        ingredient have no forecast, the figure is a PARTIAL
 *                        one and is flagged as such (usage_is_partial) rather
 *                        than presented as the whole picture; where NONE do,
 *                        it is null, never 0.
 *
 * ────────────────────────────────────────────────────────────────────────
 * RISK LADDER — evaluated strictly top to bottom, first match wins
 * ────────────────────────────────────────────────────────────────────────
 *
 * Exactly six states, no others, and because the ladder short-circuits on the
 * first match two conditions can never produce contradictory statuses.
 *
 *  1. UNAVAILABLE        capacity cannot be calculated at all — the item has
 *                        no measurable recipe/ingredient configuration, or its
 *                        recipe reaches into another branch's inventory.
 *                        (ProductionCapacityService is_measurable === false.)
 *
 *  2. OUT OF STOCK       capacity <= 0: a required ingredient's AVAILABLE
 *                        quantity is at or below zero, so the item cannot be
 *                        produced right now. Checked BEFORE the forecast is
 *                        consulted, deliberately — this is a fact about
 *                        today's shelf and does not depend on sales history.
 *                        Phase 1 measured Branches 1-3 with no recent selling
 *                        days at all; if this sat below INSUFFICIENT DATA, a
 *                        genuinely unproducible item in those branches would
 *                        be masked by the absence of a forecast.
 *
 *  3. INSUFFICIENT DATA  the item IS producible (capacity > 0) but has no
 *                        menu-level forecast, so demand-vs-capacity cannot be
 *                        assessed. No shortage, no coverage and no risk
 *                        verdict are invented for it.
 *
 *  4. CRITICAL           producible and forecastable, and coverage is below
 *                        COVERAGE_CRITICAL_DAYS — current capacity does not
 *                        cover even one day of projected demand.
 *
 *  5. LOW                producible and forecastable, and either coverage is
 *                        below COVERAGE_LOW_DAYS (equivalently: a potential
 *                        shortage exists over the horizon) or capacity is at
 *                        or below the EXISTING inventory low-stock threshold,
 *                        config('inventory.low_stock_threshold').
 *
 *  6. GOOD               capacity covers the whole projected horizon and no
 *                        low-stock condition applies.
 *
 * THE EXACT THRESHOLDS, and why they are these:
 *
 *   COVERAGE_CRITICAL_DAYS = 1.0 day. Below one day of projected demand the
 *   item is expected to run out before the next trading day ends — the point
 *   at which "restock soon" becomes "restock today". It is also the threshold
 *   the inventory insight is worded against ("may support less than 1 day of
 *   projected demand").
 *
 *   COVERAGE_LOW_DAYS = DemandForecastService::FORECAST_HORIZON_DAYS = 7 days.
 *   Not an invented number: it is exactly the horizon the forecast projects
 *   over, so "LOW" means precisely "current capacity does not cover the period
 *   this page is projecting". That equivalence is what keeps the Risk column
 *   and the Potential Shortage column from ever disagreeing — coverage < 7 and
 *   shortage > 0 are the same statement:
 *
 *       shortage > 0  <=>  7 x perDay > capacity  <=>  capacity/perDay < 7
 *
 *   The low-stock floor REUSES config('inventory.low_stock_threshold') (3 by
 *   default) rather than inventing a second idea of "low". The Menu Production
 *   Capacity table has always badged capacity <= that value as "Low Stock";
 *   without the floor, a slow-selling item with 2 units of capacity and 20 days
 *   of coverage would be badged "Low Stock" in one column and "GOOD" in the
 *   next one on the same row. The floor can only ever raise GOOD to LOW; it
 *   never lowers a CRITICAL, and it is never consulted for the three states
 *   above it.
 *
 * ────────────────────────────────────────────────────────────────────────
 * INSUFFICIENT IS NOT ZERO
 * ────────────────────────────────────────────────────────────────────────
 *
 * Carried through from DemandForecastService unchanged. Where a forecast is
 * unavailable, potential_shortage and coverage_days are NULL and the row says
 * INSUFFICIENT DATA. They are never 0, because a 0 shortage asserts "we have
 * enough" and a 0 coverage asserts "we run out today", and neither is
 * something an absent forecast can support. An item that never sold is not
 * evidence of zero demand: it still appears with its capacity, its bottleneck
 * and its recipe analysis, with demand marked unavailable.
 *
 * ────────────────────────────────────────────────────────────────────────
 * BRANCH SCOPE
 * ────────────────────────────────────────────────────────────────────────
 *
 * This class never reads a request. $branchScope arrives from
 * ResolvesBranchScope::getSelectedBranch() by way of the controller, exactly
 * as it does for the three services whose output is joined here — and every
 * one of those outputs was already scoped before it got here, so there is no
 * path by which a row from outside the scope can enter. The only queries this
 * class takes at all are the two branch-labelling lookups under 'all', and
 * both are keyed by ids that are already in scope.
 *
 * What is deliberately NOT built here: any per-branch total, ranking or
 * comparison. That shape was removed from this page by the Phase 3 audit as a
 * cross-branch disclosure risk and must not return. Under 'all' the rows stay
 * per menu item, each labelled with its own branch so two same-named items are
 * told apart — which is a distinction, not a comparison.
 *
 * See docs/ANALYTICS_METHODOLOGY.md for the written-up methodology.
 */
class AnalyticsIntelligenceService
{
    // ══════════ Risk states — these six and no others ══════════

    public const RISK_UNAVAILABLE       = 'unavailable';
    public const RISK_OUT_OF_STOCK      = 'out_of_stock';
    public const RISK_INSUFFICIENT_DATA = 'insufficient_data';
    public const RISK_CRITICAL          = 'critical';
    public const RISK_LOW               = 'low';
    public const RISK_GOOD              = 'good';

    /** Display labels. One place, so screen, paper and CSV cannot word them differently. */
    public const RISK_LABELS = [
        self::RISK_UNAVAILABLE       => 'Unavailable',
        self::RISK_OUT_OF_STOCK      => 'Out of Stock',
        self::RISK_INSUFFICIENT_DATA => 'Insufficient Data',
        self::RISK_CRITICAL          => 'Critical',
        self::RISK_LOW               => 'Low',
        self::RISK_GOOD              => 'Good',
    ];

    /**
     * Ladder order, most severe first. Used for sorting and for the
     * "items requiring attention" count; it is NOT the classification itself,
     * which is the top-to-bottom cascade in deriveRisk().
     */
    public const RISK_SEVERITY = [
        self::RISK_OUT_OF_STOCK      => 0,
        self::RISK_CRITICAL          => 1,
        self::RISK_LOW               => 2,
        self::RISK_INSUFFICIENT_DATA => 3,
        self::RISK_UNAVAILABLE       => 4,
        self::RISK_GOOD              => 5,
    ];

    /** Below this many days of coverage, the item is CRITICAL. See the class docblock. */
    public const COVERAGE_CRITICAL_DAYS = 1.0;

    /**
     * Below this many days of coverage, the item is LOW. Aliased from the
     * forecast horizon rather than restated, so "does not cover the projected
     * period" cannot come to mean two different lengths of period.
     */
    public const COVERAGE_LOW_DAYS = DemandForecastService::FORECAST_HORIZON_DAYS;

    /** Coverage could not be divided: no forecast for this item. */
    public const COVERAGE_INSUFFICIENT = 'insufficient_data';

    /** Coverage could not be divided: capacity itself is unmeasurable. */
    public const COVERAGE_UNAVAILABLE = 'unavailable';

    /**
     * Coverage could not be divided: projected demand is zero, so capacity
     * would last indefinitely. Reported as Not Applicable rather than as an
     * invented number — and never as a division by zero.
     */
    public const COVERAGE_NO_DEMAND = 'no_projected_demand';

    /** How many insight cards the page will show at most, worst first. */
    public const MAX_INSIGHTS = 12;

    /**
     * Join the three services' results into one shape.
     *
     * @param  int|string  $branchScope  branch id, or 'all'. From getSelectedBranch().
     * @param  array  $productionCapacity  ProductionCapacityService::forBranchScope()
     * @param  array  $menuForecast        DemandForecastService::forMenuItems(), UNSLICED
     * @param  array  $profit              ProfitCalculationService::forRange()
     * @param  array  $weekdayDemand       DemandForecastService::byDayOfWeek()
     * @param  array  $salesForecast       DemandForecastService::forDailySeries()
     */
    public function build(
        $branchScope,
        array $productionCapacity,
        array $menuForecast,
        array $profit,
        array $weekdayDemand = [],
        array $salesForecast = [],
        ?int $lowCapacityThreshold = null
    ): array {
        $lowCapacityThreshold ??= (int) config('inventory.low_stock_threshold', 3);
        $isAllBranches = $branchScope === 'all';

        $rows = $this->menuRows(
            $branchScope,
            $productionCapacity,
            $menuForecast,
            $profit,
            $lowCapacityThreshold
        );

        $ingredients = $this->ingredientRows($rows, $isAllBranches);

        return [
            'branch_scope'            => $branchScope,
            'is_all_branches'         => $isAllBranches,
            'horizon_days'            => DemandForecastService::FORECAST_HORIZON_DAYS,
            'coverage_critical_days'  => self::COVERAGE_CRITICAL_DAYS,
            'coverage_low_days'       => self::COVERAGE_LOW_DAYS,
            'low_capacity_threshold'  => $lowCapacityThreshold,

            // Every menu item in scope that either sold in the period or is
            // currently producible. The CSV writes this whole list; the screen
            // renders the two views of it below.
            'rows'                    => $rows,

            // The Menu Production Capacity table's rows, in the order
            // ProductionCapacityService already ranked them (most at-risk
            // first). A strict subset of 'rows' — the same array entries.
            'capacity_rows'           => $this->capacityOrder($rows),

            // The Menu Performance table's rows: what actually sold in the
            // selected period, revenue first, financials from
            // ProfitCalculationService and from nowhere else.
            'performance_rows'        => $this->performanceOrder($rows),

            'ingredients'             => $ingredients,
            'insights'                => $this->insights(
                $rows,
                $ingredients,
                $profit,
                $weekdayDemand,
                $salesForecast,
                $lowCapacityThreshold
            ),
            'summary'                 => $this->summarize($rows, $ingredients),

            // The period financial chain, passed straight through. Quoted by
            // the CSV summary block so the export's totals are the service's
            // own figures rather than a sum of the grid above them.
            'financials'              => [
                'gross_revenue'         => $profit['gross_revenue'] ?? 0.0,
                'discounts'             => $profit['discounts'] ?? 0.0,
                'net_revenue'           => $profit['net_revenue'] ?? 0.0,
                'cogs'                  => $profit['cogs'] ?? 0.0,
                'gross_profit'          => $profit['gross_profit'] ?? 0.0,
                'margin_percent'        => $profit['margin_percent'] ?? null,
                'order_count'           => $profit['order_count'] ?? 0,
                'item_count'            => $profit['item_count'] ?? 0,
                'legacy_fallback_count' => $profit['legacy_fallback_count'] ?? 0,
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // MENU ROWS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * One row per menu item, carrying capacity, forecast, financials and the
     * three derived figures.
     *
     * The spine is the UNION of the capacity list and the period's sales, and
     * it has to be: capacity alone would drop an item that sold well and has
     * since been switched off, and sales alone would drop every item that has
     * a recipe but has never sold — the exact item §11 says must still appear,
     * with demand marked unavailable rather than assumed to be zero.
     */
    private function menuRows(
        $branchScope,
        array $productionCapacity,
        array $menuForecast,
        array $profit,
        int $lowCapacityThreshold
    ): array {
        $isAllBranches = $branchScope === 'all';
        $rows = [];

        // ── capacity first: it is already sorted most-at-risk-first, and that
        //    order is preserved for the capacity view via capacity_position.
        foreach ($productionCapacity as $position => $cap) {
            $rows['id:' . $cap['menu_item_id']] = array_merge($this->blankRow(), [
                'menu_item_id'            => $cap['menu_item_id'],
                'menu_item_name'          => $cap['menu_item_name'],
                'branch_id'               => $cap['branch_id'],
                'branch_name'             => $cap['branch_name'],
                'has_capacity_row'        => true,
                'capacity_position'       => $position,
                'capacity'                => $cap['capacity'],
                'is_measurable'           => $cap['is_measurable'],
                'unavailable_reason'      => $cap['unavailable_reason'],
                'bottleneck_inventory_id' => $cap['bottleneck_inventory_id'],
                'bottleneck_name'         => $cap['bottleneck_name'],
                'bottleneck_unit'         => $cap['bottleneck_unit'],
                'bottleneck_capacity'     => $cap['bottleneck_capacity'],
                'ingredients'             => $cap['ingredients'],
            ]);
        }

        // ── menu-level forecast. Rows here are items that SOLD in the period,
        //    which is not the same set as the capacity list in either
        //    direction.
        foreach ($menuForecast['rows'] ?? [] as $fc) {
            $key = 'id:' . $fc['menu_item_id'];

            if (!isset($rows[$key])) {
                $rows[$key] = array_merge($this->blankRow(), [
                    'menu_item_id'   => $fc['menu_item_id'],
                    'menu_item_name' => $fc['menu_item_name'],
                ]);
            }

            $rows[$key]['has_forecast_row']          = true;
            $rows[$key]['forecast_sufficient']       = $fc['sufficient'];
            $rows[$key]['forecast_reason']           = $fc['reason'];
            $rows[$key]['forecast_message']          = $fc['message'];
            $rows[$key]['forecast_qty_per_day']      = $fc['forecast_qty_per_day'];
            $rows[$key]['forecast_qty_next_7_days']  = $fc['forecast_qty_next_7_days'];
            $rows[$key]['days_with_sales']           = $fc['days_with_sales'];
            $rows[$key]['total_qty_in_range']        = $fc['total_qty_in_range'];
        }

        // ── financials. ProfitCalculationService is the ONLY source of money
        //    on this page: nothing below re-derives revenue, cost or margin.
        foreach ($profit['items'] ?? [] as $item) {
            // A hard-deleted menu item has no id to join on; it still has
            // sales, so it is keyed by the order line's own name snapshot the
            // same way ProfitCalculationService grouped it.
            $key = $item['menu_item_id'] !== null
                ? 'id:' . $item['menu_item_id']
                : 'name:' . $item['name'];

            if (!isset($rows[$key])) {
                $rows[$key] = array_merge($this->blankRow(), [
                    'menu_item_id'   => $item['menu_item_id'],
                    'menu_item_name' => $item['name'],
                ]);
            }

            $rows[$key]['has_sales']      = true;
            $rows[$key]['quantity_sold']  = $item['quantity'];
            $rows[$key]['revenue']        = $item['revenue'];
            $rows[$key]['cogs']           = $item['cost'];
            $rows[$key]['gross_profit']   = $item['profit'];
            $rows[$key]['margin_percent'] = $item['margin_percent'];
        }

        $rows = array_values($rows);

        // Branch labelling, consolidated view only — see labelBranches().
        if ($isAllBranches) {
            $rows = $this->labelBranches($rows);
        }

        // The three derived figures and the risk verdict, per row.
        foreach ($rows as $i => $row) {
            $rows[$i] = $this->deriveRisk($row, $lowCapacityThreshold);
        }

        return $rows;
    }

    /** Every key a menu row carries, so no consumer has to test isset(). */
    private function blankRow(): array
    {
        return [
            'menu_item_id'             => null,
            'menu_item_name'           => '',
            'branch_id'                => null,
            'branch_name'              => null,

            'has_capacity_row'         => false,
            'capacity_position'        => PHP_INT_MAX,
            'capacity'                 => null,
            'is_measurable'            => false,
            'unavailable_reason'       => null,
            'bottleneck_inventory_id'  => null,
            'bottleneck_name'          => null,
            'bottleneck_unit'          => null,
            'bottleneck_capacity'      => null,
            'ingredients'              => [],

            'has_forecast_row'         => false,
            'forecast_sufficient'      => false,
            'forecast_reason'          => null,
            'forecast_message'         => null,
            'forecast_qty_per_day'     => null,
            'forecast_qty_next_7_days' => null,
            'days_with_sales'          => 0,
            'total_qty_in_range'       => 0,

            'has_sales'                => false,
            'quantity_sold'            => 0,
            'revenue'                  => 0.0,
            'cogs'                     => 0.0,
            'gross_profit'             => 0.0,
            'margin_percent'           => null,

            'potential_shortage'       => null,
            'has_shortage'             => false,
            'coverage_days'            => null,
            'coverage_reason'          => null,
            'risk'                     => self::RISK_UNAVAILABLE,
            'risk_label'               => 'Unavailable',
            'risk_reason'              => null,
        ];
    }

    /**
     * Fill in branch names for rows the capacity list did not already label.
     *
     * Under 'all' two branches may legitimately carry a menu item of the same
     * name, and a row that says only "Cheesecake" is ambiguous. Capacity rows
     * arrive already labelled, for free; this covers the remainder — items
     * that sold in the period but are no longer in the capacity list because
     * they have since been switched off or archived.
     *
     * TWO QUERIES AT MOST, and only when such a row actually exists: one
     * whereIn over the menu items in question, and one over the branch ids the
     * capacity rows did not already name. Nothing here is per-row.
     */
    private function labelBranches(array $rows): array
    {
        $needIds = [];
        $known = [];

        foreach ($rows as $row) {
            if ($row['branch_id'] !== null && $row['branch_name'] !== null) {
                $known[$row['branch_id']] = $row['branch_name'];
            } elseif ($row['branch_name'] === null && $row['menu_item_id'] !== null) {
                $needIds[$row['menu_item_id']] = true;
            }
        }

        if (empty($needIds)) {
            return $rows;
        }

        $branchByItem = MenuItem::whereIn('id', array_keys($needIds))
            ->pluck('branch_id', 'id');

        $missingBranchIds = collect($branchByItem)
            ->filter()
            ->unique()
            ->reject(fn ($id) => isset($known[$id]))
            ->values();

        if ($missingBranchIds->isNotEmpty()) {
            foreach (Branch::whereIn('id', $missingBranchIds->all())->pluck('name', 'id') as $id => $name) {
                $known[$id] = $name;
            }
        }

        foreach ($rows as $i => $row) {
            if ($row['branch_name'] !== null || $row['menu_item_id'] === null) {
                continue;
            }

            $branchId = $branchByItem[$row['menu_item_id']] ?? null;

            if ($branchId !== null) {
                $rows[$i]['branch_id'] = (int) $branchId;
                $rows[$i]['branch_name'] = $known[$branchId] ?? null;
            }
        }

        return $rows;
    }

    /**
     * Potential shortage, days of coverage, and the risk verdict for one row.
     *
     * The ladder in the class docblock, in code, evaluated top to bottom with
     * an early return at each rung — which is what makes "two conditions
     * producing contradictory statuses" structurally impossible rather than
     * merely unlikely.
     */
    private function deriveRisk(array $row, int $lowCapacityThreshold): array
    {
        $capacity   = $row['capacity'];
        $measurable = $row['has_capacity_row'] && $row['is_measurable'];
        $perDay     = $row['forecast_sufficient'] ? $row['forecast_qty_per_day'] : null;
        $horizonQty = $row['forecast_sufficient'] ? $row['forecast_qty_next_7_days'] : null;

        // ── Rung 1: UNAVAILABLE. No capacity figure exists to compare against,
        //    so neither shortage nor coverage can be calculated at all.
        if (!$measurable) {
            return array_merge($row, [
                'potential_shortage' => null,
                'has_shortage'       => false,
                'coverage_days'      => null,
                'coverage_reason'    => self::COVERAGE_UNAVAILABLE,
                'risk'               => self::RISK_UNAVAILABLE,
                'risk_label'         => self::RISK_LABELS[self::RISK_UNAVAILABLE],
                'risk_reason'        => $row['has_capacity_row']
                    ? 'Production capacity cannot be calculated for this item.'
                    : 'This item is not in the current production list.',
            ]);
        }

        // ── Rung 2: OUT OF STOCK. A current-stock fact, checked before the
        //    forecast is consulted so an unproducible item in a branch with no
        //    sales history is never masked as INSUFFICIENT DATA.
        if ($capacity <= 0) {
            return array_merge($row, [
                'potential_shortage' => null,
                'has_shortage'       => false,
                'coverage_days'      => 0.0,
                'coverage_reason'    => null,
                'risk'               => self::RISK_OUT_OF_STOCK,
                'risk_label'         => self::RISK_LABELS[self::RISK_OUT_OF_STOCK],
                'risk_reason'        => $row['bottleneck_name'] !== null
                    ? $row['bottleneck_name'] . ' is unavailable, so none of this item can be produced right now.'
                    : 'A required ingredient is unavailable, so none of this item can be produced right now.',
            ]);
        }

        // ── Rung 3: INSUFFICIENT DATA. Producible, but nothing to compare the
        //    capacity against. No fabricated 0 shortage and no fabricated
        //    coverage figure — both stay null.
        if ($perDay === null || $horizonQty === null) {
            return array_merge($row, [
                'potential_shortage' => null,
                'has_shortage'       => false,
                'coverage_days'      => null,
                'coverage_reason'    => self::COVERAGE_INSUFFICIENT,
                'risk'               => self::RISK_INSUFFICIENT_DATA,
                'risk_label'         => self::RISK_LABELS[self::RISK_INSUFFICIENT_DATA],
                'risk_reason'        => 'Not enough sales history to project demand for this item, '
                    . 'so demand against capacity cannot be assessed.',
            ]);
        }

        // Guard, not arithmetic: DemandForecastService only reports sufficient
        // when the window sums above zero, so perDay is > 0 on every path that
        // reaches here. If that ever changes, this refuses to divide rather
        // than printing infinity.
        if ($perDay <= 0) {
            $risk = $capacity <= $lowCapacityThreshold ? self::RISK_LOW : self::RISK_GOOD;

            return array_merge($row, [
                'potential_shortage' => null,
                'has_shortage'       => false,
                'coverage_days'      => null,
                'coverage_reason'    => self::COVERAGE_NO_DEMAND,
                'risk'               => $risk,
                'risk_label'         => self::RISK_LABELS[$risk],
                'risk_reason'        => 'No projected demand for this item in the forecast window.',
            ]);
        }

        // Shortage is reported only when it EXISTS. Capacity at or above the
        // projected demand is the absence of a shortage, not a shortage of
        // zero orders.
        $shortage = round($horizonQty - $capacity, 2);
        $hasShortage = $shortage > 0;

        $coverage = round($capacity / $perDay, 1);

        // ── Rung 4: CRITICAL.
        if ($coverage < self::COVERAGE_CRITICAL_DAYS) {
            return array_merge($row, [
                'potential_shortage' => $hasShortage ? $shortage : null,
                'has_shortage'       => $hasShortage,
                'coverage_days'      => $coverage,
                'coverage_reason'    => null,
                'risk'               => self::RISK_CRITICAL,
                'risk_label'         => self::RISK_LABELS[self::RISK_CRITICAL],
                'risk_reason'        => 'Current capacity covers less than one day of projected demand.',
            ]);
        }

        // ── Rung 5: LOW. Either the horizon is not covered (which is exactly
        //    "a potential shortage exists"), or the existing inventory
        //    low-stock threshold applies to the capacity figure.
        $belowHorizon = $coverage < self::COVERAGE_LOW_DAYS;
        $lowCapacity  = $capacity <= $lowCapacityThreshold;

        if ($belowHorizon || $lowCapacity) {
            return array_merge($row, [
                'potential_shortage' => $hasShortage ? $shortage : null,
                'has_shortage'       => $hasShortage,
                'coverage_days'      => $coverage,
                'coverage_reason'    => null,
                'risk'               => self::RISK_LOW,
                'risk_label'         => self::RISK_LABELS[self::RISK_LOW],
                'risk_reason'        => $belowHorizon
                    ? 'Current capacity does not cover the full ' . self::COVERAGE_LOW_DAYS
                        . '-day projected demand.'
                    : 'Only ' . $capacity . ' order' . ($capacity === 1 ? '' : 's')
                        . ' of capacity remain, at or below the low-stock threshold of '
                        . $lowCapacityThreshold . '.',
            ]);
        }

        // ── Rung 6: GOOD.
        return array_merge($row, [
            'potential_shortage' => null,
            'has_shortage'       => false,
            'coverage_days'      => $coverage,
            'coverage_reason'    => null,
            'risk'               => self::RISK_GOOD,
            'risk_label'         => self::RISK_LABELS[self::RISK_GOOD],
            'risk_reason'        => 'Current capacity covers the projected demand for the next '
                . self::COVERAGE_LOW_DAYS . ' days.',
        ]);
    }

    /**
     * Menu Production Capacity order — exactly the order
     * ProductionCapacityService returned, preserved through the join so the
     * table the owner already knows does not reshuffle under them.
     */
    private function capacityOrder(array $rows): array
    {
        $capacity = array_values(array_filter($rows, fn ($r) => $r['has_capacity_row']));

        usort($capacity, fn ($a, $b) => $a['capacity_position'] <=> $b['capacity_position']);

        return $capacity;
    }

    /** Menu Performance order: what sold, most revenue first. */
    private function performanceOrder(array $rows): array
    {
        $sold = array_values(array_filter($rows, fn ($r) => $r['has_sales']));

        usort($sold, function (array $a, array $b) {
            $cmp = $b['revenue'] <=> $a['revenue'];
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $b['quantity_sold'] <=> $a['quantity_sold'];

            return $cmp !== 0 ? $cmp : strcasecmp($a['menu_item_name'], $b['menu_item_name']);
        });

        return $sold;
    }

    // ══════════════════════════════════════════════════════════════════════
    // INVENTORY INTELLIGENCE — the reverse of the recipe relationship
    // ══════════════════════════════════════════════════════════════════════

    /**
     * One row per ingredient any in-scope recipe uses: what it is expected to
     * consume per day, how long the stock on the shelf covers that, and which
     * menu items depend on it.
     *
     * AFFECTED MENU ITEMS is the reverse of the requirement map
     * ProductionCapacityService already walked, so it costs no query and
     * cannot disagree with the capacity figures it is derived from. It is the
     * real recipe relationship — never a guess, never a count of items that
     * merely look related.
     *
     * DOUBLE COUNTING. Each menu item contributes to each ingredient exactly
     * once: the ingredient list on a capacity row comes from a requirement MAP
     * keyed by inventory id, so a recipe naming the same ingredient twice has
     * already been summed into one entry upstream. Two DIFFERENT menu items
     * sharing an ingredient are two distinct demands and are summed, which is
     * the intended arithmetic.
     *
     * PARTIAL USAGE is flagged, not hidden. When some of the items that use an
     * ingredient have a forecast and others do not, the usage figure only
     * accounts for the forecastable ones — so it is a FLOOR on real usage, and
     * usage_is_partial says so, so the page can state the limitation instead of
     * presenting an understatement as the whole picture. When NONE of them have
     * a forecast, usage and days-of-stock are null rather than 0.
     *
     * BRANCH SAFETY is inherited: these ingredient rows come from capacity
     * rows that ProductionCapacityService already scoped, and a branch-scoped
     * view whose recipe reached into another branch was refused there with an
     * EMPTY ingredient list. There is no path by which another branch's stock
     * level reaches this array.
     */
    private function ingredientRows(array $rows, bool $isAllBranches): array
    {
        $byInventory = [];
        $branchNames = [];

        foreach ($rows as $row) {
            if ($row['branch_id'] !== null && $row['branch_name'] !== null) {
                $branchNames[$row['branch_id']] = $row['branch_name'];
            }
        }

        foreach ($rows as $row) {
            if (!$row['has_capacity_row']) {
                continue;
            }

            foreach ($row['ingredients'] as $ing) {
                $id = $ing['inventory_id'];

                if (!isset($byInventory[$id])) {
                    $byInventory[$id] = [
                        'inventory_id'            => $id,
                        'name'                    => $ing['name'],
                        'unit'                    => $ing['unit'],
                        'branch_id'               => $ing['branch_id'],
                        'branch_name'             => $ing['branch_id'] !== null
                            ? ($branchNames[$ing['branch_id']] ?? null)
                            : null,
                        'available'               => $ing['available'],
                        'is_missing'              => $ing['is_missing'],
                        'affected_menu_items'     => [],
                        'menu_items_affected'     => 0,
                        'menu_items_forecastable' => 0,
                        'bottleneck_for'          => 0,
                        'average_daily_usage'     => 0.0,
                        'usage_is_partial'        => false,
                        'days_of_stock'           => null,
                        'days_of_stock_reason'    => null,
                        'is_out_of_stock'         => false,
                    ];
                }

                $byInventory[$id]['menu_items_affected']++;
                $byInventory[$id]['affected_menu_items'][] = [
                    'menu_item_id'      => $row['menu_item_id'],
                    'menu_item_name'    => $row['menu_item_name'],
                    'branch_name'       => $isAllBranches ? $row['branch_name'] : null,
                    'required_per_unit' => $ing['required_per_unit'],
                    'risk'              => $row['risk'],
                ];

                if ($row['bottleneck_inventory_id'] === $id) {
                    $byInventory[$id]['bottleneck_for']++;
                }

                // Average Daily Ingredient Usage — the item's own forecast
                // multiplied by its own recipe requirement, never by another
                // item's demand.
                if ($row['forecast_sufficient']
                    && $row['forecast_qty_per_day'] !== null
                    && $ing['required_per_unit'] > 0
                ) {
                    $byInventory[$id]['average_daily_usage'] +=
                        $row['forecast_qty_per_day'] * (float) $ing['required_per_unit'];
                    $byInventory[$id]['menu_items_forecastable']++;
                }
            }
        }

        foreach ($byInventory as $id => $ing) {
            $forecastable = $ing['menu_items_forecastable'];
            $usage = round($ing['average_daily_usage'], 3);

            if ($forecastable === 0 || $usage <= 0) {
                // NOT zero usage: no forecastable menu item uses this
                // ingredient, so nothing is known about its consumption rate.
                $byInventory[$id]['average_daily_usage'] = null;
                $byInventory[$id]['days_of_stock'] = null;
                $byInventory[$id]['days_of_stock_reason'] = self::COVERAGE_INSUFFICIENT;
            } else {
                $byInventory[$id]['average_daily_usage'] = $usage;
                $byInventory[$id]['usage_is_partial'] = $forecastable < $ing['menu_items_affected'];

                if ($ing['available'] === null) {
                    $byInventory[$id]['days_of_stock'] = null;
                    $byInventory[$id]['days_of_stock_reason'] = self::COVERAGE_UNAVAILABLE;
                } else {
                    $byInventory[$id]['days_of_stock'] = round((float) $ing['available'] / $usage, 1);
                }
            }

            $byInventory[$id]['is_out_of_stock'] = $ing['available'] !== null
                && (float) $ing['available'] <= 0;

            usort(
                $byInventory[$id]['affected_menu_items'],
                fn ($a, $b) => strcasecmp($a['menu_item_name'], $b['menu_item_name'])
            );
        }

        $list = array_values($byInventory);

        // Most urgent first: out of stock, then fewest days of stock, then the
        // ones nothing can be said about, then by name for a stable order.
        usort($list, function (array $a, array $b) {
            if ($a['is_out_of_stock'] !== $b['is_out_of_stock']) {
                return $a['is_out_of_stock'] ? -1 : 1;
            }
            $aHas = $a['days_of_stock'] !== null;
            $bHas = $b['days_of_stock'] !== null;
            if ($aHas !== $bHas) {
                return $aHas ? -1 : 1;
            }
            if ($aHas && $a['days_of_stock'] !== $b['days_of_stock']) {
                return $a['days_of_stock'] <=> $b['days_of_stock'];
            }

            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $list;
    }

    // ══════════════════════════════════════════════════════════════════════
    // SUMMARY
    // ══════════════════════════════════════════════════════════════════════

    private function summarize(array $rows, array $ingredients): array
    {
        $counts = array_fill_keys(array_keys(self::RISK_SEVERITY), 0);

        foreach ($rows as $row) {
            $counts[$row['risk']]++;
        }

        $withShortage = array_values(array_filter($rows, fn ($r) => $r['has_shortage']));

        return [
            'menu_items'  => count($rows),
            'risk_counts' => $counts,

            // "Items Requiring Attention" — the KPI. Deliberately the two
            // states that describe a problem with stock RIGHT NOW, not the two
            // that describe missing data: an item nothing is known about is not
            // evidence of a risk, and counting it as one would inflate the
            // number every quiet week.
            'items_requiring_attention' => $counts[self::RISK_OUT_OF_STOCK] + $counts[self::RISK_CRITICAL],

            'items_with_shortage'       => count($withShortage),
            'total_potential_shortage'  => empty($withShortage)
                ? null
                : round(array_sum(array_column($withShortage, 'potential_shortage')), 2),

            'ingredients_tracked'       => count($ingredients),
            'ingredients_out_of_stock'  => count(array_filter($ingredients, fn ($i) => $i['is_out_of_stock'])),
            'ingredients_below_one_day' => count(array_filter(
                $ingredients,
                fn ($i) => $i['days_of_stock'] !== null
                    && !$i['is_out_of_stock']
                    && $i['days_of_stock'] < self::COVERAGE_CRITICAL_DAYS
            )),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // AUTOMATED INSIGHTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Owner-facing sentences, every one of them reproducible by hand from a
     * figure in the tables above it.
     *
     * WHAT WAS REUSED FROM THE DORMANT ENGINE, and what was not.
     * AnalyticsService::recommendations() has never been wired to a screen. Its
     * rules were triaged rather than switched on wholesale:
     *
     *   REUSED (re-expressed here against Phase 2d's data):
     *     out-of-stock alert, low-stock alert, best-seller / strong performer,
     *     least-seller / low demand. The CONDITIONS were sound; what they were
     *     computed from was not — they read live 30-day aggregates regardless
     *     of the range the owner had selected, so the card would contradict the
     *     table beside it. Here they are computed from the SAME period result
     *     the rest of the page renders.
     *
     *   OBSOLETE, not carried over:
     *     "no recent movement" slow-stock (a stock_movements question, not a
     *     demand-vs-capacity one, and already served by the Inventory screen),
     *     and the today-vs-yesterday / this-week-vs-last-week drop rules —
     *     both ignore the selected date range entirely and would state a
     *     comparison the page is not reporting on.
     *
     *   BRANCH-UNSAFE, deliberately NOT surfaced:
     *     the branch-leader rule, which ranks every branch's 30-day sales
     *     against the average. That is precisely the cross-branch comparison
     *     the Phase 3 audit removed from this page, and re-surfacing it —
     *     even only under 'all' — would put it one session variable away from
     *     a branch-locked supervisor. It stays dormant.
     *
     *   NEEDED THE NEW DATA (new here, impossible before Phase 2d):
     *     shortage, coverage, bottleneck, capacity, profitability and the
     *     weekday demand pattern.
     *
     * LANGUAGE. Every recommendation is decision support: "Consider…",
     * "Monitor…", "Review…". Nothing here says "buy exactly N units" — the
     * database has no supplier, lead-time or pack-size data that could support
     * such a number — and nothing tells the owner to remove a product. The
     * decision stays theirs.
     *
     * BRANCH SAFETY. Every sentence is built from $rows / $ingredients /
     * $profit, all three of which were scoped before they reached this class.
     * A branch-locked user cannot receive another branch's insight because no
     * other branch's data is in the arrays the sentences are made of.
     */
    private function insights(
        array $rows,
        array $ingredients,
        array $profit,
        array $weekdayDemand,
        array $salesForecast,
        int $lowCapacityThreshold
    ): array {
        $insights = [];

        // ── SHORTAGE ALERT ────────────────────────────────────────────────
        foreach ($this->topBy($rows, fn ($r) => $r['has_shortage'], 'potential_shortage', 3) as $row) {
            $insights[] = [
                'type'    => 'shortage',
                'level'   => $row['risk'] === self::RISK_CRITICAL ? 'critical' : 'warning',
                'icon'    => 'bi-graph-up-arrow',
                'title'   => 'Projected demand may exceed capacity',
                'message' => sprintf(
                    'Projected demand for %s over the next %d days may exceed current production capacity'
                        . ' by approximately %s order%s. Consider preparing additional stock ahead of that demand.',
                    $this->itemLabel($row),
                    self::COVERAGE_LOW_DAYS,
                    $this->trim($row['potential_shortage']),
                    abs($row['potential_shortage'] - 1.0) < 0.001 ? '' : 's'
                ),
            ];
        }

        // ── INVENTORY ALERT ───────────────────────────────────────────────
        $urgentIngredients = array_values(array_filter(
            $ingredients,
            fn ($i) => $i['is_out_of_stock']
                || ($i['days_of_stock'] !== null && $i['days_of_stock'] < self::COVERAGE_CRITICAL_DAYS)
        ));

        foreach (array_slice($urgentIngredients, 0, 3) as $ing) {
            $insights[] = [
                'type'    => 'inventory',
                'level'   => 'critical',
                'icon'    => 'bi-exclamation-octagon',
                'title'   => 'Ingredient stock is running out',
                'message' => $ing['is_out_of_stock']
                    ? sprintf(
                        '%s has no available stock. Consider replenishing it before the %d menu item%s'
                            . ' that use%s it can be produced again.',
                        $ing['name'] ?? 'This ingredient',
                        $ing['menu_items_affected'],
                        $ing['menu_items_affected'] === 1 ? '' : 's',
                        $ing['menu_items_affected'] === 1 ? 's' : ''
                    )
                    : sprintf(
                        '%s may support less than 1 day of projected demand. Consider replenishing it.%s',
                        $ing['name'] ?? 'This ingredient',
                        $ing['usage_is_partial']
                            ? ' Projected usage counts only the menu items that have enough sales history'
                                . ' to forecast, so real usage may be higher.'
                            : ''
                    ),
            ];
        }

        // ── BOTTLENECK ────────────────────────────────────────────────────
        $limiting = array_values(array_filter(
            $ingredients,
            fn ($i) => $i['bottleneck_for'] > 0 && !$i['is_out_of_stock']
        ));

        foreach (array_slice($limiting, 0, 2) as $ing) {
            $insights[] = [
                'type'    => 'bottleneck',
                'level'   => 'warning',
                'icon'    => 'bi-funnel',
                'title'   => 'Limiting ingredient',
                'message' => sprintf(
                    '%s is currently limiting production for %d menu item%s. Consider reviewing its stock'
                        . ' level first when preparing for the days ahead.',
                    $ing['name'] ?? 'An ingredient',
                    $ing['bottleneck_for'],
                    $ing['bottleneck_for'] === 1 ? '' : 's'
                ),
            ];
        }

        // ── CAPACITY ALERT ────────────────────────────────────────────────
        $lowCapacityRows = $this->lowestBy(
            $rows,
            fn ($r) => $r['has_capacity_row']
                && $r['is_measurable']
                && $r['capacity'] > 0
                && $r['capacity'] <= $lowCapacityThreshold,
            'capacity',
            3
        );

        foreach ($lowCapacityRows as $row) {
            $insights[] = [
                'type'    => 'capacity',
                'level'   => 'warning',
                'icon'    => 'bi-speedometer2',
                'title'   => 'Limited production capacity',
                'message' => sprintf(
                    'Current inventory can support approximately %d additional order%s of %s.'
                        . ' Monitor this item during service.',
                    $row['capacity'],
                    $row['capacity'] === 1 ? '' : 's',
                    $this->itemLabel($row)
                ),
            ];
        }

        // ── STRONG PERFORMER / LOW DEMAND / PROFITABILITY ─────────────────
        $sold = array_values(array_filter($rows, fn ($r) => $r['has_sales'] && $r['quantity_sold'] > 0));

        if (!empty($sold)) {
            $quantities = array_column($sold, 'quantity_sold');
            $averageQty = array_sum($quantities) / count($quantities);

            // Highest quantity sold in the selected period. Stated as the
            // historical fact it is — not as a prediction about next week.
            $best = $sold;
            usort($best, fn ($a, $b) => $b['quantity_sold'] <=> $a['quantity_sold']);
            $top = $best[0];

            if (count($sold) > 1 && $top['quantity_sold'] > $averageQty) {
                $insights[] = [
                    'type'    => 'performance',
                    'level'   => 'success',
                    'icon'    => 'bi-star-fill',
                    'title'   => 'Strong performer',
                    'message' => sprintf(
                        '%s had the highest quantity sold during the selected period (%s unit%s).'
                            . ' Consider keeping its ingredients well stocked.',
                        $this->itemLabel($top),
                        number_format($top['quantity_sold']),
                        $top['quantity_sold'] === 1 ? '' : 's'
                    ),
                ];
            }

            // Below the selected-period average. "Monitor", never "remove".
            $lowDemand = array_values(array_filter($sold, fn ($r) => $r['quantity_sold'] < $averageQty));
            usort($lowDemand, fn ($a, $b) => $a['quantity_sold'] <=> $b['quantity_sold']);

            foreach (array_slice($lowDemand, 0, 2) as $row) {
                $insights[] = [
                    'type'    => 'low_demand',
                    'level'   => 'info',
                    'icon'    => 'bi-graph-down',
                    'title'   => 'Below-average demand',
                    'message' => sprintf(
                        'Sales for %s are below the selected-period average (%s sold against an average'
                            . ' of %s). Monitor it over a longer period before drawing a conclusion.',
                        $this->itemLabel($row),
                        number_format($row['quantity_sold']),
                        number_format($averageQty, 1)
                    ),
                ];
            }

            // Strong sales, weak margin. Both halves come from
            // ProfitCalculationService; nothing here re-derives money.
            $periodMargin = $profit['margin_percent'] ?? null;

            if ($periodMargin !== null) {
                $candidates = array_values(array_filter(
                    $sold,
                    fn ($r) => $r['quantity_sold'] >= $averageQty
                        && $r['margin_percent'] !== null
                        && $r['margin_percent'] < $periodMargin
                ));
                usort($candidates, fn ($a, $b) => $a['margin_percent'] <=> $b['margin_percent']);

                foreach (array_slice($candidates, 0, 2) as $row) {
                    $insights[] = [
                        'type'    => 'profitability',
                        'level'   => 'warning',
                        'icon'    => 'bi-cash-coin',
                        'title'   => 'Strong sales, lower margin',
                        'message' => sprintf(
                            '%s has strong sales but a relatively low gross margin (%s%% against %s%% for'
                                . ' the period). Consider evaluating its recipe cost or pricing.',
                            $this->itemLabel($row),
                            number_format($row['margin_percent'], 1),
                            number_format($periodMargin, 1)
                        ),
                    ];
                }
            }
        }

        // ── DEMAND PATTERN ────────────────────────────────────────────────
        // Descriptive only, and it does NOT feed back into the forecast: the
        // moving average stays flat by design. Reported only when at least two
        // weekdays were observed more than once, so a single busy Sunday in a
        // 3-day range cannot become a "pattern".
        $observed = array_values(array_filter(
            $weekdayDemand,
            fn ($d) => $d['observations'] >= 2 && $d['average'] > 0
        ));

        if (count($observed) >= 2) {
            usort($observed, fn ($a, $b) => $b['average'] <=> $a['average']);
            $high = $observed[0];
            $rest = array_slice($observed, 1);
            $restAverage = array_sum(array_column($rest, 'average')) / count($rest);

            if ($high['average'] > $restAverage) {
                $insights[] = [
                    'type'    => 'demand_pattern',
                    'level'   => 'info',
                    'icon'    => 'bi-calendar-week',
                    'title'   => 'Demand pattern',
                    'message' => sprintf(
                        'Historical %s demand has been higher than some other weekdays in this period'
                            . ' (averaging %s across %d %s). Descriptive history, not a prediction —'
                            . ' consider weighing it alongside your own knowledge of the week.',
                        $high['day'],
                        '₱' . number_format($high['average'], 2),
                        $high['observations'],
                        $high['day'] . 's'
                    ),
                ];
            }
        }

        // ── FORECAST UNAVAILABLE ──────────────────────────────────────────
        if (!empty($salesForecast) && ($salesForecast['sufficient'] ?? false) === false) {
            $insights[] = [
                'type'    => 'forecast_unavailable',
                'level'   => 'info',
                'icon'    => 'bi-info-circle',
                'title'   => 'Forecast unavailable',
                'message' => ($salesForecast['message'] ?? 'Insufficient historical data for forecasting.')
                    . ' Demand-versus-capacity figures are reported as Insufficient Data rather than'
                    . ' estimated, so nothing on this page assumes demand is zero.',
            ];
        }

        // Worst first, then cap. The order is by the level's own severity and
        // is stable within a level, so the list does not reshuffle between two
        // views of the same data.
        $levelRank = ['critical' => 0, 'warning' => 1, 'success' => 2, 'info' => 3];
        $indexed = array_map(fn ($i, $v) => [$i, $v], array_keys($insights), $insights);

        usort($indexed, function (array $a, array $b) use ($levelRank) {
            $cmp = ($levelRank[$a[1]['level']] ?? 9) <=> ($levelRank[$b[1]['level']] ?? 9);

            return $cmp !== 0 ? $cmp : ($a[0] <=> $b[0]);
        });

        return array_slice(array_column($indexed, 1), 0, self::MAX_INSIGHTS);
    }

    /** The N rows matching $filter with the LARGEST $field. */
    private function topBy(array $rows, callable $filter, string $field, int $limit): array
    {
        $matched = array_values(array_filter($rows, $filter));
        usort($matched, fn ($a, $b) => $b[$field] <=> $a[$field]);

        return array_slice($matched, 0, $limit);
    }

    /** The N rows matching $filter with the SMALLEST $field. */
    private function lowestBy(array $rows, callable $filter, string $field, int $limit): array
    {
        $matched = array_values(array_filter($rows, $filter));
        usort($matched, fn ($a, $b) => $a[$field] <=> $b[$field]);

        return array_slice($matched, 0, $limit);
    }

    /**
     * An item's name, qualified by its branch only where two branches could
     * legitimately carry the same name. A branch-scoped viewer already knows
     * which branch they are reading and never needs telling.
     */
    private function itemLabel(array $row): string
    {
        return $row['branch_name'] !== null
            ? $row['menu_item_name'] . ' (' . $row['branch_name'] . ')'
            : $row['menu_item_name'];
    }

    /** "13" not "13.00", "13.5" not "13.50" — quantities, not money. */
    private function trim(?float $value): string
    {
        if ($value === null) {
            return '0';
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}

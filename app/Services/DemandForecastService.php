<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DemandForecastService — "what may happen next, based on what actually
 * happened?"
 *
 * Phase 2c. The user-facing forecasting method for the Analytics page. It is a
 * MOVING AVERAGE and nothing more: the mean of the most recent calendar-day
 * sales observations inside the range the owner selected. Chosen over anything
 * cleverer because the owner has to be able to check it by hand —
 *
 *     forecast = (sum of the recent daily observations) / (number of them)
 *
 * — and because the dataset behind it is small enough that a fancier model
 * would be fitting noise while sounding authoritative.
 *
 * NOTHING HERE IS A PROMISE. Every figure this class returns is an estimate
 * projected from history. It carries no confidence interval, no accuracy
 * percentage and no probability, because none of those are calculated: the
 * project has no validated statistical machinery to back such a number, and
 * inventing one would be the single most misleading thing this page could do.
 * Callers must label the output Forecast / Estimated / Projected.
 *
 * INSUFFICIENT IS NOT ZERO. This is the load-bearing rule of the whole class.
 * A branch with no usable history returns sufficient = false and a reason —
 * never a forecast of ₱0. The two mean opposite things: ₱0 asserts that
 * demand is expected to be nothing, while insufficient says the evidence
 * cannot support any expectation at all. Phase 1 measured the live data and
 * found Main Branch with 11 selling days in the recent 30 and Branches 1-3
 * with none, so this is the ordinary case here, not an edge case. Phase 2d
 * will feed these results into forecast-vs-capacity risk, where a fabricated
 * ₱0 would silently become "no shortage risk" for exactly the branches nothing
 * is known about.
 *
 * ZERO-SALES DAYS ARE OBSERVATIONS; MISSING ONES DO NOT EXIST. The input is
 * the zero-filled calendar-day series from
 * AnalyticsService::dailySalesSeriesForRange(), so a day the shop took no money
 * contributes a real 0.0 to the mean. It has to: the forecast answers "how much
 * per DAY", and averaging only the days that happened to sell would answer the
 * different question "how much on a day that sells", which runs high every time
 * there is a quiet day. No calendar day inside the range is skipped, and no day
 * outside it is invented.
 *
 * NO QUERIES FOR THE SALES FORECAST. forDailySeries() and byDayOfWeek() take
 * the series the Analytics page has already fetched and are pure arithmetic
 * over it. That is deliberate: the chart, the KPI and the weekday table are
 * three readings of ONE query, not three trips to the database. Only the
 * menu-level forecast touches the database, and it does so in a single grouped
 * query for every item at once — see forMenuItems().
 *
 * BRANCH SCOPE arrives from ResolvesBranchScope::getSelectedBranch() and
 * nowhere else, exactly as ProductionCapacityService requires. This class never
 * reads a request, so there is no branch parameter for a branch-locked
 * supervisor to tamper with.
 *
 * See docs/ANALYTICS_METHODOLOGY.md §10 for the written-up methodology.
 */
class DemandForecastService
{
    /** The only forecasting method this class implements. */
    public const METHOD = 'moving_average';

    /**
     * How many of the most recent calendar days the average is taken over.
     *
     * SEVEN, so the window is exactly one week and every weekday contributes
     * once. A window that is not a whole number of weeks silently weights
     * whichever weekdays happen to land at the end of the selected range — a
     * 5-day window ending on a Sunday would carry two weekend days out of five
     * and read high for a café. Seven also matches the forecast horizon, so
     * "the last week" predicts "the next week", which is the comparison an
     * owner is actually making in their head.
     *
     * Not longer: Phase 1 measured only 11 selling days in the recent 30, so a
     * 14- or 28-day window would mostly average in stretches with no trading
     * activity and drag every forecast toward zero without being any better
     * evidenced.
     */
    public const MOVING_AVERAGE_WINDOW_DAYS = 7;

    /** How many days ahead the projection runs. */
    public const FORECAST_HORIZON_DAYS = 7;

    /**
     * Selling days the selected range must contain before a forecast is drawn.
     *
     * REUSED, not reinvented: this is AnalyticsService's existing
     * FORECAST_MIN_DAYS_WITH_SALES, the same bar the dormant SLR forecast has
     * always applied and the same one DemoSalesTopUp and DemoSalesSeeder
     * already measure themselves against. A second, competing literal here is
     * how "enough data" would end up meaning two different things in one
     * module.
     */
    public const MIN_DAYS_WITH_SALES = AnalyticsService::FORECAST_MIN_DAYS_WITH_SALES;

    /**
     * The same bar again, for one menu item rather than the whole branch.
     *
     * Deliberately the same number and deliberately expressed as an alias
     * rather than a copy: "enough history to forecast" should not quietly mean
     * something stricter for a cupcake than for the shop. If the two ever need
     * to diverge, they diverge HERE, once, with a reason written next to them.
     */
    public const MENU_MIN_DAYS_WITH_SALES = self::MIN_DAYS_WITH_SALES;

    /** No completed sales at all inside the selected range. */
    public const REASON_NO_HISTORY = 'no_history';

    /** Some selling days, but fewer than MIN_DAYS_WITH_SALES of them. */
    public const REASON_TOO_FEW_DAYS = 'too_few_days';

    /**
     * Enough selling days in the range overall, but not one of them falls
     * inside the moving-average window the forecast is actually taken over.
     *
     * Without this arm the class would happily average seven consecutive empty
     * days and report a perfectly confident ₱0 — the exact false negative the
     * class docblock exists to prevent, arrived at by arithmetic instead of by
     * a missing branch. A week with no trading at all is very much more likely
     * to mean the shop was shut, or that sales stopped being recorded, than
     * that demand has genuinely gone to nothing.
     */
    public const REASON_STALE_HISTORY = 'stale_history';

    // ══════════════════════════════════════════════════════════════════════
    // BRANCH / OVERALL SALES FORECAST
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Moving-average sales forecast over an already-fetched daily series.
     *
     * @param  array<int,float>  $values  one zero-filled entry per calendar day
     *                                    of [$start, $end], in order, exactly as
     *                                    AnalyticsService::dailySalesSeriesForRange()
     *                                    returns in its 'values' key.
     * @param  Carbon  $start  first calendar day of the HISTORICAL range.
     * @param  Carbon  $end    last calendar day of the HISTORICAL range. The
     *                         forecast period begins the day AFTER this — not
     *                         after "today" — so the two periods on the chart
     *                         always meet exactly once and never overlap.
     */
    public function forDailySeries(array $values, Carbon $start, Carbon $end): array
    {
        $values = array_values(array_map('floatval', $values));

        $historicalDays = count($values);
        $daysWithSales  = count(array_filter($values, fn ($v) => $v > 0));

        $context = [
            'method'           => self::METHOD,
            'window_days'      => self::MOVING_AVERAGE_WINDOW_DAYS,
            'horizon_days'     => self::FORECAST_HORIZON_DAYS,
            'historical_days'  => $historicalDays,
            'days_with_sales'  => $daysWithSales,
            'min_days_with_sales' => self::MIN_DAYS_WITH_SALES,
            'historical_start' => $start->toDateString(),
            'historical_end'   => $end->toDateString(),
        ];

        if ($historicalDays === 0 || $daysWithSales === 0) {
            return $this->insufficient($context, self::REASON_NO_HISTORY);
        }

        if ($daysWithSales < self::MIN_DAYS_WITH_SALES) {
            return $this->insufficient($context, self::REASON_TOO_FEW_DAYS);
        }

        // The window is the TAIL of the range: the most recent observations are
        // the ones a moving average is supposed to be made of. A range shorter
        // than the window simply uses every day it has, and reports how many
        // that was via observations_used rather than padding itself out with
        // days that never happened.
        $window = array_slice($values, -min(self::MOVING_AVERAGE_WINDOW_DAYS, $historicalDays));

        if (array_sum($window) <= 0) {
            return $this->insufficient($context, self::REASON_STALE_HISTORY);
        }

        $observationsUsed = count($window);
        $average = array_sum($window) / $observationsUsed;

        // A moving average has no trend term, so every projected day carries
        // the SAME value. That flatness is the honest shape of this method and
        // is not smoothed over with a slope the data cannot evidence; the
        // weekday table below is offered as descriptive context instead.
        $nextDay = round($average, 2);

        $forecast = [];
        for ($i = 1; $i <= self::FORECAST_HORIZON_DAYS; $i++) {
            $date = $end->copy()->startOfDay()->addDays($i);
            $forecast[] = [
                'date'  => $date->toDateString(),
                'label' => $date->format('M d'),
                'value' => $nextDay,
            ];
        }

        return $context + [
            'sufficient'        => true,
            'reason'            => null,
            'observations'      => array_map(fn ($v) => round($v, 2), $window),
            'observations_used' => $observationsUsed,
            'next_day'          => $nextDay,
            'next_7_days'       => $forecast,
            'total_next_7_days' => round($nextDay * self::FORECAST_HORIZON_DAYS, 2),
            'forecast_start'    => $forecast[0]['date'],
            'forecast_end'      => $forecast[count($forecast) - 1]['date'],
            'message'           => 'Projected from the average of the last ' . $observationsUsed
                . ' day' . ($observationsUsed === 1 ? '' : 's') . ' of sales.',
        ];
    }

    /**
     * The insufficient-data result.
     *
     * next_day and total_next_7_days are NULL, never 0.0, and next_7_days is
     * empty rather than seven zero rows — so a caller that forgets to check
     * sufficient renders a blank or errors loudly instead of quietly drawing a
     * flat ₱0 line across the chart and calling it a forecast.
     */
    private function insufficient(array $context, string $reason): array
    {
        $messages = [
            self::REASON_NO_HISTORY => 'Insufficient historical data for forecasting.',
            self::REASON_TOO_FEW_DAYS => 'Insufficient historical data for forecasting. '
                . 'At least ' . self::MIN_DAYS_WITH_SALES . ' days with sales are needed in the selected period.',
            self::REASON_STALE_HISTORY => 'Insufficient recent data for forecasting. '
                . 'There were no sales in the last ' . self::MOVING_AVERAGE_WINDOW_DAYS
                . ' days of the selected period.',
        ];

        return $context + [
            'sufficient'        => false,
            'reason'            => $reason,
            'observations'      => [],
            'observations_used' => 0,
            'next_day'          => null,
            'next_7_days'       => [],
            'total_next_7_days' => null,
            'forecast_start'    => null,
            'forecast_end'      => null,
            'message'           => $messages[$reason],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // DAY-OF-WEEK ANALYSIS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Average takings per weekday across the selected range.
     *
     * DESCRIPTIVE EVIDENCE, NOT A SEASONAL MODEL, and the wording callers use
     * must keep that distinction: "historical Sunday sales have averaged ₱X
     * across N Sundays", never "Sunday will be higher". With the handful of
     * observations per weekday this dataset actually has, the difference
     * between two weekdays is as likely to be which weeks happened to be busy
     * as it is to be a property of the weekday. That is why nothing here feeds
     * back into forDailySeries(): the forecast stays flat, and this table sits
     * beside it as context the owner can weigh themselves.
     *
     * Only weekdays that genuinely occur in the range appear in the result —
     * a 3-day range yields 3 rows, not 7 padded with invented zeroes.
     * Every OCCURRENCE of a weekday counts toward its average, including days
     * that took nothing, for the same reason forDailySeries() keeps its zeroes.
     *
     * @param  array<int,float>  $values  the same zero-filled daily series.
     * @param  Carbon  $start  the day $values[0] belongs to.
     * @return array<int,array> one row per weekday present, Monday-first.
     */
    public function byDayOfWeek(array $values, Carbon $start): array
    {
        $values = array_values(array_map('floatval', $values));

        $buckets = [];
        $cursor = $start->copy()->startOfDay();

        foreach ($values as $value) {
            $key = (int) $cursor->dayOfWeekIso; // 1 = Monday … 7 = Sunday

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'iso_day'         => $key,
                    'day'             => $cursor->format('l'),
                    'observations'    => 0,
                    'days_with_sales' => 0,
                    'total'           => 0.0,
                ];
            }

            $buckets[$key]['observations']++;
            $buckets[$key]['total'] += $value;
            if ($value > 0) {
                $buckets[$key]['days_with_sales']++;
            }

            $cursor->addDay();
        }

        ksort($buckets);

        return array_values(array_map(function (array $b) {
            $b['average'] = round($b['total'] / $b['observations'], 2);
            $b['total']   = round($b['total'], 2);
            return $b;
        }, $buckets));
    }

    /**
     * One weekday's row out of byDayOfWeek(), or null if that weekday never
     * occurred in the range.
     *
     * Exists because "how do Sundays look?" is a question the owner asks
     * directly, and answering it by hand-filtering the array at every call site
     * is how one of those call sites eventually starts fabricating a row for a
     * Sunday the range never contained.
     *
     * @param  int  $isoDay  1 = Monday … 7 = Sunday (Carbon's dayOfWeekIso).
     */
    public function forWeekday(array $values, Carbon $start, int $isoDay): ?array
    {
        foreach ($this->byDayOfWeek($values, $start) as $row) {
            if ($row['iso_day'] === $isoDay) {
                return $row;
            }
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════════════
    // MENU-LEVEL DEMAND FORECAST
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Per-item demand forecast, in units per day, for the items that have
     * enough history to support one.
     *
     * ONE QUERY for every item and every day at once — grouped by item AND by
     * calendar day in SQL, then pivoted in PHP. The N+1 shape this avoids is
     * the obvious one: loop the menu, query each item's history. On a menu of
     * any size that is hundreds of round trips for a single page view, and it
     * is precisely the per-day-loop pattern Phase 2a removed from the chart.
     *
     * ITEMS WITH NO HISTORY ARE NOT LISTED AT ALL. The result covers items that
     * actually sold in the range; an item that sold nothing has no observations
     * to forecast from, and listing it with a zero would assert an absence of
     * demand that the data does not show — it may simply be new, or seasonal,
     * or only just added to the menu. Items that DID sell but not on enough
     * days are listed with sufficient = false and said so, because for those
     * the honest answer is "not enough yet" rather than silence.
     *
     * Quantities, not pesos. Phase 2d joins this to production capacity and
     * inventory, which are both denominated in units; forecasting revenue per
     * item here would just have to be divided back out again.
     *
     * @param  int|string  $branchScope  a branch id, or 'all'.
     * @param  int  $limit  how many ranked rows to return for display.
     */
    public function forMenuItems($branchScope, Carbon $start, Carbon $end, int $limit = 5): array
    {
        $from = $start->copy()->startOfDay();
        $to   = $end->copy()->endOfDay();

        $query = DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->whereNotNull('oi.menu_item_id')
            ->where('o.status', 'completed')
            ->whereBetween('o.completed_at', [$from, $to]);

        // Same branch rule as every other aggregate in the module: a scope of
        // 'all' is the consolidated view, anything else is one branch id that
        // came from getSelectedBranch() and never from the request.
        if ($branchScope !== 'all') {
            $query->where('o.branch_id', $branchScope);
        }

        $rows = $query
            ->groupBy('oi.menu_item_id', DB::raw('DATE(o.completed_at)'))
            ->get([
                'oi.menu_item_id as menu_item_id',
                DB::raw('DATE(o.completed_at) as day'),
                DB::raw('SUM(oi.quantity) as qty'),
                // The name is taken from the ORDER LINE's own snapshot rather
                // than joined from menu_items, so an item that has since been
                // renamed or deleted still reports under the name it sold as —
                // the same convention the receipt and the sales export follow.
                DB::raw('MAX(oi.item_name) as item_name'),
            ]);

        // Pivot: menu_item_id => ['name' => …, 'days' => [Y-m-d => qty]]
        $byItem = [];
        foreach ($rows as $row) {
            $id = (int) $row->menu_item_id;
            if (! isset($byItem[$id])) {
                $byItem[$id] = ['name' => $row->item_name, 'days' => []];
            }
            $byItem[$id]['days'][(string) $row->day] = (float) $row->qty;
        }

        // The window is the same tail of calendar days the sales forecast uses,
        // computed once here rather than per item.
        $windowDates = $this->windowDates($start, $end);

        $results = [];
        foreach ($byItem as $id => $item) {
            $daysWithSales = count(array_filter($item['days'], fn ($q) => $q > 0));

            $observations = array_map(
                fn (string $d) => (float) ($item['days'][$d] ?? 0.0),
                $windowDates
            );

            $sufficient = $daysWithSales >= self::MENU_MIN_DAYS_WITH_SALES
                && array_sum($observations) > 0;

            $perDay = $sufficient
                ? round(array_sum($observations) / count($observations), 2)
                : null;

            $results[] = [
                'menu_item_id'      => $id,
                'menu_item_name'    => $item['name'],
                'sufficient'        => $sufficient,
                'reason'            => $sufficient
                    ? null
                    : ($daysWithSales >= self::MENU_MIN_DAYS_WITH_SALES
                        ? self::REASON_STALE_HISTORY
                        : self::REASON_TOO_FEW_DAYS),
                'message'           => $sufficient
                    ? null
                    : 'Insufficient historical data for menu-level forecasting.',
                'days_with_sales'   => $daysWithSales,
                'min_days_with_sales' => self::MENU_MIN_DAYS_WITH_SALES,
                'observations_used' => $sufficient ? count($observations) : 0,
                'total_qty_in_range' => (int) round(array_sum($item['days'])),
                'forecast_qty_per_day' => $perDay,
                'forecast_qty_next_7_days' => $perDay === null
                    ? null
                    : round($perDay * self::FORECAST_HORIZON_DAYS, 2),
            ];
        }

        // Forecastable items first — an owner reading this table wants the
        // items it can actually say something about at the top — then by
        // projected demand, then by name so the order is stable when two items
        // tie (and when neither has a forecast at all).
        usort($results, function (array $a, array $b) {
            if ($a['sufficient'] !== $b['sufficient']) {
                return $a['sufficient'] ? -1 : 1;
            }
            $cmp = ($b['forecast_qty_per_day'] ?? 0) <=> ($a['forecast_qty_per_day'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $b['total_qty_in_range'] <=> $a['total_qty_in_range'];
            return $cmp !== 0 ? $cmp : strcmp($a['menu_item_name'], $b['menu_item_name']);
        });

        return [
            'method'              => self::METHOD,
            'window_days'         => self::MOVING_AVERAGE_WINDOW_DAYS,
            'horizon_days'        => self::FORECAST_HORIZON_DAYS,
            'min_days_with_sales' => self::MENU_MIN_DAYS_WITH_SALES,
            'items_with_history'  => count($results),
            'items_forecastable'  => count(array_filter($results, fn ($r) => $r['sufficient'])),
            'rows'                => array_slice($results, 0, max(0, $limit)),
        ];
    }

    /**
     * The calendar dates the moving-average window covers — the last
     * MOVING_AVERAGE_WINDOW_DAYS days of [$start, $end], or the whole range
     * when it is shorter than that.
     *
     * @return array<int,string> Y-m-d, oldest first.
     */
    private function windowDates(Carbon $start, Carbon $end): array
    {
        $first = $start->copy()->startOfDay();
        $last  = $end->copy()->startOfDay();

        $cursor = $last->copy()->subDays(self::MOVING_AVERAGE_WINDOW_DAYS - 1);
        if ($cursor->lt($first)) {
            $cursor = $first->copy();
        }

        $dates = [];
        while ($cursor->lte($last)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }
}

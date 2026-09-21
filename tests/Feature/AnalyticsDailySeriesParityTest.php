<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AnalyticsService::dailySalesSeriesForRange() — the per-day loop replaced by
 * one grouped query (Phase 2a, item 2).
 *
 * BEFORE: the method ran `whereDate('completed_at', $day)->sum('total')` once
 * per calendar day in the selected range. Measured on /admin/analytics:
 *
 *      1 day       8 queries
 *      30 days    39 queries
 *      1 year    374 queries
 *      11 years  4,289 queries      <- one page view
 *
 * AFTER: one `GROUP BY DATE(completed_at)` query, zero-filled in PHP, so the
 * count no longer moves with the range at all.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WHY THE OLD IMPLEMENTATION LIVES IN THIS FILE
 *
 * The brief asked for the old and new implementations to be run against the
 * same data and asserted identical, then for the old one to be removed. Both
 * halves are satisfied here: the production service carries ONLY the grouped
 * query, and legacyPerDayLoop() below is a verbatim reference copy of the code
 * that was deleted from it. Keeping the reference in the test rather than in
 * the service means the equivalence is re-proved on every run — a future
 * "optimisation" of the grouped query that changes a figure fails here — while
 * the service itself has no dead second path to drift out of sync.
 *
 * The one place the two could genuinely have disagreed is the FIRST day of the
 * range. The old loop's `whereDate()` matched the whole calendar day whatever
 * time $start carried; a naive `whereBetween([$start, $end])` would have
 * dropped that day's earlier hours. test_a_mid_day_start_still_counts_the_whole
 * _first_day pins exactly that case, because the resolvers always hand in
 * startOfDay() today and a future caller might not.
 */
class AnalyticsDailySeriesParityTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'DAILYPARITY';
    private const ORDER_PREFIX = 'DPAR-';

    private array $highWater = [];
    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['branches', 'orders', 'users'] as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
        }

        $this->branchA = Branch::create([
            'name'      => self::PREFIX . ' Branch A ' . uniqid(),
            'code'      => 'DPA' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address A',
            'is_active' => true,
        ]);

        $this->branchB = Branch::create([
            'name'      => self::PREFIX . ' Branch B ' . uniqid(),
            'code'      => 'DPB' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address B',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('orders')
            ->where('id', '>', $this->highWater['orders'])
            ->where('order_number', 'like', self::ORDER_PREFIX . '%')
            ->delete();

        DB::table('users')
            ->where('id', '>', $this->highWater['users'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        DB::table('branches')
            ->where('id', '>', $this->highWater['branches'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        $this->assertSame(0, DB::table('orders')->where('order_number', 'like', self::ORDER_PREFIX . '%')->count());
        $this->assertSame(0, DB::table('users')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('branches')->where('name', 'like', self::PREFIX . '%')->count());

        parent::tearDown();
    }

    // ── the deleted implementation, kept as the reference ───────────────────

    /**
     * Verbatim copy of AnalyticsService::dailySalesSeriesForRange() as it stood
     * before Phase 2a — one query per calendar day. Reimplemented against the
     * same branch scope the service applies so the only difference under test
     * is the aggregation strategy.
     */
    private function legacyPerDayLoop(int|string $branchScope, \Carbon\Carbon $start, \Carbon\Carbon $end): array
    {
        $labels = [];
        $values = [];

        $cursor = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();

        while ($cursor->lte($last)) {
            $labels[] = $cursor->format('M d');

            $q = Order::where('status', 'completed')
                ->whereDate('completed_at', $cursor->toDateString());

            if ($branchScope !== 'all') {
                $q->where('branch_id', $branchScope);
            }

            $values[] = (float) $q->sum('total');
            $cursor->addDay();
        }

        return ['labels' => $labels, 'values' => $values];
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function completedOrder(Branch $branch, float $total, string $completedAt): Order
    {
        return Order::create([
            'order_number' => self::ORDER_PREFIX . strtoupper(substr(uniqid(), -8)),
            'branch_id'    => $branch->id,
            'type'         => 'walk_in',
            'status'       => 'completed',
            'subtotal'     => $total,
            'total'        => $total,
            'completed_at' => $completedAt,
        ]);
    }

    private function cancelledOrder(Branch $branch, float $total, string $completedAt): Order
    {
        return Order::create([
            'order_number' => self::ORDER_PREFIX . strtoupper(substr(uniqid(), -8)),
            'branch_id'    => $branch->id,
            'type'         => 'walk_in',
            'status'       => 'cancelled',
            'subtotal'     => $total,
            'total'        => $total,
            'completed_at' => $completedAt,
        ]);
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    /**
     * Queries issued while $fn runs.
     *
     * A METHOD rather than an inline block, because Laravel has no "remove this
     * listener" and every DB::listen() closure stays attached for the rest of
     * the test. Measuring twice in one scope gives the second measurement two
     * live counters writing to the same by-reference variables, and it reports
     * double — which is exactly how this pass's investigation first read 17,156
     * queries for an 11-year range instead of the true 4,289. Each call here
     * gets a fresh function scope, so each counter owns its own $count and its
     * own $listening and stops at its own boundary.
     */
    private function queriesFor(callable $fn): int
    {
        $count = 0;
        $listening = true;

        DB::listen(function () use (&$count, &$listening) {
            if ($listening) {
                $count++;
            }
        });

        try {
            $fn();
        } finally {
            $listening = false;
        }

        return $count;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PARITY — old vs new, identical output
    // ══════════════════════════════════════════════════════════════════════

    public function test_grouped_query_matches_the_old_per_day_loop_exactly(): void
    {
        // A range with sales, gaps, several orders on one day, a cancelled
        // order that must NOT count, and another branch's sales that must not
        // leak — every way the two implementations could have disagreed.
        $this->completedOrder($this->branchA, 250.00, '2026-03-02 09:15:00');
        $this->completedOrder($this->branchA, 125.50, '2026-03-02 18:40:00');
        // 2026-03-03: deliberately empty — the zero-fill case.
        $this->completedOrder($this->branchA, 999.99, '2026-03-04 12:00:00');
        $this->cancelledOrder($this->branchA, 500.00, '2026-03-04 13:00:00');
        $this->completedOrder($this->branchB, 777.00, '2026-03-04 12:30:00');
        $this->completedOrder($this->branchA, 60.25, '2026-03-06 23:59:00');

        $start = \Carbon\Carbon::parse('2026-03-01')->startOfDay();
        $end = \Carbon\Carbon::parse('2026-03-07')->endOfDay();

        foreach (['all', $this->branchA->id, $this->branchB->id] as $scope) {
            $legacy = $this->legacyPerDayLoop($scope, $start, $end);
            $current = (new AnalyticsService($scope))->dailySalesSeriesForRange($start, $end);

            $this->assertSame(
                $legacy,
                $current,
                "Grouped query disagreed with the per-day loop for branch scope [{$scope}]."
            );
        }
    }

    public function test_the_parity_fixture_actually_exercises_real_figures(): void
    {
        // Guards the test above from passing because BOTH implementations
        // returned all zeroes. Asserts the concrete expected series once, by
        // hand, so the reference loop itself is pinned to known-good values.
        $this->completedOrder($this->branchA, 250.00, '2026-03-02 09:15:00');
        $this->completedOrder($this->branchA, 125.50, '2026-03-02 18:40:00');
        $this->completedOrder($this->branchA, 999.99, '2026-03-04 12:00:00');
        $this->cancelledOrder($this->branchA, 500.00, '2026-03-04 13:00:00');

        $series = (new AnalyticsService($this->branchA->id))->dailySalesSeriesForRange(
            \Carbon\Carbon::parse('2026-03-01')->startOfDay(),
            \Carbon\Carbon::parse('2026-03-05')->endOfDay()
        );

        $this->assertSame(['Mar 01', 'Mar 02', 'Mar 03', 'Mar 04', 'Mar 05'], $series['labels']);
        $this->assertSame([0.0, 375.50, 0.0, 999.99, 0.0], $series['values']);
    }

    public function test_a_mid_day_start_still_counts_the_whole_first_day(): void
    {
        // The one real divergence risk in the rewrite: the old whereDate()
        // matched the entire calendar day whatever time $start carried. An
        // order completed at 09:00 must still land in the Mar 02 bar when the
        // caller hands in a 15:00 start.
        $this->completedOrder($this->branchA, 250.00, '2026-03-02 09:15:00');

        $start = \Carbon\Carbon::parse('2026-03-02 15:00:00');
        $end = \Carbon\Carbon::parse('2026-03-03')->endOfDay();

        $legacy = $this->legacyPerDayLoop($this->branchA->id, $start, $end);
        $current = (new AnalyticsService($this->branchA->id))->dailySalesSeriesForRange($start, $end);

        $this->assertSame($legacy, $current);
        $this->assertSame([250.00, 0.0], $current['values']);
    }

    public function test_a_single_day_range_returns_one_bar(): void
    {
        $this->completedOrder($this->branchA, 42.00, '2026-03-02 10:00:00');

        $day = \Carbon\Carbon::parse('2026-03-02');
        $current = (new AnalyticsService($this->branchA->id))
            ->dailySalesSeriesForRange($day->copy()->startOfDay(), $day->copy()->endOfDay());

        $this->assertSame(['Mar 02'], $current['labels']);
        $this->assertSame([42.00], $current['values']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // QUERY BUDGET — the count must not move with the range width
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_series_costs_exactly_one_query_however_wide_the_range(): void
    {
        $this->completedOrder($this->branchA, 250.00, '2026-03-02 09:15:00');

        $service = new AnalyticsService($this->branchA->id);

        foreach ([
            '1 day'    => ['2026-03-02', '2026-03-02'],
            '30 days'  => ['2026-02-01', '2026-03-02'],
            '1 year'   => ['2025-03-02', '2026-03-02'],
            '11 years' => ['2015-01-01', '2026-09-20'],
        ] as $label => [$from, $to]) {
            $count = $this->queriesFor(function () use ($service, $from, $to) {
                $service->dailySalesSeriesForRange(
                    \Carbon\Carbon::parse($from)->startOfDay(),
                    \Carbon\Carbon::parse($to)->endOfDay()
                );
            });

            $this->assertSame(
                1,
                $count,
                "dailySalesSeriesForRange() ran {$count} queries for a {$label} range; it must always run exactly 1."
            );
        }
    }

    /**
     * How much of the report's budget one page view may spend.
     *
     * A CEILING rather than an equality against a narrow range, deliberately.
     * Two of the page's queries are data-dependent rather than range-dependent:
     * averageOrderValueForRange() skips its second sum when the order count is
     * zero, and topProductsForRange()'s `with('menuItem')` only fires when
     * there is a product to hydrate. Widening a range can therefore legitimately
     * add a query or two by bringing pre-existing rows of pomida_db_testing
     * into scope, and an equality assertion would fail on that rather than on a
     * regression. A ceiling states the property that actually matters and is
     * immune to whatever else happens to be in the database.
     *
     * RAISED 15 -> 24 in Phase 2b, when Menu Production Capacity was added to
     * this page. The page now measures 17-18 (it was 7-8). That rise is a
     * FIXED cost, not a per-day one, and it is the price of the capacity
     * section reusing the inventory logic instead of duplicating it: roughly
     * four queries for the menu items, their recipes, one whereIn over every
     * inventory row those recipes touch and — on the consolidated view only —
     * the branch names, plus the seven that
     * InventoryDeductionService::committedQuantities() spends walking every
     * open order to find what stock is already spoken for. That walk is the
     * same one the customer's add-to-cart and quantity controls already run on
     * every request; a leaner analytics-only copy of it is exactly the
     * duplication that would let the Analytics page and the till start quoting
     * different numbers.
     *
     * 24 is still a real limit, not a rubber stamp. What this guard exists to
     * catch is a per-day loop coming back, and those land in the hundreds or
     * thousands (measured before the pass that removed them: 39 for 30 days,
     * 374 for a year, 4,289 for 11 years). Even the smallest of those is well
     * clear of this line, and the 6-query margin above the current 18 is there
     * for the data-dependent queries described above, not for future growth:
     * a new section that needs more budget should have to come back here and
     * say why, in this comment, as this one did.
     *
     * NOT RAISED in Phase 2d, and that is worth recording. Phase 2d put
     * ProfitCalculationService on this page (the Menu Performance table, the
     * profitability insights and the CSV's money columns all read from it),
     * which should have cost a few queries — and measured at 112 for an
     * 11-year range on the first run, because that service's legacy-cost
     * fallback called MenuItemCosting::costFor() per sold LINE, and each of
     * those takes a recipe query plus an inventory query. A pre-existing N+1
     * this page had simply never been in a position to trigger.
     *
     * It was fixed rather than budgeted for: the lines now arrive with
     * menuItem.recipeIngredients eager-loaded and are priced through
     * MenuItemCosting::costForMany(), which takes ONE inventory query for the
     * whole set. The Summary screen and the sales CSV, which had the same
     * shape, got the fix with it. Measured after: the page is 17 for a 1-day
     * range and 21 for an 11-year one, print is 20, and the new CSV export is
     * 16 to 20. So Phase 2d's two extra sections came in under the line the
     * page already had — the margin is thinner than it was (3, not 6), and the
     * next section to need budget should still come here and say why.
     */
    private const PAGE_QUERY_CEILING = 24;

    /** Query count for one report page, as the admin, over a custom range. */
    private function pageQueryCount(string $route, string $from, string $to): int
    {
        $admin = $this->admin();

        return $this->queriesFor(function () use ($admin, $route, $from, $to) {
            $this->actingAs($admin, 'admin')->get(route($route, [
                'period' => 'custom', 'date_from' => $from, 'date_to' => $to,
            ]))->assertOk();
        });
    }

    public function test_the_analytics_page_query_count_stays_low_at_every_range_width(): void
    {
        // Sales at both ends of the widest window, so no range under test is
        // trivially empty.
        $this->completedOrder($this->branchA, 250.00, '2026-03-01 09:15:00');
        $this->completedOrder($this->branchA, 180.00, '2016-05-04 09:15:00');

        foreach ([
            '1 day'    => ['2026-03-01', '2026-03-01'],
            '30 days'  => ['2026-02-01', '2026-03-02'],
            '1 year'   => ['2025-03-02', '2026-03-02'],
            '11 years' => ['2015-01-01', '2026-09-20'],
        ] as $label => [$from, $to]) {
            $count = $this->pageQueryCount('admin.analytics', $from, $to);

            $this->assertLessThanOrEqual(
                self::PAGE_QUERY_CEILING,
                $count,
                "The Analytics page ran {$count} queries for a {$label} range; the budget is "
                    . self::PAGE_QUERY_CEILING . ' whatever the range width.'
            );
        }
    }

    public function test_the_analytics_print_query_count_stays_low_at_every_range_width(): void
    {
        $this->completedOrder($this->branchA, 250.00, '2026-03-01 09:15:00');
        $this->completedOrder($this->branchA, 180.00, '2016-05-04 09:15:00');

        foreach ([
            '1 day'    => ['2026-03-01', '2026-03-01'],
            '11 years' => ['2015-01-01', '2026-09-20'],
        ] as $label => [$from, $to]) {
            $count = $this->pageQueryCount('admin.analytics.print', $from, $to);

            $this->assertLessThanOrEqual(
                self::PAGE_QUERY_CEILING,
                $count,
                "The Analytics print view ran {$count} queries for a {$label} range."
            );
        }
    }

    /**
     * The CSV export is held to the SAME budget as the page it exports.
     *
     * It is the third consumer of AdminController::analyticsContext(), so it
     * ought to cost what the other two cost — and an export is exactly where a
     * per-row query would hide longest, because nobody watches a download the
     * way they watch a page load. Added in Phase 2d with the route.
     */
    public function test_the_analytics_export_query_count_stays_low_at_every_range_width(): void
    {
        $this->completedOrder($this->branchA, 250.00, '2026-03-01 09:15:00');
        $this->completedOrder($this->branchA, 180.00, '2016-05-04 09:15:00');

        $admin = $this->admin();

        foreach ([
            '1 day'    => ['2026-03-01', '2026-03-01'],
            '11 years' => ['2015-01-01', '2026-09-20'],
        ] as $label => [$from, $to]) {
            $count = $this->queriesFor(function () use ($admin, $from, $to) {
                $response = $this->actingAs($admin, 'admin')->get(route('admin.analytics.export', [
                    'period' => 'custom', 'date_from' => $from, 'date_to' => $to,
                ]));
                $response->assertOk();

                // The body is a stream: without draining it, the callback that
                // issues the queries never runs and this measures nothing.
                $response->streamedContent();
            });

            $this->assertLessThanOrEqual(
                self::PAGE_QUERY_CEILING,
                $count,
                "The Analytics CSV export ran {$count} queries for a {$label} range; the budget is "
                    . self::PAGE_QUERY_CEILING . ' whatever the range width.'
            );
        }
    }
}

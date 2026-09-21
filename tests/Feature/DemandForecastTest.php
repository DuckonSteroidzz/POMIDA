<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\DemandForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DemandForecastService — Phase 2c.
 *
 * The moving-average forecast that the Analytics page shows, the descriptive
 * weekday breakdown beside it, and the menu-level projection under it.
 *
 * THE PROPERTY THIS FILE EXISTS TO PROTECT is that "we cannot forecast this"
 * never degrades into "we forecast nothing". Those two statements look almost
 * identical on a dashboard and mean opposite things, and the second one is the
 * dangerous one: Phase 2d feeds these results into forecast-vs-capacity risk,
 * where a fabricated ₱0 reads as "no shortage possible" for exactly the
 * branches nothing is known about. Several tests below assert `null` rather
 * than `0.0` and would pass just as loudly either way if they only checked
 * `empty()` — they use assertNull()/assertNotSame() on purpose.
 *
 * ARITHMETIC IS PINNED BY HAND. The first test works the average out longhand
 * in its own comment. A forecast test that only asserts "some number came back"
 * would survive the formula being changed underneath it, which is the one
 * regression that matters most here.
 *
 * AMBIENT DATA. pomida_db_testing is a shared, populated database, so every
 * assertion is either scoped to a branch this file created in setUp() or fed a
 * hand-built series directly. Nothing here asserts against a global total.
 */
class DemandForecastTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'FORECASTPH2C';
    private const ORDER_PREFIX = 'FC2C-';

    private array $highWater = [];
    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['branches', 'orders', 'order_items', 'menu_items', 'users'] as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
        }

        $this->branchA = Branch::create([
            'name'      => self::PREFIX . ' Branch A ' . uniqid(),
            'code'      => 'F2A' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address A',
            'is_active' => true,
        ]);

        $this->branchB = Branch::create([
            'name'      => self::PREFIX . ' Branch B ' . uniqid(),
            'code'      => 'F2B' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address B',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // Children before parents, each bounded by both the high-water id and
        // this file's own prefix, so a shared database is never touched beyond
        // the rows this test created.
        DB::table('order_items')
            ->where('id', '>', $this->highWater['order_items'])
            ->whereIn('order_id', DB::table('orders')
                ->where('order_number', 'like', self::ORDER_PREFIX . '%')
                ->pluck('id'))
            ->delete();

        DB::table('orders')
            ->where('id', '>', $this->highWater['orders'])
            ->where('order_number', 'like', self::ORDER_PREFIX . '%')
            ->delete();

        DB::table('menu_items')
            ->where('id', '>', $this->highWater['menu_items'])
            ->where('name', 'like', self::PREFIX . '%')
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
        $this->assertSame(0, DB::table('menu_items')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('users')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('branches')->where('name', 'like', self::PREFIX . '%')->count());

        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function service(): DemandForecastService
    {
        return app(DemandForecastService::class);
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function supervisor(Branch $branch): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Supervisor ' . uniqid(),
            'email'     => 'forecast2c-' . uniqid() . '@example.test',
            'password'  => bcrypt('Sup3rStr0ng!Pass'),
            'role'      => 'supervisor',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    private function menuItem(Branch $branch, string $label, float $price = 100.00): MenuItem
    {
        return MenuItem::create([
            'category_id'   => Category::value('id'),
            'branch_id'     => $branch->id,
            'name'          => self::PREFIX . ' ' . $label . ' ' . uniqid(),
            'price'         => $price,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    private function order(
        Branch $branch,
        float $total,
        string $completedAt,
        string $status = 'completed',
        ?MenuItem $item = null,
        int $qty = 1
    ): Order {
        $order = Order::create([
            'order_number' => self::ORDER_PREFIX . strtoupper(substr(uniqid(), -8)),
            'branch_id'    => $branch->id,
            'type'         => 'walk_in',
            'status'       => $status,
            'subtotal'     => $total,
            'total'        => $total,
            'completed_at' => $completedAt,
        ]);

        if ($item !== null) {
            OrderItem::create([
                'order_id'     => $order->id,
                'menu_item_id' => $item->id,
                'item_name'    => $item->name,
                'item_price'   => $item->price,
                'quantity'     => $qty,
                'subtotal'     => $total,
            ]);
        }

        return $order;
    }

    /** The daily series the Analytics page itself feeds the forecast. */
    private function series(int|string $scope, Carbon $start, Carbon $end): array
    {
        return (new AnalyticsService($scope))->dailySalesSeriesForRange($start, $end)['values'];
    }

    private function day(string $date): Carbon
    {
        return Carbon::parse($date)->startOfDay();
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 1 — the moving average itself
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_moving_average_matches_a_hand_calculation(): void
    {
        // The brief's worked example. Padded to a 7-day window because that is
        // the window the service uses; the four figures from the brief sit at
        // the end, where the moving average takes its observations from.
        //
        //   120 + 140 + 160 + 150 + 170 + 190 + 200 = 1,130
        //   1,130 / 7 = 161.428571… -> 161.43
        $values = [120.0, 140.0, 160.0, 150.0, 170.0, 190.0, 200.0];

        $result = $this->service()->forDailySeries(
            $values,
            $this->day('2026-03-01'),
            $this->day('2026-03-07')
        );

        $this->assertTrue($result['sufficient']);
        $this->assertSame('moving_average', $result['method']);
        $this->assertSame(7, $result['observations_used']);
        $this->assertSame(round(array_sum($values) / 7, 2), $result['next_day']);
        $this->assertSame(161.43, $result['next_day']);
    }

    public function test_a_range_shorter_than_the_window_averages_only_the_days_it_has(): void
    {
        // Five days — the shortest history the sufficiency bar allows — against
        // a seven-day window. The window shrinks to the range rather than
        // padding itself out with two days that never happened.
        //
        //   (130 + 150 + 170 + 190 + 200) / 5 = 840 / 5 = 168.00
        $result = $this->service()->forDailySeries(
            [130.0, 150.0, 170.0, 190.0, 200.0],
            $this->day('2026-03-01'),
            $this->day('2026-03-05')
        );

        $this->assertTrue($result['sufficient']);
        $this->assertSame(5, $result['observations_used']);
        $this->assertSame(168.0, $result['next_day']);

        // Four selling days is one short of the bar, so the brief's own
        // four-observation example is not forecastable here — by design, and
        // asserted so the threshold cannot drift without this saying so.
        $tooShort = $this->service()->forDailySeries(
            [150.0, 170.0, 190.0, 200.0],
            $this->day('2026-03-01'),
            $this->day('2026-03-04')
        );

        $this->assertFalse($tooShort['sufficient']);
        $this->assertSame(DemandForecastService::REASON_TOO_FEW_DAYS, $tooShort['reason']);
        $this->assertNull($tooShort['next_day']);
    }

    public function test_zero_sales_days_are_averaged_in_rather_than_skipped(): void
    {
        // A forecast of sales PER DAY must divide by every calendar day, not
        // only the ones that sold. Averaging 5 selling days out of 7 would
        // answer "how much on a day that sells" and run high every time.
        //
        //   (100 + 0 + 100 + 0 + 100 + 100 + 100) / 7 = 500 / 7 = 71.428… -> 71.43
        $result = $this->service()->forDailySeries(
            [100.0, 0.0, 100.0, 0.0, 100.0, 100.0, 100.0],
            $this->day('2026-03-01'),
            $this->day('2026-03-07')
        );

        $this->assertSame(7, $result['observations_used'], 'Zero-sales days must still be observations.');
        $this->assertSame(71.43, $result['next_day']);
        $this->assertNotSame(100.0, $result['next_day'], 'Dropping the zeroes would have produced 100.00.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // TESTS 2 & 3 — next-day and next-7-day
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_next_day_forecast_is_the_moving_average(): void
    {
        $result = $this->service()->forDailySeries(
            [200.0, 200.0, 200.0, 200.0, 200.0, 200.0, 200.0],
            $this->day('2026-03-01'),
            $this->day('2026-03-07')
        );

        $this->assertSame(200.0, $result['next_day']);

        // The forecast period starts the day AFTER the historical range ends,
        // not after "today" — otherwise the two periods on the chart could
        // overlap, or leave a gap, whenever the owner looks at a past range.
        $this->assertSame('2026-03-08', $result['next_7_days'][0]['date']);
        $this->assertSame('2026-03-08', $result['forecast_start']);
    }

    public function test_the_next_seven_days_are_seven_flat_days_summing_to_seven_times_the_average(): void
    {
        // A moving average carries no trend term, so every projected day is the
        // same figure. That flatness is the honest shape of the method; a slope
        // here would be a claim the arithmetic does not make.
        $result = $this->service()->forDailySeries(
            [100.0, 200.0, 300.0, 100.0, 200.0, 300.0, 200.0],
            $this->day('2026-03-01'),
            $this->day('2026-03-07')
        );

        $expectedPerDay = round(1400 / 7, 2); // 200.00

        $this->assertCount(7, $result['next_7_days']);
        $this->assertSame($expectedPerDay, $result['next_day']);
        $this->assertSame(round($expectedPerDay * 7, 2), $result['total_next_7_days']);
        $this->assertSame(1400.0, $result['total_next_7_days']);

        foreach ($result['next_7_days'] as $i => $point) {
            $this->assertSame($expectedPerDay, $point['value'], "Forecast day {$i} should be flat.");
        }

        // Seven consecutive dates, in order, immediately after the range.
        $this->assertSame(
            ['2026-03-08', '2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13', '2026-03-14'],
            array_column($result['next_7_days'], 'date')
        );
        $this->assertSame('2026-03-14', $result['forecast_end']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TESTS 4 & 5 — insufficient data is a STATE, never a zero
    // ══════════════════════════════════════════════════════════════════════

    public function test_too_few_selling_days_returns_an_insufficient_state_not_a_zero(): void
    {
        // Four selling days; the bar is five.
        $result = $this->service()->forDailySeries(
            [100.0, 100.0, 100.0, 100.0, 0.0, 0.0, 0.0],
            $this->day('2026-03-01'),
            $this->day('2026-03-07')
        );

        $this->assertFalse($result['sufficient']);
        $this->assertSame(DemandForecastService::REASON_TOO_FEW_DAYS, $result['reason']);
        $this->assertStringContainsString('Insufficient historical data', $result['message']);

        // The heart of it: null, not 0.0. A caller that prints this without
        // checking gets a blank, never a confident "₱0.00".
        $this->assertNull($result['next_day']);
        $this->assertNull($result['total_next_7_days']);
        $this->assertSame([], $result['next_7_days']);
        $this->assertNotSame(0.0, $result['next_day']);
        $this->assertNotSame(0, $result['next_day']);
    }

    public function test_a_completely_empty_history_is_reported_as_no_history(): void
    {
        $result = $this->service()->forDailySeries(
            array_fill(0, 30, 0.0),
            $this->day('2026-03-01'),
            $this->day('2026-03-30')
        );

        $this->assertFalse($result['sufficient']);
        $this->assertSame(DemandForecastService::REASON_NO_HISTORY, $result['reason']);
        $this->assertNull($result['next_day']);
        $this->assertSame(30, $result['historical_days']);
        $this->assertSame(0, $result['days_with_sales']);
    }

    public function test_enough_old_selling_days_but_a_dead_recent_window_is_still_insufficient(): void
    {
        // Six selling days, all of them before the 7-day averaging window. The
        // arithmetic would happily return a perfectly confident 0.00 here; a
        // week with no trading at all is far likelier to mean the shop was shut
        // or sales stopped being recorded than that demand has gone to nothing.
        $values = array_merge(
            [100.0, 100.0, 100.0, 100.0, 100.0, 100.0],
            array_fill(0, 7, 0.0)
        );

        $result = $this->service()->forDailySeries(
            $values,
            $this->day('2026-03-01'),
            $this->day('2026-03-13')
        );

        $this->assertFalse($result['sufficient']);
        $this->assertSame(DemandForecastService::REASON_STALE_HISTORY, $result['reason']);
        $this->assertNull($result['next_day'], 'A dead window must not average out to a confident zero.');
        $this->assertSame(6, $result['days_with_sales']);
    }

    public function test_a_branch_with_no_recent_sales_gets_the_insufficient_message_on_the_page(): void
    {
        // Phase 1's measured reality for Branches 1-3: nothing recorded at all.
        $start = $this->day('2026-03-01');
        $end   = $this->day('2026-03-30')->endOfDay();

        $result = $this->service()->forDailySeries(
            $this->series($this->branchB->id, $start, $end),
            $start,
            $end
        );

        $this->assertFalse($result['sufficient']);
        $this->assertNull($result['total_next_7_days']);

        // And the page says so in words rather than printing a peso figure.
        session(['selected_branch_id' => $this->branchB->id]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', [
                'period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-03-30',
            ]))->assertOk()->getContent();

        $this->assertStringContainsString('Insufficient Data', $html);
        $this->assertStringContainsString('Insufficient historical data for forecasting.', $html);
    }

    public function test_the_kpi_never_renders_a_peso_zero_in_place_of_a_forecast(): void
    {
        session(['selected_branch_id' => $this->branchB->id]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', [
                'period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-03-30',
            ]))->assertOk()->getContent();

        // Isolate the forecast card so the assertion cannot be satisfied (or
        // broken) by a ₱0.00 belonging to Total Sales or a capacity row.
        $card = $this->sliceForecastCard($html);

        $this->assertStringContainsString('Projected Next 7 Days', $card);
        $this->assertStringContainsString('Insufficient Data', $card);
        $this->assertStringNotContainsString('₱0.00', $card);
    }

    /** The "Projected Next 7 Days" tile and its immediate surroundings. */
    private function sliceForecastCard(string $html): string
    {
        $from = strpos($html, 'Projected Next 7 Days');
        $this->assertNotFalse($from, 'The forecast KPI is missing from the page.');

        return substr($html, $from, 600);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TESTS 6 & 7 — what counts as a sale
    // ══════════════════════════════════════════════════════════════════════

    public function test_only_completed_orders_bounded_by_completed_at_feed_the_forecast(): void
    {
        $start = $this->day('2026-03-01');
        $end   = $this->day('2026-03-07')->endOfDay();

        // Five completed days -> a forecast exists.
        foreach (['01', '02', '03', '04', '05'] as $d) {
            $this->order($this->branchA, 100.00, "2026-03-{$d} 12:00:00");
        }

        // Every non-completed status in the orders enum, inside the window,
        // must be invisible.
        foreach (['pending', 'preparing', 'serving'] as $status) {
            $this->order($this->branchA, 999.00, '2026-03-06 12:00:00', $status);
        }

        $result = $this->service()->forDailySeries(
            $this->series($this->branchA->id, $start, $end),
            $start,
            $end
        );

        $this->assertTrue($result['sufficient']);
        // 500 / 7 = 71.428… -> 71.43. If any of the 999.00 rows leaked in this
        // would be far higher.
        $this->assertSame(71.43, $result['next_day']);
        $this->assertSame(5, $result['days_with_sales']);
    }

    public function test_cancelled_orders_have_no_effect_on_the_forecast(): void
    {
        $start = $this->day('2026-03-01');
        $end   = $this->day('2026-03-07')->endOfDay();

        foreach (['01', '02', '03', '04', '05'] as $d) {
            $this->order($this->branchA, 100.00, "2026-03-{$d} 12:00:00");
        }

        $before = $this->service()->forDailySeries(
            $this->series($this->branchA->id, $start, $end),
            $start,
            $end
        );

        // A large cancelled order on a day that already sells, and another on a
        // day that does not — so a leak would move both the average and the
        // selling-day count.
        $this->order($this->branchA, 5000.00, '2026-03-03 15:00:00', 'cancelled');
        $this->order($this->branchA, 5000.00, '2026-03-06 15:00:00', 'cancelled');

        $after = $this->service()->forDailySeries(
            $this->series($this->branchA->id, $start, $end),
            $start,
            $end
        );

        $this->assertSame($before['next_day'], $after['next_day']);
        $this->assertSame($before['days_with_sales'], $after['days_with_sales']);
        $this->assertSame(5, $after['days_with_sales']);
    }

    public function test_an_order_completed_outside_the_range_is_not_an_observation(): void
    {
        // completed_at, not created_at, is the boundary — and it is a real
        // boundary, not a suggestion.
        $this->order($this->branchA, 100.00, '2026-02-28 23:59:00');
        $this->order($this->branchA, 100.00, '2026-03-08 00:01:00');

        $start = $this->day('2026-03-01');
        $end   = $this->day('2026-03-07')->endOfDay();

        $result = $this->service()->forDailySeries(
            $this->series($this->branchA->id, $start, $end),
            $start,
            $end
        );

        $this->assertFalse($result['sufficient']);
        $this->assertSame(0, $result['days_with_sales']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TESTS 8 & 9 — the selected range, and Phase 2a's validation
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_forecast_inputs_follow_the_selected_historical_range(): void
    {
        // March sells; April does not.
        foreach (['01', '02', '03', '04', '05', '06', '07'] as $d) {
            $this->order($this->branchA, 140.00, "2026-03-{$d} 12:00:00");
        }

        $march = $this->service()->forDailySeries(
            $this->series($this->branchA->id, $this->day('2026-03-01'), $this->day('2026-03-07')->endOfDay()),
            $this->day('2026-03-01'),
            $this->day('2026-03-07')->endOfDay()
        );

        $april = $this->service()->forDailySeries(
            $this->series($this->branchA->id, $this->day('2026-04-01'), $this->day('2026-04-07')->endOfDay()),
            $this->day('2026-04-01'),
            $this->day('2026-04-07')->endOfDay()
        );

        $this->assertTrue($march['sufficient']);
        $this->assertSame(140.0, $march['next_day']);
        $this->assertSame('2026-03-08', $march['forecast_start']);

        $this->assertFalse($april['sufficient'], 'A range with no sales must not inherit March.');
        $this->assertNull($april['next_day']);
    }

    public function test_an_inverted_custom_range_still_falls_back_without_forecasting_from_it(): void
    {
        // Phase 2a demoted an inverted range to the safe default and said so in
        // a notice. Phase 2c must not have reopened that: the forecast is built
        // from whatever range the page actually REPORTED on, so if the fallback
        // regressed, a negative-width range would reach the forecast.
        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', [
                'period' => 'custom', 'date_from' => '2026-03-30', 'date_to' => '2026-03-01',
            ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="date-range-notice"', $html);
        $this->assertStringContainsString('Projected Next 7 Days', $html);
    }

    public function test_a_garbage_start_date_does_not_reach_the_forecast(): void
    {
        // "0000-00-00" is the input Carbon parses as year -1; before Phase 2a
        // it spanned 740,277 days. The page must still render, and the forecast
        // must not have become a new way to spend that range.
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', [
                'period' => 'custom', 'date_from' => '0000-00-00', 'date_to' => '2026-03-01',
            ]))->assertOk();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', [
                'period' => 'custom', 'date_from' => 'banana', 'date_to' => 'also-banana',
            ]))->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════════
    // TESTS 10 & 11 — day of week, and Sunday in particular
    // ══════════════════════════════════════════════════════════════════════

    public function test_each_date_maps_to_its_correct_weekday(): void
    {
        // 2026-03-01 is a Sunday. Seven days -> one of each weekday, in
        // Monday-first order regardless of where the range began.
        $rows = $this->service()->byDayOfWeek(
            [10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0],
            $this->day('2026-03-01')
        );

        $this->assertCount(7, $rows);
        $this->assertSame(
            ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            array_column($rows, 'day')
        );

        $byDay = array_column($rows, null, 'day');

        // Sunday was day 0 of the series (10.0); Monday was day 1 (20.0).
        $this->assertSame(10.0, $byDay['Sunday']['average']);
        $this->assertSame(20.0, $byDay['Monday']['average']);
        $this->assertSame(70.0, $byDay['Saturday']['average']);

        foreach ($rows as $row) {
            $this->assertSame(1, $row['observations']);
        }
    }

    public function test_only_weekdays_that_actually_occur_in_the_range_are_listed(): void
    {
        // Sun 2026-03-01 to Tue 2026-03-03. Three rows, not seven padded out
        // with invented zeroes for weekdays the range never contained.
        $rows = $this->service()->byDayOfWeek(
            [100.0, 200.0, 300.0],
            $this->day('2026-03-01')
        );

        $this->assertCount(3, $rows);
        $this->assertSame(['Monday', 'Tuesday', 'Sunday'], array_column($rows, 'day'));
    }

    public function test_sunday_analysis_averages_only_the_sundays_the_range_contains(): void
    {
        // 2026-03-01, 03-08, 03-15 are Sundays. A 21-day range from 03-01
        // contains exactly three of them.
        $values = array_fill(0, 21, 0.0);
        $values[0]  = 900.0;  // Sun 03-01
        $values[7]  = 600.0;  // Sun 03-08
        $values[14] = 300.0;  // Sun 03-15
        $values[1]  = 50.0;   // Mon 03-02 — must not pollute the Sunday row

        $sunday = $this->service()->forWeekday($values, $this->day('2026-03-01'), 7);

        $this->assertNotNull($sunday);
        $this->assertSame('Sunday', $sunday['day']);
        $this->assertSame(3, $sunday['observations'], 'Only the three real Sundays may be counted.');
        $this->assertSame(3, $sunday['days_with_sales']);
        $this->assertSame(600.0, $sunday['average']); // (900 + 600 + 300) / 3
        $this->assertSame(1800.0, $sunday['total']);
    }

    public function test_a_sunday_with_no_sales_is_counted_as_an_observation_not_dropped(): void
    {
        // Two Sundays, one of which took nothing. The average must be halved by
        // it, and the row must say plainly that only one of the two sold.
        $values = array_fill(0, 14, 0.0);
        $values[0] = 800.0; // Sun 03-01
        $values[7] = 0.0;   // Sun 03-08 — a real, quiet Sunday

        $sunday = $this->service()->forWeekday($values, $this->day('2026-03-01'), 7);

        $this->assertSame(2, $sunday['observations']);
        $this->assertSame(1, $sunday['days_with_sales']);
        $this->assertSame(400.0, $sunday['average']);
    }

    public function test_a_weekday_the_range_never_contained_returns_null_not_an_empty_row(): void
    {
        // Sun 03-01 to Tue 03-03 contains no Friday (iso 5).
        $this->assertNull(
            $this->service()->forWeekday([1.0, 2.0, 3.0], $this->day('2026-03-01'), 5)
        );
    }

    public function test_the_weekday_table_reaches_the_page_with_cautious_wording(): void
    {
        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 100.00, sprintf('2026-03-%02d 12:00:00', $d));
        }

        session(['selected_branch_id' => $this->branchA->id]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', [
                'period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-03-07',
            ]))->assertOk()->getContent();

        $this->assertStringContainsString('Historical Demand by Day of Week', $html);
        $this->assertStringContainsString('Sunday', $html);

        // No claim about the future is made from a weekday average.
        $this->assertStringNotContainsString('will be higher', $html);
        $this->assertStringNotContainsString('will be busier', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TESTS 12 & 13 — menu-level forecasting
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_menu_item_with_enough_history_gets_a_forecast(): void
    {
        $item = $this->menuItem($this->branchA, 'Popular Cake');

        // Sold on 7 consecutive days, 2 units a day, all inside the window.
        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 200.00, sprintf('2026-03-%02d 12:00:00', $d), 'completed', $item, 2);
        }

        $result = $this->service()->forMenuItems(
            $this->branchA->id,
            $this->day('2026-03-01'),
            $this->day('2026-03-07')->endOfDay()
        );

        $row = collect($result['rows'])->firstWhere('menu_item_id', $item->id);

        $this->assertNotNull($row, 'An item sold every day should be in the ranked rows.');
        $this->assertTrue($row['sufficient']);
        $this->assertSame(7, $row['days_with_sales']);
        $this->assertSame(2.0, $row['forecast_qty_per_day']);   // 14 units / 7 days
        $this->assertSame(14.0, $row['forecast_qty_next_7_days']);
        $this->assertSame(14, $row['total_qty_in_range']);
        $this->assertSame(1, $result['items_forecastable']);
    }

    public function test_a_menu_item_with_too_little_history_returns_insufficient_not_zero(): void
    {
        $popular = $this->menuItem($this->branchA, 'Popular Cake');
        $rare    = $this->menuItem($this->branchA, 'Rare Cake');

        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 200.00, sprintf('2026-03-%02d 12:00:00', $d), 'completed', $popular, 2);
        }

        // Two selling days; the bar is five.
        $this->order($this->branchA, 100.00, '2026-03-02 12:00:00', 'completed', $rare, 1);
        $this->order($this->branchA, 100.00, '2026-03-04 12:00:00', 'completed', $rare, 1);

        $result = $this->service()->forMenuItems(
            $this->branchA->id,
            $this->day('2026-03-01'),
            $this->day('2026-03-07')->endOfDay(),
            10
        );

        $row = collect($result['rows'])->firstWhere('menu_item_id', $rare->id);

        $this->assertNotNull($row, 'An item that sold at all should be listed, and told it lacks history.');
        $this->assertFalse($row['sufficient']);
        $this->assertSame(2, $row['days_with_sales']);
        $this->assertSame(
            'Insufficient historical data for menu-level forecasting.',
            $row['message']
        );

        // Again: null, never 0. A zero would assert nobody wants this item.
        $this->assertNull($row['forecast_qty_per_day']);
        $this->assertNull($row['forecast_qty_next_7_days']);
        $this->assertNotSame(0.0, $row['forecast_qty_per_day']);

        // Forecastable items rank above unforecastable ones.
        $this->assertSame($popular->id, $result['rows'][0]['menu_item_id']);
        $this->assertSame(2, $result['items_with_history']);
        $this->assertSame(1, $result['items_forecastable']);
    }

    public function test_a_menu_item_that_never_sold_is_not_listed_at_all(): void
    {
        $sold   = $this->menuItem($this->branchA, 'Sold Cake');
        $unsold = $this->menuItem($this->branchA, 'Unsold Cake');

        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 200.00, sprintf('2026-03-%02d 12:00:00', $d), 'completed', $sold, 2);
        }

        $result = $this->service()->forMenuItems(
            $this->branchA->id,
            $this->day('2026-03-01'),
            $this->day('2026-03-07')->endOfDay(),
            50
        );

        $ids = array_column($result['rows'], 'menu_item_id');

        $this->assertContains($sold->id, $ids);
        $this->assertNotContains(
            $unsold->id,
            $ids,
            'An item with no sales has nothing to forecast from and must not be given a zero row.'
        );
    }

    public function test_menu_forecasting_ignores_cancelled_orders(): void
    {
        $item = $this->menuItem($this->branchA, 'Cancelled Cake');

        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 200.00, sprintf('2026-03-%02d 12:00:00', $d), 'cancelled', $item, 9);
        }

        $result = $this->service()->forMenuItems(
            $this->branchA->id,
            $this->day('2026-03-01'),
            $this->day('2026-03-07')->endOfDay(),
            50
        );

        $this->assertNotContains($item->id, array_column($result['rows'], 'menu_item_id'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // TESTS 14, 15 & 16 — branch scope
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_specific_branch_forecasts_only_from_its_own_sales(): void
    {
        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 100.00, sprintf('2026-03-%02d 12:00:00', $d));
            $this->order($this->branchB, 900.00, sprintf('2026-03-%02d 12:00:00', $d));
        }

        $start = $this->day('2026-03-01');
        $end   = $this->day('2026-03-07')->endOfDay();

        $a = $this->service()->forDailySeries($this->series($this->branchA->id, $start, $end), $start, $end);
        $b = $this->service()->forDailySeries($this->series($this->branchB->id, $start, $end), $start, $end);

        $this->assertSame(100.0, $a['next_day']);
        $this->assertSame(900.0, $b['next_day']);
    }

    public function test_menu_forecasting_is_scoped_to_the_selected_branch(): void
    {
        $itemA = $this->menuItem($this->branchA, 'Branch A Cake');
        $itemB = $this->menuItem($this->branchB, 'Branch B Cake');

        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 100.00, sprintf('2026-03-%02d 12:00:00', $d), 'completed', $itemA, 1);
            $this->order($this->branchB, 100.00, sprintf('2026-03-%02d 12:00:00', $d), 'completed', $itemB, 1);
        }

        $result = $this->service()->forMenuItems(
            $this->branchA->id,
            $this->day('2026-03-01'),
            $this->day('2026-03-07')->endOfDay(),
            50
        );

        $ids = array_column($result['rows'], 'menu_item_id');

        $this->assertContains($itemA->id, $ids);
        $this->assertNotContains($itemB->id, $ids, 'Branch B demand must never appear under Branch A.');
    }

    public function test_a_branch_locked_supervisor_cannot_widen_the_forecast_scope_via_the_querystring(): void
    {
        // Branch B sells ten times as much as Branch A. A supervisor locked to
        // A must see A's forecast whatever they put in the URL — and must not
        // see B's figure anywhere on the page.
        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 100.00, sprintf('2026-03-%02d 12:00:00', $d));
            $this->order($this->branchB, 1000.00, sprintf('2026-03-%02d 12:00:00', $d));
        }

        $supervisor = $this->supervisor($this->branchA);

        foreach ([
            ['branch_id' => $this->branchB->id],
            ['branch' => $this->branchB->id],
            ['scope' => 'all'],
            ['selected_branch_id' => $this->branchB->id],
        ] as $tamper) {
            $html = $this->actingAs($supervisor, 'admin')
                ->get(route('admin.analytics', $tamper + [
                    'period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-03-07',
                ]))->assertOk()->getContent();

            $card = $this->sliceForecastCard($html);

            // A's projection: 100/day * 7 = ₱700.00. B's would be ₱7,000.00.
            $this->assertStringContainsString('₱700.00', $card);
            $this->assertStringNotContainsString('₱7,000.00', $card);
        }
    }

    public function test_all_branches_consolidates_both_branches(): void
    {
        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 100.00, sprintf('2026-03-%02d 12:00:00', $d));
            $this->order($this->branchB, 400.00, sprintf('2026-03-%02d 12:00:00', $d));
        }

        $start = $this->day('2026-03-01');
        $end   = $this->day('2026-03-07')->endOfDay();

        $a   = $this->service()->forDailySeries($this->series($this->branchA->id, $start, $end), $start, $end);
        $b   = $this->service()->forDailySeries($this->series($this->branchB->id, $start, $end), $start, $end);
        $all = $this->service()->forDailySeries($this->series('all', $start, $end), $start, $end);

        $this->assertSame(100.0, $a['next_day']);
        $this->assertSame(400.0, $b['next_day']);

        // 'all' is the existing consolidated behaviour: everything in one
        // series. It is >= A + B because pomida_db_testing carries other
        // branches' ambient rows too — the property is that it INCLUDES both,
        // not that it equals exactly their sum.
        $this->assertGreaterThanOrEqual(500.0, $all['next_day']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TESTS 17 & 18 — the chart
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_chart_separates_historical_labels_from_forecast_labels(): void
    {
        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 100.00, sprintf('2026-03-%02d 12:00:00', $d));
        }

        session(['selected_branch_id' => $this->branchA->id]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', [
                'period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-03-07',
            ]))->assertOk()->getContent();

        // One graph, and it is the trend/forecast one.
        $this->assertSame(1, substr_count($html, '<canvas'));
        $this->assertStringContainsString('salesTrendChart', $html);

        // Historical days are the seven the range covers; the forecast points
        // carry the seven dates that follow, and no historical date appears
        // among them.
        $this->assertStringContainsString('"2026-03-08"', $html);
        $this->assertStringContainsString('"2026-03-14"', $html);

        $forecastJson = $this->sliceForecastPoints($html);
        foreach (['2026-03-01', '2026-03-07'] as $historicalDate) {
            $this->assertStringNotContainsString(
                '"' . $historicalDate . '"',
                $forecastJson,
                'A historical date must never appear as a forecast point.'
            );
        }

        // Solid actuals, dashed forecast. The dashed dataset is built only
        // when forecastPoints is non-empty, so the DATA above is what decides
        // whether it is drawn — the borderDash literal itself is static
        // script text and would be present either way.
        $this->assertStringContainsString('borderDash', $html);
        $this->assertStringContainsString('if (forecastPoints.length)', $html);
        $this->assertStringContainsString('Actual Sales', $html);
        $this->assertStringContainsString('Forecast (₱, estimated)', $html);
    }

    /** The JSON array the view hands the chart as forecastPoints. */
    private function sliceForecastPoints(string $html): string
    {
        $from = strpos($html, 'const forecastPoints =');
        $this->assertNotFalse($from);
        $to = strpos($html, ';', $from);

        return substr($html, $from, $to - $from);
    }

    public function test_no_forecast_line_is_drawn_when_the_data_is_insufficient(): void
    {
        // Branch B has nothing at all in this window.
        session(['selected_branch_id' => $this->branchB->id]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', [
                'period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-03-30',
            ]))->assertOk()->getContent();

        // The forecast dataset is an EMPTY array — not seven zero points
        // squaring the chart off into next week. Because the dashed dataset is
        // pushed only `if (forecastPoints.length)`, an empty array is exactly
        // what "no forecast line" means at runtime.
        $this->assertStringContainsString('const forecastPoints = [];', $html);
        $this->assertStringContainsString('if (forecastPoints.length)', $html);

        // No forecast DATE reaches the page either, so there is nothing for a
        // future chart change to accidentally start plotting.
        $this->assertStringNotContainsString('"2026-03-31"', $html);

        // Still exactly one graph: the historical line is drawn as usual.
        $this->assertSame(1, substr_count($html, '<canvas'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 19 — the query budget
    // ══════════════════════════════════════════════════════════════════════

    /**
     * What the WHOLE forecast may cost, on top of the page's existing work.
     *
     * The sales forecast and the weekday table are arithmetic over the daily
     * series the chart has already fetched, so between them they add NOTHING.
     * The menu-level forecast adds exactly one grouped query covering every
     * item and every day at once. Two is the ceiling rather than one so a
     * legitimate future addition has a little room; what it exists to catch is
     * a per-day or per-item loop coming back, and those land in the dozens or
     * hundreds.
     */
    private const FORECAST_QUERY_CEILING = 2;

    private function queriesFor(callable $fn): int
    {
        // A fresh function scope per call — Laravel cannot detach a DB::listen
        // closure, and two live counters in one scope report double.
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

    public function test_the_forecast_does_not_return_to_one_query_per_day(): void
    {
        $item = $this->menuItem($this->branchA, 'Budget Cake');

        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 100.00, sprintf('2026-03-%02d 12:00:00', $d), 'completed', $item, 1);
        }

        // Range widths spanning four orders of magnitude. If any part of the
        // forecast walked the calendar or the menu, the count would climb with
        // them; it must not move at all.
        foreach ([
            '7 days'   => ['2026-03-01', '2026-03-07'],
            '30 days'  => ['2026-02-06', '2026-03-07'],
            '1 year'   => ['2025-03-07', '2026-03-07'],
            '11 years' => ['2015-01-01', '2026-03-07'],
        ] as $label => [$from, $to]) {
            $start = $this->day($from);
            $end   = $this->day($to)->endOfDay();

            // The series is fetched OUTSIDE the measured block, exactly as the
            // page fetches it once for the chart and reuses it here.
            $values = $this->series($this->branchA->id, $start, $end);

            $count = $this->queriesFor(function () use ($values, $start, $end) {
                $service = $this->service();
                $service->forDailySeries($values, $start, $end);
                $service->byDayOfWeek($values, $start);
                $service->forMenuItems($this->branchA->id, $start, $end, 5);
            });

            $this->assertLessThanOrEqual(
                self::FORECAST_QUERY_CEILING,
                $count,
                "The forecast ran {$count} queries for a {$label} range; the budget is "
                    . self::FORECAST_QUERY_CEILING . ' whatever the range width.'
            );
        }
    }

    public function test_the_sales_forecast_and_weekday_analysis_cost_no_queries_at_all(): void
    {
        $start = $this->day('2026-03-01');
        $end   = $this->day('2026-03-07')->endOfDay();
        $values = [100.0, 100.0, 100.0, 100.0, 100.0, 100.0, 100.0];

        $count = $this->queriesFor(function () use ($values, $start, $end) {
            $service = $this->service();
            $service->forDailySeries($values, $start, $end);
            $service->byDayOfWeek($values, $start);
            $service->forWeekday($values, $start, 7);
        });

        $this->assertSame(
            0,
            $count,
            'These three read the series the page already fetched; they must not go back to the database.'
        );
    }

    public function test_menu_forecasting_costs_one_query_however_many_items_there_are(): void
    {
        $items = [];
        foreach (range(1, 6) as $n) {
            $items[] = $this->menuItem($this->branchA, "Budget Cake {$n}");
        }

        foreach ($items as $item) {
            foreach (range(1, 7) as $d) {
                $this->order($this->branchA, 100.00, sprintf('2026-03-%02d 12:00:00', $d), 'completed', $item, 1);
            }
        }

        $count = $this->queriesFor(function () {
            $this->service()->forMenuItems(
                $this->branchA->id,
                $this->day('2026-03-01'),
                $this->day('2026-03-07')->endOfDay(),
                50
            );
        });

        $this->assertSame(1, $count, 'Six items must cost the same one grouped query as one item would.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // Honesty guarantees the UI must keep
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_page_never_claims_confidence_or_accuracy(): void
    {
        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 100.00, sprintf('2026-03-%02d 12:00:00', $d));
        }

        session(['selected_branch_id' => $this->branchA->id]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', [
                'period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-03-07',
            ]))->assertOk()->getContent();

        // Bare "guaranteed" is deliberately NOT on this list: the page's own
        // caveat is "not a guaranteed figure", and forbidding the word would
        // forbid the disclaimer along with the claim. What is banned is the
        // CLAIM — a confidence, an accuracy, a probability, or a promise —
        // because the project calculates none of those and a fabricated one
        // would be the most misleading thing this page could show.
        foreach ([
            'confidence',
            'accuracy',
            'accurate',
            'probability',
            'guaranteed demand',
            'guaranteed sales',
            'AI prediction',
            'will be exactly',
            'will be higher',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $html,
                "The Analytics page must not claim \"{$forbidden}\" — no such figure is calculated."
            );
        }

        // And it does label the projection for what it is.
        $this->assertStringContainsString('Projected Next 7 Days', $html);
        $this->assertStringContainsString('Estimated', $html);
        $this->assertStringContainsString('not a guaranteed figure', $html);
    }

    public function test_only_one_forecasting_method_is_presented(): void
    {
        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 100.00, sprintf('2026-03-%02d 12:00:00', $d));
        }

        session(['selected_branch_id' => $this->branchA->id]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', [
                'period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-03-07',
            ]))->assertOk()->getContent();

        $this->assertStringContainsString('moving average', $html);

        // The dormant SLR forecast must not surface alongside it — two
        // competing forecasts on one page is two numbers the owner has to
        // choose between with nothing to choose on.
        foreach (['Linear Regression', 'regression', 'slope', 'intercept'] as $slr) {
            $this->assertStringNotContainsStringIgnoringCase($slr, $html);
        }
    }

    public function test_the_threshold_is_centralised_rather_than_scattered(): void
    {
        // The menu-level bar is the SAME constant as the branch-level one, and
        // both alias AnalyticsService's long-standing threshold. If someone
        // introduces a second literal, this is where it is noticed.
        $this->assertSame(
            AnalyticsService::FORECAST_MIN_DAYS_WITH_SALES,
            DemandForecastService::MIN_DAYS_WITH_SALES
        );
        $this->assertSame(
            DemandForecastService::MIN_DAYS_WITH_SALES,
            DemandForecastService::MENU_MIN_DAYS_WITH_SALES
        );
    }

    public function test_the_print_view_carries_the_same_forecast_as_the_screen(): void
    {
        foreach (range(1, 7) as $d) {
            $this->order($this->branchA, 100.00, sprintf('2026-03-%02d 12:00:00', $d));
        }

        session(['selected_branch_id' => $this->branchA->id]);

        $params = ['period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-03-07'];

        $screen = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', $params))->assertOk()->getContent();

        $print = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics.print', $params))->assertOk()->getContent();

        // 100/day * 7 = ₱700.00 on both.
        $this->assertStringContainsString('₱700.00', $this->sliceForecastCard($screen));
        $this->assertStringContainsString('₱700.00', $print);
        $this->assertStringContainsString('Demand Forecast', $print);
        $this->assertStringContainsString('not a guaranteed figure', $print);
    }
}

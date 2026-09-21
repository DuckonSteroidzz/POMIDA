<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Custom date-range handling on the four report endpoints that accept
 * date_from / date_to off the querystring (Phase 2a, item 1):
 *
 *      admin.analytics
 *      admin.analytics.print
 *      admin.summary
 *      admin.export.orders
 *
 * ── WHAT ACTUALLY REPRODUCED ───────────────────────────────────────────────
 *
 * The brief listed four bad inputs. Measured against the code as it stood,
 * they did NOT all behave the same way, and two of them were not 500s at all:
 *
 *  - UNPARSEABLE ("banana", "2026-13-45") -> HTTP 500 on all four. Carbon::parse()
 *    threw InvalidFormatException straight out of the controller. This is the
 *    reported bug.
 *
 *  - "0000-00-00" -> HTTP 200. Carbon parses it happily as year -1
 *    (-0001-11-30), so nothing threw. With both bounds zeroed it is a one-day
 *    range, which is why it looked harmless. Paired with a real end date it
 *    spanned 740,277 days, and dailySalesSeriesForRange() then ran one query
 *    PER DAY — a single querystring that any authenticated report viewer could
 *    send, costing three quarters of a million queries. Worse than the 500,
 *    and invisible in a status code.
 *
 *  - INVERTED (from > to) -> HTTP 200, silently wrong. Carbon 3's diffInDays()
 *    is SIGNED, so resolveSummaryPeriod()'s `$days = diffInDays + 1` went
 *    negative and subDays() on a negative count ADDS days: the "previous
 *    period" behind Summary's % change badge landed in the FUTURE.
 *
 *  - INCOMPLETE (one bound blank) -> HTTP 200, and ALREADY CORRECT. Both
 *    showSummary() and resolveAnalyticsPeriod() already fell back when a bound
 *    was missing. Nothing was fixed here; the tests below pin the existing
 *    behaviour so it cannot regress while the rest of the parsing changes
 *    around it.
 *
 * ── ALWAYS PAIR REFUSAL WITH ACCEPTANCE ────────────────────────────────────
 *
 * Every rejection test below has a matching acceptance test: the same endpoint,
 * a VALID custom range, still resolving to exactly that range and still
 * reporting the figures inside it. A validator that quietly broke the ordinary
 * path would pass a refusal-only suite.
 */
class ReportDateRangeValidationTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'DATEVALID';
    private const ORDER_PREFIX = 'DVAL-';

    /** Every endpoint under test, and how its period is spelled. */
    private const ENDPOINTS = [
        'admin.analytics',
        'admin.analytics.print',
        'admin.summary',
        'admin.export.orders',
    ];

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
            'code'      => 'DVA' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address A',
            'is_active' => true,
        ]);

        $this->branchB = Branch::create([
            'name'      => self::PREFIX . ' Branch B ' . uniqid(),
            'code'      => 'DVB' . strtoupper(substr(uniqid(), -6)),
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

    // ── fixtures ────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function supervisor(Branch $branch): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Supervisor ' . uniqid(),
            'email'     => 'datevalid-' . uniqid() . '@example.test',
            'password'  => bcrypt('Sup3rStr0ng!Pass'),
            'role'      => 'supervisor',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

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

    /** GET one report endpoint with a custom range, fully realising any stream. */
    private function getReport(User $as, string $route, array $params)
    {
        $response = $this->actingAs($as, 'admin')
            ->get(route($route, array_merge(['period' => 'custom'], $params)));

        // A streamed CSV only executes its callback when the content is read,
        // so an exception inside exportOrders()'s closure would otherwise never
        // surface and the test would pass on a broken endpoint.
        if ($response->getStatusCode() === 200 && $route === 'admin.export.orders') {
            $response->streamedContent();
        }

        return $response;
    }

    // ══════════════════════════════════════════════════════════════════════
    // REFUSAL — every bad shape, every endpoint, no 500
    // ══════════════════════════════════════════════════════════════════════

    public static function badRangeProvider(): array
    {
        return [
            // label                        from            to
            'unparseable words'        => ['banana',      'kiwi'],
            'impossible month and day' => ['2026-13-45',  '2026-13-46'],
            'zero date, both bounds'   => ['0000-00-00',  '0000-00-00'],
            'zero date, real end'      => ['0000-00-00',  '2026-09-20'],
            'impossible day only'      => ['2026-02-31',  '2026-03-05'],
            'year-month only'          => ['2026-09',     '2026-09-20'],
            'unpadded'                 => ['2026-9-1',    '2026-9-20'],
            'slashes'                  => ['2026/09/01',  '2026/09/20'],
            'inverted'                 => ['2026-09-20',  '2026-09-01'],
            'incomplete, no end'       => ['2026-09-01',  ''],
            'incomplete, no start'     => ['',            '2026-09-20'],
            'absurd year'              => ['1200-01-01',  '2026-09-20'],
            'sql-ish'                  => ["2026-09-01' OR '1'='1", '2026-09-20'],
        ];
    }

    /**
     * @dataProvider badRangeProvider
     */
    public function test_a_bad_custom_range_never_errors_on_any_report_endpoint(string $from, string $to): void
    {
        $admin = $this->admin();

        foreach (self::ENDPOINTS as $route) {
            $response = $this->getReport($admin, $route, ['date_from' => $from, 'date_to' => $to]);

            $this->assertSame(
                200,
                $response->getStatusCode(),
                "{$route} answered {$response->getStatusCode()} for date_from='{$from}' date_to='{$to}'."
            );
        }
    }

    public function test_an_unparseable_range_falls_back_and_says_so_on_analytics(): void
    {
        $html = $this->getReport($this->admin(), 'admin.analytics', [
            'date_from' => 'banana', 'date_to' => 'kiwi',
        ])->assertOk()->getContent();

        // Told, not silently swapped underneath the viewer.
        $this->assertStringContainsString('data-testid="date-range-notice"', $html);
        $this->assertStringContainsString('was not valid', $html);

        // Fell back to the page's own default preset, and the selector agrees
        // with the figures rather than still claiming "Custom Range".
        $this->assertStringContainsString('value="last30" selected', $html);

        // The rejected string is not echoed back into the date input.
        $this->assertStringNotContainsString('value="banana"', $html);
    }

    public function test_an_unparseable_range_falls_back_and_says_so_on_summary(): void
    {
        $html = $this->getReport($this->admin(), 'admin.summary', [
            'date_from' => 'banana', 'date_to' => 'kiwi',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="date-range-notice"', $html);
        $this->assertStringContainsString('was not valid', $html);
        $this->assertStringNotContainsString('value="banana"', $html);
    }

    public function test_the_printed_analytics_sheet_carries_the_notice_too(): void
    {
        // A printed page leaves the browser that would otherwise show the
        // notice, so the fallback has to be stated on the paper.
        $html = $this->getReport($this->admin(), 'admin.analytics.print', [
            'date_from' => 'banana', 'date_to' => 'kiwi',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="date-range-notice"', $html);
        $this->assertStringContainsString('was not valid', $html);
    }

    public function test_an_inverted_range_is_swapped_rather_than_refused(): void
    {
        // Sales inside the range, entered backwards. The figures must be the
        // figures for that range, not an empty window and not a 500.
        $this->completedOrder($this->branchA, 1234.00, '2026-03-03 10:00:00');

        $html = $this->actingAs($this->supervisor($this->branchA), 'admin')
            ->get(route('admin.analytics', [
                'period' => 'custom', 'date_from' => '2026-03-05', 'date_to' => '2026-03-01',
            ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="date-range-notice"', $html);
        $this->assertStringContainsString('swapped', $html);

        // Reported on Mar 01 - Mar 05, in that order, and the sale inside it counted.
        $this->assertStringContainsString('Mar 01, 2026', $html);
        $this->assertStringContainsString('Mar 05, 2026', $html);
        $this->assertStringContainsString('₱1,234.00', $html);
    }

    public function test_an_inverted_range_no_longer_puts_summarys_previous_period_in_the_future(): void
    {
        // The signed-diffInDays bug: before the swap, the comparison window
        // behind the % change badge was computed from a NEGATIVE day count and
        // landed after the range instead of before it.
        $html = $this->getReport($this->admin(), 'admin.summary', [
            'date_from' => '2026-03-10', 'date_to' => '2026-03-01',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('swapped', $html);

        // Whatever the previous period is, it must START BEFORE the range does.
        // Pulled from the rendered print block, which states both windows.
        $this->assertMatchesRegularExpression('/Mar 01, 2026\s*&ndash;\s*Mar 10, 2026/', $html);
    }

    public function test_the_zero_date_range_cannot_span_seven_hundred_thousand_days(): void
    {
        // The denial-of-service shape. Before validation this resolved to
        // year -1 and issued one query per day for 740,277 days.
        $count = 0;
        $listening = true;
        DB::listen(function () use (&$count, &$listening) {
            if ($listening) { $count++; }
        });

        $this->getReport($this->admin(), 'admin.analytics', [
            'date_from' => '0000-00-00', 'date_to' => '2026-09-20',
        ])->assertOk();

        $listening = false;

        $this->assertLessThan(
            40,
            $count,
            "A 0000-00-00 start date cost {$count} queries; it must be rejected before it can span anything."
        );
    }

    public function test_incomplete_bounds_fall_back_silently_rather_than_scolding(): void
    {
        // This case was ALREADY handled before this pass, and deliberately
        // stays silent: an empty second box is someone still filling the form
        // in, not a mistake worth a message.
        $html = $this->getReport($this->admin(), 'admin.analytics', [
            'date_from' => '2026-09-01', 'date_to' => '',
        ])->assertOk()->getContent();

        $this->assertStringNotContainsString('data-testid="date-range-notice"', $html);
        $this->assertStringContainsString('value="last30" selected', $html);
    }

    public function test_the_csv_still_streams_and_states_the_period_it_fell_back_to(): void
    {
        // A CSV has nowhere to render a notice, so the header block it already
        // writes has to state the window actually reported on.
        $csv = $this->getReport($this->admin(), 'admin.export.orders', [
            'date_from' => 'banana', 'date_to' => 'kiwi',
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('Sales & Profit Report', $csv);
        $this->assertStringContainsString('Period', $csv);
        $this->assertStringContainsString(now()->format('M d, Y'), $csv);
        $this->assertStringNotContainsString('banana', $csv);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ACCEPTANCE — the valid path is untouched
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_valid_custom_range_still_resolves_to_exactly_that_range(): void
    {
        $this->completedOrder($this->branchA, 555.00, '2026-03-03 10:00:00');
        // Outside the range on both sides — must not be counted.
        $this->completedOrder($this->branchA, 900.00, '2026-02-27 10:00:00');
        $this->completedOrder($this->branchA, 900.00, '2026-03-09 10:00:00');

        $html = $this->actingAs($this->supervisor($this->branchA), 'admin')
            ->get(route('admin.analytics', [
                'period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-03-05',
            ]))->assertOk()->getContent();

        // No notice at all on a good request.
        $this->assertStringNotContainsString('data-testid="date-range-notice"', $html);

        // The selector still says Custom Range, and the boxes still hold the
        // submitted dates.
        $this->assertStringContainsString('value="custom" selected', $html);
        $this->assertStringContainsString('value="2026-03-01"', $html);
        $this->assertStringContainsString('value="2026-03-05"', $html);

        $this->assertStringContainsString('Mar 01, 2026', $html);
        $this->assertStringContainsString('Mar 05, 2026', $html);
        $this->assertStringContainsString('₱555.00', $html);
        $this->assertStringNotContainsString('₱900.00', $html);
    }

    public function test_a_valid_custom_range_still_works_on_all_four_endpoints(): void
    {
        $this->completedOrder($this->branchA, 555.00, '2026-03-03 10:00:00');
        $admin = $this->admin();

        foreach (self::ENDPOINTS as $route) {
            $this->getReport($admin, $route, [
                'date_from' => '2026-03-01', 'date_to' => '2026-03-05',
            ])->assertOk();
        }

        // And the CSV names the requested window in its filename, unchanged.
        $response = $this->actingAs($admin, 'admin')->get(route('admin.export.orders', [
            'period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-03-05',
        ]))->assertOk();

        $this->assertStringContainsString(
            'sales-report_2026-03-01_to_2026-03-05.csv',
            $response->headers->get('content-disposition')
        );
    }

    public function test_the_named_presets_are_unaffected(): void
    {
        $admin = $this->admin();

        foreach (['today', 'last7', 'last30', 'month'] as $preset) {
            $this->actingAs($admin, 'admin')
                ->get(route('admin.analytics', ['period' => $preset]))
                ->assertOk();
        }

        foreach (['today', 'week', 'month'] as $preset) {
            $this->actingAs($admin, 'admin')
                ->get(route('admin.summary', ['period' => $preset]))
                ->assertOk();
        }
    }

    public function test_an_unrecognised_period_still_falls_back_without_a_notice(): void
    {
        // Pre-existing behaviour, not part of this fix, but it shares the
        // switch the fix touched.
        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', ['period' => 'fortnight']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('value="last30" selected', $html);
        $this->assertStringNotContainsString('data-testid="date-range-notice"', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // SECURITY — a bad range must not become a way to widen branch scope
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_rejected_range_does_not_widen_a_locked_supervisors_branch_scope(): void
    {
        // The fallback path builds a fresh period. It must not also rebuild the
        // BRANCH scope from anything the request said — a locked supervisor
        // still sees only their own branch on the fallback period.
        $this->completedOrder($this->branchA, 111.00, now()->subDay()->toDateTimeString());
        $this->completedOrder($this->branchB, 222.00, now()->subDay()->toDateTimeString());

        $html = $this->actingAs($this->supervisor($this->branchA), 'admin')
            ->get(route('admin.analytics', [
                'period' => 'custom', 'date_from' => 'banana', 'date_to' => 'kiwi',
                'branch_id' => $this->branchB->id,
            ]))->assertOk()->getContent();

        $this->assertStringContainsString('₱111.00', $html);
        $this->assertStringNotContainsString('₱222.00', $html);
        $this->assertStringNotContainsString($this->branchB->name, $html);
    }

    public function test_staff_still_cannot_reach_the_analytics_endpoints_with_any_range(): void
    {
        $staff = User::create([
            'name'      => self::PREFIX . ' Staff ' . uniqid(),
            'email'     => 'datevalid-staff-' . uniqid() . '@example.test',
            'password'  => bcrypt('Sup3rStr0ng!Pass'),
            'role'      => 'staff',
            'branch_id' => $this->branchA->id,
            'is_active' => true,
        ]);

        // A malformed range must not become a way around the role gate — the
        // gate is middleware and runs before any of this parsing.
        foreach (['admin.analytics', 'admin.analytics.print', 'admin.export.orders'] as $route) {
            $response = $this->actingAs($staff, 'admin')
                ->get(route($route, ['period' => 'custom', 'date_from' => 'banana', 'date_to' => 'kiwi']));

            $this->assertNotSame(200, $response->getStatusCode(), "{$route} let staff through.");
        }
    }
}

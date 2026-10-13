<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\StreakCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Streak Calendar's arithmetic (App\Services\StreakCalendar).
 *
 * Every fixture order is created here, for an account created here, inside
 * DatabaseTransactions — nothing survives the run. "Now" is pinned to
 * 2026-10-15 12:00 Manila (04:00 UTC) so day boundaries are deterministic.
 * Order times are written in UTC, the way the app stores them
 * (APP_TIMEZONE=UTC), via manila() so each fixture reads as a shop-local time.
 */
class StreakCalendarTest extends TestCase
{
    use DatabaseTransactions;

    private const NOW_UTC = '2026-10-15 04:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());
        $this->assertSame('UTC', config('app.timezone'), 'fixtures below assume the app stores UTC');

        $this->travelTo(CarbonImmutable::parse(self::NOW_UTC, 'UTC'));
    }

    private function customer(array $overrides = []): User
    {
        $user = User::create(array_merge([
            'name'              => 'Streak Test',
            'email'             => 'streak-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ], $overrides));

        // created_at is not fillable; back-date it so earlier months are in range.
        DB::table('users')->where('id', $user->id)->update(['created_at' => '2026-01-01 00:00:00']);

        return $user->fresh();
    }

    /** A Manila wall-clock time, as the UTC string the database stores. */
    private function manila(string $local): string
    {
        return CarbonImmutable::parse($local, 'Asia/Manila')->utc()->format('Y-m-d H:i:s');
    }

    private function order(User $user, string $manilaTime, string $status = 'completed'): Order
    {
        return Order::create([
            'order_number'   => 'STK-' . strtoupper(Str::random(8)),
            'user_id'        => $user->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => $status,
            'payment_method' => 'cash',
            'payment_status' => $status === 'completed' ? 'paid' : 'pending',
            'subtotal'       => 100,
            'total'          => 100,
            // Set even on the non-completed fixtures, so a test proves the
            // STATUS is what excludes them, not a missing timestamp.
            'completed_at'   => $this->manila($manilaTime),
        ]);
    }

    private function today(): CarbonImmutable
    {
        return StreakCalendar::today();
    }

    // ══════════ consecutive days and gaps ══════════

    public function test_today_is_taken_in_the_shop_zone(): void
    {
        $this->assertSame('2026-10-15', $this->today()->format('Y-m-d'));
        $this->assertSame('Asia/Manila', $this->today()->getTimezone()->getName());
    }

    public function test_three_consecutive_days_ending_today_make_a_three_day_streak(): void
    {
        $user = $this->customer();
        $this->order($user, '2026-10-13 09:00');
        $this->order($user, '2026-10-14 18:00');
        $this->order($user, '2026-10-15 11:00');

        $days = StreakCalendar::completedDays($user);
        $current = StreakCalendar::current($days, $this->today());

        $this->assertSame(3, $current['length']);
        $this->assertSame('2026-10-13', $current['start']);
        $this->assertTrue($current['alive_today']);
        $this->assertSame(3, StreakCalendar::best($days));
    }

    public function test_several_orders_on_one_day_count_as_one_day(): void
    {
        $user = $this->customer();
        $this->order($user, '2026-10-15 08:00');
        $this->order($user, '2026-10-15 10:00');
        $this->order($user, '2026-10-15 11:30');

        $this->assertSame(['2026-10-15' => true], StreakCalendar::completedDays($user));
        $this->assertSame(1, StreakCalendar::current(StreakCalendar::completedDays($user), $this->today())['length']);
    }

    public function test_a_missed_day_breaks_the_streak_but_best_remembers_the_longer_run(): void
    {
        $user = $this->customer();
        // A 4-day run, a gap on the 10th, then 2 days.
        foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'] as $d) {
            $this->order($user, "{$d} 12:00");
        }
        $this->order($user, '2026-10-14 12:00');
        $this->order($user, '2026-10-15 09:00');

        $days = StreakCalendar::completedDays($user);

        $this->assertSame(2, StreakCalendar::current($days, $this->today())['length']);
        $this->assertSame(4, StreakCalendar::best($days));
    }

    public function test_a_streak_that_ended_yesterday_is_still_shown_until_today_ends(): void
    {
        $user = $this->customer();
        $this->order($user, '2026-10-13 12:00');
        $this->order($user, '2026-10-14 12:00');

        $current = StreakCalendar::current(StreakCalendar::completedDays($user), $this->today());

        $this->assertSame(2, $current['length']);
        $this->assertFalse($current['alive_today']);
    }

    public function test_a_streak_whose_last_day_was_two_days_ago_is_over(): void
    {
        $user = $this->customer();
        $this->order($user, '2026-10-12 12:00');
        $this->order($user, '2026-10-13 12:00');

        $current = StreakCalendar::current(StreakCalendar::completedDays($user), $this->today());

        $this->assertSame(0, $current['length']);
        $this->assertSame(2, StreakCalendar::best(StreakCalendar::completedDays($user)));
    }

    public function test_a_customer_with_no_completed_orders_has_no_streak(): void
    {
        $user = $this->customer();

        $this->assertSame([], StreakCalendar::completedDays($user));
        $this->assertSame(0, StreakCalendar::current([], $this->today())['length']);
        $this->assertSame(0, StreakCalendar::best([]));
    }

    public function test_a_streak_across_a_month_boundary_is_one_streak(): void
    {
        $user = $this->customer();
        $this->order($user, '2026-09-29 12:00');
        $this->order($user, '2026-09-30 12:00');
        $this->order($user, '2026-10-01 12:00');

        $this->assertSame(3, StreakCalendar::best(StreakCalendar::completedDays($user)));
    }

    // ══════════ time zone boundaries ══════════

    /**
     * 00:30 Manila on the 15th is 16:30 UTC on the 14th. Bucketed by the raw
     * UTC value it would land on the 14th; it belongs to the 15th.
     */
    public function test_just_after_midnight_in_manila_is_the_new_day_even_though_utc_is_still_yesterday(): void
    {
        $user = $this->customer();
        $order = $this->order($user, '2026-10-15 00:30');

        $this->assertSame('2026-10-14 16:30:00', DB::table('orders')->where('id', $order->id)->value('completed_at'));
        $this->assertSame(['2026-10-15' => true], StreakCalendar::completedDays($user));
    }

    public function test_just_before_midnight_in_manila_is_still_that_day(): void
    {
        $user = $this->customer();
        $this->order($user, '2026-10-14 23:59:59');

        $this->assertSame(['2026-10-14' => true], StreakCalendar::completedDays($user));
    }

    /**
     * 07:59 Manila is 23:59 UTC the day before — the exact window in which a
     * UTC day boundary would split one Manila day across two calendar cells.
     */
    public function test_an_early_morning_order_and_an_evening_order_on_the_same_manila_day_are_one_day(): void
    {
        $user = $this->customer();
        $this->order($user, '2026-10-14 07:59');
        $this->order($user, '2026-10-14 21:00');

        $this->assertSame(['2026-10-14' => true], StreakCalendar::completedDays($user));
    }

    // ══════════ only completed orders count ══════════

    public function test_pending_preparing_serving_and_cancelled_orders_never_mark_a_day(): void
    {
        $user = $this->customer();
        $this->order($user, '2026-10-12 12:00', 'pending');
        $this->order($user, '2026-10-13 12:00', 'preparing');
        $this->order($user, '2026-10-14 12:00', 'serving');
        $this->order($user, '2026-10-15 09:00', 'cancelled');

        $this->assertSame([], StreakCalendar::completedDays($user));
    }

    public function test_a_cancelled_order_cannot_fill_the_gap_in_a_streak(): void
    {
        $user = $this->customer();
        $this->order($user, '2026-10-13 12:00');
        $this->order($user, '2026-10-14 12:00', 'cancelled');
        $this->order($user, '2026-10-15 09:00');

        $days = StreakCalendar::completedDays($user);

        $this->assertSame(1, StreakCalendar::current($days, $this->today())['length']);
        $this->assertSame(1, StreakCalendar::best($days));
    }

    public function test_another_customers_orders_are_not_counted(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();
        $this->order($theirs, '2026-10-15 09:00');

        $this->assertSame([], StreakCalendar::completedDays($mine));
    }

    // ══════════ month navigation ══════════

    public function test_the_calendar_defaults_to_the_current_shop_month(): void
    {
        $user = $this->customer();

        $this->assertSame('2026-10', StreakCalendar::resolveMonth(null, $user, $this->today())->format('Y-m'));
    }

    public function test_a_valid_past_month_is_shown_and_navigation_moves_one_month_each_way(): void
    {
        $user = $this->customer();
        $this->order($user, '2026-08-20 12:00');

        $cal = StreakCalendar::forAccountPage($user, '2026-08', $this->today());

        $this->assertSame('2026-08', $cal['month']->format('Y-m'));
        $this->assertSame('2026-07', $cal['prev']);
        $this->assertSame('2026-09', $cal['next']);
        $this->assertSame(1, $cal['active_in_month']);

        $active = collect($cal['weeks'])->flatten(1)->where('active', true)->pluck('date')->all();
        $this->assertSame(['2026-08-20'], $active);
    }

    public function test_the_current_month_has_no_next_link_and_a_future_month_is_clamped(): void
    {
        $user = $this->customer();

        $this->assertNull(StreakCalendar::forAccountPage($user, '2026-10', $this->today())['next']);
        $this->assertSame('2026-10', StreakCalendar::resolveMonth('2027-03', $user, $this->today())->format('Y-m'));
    }

    public function test_a_month_before_the_account_existed_is_clamped_and_has_no_previous_link(): void
    {
        $user = $this->customer(); // created 2026-01-01

        $this->assertSame('2026-01', StreakCalendar::resolveMonth('2019-05', $user, $this->today())->format('Y-m'));
        $this->assertNull(StreakCalendar::forAccountPage($user, '2026-01', $this->today())['prev']);
    }

    public function test_a_malformed_month_falls_back_to_the_current_month(): void
    {
        $user = $this->customer();

        foreach (['2026-13', '2026-1', 'abc', '2026-10-01', "2026-10'; --", ''] as $junk) {
            $this->assertSame('2026-10', StreakCalendar::resolveMonth($junk, $user, $this->today())->format('Y-m'), "input: {$junk}");
        }
    }

    public function test_the_month_grid_is_whole_weeks_starting_on_sunday(): void
    {
        $user = $this->customer();
        $cal = StreakCalendar::forAccountPage($user, '2026-10', $this->today());

        foreach ($cal['weeks'] as $week) {
            $this->assertCount(7, $week);
        }

        // 1 Oct 2026 is a Thursday, so the grid opens on Sunday 27 Sept.
        $this->assertSame('2026-09-27', $cal['weeks'][0][0]['date']);
        $this->assertFalse($cal['weeks'][0][0]['in_month']);
        $this->assertSame(31, collect($cal['weeks'])->flatten(1)->where('in_month', true)->count());

        $today = collect($cal['weeks'])->flatten(1)->firstWhere('today', true);
        $this->assertSame('2026-10-15', $today['date']);
    }

    public function test_an_out_of_month_day_is_never_marked_even_with_an_order(): void
    {
        $user = $this->customer();
        $this->order($user, '2026-09-30 12:00');

        $cal = StreakCalendar::forAccountPage($user, '2026-10', $this->today());
        $cell = collect($cal['weeks'])->flatten(1)->firstWhere('date', '2026-09-30');

        $this->assertFalse($cell['active']);
        $this->assertSame(0, $cal['active_in_month']);
    }
}

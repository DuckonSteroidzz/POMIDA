<?php

namespace App\Services;

use App\Models\User;
use App\Support\ShopDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The customer's ordering streak, read from COMPLETED orders only.
 *
 * WHY ONLY COMPLETED
 * ------------------
 * A pending order costs nothing to place and the customer can cancel it
 * themselves (OrderController::cancelCustomerOrder), so counting placed or
 * pending orders would let anyone build a streak — and collect the milestone
 * bonuses App\Services\Loyalty pays on it — with orders that never happened.
 * 'completed' is set only by staff, at the counter, after payment
 * (AdminController::completeOrder), which is what makes a day "real".
 *
 * WHICH DAY AN ORDER BELONGS TO
 * -----------------------------
 * The day it was completed, in the SHOP's time zone (config app.shop_timezone,
 * Asia/Manila). The app stores timestamps in APP_TIMEZONE (UTC on this
 * machine, possibly Asia/Manila live), so they are read in that zone and
 * converted; under UTC, bucketing the raw values would move every order
 * completed before 8:00 AM Manila onto the previous day. completed_at is
 * always set by completeOrder(); created_at is the fallback only for legacy
 * rows that never had it.
 *
 * Stateless and read-only. Day keys are 'Y-m-d' strings throughout.
 */
class StreakCalendar
{
    public static function timezone(): string
    {
        return ShopDay::timezone();
    }

    /** Today, in the shop's zone, at midnight. */
    public static function today(): CarbonImmutable
    {
        return ShopDay::today();
    }

    /**
     * Every shop-zone day on which this customer had at least one completed
     * order, as a set: ['2026-10-05' => true, ...], sorted ascending.
     *
     * $branchId: only orders AT that branch (orders.branch_id) — the stamp
     * card and the Account page calendar are per branch. Null: every branch,
     * which is what the company-wide streak POINTS bonus (Loyalty) reads.
     *
     * @return array<string, true>
     */
    public static function completedDays(User $user, ?int $branchId = null): array
    {
        $appZone  = ShopDay::appTimezone();
        $shopZone = self::timezone();

        $stamps = DB::table('orders')
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COALESCE(completed_at, created_at) AS done_at')
            ->pluck('done_at');

        $days = [];

        foreach ($stamps as $stamp) {
            if ($stamp === null) {
                continue;
            }

            $day = CarbonImmutable::parse($stamp, $appZone)->setTimezone($shopZone)->format('Y-m-d');
            $days[$day] = true;
        }

        ksort($days);

        return $days;
    }

    /**
     * The run of consecutive days that ENDS on $endDay, or null if $endDay
     * itself has no completed order.
     *
     * @param  array<string, true>  $days
     * @return array{length:int, start:string, end:string}|null
     */
    public static function runEndingOn(array $days, CarbonImmutable $endDay): ?array
    {
        $end = $endDay->format('Y-m-d');

        if (! isset($days[$end])) {
            return null;
        }

        $length = 0;
        $cursor = $endDay;

        while (isset($days[$cursor->format('Y-m-d')])) {
            $length++;
            $cursor = $cursor->subDay();
        }

        return [
            'length' => $length,
            'start'  => $cursor->addDay()->format('Y-m-d'),
            'end'    => $end,
        ];
    }

    /**
     * The streak as the customer should see it today.
     *
     * A streak whose last day was YESTERDAY is still alive — today is not over
     * and one completed order keeps it going — so it is shown at full length
     * rather than as 0 every morning until the first order of the day.
     *
     * @param  array<string, true>  $days
     * @return array{length:int, start:?string, end:?string, alive_today:bool}
     */
    public static function current(array $days, ?CarbonImmutable $today = null): array
    {
        $today ??= self::today();

        if ($run = self::runEndingOn($days, $today)) {
            return $run + ['alive_today' => true];
        }

        if ($run = self::runEndingOn($days, $today->subDay())) {
            return $run + ['alive_today' => false];
        }

        return ['length' => 0, 'start' => null, 'end' => null, 'alive_today' => false];
    }

    /**
     * The longest run of consecutive days ever.
     *
     * @param  array<string, true>  $days
     */
    public static function best(array $days): int
    {
        $best = 0;
        $run  = 0;
        $prev = null;

        foreach (array_keys($days) as $day) {
            $date = CarbonImmutable::parse($day, self::timezone());
            $run  = ($prev !== null && $prev->addDay()->format('Y-m-d') === $day) ? $run + 1 : 1;
            $best = max($best, $run);
            $prev = $date;
        }

        return $best;
    }

    /**
     * The month the calendar should show for a requested ?month=YYYY-MM.
     *
     * Anything malformed, before the account existed, or after the current
     * month falls back to (or is clamped to) a sensible month rather than
     * erroring — it is a GET parameter anyone can edit.
     */
    public static function resolveMonth(?string $requested, User $user, ?CarbonImmutable $today = null): CarbonImmutable
    {
        $today ??= self::today();
        $latest = $today->startOfMonth();
        $earliest = self::earliestMonth($user, $today);

        if (! is_string($requested) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested)) {
            return $latest;
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $requested, self::timezone())->startOfMonth();

        if ($month->greaterThan($latest)) {
            return $latest;
        }

        if ($month->lessThan($earliest)) {
            return $earliest;
        }

        return $month;
    }

    /** The first month worth showing: when the account was created. */
    public static function earliestMonth(User $user, ?CarbonImmutable $today = null): CarbonImmutable
    {
        $today ??= self::today();

        $created = $user->created_at
            ? CarbonImmutable::instance($user->created_at)->setTimezone(self::timezone())->startOfMonth()
            : $today->startOfMonth();

        return $created->greaterThan($today) ? $today->startOfMonth() : $created;
    }

    /**
     * Everything the Account page's calendar card renders.
     *
     * $branchId: the calendar shows the branch the customer is ordering from
     * — its red days, current and best run and days this month count only
     * orders completed THERE. 'bonus_current' is always the run across every
     * branch, because the streak points bonus is company-wide.
     *
     * @return array{
     *   month: CarbonImmutable, weeks: array<int, array<int, array{date:string, day:int, in_month:bool, active:bool, today:bool, future:bool}>>,
     *   active_in_month:int, current: array, bonus_current: array, best:int, prev:?string, next:?string, branch_id:?int
     * }
     */
    public static function forAccountPage(User $user, ?string $requestedMonth, ?CarbonImmutable $today = null, ?int $branchId = null): array
    {
        $today ??= self::today();
        $days  = self::completedDays($user, $branchId);
        $month = self::resolveMonth($requestedMonth, $user, $today);

        // Weeks start on Sunday, as a Philippine wall calendar does.
        $cursor = $month->startOfWeek(CarbonImmutable::SUNDAY);
        $last   = $month->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY)->startOfDay();
        $todayKey = $today->format('Y-m-d');

        $weeks = [];
        $week  = [];
        $activeInMonth = 0;

        while ($cursor->lessThanOrEqualTo($last)) {
            $key     = $cursor->format('Y-m-d');
            $inMonth = $cursor->month === $month->month;
            $active  = $inMonth && isset($days[$key]);

            if ($active) {
                $activeInMonth++;
            }

            $week[] = [
                'date'     => $key,
                'day'      => $cursor->day,
                'in_month' => $inMonth,
                'active'   => $active,
                'today'    => $key === $todayKey,
                'future'   => $key > $todayKey,
            ];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }

            $cursor = $cursor->addDay();
        }

        $earliest = self::earliestMonth($user, $today);

        return [
            'month'           => $month,
            'weeks'           => $weeks,
            'active_in_month' => $activeInMonth,
            'current'         => self::current($days, $today),
            'bonus_current'   => $branchId === null ? self::current($days, $today) : self::current(self::completedDays($user), $today),
            'best'            => self::best($days),
            'branch_id'       => $branchId,
            'prev'            => $month->greaterThan($earliest) ? $month->subMonth()->format('Y-m') : null,
            'next'            => $month->lessThan($today->startOfMonth()) ? $month->addMonth()->format('Y-m') : null,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\LoyaltyAward;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Registered-customer bonuses — the only code that pays them.
 *
 * Three bonuses, all into users.bonus_points, all recorded in loyalty_awards,
 * all with the values in config/loyalty.php:
 *
 *   welcome           once per account, when its email address is verified
 *                     (HandlesEmailVerification::verifyEmail)
 *   order_bonus       once per completed order placed from a verified account
 *   streak_milestone  once per milestone per streak, checked whenever an
 *                     order completes (AdminController::completeOrder)
 *
 * and, on the same completion, the free dessert stamp card
 * (App\Services\FreeDesserts, ledger type free_dessert, 0 points — the reward
 * row is the prize).
 *
 * Everything is triggered by the server at the moment the thing actually
 * happened. No route lets a customer ask for a bonus.
 *
 * IDEMPOTENCY
 * -----------
 * award() inserts the ledger row first and credits the points only if that
 * insert succeeded, both inside one transaction. UNIQUE(user_id, type,
 * award_key) is therefore the whole guard: a retry, a refresh, a double click
 * or two staff sessions completing at the same instant all try to insert the
 * same key, the database lets exactly one through, and the loser pays nothing.
 * There is deliberately no "has this been paid?" read before the insert — that
 * read is the race, not the protection.
 *
 * WHO QUALIFIES
 * -------------
 * An active customer account with a verified email. Guests have no account to
 * credit; an unverified account has not proved it is a real person yet (the
 * login gate turns them away anyway — see AuthController::login).
 */
class Loyalty
{
    public static function qualifies(?User $user): bool
    {
        return $user !== null
            && $user->role === 'customer'
            && (bool) $user->is_active
            && $user->hasVerifiedEmail();
    }

    /**
     * Pay one bonus exactly once. Returns true only for the call that paid it.
     */
    public static function award(User $user, string $type, string $key, int $points, ?int $orderId = null): bool
    {
        if ($points <= 0) {
            return false;
        }

        try {
            return DB::transaction(function () use ($user, $type, $key, $points, $orderId) {
                LoyaltyAward::create([
                    'user_id'   => $user->id,
                    'type'      => $type,
                    'award_key' => $key,
                    'points'    => $points,
                    'order_id'  => $orderId,
                ]);

                // An atomic UPDATE ... SET bonus_points = bonus_points + n, so
                // a spin spending bonus points at the same moment cannot have
                // its write overwritten by a stale in-memory balance.
                User::whereKey($user->id)->increment('bonus_points', $points);

                return true;
            });
        } catch (QueryException $e) {
            if (self::isDuplicateKey($e)) {
                return false;
            }

            throw $e;
        }
    }

    /** MySQL 1062 / SQLSTATE 23000 on the ledger's unique key: already paid. */
    private static function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            || str_contains($e->getMessage(), 'loyalty_awards_user_type_key_unique');
    }

    /** The one-time welcome bonus. Called when an email address becomes verified. */
    public static function welcome(User $user): bool
    {
        if (! self::qualifies($user)) {
            return false;
        }

        return self::award(
            $user,
            LoyaltyAward::TYPE_WELCOME,
            'welcome',
            (int) config('loyalty.welcome_bonus_points', 0)
        );
    }

    /**
     * Everything owed for one order reaching 'completed'. Called by the staff
     * completion path AFTER its transaction has committed, so a bonus is never
     * paid for a completion that rolled back.
     *
     * @return array<int, LoyaltyAward::TYPE_*> the types actually paid by THIS call
     */
    public static function orderCompleted(Order $order): array
    {
        if ($order->status !== 'completed' || ! $order->user_id) {
            return [];
        }

        $user = User::find($order->user_id);

        if (! self::qualifies($user)) {
            return [];
        }

        $paid = [];

        if (self::award($user, LoyaltyAward::TYPE_ORDER_BONUS, (string) $order->id, (int) config('loyalty.order_bonus_points', 0), $order->id)) {
            $paid[] = LoyaltyAward::TYPE_ORDER_BONUS;
        }

        // The run that contains the day THIS order was PLACED — the day
        // StreakCalendar credits it to — not the day staff completed it (an
        // order placed 23:50 and completed 00:10 belongs to the earlier day).
        // The whole run, so a late completion that joins days already marked
        // after it still sees the full length.
        $placedDay = CarbonImmutable::instance($order->created_at ?? $order->completed_at ?? now())
            ->setTimezone(StreakCalendar::timezone())->startOfDay();

        // The owner's Streak Calendar switch (Setting::streakCalendarEnabled)
        // stops NEW streak bonuses; the order bonus above is not part of it.
        // When it is switched back on, the run is still read from completed
        // orders, so a streak that kept going while it was off is paid at the
        // next completion — once, as always.
        $run = \App\Models\Setting::streakCalendarEnabled()
            ? StreakCalendar::runContaining(StreakCalendar::completedDays($user), $placedDay)
            : null;

        if ($run !== null) {
            foreach (self::milestones() as $days => $points) {
                if ($run['length'] < $days) {
                    continue;
                }

                // Keyed on the streak's FIRST day: the same streak cannot pay the
                // same milestone twice, and a new streak after a gap has a new key.
                if (self::award($user, LoyaltyAward::TYPE_STREAK, $days . ':' . $run['start'], $points, $order->id)) {
                    $paid[] = LoyaltyAward::TYPE_STREAK;
                }
            }
        }

        // The stamp card reward (Oct 2026): NOT the streak above (which stays
        // company-wide) — PER BRANCH: one stamp per day with a completed order
        // at THIS order's branch, any days, one reward per X stamps of that
        // branch (App\Services\StreakRewardSettings). Its own ledger type and
        // keys. grantDue() itself refuses while either switch is off for that
        // branch or it has no reward. See App\Services\FreeDesserts.
        if ($order->branch_id && ($desserts = FreeDesserts::grantDue($user, (int) $order->branch_id, $order->id))) {
            $paid[] = LoyaltyAward::TYPE_FREE_DESSERT;

            \App\Models\Notification::freeDessertEarned($order, $desserts);
        }

        return $paid;
    }

    /**
     * The configured milestones, cleaned: positive day counts and points only,
     * ascending by days.
     *
     * @return array<int, int> days => points
     */
    public static function milestones(): array
    {
        $out = [];

        foreach ((array) config('loyalty.streak_milestones', []) as $days => $points) {
            if ((int) $days > 0 && (int) $points > 0) {
                $out[(int) $days] = (int) $points;
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * The sign-up teaser line shown to guests (cart, Spin & Win result).
     * Built from config only, so it cannot promise a figure the server does
     * not pay; null when every bonus is switched off. The streak and stamp
     * card perks follow the owner's switch for the same reason, and the stamp
     * card's number is the guest's branch's (the company default while no
     * branch is picked; left out when that branch's card is off).
     */
    public static function guestTeaser(): ?string
    {
        $perks = [];
        $streakOn = \App\Models\Setting::streakCalendarEnabled();
        $branch = FreeDesserts::sessionBranch();
        $dessertDays = match (true) {
            ! $streakOn => 0,
            $branch === null => FreeDesserts::visitDays(),
            FreeDesserts::active($branch->id) => FreeDesserts::visitDays($branch->id),
            default => 0,
        };

        if (($welcome = (int) config('loyalty.welcome_bonus_points', 0)) > 0) {
            $perks[] = $welcome . ' welcome points';
        }

        if (($order = (int) config('loyalty.order_bonus_points', 0)) > 0) {
            $perks[] = '+' . $order . ' points on every completed order';
        }

        if ($streakOn && ($milestones = self::milestones())) {
            $perks[] = 'streak bonuses at ' . implode(' and ', array_map(
                fn ($days) => $days . ' days',
                array_keys($milestones)
            ));
        }

        if ($dessertDays > 0) {
            $perks[] = 'a reward for every ' . $dessertDays . ' stamps (1 stamp per day you completed an order)';
        }

        if (! $perks) {
            return null;
        }

        $last = array_pop($perks);

        return 'Create a free account for ' . ($perks ? implode(', ', $perks) . ' and ' : '') . $last . '.';
    }

    /** Has this customer already received the welcome bonus? */
    public static function hasWelcome(User $user): bool
    {
        return LoyaltyAward::where('user_id', $user->id)
            ->where('type', LoyaltyAward::TYPE_WELCOME)
            ->exists();
    }
}

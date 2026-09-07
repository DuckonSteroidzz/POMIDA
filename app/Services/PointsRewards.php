<?php

namespace App\Services;

use App\Models\GamePlayed;
use App\Models\User;

/**
 * The points-threshold reward rule, in one place.
 *
 * Every THRESHOLD lifetime points a customer earns entitles them to one
 * voucher reward, handed over by staff at the counter. Both sides of the app
 * need the same arithmetic — the customer side to decide when to congratulate
 * someone, the admin side to decide how many codes staff may still issue them
 * — so it lives here rather than being written twice. Same reasoning as
 * PasswordPolicy and CustomerOrderAccess: a rule duplicated across two
 * controllers is one edit away from the two disagreeing, and here the two
 * disagreeing means either a customer is congratulated for a reward staff
 * cannot issue, or staff can issue one the customer never earned.
 *
 * WHICH "POINTS" THIS COUNTS, AND WHY IT IS NOT users.points
 * ----------------------------------------------------------
 * `users.points` is a spendable balance. AuthController::addPoints() credits
 * it on a spin, and then SUBTRACTS points_required from it when the wheel
 * awards a voucher. It legitimately goes down.
 *
 * A reward threshold has to be measured against something that only ever goes
 * up, or the same milestone is crossed repeatedly: spend points on a wheel
 * voucher, earn them back, and floor(points / THRESHOLD) increases a second
 * time for points that were already rewarded once.
 *
 * `games_played` is that monotonic number. It is an append-only ledger with
 * one row per accepted spin, and its own migration describes it as the audit
 * trail for "where did these points come from?". Nothing ever decrements
 * points_awarded, so the SUM below is a true lifetime total.
 *
 * GUESTS
 * ------
 * Guest spins are recorded with user_id NULL (they bank points in the session
 * instead) and this feature does not apply to them: a reward has to be issued
 * to somebody staff can look up and whose claim count can be tracked, which
 * means an account. Guests were already excluded from wheel vouchers for the
 * same reason.
 */
class PointsRewards
{
    /**
     * Lifetime points per voucher reward.
     *
     * A plain named constant rather than an admin setting, deliberately: the
     * value is expected to be stable, and a settings table plus a UI to edit
     * it is a lot of surface for a number that changes once a year. When it
     * does change, it changes here. Follows the same convention as
     * AuthController::SPINS_PER_ORDER and WHEEL_SEGMENTS.
     *
     * NOTE ON CHANGING IT: rewards already claimed are counted, not stored as
     * milestones, so LOWERING this retroactively grants rewards for points
     * already earned (earned goes up, claims stay) and RAISING it can leave a
     * customer with more claims than earned. unclaimedFor() floors at zero for
     * exactly that reason, so the second case degrades to "no new rewards
     * until they catch up" rather than a negative count.
     */
    public const THRESHOLD = 30;

    /**
     * Total points this customer has EVER been awarded by the wheel.
     *
     * Read from the spin ledger, not from users.points — see the class
     * comment. Returns 0 for a customer who has never spun.
     */
    public static function lifetimePointsFor(User $user): int
    {
        return (int) GamePlayed::where('user_id', $user->id)->sum('points_awarded');
    }

    /**
     * How many rewards those lifetime points add up to.
     */
    public static function earnedFor(User $user): int
    {
        return self::rewardsIn(self::lifetimePointsFor($user));
    }

    /**
     * How many rewards a given points total is worth. Kept separate from
     * earnedFor() so the crossing check in addPoints() can ask the same
     * question about a total it already has in hand, without a second query.
     */
    public static function rewardsIn(int $points): int
    {
        if ($points < 0) {
            return 0;
        }

        return intdiv($points, self::THRESHOLD);
    }

    /**
     * Rewards this customer has earned but not yet been handed.
     *
     * Floored at zero so a THRESHOLD increase (or any manual correction to
     * reward_claims) can never produce a negative that would read as "owes us
     * rewards" or, worse, be compared with >= somewhere and behave oddly.
     */
    public static function unclaimedFor(User $user): int
    {
        return max(0, self::earnedFor($user) - (int) $user->reward_claims);
    }

    /**
     * The full picture for one customer, for the admin lookup screen.
     *
     * One method so the four numbers on that screen are guaranteed to be
     * internally consistent — computed from the same lifetime read rather
     * than four separate calls that could each re-query between changes.
     */
    public static function summaryFor(User $user): array
    {
        $lifetime = self::lifetimePointsFor($user);
        $earned   = self::rewardsIn($lifetime);
        $claimed  = (int) $user->reward_claims;

        return [
            'lifetime_points' => $lifetime,
            'threshold'       => self::THRESHOLD,
            'earned'          => $earned,
            'claimed'         => $claimed,
            'unclaimed'       => max(0, $earned - $claimed),
            // How many more points until the next reward — for the counter
            // staff to answer "how close am I?" without doing the division.
            'points_to_next'  => self::THRESHOLD - ($lifetime % self::THRESHOLD),
        ];
    }
}

<?php

namespace App\Support;

use App\Models\FreeDessertReward;
use App\Models\User;
use App\Services\FreeDesserts;
use App\Services\Loyalty;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * FreeDessertPrompt — the one-time "You earned a reward!" pop-up (Oct 2026;
 * named from when the only reward was a free dessert) on the customer Orders
 * and Menu pages.
 *
 * THIS GRANTS NOTHING
 * -------------------
 * Rewards are earned only by App\Services\FreeDesserts::grantDue() when staff
 * complete an order, and spent only at checkout with their code. This class
 * READS the customer's usable rewards and writes one marker,
 * free_dessert_rewards.notified_at. Its button is a plain link to More →
 * Vouchers & Rewards, where the reward, its code and "Use now" are.
 *
 * ONCE PER REWARD, REMEMBERED BY THE SERVER
 * -----------------------------------------
 * announce() stamps notified_at with `WHERE notified_at IS NULL` and answers
 * with the number of rows IT flipped, so a refresh, a second tab or another
 * phone asking at the same moment cannot both win: only the request that
 * actually flipped a row gets a pop-up. The marker is on the reward, so the
 * pop-up for a reward never comes back, even if that reward is later handed
 * back by a cancelled order.
 *
 * WHEN IT IS ASKED
 * ----------------
 * The page does not claim while rendering. pageState() only says whether
 * something is waiting; the page's script calls announce() (POST
 * customer.free-dessert.prompt) when the pop-up's turn actually comes —
 * after the Spin prompt, the order-completed notice or the welcome popup has
 * closed — so a reward is never marked as shown for a page that was left
 * before it could show it. The same endpoint is called when the page's order
 * poll sees an order complete, which is how a page already open learns about
 * a reward staff just triggered.
 *
 * WHO SEES IT
 * -----------
 * A signed-in customer who qualifies for loyalty rewards
 * (Loyalty::qualifies), while the stamp card is switched on, for rewards that
 * are still available and not expired (FreeDessertReward::usable). A reward
 * already spent, expired or belonging to someone else is never announced.
 * Guests never see it.
 *
 * FAILS CLOSED
 * ------------
 * It is a convenience. Any failure (for example the column not yet migrated)
 * means "no pop-up" and is logged; it must never take a page down.
 */
class FreeDessertPrompt
{
    /** The signed-in customer who could ever be shown the pop-up, or null. */
    public static function customer(): ?User
    {
        $user = Auth::guard('customer')->user();

        // The owner's master switch only: no pop-up while it is off. Rewards
        // are announced whichever branch earned them (a branch switched off
        // hides only its own calendar and stamp card), and the reward itself
        // stays usable in the cart either way.
        if (! $user || ! FreeDesserts::masterOn() || ! Loyalty::qualifies($user)) {
            return null;
        }

        return $user;
    }

    /**
     * What the page renders: null = no pop-up markup at all (guest, not
     * eligible, or an error); false = ready for a live announcement but none
     * waiting now; true = a reward is waiting to be announced on this load.
     */
    public static function pageState(): ?bool
    {
        try {
            $user = self::customer();

            if (! $user) {
                return null;
            }

            return self::unannounced($user)->exists();
        } catch (\Throwable $e) {
            Log::warning('Free dessert prompt skipped', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Mark every usable, not-yet-announced reward of the signed-in customer
     * as announced, and return what the pop-up says — or null when this
     * request flipped nothing (none waiting, or another tab got there first).
     *
     * @return array{count:int, title:string, message:string}|null
     */
    public static function announce(): ?array
    {
        try {
            $user = self::customer();

            if (! $user) {
                return null;
            }

            // The atomic claim: only the request that flips a row shows it.
            // toBase() so the marker does not touch updated_at.
            $claimed = self::unannounced($user)
                ->toBase()
                ->update(['notified_at' => now()]);

            return $claimed > 0 ? self::payload($user, $claimed) : null;
        } catch (\Throwable $e) {
            Log::warning('Free dessert prompt skipped', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array{count:int, title:string, message:string} */
    public static function payload(User $user, int $count): array
    {
        $title = $count === 1
            ? 'You earned a reward!'
            : 'You earned ' . $count . ' rewards!';

        // The reward that expires first — the one to use first.
        $first = FreeDesserts::usableFor($user);

        $message = $count === 1
            ? ($first ? $first->description() . '. ' : '') . 'Your reward code is waiting in Vouchers & Rewards.'
            : 'Your reward codes are waiting in Vouchers & Rewards.';

        if ($first?->expires_at) {
            $message .= ' Use ' . ($count === 1 ? 'it' : 'the first one')
                . ' by ' . ShopDay::format($first->expires_at, 'M j, Y') . '.';
        }

        return ['count' => $count, 'title' => $title, 'message' => $message];
    }

    private static function unannounced(User $user)
    {
        return FreeDessertReward::usable()
            ->where('user_id', $user->id)
            ->whereNull('notified_at');
    }
}

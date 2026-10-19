<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\FreeDesserts;
use App\Services\Loyalty;
use App\Services\TableOccupancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Want a stamp for this order?" — a Dine-In guest joins the loyalty program
 * right after ordering, and the order they just placed comes with them
 * (Oct 2026).
 *
 * THE FLOW
 * --------
 * 1. The guest taps Sign up or Log in on the Orders page card (or in the
 *    rewards pop-up on the Dine-In menu). That goes through start(), which writes the
 *    intent into THIS session and pins the order the card was about.
 * 2. Sign-up does not sign anyone in (email confirmation comes first, see
 *    App\Support\VerificationFlow), so the claim always happens at LOGIN.
 *    AuthController::login() calls claimInto() once the login has fully
 *    succeeded.
 * 3. claimInto() sets orders.user_id on the pinned order. For an order still
 *    open, nothing else is granted here: the stamp, the order bonus and any
 *    reward come from the existing completion path
 *    (AdminController::completeOrder() -> Loyalty::orderCompleted()) when
 *    staff press Complete, exactly as for an order placed while signed in.
 *
 * WHAT PROVES THE GUEST OWNS THE ORDER
 * ------------------------------------
 * Only the session. The order id is never read from a URL or a form: start()
 * picks it from App\Support\GuestOrders (the orders THIS session placed), and
 * claimInto() re-checks that list under a row lock. On top of that the order
 * must still be ownerless (user_id IS NULL), so an order that belongs to an
 * account can never be moved, and a claimed order cannot be claimed twice.
 *
 * LATE CLAIM, SAME SHOP DAY ONLY
 * ------------------------------
 * A new customer has to confirm their email before they can log in, so most
 * of them log in after staff have already pressed Complete. An order that
 * was completed during the CURRENT Manila shop day (App\Support\ShopDay) is
 * therefore still attached, and claimInto() then runs the same loyalty step
 * Complete runs (Loyalty::orderCompleted(): order bonus, streak, stamp
 * reward and its pop-up), because Complete ran it with no account. Every one
 * of those is a loyalty_awards row with a unique key, so a race with Complete
 * or a repeated call can never credit anything twice.
 *
 * An order completed on an EARLIER shop day is left as a guest order (no
 * retroactive stamp): the customer is told their later orders count.
 * Cancelled and rejected orders are never attached.
 */
final class DineInJoin
{
    /** The intent: this session came through a Sign up / Log in button here. */
    public const SESSION_ACTIVE = 'dinein_join.active';

    /** The one order the intent is about (picked server-side, never posted). */
    public const SESSION_ORDER = 'dinein_join.order_id';

    /** The table session the menu pop-up was already handed out for. */
    public const SESSION_POPUP_SEEN = 'dinein_join.popup_seen';

    /** Stamp circles drawn by the pop-up: the generic row, and the most a phone fits. */
    public const POPUP_DEFAULT_STAMPS = 5;

    public const POPUP_MAX_STAMPS = 6;

    public const MODE_SIGNUP = 'signup';

    public const MODE_LOGIN = 'login';

    /** What claimInto() did. */
    public const CLAIMED = 'claimed';

    /** Attached after Complete, the same shop day; its loyalty step has run. */
    public const CLAIMED_COMPLETED = 'claimed_completed';

    public const TOO_LATE = 'too_late';

    public const NOTHING = 'nothing';

    /** Statuses an order may still be claimed in: the same "open" set the Orders page uses. */
    public const OPEN_STATUSES = ['pending', 'preparing', 'serving'];

    /** Statuses after which an order is no longer in progress. */
    public const FINISHED_STATUSES = ['completed', 'cancelled', 'rejected'];

    /** Copy shared by the card, the login page and the flash after login. */
    public const MSG_CLAIMED = 'This order is now on your account. It will count toward your stamp card when it is completed.';

    public const MSG_CLAIMED_COMPLETED = 'This order is now on your account and counts toward your stamp card.';

    /** Shown in place of Log in / Create account while a guest order is in progress. */
    public const MSG_ONGOING_ORDER = 'You have an ongoing order. Please complete or cancel it before creating a new account or logging in.';

    /** Flash key the route guard sets and the in-page prompt reads. */
    public const NOTICE_KEY = 'ongoing_order_notice';

    public const MSG_TOO_LATE ='This order was completed on an earlier day, so it cannot earn a stamp. Your next orders will.';

    /**
     * A Dine-In guest is in this browser: no account signed in, and the
     * session is seated at a table. Both the card and the menu line need this.
     */
    public static function isDineInGuest(): bool
    {
        return ! Auth::guard('customer')->check()
            && session('order_type') === 'dine_in'
            && filled(session('table_number'));
    }

    /**
     * The order that must finish before this browser may log in or sign up:
     * a Dine-In GUEST's own order that is still in progress (anything not
     * completed, cancelled or rejected), found through the same session-owned
     * list the claim uses (App\Support\GuestOrders). Logging in replaces the
     * session, so the guest would lose sight of an order staff are still
     * working on. Never for a signed-in customer or a pick-up session.
     *
     * Once per request: the Orders card, the buttons and the route guard all
     * ask, and the answer cannot change inside one request.
     */
    public static function ongoingOrder(): ?Order
    {
        if (! self::isDineInGuest()) {
            return null;
        }

        $ids = GuestOrders::ids();

        if (! $ids) {
            return null;
        }

        $attributes = request()->attributes;
        $key = 'dinein_join.ongoing:' . implode(',', $ids);

        if (! $attributes->has($key)) {
            $attributes->set($key, Order::whereIn('id', $ids)
                ->whereNull('user_id')
                ->where('type', 'dine_in')
                ->whereNotIn('status', self::FINISHED_STATUSES)
                ->orderByDesc('id')
                ->first());
        }

        return $attributes->get($key);
    }

    /** Is guest Log in / Create account closed to this browser right now? */
    public static function blocksGuestAuth(): bool
    {
        return self::ongoingOrder() !== null;
    }

    /**
     * Markup for a Log in / Sign up link: the page script opens the "ongoing
     * order" prompt instead of following it. Empty when nothing is blocked,
     * so the link behaves exactly as before.
     */
    public static function blockAttr(): string
    {
        return self::blocksGuestAuth() ? ' data-ongoing-order-block' : '';
    }

    /**
     * Stamps can be earned at the branch this session is seated at. The card
     * promises a stamp, so it is not shown where none can be earned.
     */
    public static function stampsOnAt(?int $branchId): bool
    {
        return $branchId !== null && $branchId > 0 && FreeDesserts::active($branchId);
    }

    /**
     * May the Dine-In menu show the rewards pop-up to this browser at all?
     * A seated guest at a branch where stamps can be earned; never a
     * signed-in customer. Says nothing about "already shown" (see
     * claimMenuPopup()).
     */
    public static function menuPopupEligible(): bool
    {
        $branch = session('branch_id');

        return self::isDineInGuest() && self::stampsOnAt(is_numeric($branch) ? (int) $branch : null);
    }

    /**
     * The Dine-In menu's rewards pop-up: yes at most ONCE per table session.
     *
     * Returns null (show nothing) for anyone not eligible or who already SAW
     * it for this table session, otherwise the data the pop-up needs. It does
     * NOT record anything: building the page is not seeing the pop-up. The
     * pop-up waits for the welcome popup (about six seconds) and every other
     * overlay, so a reload or a tap-away in that window used to spend the
     * one showing without it ever reaching the screen. The browser reports
     * the moment it really opens (markMenuPopupSeen()), and only then is it
     * used up. A page that never runs its script, or a report that fails,
     * leaves the pop-up due, which errs towards showing it once more.
     *
     * @return array{stamps:int}|null
     */
    public static function claimMenuPopup(): ?array
    {
        if (! self::menuPopupEligible() || session(self::SESSION_POPUP_SEEN) === self::menuPopupVisit()) {
            return null;
        }

        return ['stamps' => self::popupStampCount((int) session('branch_id'))];
    }

    /**
     * The pop-up reached the screen: record it in THIS session so a refresh,
     * a second tab or another page load cannot bring it back; only a new
     * table session can. False (and nothing written) for anyone the pop-up
     * is never shown to, so a customer or a pick-up session is not marked.
     */
    public static function markMenuPopupSeen(): bool
    {
        if (! self::menuPopupEligible()) {
            return false;
        }

        session()->put(self::SESSION_POPUP_SEEN, self::menuPopupVisit());

        return true;
    }

    /**
     * The table session the pop-up is "once" for: the table session's own
     * token (TableOccupancy::SESSION_KEY), which a new visit replaces, with
     * the branch and table as the fallback when there is none.
     */
    private static function menuPopupVisit(): string
    {
        return (string) (session(TableOccupancy::SESSION_KEY)
            ?: ('table:' . (int) session('branch_id') . ':' . session('table_number')));
    }

    /**
     * How many circles the pop-up's stamp row draws: the branch's configured
     * days when that fits on a phone, otherwise the generic five. It is an
     * illustration only; the real stamp card is on the account.
     */
    public static function popupStampCount(int $branchId): int
    {
        $days = FreeDesserts::visitDays($branchId);

        return $days >= 2 && $days <= self::POPUP_MAX_STAMPS ? $days : self::POPUP_DEFAULT_STAMPS;
    }

    /**
     * The order the Orders page card is about, or null for no card: this
     * session's newest ownerless Dine-In order that was COMPLETED today. While
     * any guest order is still in progress there is no card (logging in would
     * lose it, see ongoingOrder()); it comes back when that order is
     * completed, and a cancelled or rejected order never offers one. The order
     * comes from the session-owned list, not from what the page happens to
     * list, because a completed order is no longer an "active" one.
     */
    public static function cardOrder(): ?Order
    {
        if (! self::isDineInGuest() || self::blocksGuestAuth()) {
            return null;
        }

        $order = self::claimableOrders()->first();

        return $order && self::stampsOnAt((int) $order->branch_id) ? $order : null;
    }

    /**
     * Enter the flow. Pins this session's newest claimable order (still open,
     * or completed earlier this shop day), if any, so claimInto() attaches
     * exactly that one, and nothing the request says.
     */
    public static function start(): void
    {
        $candidate = self::claimableOrders()->first();

        session()->put(self::SESSION_ACTIVE, true);

        if ($candidate) {
            session()->put(self::SESSION_ORDER, $candidate->id);
        } else {
            session()->forget(self::SESSION_ORDER);
        }
    }

    public static function active(): bool
    {
        return session(self::SESSION_ACTIVE) === true;
    }

    public static function pinnedOrderId(): ?int
    {
        $id = session(self::SESSION_ORDER);

        return is_int($id) && $id > 0 ? $id : null;
    }

    public static function forget(): void
    {
        session()->forget([self::SESSION_ACTIVE, self::SESSION_ORDER]);
    }

    /**
     * Attach the pinned order to $user. Called by login() after the login has
     * succeeded (password, active, customer, confirmed email). The intent is
     * spent whatever the outcome, so one trip through the flow is one claim.
     *
     * @return string one of CLAIMED, CLAIMED_COMPLETED, TOO_LATE, NOTHING
     */
    public static function claimInto(User $user): string
    {
        $orderId = self::pinnedOrderId();

        self::forget();

        // Re-checked against the LIVE session list, not just the pin: a new
        // Dine-In visit in this browser (GuestOrders::forget()) revokes it.
        if ($orderId === null || ! GuestOrders::owns($orderId)) {
            return self::NOTHING;
        }

        [$result, $order] = DB::transaction(function () use ($orderId, $user) {
            // Locked so staff pressing Complete and this claim cannot both
            // act on what they each read a moment ago. Whichever commits
            // second sees the first one's result.
            $order = Order::whereKey($orderId)->lockForUpdate()->first();

            if (! $order || $order->user_id !== null || $order->type !== 'dine_in') {
                return [self::NOTHING, null];
            }

            if (in_array($order->status, self::OPEN_STATUSES, true)) {
                $result = self::CLAIMED;
            } elseif (self::completedToday($order)) {
                $result = self::CLAIMED_COMPLETED;
            } else {
                return [$order->status === 'completed' ? self::TOO_LATE : self::NOTHING, null];
            }

            $order->forceFill(['user_id' => $user->id])->save();

            // The order's own status messages so far were stored for a guest
            // (user_id NULL, matched by this session). Move them with it, or
            // the account's bell would lose them.
            Notification::where('order_id', $order->id)
                ->where('audience', Notification::AUDIENCE_CUSTOMER)
                ->whereNull('user_id')
                ->update(['user_id' => $user->id]);

            return [$result, $order];
        });

        // Complete already ran for this order, with no account to credit.
        // Run its loyalty step now, after the commit, exactly as
        // completeOrder() does: idempotent (unique loyalty_awards keys), and
        // never allowed to undo a claim that has already happened.
        if ($result === self::CLAIMED_COMPLETED) {
            try {
                Loyalty::orderCompleted($order);
            } catch (\Throwable $e) {
                Log::error('Loyalty bonus could not be awarded for a late Dine-In claim', [
                    'order_id'  => $order->id,
                    'exception' => $e,
                ]);
            }
        }

        return $result;
    }

    /**
     * Completed during the current shop day (Manila midnight to midnight).
     * Cancelled and rejected orders never qualify.
     */
    private static function completedToday(Order $order): bool
    {
        return $order->status === 'completed' && ShopDay::isToday($order->completed_at);
    }

    /** @return Collection<int, Order> this session's claimable orders, newest first */
    private static function claimableOrders(): Collection
    {
        $ids = GuestOrders::ids();

        if (! $ids) {
            return collect();
        }

        return Order::whereIn('id', $ids)
            ->whereNull('user_id')
            ->where('type', 'dine_in')
            ->whereIn('status', [...self::OPEN_STATUSES, 'completed'])
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Order $o) => $o->status !== 'completed' || self::completedToday($o))
            ->values();
    }
}

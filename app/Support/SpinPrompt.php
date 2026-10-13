<?php

namespace App\Support;

use App\Http\Controllers\Customer\AuthController;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SpinPrompt — decides whether the Orders page shows the one-time
 * "Play Spin & Win while you wait" modal (Oct 2026, capstone adviser request).
 *
 * THIS GRANTS NOTHING
 * -------------------
 * The modal is a link to the existing /customer/game page. Spins are still
 * counted, limited and awarded entirely by AuthController::spinWindowState()
 * and addPoints(); this class only READS that state and writes one marker,
 * orders.spin_prompted_at. No spin, voucher or points rule lives here.
 *
 * ONCE PER ORDER, REMEMBERED BY THE SERVER
 * ----------------------------------------
 * The marker is stamped in the same step that decides to show the modal, with
 * `WHERE spin_prompted_at IS NULL` and a check of the affected-row count, so
 * two tabs (or a refresh racing a poll) cannot both win: only the request that
 * actually flipped the column renders the modal. Closing, playing and
 * refreshing therefore all behave the same — it does not come back. The marker
 * is on the order, not the session, so signing in on another phone does not
 * show it again either.
 *
 * WHICH ORDERS COUNT
 * ------------------
 * An order is only worth prompting for once the shop has something real to
 * serve. A GCash order that staff have not yet verified (payment_status other
 * than 'paid') and an order whose PWD/Senior discount is still awaiting
 * verification are skipped, because the spin rules today count ANY non-
 * cancelled order of the day — so promoting the wheel at that moment would be
 * an invitation to place an unpaid order, spin, and cancel. Cash orders are
 * prompted straight away. A skipped order is not stamped, so it is prompted
 * later, once it qualifies. Whether the wheel itself should wait for payment is
 * a separate decision about the spin rules, and is not made here.
 *
 * ONE MODAL PER PAGE LOAD
 * -----------------------
 * Several open orders are stamped together and answered with one modal, so a
 * second-round order never stacks a second popup on top of the first.
 *
 * FAILS CLOSED
 * ------------
 * It is a convenience. Any failure (for example the column not yet migrated on
 * a database) means "no modal" and is logged; it must never take the order
 * status page down.
 */
class SpinPrompt
{
    /**
     * @param  Collection<int,Order>  $openOrders  the orders the page lists as in flight
     * @return array{order_number:string, spins_remaining:int}|null  null = show nothing
     */
    public static function forOrders(Collection $openOrders): ?array
    {
        try {
            if ($openOrders->isEmpty() || ! Setting::gameEnabled()) {
                return null;
            }

            $candidates = $openOrders
                ->filter(fn (Order $o) => self::qualifies($o))
                ->sortByDesc('id')
                ->values();

            if ($candidates->isEmpty()) {
                return null;
            }

            // Same server-side rules the Game page and the spin endpoint use.
            $state = app(AuthController::class)->spinWindowState();

            if (empty($state['can_spin']) || (int) ($state['spins_remaining'] ?? 0) < 1) {
                return null;
            }

            // The atomic "claim": only the request that flips the column shows it.
            $claimed = DB::table('orders')
                ->whereIn('id', $candidates->pluck('id')->all())
                ->whereNull('spin_prompted_at')
                ->update(['spin_prompted_at' => now()]);

            if ($claimed < 1) {
                return null;
            }

            return [
                'order_number'    => (string) $candidates->first()->order_number,
                'spins_remaining' => (int) $state['spins_remaining'],
            ];
        } catch (\Throwable $e) {
            Log::warning('Spin prompt skipped', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    private static function qualifies(Order $order): bool
    {
        // The spin counter only looks at orders placed today (the shop's day).
        if (! ShopDay::isToday($order->created_at)) {
            return false;
        }

        if (! in_array($order->status, ['pending', 'preparing', 'serving'], true)) {
            return false;
        }

        // Already shown (the model attribute is absent on an un-migrated DB;
        // the claim query then throws and the caller fails closed).
        if ($order->getAttribute('spin_prompted_at') !== null) {
            return false;
        }

        // GCash: only once staff have verified the payment.
        if ($order->payment_method === 'gcash' && $order->payment_status !== 'paid') {
            return false;
        }

        // PWD/Senior discount still awaiting staff verification.
        if ($order->discount_status === 'pending') {
            return false;
        }

        return true;
    }
}

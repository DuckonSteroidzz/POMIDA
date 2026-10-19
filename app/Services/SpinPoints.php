<?php

namespace App\Services;

use App\Models\GamePlayed;
use App\Models\LoyaltyAward;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Taking back the Spin & Win points a cancelled order won (Oct 2026).
 *
 * users.points is the account's spin balance. It goes up on every spin
 * (AuthController::addPoints) and it goes down for exactly two reasons:
 *
 *   1. a wheel voucher is won, which costs its points_required (addPoints);
 *   2. the order the spins were played on is cancelled — this class.
 *
 * Nothing else lowers it. Placing an order used to reset it to 0, which is
 * how points earned on one order disappeared when the next one was placed;
 * that reset is gone, and the balance now stacks across orders.
 *
 * WHAT IS REVERSED
 * ----------------
 * Every spin is recorded in games_played against the order it was played on
 * (the visitor's newest open order at the time), with the points it paid.
 * When that order is cancelled, the SUM of points_awarded of its spins by the
 * order's own account is taken back. A spin can only be recorded against an
 * order that is still open, so these are the points won while it was waiting.
 * Guest spins (user_id NULL) are not touched: their points live in the
 * guest's session, which the staff side cannot reach.
 *
 * ALREADY SPENT
 * -------------
 * If a wheel voucher was won in the meantime, some of those points may be
 * gone. The voucher is never revoked. What is left of the spin balance is
 * taken, the balance stops at 0, and the part that could not be taken is
 * recorded as a shortfall row in the ledger. bonus_points (welcome, order
 * and streak bonuses) is never touched: those were earned separately.
 *
 * IDEMPOTENCY
 * -----------
 * Same guard as App\Services\Loyalty::award(): the ledger row
 * (spin_reversal, key = order id) is inserted first, inside the transaction
 * that lowers the balance, and UNIQUE(user_id, type, award_key) lets exactly
 * one insert through. A second cancel, a re-save or two staff sessions at
 * once all collide on that key and take nothing more. The account row is
 * locked first so a spin or a voucher win cannot interleave with the read of
 * the balance.
 */
class SpinPoints
{
    /**
     * Take back the spin points a cancelled order won. Returns the points
     * actually removed by THIS call (0 when already reversed or nothing won).
     */
    public static function reverseForCancelledOrder(Order $order): int
    {
        if ($order->status !== 'cancelled' || ! $order->user_id) {
            return 0;
        }

        $won = (int) GamePlayed::where('order_id', $order->id)
            ->where('user_id', $order->user_id)
            ->sum('points_awarded');

        if ($won <= 0) {
            return 0;
        }

        try {
            return DB::transaction(function () use ($order, $won) {
                $balance = User::whereKey($order->user_id)->lockForUpdate()->value('points');

                if ($balance === null) {
                    return 0;
                }

                $taken     = min($won, max(0, (int) $balance));
                $shortfall = $won - $taken;

                // The idempotency key. Written even when nothing is left to
                // take (points 0), so a repeat can never take more later.
                LoyaltyAward::create([
                    'user_id'   => $order->user_id,
                    'type'      => LoyaltyAward::TYPE_SPIN_REVERSAL,
                    'award_key' => (string) $order->id,
                    'points'    => $taken,
                    'order_id'  => $order->id,
                ]);

                if ($shortfall > 0) {
                    LoyaltyAward::create([
                        'user_id'   => $order->user_id,
                        'type'      => LoyaltyAward::TYPE_SPIN_REVERSAL_SHORTFALL,
                        'award_key' => (string) $order->id,
                        'points'    => $shortfall,
                        'order_id'  => $order->id,
                    ]);
                }

                if ($taken > 0) {
                    User::whereKey($order->user_id)->decrement('points', $taken);
                }

                return $taken;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062
                || str_contains($e->getMessage(), 'loyalty_awards_user_type_key_unique')) {
                return 0;
            }

            throw $e;
        }
    }

    /**
     * For Order's saved hook: a failure here must never undo the cancel that
     * has already been saved. The ledger key makes a later retry safe.
     */
    public static function reverseAfterCancel(Order $order): void
    {
        try {
            self::reverseForCancelledOrder($order);
        } catch (\Throwable $e) {
            Log::error('Spin points could not be reversed after cancellation', [
                'order_id'  => $order->id,
                'exception' => $e,
            ]);
        }
    }
}

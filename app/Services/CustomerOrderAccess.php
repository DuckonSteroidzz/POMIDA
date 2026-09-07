<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderRating;
use App\Support\GuestOrders;
use App\Services\TableOccupancy;
use Illuminate\Support\Facades\Auth;

/**
 * CustomerOrderAccess — the one place that answers "is this visitor allowed to
 * act on this order?", for logged-in customers and guests alike.
 *
 * The ownership rule used to live as a private method on OrderController, which
 * meant the rating endpoint could not reuse it and hand-rolled its own weaker
 * check (auth required, so guests were locked out entirely). Both now go
 * through this class, so a guest's claim to their order is defined once.
 */
class CustomerOrderAccess
{
    /** Order types that can be rated by a customer. */
    public const RATABLE_TYPES = ['dine_in', 'pick_up'];

    /**
     * Resolve an order the CURRENT visitor is actually allowed to see or act on.
     *
     * Rules:
     *  - Logged-in customer -> the order must belong to their user_id.
     *  - Guest (not logged in) -> the order must be one THIS session placed
     *    (see App\Support\GuestOrders) AND must be a genuine guest order
     *    (user_id IS NULL). The second condition means a guest can never read
     *    an order that belongs to a real account, even by guessing an ID.
     *
     * Returns null when the visitor has no claim to the order; callers decide
     * whether that becomes a 404, a redirect, or a JSON error.
     */
    public static function resolveOwnedOrder(int $id, array $with = [], bool $includePastVisits = false): ?Order
    {
        $build = function () use ($id, $with) {
            $query = Order::query()->where('id', $id);

            if (!empty($with)) {
                $query->with($with);
            }

            return $query;
        };

        if (Auth::guard('customer')->check()) {
            $own = $build()
                ->where('user_id', Auth::guard('customer')->id())
                ->first();

            if ($own) {
                return $own;
            }

            /*
             * Not one of their account's orders — but it may still be an order
             * THIS BROWSER placed as a guest before signing in. That is a very
             * ordinary sequence: order at the table as a guest, then log in to
             * claim points, then tap View Receipt. Before this, logging in
             * revoked the claim on your own order and the receipt 404'd
             * (reproduced live). The guest rules below are applied unchanged,
             * so this widens nothing: the order must still be one this session
             * placed AND a genuine guest order.
             */
        }

        if (!GuestOrders::owns($id) && !($includePastVisits && GuestOrders::ownedInPastVisit($id))) {
            return null;
        }

        return $build()->whereNull('user_id')->first();
    }

    /**
     * May the visitor start ANOTHER order while this one is still open?
     *
     * Yes for a dine-in customer who is still sitting at the table that order
     * belongs to. A café visit is not one order — people order a second round,
     * a dessert, a coffee after the meal — and making them wait for the
     * kitchen to finish before they can even add to the cart is not how a
     * counter works. The reported bug was exactly this: Add to Cart stayed
     * dead for the whole life of the order and only came back once staff
     * marked it completed.
     *
     * Yes for PICK-UP too. The second report was the same complaint from the
     * counter queue: order drinks, remember the food a minute later, and the
     * cart is dead until the kitchen finishes the first order. Scoping the
     * item-43 fix to dine-in only was caution, not a requirement — it was
     * written because a pick-up order has no table and therefore no "still
     * seated at the same visit" signal to anchor on. Nothing downstream
     * actually needed that signal:
     *
     *  - Ownership never depended on it. A logged-in customer's orders are
     *    found by user_id; a guest's by App\Support\GuestOrders, which has
     *    been a SET of order ids since item 43 — so a second pick-up order
     *    cannot orphan the first one's receipt, notifications or rating.
     *  - Table occupancy (item 40) is dine-in only and is never consulted for
     *    a pick-up order, so nothing here can touch its guarantees.
     *  - Abuse is handled by the per-session/per-IP place-order limiters
     *    reworked in Round 1, not by this gate.
     *
     * The one remaining No is a dine-in order whose table this visitor no
     * longer holds — the occupancy was released, cleared by staff, or belongs
     * to someone else. Without this the check would be "has an old dine-in
     * order", which any stale session would satisfy.
     *
     * TableOccupancy is the authority on "still sitting there", so this asks
     * it rather than re-deriving the answer, and inherits its session-token
     * then order-ownership signals unchanged.
     */
    public static function mayOrderAlongside(?Order $active): bool
    {
        if (!$active) {
            return true;
        }

        // No table, so nothing to still be seated at — and nothing else in the
        // app assumes a pick-up customer has at most one order open.
        if ($active->type === 'pick_up') {
            return true;
        }

        if ($active->type !== 'dine_in') {
            return false;
        }

        if (!$active->branch_id || $active->table_number === null || $active->table_number === '') {
            return false;
        }

        $session = TableOccupancy::activeFor(
            (int) $active->branch_id,
            (string) $active->table_number
        );

        return $session !== null && TableOccupancy::heldByCurrentVisitor($session);
    }

    /**
     * Decide whether the current visitor may rate this order, and why not.
     *
     * Shared by the Order History rating modal and the in-place control in the
     * order-completed popup, so there is exactly one definition of "can this
     * order be rated, by whom".
     *
     * @return array{ok: bool, order?: Order, rating?: OrderRating, error?: string, status?: int}
     */
    public static function resolveRatableOrder(int $id): array
    {
        $order = self::resolveOwnedOrder($id);

        // A visitor with no claim to the order is told the order does not
        // exist, rather than that it exists but is not theirs.
        if (!$order) {
            return [
                'ok'     => false,
                'error'  => 'That order could not be found.',
                'status' => 404,
            ];
        }

        if ($order->status !== 'completed') {
            return [
                'ok'     => false,
                'order'  => $order,
                'error'  => 'You can rate your meal once the order is completed.',
                'status' => 422,
            ];
        }

        if (!in_array($order->type, self::RATABLE_TYPES, true)) {
            return [
                'ok'     => false,
                'order'  => $order,
                'error'  => 'This kind of order cannot be rated.',
                'status' => 422,
            ];
        }

        $existing = OrderRating::where('order_id', $order->id)->first();

        if ($existing) {
            return [
                'ok'     => false,
                'order'  => $order,
                'rating' => $existing,
                'error'  => 'You have already rated this order.',
                'status' => 409,
            ];
        }

        return ['ok' => true, 'order' => $order];
    }

    /**
     * The rating already recorded for an order the visitor owns, if any.
     * Returns null both when there is no rating and when the visitor has no
     * claim to the order — callers must not distinguish the two.
     */
    public static function existingRatingFor(int $id): ?OrderRating
    {
        $order = self::resolveOwnedOrder($id);

        if (!$order) {
            return null;
        }

        return OrderRating::where('order_id', $order->id)->first();
    }
}

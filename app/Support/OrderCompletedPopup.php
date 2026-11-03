<?php

namespace App\Support;

use App\Models\Order;
use App\Services\CustomerOrderAccess;
use Illuminate\Support\Facades\DB;

/**
 * Which completed orders still owe this visitor the "Order Completed / How was
 * your meal?" pop-up (customer/partials/order-completed-popup).
 *
 * WHY THE SERVER DECIDES (QA, Nov 2 2026)
 * ---------------------------------------
 * The pop-up used to be two different copies, one on the Menu page (OK only)
 * and one on the Orders page (with stars), that shared ONE localStorage "seen"
 * flag written the moment either opened. Seeing the Menu copy therefore used up
 * the only copy with stars, and a guest, who has no Order History, could then
 * never rate at all. Each copy also only watched one order on its own page, so
 * an order that finished elsewhere, or that was not the newest one, was missed.
 *
 * Now the answer is one query: a completed, ratable order this visitor owns,
 * with no rating yet and no "Not now". Nothing is marked when the pop-up opens,
 * only when the customer answers, so a reload or a page change cannot lose it.
 *
 * Ownership matches CustomerOrderAccess::resolveOwnedOrder(), the rule the
 * rating endpoint itself applies, so the pop-up never offers a rating the
 * endpoint would refuse.
 */
final class OrderCompletedPopup
{
    /** More than a visitor could sensibly answer in one sitting; shown one at a time anyway. */
    private const MAX_QUEUE = 10;

    /**
     * Oldest first, so several finished orders are asked about in the order
     * they were placed.
     *
     * @return array<int, array{order_id:int, order_number:string, receipt_url:string}>
     */
    public static function queue(?int $customerId): array
    {
        $guestIds = GuestOrders::ids();

        // A visitor with no claim on any order costs no query.
        if ($customerId === null && $guestIds === []) {
            return [];
        }

        return Order::query()
            ->select(['id', 'order_number'])
            ->where('status', 'completed')
            ->whereIn('type', CustomerOrderAccess::RATABLE_TYPES)
            ->whereNull('rating_prompt_dismissed_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('order_ratings')->whereColumn('order_ratings.order_id', 'orders.id');
            })
            ->where(function ($q) use ($customerId, $guestIds) {
                if ($customerId !== null) {
                    $q->where('user_id', $customerId);
                }

                if ($guestIds !== []) {
                    $q->orWhere(fn ($guest) => $guest->whereNull('user_id')->whereIn('id', $guestIds));
                }
            })
            ->orderBy('id')
            ->limit(self::MAX_QUEUE)
            ->get()
            ->map(fn (Order $order) => [
                'order_id' => (int) $order->id,
                'order_number' => (string) $order->order_number,
                'receipt_url' => route('customer.receipt', $order->id),
            ])
            ->all();
    }

    /**
     * "Not now": never ask about this order again. It stays ratable from Order
     * History. Through the base query so updated_at, which the admin board and
     * the poll's change detection read, does not move.
     */
    public static function dismiss(Order $order): void
    {
        Order::whereKey($order->id)
            ->whereNull('rating_prompt_dismissed_at')
            ->toBase()
            ->update(['rating_prompt_dismissed_at' => now()]);
    }
}

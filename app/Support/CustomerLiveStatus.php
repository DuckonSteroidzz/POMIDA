<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Everything a customer page's ONE background poll needs, in one small answer:
 * the bell's unread count + newest notification id, and the status of the
 * orders the page is watching.
 *
 * WHY THIS EXISTS
 * ---------------
 * A customer page used to ask three separate endpoints on three separate
 * timers — /notifications/unread-count (bell), /order-status (one order) and,
 * on the Orders page, /orders-status (every order) — and none of them ever
 * stopped. This is the same data, answered once. The old endpoints are kept
 * as they were; other pages still call them.
 *
 * WHAT IS CHEAP ABOUT IT
 * ----------------------
 *  - ONE aggregate query for the bell (count + max id) instead of two.
 *  - ONE primary-key lookup for the orders, and only when there is an order to
 *    ask about; a visitor with no orders costs no orders query at all.
 *  - A guest with no orders in the session skips the notifications query too:
 *    scopeVisibleToCurrentCustomer() would answer "nothing" via `1 = 0`, so the
 *    answer is returned without asking the database.
 *  - Only the columns the status payload reads are selected.
 *
 * WHICH ORDERS ARE ANSWERED
 * -------------------------
 *  - Every id the page asks about (`order_ids[]`), in whatever status it is in
 *    now. The page is what knows which orders it is still watching, and the
 *    one poll that has to notice an order becoming completed/cancelled is the
 *    poll that asks about it by id (see OrderController::customerOrdersStatus()
 *    for the bug a status filter caused).
 *  - The order the session points at (customer_order_id), but only while it is
 *    still active — so a page that is not watching anything yet still notices
 *    an order placed in another tab, and switches to the fast cadence — or, on
 *    the first poll of a page load (`discover=1`), whatever its status is, so
 *    an order that finished before this page opened still gets its one-time
 *    "completed" popup, exactly as before.
 *
 * Plus `completed_popups`: the completed orders still waiting for the shared
 * Order Completed pop-up (App\Support\OrderCompletedPopup). Not tied to any
 * order id the page asked about, because the customer may be on any page.
 *
 * Ownership is resolved on the server only, exactly like the endpoints this
 * replaces: a client can narrow the answer to orders it owns, never widen it.
 */
final class CustomerLiveStatus
{
    /** Statuses after which nothing about an order will change again. */
    public const TERMINAL = ['completed', 'cancelled'];

    /** Never answer about more ids than a guest session can even hold. */
    private const MAX_IDS = GuestOrders::MAX_TRACKED;

    private const ORDER_COLUMNS = [
        'id', 'user_id', 'order_number', 'status', 'type', 'payment_method',
        'payment_status', 'discount_status', 'discount_type', 'discount_amount',
        'total', 'updated_at',
    ];

    public static function snapshot(Request $request): array
    {
        $customerId = Auth::guard('customer')->check() ? Auth::guard('customer')->id() : null;

        $requested = collect((array) $request->input('order_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->take(self::MAX_IDS)
            ->values();

        $sessionOrderId = (int) (session('customer_order_id') ?: session('guest_order_id') ?: 0);
        $discover = $request->boolean('discover');

        // concat(), not push(): push() would mutate $requested and make the
        // session's order look like one the page explicitly asked about.
        $candidateIds = $sessionOrderId > 0
            ? $requested->concat([$sessionOrderId])->unique()->values()
            : $requested;

        $orders = $candidateIds->isEmpty()
            ? collect()
            : self::ownedOrders($candidateIds->all(), $customerId);

        // Requested ids always answer; the session's order answers while it is
        // active, or on the page's first poll.
        $orders = $orders->filter(function (Order $order) use ($requested, $sessionOrderId, $discover) {
            if ($requested->contains((int) $order->id)) {
                return true;
            }

            return (int) $order->id === $sessionOrderId
                && ($discover || !in_array($order->status, self::TERMINAL, true));
        })->values();

        $active = $orders->contains(fn (Order $o) => !in_array($o->status, self::TERMINAL, true));

        return array_merge(self::bell($customerId), [
            'orders' => $orders->map(fn (Order $o) => array_merge(['has_order' => true], self::payload($o)))->all(),
            'session_order_id' => $orders->contains(fn (Order $o) => (int) $o->id === $sessionOrderId) ? $sessionOrderId : null,
            'active' => $active,
            'completed_popups' => OrderCompletedPopup::queue($customerId),
        ]);
    }

    /** @param  int[]  $ids */
    private static function ownedOrders(array $ids, ?int $customerId)
    {
        $query = Order::query()->select(self::ORDER_COLUMNS)->whereIn('id', $ids);

        if ($customerId !== null) {
            return $query->where('user_id', $customerId)->get();
        }

        return $query->whereNull('user_id')
            ->get()
            ->filter(fn (Order $order) => GuestOrders::owns($order->id))
            ->values();
    }

    /** Unread count and newest id, in one query (two before). */
    private static function bell(?int $customerId): array
    {
        if ($customerId === null && !GuestOrders::ids()) {
            return ['unread' => 0, 'latest_id' => 0];
        }

        $row = Notification::visibleToCurrentCustomer()
            ->inTray()
            ->selectRaw('COUNT(CASE WHEN read_at IS NULL THEN 1 END) AS unread, MAX(id) AS latest_id')
            ->first();

        return [
            'unread' => (int) ($row->unread ?? 0),
            'latest_id' => (int) ($row->latest_id ?? 0),
        ];
    }

    /**
     * The fields every order-status answer carries, for one order. Shared with
     * OrderController so the single-order, multi-order and live-status
     * endpoints can never describe the same order differently.
     */
    public static function payload(Order $order): array
    {
        $discountStatus = $order->discount_status ?? 'approved';
        $discountType = $order->discount_type;
        $discountAmount = (float) ($order->discount_amount ?? 0);

        // If the customer already chose Continue Transaction for this
        // exact order, the customer-facing status must stay approved even
        // if an old/stale rejected value is encountered.
        if ((int) session('discount_continued_order_id') === (int) $order->id) {
            $discountStatus = 'approved';
            $discountType = null;
            $discountAmount = 0;
        }

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            // Drives the customer tracker's step-3 label and helper text
            // ("Ready for Pick-up" vs "Served"). Additive — existing consumers
            // ignore it.
            'type' => $order->type,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'discount_status' => $discountStatus,
            'discount_type' => $discountType,
            'discount_amount' => $discountAmount,
            'total' => (float) ($order->total ?? 0),
            'updated_at' => optional($order->updated_at)->toIso8601String(),
        ];
    }
}

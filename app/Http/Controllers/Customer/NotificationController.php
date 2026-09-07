<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;

/**
 * Customer-facing notification feed.
 *
 * Every query goes through Notification::scopeVisibleToCurrentCustomer(), which
 * derives ownership from the session/guard on the server. No endpoint here
 * accepts an order id, user id, or notification id from the client, so there is
 * nothing for a caller to tamper with.
 */
class NotificationController extends Controller
{
    /** Most recent notifications shown in the dropdown. */
    private const FEED_LIMIT = 20;

    /**
     * Badge count. Polled every ~20s by the bell partial.
     */
    public function unreadCount(): JsonResponse
    {
        $scoped = fn () => Notification::visibleToCurrentCustomer();

        return response()->json([
            'unread' => $scoped()->whereNull('read_at')->count(),

            // Highest notification id this viewer can see. The bell uses it to
            // detect genuinely NEW arrivals for the toast layer. A rising id is
            // reliable where a rising unread COUNT is not: if one notification
            // is marked read in another tab at the same moment another arrives,
            // the count can stay level and the new one would be missed.
            'latest_id' => (int) ($scoped()->max('id') ?? 0),
        ]);
    }

    /**
     * The dropdown list: newest first, capped, with unread flagged so the UI
     * can style them differently.
     */
    public function index(): JsonResponse
    {
        /*
         * 'order' is eager-loaded because every row carries its order number
         * out to the toast layer now. A customer can have more than one order
         * in flight at once since item 43 / Round 3A, so "Order is being
         * prepared" on its own is genuinely ambiguous — the toast has to say
         * WHICH order. Without the eager load this would be one query per row.
         */
        $notifications = Notification::visibleToCurrentCustomer()
            ->with('order:id,order_number')
            ->latest('id')
            ->limit(self::FEED_LIMIT)
            ->get();

        return response()->json([
            'unread' => $notifications->whereNull('read_at')->count(),
            'notifications' => $notifications->map(fn (Notification $n) => [
                'id'      => $n->id,
                'type'    => $n->type,
                'title'   => $n->title,
                'message' => $n->message,
                // The order this notification is about, as a short reference
                // the toast can show as a chip. Null for any row whose order
                // has since been deleted.
                'order_number' => $n->order?->order_number,
                'is_read' => $n->read_at !== null,
                'ago'     => $n->created_at->diffForHumans(null, true) . ' ago',
            ])->values(),
        ]);
    }

    /**
     * Mark everything this visitor can see as read.
     *
     * Scoped the same way as the read paths, so this can only ever clear the
     * caller's own notifications.
     */
    public function markAllRead(): JsonResponse
    {
        Notification::visibleToCurrentCustomer()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }
}

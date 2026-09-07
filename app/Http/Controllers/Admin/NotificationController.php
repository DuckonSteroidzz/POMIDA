<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesBranchScope;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;

/**
 * Staff/admin notification feed.
 *
 * Branch scoping comes from ResolvesBranchScope::getSelectedBranch() — the same
 * rule the Completed Orders / Inventory / Summary pages use. Staff are locked
 * to their own branch there, so a Branch 1 staff member cannot be served a
 * Branch 2 notification even by crafting the request; the branch is never read
 * from client input.
 *
 * These routes sit behind the 'admin' guard middleware plus role:admin,staff.
 */
class NotificationController extends Controller
{
    use ResolvesBranchScope;

    private const FEED_LIMIT = 20;

    public function unreadCount(): JsonResponse
    {
        $scoped = fn () => Notification::visibleToStaff($this->getSelectedBranch());

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

    public function index(): JsonResponse
    {
        $notifications = Notification::visibleToStaff($this->getSelectedBranch())
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
                'is_read' => $n->read_at !== null,
                'ago'     => $n->created_at->diffForHumans(null, true) . ' ago',
            ])->values(),
        ]);
    }

    /**
     * Marks read only within the current branch scope, so an admin clearing
     * "Branch 1" does not silently clear Branch 2's queue as well.
     */
    public function markAllRead(): JsonResponse
    {
        Notification::visibleToStaff($this->getSelectedBranch())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }
}

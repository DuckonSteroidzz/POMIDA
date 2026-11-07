<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ResolvesBranchScope;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\AdminOrderAccess;
use App\Support\ShopDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The Refunds page (QA, 5 Nov 2026): "I can't see the refund … we can't open
 * it again."
 *
 * Before this, a refund was visible in exactly one place — the Refunds Owed
 * band on the order board — and only while it was still owed. Mark Refunded
 * dropped it from the band and nothing could open it again. This page lists
 * every refund, owed AND refunded, and each one has a detail page that stays
 * reachable after it is settled.
 *
 * Read-only apart from linking to the existing Mark Refunded action
 * (AdminController::markOrderRefunded): who owes a refund is decided by the
 * two cancel paths, and nothing here changes it. Same role group as that
 * action (owner, supervisor, staff); branch scope is getSelectedBranch() for
 * the list — the Viewing bar for the owner, their own branch for everyone
 * else — and AdminOrderAccess::resolveInScope() for one refund.
 */
class RefundController extends Controller
{
    use ResolvesBranchScope;

    public const PER_PAGE = 25;

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        // Branch, dates and search — everything except the status tab, so
        // the tab counts say how many each tab WOULD show.
        $base = Order::refunds()
            ->when(
                ($branch = $this->getSelectedBranch()) !== 'all',
                fn ($q) => $q->where('branch_id', $branch)
            );

        $this->applyDates($base, $filters['from'], $filters['to']);
        $this->applySearch($base, $filters['q']);

        $counts = [
            'owed'     => (clone $base)->where('payment_status', 'refund_pending')->count(),
            'refunded' => (clone $base)->where('payment_status', 'refunded')->count(),
        ];
        $counts['all'] = $counts['owed'] + $counts['refunded'];

        $owedTotal = (float) (clone $base)->where('payment_status', 'refund_pending')->sum('total');

        $refunds = (clone $base)
            ->when($filters['status'] === 'owed', fn ($q) => $q->where('payment_status', 'refund_pending'))
            ->when($filters['status'] === 'refunded', fn ($q) => $q->where('payment_status', 'refunded'))
            ->with(['customer', 'branch', 'refundedBy'])
            // Owed first, the longest-waiting customer at the top; then the
            // history, most recently refunded first.
            ->orderByRaw('CASE WHEN payment_status = ? THEN 0 ELSE 1 END', ['refund_pending'])
            ->orderByRaw('CASE WHEN payment_status = ? THEN cancelled_at END ASC', ['refund_pending'])
            ->orderByRaw('COALESCE(refunded_at, cancelled_at) DESC')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.refunds', compact('refunds', 'counts', 'owedTotal', 'filters'));
    }

    /**
     * One refund, read-only, any time — before and after it is refunded.
     * Out of the viewer's branch scope it is a 404, like every other order
     * endpoint; an order that never owed a refund is a 404 here too.
     */
    public function show(int $id)
    {
        $order = AdminOrderAccess::resolveInScope($id, [
            'items',
            'items.removedIngredients',
            'customer',
            'branch',
            'refundedBy',
        ]);

        abort_unless($order->isRefund(), 404);

        return view('admin.refund-show', compact('order'));
    }

    /** Only known values survive; anything else falls back to "show all". */
    private function filters(Request $request): array
    {
        $date = fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
                ? $value
                : null;

        $status = $request->query('status');

        return [
            'status' => in_array($status, ['owed', 'refunded'], true) ? $status : 'all',
            'from'   => $date($request->query('from')),
            'to'     => $date($request->query('to')),
            'q'      => mb_substr(trim((string) $request->query('q', '')), 0, 100),
        ];
    }

    /**
     * Cancelled-on range, in shop days (Manila) — the same conversion as
     * App\Support\OrderHistoryFilter, so "Nov 5" means Nov 5 at the counter
     * and not Nov 5 in the app's UTC.
     */
    private function applyDates($query, ?string $from, ?string $to): void
    {
        $appZone  = ShopDay::appTimezone();
        $shopZone = ShopDay::timezone();

        if ($from !== null) {
            $start = CarbonImmutable::parse($from, $shopZone)->startOfDay();
            $query->where('cancelled_at', '>=', Carbon::instance($start)->setTimezone($appZone));
        }

        if ($to !== null) {
            $end = CarbonImmutable::parse($to, $shopZone)->startOfDay()->addDay();
            $query->where('cancelled_at', '<', Carbon::instance($end)->setTimezone($appZone));
        }
    }

    /** Order number, or the customer's name, email or phone. */
    private function applySearch($query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $like = '%' . addcslashes($term, '%_\\') . '%';

        $query->where(function ($q) use ($like) {
            $q->where('order_number', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('contact_number', 'like', $like));
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Support\GuestOrders;
use Illuminate\Support\Facades\Auth;

/**
 * In-app notification.
 *
 * This model is the ONE place that knows how notifications are created and who
 * is allowed to read them — the same "one shared entry point" pattern already
 * used by Voucher::redemptionErrorFor() and Order::pwdSeniorDiscountFor().
 * Controllers call the static factory helpers below; they never build rows or
 * ownership queries themselves.
 *
 * Two audiences share the table:
 *   'customer' — the person who placed the order
 *   'staff'    — the counter, scoped by branch
 */
class Notification extends Model
{
    use HasFactory;

    public const AUDIENCE_CUSTOMER = 'customer';
    public const AUDIENCE_STAFF    = 'staff';

    protected $fillable = [
        'user_id',
        'order_id',
        'branch_id',
        'audience',
        'type',
        'title',
        'message',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /*
    |--------------------------------------------------------------------------
    | READ SCOPES — who is allowed to see what
    |--------------------------------------------------------------------------
    */

    /**
     * Limit a query to notifications the CURRENT visitor may read.
     *
     * This mirrors OrderController::resolveOwnedOrder() exactly, and for the
     * same reason: the ID alone must never be what grants access.
     *
     *  - Logged-in customer -> rows carrying their user_id.
     *  - Guest -> rows for the orders THIS session placed (see
     *    App\Support\GuestOrders) and only if the order is a genuine guest
     *    order (user_id IS NULL). A guest can therefore never read a registered
     *    customer's notifications, even by guessing an order ID.
     *
     * Nothing here reads an identifier from the request body or query string,
     * so there is no client-supplied value to tamper with.
     */
    public function scopeVisibleToCurrentCustomer(Builder $query): Builder
    {
        $query->where('audience', self::AUDIENCE_CUSTOMER);

        if (Auth::guard('customer')->check()) {
            return $query->where('user_id', Auth::guard('customer')->id());
        }

        $guestOrderIds = GuestOrders::ids();

        if (!$guestOrderIds) {
            // No order in this session: a guest has nothing to see.
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereNull('user_id')
            ->whereIn('order_id', $guestOrderIds)
            ->whereHas('order', fn ($o) => $o->whereNull('user_id'));
    }

    /**
     * Limit a query to staff-audience notifications for the given branch scope.
     *
     * $branchScope comes from AdminController::getSelectedBranch():
     * an int branch id, or the string 'all' (admin viewing every branch).
     * Staff are locked to their own branch there, so a Branch 1 staff member
     * can never be handed a Branch 2 scope.
     */
    public function scopeVisibleToStaff(Builder $query, int|string $branchScope): Builder
    {
        $query->where('audience', self::AUDIENCE_STAFF);

        if ($branchScope !== 'all') {
            $query->where('branch_id', (int) $branchScope);
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | WRITE HELPERS — the only places that create notification rows
    |--------------------------------------------------------------------------
    */

    /**
     * Human-readable copy for each order status the app actually uses.
     * The real flow is pending -> preparing -> serving -> completed, plus
     * cancelled. There is no 'ready' status in this system.
     */
    private const STATUS_COPY = [
        'preparing' => ['Order is being prepared', 'Good news — the kitchen has started on order #%s.'],
        'serving'   => ['Order is on its way',     'Order #%s is being served now.'],
        'completed' => ['Order complete',          'Order #%s is complete. Enjoy! 🍑'],
        'cancelled' => ['Order cancelled',         'Order #%s has been cancelled. Please ask our staff if you need help.'],
    ];

    /**
     * Tell the customer their order moved to a new status.
     * Silently does nothing for a status with no customer-facing copy.
     */
    public static function orderStatusChanged(Order $order, string $status): ?self
    {
        if (!isset(self::STATUS_COPY[$status])) {
            return null;
        }

        [$title, $messageTemplate] = self::STATUS_COPY[$status];

        return self::forCustomer(
            $order,
            'order_status_changed',
            $title,
            sprintf($messageTemplate, $order->order_number)
        );
    }

    /**
     * Staff approved the customer's GCash payment.
     */
    public static function gcashApproved(Order $order): ?self
    {
        return self::forCustomer(
            $order,
            'gcash_approved',
            'GCash payment confirmed',
            'Your GCash payment for order #' . $order->order_number . ' has been verified. Thank you!'
        );
    }

    /**
     * Staff rejected the customer's GCash payment.
     */
    public static function gcashRejected(Order $order): ?self
    {
        return self::forCustomer(
            $order,
            'gcash_rejected',
            'GCash payment not verified',
            'We could not verify your GCash payment for order #' . $order->order_number .
            '. The order was cancelled — please talk to our staff.'
        );
    }

    /**
     * A customer placed an order online. Staff need to see it at the counter.
     *
     * Manual (walk-in) orders deliberately do NOT call this: staff key those in
     * themselves, so notifying them of their own action is pure noise.
     */
    public static function newOrderForStaff(Order $order): self
    {
        return self::forStaff(
            $order,
            'new_order',
            'New online order',
            'Order #' . $order->order_number . ' came in' .
            ($order->type === 'dine_in' && $order->table_number
                ? ' from table ' . $order->table_number
                : ' for ' . ($order->type === 'pick_up' ? 'pick-up' : 'dine-in')) .
            ' — ₱' . number_format((float) $order->total, 2) . '.'
        );
    }

    /**
     * A customer declared they paid via GCash and the order is now sitting in
     * the verification queue. Operationally the most important one: before
     * this existed staff had no way to know a payment needed checking except
     * refreshing the page.
     */
    public static function gcashAwaitingVerification(Order $order): self
    {
        return self::forStaff(
            $order,
            'gcash_awaiting_verification',
            'GCash payment needs verifying',
            'A customer marked order #' . $order->order_number . ' as paid via GCash (₱' .
            number_format((float) $order->total, 2) . '). Please verify it.'
        );
    }

    /**
     * A customer cancelled their own order AFTER declaring they had already
     * paid by GCash, so money may really have left their account.
     *
     * This is the most time-sensitive staff notification in the system. There
     * is no merchant API here — nothing in this app can reverse a GCash
     * transfer — so the refund is a person at the counter sending money back.
     * If nobody reads this, the customer is simply out of pocket, which is why
     * the wording names the action instead of just reporting the event.
     */
    public static function refundPending(Order $order): self
    {
        return self::forStaff(
            $order,
            'refund_pending',
            'Refund needed — customer cancelled a paid order',
            'A customer cancelled order #' . $order->order_number . ' after marking it paid via GCash (₱' .
            number_format((float) $order->total, 2) . '). Send the refund manually, then mark it refunded.'
        );
    }

    /**
     * A customer's lifetime points crossed a reward threshold.
     *
     * The first notification here that is NOT about an order, which is what
     * order_id being nullable was left for — the migration says as much. It
     * goes through the same table, the same audience column and therefore the
     * same bell the customer already watches for order updates; nothing about
     * the delivery path is special-cased for it.
     *
     * Customer-audience rows for a signed-in user are matched by user_id
     * alone in scopeVisibleToCurrentCustomer(), so a null order_id is fully
     * visible. Guests are not eligible for these rewards at all (they have no
     * account to issue against), which is why this takes a User rather than
     * going through forCustomer()'s order-shaped signature.
     */
    public static function pointsRewardEarned(\App\Models\User $user, int $milestone): self
    {
        return self::create([
            'user_id'   => $user->id,
            'order_id'  => null,
            'branch_id' => null,
            'audience'  => self::AUDIENCE_CUSTOMER,
            'type'      => 'points_reward_earned',
            'title'     => 'You earned a voucher reward!',
            'message'   => "Congratulations! You've reached " . $milestone
                . ' points and earned a voucher discount. Please claim it from our staff '
                . 'at the counter — you can use it on your next order or your next visit.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internal builders
    |--------------------------------------------------------------------------
    */

    /**
     * Returns null for orders that have no customer who could ever read the
     * row. A manual/walk-in order is keyed in by staff at the counter: it has
     * user_id = NULL and processed_by set, and no browser session anywhere
     * points at it, so a customer notification for one would be a dead row
     * that nobody can reach. A guest ONLINE order also has user_id = NULL but
     * processed_by = NULL, and its session does point at it — that one counts.
     */
    private static function forCustomer(Order $order, string $type, string $title, string $message): ?self
    {
        if ($order->user_id === null && $order->processed_by !== null) {
            return null;
        }

        return self::create([
            // Null for a guest order — such rows are matched by order_id
            // against the session in scopeVisibleToCurrentCustomer().
            'user_id'   => $order->user_id,
            'order_id'  => $order->id,
            'branch_id' => $order->branch_id,
            'audience'  => self::AUDIENCE_CUSTOMER,
            'type'      => $type,
            'title'     => $title,
            'message'   => $message,
        ]);
    }

    private static function forStaff(Order $order, string $type, string $title, string $message): self
    {
        return self::create([
            'user_id'   => null,
            'order_id'  => $order->id,
            'branch_id' => $order->branch_id,
            'audience'  => self::AUDIENCE_STAFF,
            'type'      => $type,
            'title'     => $title,
            'message'   => $message,
        ]);
    }
}

<?php

namespace App\Models;

use App\Models\DiscountCard;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    /**
     * The flat PWD / Senior Citizen discount rate (20%), applied to the order
     * subtotal and floored so it never exceeds it.
     *
     * Single source of truth for this formula. It used to be copy-pasted
     * inline in OrderController::placeOrder() (twice, for the saved-card and
     * transaction-specific branches) and was about to become a third
     * independent copy for the admin Manual Order flow — exactly the kind of
     * drift that already caused a real bug this session (see Voucher's
     * redemptionErrorFor()/discountFor() for the voucher equivalent).
     *
     * Rounds ONCE, here, so subtotal - discount always nets out to the saved
     * total to the centavo — do not round again at the call site.
     */
    public const PWD_SENIOR_DISCOUNT_RATE = 0.20;

    public static function pwdSeniorDiscountFor(float $subtotal): float
    {
        return round(min($subtotal * self::PWD_SENIOR_DISCOUNT_RATE, $subtotal), 2);
    }

    /**
     * Does the voucher beat the PWD/Senior discount on this order?
     *
     * A voucher and a PWD/Senior discount never stack: both are priced against
     * the same subtotal and only the LARGER is applied, with the loser left
     * completely unspent. This is the single definition of that comparison.
     *
     * It was written inline in OrderController::placeOrder() and the admin
     * Manual Order flow (2026-09-03) needs the identical rule, including the
     * tie-break — so it moved here rather than being copied. The logic is
     * exactly what placeOrder() already applied; the customer path's behaviour
     * is unchanged.
     *
     * THE TIE RULE
     * ------------
     * Strictly greater-than, so a PWD/Senior discount WINS A TIE and the
     * voucher is left for another day. That is the customer-favouring choice:
     * on equal money today they keep the voucher, and the PWD/Senior
     * entitlement is a legal right rather than a promotion that can run out,
     * so using it costs them nothing. Picking the voucher on a tie would burn
     * a claim for no extra benefit.
     *
     * @param  float      $voucherDiscount  What the voucher is worth here.
     * @param  float|null $cardDiscount     What PWD/Senior is worth, or null
     *                                      when no PWD/Senior was claimed at
     *                                      all — in which case the voucher is
     *                                      unopposed and simply wins.
     */
    public static function voucherBeatsCard(float $voucherDiscount, ?float $cardDiscount): bool
    {
        return $cardDiscount === null || $voucherDiscount > $cardDiscount;
    }
    protected $fillable = [
        'order_number',
        'receipt_number',

        // Relationships
        'user_id',
        'branch_id',
        'processed_by',

        // Order type
        'type',
        'table_number',
        'is_takeout',

        // Status
        'status',

        // Pricing
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',

        // Discount
        'discount_type',
        'voucher_id',

        // PWD / Senior discount beneficiary
        'discount_card_id',
        'discount_beneficiary_name',
        'discount_beneficiary_card_number',
        'discount_beneficiary_expiration',
        'discount_id_image',
        'discount_status',

        // Payment
        'payment_method',
        'payment_status',
        'amount_paid',
        'change_amount',

        // Notes
        'notes',
        'cancellation_reason',

        // Status timestamps
        'confirmed_at',
        'preparing_at',
        'serving_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'is_takeout' => 'boolean',

        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'change_amount' => 'decimal:2',

        'discount_beneficiary_expiration' => 'date',

        'confirmed_at' => 'datetime',
        'preparing_at' => 'datetime',
        'serving_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Free the dine-in table as soon as this order stops being active.
     *
     * Hooked on the model rather than on each controller because an order
     * reaches 'completed' or 'cancelled' from four different places (staff
     * complete, staff cancel, GCash-failure cancel, customer cancel after a
     * rejected discount card). Every one of them goes through Eloquent's
     * save(), so one hook here covers all of them and any added later — a
     * controller-by-controller release would be one edit away from leaving a
     * table locked forever.
     *
     * wasChanged() means this only fires on the transition, not on every
     * subsequent save of an already-finished order.
     */
    protected static function booted(): void
    {
        static::saved(function (self $order) {
            if (!$order->wasChanged('status')) {
                return;
            }

            if (!in_array($order->status, \App\Services\TableOccupancy::FINISHED_STATUSES, true)) {
                return;
            }

            \App\Services\TableOccupancy::releaseForOrder(
                $order,
                $order->status === 'completed' ? 'order_completed' : 'order_cancelled'
            );
        });
    }

    /**
     * RELATIONSHIPS
     */

    // Order placed by a customer
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Order belongs to a branch
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // Order processed by staff/admin
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // Order has many items
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // PWD/Senior discount card used for this order
    public function discountCard(): BelongsTo
    {
        return $this->belongsTo(DiscountCard::class, 'discount_card_id');
    }
        public function voucher()
    {
        return $this->belongsTo(\App\Models\Voucher::class, 'voucher_id');
    }

    /**
     * HELPER METHODS
     */

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isPreparing(): bool
    {
        return $this->status === 'preparing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Short, human name for whatever discount this order actually carries
     * ('PWD', 'Senior Citizen', 'Voucher', or a bare 'Discount' when the type
     * is unknown but money came off). Empty string when there is no discount.
     *
     * This is the SINGLE source of truth for every screen that names a
     * discount — the admin Active Orders card and its detail modal, the
     * customer's order page and the receipt. Do not re-derive the label
     * anywhere else.
     */
    public function discountLabel(): string
    {
        return match (strtolower((string) $this->discount_type)) {
            'pwd' => 'PWD',
            'senior', 'senior_citizen', 'senior citizen' => 'Senior Citizen',
            'voucher' => 'Voucher',
            default => $this->discount_amount > 0 ? 'Discount' : '',
        };
    }

    /**
     * The same label as discountLabel(), spelled for a discount line
     * ('Voucher Discount', 'PWD Discount', 'Senior Citizen Discount').
     * Empty string when there is no discount.
     */
    public function discountDisplayLabel(): string
    {
        $label = $this->discountLabel();

        return match ($label) {
            '', 'Discount' => $label,
            default => $label . ' Discount',
        };
    }
}
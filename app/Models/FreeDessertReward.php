<?php

namespace App\Models;

use App\Services\StreakRewardSettings;
use App\Support\RewardCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One loyalty reward earned by one customer for one full stamp card.
 * Created only by App\Services\FreeDesserts::grant(); spent and handed back
 * only by FreeDesserts as well — see that class for the rules.
 *
 * (The table and class keep their first name, from when the only reward was a
 * free dessert. What a reward is now comes from its own snapshot columns,
 * copied from the owner's Streak Rewards settings when it was earned.)
 *
 * @property int         $id
 * @property int         $user_id
 * @property int         $loyalty_award_id
 * @property string      $code          RWD + 8, stored without dashes (App\Support\RewardCode), UNIQUE
 * @property string|null $reward_type   'menu_item' | 'voucher'; null = earned before rewards had snapshots
 * @property int|null    $menu_item_id  menu_item: the item given free
 * @property string|null $item_name     menu_item: its name when earned
 * @property int|null    $voucher_id    voucher: the template the terms were copied from
 * @property string|null $voucher_code  voucher: that template's code
 * @property string|null $discount_type voucher: 'percent' | 'fixed'
 * @property string|null $discount_value
 * @property string|null $minimum_order
 * @property int|null    $branch_id     the ISSUING branch (whose stamp card earned it) — the only
 *                                      branch it can be used at, item and voucher rewards alike
 *                                      (branchErrorFor). Null only for a reward earned before
 *                                      rewards were per branch: usable at any branch.
 * @property string      $status        available | used | expired
 * @property int|null    $order_id
 * @property int|null    $order_item_id the ₱0 line of a menu-item reward
 * @property int|null    $used_branch_id
 * @property \Illuminate\Support\Carbon|null $expires_at  null = never expires
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property \Illuminate\Support\Carbon|null $notified_at  when the "You earned a reward!" pop-up
 *                                                         was shown for it (App\Support\FreeDessertPrompt)
 */
class FreeDessertReward extends Model
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_USED = 'used';
    public const STATUS_EXPIRED = 'expired';

    /** statusKey(): what the customer and the admin check are told. */
    public const KEY_READY = 'ready';
    public const KEY_ON_ORDER = 'on_order';
    public const KEY_USED = 'used';
    public const KEY_EXPIRED = 'expired';
    public const KEY_NOT_SET = 'not_set';

    protected $fillable = [
        'user_id',
        'loyalty_award_id',
        'code',
        'reward_type',
        'menu_item_id',
        'item_name',
        'voucher_id',
        'voucher_code',
        'discount_type',
        'discount_value',
        'minimum_order',
        'branch_id',
        'status',
        'order_id',
        'order_item_id',
        'used_branch_id',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'user_id'          => 'integer',
        'loyalty_award_id' => 'integer',
        'menu_item_id'     => 'integer',
        'voucher_id'       => 'integer',
        'discount_value'   => 'decimal:2',
        'minimum_order'    => 'decimal:2',
        'branch_id'        => 'integer',
        'order_id'         => 'integer',
        'order_item_id'    => 'integer',
        'used_branch_id'   => 'integer',
        'expires_at'       => 'datetime',
        'used_at'          => 'datetime',
        'notified_at'      => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function loyaltyAward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAward::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /** The branch that issued it, and the only one it can be used at (no foreign key — see the migration). */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** "Main Branch"; "Any branch" for a reward from before rewards were per branch. */
    public function branchName(): string
    {
        if ($this->branch_id === null) {
            return 'Any branch';
        }

        return $this->branch?->name ?? 'Another branch';
    }

    /**
     * Where it may be used — the one branch rule for item AND voucher rewards:
     * only at the branch that issued it. A NULL branch_id (earned before
     * rewards were per branch) is any branch, tested on its own first so a
     * null can never be cast into a match. A null $orderBranchId ("no branch
     * known") refuses a branch-bound reward — fail-safe.
     */
    public function branchErrorFor(?int $orderBranchId): ?string
    {
        if ($this->branch_id === null) {
            return null;
        }

        if ($orderBranchId !== null && (int) $this->branch_id === $orderBranchId) {
            return null;
        }

        $name = $this->branch?->name;

        return $name
            ? 'This reward can only be used at ' . $name . '.'
            : 'This reward belongs to a different branch.';
    }

    public function isItemReward(): bool
    {
        return $this->reward_type === StreakRewardSettings::TYPE_MENU_ITEM;
    }

    public function isVoucherReward(): bool
    {
        return $this->reward_type === StreakRewardSettings::TYPE_VOUCHER;
    }

    /** RWD-XXXX-XXXX. */
    public function displayCode(): string
    {
        return RewardCode::display($this->code);
    }

    /** "Free Chocolate Cake", "10% off one order (min. order ₱200.00)", ... */
    public function description(): string
    {
        return StreakRewardSettings::describe($this);
    }

    /** Past its expiry instant. expires_at is always a precise instant (never a date-only midnight). */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(now());
    }

    /** Available, not past its expiry, and it knows what it is. */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE
            && $this->reward_type !== null
            && ! $this->isExpired();
    }

    /** The query form of isUsable() — what checkout may spend. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AVAILABLE)
            ->whereNotNull('reward_type')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** ready | on_order | used | expired | not_set */
    public function statusKey(): string
    {
        if ($this->status === self::STATUS_USED) {
            $order = $this->order;

            return $order && ! in_array($order->status, ['completed', 'cancelled'], true)
                ? self::KEY_ON_ORDER
                : self::KEY_USED;
        }

        if ($this->status === self::STATUS_EXPIRED || $this->isExpired()) {
            return self::KEY_EXPIRED;
        }

        return $this->reward_type === null ? self::KEY_NOT_SET : self::KEY_READY;
    }

    public function statusLabel(): string
    {
        return match ($this->statusKey()) {
            self::KEY_READY    => 'Ready to use',
            self::KEY_ON_ORDER => 'On a current order',
            self::KEY_USED     => 'Used',
            self::KEY_EXPIRED  => 'Expired',
            default            => 'Not ready yet',
        };
    }

    /**
     * Why the OWNER cannot spend this reward at all right now, or null. Only
     * ever shown to the owner (a stranger's code is "Invalid voucher code."
     * before this is asked).
     */
    public function usabilityError(): ?string
    {
        return match ($this->statusKey()) {
            self::KEY_READY    => null,
            self::KEY_ON_ORDER, self::KEY_USED => 'This reward code has already been used.',
            self::KEY_EXPIRED  => 'This reward code has expired.',
            default            => 'This reward is not ready to use yet. Please try again later.',
        };
    }

    /**
     * A voucher reward's order rules — the same two a voucher has, in the
     * same order and words as Voucher::redemptionErrorFor(): where it may be
     * spent (branchErrorFor), then the minimum paid subtotal.
     */
    public function redemptionErrorFor(float $subtotal, ?int $orderBranchId): ?string
    {
        if (($error = $this->branchErrorFor($orderBranchId)) !== null) {
            return $error;
        }

        if ($subtotal < (float) $this->minimum_order) {
            return 'Minimum order of ₱' . number_format((float) $this->minimum_order, 2) . ' required.';
        }

        return null;
    }

    /** Voucher::discountFor()'s formula: rounded once, never above the subtotal. */
    public function discountFor(float $subtotal): float
    {
        $discount = $this->discount_type === 'percent'
            ? $subtotal * ((float) $this->discount_value / 100)
            : (float) $this->discount_value;

        return round(max(0, min($discount, $subtotal)), 2);
    }
}

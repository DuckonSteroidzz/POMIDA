<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'id_number',
        'full_name',
        'id_image',
        'expiration_date',
        'is_verified',
        'verified_at',
        'verified_by',
        'is_active',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'expiration_date' => 'date',
        'verified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Compatibility accessors used by the customer/admin views.
     * The model stores full_name/id_number, while older views may use name/card_number.
     */
    public function getNameAttribute($value)
    {
        return $this->attributes['full_name'] ?? null;
    }

    public function getCardNumberAttribute($value)
    {
        return $this->attributes['id_number'] ?? null;
    }

    public function getStatusAttribute($value)
    {
        if (array_key_exists('verification_status', $this->attributes)) {
            return $this->attributes['verification_status'];
        }

        return !empty($this->attributes['is_verified']) ? 'verified' : 'pending';
    }

    /*
    |--------------------------------------------------------------------------
    | SINGLE SOURCE OF TRUTH FOR THE CARD EXPIRATION RULE
    |--------------------------------------------------------------------------
    |
    | "Is this PWD/Senior card still usable?" used to be decided in four
    | independent places, each with its own wording and its own idea of what
    | counts as expired:
    |
    |   - the cart page's date input (a `min` attribute),
    |   - the cart page's JS pre-flight check in openOrderConfirmation(),
    |   - a Laravel validation rule (after_or_equal:today) on placeOrder(),
    |   - two hand-written Carbon comparisons inside placeOrder() itself, one
    |     for the saved-card branch and one for the transaction branch.
    |
    | That drift is what let the cart show "This discount card has already
    | expired." and a live -20% discount at the same time: the JS that printed
    | the message and the JS that applied the discount did not share a rule.
    |
    | Everything now calls this method, and the cart page renders its messages
    | from the constants below, so the preview and the charge cannot disagree.
    |
    */

    public const ERROR_EXPIRATION_MISSING = 'Please enter the discount card expiration date.';
    public const ERROR_EXPIRATION_INVALID = 'That discount card expiration date is not a valid date.';
    public const ERROR_EXPIRED = 'This discount card has already expired.';

    /**
     * Why this expiration date makes the card unusable, or null when it is
     * fine. Accepts anything date-ish: a Y-m-d string from the cart form, a
     * Carbon instance from the saved-card column, or null.
     */
    public static function expirationErrorFor($expiration): ?string
    {
        if ($expiration === null || $expiration === '') {
            return self::ERROR_EXPIRATION_MISSING;
        }

        try {
            $date = $expiration instanceof \DateTimeInterface
                ? \Illuminate\Support\Carbon::instance($expiration)
                : \Illuminate\Support\Carbon::parse((string) $expiration);
        } catch (\Throwable $e) {
            return self::ERROR_EXPIRATION_INVALID;
        }

        return $date->startOfDay()->lt(today())
            ? self::ERROR_EXPIRED
            : null;
    }

    public function isExpired(): bool
    {
        return self::expirationErrorFor($this->expiration_date) !== null;
    }

    /**
     * RELATIONSHIPS
     */

    // Belongs to a user (yung may-ari ng card)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Belongs to a verifier (admin/staff na nag-verify)
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
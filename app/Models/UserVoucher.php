<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserVoucher extends Model
{
    protected $fillable = [
        'user_id',
        // The admin who handed this code out, for a claim minted at the
        // counter rather than won on the wheel. NULL for every wheel win,
        // guest or account. Same convention as table_access_codes.issued_by.
        'issued_by',
        'voucher_id',
        // Unique, single-use code that identifies THIS claim on its own.
        // Carried by every bearer claim: guest wheel wins, account wheel wins
        // (since 2026-09-01), and admin-issued counter codes.
        'claim_code',
        'acquired_date',
        'valid_from',
        'is_used',
        'used_at',
    ];

    protected $casts = [
        'acquired_date' => 'date',
        'valid_from'    => 'date',
        'is_used'       => 'boolean',
        'used_at'       => 'datetime',
    ];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A claim with no account behind it, held by whoever knows its claim code.
     * Won on the wheel by a guest; see App\Services\VoucherClaims::mintForGuest().
     */
    public function isGuestClaim(): bool
    {
        return $this->user_id === null;
    }

    /*
    |--------------------------------------------------------------------------
    | WHAT THE CUSTOMER IS TOLD ABOUT THIS CLAIM
    |--------------------------------------------------------------------------
    |
    | The Vouchers page used to work this out for itself, from the wrong
    | columns, and so disagreed with the server: a claim whose personal window
    | opened tomorrow was shown as "Valid now" with a Copy button, and the cart
    | then refused it with a message the card gave no hint of. A voucher shown
    | as yours that can never be spent is broken from the customer's side even
    | though every individual rule is behaving.
    |
    | These two methods are the only thing the page is allowed to ask. Both
    | delegate to Voucher::availabilityErrorFor(), which is also what checkout
    | runs, so the badge, the Copy button and the server can no longer drift.
    |
    */

    /**
     * Why this specific claim cannot be spent right now, or null when it can.
     * The string is the exact one the cart would show, so the card can state
     * the real reason instead of a generic line.
     */
    public function blockingReason(): ?string
    {
        $voucher = $this->voucher;

        if (!$voucher) {
            // The voucher row is gone (deleted rather than deactivated). The
            // claim cannot be spent and nothing can make it spendable.
            return 'This voucher is no longer available.';
        }

        return $voucher->availabilityErrorFor($this->user, $this);
    }

    /**
     * A short machine-readable state for styling: ready | used | expired |
     * not_yet_valid | unavailable.
     */
    public function statusKey(): string
    {
        if ($this->is_used) {
            return 'used';
        }

        $reason = $this->blockingReason();

        if ($reason === null) {
            return 'ready';
        }

        $voucher = $this->voucher;

        if ($voucher && $voucher->expires_at && $voucher->expires_at->isPast()) {
            return 'expired';
        }

        if ($this->valid_from && today()->lessThan($this->valid_from)) {
            return 'not_yet_valid';
        }

        return 'unavailable';
    }

    /** The badge wording that goes with statusKey(). */
    public function statusLabel(): string
    {
        return match ($this->statusKey()) {
            'ready'         => 'Ready to use',
            'used'          => 'Used',
            'expired'       => 'Expired',
            'not_yet_valid' => 'Not yet valid',
            default         => 'Unavailable',
        };
    }
}

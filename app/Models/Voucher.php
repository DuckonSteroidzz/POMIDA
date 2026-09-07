<?php

namespace App\Models;

use App\Services\VoucherClaims;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'description',
        'discount_type',
        'discount_value',
        'max_uses',
        'used_count',
        'minimum_order',
        'expires_at',
        'valid_from',
        'is_active',
        'points_required',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'minimum_order'  => 'decimal:2',
        'expires_at'     => 'datetime',
        'valid_from'     => 'date',
        'is_active'      => 'boolean',
    ];

    public function isValid(): bool
    {
        if (!$this->is_active) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        if ($this->max_uses > 0 && $this->used_count >= $this->max_uses) return false;
        return true;
    }

    // Bagong method — check kung pwede na gamitin ngayon
    public function isRedeemableToday(): bool
    {
        if (!$this->isValid()) return false;
        if (!$this->valid_from) return true;
        // Hindi pwede gamitin sa araw na nakuha (valid_from = tomorrow or later)
        return today()->greaterThanOrEqualTo($this->valid_from);
    }

    /*
    |--------------------------------------------------------------------------
    | SINGLE SOURCE OF TRUTH FOR REDEMPTION
    |--------------------------------------------------------------------------
    |
    | Every rule that decides whether a voucher may be redeemed lives here, and
    | ONLY here. Both the cart page (AuthController::applyVoucher, which only
    | previews the discount) and checkout (OrderController::placeOrder, which
    | actually charges the customer) call these methods.
    |
    | This matters: the two used to duplicate the rules, drifted apart, and the
    | cart ended up rejecting vouchers that checkout happily accepted — a
    | customer could bypass the minimum-order rule entirely by posting the form
    | directly. Any NEW rule must be added here so both paths get it at once.
    |
    */

    /**
     * Why this voucher cannot be redeemed, or null when it can be.
     *
     * @param  User|null  $user      The customer redeeming it (null = guest).
     * @param  float      $subtotal  Order subtotal, add-on options included.
     */
    public function redemptionErrorFor(?User $user, float $subtotal, ?UserVoucher $claim = null): ?string
    {
        // Everything that does not depend on what is in the cart. Split out so
        // the customer's Vouchers page can ask the SAME question about a
        // voucher it is about to present as usable — see availabilityErrorFor().
        //
        // $claim is the specific row a typed CLAIM CODE named, when that is how
        // the customer identified the voucher (a guest redeeming a prize they
        // won on the wheel). Passing it through means the preview and the
        // charge judge, and then spend, the very same row.
        $error = $this->availabilityErrorFor($user, $claim);

        if ($error !== null) {
            return $error;
        }

        if ($subtotal < (float) $this->minimum_order) {
            return 'Minimum order of ₱' . number_format($this->minimum_order, 2) . ' required.';
        }

        return null;
    }

    /**
     * Why a NEW claim cannot be issued on this voucher right now, or null.
     *
     * This is the issuance question, which is not the same as the redemption
     * question. availabilityErrorFor() asks "may this holder spend this claim",
     * and for a wheel voucher it needs a claim to reason about — asking it with
     * no claim and no user returns "Please sign in to use this voucher...",
     * which is a redemption message and nonsense for an admin minting a code.
     *
     * WHAT THIS MIRRORS
     * -----------------
     * The wheel's own issuability rule, in AuthController::winnableVoucherFor():
     *
     *     ->where('is_active', true)
     *     ->where(fn => whereNull('expires_at')->orWhere('expires_at','>',now()))
     *     ->where(fn => where('max_uses','<=',0)->orWhereColumn('used_count','<','max_uses'))
     *
     * Same three conditions, same meanings, so an admin cannot hand out a code
     * for a voucher the wheel would refuse to award — in particular, the
     * max_uses cap is the SAME counter and is checked the same way, so this
     * path cannot be used to exceed a limit a wheel win would be bound by.
     *
     * ONE CONDITION STRICTER THAN THE WHEEL, DELIBERATELY
     * ---------------------------------------------------
     * valid_from is also refused here. The wheel does not check it, because a
     * wheel claim carries its OWN valid_from and the shared column is a launch
     * date rather than a per-holder window (see the long note in
     * availabilityErrorFor). But an admin handing a code to a customer standing
     * at the counter should not be handing out something from a promotion that
     * has not opened yet. Stricter is safe here; it can only ever refuse an
     * issuance the wheel would have allowed, never permit one it would refuse.
     */
    public function issuanceErrorFor(): ?string
    {
        if (! $this->is_active) {
            return 'This voucher is inactive, so no new codes can be issued for it.';
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'This voucher has expired, so no new codes can be issued for it.';
        }

        if ($this->max_uses > 0 && $this->used_count >= $this->max_uses) {
            return 'This voucher has reached its usage limit ('
                . $this->used_count . '/' . $this->max_uses
                . '), so no new codes can be issued for it.';
        }

        if ($this->valid_from && today()->lessThan($this->valid_from)) {
            return 'This voucher does not open until '
                . $this->valid_from->format('M d, Y')
                . ', so no new codes can be issued for it yet.';
        }

        return null;
    }

    /**
     * Why this voucher cannot be redeemed AT ALL right now, ignoring the cart.
     *
     * THE BUG THIS EXISTS FOR
     * -----------------------
     * The Vouchers page decided for itself whether a claim was usable, and it
     * decided it differently from the server. It read $voucher->valid_from —
     * the SHARED column on the voucher row — while redemptionErrorFor() reads
     * the customer's own user_vouchers.valid_from, which is where item 41 moved
     * the per-customer window. It also never looked at is_active, expires_at or
     * max_uses at all. So a claim whose personal window opens tomorrow was
     * shown with a green "Valid now" badge and a Copy button, and the cart then
     * refused it — a voucher sitting in the account that the system will always
     * say no to, which is exactly the report. Both sides now call this, so they
     * cannot disagree.
     *
     * $claim lets a caller ask about one SPECIFIC claim row (the Vouchers page
     * asks per card). Left null, the holder's spendable claim is resolved the
     * same way checkout resolves it.
     *
     * The item-30 rule is untouched: a wheel voucher still requires a genuine
     * unused claim, and nothing here can be used to redeem one without it.
     */
    public function availabilityErrorFor(?User $user, ?UserVoucher $claim = null): ?string
    {
        if (!$this->is_active) {
            return 'This voucher is no longer available.';
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'This voucher has expired.';
        }

        if ($this->max_uses > 0 && $this->used_count >= $this->max_uses) {
            return 'This voucher has reached its usage limit.';
        }

        /*
         * Vouchers with points_required are WON on the game wheel — they are
         * not public promo codes. Knowing the code is not enough; the
         * customer must hold an unused user_vouchers row for it. A voucher
         * with points_required = 0 is a normal public code and skips this
         * whole branch, including its valid_from check further down.
         *
         * The valid_from check for a wheel-won voucher MUST read the
         * customer's own user_vouchers.valid_from, not $this->valid_from —
         * that shared column is a single value on ONE voucher row that many
         * different customers each hold their own claim on. Reading it here
         * used to mean the most recent winner's "starts tomorrow" date
         * silently became every earlier winner's redemption window too,
         * with zero action from them. Reproduced live with two real customer
         * accounts before this fix: winner A's effective valid_from visibly
         * shifted the instant winner B won the same voucher code.
         */
        if ((int) $this->points_required > 0) {
            /*
             * A claim row must belong to THIS voucher. Without this guard a
             * caller could hand over a valid claim on a cheap prize while
             * naming an expensive voucher, and the checks below would all pass.
             */
            if ($claim !== null && (int) $claim->voucher_id !== (int) $this->id) {
                return 'This voucher can only be used if you won it from the game.';
            }

            /*
             * A CLAIM IS A BEARER INSTRUMENT. Changed 2026-09-01.
             *
             * This used to refuse an owned claim presented by anyone but its
             * owner ("This voucher belongs to another account."). That made the
             * Dine-In path impossible: a Dine-In customer never creates an
             * account — a decision already defended to the panel — so a prize
             * could never reach them, and the system claimed a capability it
             * did not have.
             *
             * Now: one win mints one claim, and that claim's code is redeemable
             * exactly ONCE by whoever presents it. The winner can hand it to a
             * friend at the table, or staff can write it down.
             *
             * WHY THIS DOES NOT REOPEN THE ITEM-30 BYPASS
             * -------------------------------------------
             * Item 30 closed a hole where knowing a voucher's SHARED PUBLIC
             * code was enough to take money off without ever playing. That is
             * untouched, and it is a different code:
             *
             *   - a shared code still resolves $claim === null and still falls
             *     into the branch below, which still refuses a guest outright
             *     and still requires a signed-in customer to actually hold a
             *     claim;
             *   - a claim code names ONE row, minted only by a real winning
             *     spin — or, since 2026-09-01, by an admin issuing one at the
             *     counter, which is gated by issuanceErrorFor() on exactly the
             *     conditions the wheel checks, including the shared max_uses
             *     cap, and is not a path any customer can reach — random over
             *     27^10, behind a per-session and per-IP throttle;
             *   - that row is marked used inside the checkout transaction with
             *     a conditional UPDATE (see OrderController::placeOrder), so it
             *     is spendable exactly once no matter who races for it.
             *
             * So possession of a claim code grants exactly the single prize
             * that was genuinely won, and nothing else. What was lost is
             * non-transferability, which was the thing being deliberately
             * changed — not a protection against a separate abuse.
             *
             * The ownership column is still meaningful and still used: it is
             * what puts the claim on the winner's own Vouchers page and what
             * enforces the one-wheel-voucher-per-account cap. It is simply no
             * longer a gate on redemption.
             */

            if ($claim === null) {
                if (!$user) {
                    /*
                     * A guest typed the SHARED public code. Still refused —
                     * this is precisely the money bypass item 30 closed, and it
                     * stays closed. A guest who genuinely won gets a unique
                     * single-use CLAIM code instead, which arrives here as an
                     * explicit $claim and never reaches this branch.
                     */
                    return 'Please sign in to use this voucher, or enter the claim code you won on the wheel.';
                }

                if (VoucherClaims::heldOn($this, $user)->isEmpty()) {
                    return 'This voucher can only be used if you won it from the game.';
                }

                // The SAME row unusedClaimFor() will hand placeOrder() to mark
                // used — checking a different row here would let the validity
                // check and the actual redemption disagree about which claim is
                // being spent.
                $claim = $this->unusedClaimFor($user);

                if (!$claim) {
                    return 'You have already used this voucher.';
                }
            } elseif ($claim->is_used) {
                // A caller asking about one specific claim row it is already
                // holding — the Vouchers page, per card.
                return 'You have already used this voucher.';
            }

            if ($claim->valid_from && today()->lessThan($claim->valid_from)) {
                return 'Voucher is not yet valid. Available starting '
                    . $claim->valid_from->format('M d, Y') . '.';
            }
        } else {
            // Public promo code: one shared window really does apply to
            // every customer, so the shared column is correct here.
            if ($this->valid_from && today()->lessThan($this->valid_from)) {
                return 'Voucher is not yet valid. Available starting '
                    . $this->valid_from->format('M d, Y') . '.';
            }
        }

        return null;
    }

    /**
     * The customer's oldest still-unused claim on this voucher, if any.
     * placeOrder() marks the returned row used inside the order transaction.
     */
    public function unusedClaimFor(?User $user): ?UserVoucher
    {
        if (!$user || (int) $this->points_required <= 0) {
            return null;
        }

        return VoucherClaims::unusedOn($this, $user);
    }

    /**
     * Discount for this subtotal, already rounded to centavos and floored so it
     * can never exceed the subtotal (the total must never go negative).
     *
     * Rounding here — once — is what keeps `subtotal - discount = total` exact.
     * Previously the discount and the final total were each derived from the
     * raw unrounded figure and rounded independently, which drifted by a
     * centavo whenever the raw discount landed on a half-centavo.
     */
    public function discountFor(float $subtotal): float
    {
        $discount = $this->discount_type === 'percent'
            ? $subtotal * ((float) $this->discount_value / 100)
            : (float) $this->discount_value;

        return round(min($discount, $subtotal), 2);
    }
}

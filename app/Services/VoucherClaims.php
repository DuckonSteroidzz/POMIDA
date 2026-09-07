<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * VoucherClaims — the one place that answers "which user_vouchers rows does the
 * CURRENT holder have on this voucher?".
 *
 * Why this is its own class
 * -------------------------
 * A wheel-won voucher (points_required > 0) is not a public code: item 30 made
 * holding an unused user_vouchers claim the condition for redeeming it, which
 * closed a real bypass where knowing the code was enough to take the money off.
 * That rule is untouched here. What changed is that the LOOKUP behind it was
 * written inline in three places as
 *
 *     UserVoucher::where('user_id', $user->id)->where('voucher_id', $v->id)
 *
 * which hard-codes "a claim belongs to an account". Round 3B will let a GUEST —
 * who has no account at all — hold a claim they won and redeem it later with a
 * single-use claim code, i.e. a row whose user_id is NULL. Every caller now asks
 * this class instead, so adding that second way of resolving the current
 * holder's claims is a change to holderScope() alone rather than a hunt through
 * the models and controllers.
 *
 * The scope of a claim is deliberately unchanged: this only resolves rows. What
 * a resolved row permits is still decided by Voucher::availabilityErrorFor().
 */
class VoucherClaims
{
    /**
     * Narrow a user_vouchers query to the claims the given holder may spend.
     *
     * Today the only kind of holder is a signed-in account. A null holder
     * matches nothing — NOT "every claim" — so a guest can never fall through
     * into someone else's rows. Round 3B adds the ownerless case here.
     */
    public static function holderScope(Builder $query, ?User $user): Builder
    {
        if (!$user) {
            // No account: no claims resolvable yet. whereRaw('0 = 1') rather
            // than an early return keeps this composable with the callers'
            // other constraints.
            return $query->whereRaw('0 = 1');
        }

        return $query->where('user_id', $user->id);
    }

    /**
     * Every claim this holder has on this voucher, used or not, oldest first.
     *
     * @return Collection<int, UserVoucher>
     */
    public static function heldOn(Voucher $voucher, ?User $user): Collection
    {
        return self::holderScope(
            UserVoucher::query()->where('voucher_id', $voucher->id),
            $user
        )->orderBy('id')->get();
    }

    /**
     * The holder's oldest still-unused claim on this voucher, if any.
     *
     * This is the row that will actually be spent at checkout, so the validity
     * check and the redemption must both be made against THIS row — checking a
     * different one would let them disagree about which claim is in play.
     */
    public static function unusedOn(Voucher $voucher, ?User $user): ?UserVoucher
    {
        return self::holderScope(
            UserVoucher::query()
                ->where('voucher_id', $voucher->id)
                ->where('is_used', false),
            $user
        )->orderBy('id')->first();
    }

    /*
    |--------------------------------------------------------------------------
    | OWNERLESS (GUEST) CLAIMS
    |--------------------------------------------------------------------------
    |
    | A guest has no account, so there is no user_id to look a claim up by.
    | Instead the claim carries its own unique, single-use CLAIM CODE, and
    | possession of that code is the proof of having won.
    |
    | Why this does not reopen the item-30 bypass
    | -------------------------------------------
    | Item 30 closed a hole where knowing a voucher's SHARED public code was
    | enough to redeem it without ever playing. A claim code is a different
    | thing in every way that matters:
    |
    |   - It is minted only by an actual winning spin, one per win — or, since
    |     2026-09-01, by an ADMIN issuing one at the counter for a walk-in
    |     customer (mintForCounter()). That second source is a deliberate,
    |     authorised act by a privileged user, and it is bound by exactly the
    |     same issuability rules a wheel win is: Voucher::issuanceErrorFor()
    |     mirrors the wheel's own filter, including the shared max_uses cap, so
    |     it cannot be used to conjure supply the wheel would not have awarded.
    |     It is never a path a customer can reach.
    |   - It is random over a 32^10 space, so it cannot be guessed, and the
    |     apply-voucher endpoint is throttled per session and per IP on top.
    |   - It names ONE claim row, and that row is marked used at checkout, so
    |     it is spendable exactly once. Knowing it grants nothing beyond the
    |     single prize actually won.
    |
    | The shared public code keeps behaving exactly as item 30 left it: typing
    | it still fails for anyone without a genuine claim.
    |
    | Deliberately NOT resolvable by session. A guest's claims are found only
    | by code, never by "which claims did this browser mint", because a shared
    | or borrowed phone would otherwise hand the next visitor someone else's
    | prize. The session list in App\Support\GuestVoucherClaims exists purely
    | to re-show the customer their own code during the visit and to cap how
    | many they can hold — it is never an authority on redemption.
    */

    /** Prefix on every minted claim code, so a customer can recognise one. */
    public const CODE_PREFIX = 'PCH';

    /**
     * Alphabet for generated codes. Deliberately excludes the characters that
     * are misread when a code is copied off a screen by hand — 0/O, 1/I/L,
     * 5/S, 8/B — because a guest is expected to write this down.
     */
    private const CODE_ALPHABET = '234679ACDEFGHJKMNPQRTUVWXYZ';

    /**
     * Mint an ownerless claim on this voucher and return it.
     *
     * The per-customer validity window is set the same way the account path
     * sets it in AuthController::addPoints() — from tomorrow — so a guest and
     * a signed-in winner are held to exactly the same rule. Item 41 put that
     * window on the claim row precisely so it could differ per holder; nothing
     * here reads the shared vouchers.valid_from column.
     */
    public static function mintForGuest(Voucher $voucher, ?string $validFrom = null, ?int $issuedBy = null): UserVoucher
    {
        return UserVoucher::create([
            'user_id'       => null,
            'issued_by'     => $issuedBy,
            'voucher_id'    => $voucher->id,
            'claim_code'    => self::generateCode(),
            'acquired_date' => today()->toDateString(),
            'valid_from'    => $validFrom ?? now()->addDay()->toDateString(),
            'is_used'       => false,
        ]);
    }

    /**
     * Mint a bearer claim that an ADMIN is handing to a walk-in customer.
     *
     * Deliberately the same minting path as a guest wheel win — same method,
     * same row shape, same code generator — rather than a second parallel
     * implementation that could drift. What differs is only:
     *
     *   - issued_by records the admin, so "who handed this out" is a query
     *     rather than a guess (same convention as table_access_codes);
     *   - valid_from is TODAY, not tomorrow. See below.
     *
     * WHY THIS ONE IS USABLE IMMEDIATELY, AND A WHEEL WIN IS NOT
     * ----------------------------------------------------------
     * A wheel claim opens the next day. That window exists to stop a
     * self-service loop — play, win, redeem, repeat — where the customer
     * controls both ends. An admin issuing a code at the counter is not that
     * loop: a person with authority has deliberately decided to grant it, and
     * the customer is standing there. Making them come back tomorrow would
     * make the feature useless for the case it was asked for.
     *
     * This is a widening of WHEN a claim opens, not of WHETHER one may exist.
     * Every gate on existence is unchanged and is checked before this is
     * called — see Voucher::issuanceErrorFor(), which mirrors the wheel's own
     * issuability rule including the shared max_uses cap.
     */
    public static function mintForCounter(Voucher $voucher, int $issuedBy): UserVoucher
    {
        return self::mintForGuest($voucher, today()->toDateString(), $issuedBy);
    }

    /**
     * The claim a typed code names, or null.
     *
     * Matching is case- and separator-insensitive because this code is read off
     * a phone screen and typed back in on a later visit, possibly on another
     * device — "pch 2c4k9 twx7d" must find the same row as "PCH-2C4K9-TWX7D".
     */
    public static function resolveByCode(?string $code): ?UserVoucher
    {
        $normalised = self::normaliseCode($code);

        if ($normalised === '') {
            return null;
        }

        // claim_code is STORED in this same normalised form, so the lookup is a
        // plain indexed equality rather than a function over the column. The
        // dashes exist only in display() below.
        return UserVoucher::with('voucher')
            ->where('claim_code', $normalised)
            ->first();
    }

    /**
     * The customer-facing spelling of a claim code: PCH-XXXXX-XXXXX.
     * Grouping makes it far easier to read off a screen and copy by hand.
     */
    public static function display(?string $stored): string
    {
        $code = self::normaliseCode($stored);

        if (strlen($code) !== 13) {
            return $code;
        }

        return substr($code, 0, 3) . '-' . substr($code, 3, 5) . '-' . substr($code, 8, 5);
    }

    /** Strip spaces and dashes and upper-case, for tolerant matching. */
    public static function normaliseCode(?string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    /** Does this look like a claim code rather than a shared voucher code? */
    public static function looksLikeClaimCode(?string $code): bool
    {
        return str_starts_with(self::normaliseCode($code), self::CODE_PREFIX);
    }

    /**
     * Work out what a customer actually typed into the voucher field.
     *
     * There is now one input box and two kinds of code behind it:
     *
     *   - a SHARED voucher code (public promo, or the public face of a wheel
     *     voucher) -> resolves a Voucher and no claim, exactly as before;
     *   - a CLAIM code -> resolves both the voucher AND the one specific claim
     *     row being spent.
     *
     * The claim matters, not just the voucher: the validity window (item 41)
     * and the used-marking at checkout (item 30) both act on a particular row,
     * and if the preview and the charge picked different rows they could
     * disagree about whether the voucher is spendable. Returning the pair from
     * ONE resolver, called by both AuthController::applyVoucher() and
     * OrderController::placeOrder(), is what stops that — the same reason
     * Voucher::redemptionErrorFor() is shared between them.
     *
     * A shared code is tried first so nothing about the existing path changes.
     *
     * @return array{voucher: ?Voucher, claim: ?UserVoucher}
     */
    public static function resolveTypedCode(?string $typed): array
    {
        $typed = trim((string) $typed);

        if ($typed === '') {
            return ['voucher' => null, 'claim' => null];
        }

        $voucher = Voucher::where('code', strtoupper($typed))->first();

        if ($voucher) {
            return ['voucher' => $voucher, 'claim' => null];
        }

        $claim = self::resolveByCode($typed);

        if ($claim && $claim->voucher) {
            return ['voucher' => $claim->voucher, 'claim' => $claim];
        }

        return ['voucher' => null, 'claim' => null];
    }

    /*
    |--------------------------------------------------------------------------
    | SPENDING A REDEMPTION
    |--------------------------------------------------------------------------
    */

    /**
     * Consume a voucher redemption: count it against the shared cap and burn
     * the specific claim row, if there is one.
     *
     * WHY THIS IS A METHOD AND NOT INLINE ANY MORE
     * --------------------------------------------
     * These two writes lived inline inside OrderController::placeOrder()'s order
     * transaction. The admin Manual Order flow (2026-09-03) needs exactly the
     * same pair, so that a code staff key in at the counter is spent the same
     * way a customer spending it online is, and so a code can never be spent
     * twice across the two paths. A second copy is precisely how the cart
     * preview and redemptionErrorFor() drifted apart before — one side gets
     * fixed and the other is left behind — so the writes moved here and BOTH
     * callers now use this. The behaviour is exactly what placeOrder() already
     * did; nothing about the customer path changed.
     *
     * MUST be called inside the same transaction that creates the order, so a
     * failure anywhere below leaves the voucher unspent.
     *
     * The conditional UPDATE ("...where is_used = false") is what makes this
     * safe against two checkouts racing on the same claim: only the first
     * matches a row, and the second affects nothing rather than double-spending
     * it. A LOSING voucher (see Order::voucherBeatsCard) must never reach here —
     * its caller passes nulls, which is how the loser is left completely
     * unspent.
     *
     * @param  int|null         $voucherId  The voucher actually applied, or null.
     * @param  UserVoucher|null $claim      The specific claim row being spent.
     *
     * @throws \RuntimeException when the claim was already used — deliberate,
     *         and its message is written to be shown to whoever redeemed it.
     */
    public static function redeem(?int $voucherId, ?UserVoucher $claim): void
    {
        /*
         * Increment by ID, not by the typed string. A typed CLAIM code matches
         * no row in `vouchers`, so a where('code', ...) would silently increment
         * nothing and the redemption would not count against max_uses at all.
         */
        if ($voucherId) {
            Voucher::whereKey($voucherId)->increment('used_count');
        }

        if ($claim) {
            $claimed = UserVoucher::where('id', $claim->id)
                ->where('is_used', false)
                ->update([
                    'is_used'    => true,
                    'used_at'    => now(),
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                throw new \RuntimeException('This voucher has already been used.');
            }
        }
    }

    /**
     * Mint a fresh claim code for a caller creating its own UserVoucher row.
     *
     * The account path (AuthController::addPoints) builds its own row rather
     * than going through mintForGuest(), because an account claim carries a
     * user_id. It still needs a code, so the generator is exposed here rather
     * than duplicated — one definition of what a claim code looks like.
     */
    public static function mintCode(): string
    {
        return self::generateCode();
    }

    /**
     * A random code that is not already in use.
     *
     * The unique index on claim_code is the real guarantee; this loop just
     * keeps an astronomically unlikely collision from surfacing as a 500.
     */
    private static function generateCode(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $body = '';

            for ($i = 0; $i < 10; $i++) {
                $body .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }

            // Stored without separators; display() adds them back.
            $code = self::CODE_PREFIX . $body;

            if (!UserVoucher::where('claim_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate a unique voucher claim code.');
    }
}

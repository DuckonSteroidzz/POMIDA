<?php

namespace App\Support;

use App\Models\UserVoucher;
use App\Services\VoucherClaims;
use Illuminate\Support\Collection;

/**
 * The voucher claims a GUEST won during the current browsing session.
 *
 * WHAT THIS IS FOR — AND WHAT IT IS NOT
 * -------------------------------------
 * A guest's claim is redeemed by its unique claim code and by nothing else
 * (see App\Services\VoucherClaims). This session list is deliberately NOT an
 * authority on redemption: it exists only so that, during the visit in which
 * the prize was won, the app can
 *
 *   - show the customer the code again without them having to have written it
 *     down the instant it appeared,
 *   - cap how many unused prizes one guest may accumulate, mirroring the
 *     two-voucher ceiling the signed-in path applies, and
 *   - hand the claims over to a real account if the guest registers or signs
 *     in before leaving.
 *
 * Treating it as proof of ownership would be wrong: a café phone or a borrowed
 * device is one browser session shared by many people, and the next visitor
 * must not inherit the last one's prize. The code is what proves the win, and
 * the code is what the customer keeps.
 *
 * Modelled on App\Support\GuestOrders, which solves the same shape of problem
 * for orders.
 */
class GuestVoucherClaims
{
    /** Session key holding the claim ids won in this session. */
    public const KEY = 'guest_voucher_claim_ids';

    /**
     * How many UNUSED prizes one guest session may hold at once. Same ceiling
     * the signed-in path applies in AuthController::addPoints(), so switching
     * between guest and account play cannot be used to hoard more.
     */
    public const MAX_UNUSED = 2;

    /** Record a claim this session just won. */
    public static function remember(UserVoucher $claim): void
    {
        $ids = self::ids();
        $ids[] = $claim->id;

        session()->put(self::KEY, array_values(array_unique($ids)));
    }

    /** @return int[] */
    public static function ids(): array
    {
        $ids = session(self::KEY, []);

        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(
            array_map('intval', array_filter($ids, 'is_numeric'))
        ));
    }

    /**
     * The claim rows themselves, newest first.
     *
     * Re-read from the database every time rather than cached in the session:
     * a claim can be spent from another device between page loads, and a stale
     * "unused" copy would let this session mint past the ceiling.
     *
     * @return Collection<int, UserVoucher>
     */
    public static function all(): Collection
    {
        $ids = self::ids();

        if (!$ids) {
            return collect();
        }

        return UserVoucher::with('voucher')
            ->whereIn('id', $ids)
            // Ownerless only. If a claim has since been adopted by an account
            // (see adoptInto()), it is that account's business now.
            ->whereNull('user_id')
            ->orderByDesc('id')
            ->get();
    }

    /** Unused prizes this session is holding right now. */
    public static function unusedCount(): int
    {
        return self::all()->where('is_used', false)->count();
    }

    /** Has this session already won a claim on the given voucher? */
    public static function holdsVoucher(int $voucherId): bool
    {
        return self::all()->contains(fn (UserVoucher $c) => (int) $c->voucher_id === $voucherId);
    }

    /**
     * Hand this session's ownerless claims to an account that has just signed
     * in or registered in the same browser.
     *
     * WHY THIS EXISTS
     * ---------------
     * "I won this as a guest, then made an account" is an ordinary sequence,
     * and without this the prize would only ever be reachable by re-typing the
     * code — which works, but is a poor experience and looks like the prize was
     * lost. Adoption is best-effort by design:
     *
     *   - a claim already spent is left alone, so nothing can be revived;
     *   - a claim on a voucher the account ALREADY holds is left ownerless,
     *     because user_vouchers carries UNIQUE(user_id, voucher_id) and the
     *     one-wheel-voucher-per-account rule must survive this. The claim is
     *     not lost: its code still redeems it.
     *
     * The claim_code is deliberately kept after adoption, so a code the
     * customer wrote down before registering does not stop working.
     *
     * @return int how many claims were adopted
     */
    public static function adoptInto(int $userId): int
    {
        $adopted = 0;

        foreach (self::all() as $claim) {
            if ($claim->is_used) {
                continue;
            }

            $alreadyHeld = UserVoucher::where('user_id', $userId)
                ->where('voucher_id', $claim->voucher_id)
                ->exists();

            if ($alreadyHeld) {
                continue;
            }

            $claim->forceFill(['user_id' => $userId])->save();
            $adopted++;
        }

        return $adopted;
    }

    /** Everything this session holds, shaped for the game page and the cart. */
    public static function forDisplay(): array
    {
        return self::all()->map(fn (UserVoucher $c) => [
            'claim_code'  => VoucherClaims::display($c->claim_code),
            'code'        => $c->voucher?->code,
            'description' => $c->voucher?->description,
            'valid_from'  => $c->valid_from?->format('M d, Y'),
            'expires_at'  => $c->voucher?->expires_at?->format('M d, Y'),
            'is_used'     => (bool) $c->is_used,
        ])->values()->all();
    }
}

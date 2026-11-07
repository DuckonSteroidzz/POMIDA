<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use App\Support\GuestVoucherClaims;
use App\Support\ShopDay;
use App\Support\WheelPrizes;
use Illuminate\Support\Facades\DB;

/**
 * Trading Spin & Win points for a voucher (QA, Nov 5 2026).
 *
 * Until now a prize was handed out by the spin itself, the moment the balance
 * covered one. A customer who already had plenty of points therefore had to
 * place an order and spin again to get a promo the owner added later. Spinning
 * now only earns points; this is the one place points become a voucher, when
 * the customer presses Redeem.
 *
 * Every rule a customer is shown lives in App\Support\WheelPrizes, and this
 * re-checks it on the server against locked rows, so the list and the redeem
 * can never disagree and a crafted request gets nothing the list would refuse.
 *
 * DOUBLE CLICKS AND TWO TABS
 * --------------------------
 * - An account redeems inside one transaction that first locks the customer's
 *   users row. A second redeem (another tab, another phone, a double click)
 *   waits on that lock, then re-reads the balance and the claims the first one
 *   committed, and is refused if the points are gone or the voucher is held.
 *   UNIQUE(user_id, voucher_id) on user_vouchers backs the "once per customer"
 *   rule at the database level as well.
 * - A guest's balance lives in the session, which no row lock can protect. The
 *   redeem and add-points routes are session-blocking (->block() in
 *   routes/web.php), so two requests from one guest session run one after the
 *   other and the second reads what the first saved.
 *
 * The voucher row is locked too, so an owner switching it off or editing its
 * points in the same instant is seen either wholly before or wholly after.
 * Lock order is user, then voucher. Checkout never locks a users row, so the
 * two cannot deadlock.
 */
final class PointsRedeem
{
    /**
     * @return array{voucher: Voucher, claim: UserVoucher, points_spent: int, total_points: int}
     *
     * @throws PointsRedeemRefused
     */
    public static function forAccount(int $userId, int $voucherId, ?int $branchId): array
    {
        return DB::transaction(function () use ($userId, $voucherId, $branchId) {
            $user = User::whereKey($userId)->lockForUpdate()->first(['id', 'points', 'bonus_points']);
            $voucher = Voucher::whereKey($voucherId)->lockForUpdate()->first();

            if (! $user) {
                throw new PointsRedeemRefused(WheelPrizes::NOT_OFFERED, 'Please sign in again.');
            }

            $claims = UserVoucher::with('voucher')->where('user_id', $userId)->get();
            $spin = max(0, (int) $user->points);
            $bonus = max(0, (int) $user->bonus_points);

            self::assertRedeemable($voucher, $spin + $bonus, $claims, $branchId);

            // Spin points first, then bonus points: the same order the wheel
            // used when it awarded the prize itself.
            $cost = (int) $voucher->points_required;
            $fromSpin = min($spin, $cost);
            $fromBonus = $cost - $fromSpin;

            // Safe to write absolute values: the row is locked, so every other
            // writer (a spin's increment, a bonus credit) waits for this commit.
            User::whereKey($userId)->update([
                'points'       => $spin - $fromSpin,
                'bonus_points' => $bonus - $fromBonus,
            ]);

            // Exactly the claim a wheel win used to create: its own transferable
            // claim code, valid from the shop's today.
            $claim = UserVoucher::create([
                'user_id'       => $userId,
                'voucher_id'    => $voucher->id,
                'claim_code'    => VoucherClaims::mintCode(),
                'acquired_date' => ShopDay::todayString(),
                'valid_from'    => ShopDay::todayString(),
                'is_used'       => false,
            ]);

            return [
                'voucher'      => $voucher,
                'claim'        => $claim,
                'points_spent' => $cost,
                'total_points' => $spin + $bonus - $cost,
            ];
        });
    }

    /**
     * A guest pays from session('guest_points') and gets an ownerless claim,
     * exactly as a guest's wheel win did. The caller's route is session-blocking.
     *
     * @return array{voucher: Voucher, claim: UserVoucher, points_spent: int, total_points: int}
     *
     * @throws PointsRedeemRefused
     */
    public static function forGuest(int $voucherId, ?int $branchId): array
    {
        $points = max(0, (int) session('guest_points', 0));

        $result = DB::transaction(function () use ($voucherId, $branchId, $points) {
            $voucher = Voucher::whereKey($voucherId)->lockForUpdate()->first();

            self::assertRedeemable($voucher, $points, GuestVoucherClaims::all(), $branchId);

            return [
                'voucher'      => $voucher,
                'claim'        => VoucherClaims::mintForGuest($voucher),
                'points_spent' => (int) $voucher->points_required,
                'total_points' => $points - (int) $voucher->points_required,
            ];
        });

        // Only once the claim is committed, so a refused or failed redeem
        // leaves the session balance as it was.
        session()->put('guest_points', $result['total_points']);
        GuestVoucherClaims::remember($result['claim']);

        return $result;
    }

    /**
     * WheelPrizes' own verdict, refused unless it reads "Ready to redeem".
     *
     * @param  \Illuminate\Support\Collection<int, UserVoucher>  $claims
     */
    private static function assertRedeemable(?Voucher $voucher, int $points, $claims, ?int $branchId): void
    {
        if (! $voucher || ! WheelPrizes::isOffered($voucher)) {
            throw new PointsRedeemRefused(WheelPrizes::NOT_OFFERED, "This voucher can't be redeemed with points right now.");
        }

        [$status] = WheelPrizes::statusFor(
            $voucher,
            $points,
            $claims->pluck('voucher_id')->map('intval')->all(),
            WheelPrizes::spendable($claims),
            $branchId
        );

        if ($status !== WheelPrizes::REDEEMABLE) {
            throw new PointsRedeemRefused($status, WheelPrizes::refusalFor($status, $voucher, $points));
        }
    }
}

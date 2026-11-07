<?php

namespace App\Support;

use App\Models\UserVoucher;
use App\Models\Voucher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The Spin & Win points rewards and what each one means FOR THIS VISITOR.
 *
 * ONE RULE (QA, Nov 2 2026). The game page's prize list and the picker that
 * awarded a prize used to be two separate queries, and they drifted. Both
 * read evaluate() from then on.
 *
 * REDEEM, NOT WIN (QA, Nov 5 2026). A spin no longer awards anything; it only
 * earns points. The customer trades points for a voucher with the Redeem
 * button (App\Services\PointsRedeem), which re-checks statusFor() against
 * locked rows. So "Ready to redeem" on the list is exactly what the server
 * accepts.
 *
 * A reward is listed when the owner has it on offer (isOffered): active, a
 * points reward (on_wheel), points_required > 0, not expired. Inactive,
 * switched-off and expired vouchers stay invisible to customers. Everything
 * else a customer can be told about is a status below.
 */
final class WheelPrizes
{
    public const REDEEMABLE = 'redeemable';
    public const ALREADY_WON = 'already_won';
    public const NEED_POINTS = 'need_points';
    public const OTHER_BRANCH = 'other_branch';
    public const NOT_STARTED = 'not_started';
    public const SOLD_OUT = 'sold_out';
    public const HOLDING_MAX = 'holding_max';

    /** Not on offer for points right now. Never listed; only a redeem refusal. */
    public const NOT_OFFERED = 'not_offered';

    /** The current visitor's rewards at this point total, cheapest first. */
    public static function forVisitor(int $points): array
    {
        $claims = self::claims();

        return self::evaluate(
            $points,
            $claims->pluck('voucher_id')->map('intval')->all(),
            self::spendable($claims),
            self::branchId()
        );
    }

    /**
     * @param  int[]  $wonVoucherIds  every voucher this visitor has ever held, used or not
     * @param  int  $held  how many unused vouchers count against the holding limit
     * @return array<int, array{voucher: Voucher, status: string, label: string}>
     */
    public static function evaluate(int $points, array $wonVoucherIds, int $held, ?int $branchId): array
    {
        return Voucher::query()
            ->with('branch')
            ->where('is_active', true)
            ->where('on_wheel', true)
            ->where('points_required', '>', 0)
            ->notExpired()
            ->orderBy('points_required')
            ->orderBy('id')
            ->get()
            ->map(function (Voucher $voucher) use ($points, $wonVoucherIds, $held, $branchId) {
                [$status, $label] = self::statusFor($voucher, $points, $wonVoucherIds, $held, $branchId);

                return ['voucher' => $voucher, 'status' => $status, 'label' => $label];
            })
            ->all();
    }

    /** Whether the owner has this voucher on offer for points: the test evaluate()'s query makes. */
    public static function isOffered(Voucher $voucher): bool
    {
        return (bool) $voucher->is_active
            && (bool) $voucher->on_wheel
            && (int) $voucher->points_required > 0
            && ! $voucher->isExpired();
    }

    /** The cheapest reward that more points alone would unlock, for the "X more pts" hint. */
    public static function nextToEarn(array $prizes): ?Voucher
    {
        return collect($prizes)->firstWhere('status', self::NEED_POINTS)['voucher'] ?? null;
    }

    /** The cheapest reward the visitor could redeem right now, for the post-spin hint. */
    public static function firstRedeemable(array $prizes): ?Voucher
    {
        return collect($prizes)->firstWhere('status', self::REDEEMABLE)['voucher'] ?? null;
    }

    /** "₱50 OFF" / "10% OFF": how a reward is named on the list, the confirm and the hint. */
    public static function rewardName(Voucher $voucher): string
    {
        $value = (float) $voucher->discount_value;
        $shown = floor($value) == $value ? number_format($value, 0) : number_format($value, 2);

        return $voucher->discount_type === 'percent' ? $shown . '% OFF' : '₱' . $shown . ' OFF';
    }

    /** The status lines the page repaints after a spin or a redeem. */
    public static function forJson(array $prizes): array
    {
        return array_map(fn (array $prize) => [
            'id' => (int) $prize['voucher']->id,
            'points' => (int) $prize['voucher']->points_required,
            'status' => $prize['status'],
            'label' => $prize['label'],
        ], $prizes);
    }

    /** Unused vouchers the current visitor holds that still count against the limit of two. */
    public static function heldCount(): int
    {
        return self::spendable(self::claims());
    }

    /**
     * Only a claim that can still be spent. One on an expired, switched-off or
     * used-up voucher can never be cleared by the customer, so counting it would
     * block every future reward for good.
     */
    public static function spendable(Collection $claims): int
    {
        return $claims->filter(fn (UserVoucher $claim) => ! $claim->is_used && $claim->voucher?->isValid())->count();
    }

    /**
     * Precedence: a fact about this customer first (already won), then why
     * nobody here can redeem it now, and only then what this customer could do.
     * The caller has already checked isOffered().
     *
     * @return array{0: string, 1: string}
     */
    public static function statusFor(Voucher $voucher, int $points, array $wonVoucherIds, int $held, ?int $branchId): array
    {
        if (in_array((int) $voucher->id, $wonVoucherIds, true)) {
            return [self::ALREADY_WON, 'Already won'];
        }

        // branchErrorFor() is the checkout rule, so a voucher this customer
        // could not spend here can never be redeemed here.
        if ($voucher->branchErrorFor($branchId) !== null) {
            return [self::OTHER_BRANCH, 'Not available at this branch'];
        }

        if ($voucher->isUsedUp()) {
            return [self::SOLD_OUT, 'Sold out'];
        }

        if (! $voucher->hasStarted()) {
            return [self::NOT_STARTED, 'Starts on ' . $voucher->valid_from->format('M d, Y')];
        }

        $short = self::shortBy($voucher, $points);

        if ($short > 0) {
            return [self::NEED_POINTS, 'Need ' . $short . ' more ' . ($short === 1 ? 'point' : 'points')];
        }

        if ($held >= GuestVoucherClaims::MAX_UNUSED) {
            return [self::HOLDING_MAX, 'Use a voucher first'];
        }

        return [self::REDEEMABLE, 'Ready to redeem'];
    }

    /** What the customer is told when the server refuses a redeem with $status. */
    public static function refusalFor(string $status, Voucher $voucher, int $points): string
    {
        $short = self::shortBy($voucher, $points);

        return match ($status) {
            self::ALREADY_WON  => 'You have already redeemed this voucher.',
            self::OTHER_BRANCH => 'This voucher is not available at this branch.',
            self::SOLD_OUT     => 'This voucher is sold out.',
            self::NOT_STARTED  => 'This voucher starts on ' . $voucher->valid_from->format('M d, Y') . '.',
            self::NEED_POINTS  => 'You need ' . $short . ' more ' . ($short === 1 ? 'point' : 'points') . ' for this voucher.',
            self::HOLDING_MAX  => 'You already have ' . GuestVoucherClaims::MAX_UNUSED . ' unused vouchers. Use one first, then redeem again.',
            default            => "This voucher can't be redeemed with points right now.",
        };
    }

    private static function shortBy(Voucher $voucher, int $points): int
    {
        return (int) $voucher->points_required - $points;
    }

    /** @return Collection<int, UserVoucher> */
    private static function claims(): Collection
    {
        return Auth::guard('customer')->check()
            ? UserVoucher::with('voucher')->where('user_id', Auth::guard('customer')->id())->get()
            : GuestVoucherClaims::all();
    }

    private static function branchId(): ?int
    {
        return session('branch_id') ? (int) session('branch_id') : null;
    }
}

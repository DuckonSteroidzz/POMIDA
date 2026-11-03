<?php

namespace App\Support;

use App\Models\UserVoucher;
use App\Models\Voucher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The Spin & Win prize list and what each prize means FOR THIS VISITOR.
 *
 * ONE RULE (QA, Nov 2 2026). The game page's "What you can win" list and the
 * picker that actually awards a prize used to be two separate queries. They
 * drifted: the list kept showing a prize its customer had already won and spent,
 * and the picker awarded a prize whose start date had not arrived. Now both read
 * evaluate(), and "You can win this" is exactly what the picker draws from.
 *
 * A prize is listed when the owner has it switched on: active, on the wheel,
 * points_required > 0, not expired. Inactive, switched-off and expired vouchers
 * stay invisible to customers. Everything else a customer can be told about is
 * a status below.
 */
final class WheelPrizes
{
    public const WINNABLE = 'winnable';
    public const ALREADY_WON = 'already_won';
    public const NEED_POINTS = 'need_points';
    public const OTHER_BRANCH = 'other_branch';
    public const NOT_STARTED = 'not_started';
    public const SOLD_OUT = 'sold_out';
    public const HOLDING_MAX = 'holding_max';

    /** The current visitor's prizes at this point total, cheapest first. */
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
     * @param  int  $held  how many unused prizes count against the holding limit
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
                [$status, $label] = self::statusOf($voucher, $points, $wonVoucherIds, $held, $branchId);

                return ['voucher' => $voucher, 'status' => $status, 'label' => $label];
            })
            ->all();
    }

    /** What the picker may award: only prizes reading "You can win this". */
    public static function pick(array $prizes): ?Voucher
    {
        $winnable = collect($prizes)->where('status', self::WINNABLE);

        return $winnable->isEmpty() ? null : $winnable->random()['voucher'];
    }

    /** The cheapest prize that more points alone would unlock, for the "X more pts" hint. */
    public static function nextToEarn(array $prizes): ?Voucher
    {
        return collect($prizes)->firstWhere('status', self::NEED_POINTS)['voucher'] ?? null;
    }

    /** The status lines the page repaints after a spin. */
    public static function forJson(array $prizes): array
    {
        return array_map(fn (array $prize) => [
            'id' => (int) $prize['voucher']->id,
            'status' => $prize['status'],
            'label' => $prize['label'],
        ], $prizes);
    }

    /** Unused prizes the current visitor holds that still count against the limit of two. */
    public static function heldCount(): int
    {
        return self::spendable(self::claims());
    }

    /**
     * Only a claim that can still be spent. One on an expired, switched-off or
     * used-up voucher can never be cleared by the customer, so counting it would
     * block every future prize for good.
     */
    public static function spendable(Collection $claims): int
    {
        return $claims->filter(fn (UserVoucher $claim) => ! $claim->is_used && $claim->voucher?->isValid())->count();
    }

    /**
     * Precedence: a fact about this customer first (already won), then why
     * nobody here can win it now, and only then what this customer could do.
     *
     * @return array{0: string, 1: string}
     */
    private static function statusOf(Voucher $voucher, int $points, array $wonVoucherIds, int $held, ?int $branchId): array
    {
        if (in_array((int) $voucher->id, $wonVoucherIds, true)) {
            return [self::ALREADY_WON, 'Already won'];
        }

        // branchErrorFor() is the redemption rule, so a prize this customer
        // could not spend here can never be offered here.
        if ($voucher->branchErrorFor($branchId) !== null) {
            return [self::OTHER_BRANCH, 'Not available at this branch'];
        }

        if ($voucher->isUsedUp()) {
            return [self::SOLD_OUT, 'Sold out'];
        }

        if (! $voucher->hasStarted()) {
            return [self::NOT_STARTED, 'Starts on ' . $voucher->valid_from->format('M d, Y')];
        }

        $short = (int) $voucher->points_required - $points;

        if ($short > 0) {
            return [self::NEED_POINTS, 'Need ' . $short . ' more ' . ($short === 1 ? 'point' : 'points')];
        }

        if ($held >= GuestVoucherClaims::MAX_UNUSED) {
            return [self::HOLDING_MAX, 'Use a voucher first'];
        }

        return [self::WINNABLE, 'You can win this'];
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

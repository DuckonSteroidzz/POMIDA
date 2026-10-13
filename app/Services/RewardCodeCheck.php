<?php

namespace App\Services;

use App\Models\FreeDessertReward;
use App\Support\RewardCode;
use App\Support\ShopDay;

/**
 * "Check a reward code" on the admin Vouchers & Game page (Oct 2026).
 *
 * READ-ONLY. It never redeems, never marks anything and never changes a
 * reward's status (not even expiring a stale one): a reward is spent only by
 * the customer's own checkout, inside the order transaction.
 *
 * WHO SEES WHAT
 * -------------
 *  - Owner (not branch-locked): every detail of any code, including the
 *    branch that issued it.
 *  - Supervisor (branch-locked): every detail of a code ISSUED BY THEIR
 *    BRANCH (reward.branch_id) — the only branch it can be used at, so it is
 *    the one their counter may see. Any other code — another branch's, or
 *    one from before rewards were per branch (branch_id NULL, issuer
 *    unknown: fail-safe) — answers only "This code belongs to another
 *    branch." with no status, customer, reward, dates or order. A branchless
 *    supervisor (lockedBranchId() = 0) therefore never sees any details.
 *  - Staff and customers never reach this: the route is in the
 *    role:admin,supervisor group, and customers have no admin session.
 *
 * Not found, malformed and "belongs to nobody" all answer the same way.
 */
class RewardCodeCheck
{
    public const STATE_VALID = 'valid';
    public const STATE_USED = 'used';
    public const STATE_EXPIRED = 'expired';
    public const STATE_NOT_SET = 'not_set';
    public const STATE_NOT_FOUND = 'not_found';
    public const STATE_OTHER_BRANCH = 'other_branch';

    /**
     * @param  int|null  $lockedBranchId  AdminOrderAccess::lockedBranchId(): null = owner
     * @return array<string, mixed>
     */
    public static function check(string $typed, ?int $lockedBranchId): array
    {
        $code = RewardCode::display($typed);

        if (! RewardCode::looksLike($typed)) {
            return ['state' => self::STATE_NOT_FOUND, 'code' => $code];
        }

        $reward = FreeDessertReward::with(['user', 'order.branch', 'branch'])
            ->where('code', RewardCode::normalise($typed))
            ->first();

        if (! $reward) {
            return ['state' => self::STATE_NOT_FOUND, 'code' => $code];
        }

        // The NULL issuer is tested on its own first, so a null can never be
        // cast into a match with a branchless supervisor's 0.
        if ($lockedBranchId !== null
            && ($reward->branch_id === null || (int) $reward->branch_id !== $lockedBranchId)) {
            return ['state' => self::STATE_OTHER_BRANCH, 'code' => $code, 'restricted' => true];
        }

        $state = match (true) {
            $reward->status === FreeDessertReward::STATUS_USED => self::STATE_USED,
            $reward->status === FreeDessertReward::STATUS_EXPIRED, $reward->isExpired() => self::STATE_EXPIRED,
            $reward->reward_type === null => self::STATE_NOT_SET,
            default => self::STATE_VALID,
        };

        $result = [
            'state'      => $state,
            'code'       => $code,
            'restricted' => false,
            'customer'   => $reward->user?->name ?? 'Unknown customer',
            'reward'     => $reward->description(),
            'issued_by'  => $reward->branchName(),
            'earned'     => ShopDay::format($reward->created_at, 'M j, Y'),
            'expires'    => $reward->expires_at ? ShopDay::format($reward->expires_at, 'M j, Y') : 'Never',
        ];

        if ($state === self::STATE_USED) {
            $order = $reward->order;

            $result['order_number'] = $order?->order_number;
            $result['used_on'] = $reward->used_at ? ShopDay::format($reward->used_at, 'M j, Y g:i A') : null;
            $result['branch'] = $order?->branch?->name
                ?? ($reward->used_branch_id ? \App\Models\Branch::whereKey($reward->used_branch_id)->value('name') : null);
            // Spent on an order that is not finished yet: it comes back if that
            // order is cancelled (FreeDesserts::releaseForOrder).
            $result['order_open'] = $order !== null && ! in_array($order->status, ['completed', 'cancelled'], true);
        }

        return $result;
    }
}

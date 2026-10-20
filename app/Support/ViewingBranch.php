<?php

namespace App\Support;

use App\Models\Branch;
use App\Services\AdminOrderAccess;

/**
 * The owner's "Viewing:" branch on the Promotions & Rewards tabs (Oct 2026).
 *
 * It is the SAME choice as the "Viewing:" bar every other admin page shows:
 * session('selected_branch_id'), which AdminController::selectBranch() writes
 * and ResolvesBranchScope::getSelectedBranch() reads ('all' or a branch id).
 * One choice, so picking Branch 1 on Vouchers is still Branch 1 on Streak
 * Rewards, on Ads and on Active Orders, and the bar never disagrees with the
 * list under it.
 *
 * A page may name the branch in its own query string (Vouchers: ?branch=,
 * Streak Rewards: ?streak_branch=) so a view can be bookmarked and the list can
 * switch in the background; a valid value there is remembered the same way the
 * bar remembers it. Anything else (a branch that does not exist, a word) is
 * ignored and the remembered choice stands.
 *
 * OWNER ONLY. A branch-locked account (staff, supervisor) has no choice to
 * make: their pages are already narrowed to their own branch by
 * AdminOrderAccess::lockedBranchId(), so this answers null for them and never
 * writes their session. That is what stops ?branch=<another branch> from
 * meaning anything to a supervisor.
 */
final class ViewingBranch
{
    /** The session key shared with the layout's "Viewing:" bar. */
    public const SESSION_KEY = 'selected_branch_id';

    /** The value that means "All Branches" (the layout's own word). */
    public const ALL = 'all';

    /**
     * The branch the owner is viewing, or null for All Branches (and always
     * null for a branch-locked account).
     *
     * $requested is the page's own query value: null when absent, 'all' or
     * $allWord for All Branches, or a branch id.
     */
    public static function resolve(mixed $requested, string $allWord = self::ALL): ?Branch
    {
        if (AdminOrderAccess::lockedBranchId() !== null) {
            return null;
        }

        if (is_string($requested) && $requested !== '') {
            if ($requested === self::ALL || $requested === $allWord) {
                session()->put(self::SESSION_KEY, self::ALL);

                return null;
            }

            if (ctype_digit($requested) && ($branch = Branch::find((int) $requested))) {
                session()->put(self::SESSION_KEY, $branch->id);

                return $branch;
            }
        }

        $stored = session(self::SESSION_KEY, self::ALL);

        return $stored === self::ALL || ! is_numeric($stored) ? null : Branch::find((int) $stored);
    }
}

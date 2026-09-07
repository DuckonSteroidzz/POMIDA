<?php

namespace App\Http\Controllers\Concerns;

/**
 * The admin area's single rule for "which branch am I looking at?".
 *
 * Extracted verbatim from AdminController so the notification feed scopes staff
 * notifications by exactly the same rule the Completed Orders, Inventory and
 * Summary pages already use — rather than a second, drifting copy of it.
 *
 *  - Staff  -> locked to their own branch. They never get a picker, so a
 *              Branch 1 staff member can never be handed a Branch 2 scope.
 *  - Admin  -> whatever the branch selector is set to, defaulting to 'all'.
 */
trait ResolvesBranchScope
{
    private function getSelectedBranch(): int|string
    {
        // Staff — locked sa sariling branch.
        //
        // The lock itself is defined once, in AdminOrderAccess::lockedBranchId(),
        // because the per-order endpoints have to apply the SAME lock this line
        // applies to the lists. Same value as before for both roles: a staff
        // member gets branch_id ?? 1, anyone else gets null here and falls
        // through to the picker below.
        $lockedBranchId = \App\Services\AdminOrderAccess::lockedBranchId();

        if ($lockedBranchId !== null) {
            return $lockedBranchId;
        }

        // Admin — may filter
        return session('selected_branch_id', 'all');
    }
}

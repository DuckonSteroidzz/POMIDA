<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;

/**
 * AdminOrderAccess — the one place that answers "may the signed-in staff member
 * or admin act on THIS order?", and, through findRecordInScope() /
 * allowsBranch(), the same question for any other branch-owned record
 * (inventory, menu items, help requests) or a bare branch_id.
 *
 * WHY THIS EXISTS (branch-scope audit, Sept 2026)
 * ----------------------------------------------
 * ResolvesBranchScope::getSelectedBranch() locks a staff member to their own
 * branch, and the LIST screens (Active Orders, Completed Orders, Inventory,
 * Summary) all consult it. But the per-order endpoints did not: every one of
 * them resolved its record with a bare
 *
 *     Order::findOrFail($id)
 *
 * and never asked which branch the order belonged to. The branch lock was
 * therefore a FILTER ON A LIST, not an authorisation boundary — a branch-1
 * staff member who typed a branch-2 order id into the URL could read its
 * receipt and its customer's PWD/Senior ID, and could complete, cancel,
 * prepare, serve, approve or reject it. Proven live in
 * tests/Feature/AuditStaffBranchScopeTest.php, which is kept as the record of
 * it.
 *
 * Completing an order also DEDUCTS stock, and the deduction follows the order's
 * own menu items, so the hole let one branch's staff move another branch's
 * inventory. That is why this had to be a shared rule rather than a check
 * pasted into whichever endpoint someone happened to remember: a second,
 * hand-rolled copy drifts, and the weaker copy becomes the hole. This is the
 * same argument CustomerOrderAccess and DiscountIdAccess were created to make.
 *
 * THE RULE
 * --------
 *  - Staff and supervisor (User::BRANCH_LOCKED_ROLES)
 *           -> may only reach an order whose branch_id is their own branch.
 *              Identical to the lock getSelectedBranch() already applies to the
 *              lists; getSelectedBranch() now asks THIS class for it, so there
 *              is exactly one definition of "a branch-locked user's branch".
 *              Supervisor was added to that set in the Sept 2026 role pass and
 *              inherited every check below unchanged — which was the point of
 *              having one definition.
 *  - Admin  -> unchanged, and deliberately unrestricted. An admin has a branch
 *              picker whose default is 'all', and the existing per-order
 *              behaviour is that they can open and act on any order regardless
 *              of what the picker is currently showing. Narrowing that to the
 *              picker would be a new restriction on admins, not a fix to the
 *              staff hole, and would break an admin viewing "all branches".
 *
 * WHY A REFUSAL IS A 404 AND NEVER A 403
 * --------------------------------------
 * Same reasoning as DiscountIdAccess and CustomerOrderAccess, stated there at
 * length: a 403 would confirm that the order EXISTS, just somewhere the viewer
 * cannot see. That is information about another branch's trade — an id prober
 * could map which order numbers are real and how busy another branch is. A 404
 * makes "there is no such order" and "that order is not yours" indistinguishable
 * from the outside, so the refusal leaks nothing. findOrFail() already produces
 * exactly that response for a missing id, which is why the out-of-scope case is
 * routed through the same ModelNotFoundException rather than a new page: no new
 * copy, no new layout, nothing for a user to learn.
 */
class AdminOrderAccess
{
    /**
     * The branch the CURRENT admin-guard user is locked to, or null when they
     * are not locked to one (an admin).
     *
     * This is the single definition of the staff branch lock;
     * ResolvesBranchScope::getSelectedBranch() delegates to it so the lists and
     * the per-order endpoints cannot drift apart.
     */
    public static function lockedBranchId(): ?int
    {
        $user = Auth::guard('admin')->user();

        // Not signed in on the admin guard at all -> no lock to report. The
        // caller is not thereby authorised: every route that reaches here is
        // already behind AdminMiddleware, which refuses an unauthenticated
        // visitor long before this runs.
        if (! $user) {
            return null;
        }

        // The role SET, not a single string comparison.
        //
        // This read `$user->role !== 'staff'` until the supervisor pass. A
        // hard-coded role name here is precisely how a new branch-locked role
        // becomes an unlocked one: an unrecognised role falls to the null
        // branch below, ResolvesBranchScope::getSelectedBranch() then falls
        // through to session('selected_branch_id', 'all'), and the account
        // reads EVERY branch. Membership of User::BRANCH_LOCKED_ROLES is now
        // the whole test, so locking a future role is a one-line change there
        // and cannot be forgotten here.
        if (! in_array($user->role, \App\Models\User::BRANCH_LOCKED_ROLES, true)) {
            return null;
        }

        // A branch-locked account with NO branch assigned.
        //
        // Staff keep the historic ?? 1: a staff row with no branch has always
        // been treated as the main branch, every live staff row has a branch,
        // and silently moving them is not this pass's job.
        //
        // Supervisor is deny-by-default instead. 0 is not a real branch id, so
        // `where('branch_id', 0)` matches nothing and the account sees an empty
        // portal rather than being handed Main Branch's orders, inventory and
        // customer ID documents by an omission. storeUser() requires a branch
        // for a supervisor, so this is the unreachable-by-design path — which
        // is exactly the kind that must fail closed.
        if ($user->branch_id === null) {
            return $user->role === 'staff' ? 1 : 0;
        }

        return (int) $user->branch_id;
    }

    /**
     * The order, if this viewer may act on it — otherwise null.
     *
     * Never distinguishes "no such order" from "not your branch"; callers turn
     * null into a 404. Use this when the caller wants to decide for itself what
     * a refusal looks like (DiscountIdAccess does).
     */
    public static function findInScope(int $id, array $with = []): ?Order
    {
        return self::findRecordInScope(Order::class, $id, $with);
    }

    /**
     * The order, or a 404 — the drop-in replacement for Order::findOrFail($id)
     * in every per-order admin endpoint.
     *
     * Throws ModelNotFoundException, exactly as findOrFail() did, so Laravel
     * renders the app's existing 404 for both "no such order" and "not your
     * branch". Nothing about the response tells the two apart.
     */
    public static function resolveInScope(int $id, array $with = []): Order
    {
        return self::resolveRecordInScope(Order::class, $id, $with);
    }

    /**
     * The SAME rule, for any other branch-owned record.
     *
     * The Sept 2026 order pass closed this hole on Order and left an identical
     * one open on every other admin/staff endpoint that resolved a
     * branch-owned row from a route id with a bare findOrFail() — inventory
     * stock-in/stock-out (which move quantities directly), the menu-item
     * availability toggle, and the help-request assist/resolve actions. Those
     * go through here now rather than growing a second, drifting copy of the
     * branch check: one weak copy is the whole hole again.
     *
     * $modelClass must have a branch_id column. Same null-or-nothing contract
     * as findInScope(): a staff member is filtered to their own branch_id, an
     * admin is not filtered at all, and "not your branch" is returned as null
     * so the caller renders it exactly like "no such row".
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  class-string<TModel>  $modelClass
     * @return TModel|null
     */
    public static function findRecordInScope(string $modelClass, int $id, array $with = [])
    {
        $query = $modelClass::query()->whereKey($id);

        if (! empty($with)) {
            $query->with($with);
        }

        $branchId = self::lockedBranchId();

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query->first();
    }

    /**
     * The record, or a 404 — the drop-in replacement for
     * Model::findOrFail($id) in a branch-owned admin/staff endpoint.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    public static function resolveRecordInScope(string $modelClass, int $id, array $with = [])
    {
        $record = self::findRecordInScope($modelClass, $id, $with);

        if (! $record) {
            throw (new ModelNotFoundException())->setModel($modelClass, [$id]);
        }

        return $record;
    }

    /**
     * May the current admin-guard user act on a record that belongs to
     * $branchId? The one answer to "is this branch mine?".
     *
     * This is for the cases where the branch is NOT reached through a resolved
     * row: POST /admin/manual-order names its target branch_id in the request
     * body and CREATES an order there, and clearTableOccupancy() acts on a
     * (branch_id, table_number) pair rather than a model id. Both used to
     * hand-roll "staff && branch_id !== theirs" — that is this method now, so
     * the rule has exactly one definition.
     *
     * Admins (not locked to a branch) may act on any branch, unchanged.
     */
    public static function allowsBranch(?int $branchId): bool
    {
        $locked = self::lockedBranchId();

        return $locked === null || $locked === (int) $branchId;
    }
}

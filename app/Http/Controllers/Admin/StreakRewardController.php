<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Voucher;
use App\Services\AdminOrderAccess;
use App\Services\RewardCodeCheck;
use App\Services\StreakRewardSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The Streak Rewards page (Oct 2026), split out of admin Vouchers & Game so
 * the stamp-card settings have their own simple screen.
 * Every route here is in the role:admin,supervisor group; Staff and
 * customers are refused by RoleMiddleware before any of this runs.
 *
 *   index       GET  admin.streak-rewards — the page itself: the branch
 *               overview and picker (owner only), the settings card for the
 *               selected branch or "All branches", and the code-check card.
 *   update      POST admin.streak.rewards.update — save one reward set:
 *               branch_id=<id> for a branch's own settings, branch_id=default
 *               for "All branches" (the company-wide fallback).
 *   useDefault  POST admin.streak.rewards.default — drop a branch's own set
 *               so it follows "All branches" again.
 *   toggle      POST admin.streak.branch.toggle — one branch's on/off.
 *   check       POST admin.streak.rewards.check — read-only lookup of one
 *               reward code, branch-scoped for a supervisor
 *               (App\Services\RewardCodeCheck). Rate limited per user and per
 *               IP (admin-reward-code-check).
 *
 * WHO MAY CHANGE WHAT (targetBranch(), the one rule for update/useDefault/toggle)
 * -------------------------------------------------------------------------
 *  - Owner (AdminOrderAccess::lockedBranchId() = null): any branch, and
 *    "All branches".
 *  - Supervisor (locked to their branch): their own branch only. Another
 *    branch_id and "All branches" are refused with 403, whatever the form
 *    sent; a branchless supervisor (locked 0) is refused everything.
 *  The owner's master Streak Calendar switch stays in the role:admin group
 *  (AdminController::toggleStreakCalendar) but is shown at the top of this
 *  same page; the Spin Wheel switch stays on Vouchers & Game.
 *
 * All answer with a redirect back to this page. Errors go into their own
 * error bags so they never show up under the Create Voucher form's fields on
 * the OTHER page, which share names like "code". The checked code is
 * flashed, never put in the URL.
 */
class StreakRewardController extends Controller
{
    public const ERROR_BAG_SETTINGS = 'streakRewards';
    public const ERROR_BAG_CHECK = 'rewardCheck';

    /** The branch_id value that means "All branches" (the company-wide fallback). */
    public const DEFAULT_SCOPE = 'default';

    /**
     * GET admin.streak-rewards. Mirrors the data AdminController::showVouchers()
     * used to build for the same card, moved here with the page itself.
     *
     *   Owner: ?streak_branch=<id>|default picks the branch (or "All
     *     branches") the settings card shows; an overview of every branch is
     *     always included.
     *   Supervisor: their own branch only — no query param, no overview.
     *     A branchless supervisor gets scope_ok=false and no form.
     */
    public function index(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();
        $isAdmin = $adminUser?->isAdmin() ?? false;
        $locked = AdminOrderAccess::lockedBranchId();

        $streakOverview = collect();
        $scopeBranch = null;
        $scopeOk = true;

        if ($locked === null) {
            $requested = (string) $request->query('streak_branch', '');
            $scopeBranch = ctype_digit($requested) ? Branch::find((int) $requested) : null;
            $streakOverview = StreakRewardSettings::overview();
        } else {
            $scopeBranch = $locked > 0 ? Branch::find($locked) : null;
            $scopeOk = $scopeBranch !== null;
        }

        $scopeId = $scopeBranch?->id;

        $streakRewards = StreakRewardSettings::current($scopeId) + [
            'branch'         => $scopeBranch,
            'scope_ok'       => $scopeOk,
            'branch_enabled' => $scopeId !== null ? StreakRewardSettings::branchEnabled($scopeId) : null,
            'master_enabled' => \App\Models\Setting::streakCalendarEnabled(),
        ];

        $rewardVoucherOptions = collect();
        $rewardMenuItemOptions = collect();

        if ($scopeOk) {
            $fitsScope = fn ($q) => $scopeId === null
                ? $q->whereNull('branch_id')
                : $q->whereNull('branch_id')->orWhere('branch_id', $scopeId);

            $rewardVoucherOptions = Voucher::with('branch')
                ->where('is_active', true)
                ->notExpired()
                ->where($fitsScope)
                ->orderBy('code')
                ->get();

            // Menu items are never company-wide in this business — the Menu
            // Items page itself says "Select a specific branch before adding
            // menu items" — so under "All branches" ($scopeId === null) this
            // query can never return a row. Skip it rather than hand the view
            // an always-empty list to render as a picker; the view disables
            // the "Free menu item" reward for that scope instead.
            if ($scopeId !== null) {
                $rewardMenuItemOptions = MenuItem::with('branch')
                    ->where($fitsScope)
                    ->orderBy('name')
                    ->get(['id', 'name', 'branch_id', 'is_available']);
            }
        }

        return view('admin.streak-rewards', compact(
            'isAdmin',
            'streakRewards',
            'streakOverview',
            'rewardVoucherOptions',
            'rewardMenuItemOptions'
        ));
    }

    public function update(Request $request): RedirectResponse
    {
        $branchId = $this->targetBranch($request, allowDefault: true);

        $validated = $request->validateWithBag(self::ERROR_BAG_SETTINGS, [
            'days'          => 'required|integer|min:1|max:' . StreakRewardSettings::MAX_DAYS,
            'reward_type'   => 'required|in:' . implode(',', StreakRewardSettings::TYPES),
            'voucher_id'    => 'nullable|required_if:reward_type,' . StreakRewardSettings::TYPE_VOUCHER . '|integer',
            'menu_item_id'  => 'nullable|required_if:reward_type,' . StreakRewardSettings::TYPE_MENU_ITEM . '|integer',
            'valid_days'    => 'required|integer|min:1|max:' . StreakRewardSettings::MAX_VALID_DAYS,
            'apply_to_all'  => 'nullable|boolean',
        ], [
            'days.required'            => 'Enter how many days with a completed order earn a reward.',
            'days.min'                 => 'Days needed must be at least 1.',
            'days.max'                 => 'Days needed can be at most ' . StreakRewardSettings::MAX_DAYS . '.',
            'days.integer'             => 'Days needed must be a whole number.',
            'reward_type.required'     => 'Choose whether the reward is a voucher or a menu item.',
            'reward_type.in'           => 'Choose whether the reward is a voucher or a menu item.',
            'voucher_id.required_if'   => 'Choose the voucher to copy for the reward.',
            'voucher_id.integer'       => 'Choose the voucher to copy for the reward.',
            'menu_item_id.required_if' => 'Choose the menu item to give.',
            'menu_item_id.integer'     => 'Choose the menu item to give.',
            'valid_days.required'      => 'Enter how many days a reward stays valid.',
            'valid_days.min'           => 'A reward must stay valid for at least 1 day.',
            'valid_days.max'           => 'Validity can be at most ' . StreakRewardSettings::MAX_VALID_DAYS . ' days.',
            'valid_days.integer'       => 'Validity must be a whole number of days.',
        ]);

        $type = $validated['reward_type'];
        $voucher = null;
        $menuItem = null;
        $branchName = $branchId !== null ? Branch::whereKey($branchId)->value('name') : null;

        if ($type === StreakRewardSettings::TYPE_VOUCHER) {
            $voucher = Voucher::with('branch')->find((int) $validated['voucher_id']);

            // The template must be live now. Its terms are copied at this
            // moment; what happens to the voucher afterwards does not change
            // the reward until these settings are saved again.
            if (! $voucher || ! $voucher->is_active || $voucher->isExpired()) {
                return $this->refuse('voucher_id', 'Choose an active voucher that has not expired.', $branchId);
            }

            // It must be valid where the reward will be used: a company-wide
            // voucher or this branch's own. "All branches" is used by every
            // branch, so only a company-wide voucher fits it.
            if (! StreakRewardSettings::fits($voucher->branch_id !== null ? (int) $voucher->branch_id : null, $branchId)) {
                return $this->refuse('voucher_id', $branchId === null
                    ? 'Choose a voucher for all branches.'
                    : 'Choose a voucher for ' . $branchName . ' or for all branches.', $branchId);
            }
        } else {
            // Archived items are excluded by MenuItem's global scope.
            $menuItem = MenuItem::find((int) $validated['menu_item_id']);

            if (! $menuItem) {
                return $this->refuse('menu_item_id', 'Choose a menu item that is still on the menu.', $branchId);
            }

            if (! StreakRewardSettings::fits($menuItem->branch_id !== null ? (int) $menuItem->branch_id : null, $branchId)) {
                return $this->refuse('menu_item_id', $branchId === null
                    ? 'Free menu item is set per branch. Choose a voucher discount for all branches instead.'
                    : 'Choose a menu item sold at ' . $branchName . ' or at all branches.', $branchId);
            }
        }

        $applyToAll = $branchId === null && $request->boolean('apply_to_all');

        StreakRewardSettings::save(
            $branchId,
            (int) $validated['days'],
            $type,
            $voucher,
            $menuItem,
            (int) $validated['valid_days'],
            Auth::guard('admin')->id(),
            $applyToAll
        );

        $message = $branchId === null
            ? ($applyToAll
                ? 'All branches saved, and every branch now uses it.'
                : 'All branches saved. Branches without their own settings still use it.')
            : 'Streak reward settings saved for ' . $branchName . '.';

        return $this->backTo($branchId)
            ->with('success', $message . ' They apply to rewards earned from now on; rewards already earned keep their own terms.');
    }

    public function useDefault(Request $request): RedirectResponse
    {
        $branchId = $this->targetBranch($request, allowDefault: false);

        StreakRewardSettings::useDefault($branchId, Auth::guard('admin')->id());

        return $this->backTo($branchId)->with(
            'success',
            Branch::whereKey($branchId)->value('name') . ' now follows All branches for its streak reward.'
        );
    }

    public function toggle(Request $request): RedirectResponse
    {
        $branchId = $this->targetBranch($request, allowDefault: false);
        $on = ! StreakRewardSettings::branchEnabled($branchId);

        StreakRewardSettings::setBranchEnabled($branchId, $on, Auth::guard('admin')->id());

        return $this->backTo($branchId)->with(
            'success',
            'Streak Calendar & Reward Stamps ' . ($on ? 'enabled' : 'disabled') . ' at ' . Branch::whereKey($branchId)->value('name') . '.'
        );
    }

    public function check(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag(self::ERROR_BAG_CHECK, [
            'reward_code' => 'required|string|max:40',
        ], [
            'reward_code.required' => 'Type the reward code to check.',
            'reward_code.max'      => 'That is longer than any reward code.',
        ]);

        return redirect()->to(route('admin.streak-rewards') . '#reward-code-check')
            ->with('reward_check', RewardCodeCheck::check(
                $validated['reward_code'],
                AdminOrderAccess::lockedBranchId()
            ));
    }

    /**
     * The branch a settings request may change, or null for "All branches"
     * (the company-wide fallback). THE one permission rule for these routes:
     *
     *   'default'  -> owner only (403 for a supervisor); 404 where a branch is required
     *   <id>       -> a supervisor's own branch only (403 for any other, and
     *                 for everything when they have no branch); the owner,
     *                 any branch that exists (404 otherwise)
     *
     * A supervisor is answered BEFORE the id is looked up, so a refusal says
     * nothing about which branch ids exist.
     */
    private function targetBranch(Request $request, bool $allowDefault): ?int
    {
        $raw = trim((string) $request->input('branch_id', ''));
        $locked = AdminOrderAccess::lockedBranchId();

        if ($locked !== null) {
            if ($raw === self::DEFAULT_SCOPE) {
                abort(403, 'Only the owner can change All branches.');
            }

            if ($locked <= 0 || $raw !== (string) $locked || ! Branch::whereKey($locked)->exists()) {
                abort(403, 'You can only change the streak reward settings of your own branch.');
            }

            return $locked;
        }

        if ($raw === self::DEFAULT_SCOPE) {
            abort_unless($allowDefault, 404);

            return null;
        }

        abort_unless(ctype_digit($raw) && Branch::whereKey((int) $raw)->exists(), 404);

        return (int) $raw;
    }

    /** Back to the card showing $branchId (null = "All branches"). */
    private function backTo(?int $branchId): RedirectResponse
    {
        $query = AdminOrderAccess::lockedBranchId() === null
            ? ['streak_branch' => $branchId ?? self::DEFAULT_SCOPE]
            : [];

        return redirect()->to(route('admin.streak-rewards', $query) . '#streak-rewards-card');
    }

    private function refuse(string $field, string $message, ?int $branchId): RedirectResponse
    {
        return $this->backTo($branchId)
            ->withErrors([$field => $message], self::ERROR_BAG_SETTINGS)
            ->withInput();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression guard for the counter/manual-order branch picker (Sept 2026,
 * lower-severity bundle item #3).
 *
 * AdminController::home() built the manual-order form's $branches list as
 * every active branch, unconditionally — unlike the sibling branch pickers
 * elsewhere in the same controller, which all consult
 * AdminOrderAccess::lockedBranchId() to restrict the list for a branch-locked
 * staff/supervisor. storeManualOrder() already 404s a submission for a
 * branch a locked user does not own, so this was a wasted-effort UX bug: a
 * locked user could pick a branch that is not theirs, fill out an entire
 * walk-in order, and have it rejected only on submit.
 *
 * These tests pin that the picker (admin/home's $branches view data) now
 * shows ONLY the locked user's own branch, and that an unrestricted admin's
 * picker is unchanged.
 *
 * Every row this file creates carries the MOBPS prefix and runs inside
 * DatabaseTransactions, so nothing survives the run in pomida_db_testing.
 */
class ManualOrderBranchPickerScopeTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'MOBPS';
    private const HOME_BRANCH = 1;

    private function otherBranch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Far Branch ' . uniqid(),
            'code'      => 'MB' . substr((string) uniqid(), -6),
            'address'   => self::PREFIX . ' test address',
            'is_active' => true,
        ]);
    }

    private function staffAt(int $branchId): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Staff',
            'email'     => strtolower(self::PREFIX) . '-staff-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => 'staff',
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
    }

    private function supervisorAt(int $branchId): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Supervisor',
            'email'     => strtolower(self::PREFIX) . '-sup-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => 'supervisor',
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    public function test_a_branch_locked_staff_members_picker_only_shows_their_own_branch(): void
    {
        $farBranch = $this->otherBranch();
        $staff = $this->staffAt(self::HOME_BRANCH);

        $branches = $this->actingAs($staff, 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->viewData('branches');

        $ids = $branches->pluck('id')->all();

        $this->assertSame(
            [self::HOME_BRANCH],
            $ids,
            'a branch-locked staff member must see only their own branch in the counter-order picker'
        );
        $this->assertNotContains($farBranch->id, $ids);
    }

    public function test_a_branch_locked_supervisors_picker_only_shows_their_own_branch(): void
    {
        $farBranch = $this->otherBranch();
        $supervisor = $this->supervisorAt(self::HOME_BRANCH);

        $branches = $this->actingAs($supervisor, 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->viewData('branches');

        $ids = $branches->pluck('id')->all();

        $this->assertSame(
            [self::HOME_BRANCH],
            $ids,
            'a branch-locked supervisor must see only their own branch in the counter-order picker'
        );
        $this->assertNotContains($farBranch->id, $ids);
    }

    public function test_an_unrestricted_admin_still_sees_every_active_branch(): void
    {
        $farBranch = $this->otherBranch();

        $branches = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->viewData('branches');

        $ids = $branches->pluck('id')->all();

        $this->assertContains(self::HOME_BRANCH, $ids);
        $this->assertContains(
            $farBranch->id,
            $ids,
            'an admin (not branch-locked) must still see every active branch in the counter-order picker'
        );
    }

    public function test_a_locked_staff_members_picker_never_lists_an_inactive_branch(): void
    {
        $staff = $this->staffAt(self::HOME_BRANCH);
        Branch::where('id', self::HOME_BRANCH)->update(['is_active' => false]);

        $branches = $this->actingAs($staff, 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->viewData('branches');

        $this->assertSame(
            [],
            $branches->pluck('id')->all(),
            'an inactive branch must never appear in the picker, even for the user locked to it'
        );

        Branch::where('id', self::HOME_BRANCH)->update(['is_active' => true]);
    }
}

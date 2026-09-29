<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AdminOrderAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\ForcesSpinOutcome;
use Tests\TestCase;

/**
 * Branch parity audit, Batch 2 (2026-09-27) — B2, B3, B4, B5, B6, B7, B8, B10.
 *
 * B1 (branch create/edit 500s) and B9 (a manual setup checklist, no code fix)
 * are deliberately out of scope for this file — see branch-parity-audit
 * memory for those.
 *
 * Every row this file creates carries the BP2 prefix and it runs inside
 * DatabaseTransactions, so nothing survives the run in pomida_db_testing.
 */
class BranchParityBatch2Test extends TestCase
{
    use DatabaseTransactions;
    use ForcesSpinOutcome;

    private const PREFIX = 'BP2';
    private const HOME_BRANCH = 1;

    // ══════════════════ shared fixtures ══════════════════

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function managerAt(int $branchId): User
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

    private function staffAt(?int $branchId): User
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

    private function otherBranch(bool $active = true): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Branch ' . uniqid(),
            'code'      => 'BP2' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => $active,
        ]);
    }

    private function menuItemIn(?int $branchId): MenuItem
    {
        return MenuItem::create([
            'category_id'  => Category::query()->value('id'),
            'branch_id'    => $branchId,
            'name'         => self::PREFIX . ' Item ' . uniqid(),
            'price'        => 100.00,
            'is_available' => true,
        ]);
    }

    private function freshOption(): MenuOption
    {
        return MenuOption::create([
            'name'             => self::PREFIX . ' Option ' . uniqid(),
            'additional_price' => 5,
            'is_active'        => true,
            'display_order'    => 0,
        ]);
    }

    private function inventoryIn(int $branchId, string $code): Inventory
    {
        return Inventory::create([
            'branch_id' => $branchId,
            'item_name' => self::PREFIX . ' Item ' . uniqid(),
            'item_code' => $code,
            'unit'      => 'kg',
            'quantity'  => 5,
            'is_active' => true,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // B2 — supervisor edit/archive of a shared/cross-branch add-on
    // ══════════════════════════════════════════════════════════════

    public function test_a_supervisor_cannot_edit_an_addon_used_by_another_branchs_item(): void
    {
        $far    = $this->otherBranch();
        $item   = $this->menuItemIn($far->id);
        $option = $this->freshOption();
        $option->menuItems()->attach($item->id);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/menu-options/' . $option->id, [
                'name'  => self::PREFIX . ' Hijacked',
                'price' => 9,
            ])
            ->assertRedirect(route('admin.menu-options'));

        $fresh = $option->fresh();
        $this->assertNotSame(self::PREFIX . ' Hijacked', $fresh->name,
            'an add-on used by a foreign branch\'s item must not be editable by a locked supervisor');
    }

    public function test_a_supervisor_cannot_edit_an_addon_used_by_a_shared_item(): void
    {
        $sharedItem = $this->menuItemIn(null); // NULL branch_id = shared across every branch
        $option     = $this->freshOption();
        $option->menuItems()->attach($sharedItem->id);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/menu-options/' . $option->id, [
                'name'  => self::PREFIX . ' Hijacked Shared',
                'price' => 9,
            ])
            ->assertRedirect(route('admin.menu-options'));

        $this->assertNotSame(self::PREFIX . ' Hijacked Shared', $option->fresh()->name,
            'an add-on used by a SHARED item must be refused, not just a differently-branded item');
    }

    public function test_a_supervisor_can_edit_an_addon_used_only_within_their_own_branch(): void
    {
        $item   = $this->menuItemIn(self::HOME_BRANCH);
        $option = $this->freshOption();
        $option->menuItems()->attach($item->id);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/menu-options/' . $option->id, [
                'name'  => self::PREFIX . ' Renamed OK',
                'price' => 9,
            ])
            ->assertRedirect(route('admin.menu-options'));

        $this->assertSame(self::PREFIX . ' Renamed OK', $option->fresh()->name);
    }

    public function test_a_supervisor_can_edit_an_addon_not_yet_assigned_to_any_item(): void
    {
        $option = $this->freshOption(); // no menuItems attached at all

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/menu-options/' . $option->id, [
                'name'  => self::PREFIX . ' Fresh Renamed',
                'price' => 9,
            ])
            ->assertRedirect(route('admin.menu-options'));

        $this->assertSame(self::PREFIX . ' Fresh Renamed', $option->fresh()->name,
            'an add-on assigned to nothing yet has vacuously satisfied "only my own branch" and must stay editable');
    }

    public function test_a_supervisor_cannot_archive_an_addon_used_by_another_branchs_item(): void
    {
        $far    = $this->otherBranch();
        $item   = $this->menuItemIn($far->id);
        $option = $this->freshOption();
        $option->menuItems()->attach($item->id);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->delete('/admin/menu-options/' . $option->id)
            ->assertRedirect(route('admin.menu-options'));

        $this->assertNull($option->fresh()->archived_at,
            'a foreign-branch add-on must not be archivable by a locked supervisor');
    }

    public function test_a_supervisor_can_archive_an_addon_used_only_within_their_own_branch(): void
    {
        $item   = $this->menuItemIn(self::HOME_BRANCH);
        $option = $this->freshOption();
        $option->menuItems()->attach($item->id);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->delete('/admin/menu-options/' . $option->id)
            ->assertRedirect(route('admin.menu-options'));

        $this->assertNotNull($option->fresh()->archived_at,
            'a supervisor must still be able to archive an add-on used only within their own branch');
    }

    public function test_admin_can_edit_an_addon_regardless_of_which_branches_use_it(): void
    {
        $far    = $this->otherBranch();
        $item   = $this->menuItemIn($far->id);
        $option = $this->freshOption();
        $option->menuItems()->attach($item->id);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->put('/admin/menu-options/' . $option->id, [
                'name'  => self::PREFIX . ' Admin Renamed',
                'price' => 9,
            ])
            ->assertRedirect(route('admin.menu-options'));

        $this->assertSame(self::PREFIX . ' Admin Renamed', $option->fresh()->name);
    }

    // ══════════════════════════════════════════════════════════════
    // B8a — category rename is owner-only
    // ══════════════════════════════════════════════════════════════

    public function test_a_supervisor_cannot_rename_a_category(): void
    {
        $category = Category::create([
            'name'          => self::PREFIX . ' Category ' . uniqid(),
            'is_active'     => true,
            'display_order' => 0,
        ]);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/add-category/' . $category->id, [
                'name' => self::PREFIX . ' Renamed By Supervisor',
            ])
            ->assertRedirect(route('admin.home'));

        $this->assertNotSame(self::PREFIX . ' Renamed By Supervisor', $category->fresh()->name,
            'categories are always shared across branches — a supervisor must never be able to rename one');
    }

    public function test_the_owner_can_still_rename_a_category(): void
    {
        $category = Category::create([
            'name'          => self::PREFIX . ' Category ' . uniqid(),
            'is_active'     => true,
            'display_order' => 0,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/add-category/' . $category->id, [
                'name' => self::PREFIX . ' Renamed By Owner',
            ])
            ->assertRedirect(route('admin.add-category'));

        $this->assertSame(self::PREFIX . ' Renamed By Owner', $category->fresh()->name);
    }

    // ══════════════════════════════════════════════════════════════
    // B8b — add-ons page hides other branches' ingredient-mapping chips
    // ══════════════════════════════════════════════════════════════

    public function test_a_locked_supervisor_only_sees_their_own_branch_chip_on_the_addons_page(): void
    {
        $home = $this->otherBranch();
        $far  = $this->otherBranch();

        $option   = $this->freshOption();
        $homeItem = $this->menuItemIn($home->id);
        $farItem  = $this->menuItemIn($far->id);
        $option->menuItems()->attach([$homeItem->id, $farItem->id]);

        $html = $this->actingAs($this->managerAt($home->id), 'admin')
            ->get('/admin/menu-options')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-branch-name="' . $home->name . '"', $html,
            'the supervisor\'s own branch chip must still show');
        $this->assertStringNotContainsString('data-branch-name="' . $far->name . '"', $html,
            'a different branch\'s ingredient-mapping chip must not be shown to a branch-locked supervisor');
    }

    public function test_the_owner_sees_every_branch_chip_on_the_addons_page(): void
    {
        $home = $this->otherBranch();
        $far  = $this->otherBranch();

        $option   = $this->freshOption();
        $homeItem = $this->menuItemIn($home->id);
        $farItem  = $this->menuItemIn($far->id);
        $option->menuItems()->attach([$homeItem->id, $farItem->id]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get('/admin/menu-options')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-branch-name="' . $home->name . '"', $html);
        $this->assertStringContainsString('data-branch-name="' . $far->name . '"', $html);
    }

    // ══════════════════════════════════════════════════════════════
    // B3 — editing a closed-branch account no longer silently reassigns it
    // ══════════════════════════════════════════════════════════════

    public function test_the_users_edit_dropdown_offers_the_accounts_own_closed_branch(): void
    {
        $closed = $this->otherBranch(active: false);
        $staff  = $this->staffAt($closed->id);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/users')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '<option value="' . $closed->id . '" selected>',
            $html,
            'the closed branch the account already belongs to must be pre-selected in its own edit row'
        );
        $this->assertStringContainsString($closed->name . ' (closed)', $html);
    }

    public function test_editing_a_non_branch_field_does_not_reassign_a_closed_branch_account_to_main(): void
    {
        $closed = $this->otherBranch(active: false);
        $staff  = $this->staffAt($closed->id);

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/users/' . $staff->id, [
                'name'      => self::PREFIX . ' Renamed Staff',
                'email'     => $staff->email,
                'branch_id' => $closed->id, // exactly what the fixed dropdown now pre-selects
                'role'      => 'staff',
            ])
            ->assertRedirect(route('admin.users'));

        $fresh = $staff->fresh();
        $this->assertSame(self::PREFIX . ' Renamed Staff', $fresh->name);
        $this->assertSame($closed->id, $fresh->branch_id,
            'saving an edit must not silently move the account off its own closed branch');
    }

    public function test_a_supervisor_of_a_closed_branch_can_save_edits_to_their_own_staff(): void
    {
        $closed     = $this->otherBranch(active: false);
        $supervisor = $this->managerAt($closed->id);
        $staff      = $this->staffAt($closed->id);

        $this->actingAs($supervisor, 'admin')
            ->put('/admin/users/' . $staff->id, [
                'name'      => self::PREFIX . ' Renamed By Closed Supervisor',
                'email'     => $staff->email,
                'branch_id' => $closed->id,
                'role'      => 'staff',
            ])
            ->assertRedirect(route('admin.users'));

        $fresh = $staff->fresh();
        $this->assertSame(self::PREFIX . ' Renamed By Closed Supervisor', $fresh->name);
        $this->assertSame($closed->id, $fresh->branch_id);
    }

    public function test_the_owner_can_still_move_an_account_between_two_open_branches(): void
    {
        $from  = $this->otherBranch(active: true);
        $to    = $this->otherBranch(active: true);
        $staff = $this->staffAt($from->id);

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/users/' . $staff->id, [
                'name'      => $staff->name,
                'email'     => $staff->email,
                'branch_id' => $to->id,
                'role'      => 'staff',
            ])
            ->assertRedirect(route('admin.users'));

        $this->assertSame($to->id, $staff->fresh()->branch_id,
            'a deliberate branch move between two open branches must still work');
    }

    // ══════════════════════════════════════════════════════════════
    // B4 — new branches start closed
    // ══════════════════════════════════════════════════════════════

    public function test_a_new_branch_is_created_closed_by_default(): void
    {
        $code = 'BP2N' . strtoupper(substr(uniqid(), -5));

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/branches', [
                'name'    => self::PREFIX . ' New Branch ' . uniqid(),
                'code'    => $code,
                'address' => self::PREFIX . ' address',
            ])
            ->assertRedirect(route('admin.branches'));

        $branch = Branch::where('code', $code)->firstOrFail();

        $this->assertFalse((bool) $branch->is_active, 'a newly created branch must start closed');
    }

    public function test_a_closed_branch_is_hidden_from_the_customer_pickup_list_until_activated(): void
    {
        $branch = $this->otherBranch(active: false);

        $names = $this->get('/customer/menu')->assertOk()->viewData('branches')->pluck('name');
        $this->assertFalse($names->contains($branch->name),
            'a closed branch must not appear in the customer pick-up list');

        $branch->update(['is_active' => true]);

        $names = $this->get('/customer/menu')->assertOk()->viewData('branches')->pluck('name');
        $this->assertTrue($names->contains($branch->name),
            'activating the branch must make it appear');
    }

    // ══════════════════════════════════════════════════════════════
    // B5 — inventory item_code is unique per branch, not globally
    // ══════════════════════════════════════════════════════════════

    public function test_two_different_branches_can_use_the_same_item_code(): void
    {
        $far = $this->otherBranch();

        $this->inventoryIn(self::HOME_BRANCH, self::PREFIX . '-SHARED-CODE');

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $far->id])
            ->post('/admin/inventory', [
                'item_name' => self::PREFIX . ' Sugar Far',
                'item_code' => self::PREFIX . '-SHARED-CODE',
                'quantity'  => 5,
                'unit'      => 'kg',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Inventory::where('item_code', self::PREFIX . '-SHARED-CODE')->count(),
            'the same item_code must now be independently usable by two different branches');
    }

    public function test_a_branch_still_cannot_reuse_its_own_active_item_code(): void
    {
        $this->inventoryIn(self::HOME_BRANCH, self::PREFIX . '-TAKEN');

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => self::HOME_BRANCH])
            ->post('/admin/inventory', [
                'item_name' => self::PREFIX . ' Sugar Dup',
                'item_code' => self::PREFIX . '-TAKEN',
                'quantity'  => 5,
                'unit'      => 'kg',
            ])
            ->assertSessionHasErrors('item_code');

        $this->assertSame(1, Inventory::where('item_code', self::PREFIX . '-TAKEN')->count());
    }

    public function test_restoring_an_archived_item_keeps_its_code_even_if_another_branch_now_uses_it(): void
    {
        $far  = $this->otherBranch();
        $item = $this->inventoryIn(self::HOME_BRANCH, self::PREFIX . '-RESTORE');

        $item->archive();

        $this->inventoryIn($far->id, self::PREFIX . '-RESTORE');

        $restored = $item->unarchive();

        $this->assertTrue($restored,
            'a code freed by archiving must be restorable even though a DIFFERENT branch has since reused it');
        $this->assertSame(self::PREFIX . '-RESTORE', $item->fresh()->item_code);
    }

    // ══════════════════════════════════════════════════════════════
    // B6 — the "next voucher" spin hint is branch-scoped
    // ══════════════════════════════════════════════════════════════

    private function customer(int $points): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Customer',
            'email'     => strtolower(self::PREFIX) . '-cust-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => 'customer',
            'points'    => $points,
            'is_active' => true,
        ]);
    }

    private function orderFor(User $user, int $branchId): void
    {
        \App\Models\Order::create([
            'order_number'   => 'BP2-' . substr(uniqid(), -8),
            'user_id'        => $user->id,
            'branch_id'      => $branchId,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 500,
            'total'          => 500,
        ]);
    }

    private function voucher(?int $branchId, string $suffix, int $pointsRequired): Voucher
    {
        return Voucher::create([
            'branch_id'       => $branchId,
            'code'            => self::PREFIX . '-' . $suffix,
            'description'     => self::PREFIX . ' voucher ' . $suffix,
            'discount_type'   => 'fixed',
            'discount_value'  => 25,
            'max_uses'        => 100,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'is_active'       => true,
            'points_required' => $pointsRequired,
        ]);
    }

    public function test_the_next_voucher_hint_never_names_a_different_branchs_voucher(): void
    {
        $far  = $this->otherBranch();
        // 10 points, deliberately below every candidate below (12/15/25/35)
        // so this spin cannot immediately WIN one and change $user->points
        // out from under the very total the "next voucher" query runs
        // against — reproduced once already: a customer with enough points
        // to win the pre-existing global 35-point voucher had their balance
        // drop to 5 mid-request, which silently changed which voucher was
        // "next" for reasons unrelated to branch scoping at all.
        $user = $this->customer(points: 10);
        $this->orderFor($user, self::HOME_BRANCH);

        // LOWER threshold but the WRONG branch — a buggy unscoped query picks
        // the globally-lowest points_required and would return this one.
        $farVoucher = $this->voucher($far->id, 'NEXTFAR', pointsRequired: 12);
        // HIGHER threshold but the RIGHT branch — must be the one returned.
        $home = $this->voucher(self::HOME_BRANCH, 'NEXTHOME', pointsRequired: 15);

        $this->forceSpinOutcome(0); // "Try Again" — leaves $user->points unchanged at 10

        $response = $this->actingAs($user, 'customer')
            ->withSession(['branch_id' => self::HOME_BRANCH])
            ->postJson('/customer/add-points')
            ->assertOk();

        $this->assertSame(
            $home->description,
            $response->json('next_voucher'),
            'the next-voucher hint must never surface a voucher from a different branch, even one with a lower threshold'
        );
    }

    public function test_the_next_voucher_hint_still_shows_a_same_branch_voucher(): void
    {
        $user = $this->customer(points: 10);
        $this->orderFor($user, self::HOME_BRANCH);

        $home = $this->voucher(self::HOME_BRANCH, 'NEXTHOME2', pointsRequired: 15);

        $this->forceSpinOutcome(0);

        $response = $this->actingAs($user, 'customer')
            ->withSession(['branch_id' => self::HOME_BRANCH])
            ->postJson('/customer/add-points')
            ->assertOk();

        $this->assertSame($home->description, $response->json('next_voucher'),
            'a same-branch voucher must still be offered as the next-voucher hint');
    }

    // ══════════════════════════════════════════════════════════════
    // B7 — a branchless staff account is denied, not defaulted to Main
    // ══════════════════════════════════════════════════════════════

    public function test_a_branchless_staff_account_is_denied_not_defaulted_to_main(): void
    {
        $staff = $this->staffAt(null);

        Auth::guard('admin')->login($staff);

        $this->assertSame(0, AdminOrderAccess::lockedBranchId(),
            'a branchless staff account must be denied (locked to 0), not defaulted to Main Branch (1)');

        Auth::guard('admin')->logout();
    }

    public function test_a_staff_account_with_a_branch_is_still_locked_to_it(): void
    {
        $staff = $this->staffAt(self::HOME_BRANCH);

        Auth::guard('admin')->login($staff);

        $this->assertSame(self::HOME_BRANCH, AdminOrderAccess::lockedBranchId());

        Auth::guard('admin')->logout();
    }

    public function test_the_dashboard_label_says_no_branch_assigned_for_branchless_staff(): void
    {
        $staff = $this->staffAt(null);

        $html = $this->actingAs($staff, 'admin')->get('/admin/home')->getContent();

        $this->assertStringContainsString('No branch assigned', $html);
        $this->assertStringNotContainsString('Main Branch', $html);
    }

    // ══════════════════════════════════════════════════════════════
    // B10 — categories.branch_id is dropped (confirmed unused)
    // ══════════════════════════════════════════════════════════════

    public function test_the_categories_table_no_longer_has_a_branch_id_column(): void
    {
        $this->assertFalse(Schema::hasColumn('categories', 'branch_id'));
    }

    public function test_category_create_still_works_after_dropping_branch_id(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/add-category', [
                'name' => self::PREFIX . ' Post-Drop Category ' . uniqid(),
            ])
            ->assertRedirect(route('admin.add-category'));

        $this->assertTrue(true); // no exception escaped — the real assertion above
    }
}

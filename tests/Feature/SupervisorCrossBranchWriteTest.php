<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\MenuOption;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Branch parity audit Phase 3, Findings #2 + #4 — Sept 2026.
 *
 * The Sept 2026 order/record pass (AuditStaffBranchScopeTest,
 * StaffBranchScopeOnBranchRecordEndpointsTest) closed the "bare findOrFail()"
 * hole on Order and on stock-in/stock-out/toggle/help-requests/manual-order,
 * then explicitly left four more branch-owned write endpoints open:
 *
 *   PUT  admin/menu-items/{id}                 AdminController::updateMenuItem
 *   POST admin/menu-items/{menuItem}/ingredients AdminController::addIngredient
 *   POST admin/menu-options/assign/{menuItemId} AdminController::assignOptions
 *   PUT  admin/inventory/{id}                  AdminController::updateInventory
 *
 * updateMenuItem() was the sharpest of the four: because getSelectedBranch()
 * is always the supervisor's own branch (never 'all'), editing ANY menu item
 * id used to silently reassign that item's branch_id to the supervisor's own
 * branch on save — not just an authorisation gap but a cross-branch item
 * hijack.
 *
 * All four now resolve their record through App\Services\AdminOrderAccess —
 * the SAME rule the order endpoints use, not a second copy of it. A
 * branch-locked refusal is the app's standard 404, indistinguishable from a
 * nonexistent id.
 *
 * Finding #4 (recipe/ingredient branch integrity) is folded into the
 * addIngredient() tests below: storeNewMenuItem()/updateMenuItem() already
 * apply inventoryIsSelectableForBranch() to every recipe row, but
 * addIngredient() — the "add one more ingredient to an existing item"
 * endpoint — never did. That check is a general data-integrity rule (already
 * applied to every actor, not just branch-locked ones), so its tests are not
 * split by role the way the authorisation tests are.
 *
 * Every row this file creates carries the SUPXBR prefix and it runs inside
 * DatabaseTransactions, so nothing survives the run in pomida_db_testing.
 */
class SupervisorCrossBranchWriteTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'SUPXBR';
    private const HOME_BRANCH = 1;

    // ══════════════════ fixtures ══════════════════

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

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function otherBranch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Far Branch ' . uniqid(),
            'code'      => 'SXB' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function inventoryIn(int $branchId, float $quantity = 100): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => self::PREFIX . ' Flour ' . uniqid(),
            'item_code'       => 'SXB-' . strtoupper(substr(uniqid(), -10)),
            'category'        => self::PREFIX,
            'quantity'        => $quantity,
            'unit'            => 'kg',
            'low_stock_alert' => 1,
            'unit_cost'       => 10,
            'is_active'       => true,
        ]);
    }

    private function menuItemIn(int $branchId): MenuItem
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

    // ══════════════════ updateMenuItem — the sharp one ══════════════════

    public function test_a_supervisor_cannot_update_another_branchs_menu_item(): void
    {
        $far  = $this->otherBranch();
        $item = $this->menuItemIn($far->id);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/menu-items/' . $item->id, [
                'category_id' => $item->category_id,
                'name'        => self::PREFIX . ' Hijacked',
                'price'       => 999,
            ])
            ->assertNotFound();

        $fresh = $item->fresh();
        $this->assertSame($far->id, (int) $fresh->branch_id,
            'a refused update must not reassign the far item into the supervisor\'s own branch');
        $this->assertNotSame(self::PREFIX . ' Hijacked', $fresh->name);
    }

    public function test_a_supervisor_can_update_their_own_branchs_menu_item(): void
    {
        $item = $this->menuItemIn(self::HOME_BRANCH);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/menu-items/' . $item->id, [
                'category_id' => $item->category_id,
                'name'        => self::PREFIX . ' Renamed',
                'price'       => 150,
            ])
            ->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame(self::PREFIX . ' Renamed', $fresh->name);
        $this->assertSame(self::HOME_BRANCH, (int) $fresh->branch_id);
    }

    public function test_admin_can_update_any_branchs_menu_item(): void
    {
        $far  = $this->otherBranch();
        $item = $this->menuItemIn($far->id);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->put('/admin/menu-items/' . $item->id, [
                'category_id' => $item->category_id,
                'name'        => self::PREFIX . ' Admin Renamed',
                'price'       => 175,
            ])
            ->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame(self::PREFIX . ' Admin Renamed', $fresh->name);
        $this->assertSame($far->id, (int) $fresh->branch_id,
            'admin editing while viewing "all" must not move the item off its own branch');
    }

    // ══════════════════ addIngredient — authorisation ══════════════════

    public function test_a_supervisor_cannot_add_an_ingredient_to_another_branchs_menu_item(): void
    {
        $far       = $this->otherBranch();
        $item      = $this->menuItemIn($far->id);
        $inventory = $this->inventoryIn($far->id);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->post('/admin/menu-items/' . $item->id . '/ingredients', [
                'inventory_id'   => $inventory->id,
                'quantity_used'  => 1,
            ])
            ->assertNotFound();

        $this->assertSame(0, MenuItemIngredient::where('menu_item_id', $item->id)->count());
    }

    public function test_a_supervisor_can_add_an_ingredient_to_their_own_branchs_menu_item(): void
    {
        $item      = $this->menuItemIn(self::HOME_BRANCH);
        $inventory = $this->inventoryIn(self::HOME_BRANCH);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->post('/admin/menu-items/' . $item->id . '/ingredients', [
                'inventory_id'   => $inventory->id,
                'quantity_used'  => 1,
            ])
            ->assertRedirect();

        $this->assertSame(1, MenuItemIngredient::where('menu_item_id', $item->id)
            ->where('inventory_id', $inventory->id)->count());
    }

    public function test_admin_can_add_an_ingredient_to_any_branchs_menu_item(): void
    {
        $far       = $this->otherBranch();
        $item      = $this->menuItemIn($far->id);
        $inventory = $this->inventoryIn($far->id);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->post('/admin/menu-items/' . $item->id . '/ingredients', [
                'inventory_id'   => $inventory->id,
                'quantity_used'  => 1,
            ])
            ->assertRedirect();

        $this->assertSame(1, MenuItemIngredient::where('menu_item_id', $item->id)
            ->where('inventory_id', $inventory->id)->count());
    }

    // ══════════════ addIngredient — Finding #4: recipe/ingredient branch integrity ══════════════
    //
    // General data-integrity rule, applied to every actor (mirrors
    // storeNewMenuItem()/updateMenuItem()) — not role-gated, so no
    // admin-unrestricted pairing here: admin is refused exactly like anyone
    // else when the branches genuinely mismatch.

    public function test_a_recipe_row_cannot_reference_an_ingredient_from_a_different_branch(): void
    {
        $far            = $this->otherBranch();
        $item           = $this->menuItemIn(self::HOME_BRANCH);
        $farInventory   = $this->inventoryIn($far->id);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->post('/admin/menu-items/' . $item->id . '/ingredients', [
                'inventory_id'   => $farInventory->id,
                'quantity_used'  => 1,
            ])
            ->assertSessionHasErrors('inventory_id');

        $this->assertSame(0, MenuItemIngredient::where('menu_item_id', $item->id)->count(),
            'a cross-branch recipe/ingredient pairing must never be written — it silently breaks stock deduction');
    }

    public function test_a_recipe_row_can_reference_an_ingredient_from_the_same_branch(): void
    {
        $item      = $this->menuItemIn(self::HOME_BRANCH);
        $inventory = $this->inventoryIn(self::HOME_BRANCH);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->post('/admin/menu-items/' . $item->id . '/ingredients', [
                'inventory_id'   => $inventory->id,
                'quantity_used'  => 1,
            ])
            ->assertRedirect();

        $this->assertSame(1, MenuItemIngredient::where('menu_item_id', $item->id)
            ->where('inventory_id', $inventory->id)->count());
    }

    // ══════════════════ assignOptions ══════════════════

    public function test_a_supervisor_cannot_assign_options_to_another_branchs_menu_item(): void
    {
        $far    = $this->otherBranch();
        $item   = $this->menuItemIn($far->id);
        $option = $this->freshOption();

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, [
                'option_ids' => [$option->id],
            ])
            ->assertNotFound();

        $this->assertSame(0, $item->fresh()->options()->count());
    }

    public function test_a_supervisor_can_assign_options_to_their_own_branchs_menu_item(): void
    {
        $item   = $this->menuItemIn(self::HOME_BRANCH);
        $option = $this->freshOption();

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, [
                'option_ids' => [$option->id],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, $item->fresh()->options()->where('menu_options.id', $option->id)->count());
    }

    public function test_admin_can_assign_options_to_any_branchs_menu_item(): void
    {
        $far    = $this->otherBranch();
        $item   = $this->menuItemIn($far->id);
        $option = $this->freshOption();

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->postJson('/admin/menu-options/assign/' . $item->id, [
                'option_ids' => [$option->id],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, $item->fresh()->options()->where('menu_options.id', $option->id)->count());
    }

    // ══════════════════ updateInventory ══════════════════

    public function test_a_supervisor_cannot_update_another_branchs_inventory_definition(): void
    {
        $far  = $this->otherBranch();
        $item = $this->inventoryIn($far->id, 50);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/inventory/' . $item->id, [
                'item_name' => self::PREFIX . ' Hijacked',
                'item_code' => $item->item_code,
                'quantity'  => 999,
                'unit'      => $item->unit,
            ])
            ->assertNotFound();

        $fresh = $item->fresh();
        $this->assertSame(50.0, (float) $fresh->quantity,
            'a refused definition update must leave the far branch row untouched');
        $this->assertNotSame(self::PREFIX . ' Hijacked', $fresh->item_name);
    }

    public function test_a_supervisor_can_update_their_own_branchs_inventory_definition(): void
    {
        $item = $this->inventoryIn(self::HOME_BRANCH, 50);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/inventory/' . $item->id, [
                'item_name' => self::PREFIX . ' Renamed',
                'item_code' => $item->item_code,
                'quantity'  => 60,
                'unit'      => $item->unit,
            ])
            ->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame(self::PREFIX . ' Renamed', $fresh->item_name);
        $this->assertSame(60.0, (float) $fresh->quantity);
    }

    public function test_admin_can_update_any_branchs_inventory_definition(): void
    {
        $far  = $this->otherBranch();
        $item = $this->inventoryIn($far->id, 50);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->put('/admin/inventory/' . $item->id, [
                'item_name' => self::PREFIX . ' Admin Renamed',
                'item_code' => $item->item_code,
                'quantity'  => 70,
                'unit'      => $item->unit,
            ])
            ->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame(self::PREFIX . ' Admin Renamed', $fresh->item_name);
        $this->assertSame(70.0, (float) $fresh->quantity);
    }

    public function test_a_refused_menu_item_write_is_indistinguishable_from_a_missing_id(): void
    {
        $far       = $this->otherBranch();
        $item      = $this->menuItemIn($far->id);
        $manager   = $this->managerAt(self::HOME_BRANCH);
        $missingId = (int) MenuItem::max('id') + 99999;

        $foreign = $this->actingAs($manager, 'admin')->put('/admin/menu-items/' . $item->id, [
            'category_id' => $item->category_id,
            'name'        => 'x',
            'price'       => 1,
        ]);
        $missing = $this->actingAs($manager, 'admin')->put('/admin/menu-items/' . $missingId, [
            'category_id' => $item->category_id,
            'name'        => 'x',
            'price'       => 1,
        ]);

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode(),
            'a foreign-branch menu item id must be refused exactly the way a nonexistent one is');
    }
}

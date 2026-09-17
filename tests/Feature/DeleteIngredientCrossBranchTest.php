<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Follow-up to the Branch parity audit Phase 3, Findings #2 + #4 pass
 * (SupervisorCrossBranchWriteTest, commit 32dd132) — Sept 2026.
 *
 * That pass closed the bare-findOrFail() hole on updateMenuItem(),
 * addIngredient(), assignOptions() and updateInventory(), and flagged
 * deleteIngredient() as carrying the identical gap: it resolved the
 * ingredient with `where('id', $ingredient)->where('menu_item_id', $menuItem)
 * ->firstOrFail()` and never checked which branch the menu item belonged
 * to, so a branch-locked supervisor could delete any recipe row by typing
 * its menu item id into the route.
 *
 * deleteIngredient() now resolves the menu item through
 * App\Services\AdminOrderAccess::resolveRecordInScope() first — the same
 * call addIngredient() already makes — before looking up the ingredient
 * under it. A branch mismatch gets the same 404 as a nonexistent id.
 * Admin stays unrestricted, verified by test.
 *
 * Every row this file creates carries the DELIG prefix and it runs inside
 * DatabaseTransactions, so nothing survives the run in pomida_db_testing.
 */
class DeleteIngredientCrossBranchTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'DELIG';
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
            'code'      => 'DLB' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function inventoryIn(int $branchId): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => self::PREFIX . ' Flour ' . uniqid(),
            'item_code'       => 'DLI-' . strtoupper(substr(uniqid(), -10)),
            'category'        => self::PREFIX,
            'quantity'        => 100,
            'unit'            => 'kg',
            'low_stock_alert' => 1,
            'unit_cost'       => 10,
            'is_active'       => true,
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

    private function ingredientFor(MenuItem $item, Inventory $inventory): MenuItemIngredient
    {
        return MenuItemIngredient::create([
            'menu_item_id'   => $item->id,
            'inventory_id'   => $inventory->id,
            'quantity_used'  => 1,
        ]);
    }

    // ══════════════════ authorisation ══════════════════

    public function test_a_supervisor_cannot_delete_an_ingredient_from_another_branchs_menu_item(): void
    {
        $far        = $this->otherBranch();
        $item       = $this->menuItemIn($far->id);
        $inventory  = $this->inventoryIn($far->id);
        $ingredient = $this->ingredientFor($item, $inventory);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->delete('/admin/menu-items/' . $item->id . '/ingredients/' . $ingredient->id)
            ->assertNotFound();

        $this->assertNotNull(
            MenuItemIngredient::find($ingredient->id),
            'a refused delete must leave the far-branch recipe row untouched'
        );
    }

    public function test_a_supervisor_can_delete_an_ingredient_from_their_own_branchs_menu_item(): void
    {
        $item       = $this->menuItemIn(self::HOME_BRANCH);
        $inventory  = $this->inventoryIn(self::HOME_BRANCH);
        $ingredient = $this->ingredientFor($item, $inventory);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->delete('/admin/menu-items/' . $item->id . '/ingredients/' . $ingredient->id)
            ->assertRedirect();

        $this->assertNull(MenuItemIngredient::find($ingredient->id));
    }

    public function test_admin_can_delete_an_ingredient_from_any_branchs_menu_item(): void
    {
        $far        = $this->otherBranch();
        $item       = $this->menuItemIn($far->id);
        $inventory  = $this->inventoryIn($far->id);
        $ingredient = $this->ingredientFor($item, $inventory);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->delete('/admin/menu-items/' . $item->id . '/ingredients/' . $ingredient->id)
            ->assertRedirect();

        $this->assertNull(MenuItemIngredient::find($ingredient->id));
    }

    // ══════════════════ NULL branch_id (shared item) ══════════════════
    //
    // resolveRecordInScope() filters on branch_id = $locked, so a shared
    // (NULL branch_id) menu item does not match a locked supervisor's own
    // branch either — the same rule already applied to addIngredient(),
    // updateMenuItem(), assignOptions() and updateInventory().

    public function test_a_supervisor_cannot_delete_an_ingredient_from_a_shared_menu_item(): void
    {
        $item       = $this->menuItemIn(null);
        $inventory  = $this->inventoryIn(self::HOME_BRANCH);
        $ingredient = $this->ingredientFor($item, $inventory);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->delete('/admin/menu-items/' . $item->id . '/ingredients/' . $ingredient->id)
            ->assertNotFound();

        $this->assertNotNull(
            MenuItemIngredient::find($ingredient->id),
            'a refused delete must leave the shared-item recipe row untouched'
        );
    }

    public function test_admin_can_delete_an_ingredient_from_a_shared_menu_item(): void
    {
        $item       = $this->menuItemIn(null);
        $inventory  = $this->inventoryIn(self::HOME_BRANCH);
        $ingredient = $this->ingredientFor($item, $inventory);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->delete('/admin/menu-items/' . $item->id . '/ingredients/' . $ingredient->id)
            ->assertRedirect();

        $this->assertNull(MenuItemIngredient::find($ingredient->id));
    }

    public function test_a_refused_ingredient_delete_is_indistinguishable_from_a_missing_id(): void
    {
        $far        = $this->otherBranch();
        $item       = $this->menuItemIn($far->id);
        $inventory  = $this->inventoryIn($far->id);
        $ingredient = $this->ingredientFor($item, $inventory);
        $manager    = $this->managerAt(self::HOME_BRANCH);
        $missingId  = (int) MenuItem::max('id') + 99999;

        $foreign = $this->actingAs($manager, 'admin')
            ->delete('/admin/menu-items/' . $item->id . '/ingredients/' . $ingredient->id);
        $missing = $this->actingAs($manager, 'admin')
            ->delete('/admin/menu-items/' . $missingId . '/ingredients/' . $ingredient->id);

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode(),
            'a foreign-branch menu item id must be refused exactly the way a nonexistent one is');
    }
}

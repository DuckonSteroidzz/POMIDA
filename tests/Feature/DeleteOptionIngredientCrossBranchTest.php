<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\MenuOption;
use App\Models\MenuOptionIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 3b F6 — deleteOptionIngredient() had no branch check at all, while
 * its sibling addOptionIngredient() already refuses (422) a branch-locked
 * caller trying to link an ingredient from another branch's inventory.
 * DeleteIngredientCrossBranchTest closed the equivalent hole on the
 * menu-item ingredient path (2026-09, commit 32dd132's follow-up); this is
 * the option path it did not cover.
 *
 * Menu options are GLOBAL (no branch_id column), so there is no parent
 * record to scope through the way deleteIngredient() scopes via the menu
 * item's own branch_id — the scope has to come from the linked INVENTORY
 * row instead, exactly as addOptionIngredient() already does it. Before this
 * fix, a branch-1 supervisor could delete a recipe row linking a
 * company-wide add-on to branch-2's inventory, silently stopping that
 * branch's deduction for it with nothing on screen to say so.
 *
 * Every row this file creates is fixture data cleaned up by
 * DatabaseTransactions; nothing survives the run in pomida_db_testing.
 */
class DeleteOptionIngredientCrossBranchTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'DELOPT';
    private const HOME_BRANCH = 1;

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
            'code'      => 'DOB' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function inventoryIn(int $branchId): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => self::PREFIX . ' Cheese ' . uniqid(),
            'item_code'       => 'DOI-' . strtoupper(substr(uniqid(), -10)),
            'category'        => self::PREFIX,
            'quantity'        => 100,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 5,
            'is_active'       => true,
        ]);
    }

    /** Menu options are global — no branch_id to pass. */
    private function menuOption(): MenuOption
    {
        return MenuOption::create([
            'name'             => self::PREFIX . ' Add-on ' . uniqid(),
            'additional_price' => 15,
            'is_active'        => true,
            'display_order'    => 0,
        ]);
    }

    private function ingredientFor(MenuOption $option, Inventory $inventory): MenuOptionIngredient
    {
        return MenuOptionIngredient::create([
            'menu_option_id' => $option->id,
            'inventory_id'   => $inventory->id,
            'quantity_used'  => 1,
        ]);
    }

    // ══════════════════ authorisation ══════════════════

    public function test_a_supervisor_cannot_delete_an_option_ingredient_pointing_at_another_branchs_inventory(): void
    {
        $far        = $this->otherBranch();
        $option     = $this->menuOption();
        $inventory  = $this->inventoryIn($far->id);
        $ingredient = $this->ingredientFor($option, $inventory);

        $response = $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->delete('/admin/menu-options/' . $option->id . '/ingredients/' . $ingredient->id);

        // A plain (non-JSON) request gets addOptionIngredient()'s own
        // non-JSON refusal shape: redirect back with a flashed field error —
        // NOT the 404 the menu-item ingredient path uses, because there is
        // no parent record here for resolveRecordInScope() to 404 on. The
        // JSON caller gets 422 instead — see the dedicated test below.
        $response->assertRedirect();
        $response->assertSessionHasErrors('inventory_id');

        $this->assertNotNull(
            MenuOptionIngredient::find($ingredient->id),
            'a refused delete must leave the far-branch recipe row untouched'
        );
    }

    public function test_a_supervisor_can_delete_an_option_ingredient_pointing_at_their_own_branchs_inventory(): void
    {
        $option     = $this->menuOption();
        $inventory  = $this->inventoryIn(self::HOME_BRANCH);
        $ingredient = $this->ingredientFor($option, $inventory);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->delete('/admin/menu-options/' . $option->id . '/ingredients/' . $ingredient->id)
            ->assertRedirect();

        $this->assertNull(MenuOptionIngredient::find($ingredient->id));
    }

    public function test_admin_can_delete_an_option_ingredient_pointing_at_any_branchs_inventory(): void
    {
        $far        = $this->otherBranch();
        $option     = $this->menuOption();
        $inventory  = $this->inventoryIn($far->id);
        $ingredient = $this->ingredientFor($option, $inventory);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->delete('/admin/menu-options/' . $option->id . '/ingredients/' . $ingredient->id)
            ->assertRedirect();

        $this->assertNull(MenuOptionIngredient::find($ingredient->id));
    }

    /**
     * The JSON caller (the recipe editor's fetch() path) must get the same
     * verdict as the plain form-post caller — same shape as
     * addOptionIngredient()'s own two response branches.
     */
    public function test_a_json_caller_is_refused_with_the_same_422_shape(): void
    {
        $far        = $this->otherBranch();
        $option     = $this->menuOption();
        $inventory  = $this->inventoryIn($far->id);
        $ingredient = $this->ingredientFor($option, $inventory);

        $response = $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->deleteJson('/admin/menu-options/' . $option->id . '/ingredients/' . $ingredient->id);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);

        $this->assertNotNull(MenuOptionIngredient::find($ingredient->id));
    }

    public function test_owner_on_a_specific_branch_selection_can_still_delete_across_branches(): void
    {
        // The owner has no lockedBranchId() at all, regardless of the
        // "Viewing:" picker — that picker filters LISTS, it does not gate
        // individual writes. Confirms the fix did not accidentally start
        // reading the session picker as a lock.
        $far        = $this->otherBranch();
        $option     = $this->menuOption();
        $inventory  = $this->inventoryIn($far->id);
        $ingredient = $this->ingredientFor($option, $inventory);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => self::HOME_BRANCH])
            ->delete('/admin/menu-options/' . $option->id . '/ingredients/' . $ingredient->id)
            ->assertRedirect();

        $this->assertNull(MenuOptionIngredient::find($ingredient->id));
    }
}

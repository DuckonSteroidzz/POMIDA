<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryDeductionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Inventory's delete button used to be a single, irreversible removal —
 * $item->delete() straight away, cascading through menu_item_ingredients /
 * menu_option_ingredients and (undiscovered until this pass — see the
 * migration docblock) through stock_movements too, with no way back.
 *
 * It is now two stages:
 *   1. DELETE /admin/inventory/{id}      — archives. Off the normal list,
 *                                          fully recoverable, and everything
 *                                          that already pointed at it (a
 *                                          live recipe, its stock movement
 *                                          history) keeps working exactly as
 *                                          before.
 *   2. DELETE /admin/inventory/{id}/force — the actual, irreversible delete,
 *                                          only reachable from Deleted Items
 *                                          on a row already through stage 1.
 *
 * THE CENTRAL RISK THIS FILE GUARDS
 * ----------------------------------
 * Archiving must not change what a LIVE recipe sees. Inventory does not use
 * App\Models\Concerns\Archivable's global scope (see the migration's and the
 * model's own docblocks for why) — this file proves that choice: an archived
 * ingredient must still be found by InventoryDeductionService and still
 * deduct correctly, because a global scope here would make
 * deductWithLock() throw "Inventory item #N is missing" on every order for
 * a menu item whose ingredient the admin merely archived, not fixed.
 */
class InventoryTwoStageDeleteTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->first();
    }

    private function makeItem(array $attrs = []): Inventory
    {
        return Inventory::create(array_merge([
            'branch_id'   => 1,
            'item_name'   => 'IVD Test Stock ' . uniqid(),
            'item_code'   => 'IVD-' . strtoupper(substr(uniqid(), -8)),
            'unit'        => 'pcs',
            'quantity'    => 10,
            'unit_cost'   => 5,
            'is_active'   => true,
        ], $attrs));
    }

    // ══════════ stage one: delete = archive, not gone ══════════

    public function test_deleting_an_item_moves_it_off_the_normal_list_and_into_deleted_items(): void
    {
        $item = $this->makeItem();

        $response = $this->actingAs($this->admin(), 'admin')
            ->delete('/admin/inventory/' . $item->id);

        $response->assertRedirect(route('admin.inventory'));
        $response->assertSessionHasNoErrors();

        $this->assertNull(Inventory::notArchived()->find($item->id), 'must leave the normal Inventory list');

        $archived = Inventory::onlyArchived()->find($item->id);
        $this->assertNotNull($archived, 'the row must survive, recoverable');
        $this->assertNotNull($archived->archived_at);

        // Two requests: the first carries the delete's own flash success
        // message, which legitimately names the item — assertDontSee has to
        // land on a page where that one-time flash has already been consumed.
        $this->actingAs($this->admin(), 'admin')->get('/admin/inventory');
        $page = $this->actingAs($this->admin(), 'admin')->get('/admin/inventory');
        $page->assertDontSee($item->item_name);
    }

    public function test_deleted_item_appears_on_the_deleted_items_page(): void
    {
        $item = $this->makeItem();
        $this->actingAs($this->admin(), 'admin')->delete('/admin/inventory/' . $item->id);

        $page = $this->actingAs($this->admin(), 'admin')->get(route('admin.inventory.deleted'));
        $page->assertOk();
        $page->assertSee($item->item_name);
    }

    public function test_a_deleted_items_link_shows_the_count_on_the_main_page(): void
    {
        $item = $this->makeItem();
        $this->actingAs($this->admin(), 'admin')->delete('/admin/inventory/' . $item->id);

        $page = $this->actingAs($this->admin(), 'admin')->get('/admin/inventory');
        $page->assertOk();
        $page->assertSee(route('admin.inventory.deleted'), false);
    }

    // ══════════ restore ══════════

    public function test_restoring_puts_it_back_on_the_normal_list_with_its_original_code(): void
    {
        $item = $this->makeItem(['item_code' => 'IVD-ORIGINAL-CODE']);
        $this->actingAs($this->admin(), 'admin')->delete('/admin/inventory/' . $item->id);

        $response = $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.inventory.restore', $item->id));

        $response->assertRedirect(route('admin.inventory'));
        $response->assertSessionHasNoErrors();

        $restored = Inventory::notArchived()->find($item->id);
        $this->assertNotNull($restored, 'must be back on the normal list');
        $this->assertNull($restored->archived_at);
        $this->assertSame('IVD-ORIGINAL-CODE', $restored->item_code, 'the original code must come back');
        $this->assertNull($restored->archived_item_code);
    }

    /**
     * item_code is NOT NULL and globally unique with no partial index (see
     * InventoryItemCodeUniqueTest's investigation notes and the migration
     * docblock). Archiving therefore parks and mangles the code so a NEW item
     * can freely reuse it — this is the case that used to be impossible
     * before this pass ("an archived item still permanently reserves its
     * code").
     */
    public function test_a_new_item_can_reuse_a_deleted_items_code(): void
    {
        $item = $this->makeItem(['item_code' => 'IVD-REUSABLE']);
        $this->actingAs($this->admin(), 'admin')->delete('/admin/inventory/' . $item->id);

        $response = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 1])
            ->post('/admin/inventory', [
                'item_name' => 'IVD New Item Same Code',
                'item_code' => 'IVD-REUSABLE',
                'quantity'  => 1,
                'unit'      => 'pcs',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertTrue(
            Inventory::notArchived()->where('item_code', 'IVD-REUSABLE')->exists(),
            'the new item must have been created with the freed-up code'
        );
    }

    /**
     * If that reused code is now live on the NEW item, restoring the OLD
     * archived one cannot also claim it — it must keep its mangled code
     * rather than crash or silently steal the code back.
     */
    public function test_restoring_keeps_the_mangled_code_when_the_original_was_reused_meanwhile(): void
    {
        $item = $this->makeItem(['item_code' => 'IVD-CONTESTED']);
        $this->actingAs($this->admin(), 'admin')->delete('/admin/inventory/' . $item->id);

        // Someone reuses the freed code on a brand new item.
        Inventory::create([
            'branch_id' => 1,
            'item_name' => 'IVD New Claimant',
            'item_code' => 'IVD-CONTESTED',
            'unit'      => 'pcs',
            'quantity'  => 1,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.inventory.restore', $item->id));

        $response->assertRedirect(route('admin.inventory'));
        $response->assertSessionHasNoErrors();

        $restored = Inventory::notArchived()->find($item->id);
        $this->assertNotNull($restored);
        $this->assertNotSame('IVD-CONTESTED', $restored->item_code, 'must not steal the code back from the new item');
        $this->assertTrue(
            Inventory::notArchived()->where('item_code', 'IVD-CONTESTED')->where('id', '!=', $item->id)->exists(),
            'the new claimant must keep the code'
        );
    }

    public function test_restoring_something_not_in_deleted_items_is_a_friendly_error_not_a_crash(): void
    {
        $item = $this->makeItem(); // never archived

        $response = $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.inventory.restore', $item->id));

        $response->assertRedirect(route('admin.inventory.deleted'));
        $response->assertSessionHasErrors('error');
        $this->assertNull(Inventory::onlyArchived()->find($item->id));
    }

    // ══════════ permanent delete ══════════

    public function test_permanent_delete_actually_removes_the_row(): void
    {
        $item = $this->makeItem();
        $this->actingAs($this->admin(), 'admin')->delete('/admin/inventory/' . $item->id);

        $response = $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.inventory.force-delete', $item->id));

        $response->assertRedirect(route('admin.inventory.deleted'));
        $response->assertSessionHasNoErrors();

        $this->assertNull(Inventory::find($item->id));
        $this->assertNull(Inventory::onlyArchived()->find($item->id));
        $this->assertNull(Inventory::notArchived()->find($item->id));
    }

    public function test_permanent_delete_is_only_reachable_on_an_item_already_deleted(): void
    {
        $item = $this->makeItem(); // never archived — still live

        $response = $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.inventory.force-delete', $item->id));

        $response->assertRedirect(route('admin.inventory.deleted'));
        $response->assertSessionHasErrors('error');
        $this->assertNotNull(Inventory::notArchived()->find($item->id), 'a live item must survive an errant force-delete call');
    }

    /**
     * THE GAP THIS PASS CLOSES. stock_movements.inventory_id was ON DELETE
     * CASCADE (confirmed against the original migration) with no warning
     * anywhere in the old deleteInventory() — a hard delete silently erased
     * the item's whole stock-in/stock-out history. It is now ON DELETE SET
     * NULL, and the permanent-delete action stamps deleted_item_name onto
     * those rows first, so the history survives and still says what it was
     * for.
     */
    public function test_permanent_delete_keeps_stock_movement_history_instead_of_erasing_it(): void
    {
        $item = $this->makeItem(['item_name' => 'IVD Movement History Item']);

        StockMovement::create([
            'inventory_id'   => $item->id,
            'movement_type'  => 'in',
            'amount'         => 5,
            'quantity_after' => 15,
            'reason'         => 'IVD test movement',
            'source'         => 'manual',
        ]);

        $this->actingAs($this->admin(), 'admin')->delete('/admin/inventory/' . $item->id);
        $this->actingAs($this->admin(), 'admin')->delete(route('admin.inventory.force-delete', $item->id));

        $movement = StockMovement::where('reason', 'IVD test movement')->first();
        $this->assertNotNull($movement, 'the movement row must survive the inventory row it pointed at being deleted');
        $this->assertNull($movement->inventory_id);
        $this->assertSame('IVD Movement History Item', $movement->deleted_item_name);
    }

    public function test_stock_movements_log_still_shows_the_item_name_after_permanent_delete(): void
    {
        $item = $this->makeItem(['item_name' => 'IVD Logged Item']);

        StockMovement::create([
            'inventory_id'   => $item->id,
            'movement_type'  => 'out',
            'amount'         => 2,
            'quantity_after' => 8,
            'reason'         => 'IVD logged movement',
            'source'         => 'manual',
        ]);

        $this->actingAs($this->admin(), 'admin')->delete('/admin/inventory/' . $item->id);
        $this->actingAs($this->admin(), 'admin')->delete(route('admin.inventory.force-delete', $item->id));

        $page = $this->actingAs($this->admin(), 'admin')->get('/admin/inventory');
        $page->assertOk();
        $page->assertSee('IVD Logged Item');
    }

    /**
     * Recipe links stay CASCADE, unchanged existing policy from before this
     * pass (see forceDeleteInventory()'s docblock) — but the admin must be
     * told, not surprised.
     */
    public function test_permanent_delete_of_an_item_still_in_a_recipe_removes_the_link_and_says_so(): void
    {
        $item = $this->makeItem();
        $menuItem = MenuItem::create([
            'name'        => 'IVD Recipe Dish ' . uniqid(),
            'price'       => 100,
            'category_id' => \App\Models\Category::query()->value('id'),
            'is_available' => true,
        ]);
        MenuItemIngredient::create([
            'menu_item_id'  => $menuItem->id,
            'inventory_id'  => $item->id,
            'quantity_used' => 1,
        ]);

        $this->actingAs($this->admin(), 'admin')->delete('/admin/inventory/' . $item->id);

        $response = $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.inventory.force-delete', $item->id));

        $response->assertSessionHasNoErrors();
        $this->assertStringContainsString('recipe link', session('success'));
        $this->assertSame(0, MenuItemIngredient::where('menu_item_id', $menuItem->id)->count());
    }

    // ══════════ the central risk: archiving must not break a live recipe ══════════

    /**
     * An ingredient still used by an active recipe must keep deducting
     * normally once merely archived (stage one) — this is what makes it safe
     * to archive freely instead of having to check every recipe first. Proves
     * Inventory's deliberate choice not to use a global scope: a global scope
     * would hide this row from InventoryDeductionService's plain
     * Inventory::whereIn()->lockForUpdate() lookup and throw "Inventory item
     * #N is missing" here instead of deducting.
     */
    public function test_an_archived_ingredient_still_deducts_normally_for_its_recipe(): void
    {
        $item = $this->makeItem(['quantity' => 20]);
        $menuItem = MenuItem::create([
            'name'         => 'IVD Still Sellable Dish ' . uniqid(),
            'price'        => 150,
            'category_id'  => \App\Models\Category::query()->value('id'),
            'is_available' => true,
        ]);
        MenuItemIngredient::create([
            'menu_item_id'  => $menuItem->id,
            'inventory_id'  => $item->id,
            'quantity_used' => 3,
        ]);

        $this->actingAs($this->admin(), 'admin')->delete('/admin/inventory/' . $item->id);
        $this->assertTrue($item->fresh()->isArchived());

        $service = app(InventoryDeductionService::class);
        $needs = $service->requirementsForLine($menuItem->fresh(), 2, []);

        $this->assertArrayHasKey($item->id, $needs, 'an archived ingredient must still be resolvable for deduction math');
        $this->assertEquals(6.0, $needs[$item->id]);

        $shortfalls = $service->cartShortfalls([
            ['menu_item' => $menuItem->fresh(), 'quantity' => 2, 'selected_option_ids' => []],
        ], 1);
        $this->assertEmpty($shortfalls, 'enough stock exists, archived or not — it must not be treated as missing');
    }

    // ══════════ CSV export stays in sync ══════════

    public function test_export_csv_does_not_contain_deleted_items(): void
    {
        $item = $this->makeItem(['item_name' => 'IVD Should Not Export']);
        $this->actingAs($this->admin(), 'admin')->delete('/admin/inventory/' . $item->id);

        $response = $this->actingAs($this->admin(), 'admin')->get('/admin/inventory/export');
        $csv = $response->streamedContent();

        $this->assertStringNotContainsString('IVD Should Not Export', $csv);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\MenuItemSize;
use App\Models\MenuItemSizeIngredient;
use App\Models\MenuOption;
use App\Models\MenuOptionIngredient;
use App\Models\User;
use App\Services\InventoryUsage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tester report (2026-11-01): an inventory ingredient (Egg) was deleted while
 * a menu item still used it, and the item went on showing Egg.
 *
 * Both stages — Delete (archive) and Permanently Delete — now refuse an
 * ingredient any recipe still names: menu item recipes, size recipes, add-on
 * recipes (and the old single-ingredient link). The server is the gate; the
 * pop-up is its voice. Rows are created inside DatabaseTransactions and carry
 * the IUB prefix.
 */
class InventoryInUseDeleteBlockTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function inventory(array $attrs = []): Inventory
    {
        return Inventory::create(array_merge([
            'branch_id' => 1,
            'item_name' => 'IUB Egg ' . uniqid(),
            'item_code' => 'IUB-' . strtoupper(substr(uniqid(), -8)),
            'unit'      => 'pcs',
            'quantity'  => 1.5,
            'unit_cost' => 5,
            'is_active' => true,
        ], $attrs));
    }

    private function menuItem(string $name): MenuItem
    {
        return MenuItem::create([
            'name'         => 'IUB ' . $name . ' ' . substr(uniqid(), -5),
            'price'        => 100,
            'category_id'  => Category::query()->value('id'),
            'branch_id'    => 1,
            'is_available' => true,
        ]);
    }

    private function inMenuItem(Inventory $inv, MenuItem $item): void
    {
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);
    }

    private function archiveViaUi(Inventory $inv)
    {
        return $this->actingAs($this->admin(), 'admin')->delete('/admin/inventory/' . $inv->id);
    }

    private function forceViaUi(Inventory $inv)
    {
        return $this->actingAs($this->admin(), 'admin')->delete(route('admin.inventory.force-delete', $inv->id));
    }

    // ══════════ stage one: Delete ══════════

    public function test_delete_is_blocked_while_a_menu_item_recipe_uses_the_ingredient(): void
    {
        $egg  = $this->inventory();
        $item = $this->menuItem('Carbonara');
        $this->inMenuItem($egg, $item);

        $response = $this->archiveViaUi($egg);

        $response->assertRedirect(route('admin.inventory'));
        $response->assertSessionHas('inventory_in_use');
        $this->assertSame(
            'You cannot delete this item because it is currently being used by the following: '
            . $item->name
            . '. Please remove it from these items first before attempting to delete it.',
            session('inventory_in_use')['message']
        );
        $this->assertFalse($egg->fresh()->isArchived(), 'nothing was archived');
        $this->assertSame(1, MenuItemIngredient::where('inventory_id', $egg->id)->count(), 'the recipe is untouched');
    }

    public function test_delete_is_blocked_by_a_size_recipe_and_names_the_size(): void
    {
        $egg  = $this->inventory();
        $item = $this->menuItem('Pasta');
        $size = MenuItemSize::create(['menu_item_id' => $item->id, 'name' => 'Large', 'price' => 150, 'display_order' => 2, 'is_active' => true]);
        MenuItemSizeIngredient::create(['menu_item_size_id' => $size->id, 'inventory_id' => $egg->id, 'quantity' => 2]);

        $this->archiveViaUi($egg)->assertSessionHas('inventory_in_use');

        $this->assertStringContainsString($item->name . ' (Large)', session('inventory_in_use')['message']);
        $this->assertFalse($egg->fresh()->isArchived());
    }

    public function test_delete_is_blocked_by_an_add_on_recipe_and_names_the_add_on(): void
    {
        $egg    = $this->inventory();
        $option = MenuOption::create(['name' => 'IUB Extra Egg ' . substr(uniqid(), -5), 'additional_price' => 10, 'display_order' => 1, 'is_active' => true]);
        MenuOptionIngredient::create(['menu_option_id' => $option->id, 'inventory_id' => $egg->id, 'quantity_used' => 1]);

        $this->archiveViaUi($egg)->assertSessionHas('inventory_in_use');

        $this->assertStringContainsString('Add-on: ' . $option->name, session('inventory_in_use')['message']);
        $this->assertFalse($egg->fresh()->isArchived());
    }

    public function test_delete_is_blocked_by_the_old_single_ingredient_link(): void
    {
        $egg  = $this->inventory();
        $item = $this->menuItem('Legacy');
        $item->forceFill(['inventory_item_id' => $egg->id, 'inventory_amount_used' => 1])->save();

        $this->archiveViaUi($egg)->assertSessionHas('inventory_in_use');

        $this->assertFalse($egg->fresh()->isArchived());
    }

    public function test_every_user_is_listed_once_in_one_message(): void
    {
        $egg = $this->inventory();
        $a   = $this->menuItem('Alpha');
        $b   = $this->menuItem('Bravo');
        $this->inMenuItem($egg, $a);
        $this->inMenuItem($egg, $b);
        $size = MenuItemSize::create(['menu_item_id' => $b->id, 'name' => 'Regular', 'price' => 90, 'display_order' => 1, 'is_active' => true]);
        MenuItemSizeIngredient::create(['menu_item_size_id' => $size->id, 'inventory_id' => $egg->id, 'quantity' => 1]);

        $this->archiveViaUi($egg);

        $this->assertSame(
            [$a->name, $b->name, $b->name . ' (Regular)'],
            InventoryUsage::usedBy($egg->id)
        );
    }

    public function test_an_archived_menu_item_still_blocks_and_is_marked_archived(): void
    {
        $egg  = $this->inventory();
        $item = $this->menuItem('Retired');
        $this->inMenuItem($egg, $item);
        $item->archive();

        $this->assertSame([$item->name . ' (archived)'], InventoryUsage::usedBy($egg->id));
        $this->archiveViaUi($egg)->assertSessionHas('inventory_in_use');
        $this->assertFalse($egg->fresh()->isArchived());
    }

    public function test_an_unused_ingredient_still_deletes_normally(): void
    {
        $egg = $this->inventory();

        $this->archiveViaUi($egg)->assertSessionMissing('inventory_in_use')->assertSessionHas('success');

        $this->assertTrue($egg->fresh()->isArchived());
    }

    public function test_it_deletes_once_the_recipe_line_is_removed(): void
    {
        $egg  = $this->inventory();
        $item = $this->menuItem('Temporary');
        $this->inMenuItem($egg, $item);

        $this->archiveViaUi($egg)->assertSessionHas('inventory_in_use');

        MenuItemIngredient::where('inventory_id', $egg->id)->delete();

        $this->archiveViaUi($egg)->assertSessionMissing('inventory_in_use');
        $this->assertTrue($egg->fresh()->isArchived());
    }

    // ══════════ stage two: Permanently Delete ══════════

    public function test_permanent_delete_is_blocked_for_an_already_archived_ingredient_still_in_a_recipe(): void
    {
        $egg  = $this->inventory();
        $item = $this->menuItem('Stranded');
        $this->inMenuItem($egg, $item);
        $egg->archive(); // the state old data is in

        $this->forceViaUi($egg)->assertSessionHas('inventory_in_use');

        $this->assertNotNull(Inventory::find($egg->id), 'still there');
        $this->assertSame(1, MenuItemIngredient::where('inventory_id', $egg->id)->count(), 'recipe line kept, not cascaded away');
    }

    public function test_permanent_delete_is_blocked_by_a_size_recipe_too(): void
    {
        $egg  = $this->inventory();
        $item = $this->menuItem('SizedPerm');
        $size = MenuItemSize::create(['menu_item_id' => $item->id, 'name' => 'Large', 'price' => 150, 'display_order' => 2, 'is_active' => true]);
        MenuItemSizeIngredient::create(['menu_item_size_id' => $size->id, 'inventory_id' => $egg->id, 'quantity' => 2]);
        $egg->archive();

        $this->forceViaUi($egg)->assertSessionHas('inventory_in_use');

        $this->assertNotNull(Inventory::find($egg->id));
        $this->assertSame(1, MenuItemSizeIngredient::where('inventory_id', $egg->id)->count());
    }

    public function test_permanent_delete_of_an_unused_archived_ingredient_still_works(): void
    {
        $egg = $this->inventory();
        $egg->archive();

        $this->forceViaUi($egg)->assertSessionMissing('inventory_in_use')->assertSessionHas('success');

        $this->assertNull(Inventory::find($egg->id));
    }

    // ══════════ the pages ══════════

    public function test_inventory_page_embeds_the_usage_so_the_popup_opens_without_a_trip(): void
    {
        $egg  = $this->inventory();
        $item = $this->menuItem('Embedded');
        $this->inMenuItem($egg, $item);

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 1])
            ->get(route('admin.inventory'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="inUseModal"', $html);
        $this->assertStringContainsString($item->name, $html);
        $this->assertStringContainsString('var ivInUse', $html);
    }

    public function test_the_refusal_pops_up_after_a_blocked_submit(): void
    {
        $egg  = $this->inventory();
        $item = $this->menuItem('FlashShown');
        $this->inMenuItem($egg, $item);

        $this->archiveViaUi($egg);

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 1])
            ->get(route('admin.inventory'))
            ->getContent();

        $this->assertStringContainsString('You cannot delete this item because it is currently being used by the following: ' . $item->name, $html);
    }

    public function test_archive_page_flags_an_archived_ingredient_still_used_and_changes_nothing(): void
    {
        $egg  = $this->inventory();
        $item = $this->menuItem('ArchivedUse');
        $this->inMenuItem($egg, $item);
        $egg->archive();

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 1])
            ->get(route('admin.inventory.deleted'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-testid="archived-in-use"', $html);
        $this->assertStringContainsString('Archived ingredient still used by: ' . $item->name, $html);
        $this->assertTrue($egg->fresh()->isArchived(), 'viewing does not touch the row');
    }

    public function test_menu_item_recipe_editor_warns_on_an_archived_ingredient_line(): void
    {
        $egg  = $this->inventory();
        $item = $this->menuItem('EditorWarn');
        $this->inMenuItem($egg, $item);
        $egg->archive();

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 1])
            ->get('/admin/menu-items')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-testid="recipe-archived-warning"', $html);
        $this->assertStringContainsString('Archived ingredient — replace it', $html);
    }

    public function test_a_live_ingredient_line_shows_no_archived_warning(): void
    {
        $egg  = $this->inventory();
        $item = $this->menuItem('EditorClean');
        $this->inMenuItem($egg, $item);

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 1])
            ->get('/admin/menu-items')
            ->getContent();

        $this->assertStringNotContainsString('Archived ingredient — replace it', preg_replace('/<script.*?<\/script>/s', '', $html));
    }

    public function test_the_add_on_editor_payload_marks_an_archived_ingredient(): void
    {
        $egg    = $this->inventory();
        $option = MenuOption::create(['name' => 'IUB Opt ' . substr(uniqid(), -5), 'additional_price' => 10, 'display_order' => 1, 'is_active' => true]);
        MenuOptionIngredient::create(['menu_option_id' => $option->id, 'inventory_id' => $egg->id, 'quantity_used' => 1]);
        $egg->archive();

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 1])
            ->get('/admin/menu-options')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('&quot;archived&quot;:true', $html);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The "Legacy Single-Ingredient Link" block is invisible in the Add/Edit Menu
 * Item modal, but its two fields are still in the DOM (hidden) so the form
 * posts exactly what it always did.
 *
 * Why hide instead of delete: updateMenuItem() writes inventory_item_id and
 * inventory_amount_used straight from the request ("?? null" / "?? 0"). If the
 * fields stopped being posted, saving ANY old item that still carries a legacy
 * link would silently clear it. The round-trip tests pin that.
 */
class MenuItemLegacyLinkHiddenTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->firstOrFail();
    }

    private function asAdminInBranch1(): self
    {
        $this->actingAs($this->admin(), 'admin')->withSession(['selected_branch_id' => 1]);

        return $this;
    }

    private function inventory(): Inventory
    {
        return Inventory::create([
            'branch_id'       => 1,
            'item_name'       => 'LLH Ingredient ' . uniqid(),
            'item_code'       => 'LLH-' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => 100,
            'unit'            => 'g',
            'low_stock_alert' => 10,
            'unit_cost'       => 1.00,
            'is_active'       => true,
        ]);
    }

    private function item(array $attrs = []): MenuItem
    {
        return MenuItem::create(array_merge([
            'category_id' => DB::table('categories')->min('id'),
            'branch_id'   => 1,
            'name'        => 'LLH Item ' . uniqid(),
            'price'       => 100,
            'cost'        => 0,
        ], $attrs));
    }

    /** What the edit form posts for an untouched item: the values the page itself rendered. */
    private function untouchedPostFor(MenuItem $item, array $hidden): array
    {
        return array_merge([
            'category_id' => $item->category_id,
            'name'        => $item->name,
            'price'       => $item->price,
            'cost'        => $item->cost,
            'branch_id'   => $item->branch_id,
        ], $hidden);
    }

    // ══════════ markup ══════════

    public function test_the_visible_legacy_section_is_gone_but_the_fields_remain_hidden(): void
    {
        $html = $this->asAdminInBranch1()->get('/admin/menu-items')->assertOk()->getContent();

        foreach ([
            'Legacy Single-Ingredient Link',
            'Amount Used per Order',
            'Leave empty for new items',
            'only used if no Recipe Ingredients are set',
        ] as $gone) {
            $this->assertFalse(str_contains($html, $gone), "visible legacy wording still present: {$gone}");
        }

        // Same names and ids, in a hidden wrapper, in the one shared Add/Edit modal.
        $this->assertMatchesRegularExpression(
            '/<div id="legacyLinkFields"[^>]*\bhidden\b[^>]*>.*?name="inventory_item_id" id="itemInventory".*?name="inventory_amount_used" id="itemAmountUsed"[^>]*>\s*<\/div>/s',
            $html,
            'the legacy fields are not inside a hidden wrapper'
        );
        $this->assertSame(1, substr_count($html, 'name="inventory_item_id"'));
        $this->assertSame(1, substr_count($html, 'name="inventory_amount_used"'));

        // The edit JS still fills them from the row's data attributes.
        $this->assertStringContainsString("document.getElementById('itemInventory').value = btn.dataset.inventory", $html);
        $this->assertStringContainsString("document.getElementById('itemAmountUsed').value = btn.dataset.amountUsed", $html);
    }

    public function test_the_edit_button_still_carries_the_legacy_values_for_the_fill(): void
    {
        $inv = $this->inventory();
        $item = $this->item(['inventory_item_id' => $inv->id, 'inventory_amount_used' => 2.5]);

        $html = $this->asAdminInBranch1()->get('/admin/menu-items')->assertOk()->getContent();

        $start = strpos($html, 'data-id="' . $item->id . '"');
        $this->assertNotFalse($start, 'the new item has no edit button on the page');

        $button = substr($html, $start, 3000);
        $button = substr($button, 0, strpos($button, 'onclick="openEditModal(this)"') ?: 3000);

        $this->assertTrue(str_contains($button, 'data-inventory="' . $inv->id . '"'), 'no data-inventory: ' . substr($button, -400));
        $this->assertTrue((bool) preg_match('/data-amount-used="2\.5\d*"/', $button), 'no data-amount-used: ' . substr($button, -400));
    }

    // ══════════ round trip ══════════

    public function test_saving_an_item_with_a_legacy_link_untouched_keeps_the_link_and_amount(): void
    {
        $inv = $this->inventory();
        $item = $this->item(['inventory_item_id' => $inv->id, 'inventory_amount_used' => 2.5]);

        $this->asAdminInBranch1()->put('/admin/menu-items/' . $item->id, $this->untouchedPostFor($item, [
            'inventory_item_id'     => (string) $inv->id,
            'inventory_amount_used' => '2.500',
        ]))->assertSessionHasNoErrors();

        $fresh = $item->fresh();
        $this->assertSame($inv->id, (int) $fresh->inventory_item_id, 'the legacy inventory link was lost');
        $this->assertEqualsWithDelta(2.5, (float) $fresh->inventory_amount_used, 0.001, 'the legacy amount was lost');
    }

    public function test_an_item_with_recipe_ingredients_still_saves_normally(): void
    {
        $inv = $this->inventory();
        $item = $this->item();
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 3]);

        $this->asAdminInBranch1()->put('/admin/menu-items/' . $item->id, $this->untouchedPostFor($item, [
            'inventory_item_id'     => '',
            'inventory_amount_used' => '0',
            'name'                  => $item->name . ' edited',
        ]))->assertSessionHasNoErrors();

        $fresh = $item->fresh();
        $this->assertSame($item->name . ' edited', $fresh->name);
        $this->assertNull($fresh->inventory_item_id);
        $this->assertSame(1, MenuItemIngredient::where('menu_item_id', $item->id)->count(), 'the recipe row changed');
    }

    public function test_a_new_item_saves_with_no_legacy_link(): void
    {
        $name = 'LLH New ' . uniqid();

        $this->asAdminInBranch1()->post('/admin/new-menu-item', [
            'category_id'           => DB::table('categories')->min('id'),
            'name'                  => $name,
            'price'                 => 120,
            'branch_id'             => 1,
            'inventory_item_id'     => '',
            'inventory_amount_used' => '0',
        ])->assertSessionHasNoErrors();

        $created = MenuItem::where('name', $name)->first();
        $this->assertNotNull($created, 'the new item was not created');
        $this->assertNull($created->inventory_item_id);
        $this->assertEqualsWithDelta(0.0, (float) $created->inventory_amount_used, 0.001);
    }
}

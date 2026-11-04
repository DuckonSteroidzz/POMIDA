<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Support\RecipeQuantityLimits;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\MenuItemSizeFixtures;
use Tests\TestCase;

/**
 * QA Nov 2, concern 2: a recipe line is how much ONE order uses, not stock.
 *
 * QA typed stock amounts into recipes (Egg 30 pc / 40 pc against 128 pc / 1.5
 * pc on the shelf, Rice 60 kg, Garlic 10-15 kg), so the menu showed Low / Out
 * of Stock while inventory looked full. This pass changes labels, help text,
 * warnings and one read-only "which ingredient limits it" figure. It must NOT
 * change the stock logic, the low-stock threshold, recipes or stock — the last
 * tests pin that.
 *
 * Rows are minted per test in their own branch (MenuItemSizeFixtures) inside
 * DatabaseTransactions on pomida_db_testing.
 */
class RecipeQuantityVsStockTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    private function menuPage(int $branchId)
    {
        return $this->actingAs($this->sizeOwner(), 'admin')
            ->withSession(['selected_branch_id' => $branchId])
            ->get(route('admin.menu-items'));
    }

    private function ingredient(int $branchId, string $name, float $stock, string $unit): Inventory
    {
        $inv = $this->sizeInventory($branchId, $stock, 1, $name);
        $inv->update(['unit' => $unit, 'item_name' => $name . ' ' . uniqid()]);

        return $inv->fresh();
    }

    private function fresh(MenuItem $item): MenuItem
    {
        return MenuItem::with(['recipeIngredients.inventory', 'inventoryItem', 'allSizes.ingredients.inventory'])->find($item->id);
    }

    // ══════════ labels and help text ══════════

    public function test_recipe_editor_says_used_per_order_and_explains_stock_in(): void
    {
        $branch = $this->sizeBranch('L');
        $item = $this->sizeItem($branch->id, 'RQVS-Labels');
        $this->baseRecipeLine($item, $this->ingredient($branch->id, 'Flour', 500, 'g'), 10);

        $html = $this->menuPage($branch->id)->assertOk()->getContent();

        $this->assertStringContainsString('Used per order</th>', $html);
        $this->assertStringContainsString('Quantity used per order', $html);
        $this->assertStringContainsString('Can make</th>', $html);
        $this->assertStringContainsString('How much ONE order uses. To add stock, go to Inventory and use Stock In.', $html);
        $this->assertStringNotContainsString('Quantity Used', $html, 'the old, stock-sounding label is gone');
        $this->assertStringNotContainsString('>Quantity</th>', $html);
    }

    public function test_sized_recipe_editor_uses_the_same_labels(): void
    {
        $branch = $this->sizeBranch('S');
        $item = $this->sizeItem($branch->id, 'RQVS-Sized');
        [$regular] = $this->enableSizes($item);
        $this->sizeRecipeLine($regular, $this->ingredient($branch->id, 'Beans', 900, 'g'), 30);

        $html = $this->menuPage($branch->id)->assertOk()->getContent();

        $this->assertStringContainsString('id="recipe-table-size-' . $regular->id . '"', $html);
        $this->assertStringContainsString('id="recipe-qty-help-size-' . $regular->id . '"', $html);
    }

    public function test_add_on_recipe_dialog_uses_the_same_wording(): void
    {
        $html = $this->actingAs($this->sizeOwner(), 'admin')
            ->get(route('admin.menu-options'))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Quantity used per order"', $html);
        $this->assertStringContainsString('How much ONE order uses. To add stock, go to Inventory and use Stock In.', $html);
        $this->assertStringNotContainsString('aria-label="Quantity used"', $html);
    }

    public function test_add_on_ingredient_json_carries_the_stock_for_can_make(): void
    {
        $branch = $this->sizeBranch('A');
        $option = MenuOption::create(['name' => 'RQVS Option ' . uniqid(), 'additional_price' => 5, 'is_active' => true, 'display_order' => 0]);
        $egg = $this->ingredient($branch->id, 'Egg', 128, 'pc');

        $this->actingAs($this->sizeOwner(), 'admin')->withSession(['selected_branch_id' => $branch->id])
            ->postJson(route('admin.menu-options.ingredients.add', $option->id), ['inventory_id' => $egg->id, 'quantity_used' => 2])
            ->assertOk()
            ->assertJsonPath('ingredient.stock', 128)
            ->assertJsonPath('ingredient.quantity_used', '2');
    }

    // ══════════ "Can make" ══════════

    public function test_can_make_is_stock_divided_by_amount_per_order_and_the_smallest_high_line_is_highlighted(): void
    {
        $branch = $this->sizeBranch('C');
        $item = $this->sizeItem($branch->id, 'RQVS-CanMake');
        $egg = $this->ingredient($branch->id, 'Egg', 128, 'pc');
        $rice = $this->ingredient($branch->id, 'Rice', 1000, 'g');
        $this->baseRecipeLine($item, $egg, 40);     // 128 / 40 = 3 orders
        $this->baseRecipeLine($item, $rice, 200);   // 1000 / 200 = 5 orders

        $html = $this->menuPage($branch->id)->assertOk()->getContent();

        $this->assertStringContainsString('3 orders', $html);
        $this->assertStringContainsString('5 orders', $html);
        $this->assertSame(1, substr_count($html, 'Runs out first</span>'), 'exactly one line runs out first');
        $this->assertStringNotContainsString('Limits item', $html);

        // QA Nov 3: the tag, the header and the line under the table explain themselves.
        $hint = e(\App\Support\RecipeQuantityLimits::CAN_MAKE_HINT);
        $this->assertStringContainsString('<span class="recipe-limit-tag" title="' . $hint . '"', $html);
        $this->assertStringContainsString('title="' . $hint . '">Can make</th>', $html);
        $this->assertStringContainsString(e(\App\Support\RecipeQuantityLimits::RUNS_OUT_NOTE), $html);
        $this->assertStringContainsString('The High ingredient that runs out first decides if the item is Low Stock or Out of Stock. Low ingredients never limit the item.', $html);
        $this->assertMatchesRegularExpression('/<tr class="recipe-limiter"[^>]*data-qty="40[^"]*"/', $html, 'the Egg line (3 orders) is the limiter');
    }

    public function test_a_low_priority_line_never_limits_the_item(): void
    {
        $branch = $this->sizeBranch('P');
        $item = $this->sizeItem($branch->id, 'RQVS-LowLine');
        $this->baseRecipeLine($item, $this->ingredient($branch->id, 'Salt', 10, 'g'), 5)->update(['priority' => 'low']); // 2 orders, but LOW
        $this->baseRecipeLine($item, $this->ingredient($branch->id, 'Pasta', 900, 'g'), 100);                           // 9 orders, HIGH

        $html = $this->menuPage($branch->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<tr class="recipe-limiter"[^>]*data-qty="100[^"]*"/', $html, 'the HIGH line limits, not the LOW one');
        $this->assertDoesNotMatchRegularExpression('/<tr class="recipe-limiter"[^>]*data-qty="5[^0-9"]*"/', $html);
    }

    // ══════════ the warning ══════════

    public function test_warning_for_more_than_the_stock_uses_the_exact_wording(): void
    {
        $this->assertSame(
            'This recipe uses 40 pc of Egg for ONE order, but you only have 1.5 pc in stock. Did you mean to add stock? Use Inventory > Stock In instead.',
            RecipeQuantityLimits::warningFor('Egg', 'pc', 40, 1.5)
        );
    }

    public function test_warning_for_an_unusually_large_amount_even_when_the_stock_covers_it(): void
    {
        $message = RecipeQuantityLimits::warningFor('Rice', 'kg', 60, 500);

        $this->assertStringContainsString('This recipe uses 60 kg of Rice for ONE order.', $message);
        $this->assertStringContainsString('over 1 kg', $message);
        $this->assertStringContainsString('Use Inventory > Stock In instead.', $message);
        $this->assertNotNull(RecipeQuantityLimits::warningFor('Garlic', 'kg', 10, 500));
        $this->assertNotNull(RecipeQuantityLimits::warningFor('Garlic', 'kg', 15, 500));
    }

    public function test_sensible_amounts_are_not_warned_about(): void
    {
        $this->assertNull(RecipeQuantityLimits::warningFor('Egg', 'pc', 3, 128));
        $this->assertNull(RecipeQuantityLimits::warningFor('Egg', 'pc', 12, 128), 'the limit itself is allowed');
        $this->assertNull(RecipeQuantityLimits::warningFor('Rice', 'g', 200, 5000));
        $this->assertNull(RecipeQuantityLimits::warningFor('Milk', 'ml', 250, 20000));
        $this->assertNull(RecipeQuantityLimits::warningFor('Rice', 'kg', 1, 60));
        $this->assertNull(RecipeQuantityLimits::warningFor('Mystery', 'sack', 5, 500), 'an unknown unit has no size limit, only the stock check');
        $this->assertNotNull(RecipeQuantityLimits::warningFor('Mystery', 'sack', 5, 2));
    }

    public function test_limits_are_looked_up_case_insensitively_and_cover_every_unit_the_dropdown_offers(): void
    {
        foreach (Inventory::ALLOWED_UNITS as $unit) {
            $this->assertNotNull(RecipeQuantityLimits::limitFor($unit), "no limit for the '{$unit}' unit");
        }
        $this->assertSame(1.0, RecipeQuantityLimits::limitFor('L'));
        $this->assertSame(1.0, RecipeQuantityLimits::limitFor('l'));
    }

    public function test_the_page_hands_the_browser_the_same_limits_and_sentences(): void
    {
        $branch = $this->sizeBranch('W');
        $html = $this->menuPage($branch->id)->assertOk()->getContent();

        $this->assertStringContainsString('window.POMIDA_RECIPE_LIMITS = ' . json_encode(RecipeQuantityLimits::limits()), $html);
        $this->assertStringContainsString('pomidaRecipeQuantityConfirm(', $html, 'the Add button asks before saving');
        // @json escapes the ">" in "Inventory > Stock In" (as a unicode escape).
        $this->assertStringContainsString('Did you mean to add stock? Use Inventory ' . chr(92) . 'u003E Stock In instead.', $html);
        $this->assertStringContainsString('pomidaRecipeQuantityConfirm', $this->actingAs($this->sizeOwner(), 'admin')->get(route('admin.menu-options'))->getContent());
    }

    // ══════════ Status column: which ingredient limits ══════════

    public function test_low_stock_names_the_limiting_ingredient_and_how_many_orders_are_left(): void
    {
        $branch = $this->sizeBranch('LS');
        $item = $this->sizeItem($branch->id, 'RQVS-Low');
        $this->baseRecipeLine($item, $this->ingredient($branch->id, 'Rice', 5000, 'g'), 100);          // 50 orders
        $this->baseRecipeLine($item, $egg = $this->ingredient($branch->id, 'Egg', 128, 'pc'), 60);   // 2 orders

        $status = $this->fresh($item)->stockStatus([]);
        $this->assertSame('low_stock', $status['state']);
        $this->assertSame(2, $status['remaining']);
        $this->assertSame(['name' => $egg->item_name, 'left' => 2, 'unit' => 'pc'], $status['limited_by']);

        $html = $this->menuPage($branch->id)->assertOk()->getContent();
        $this->assertStringContainsString('· runs out first: ' . e($egg->item_name) . ' (2 left)', $html);
    }

    public function test_out_of_stock_names_the_ingredient_that_is_out(): void
    {
        $branch = $this->sizeBranch('OS');
        $item = $this->sizeItem($branch->id, 'RQVS-Out');
        $this->baseRecipeLine($item, $this->ingredient($branch->id, 'Rice', 5000, 'g'), 100);
        $this->baseRecipeLine($item, $egg = $this->ingredient($branch->id, 'Egg', 1.5, 'pc'), 40);   // 1.5 < 40: not even one order

        $status = $this->fresh($item)->stockStatus([]);
        $this->assertSame('out_of_stock', $status['state']);
        $this->assertSame($egg->item_name, $status['limited_by']['name']);
        $this->assertSame(0, $status['limited_by']['left']);

        $html = $this->menuPage($branch->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-stock-state="out_of_stock">Out of Stock<\/span>\s*<span class="menu-stock-left menu-stock-limit">· ' . preg_quote(e($egg->item_name), '/') . '<\/span>/', $html);
    }

    public function test_in_stock_and_no_recipe_items_name_no_ingredient(): void
    {
        $branch = $this->sizeBranch('IN');
        $ok = $this->sizeItem($branch->id, 'RQVS-Fine');
        $this->baseRecipeLine($ok, $this->ingredient($branch->id, 'Rice', 5000, 'g'), 100);
        $none = $this->sizeItem($branch->id, 'RQVS-NoRecipe');

        $this->assertNull($this->fresh($ok)->stockStatus([])['limited_by']);
        $this->assertNull($this->fresh($none)->stockStatus([])['limited_by']);
    }

    public function test_a_low_priority_ingredient_is_never_named_as_the_limiter(): void
    {
        $branch = $this->sizeBranch('LP');
        $item = $this->sizeItem($branch->id, 'RQVS-LowNamed');
        $this->baseRecipeLine($item, $this->ingredient($branch->id, 'Garnish', 0, 'g'), 5)->update(['priority' => 'low']);
        $this->baseRecipeLine($item, $pasta = $this->ingredient($branch->id, 'Pasta', 250, 'g'), 100);   // 2 orders

        $status = $this->fresh($item)->stockStatus([]);
        $this->assertSame('low_stock', $status['state']);
        $this->assertSame($pasta->item_name, $status['limited_by']['name']);
    }

    public function test_committed_stock_is_taken_off_before_naming_the_limiter(): void
    {
        $branch = $this->sizeBranch('CS');
        $item = $this->sizeItem($branch->id, 'RQVS-Reserved');
        $inv = $this->ingredient($branch->id, 'Cheese', 1000, 'g');
        $this->baseRecipeLine($item, $inv, 100);                                    // 10 orders on the shelf

        $this->assertSame('in_stock', $this->fresh($item)->stockStatus([])['state']);

        $status = $this->fresh($item)->stockStatus([$inv->id => 750]);              // 250 free
        $this->assertSame('low_stock', $status['state']);
        $this->assertSame(2, $status['limited_by']['left']);
    }

    public function test_a_sized_item_names_the_limiter_of_the_size_that_decided_the_verdict(): void
    {
        $branch = $this->sizeBranch('SZ');
        $item = $this->sizeItem($branch->id, 'RQVS-SizedLow');
        [$regular, $large] = $this->enableSizes($item);
        $this->sizeRecipeLine($regular, $beans = $this->ingredient($branch->id, 'Beans', 100, 'g'), 40);   // 2 orders
        $this->sizeRecipeLine($large, $this->ingredient($branch->id, 'BeansL', 100, 'g'), 80);             // 1 order

        $status = $this->fresh($item)->stockStatus([]);
        $this->assertSame('low_stock', $status['state']);
        $this->assertSame($beans->item_name, $status['limited_by']['name'], 'Regular can make the most, so it sets the verdict');
        $this->assertSame('Regular', $status['limited_by']['size']);
    }

    public function test_the_page_explains_what_low_stock_means_using_the_live_threshold(): void
    {
        $branch = $this->sizeBranch('N');
        $html = $this->menuPage($branch->id)->assertOk()->getContent();

        $this->assertStringContainsString('<strong>Low Stock</strong> means 3 or fewer orders can still be made.', $html);

        config(['inventory.low_stock_threshold' => 5]);
        $this->assertStringContainsString('means 5 or fewer orders', $this->menuPage($branch->id)->getContent());
    }

    // ══════════ nothing about the stock rule moved ══════════

    public function test_the_verdict_and_the_remaining_count_are_unchanged_and_nothing_is_written(): void
    {
        $branch = $this->sizeBranch('U');
        $item = $this->sizeItem($branch->id, 'RQVS-Unchanged');
        $egg = $this->ingredient($branch->id, 'Egg', 128, 'pc');
        $this->baseRecipeLine($item, $egg, 40);

        $before = [DB::table('inventory')->where('id', $egg->id)->value('quantity'), DB::table('menu_item_ingredients')->where('menu_item_id', $item->id)->value('quantity_used')];

        $fresh = $this->fresh($item);
        $this->assertSame(3, $fresh->remainingServings([]));
        $this->assertTrue($fresh->hasIngredientStock(3, []));
        $this->assertFalse($fresh->hasIngredientStock(4, []));
        $this->assertSame(3, (int) config('inventory.low_stock_threshold'));
        $this->assertSame('low_stock', $fresh->stockStatus([])['state'], '3 orders is still Low Stock: the line is drawn at 3 or fewer');

        $this->menuPage($branch->id)->assertOk();

        $this->assertSame($before, [DB::table('inventory')->where('id', $egg->id)->value('quantity'), DB::table('menu_item_ingredients')->where('menu_item_id', $item->id)->value('quantity_used')]);
    }
}

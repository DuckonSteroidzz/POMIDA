<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\MenuOption;
use App\Models\MenuOptionIngredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\MenuItemCosting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A menu item's cost comes from its recipe, not from a typed-in number (2026-09-03).
 *
 * WHY
 * ----
 * The Menu Items form has a Cost box a human types into. That number is a guess
 * and it drifts the moment an ingredient price changes. The item already HAS a
 * recipe (menu_item_ingredients) and every inventory row already carries a
 * unit_cost, so the true cost is already knowable. Owner's decision: when an item
 * has a recipe, the recipe is the source of truth; the typed-in cost column is a
 * fallback ONLY for items with no recipe and no legacy single-ingredient link.
 *
 * WHAT THIS FOLLOWS
 * ------------------
 * App\Services\MenuItemCosting does NOT walk the recipe itself. It delegates to
 * InventoryDeductionService::requirementsForLine() — the same walker that decides
 * what an order deducts — so "what we charge ourselves" and "what leaves the
 * shelf" cannot disagree. It calls it with quantity 1 and an EMPTY option list:
 * base recipe only, because an add-on is chosen per order, not part of the item.
 *
 * UNITS
 * ------
 * menu_item_ingredients.quantity_used is entered in the unit of the inventory row
 * it points at (the recipe form labels the quantity box with that row's unit), and
 * inventory.unit_cost is pesos per that same unit. So quantity_used x unit_cost is
 * pesos with no conversion — the same arithmetic the Inventory page already uses
 * for Stock Value (quantity x unit_cost).
 *
 * Every figure below is a real worked number, not a re-derivation of the code.
 */
class MenuItemRecipeCostTest extends TestCase
{
    use DatabaseTransactions;

    /** Staff, locked to branch 1 by ResolvesBranchScope — the branch these fixtures live in. */
    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    /** The edit form is admin-only (route middleware), so it needs a real admin. */
    private function admin(): User
    {
        return User::where('role', 'admin')->firstOrFail();
    }

    private function costing(): MenuItemCosting
    {
        return app(MenuItemCosting::class);
    }

    private function makeInventory(float $unitCost, string $unit = 'g'): Inventory
    {
        return Inventory::create([
            'branch_id'       => 1,
            'item_name'       => 'MIRC Ingredient ' . uniqid(),
            'item_code'       => 'MIRC-' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => 10000,
            'unit'            => $unit,
            'low_stock_alert' => 1,
            'unit_cost'       => $unitCost,
            'is_active'       => true,
        ]);
    }

    private function makeItem(array $attrs = []): MenuItem
    {
        return MenuItem::create(array_merge([
            'category_id'   => DB::table('categories')->min('id'),
            'branch_id'     => 1,
            'name'          => 'MIRC Item ' . uniqid(),
            'price'         => 100,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
            'total_sold'    => 0,
        ], $attrs));
    }

    /**
     * The single <tr> of the Menu Items table that contains the given item name.
     * Lets an assertion be about ONE row instead of the whole page, so a figure
     * another item happens to share cannot make a broken sum look right.
     */
    private function rowFor(string $html, string $itemName): string
    {
        foreach (preg_split('/<tr\b/', $html) as $row) {
            // Both conditions matter: the flash banner above the table repeats the
            // item name, so a name match alone can return the whole page header
            // and make any assertion about "the row" meaningless.
            if (str_contains($row, $itemName) && str_contains($row, 'data-label="Cost"')) {
                return $row;
            }
        }
        $this->fail('No table row found for "' . $itemName . '".');
    }

    /*
    |--------------------------------------------------------------------------
    | THE RECIPE IS THE SOURCE OF TRUTH
    |--------------------------------------------------------------------------
    */

    public function test_a_two_ingredient_recipe_costs_the_sum_of_quantity_times_unit_cost(): void
    {
        // The real Brewed Coffee shape: Coffee Beans 15 g at PHP 1/g + White
        // Sugar 5 g at PHP 1/g = PHP 20.00. The typed-in cost is set to a
        // deliberately wrong 999 so a fallback-instead-of-recipe bug cannot pass.
        $beans = $this->makeInventory(1.00);
        $sugar = $this->makeInventory(1.00);
        $item  = $this->makeItem(['price' => 50, 'cost' => 999]);

        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $beans->id, 'quantity_used' => 15]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $sugar->id, 'quantity_used' => 5]);

        $b = $this->costing()->breakdownFor($item->fresh());

        $this->assertSame(20.00, $b['cost'], '15 x 1 + 5 x 1 must be 20.00');
        $this->assertFalse($b['is_fallback'], 'an item with a recipe is a computed cost, not a guess');
    }

    public function test_a_fractional_unit_cost_is_not_truncated(): void
    {
        // 3 x 2.35 + 1.5 x 4.20 = 7.05 + 6.30 = 13.35. Chosen so an int cast or
        // a floor() anywhere would land on 12 or 13, not 13.35.
        $a = $this->makeInventory(2.35);
        $c = $this->makeInventory(4.20);
        $item = $this->makeItem(['price' => 40]);

        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $a->id, 'quantity_used' => 3]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $c->id, 'quantity_used' => 1.5]);

        $this->assertSame(13.35, $this->costing()->breakdownFor($item->fresh())['cost']);
    }

    public function test_changing_an_ingredient_unit_cost_changes_the_item_cost_on_the_next_read(): void
    {
        // This is the entire point of the change: the number must follow the
        // ingredient price instead of being frozen at whatever someone typed.
        $ing  = $this->makeInventory(2.00);
        $item = $this->makeItem(['price' => 100, 'cost' => 999]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $ing->id, 'quantity_used' => 10]);

        // 10 x 2.00 = 20.00
        $this->assertSame(20.00, $this->costing()->breakdownFor($item->fresh())['cost']);

        // Supplier raises the price to 3.50 -> 10 x 3.50 = 35.00, without the
        // menu item being touched at all.
        $ing->update(['unit_cost' => 3.50]);

        $this->assertSame(
            35.00,
            $this->costing()->breakdownFor($item->fresh())['cost'],
            'the item cost must follow the ingredient price without the item being edited'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | LEGACY LINK AND FALLBACK
    |--------------------------------------------------------------------------
    */

    public function test_an_item_using_only_the_legacy_single_ingredient_link_is_costed_too(): void
    {
        // inventory_amount_used 4 at 6.25 = 25.00. requirementsForLine() already
        // honours this legacy path, so costing gets it for free.
        $ing  = $this->makeInventory(6.25, 'pc');
        $item = $this->makeItem([
            'price'                 => 60,
            'cost'                  => 999,
            'inventory_item_id'     => $ing->id,
            'inventory_amount_used' => 4,
        ]);

        $b = $this->costing()->breakdownFor($item->fresh());

        $this->assertSame(25.00, $b['cost'], '4 x 6.25 must be 25.00');
        $this->assertFalse($b['is_fallback'], 'a legacy link is still a real ingredient cost');
    }

    public function test_an_item_with_no_recipe_and_no_legacy_link_falls_back_to_the_typed_in_cost(): void
    {
        $item = $this->makeItem(['price' => 80, 'cost' => 33.50]);

        $b = $this->costing()->breakdownFor($item->fresh());

        $this->assertSame(33.50, $b['cost']);
        $this->assertTrue($b['is_fallback'], 'with nothing to cost from, the number is a guess and must say so');
    }

    /*
    |--------------------------------------------------------------------------
    | GROSS PROFIT
    |--------------------------------------------------------------------------
    */

    public function test_gross_profit_is_price_minus_cost_in_pesos_and_percent(): void
    {
        // Price 50, recipe 15 x 1 + 5 x 1 = 20 -> profit 30, margin 60.0%.
        $beans = $this->makeInventory(1.00);
        $sugar = $this->makeInventory(1.00);
        $item  = $this->makeItem(['price' => 50, 'cost' => 999]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $beans->id, 'quantity_used' => 15]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $sugar->id, 'quantity_used' => 5]);

        $b = $this->costing()->breakdownFor($item->fresh());

        $this->assertSame(30.00, $b['profit']);
        $this->assertSame(60.0, $b['margin_percent']);
    }

    public function test_an_item_that_costs_more_than_it_sells_for_reports_a_loss_not_zero(): void
    {
        // Price 10, recipe 1 x 25 = 25 -> profit -15.00, margin -150.0%.
        // Clamping to zero here would hide a real loss from the owner.
        $ing  = $this->makeInventory(25.00, 'pc');
        $item = $this->makeItem(['price' => 10]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $ing->id, 'quantity_used' => 1]);

        $b = $this->costing()->breakdownFor($item->fresh());

        $this->assertSame(25.00, $b['cost']);
        $this->assertSame(-15.00, $b['profit']);
        $this->assertSame(-150.0, $b['margin_percent']);
    }

    /*
    |--------------------------------------------------------------------------
    | HONEST EDGE CASES
    |--------------------------------------------------------------------------
    */

    public function test_an_ingredient_priced_at_zero_does_not_break_the_calculation(): void
    {
        // unit_cost is NOT NULL defaulting to 0.00, so a free ingredient is a
        // legitimate row. 20 x 0 + 4 x 1.50 = 6.00, and it is still a COMPUTED
        // cost, not a fallback.
        $free = $this->makeInventory(0);
        $paid = $this->makeInventory(1.50);
        $item = $this->makeItem(['price' => 30, 'cost' => 999]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $free->id, 'quantity_used' => 20]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $paid->id, 'quantity_used' => 4]);

        $b = $this->costing()->breakdownFor($item->fresh());

        $this->assertSame(6.00, $b['cost']);
        $this->assertFalse($b['is_fallback']);
    }

    public function test_an_everything_free_recipe_costs_zero_and_is_still_not_a_fallback(): void
    {
        $free = $this->makeInventory(0);
        $item = $this->makeItem(['price' => 30, 'cost' => 999]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $free->id, 'quantity_used' => 5]);

        $b = $this->costing()->breakdownFor($item->fresh());

        $this->assertSame(0.0, $b['cost']);
        $this->assertFalse($b['is_fallback'], 'zero from a real recipe is a fact, not a missing figure');
    }

    public function test_a_price_of_zero_reports_no_percentage_rather_than_a_made_up_one(): void
    {
        // There is no percentage of nothing. Printing "0%" or dividing by zero
        // would both be wrong; null lets the screen say "(no price)".
        $ing  = $this->makeInventory(4.00, 'pc');
        $item = $this->makeItem(['price' => 0]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $ing->id, 'quantity_used' => 2]);

        $b = $this->costing()->breakdownFor($item->fresh());

        $this->assertSame(8.00, $b['cost']);
        $this->assertSame(-8.00, $b['profit']);
        $this->assertNull($b['margin_percent']);
    }

    /*
    |--------------------------------------------------------------------------
    | BASE RECIPE ONLY — add-ons are chosen per order, not part of the item
    |--------------------------------------------------------------------------
    */

    public function test_an_add_on_option_does_not_inflate_the_items_cost(): void
    {
        // An option attached to the item still costs the item nothing: it is
        // only chosen at order time. Options will count toward COGS in a later
        // pass, against a real order that says which were actually picked.
        $base  = $this->makeInventory(1.00);
        $extra = $this->makeInventory(50.00);
        $item  = $this->makeItem(['price' => 50]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $base->id, 'quantity_used' => 10]);

        $option = MenuOption::create([
            'name'             => 'MIRC Extra ' . uniqid(),
            'additional_price' => 20,
            'is_available'     => true,
        ]);
        MenuOptionIngredient::create([
            'menu_option_id' => $option->id,
            'inventory_id'   => $extra->id,
            'quantity_used'  => 1,
        ]);
        $item->options()->attach($option->id);

        // 10 x 1 = 10.00 — the 50-per-unit option ingredient must not appear.
        $this->assertSame(10.00, $this->costing()->breakdownFor($item->fresh())['cost']);
    }

    /*
    |--------------------------------------------------------------------------
    | THE SCREEN
    |--------------------------------------------------------------------------
    */

    public function test_the_menu_items_page_shows_cost_and_gross_profit_for_a_recipe_item(): void
    {
        $beans = $this->makeInventory(1.00);
        $sugar = $this->makeInventory(1.00);
        $item  = $this->makeItem(['price' => 50, 'cost' => 999, 'name' => 'MIRC Screen Coffee']);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $beans->id, 'quantity_used' => 15]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $sugar->id, 'quantity_used' => 5]);

        // Admin, because the Edit column (and its data-* attributes) is admin-only.
        $page = $this->actingAs($this->admin(), 'admin')->get('/admin/menu-items');

        $page->assertOk();
        $page->assertSee('MIRC Screen Coffee');
        $page->assertSee('Gross Profit');

        // Assert against THIS item's own row, not just anywhere on the page —
        // other items legitimately carry a 20.00 of their own, so a loose
        // assertSee() would pass even with the arithmetic broken.
        $row = $this->rowFor($page->getContent(), 'MIRC Screen Coffee');
        $this->assertStringContainsString('₱20.00', $row, 'computed cost, not the 999 guess');
        $this->assertStringContainsString('₱30.00', $row, '50 - 20');
        $this->assertStringContainsString('(60.0%)', $row);
        $this->assertStringNotContainsString('₱999.00', $row);
        $this->assertStringNotContainsString('No recipe', $row, 'a computed cost must not be badged as a guess');
        // The same figure is what the edit modal loads, so the box opens locked
        // at the computed number rather than the stored guess.
        $this->assertStringContainsString('data-has-recipe="1"', $row);
        $this->assertStringContainsString('data-computed-cost="20.00"', $row);
    }

    public function test_a_fallback_cost_is_visibly_marked_on_the_menu_items_page(): void
    {
        $this->makeItem(['price' => 80, 'cost' => 33.50, 'name' => 'MIRC Screen Guess']);

        $page = $this->actingAs($this->staff(), 'admin')->get('/admin/menu-items');

        $page->assertOk();
        $page->assertSee('MIRC Screen Guess');

        $row = $this->rowFor($page->getContent(), 'MIRC Screen Guess');
        $this->assertStringContainsString('₱33.50', $row);
        $this->assertStringContainsString('₱46.50', $row, '80 - 33.50');
        // The badge that tells a guess from a real figure at a glance.
        $this->assertStringContainsString('No recipe', $row);
        $this->assertStringContainsString('No recipe set', $row);
    }

    public function test_the_menu_items_page_still_lists_every_item_for_the_branch(): void
    {
        // Regression: adding two columns must not drop rows or blow up on an
        // item with no recipe, no legacy link, or a zero price.
        $expected = MenuItem::where('branch_id', 1)->pluck('name');
        $this->assertGreaterThan(0, $expected->count());

        $page = $this->actingAs($this->staff(), 'admin')->get('/admin/menu-items');

        $page->assertOk();
        foreach ($expected as $name) {
            $page->assertSee(e($name), false);
        }
    }

    public function test_completing_an_order_still_deducts_exactly_what_the_recipe_says(): void
    {
        // Regression guard for the whole point of reusing requirementsForLine():
        // reading cost from the walker must not change what the walker deducts.
        // Recipe 15 g + 5 g, ordered 2x -> 30 g and 10 g off the shelf, and the
        // item's cost is STILL 20.00 per unit (15 x 1 + 5 x 1), unchanged by the
        // order quantity.
        $beans = $this->makeInventory(1.00);
        $sugar = $this->makeInventory(1.00);
        $item  = $this->makeItem(['price' => 50]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $beans->id, 'quantity_used' => 15]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $sugar->id, 'quantity_used' => 5]);

        $beansBefore = (float) $beans->fresh()->quantity;
        $sugarBefore = (float) $sugar->fresh()->quantity;

        $order = Order::create([
            'order_number' => 'MIRC-' . strtoupper(substr(uniqid(), -8)),
            'branch_id'    => 1,
            'type'         => 'pick_up',
            'status'       => 'pending',
            'subtotal'     => 100,
            'total'        => 100,
        ]);
        OrderItem::create([
            'order_id'     => $order->id,
            'menu_item_id' => $item->id,
            'item_name'    => $item->name,
            'item_price'   => 50,
            'quantity'     => 2,
            'subtotal'     => 100,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/orders/' . $order->id . '/complete');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame($beansBefore - 30.0, (float) $beans->fresh()->quantity, '2 x 15 g of beans');
        $this->assertSame($sugarBefore - 10.0, (float) $sugar->fresh()->quantity, '2 x 5 g of sugar');

        // Cost is per ONE item and is not affected by how many were ordered.
        $this->assertSame(20.00, $this->costing()->breakdownFor($item->fresh())['cost']);
    }
    public function test_the_edit_form_locks_the_cost_box_only_when_the_item_has_a_recipe(): void
    {
        // Add and Edit both live in the one modal on the Menu Items list now —
        // there is no standalone edit page. openEditModal() reads data-has-recipe
        // / data-computed-cost off the Edit button and calls applyCostLock(),
        // which is what actually locks/unlocks the Cost box; those two
        // attributes are therefore the server-rendered evidence to check,
        // isolated to each item's own row.
        $ing     = $this->makeInventory(2.00);
        $withRec = $this->makeItem(['price' => 100, 'cost' => 999, 'name' => 'MIRC Locked Cost Item']);
        MenuItemIngredient::create(['menu_item_id' => $withRec->id, 'inventory_id' => $ing->id, 'quantity_used' => 10]);
        $noRec = $this->makeItem(['price' => 100, 'cost' => 41.00, 'name' => 'MIRC Open Cost Item']);

        $html = $this->actingAs($this->admin(), 'admin')->get('/admin/menu-items')->getContent();

        $lockedRow = $this->rowFor($html, 'MIRC Locked Cost Item');
        $this->assertStringContainsString('data-has-recipe="1"', $lockedRow);
        $this->assertStringContainsString('data-computed-cost="20.00"', $lockedRow, '10 x 2.00, not the 999 guess');

        $openRow = $this->rowFor($html, 'MIRC Open Cost Item');
        $this->assertStringContainsString('data-has-recipe="0"', $openRow);
        $this->assertStringContainsString('data-cost="41.00"', $openRow);
    }

    /**
     * The Edit modal's saved recipe rows must carry the per-unit cost and the
     * quantity as data-* attributes, so the live "Cost from recipe" preview
     * (recalcRecipeCost()) can recompute from the rows on screen after an
     * ingredient is added or removed — the same way Add mode already does from
     * its draft rows.
     */
    public function test_edit_modal_saved_recipe_rows_carry_unit_cost_and_quantity_for_the_live_preview(): void
    {
        $beans = $this->makeInventory(1.25);
        $sugar = $this->makeInventory(0.80);
        $item  = $this->makeItem(['price' => 60, 'cost' => 999, 'name' => 'MIRC Live Cost Item']);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $beans->id, 'quantity_used' => 15]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $sugar->id, 'quantity_used' => 5]);

        $html = $this->actingAs($this->admin(), 'admin')->get('/admin/menu-items')->getContent();

        // Isolate this item's Edit recipe block.
        $start = strpos($html, 'id="recipe-' . $item->id . '"');
        $this->assertNotFalse($start, 'the per-item Edit recipe block is missing');
        $block = substr($html, $start, 4000);

        // Each saved row exposes its own unit_cost and quantity_used.
        $this->assertMatchesRegularExpression(
            '/data-ingredient-id="' . $item->recipeIngredients->first()->id . '"[^>]*data-unit-cost="1\.25"[^>]*data-qty="15/',
            $block,
            'the first saved row must carry data-unit-cost and data-qty'
        );
        $this->assertStringContainsString('data-unit-cost="0.80"', $block);
        $this->assertStringContainsString('data-qty="5', $block);
    }

    /**
     * The live preview is ONE shared function, invoked from the Edit
     * add-ingredient and delete-ingredient success handlers (Add mode already
     * called it from its draft add/remove).
     */
    public function test_the_cost_preview_function_is_shared_and_wired_into_the_edit_handlers(): void
    {
        $js = file_get_contents(resource_path('views/admin/menu-items.blade.php'));

        // One generalised function; the Add-mode name is now a thin wrapper.
        $this->assertStringContainsString('function recalcRecipeCost(blockId)', $js);
        $this->assertStringContainsString("function recalcAddModeCost() { recalcRecipeCost('add'); }", $js);

        // Invoked from BOTH Edit fetch success paths (add and delete).
        $this->assertSame(
            2,
            substr_count($js, 'recalcRecipeCost(blockId);'),
            'recalcRecipeCost(blockId) must run after the Edit add-ingredient AND delete-ingredient fetches'
        );

        // The appended Edit row is given the same data-* the preview reads.
        $this->assertStringContainsString('row.dataset.unitCost = editUnitCost;', $js);
        $this->assertStringContainsString('row.dataset.qty = editQty;', $js);
    }
}

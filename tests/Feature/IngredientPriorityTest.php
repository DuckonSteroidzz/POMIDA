<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\MenuItemSizeIngredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemRemovedIngredient;
use App\Services\InventoryDeductionService;
use App\Services\ProfitCalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\MenuItemSizeOrderFixtures;
use Tests\TestCase;

/**
 * Defect Report 6 — the INGREDIENT PRIORITY system (High / Low).
 *
 * The base recipe in almost every test is one HIGH ingredient and one LOW one,
 * and each test empties whichever shelf it is about. A leak in either direction
 * fails loudly: a LOW line that still blocks refuses the order, and a HIGH line
 * that stopped counting lets it through.
 */
class IngredientPriorityTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    /**
     * A pancake plate at $branch: 1 Corned Beef (HIGH, ₱10) + 2 Syrup (LOW, ₱5).
     *
     * @return array{item: MenuItem, high: Inventory, low: Inventory, highLine: MenuItemIngredient, lowLine: MenuItemIngredient}
     */
    private function plate(Branch $branch, float $highStock = 100, float $lowStock = 100, string $name = 'Plate'): array
    {
        $item = $this->sizeItem($branch->id, $name, 150);
        $high = $this->named($this->sizeInventory($branch->id, $highStock, 10), 'Corned Beef');
        $low = $this->named($this->sizeInventory($branch->id, $lowStock, 5), 'Pancake Syrup');

        $highLine = $this->baseRecipeLine($item, $high, 1);
        $lowLine = $this->baseRecipeLine($item, $low, 2);
        $lowLine->update(['priority' => 'low']);

        return [
            'item'     => $item->fresh(),
            'high'     => $high,
            'low'      => $low,
            'highLine' => $highLine->fresh(),
            'lowLine'  => $lowLine->fresh(),
        ];
    }

    private function named(Inventory $inventory, string $name): Inventory
    {
        $inventory->update(['item_name' => $name]);

        return $inventory;
    }

    /** A loaded copy, as every real caller has it. */
    private function loaded(MenuItem $item): MenuItem
    {
        return MenuItem::with(['recipeIngredients.inventory', 'inventoryItem', 'allSizes.ingredients.inventory'])->find($item->id);
    }

    private function asGuest(Branch $branch, array $extra = []): static
    {
        return $this->withSession(array_merge(['branch_id' => $branch->id, 'order_type' => 'pick_up'], $extra));
    }

    /** One session-cart line with removals, exactly as addToCart() writes it. */
    private function cartLineRemoving(MenuItem $item, int $qty, array $removedIds, $size = null): array
    {
        $line = $this->cartLine($item, $size, $qty);
        $key = array_key_first($line);
        $line[$key]['removed_ingredients'] = $removedIds;

        return [$key . '_r' . implode('-', $removedIds) => $line[$key]];
    }

    /** @return list<array{name: string, reason: string, inventory_id: ?int}> */
    private function removalsOf(OrderItem $line): array
    {
        return DB::table('order_item_removed_ingredients')
            ->where('order_item_id', $line->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'name'         => $row->ingredient_name,
                'reason'       => $row->reason,
                'inventory_id' => $row->inventory_id !== null ? (int) $row->inventory_id : null,
            ])
            ->all();
    }

    // ══════════ 1. DATA ══════════

    public function test_priority_defaults_to_high_on_both_recipe_tables_and_the_frozen_copy(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        $inv = $this->sizeInventory($branch->id);

        // Raw inserts: the column default, not the model's, is what existing rows got.
        $baseId = DB::table('menu_item_ingredients')->insertGetId([
            'menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1,
        ]);
        $this->assertSame('high', DB::table('menu_item_ingredients')->where('id', $baseId)->value('priority'));

        [$regular] = $this->enableSizes($item);
        $sizeId = DB::table('menu_item_size_ingredients')->insertGetId([
            'menu_item_size_id' => $regular->id, 'inventory_id' => $inv->id, 'quantity' => 1,
        ]);
        $this->assertSame('high', DB::table('menu_item_size_ingredients')->where('id', $sizeId)->value('priority'));

        $this->assertSame('high', $this->baseRecipeLine($this->sizeItem($branch->id), $inv, 1)->priority);

        foreach (['menu_item_ingredients', 'menu_item_size_ingredients', 'order_item_size_ingredients'] as $table) {
            $column = collect(DB::select("SHOW COLUMNS FROM {$table} LIKE 'priority'"))->first();
            $this->assertSame("enum('high','low')", $column->Type, $table);
            $this->assertSame('NO', $column->Null, $table);
            $this->assertSame('high', $column->Default, $table);
        }

        $this->assertFalse(Schema::hasColumn('menu_option_ingredients', 'priority'), 'Add-on recipes are not part of this.');
    }

    // ══════════ 2. STOCK STATUS — only HIGH decides, everywhere ══════════

    public function test_a_low_ingredient_at_zero_leaves_the_item_in_stock_on_the_model(): void
    {
        $f = $this->plate($this->sizeBranch('A'), 100, 0);
        $item = $this->loaded($f['item']);

        $this->assertTrue($item->hasIngredientStock());
        $this->assertSame(100, $item->remainingServings());
        $this->assertFalse($item->isLowOnIngredientStock());
        $this->assertNull($item->orderBlockedReason());
        $this->assertSame('in_stock', $item->stockStatus([])['state']);
    }

    public function test_a_high_ingredient_at_zero_makes_the_item_out_of_stock(): void
    {
        $f = $this->plate($this->sizeBranch('A'), 0, 100);
        $item = $this->loaded($f['item']);

        $this->assertFalse($item->hasIngredientStock());
        $this->assertSame('out_of_stock', $item->stockStatus([])['state']);
        $this->assertStringContainsString('out of stock', $item->orderBlockedReason());
    }

    public function test_only_a_high_ingredient_can_make_the_item_low_stock(): void
    {
        $lowOnHigh = $this->loaded($this->plate($this->sizeBranch('A'), 2, 100)['item']);
        $this->assertSame('low_stock', $lowOnHigh->stockStatus([])['state']);
        $this->assertSame(2, $lowOnHigh->stockStatus([])['remaining']);

        // 2 of the LOW ingredient is one serving of it: would read "Low Stock" if it counted.
        $lowOnLow = $this->loaded($this->plate($this->sizeBranch('B'), 100, 2)['item']);
        $this->assertSame('in_stock', $lowOnLow->stockStatus([])['state']);
    }

    public function test_marking_the_only_short_line_low_is_what_changes_the_verdict(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        $line = $this->baseRecipeLine($item, $this->sizeInventory($branch->id, 0), 1);

        $this->assertSame('out_of_stock', $this->loaded($item)->stockStatus([])['state'], 'Unchanged until someone marks it Low.');

        $line->update(['priority' => 'low']);
        $this->assertSame('in_stock', $this->loaded($item)->stockStatus([])['state']);
    }

    public function test_a_size_recipe_follows_the_same_rule(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $syrup = $this->sizeInventory($branch->id, 0, 1, 'Syrup');
        MenuItemSizeIngredient::create([
            'menu_item_size_id' => $f['regular']->id,
            'inventory_id'      => $syrup->id,
            'quantity'          => 1,
            'priority'          => 'low',
        ]);

        $item = $this->loaded($f['item']);
        $regular = $item->allSizes->firstWhere('id', $f['regular']->id);

        $this->assertTrue($item->hasIngredientStock(1, [], $regular));
        $this->assertSame('in_stock', $item->stockStatus([])['state']);

        DB::table('inventory')->where('id', $f['regInv']->id)->update(['quantity' => 0]);
        DB::table('inventory')->where('id', $f['largeInv']->id)->update(['quantity' => 0]);
        $this->assertSame('out_of_stock', $this->loaded($f['item'])->stockStatus([])['state']);
    }

    public function test_the_customer_menu_card_and_item_page_ignore_a_low_shortage(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 100, 0);

        $menu = $this->asGuest($branch)->get(route('customer.items', $f['item']->category_id))->assertOk();
        $menu->assertDontSee('Out of Stock');

        $page = $this->asGuest($branch)->get(route('customer.item', $f['item']->id))->assertOk();
        $page->assertDontSee('This item is temporarily unavailable');
        $page->assertSee('Add to cart');
    }

    public function test_the_customer_pages_still_block_on_a_high_shortage(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 0, 100);

        $this->asGuest($branch)->get(route('customer.items', $f['item']->category_id))->assertOk()->assertSee('Out of Stock');
        $this->asGuest($branch)->get(route('customer.item', $f['item']->id))->assertOk()
            ->assertSee('This item is temporarily unavailable');
    }

    public function test_the_admin_status_column_uses_the_same_verdict(): void
    {
        $branch = $this->sizeBranch('A');
        $lowShort = $this->plate($branch, 100, 0, 'Low Short');
        $highShort = $this->plate($branch, 0, 100, 'High Short');

        $html = $this->actingAs($this->sizeOwner(), 'admin')
            ->withSession(['selected_branch_id' => $branch->id])
            ->get(route('admin.menu-items'))
            ->assertOk()
            ->getContent();

        $statusOf = function (MenuItem $item) use ($html): string {
            $at = strpos($html, 'data-id="' . $item->id . '"');
            $start = strrpos(substr($html, 0, $at), 'data-stock-state="');
            preg_match('/data-stock-state="([a-z_]+)"/', substr($html, $start), $m);

            return $m[1];
        };

        $this->assertSame('in_stock', $statusOf($lowShort['item']));
        $this->assertSame('out_of_stock', $statusOf($highShort['item']));
    }

    public function test_the_stock_gate_and_availability_measure_high_lines_only(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 5, 0);
        $service = app(InventoryDeductionService::class);

        $this->assertSame(5, $service->unitsAvailableFor($f['item'], $branch->id));
        $this->assertSame([], $service->cartShortfalls([['menu_item' => $f['item'], 'quantity' => 5]], $branch->id));
        $this->assertCount(1, $service->cartShortfalls([['menu_item' => $f['item'], 'quantity' => 6]], $branch->id));
    }

    public function test_item_cost_stays_the_whole_recipe(): void
    {
        $f = $this->plate($this->sizeBranch('A'));

        // 1 x ₱10 HIGH + 2 x ₱5 LOW.
        $this->assertSame(20.0, app(\App\Services\MenuItemCosting::class)->costFor($f['item']));
    }

    public function test_analytics_out_of_stock_list_ignores_a_low_shortage(): void
    {
        $branch = $this->sizeBranch('A');
        $lowShort = $this->plate($branch, 100, 0, 'Low Short');
        $highShort = $this->plate($branch, 0, 100, 'High Short');

        $short = (new \App\Services\AnalyticsService($branch->id))
            ->menuItemsOutOfStock()->pluck('id')->all();

        $this->assertContains($highShort['item']->id, $short);
        $this->assertNotContains($lowShort['item']->id, $short);
    }

    public function test_production_capacity_is_not_limited_by_a_low_line(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 7, 0);

        $row = app(\App\Services\ProductionCapacityService::class)->forMenuItem($this->loaded($f['item']), $branch->id);

        $this->assertSame(7, $row['capacity']);
        $this->assertSame($f['high']->id, $row['bottleneck_inventory_id']);
    }

    public function test_an_item_with_only_low_lines_has_a_recipe_but_nothing_measurable(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        $line = $this->baseRecipeLine($item, $this->sizeInventory($branch->id, 0), 1);
        $line->update(['priority' => 'low']);

        $loaded = $this->loaded($item);
        $this->assertTrue($loaded->hasRecipe());
        $this->assertSame('in_stock', $loaded->stockStatus([])['state']);
        $this->assertSame(
            InventoryDeductionService::UNMEASURABLE_NO_INGREDIENT,
            app(InventoryDeductionService::class)->availabilityBreakdownFor($loaded, $branch->id)['reason']
        );
    }

    // ══════════ 3. RECIPE EDITOR ══════════

    public function test_owner_and_own_branch_supervisor_can_switch_priority(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);

        foreach ([$this->sizeOwner(), $this->sizeSupervisor($branch->id)] as $actor) {
            $response = $this->actingAs($actor, 'admin')
                ->putJson(route('admin.menu-items.ingredients.priority', [$f['item']->id, $f['highLine']->id]), ['priority' => 'low']);

            $response->assertOk()->assertJson(['success' => true, 'priority' => 'low']);
            $this->assertSame('low', $f['highLine']->fresh()->priority);

            $f['highLine']->update(['priority' => 'high']);
        }
    }

    public function test_staff_cannot_switch_priority_and_nothing_changes(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);

        $this->actingAs($this->sizeStaff($branch->id), 'admin')
            ->putJson(route('admin.menu-items.ingredients.priority', [$f['item']->id, $f['highLine']->id]), ['priority' => 'low'])
            // RoleMiddleware's friendly bounce, which the editor's script reads as a refusal.
            ->assertRedirect(route('admin.home'));

        $this->assertSame('high', $f['highLine']->fresh()->priority);
    }

    public function test_a_supervisor_cannot_switch_another_branchs_recipe(): void
    {
        $f = $this->plate($this->sizeBranch('A'));

        $this->actingAs($this->sizeSupervisor($this->sizeBranch('B')->id), 'admin')
            ->putJson(route('admin.menu-items.ingredients.priority', [$f['item']->id, $f['highLine']->id]), ['priority' => 'low'])
            ->assertNotFound();

        $this->assertSame('high', $f['highLine']->fresh()->priority);
    }

    public function test_a_guest_cannot_switch_priority(): void
    {
        $f = $this->plate($this->sizeBranch('A'));

        $response = $this->putJson(route('admin.menu-items.ingredients.priority', [$f['item']->id, $f['highLine']->id]), ['priority' => 'low']);

        $this->assertContains($response->status(), [401, 302, 403]);
        $this->assertSame('high', $f['highLine']->fresh()->priority);
    }

    public function test_another_items_line_is_not_reachable_through_this_item(): void
    {
        $branch = $this->sizeBranch('A');
        $a = $this->plate($branch);
        $b = $this->plate($branch, 100, 100, 'Other');

        $this->actingAs($this->sizeOwner(), 'admin')
            ->putJson(route('admin.menu-items.ingredients.priority', [$a['item']->id, $b['highLine']->id]), ['priority' => 'low'])
            ->assertNotFound();

        $this->assertSame('high', $b['highLine']->fresh()->priority);
    }

    public function test_an_invalid_priority_is_refused(): void
    {
        $f = $this->plate($this->sizeBranch('A'));

        $this->actingAs($this->sizeOwner(), 'admin')
            ->putJson(route('admin.menu-items.ingredients.priority', [$f['item']->id, $f['highLine']->id]), ['priority' => 'medium'])
            ->assertStatus(422);

        $this->assertSame('high', $f['highLine']->fresh()->priority);
    }

    public function test_switching_answers_with_the_items_new_status(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 0, 100);

        $this->actingAs($this->sizeOwner(), 'admin')
            ->putJson(route('admin.menu-items.ingredients.priority', [$f['item']->id, $f['highLine']->id]), ['priority' => 'low'])
            ->assertOk()
            ->assertJsonPath('status.state', 'in_stock');
    }

    public function test_a_size_recipe_line_switches_under_the_same_rules(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $line = MenuItemSizeIngredient::where('menu_item_size_id', $f['regular']->id)->first();
        $url = route('admin.menu-items.sizes.ingredients.priority', [$f['item']->id, $f['regular']->id, $line->id]);

        $this->actingAs($this->sizeStaff($branch->id), 'admin')->putJson($url, ['priority' => 'low'])->assertRedirect(route('admin.home'));
        $this->assertSame('high', $line->fresh()->priority);

        $this->actingAs($this->sizeSupervisor($this->sizeBranch('B')->id), 'admin')->putJson($url, ['priority' => 'low'])->assertNotFound();
        $this->assertSame('high', $line->fresh()->priority);

        $this->actingAs($this->sizeSupervisor($branch->id), 'admin')->putJson($url, ['priority' => 'low'])->assertOk();
        $this->assertSame('low', $line->fresh()->priority);

        // Another size's line is not reachable through this size.
        $large = MenuItemSizeIngredient::where('menu_item_size_id', $f['large']->id)->first();
        $this->actingAs($this->sizeOwner(), 'admin')
            ->putJson(route('admin.menu-items.sizes.ingredients.priority', [$f['item']->id, $f['regular']->id, $large->id]), ['priority' => 'low'])
            ->assertNotFound();
    }

    public function test_the_editor_shows_priority_and_stock_with_whole_numbers_for_count_units(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 12.5, 0);
        DB::table('inventory')->where('id', $f['low']->id)->update(['unit' => 'pcs', 'quantity' => 6]);

        $html = $this->actingAs($this->sizeOwner(), 'admin')
            ->withSession(['selected_branch_id' => $branch->id])
            ->get(route('admin.menu-items'))
            ->assertOk()
            ->getContent();

        $start = strpos($html, 'id="recipe-' . $f['item']->id . '"');
        $block = substr($html, $start, strpos($html, 'id="recipe-profit-' . $f['item']->id . '"', $start) - $start);

        $this->assertStringContainsString('Priority Level', $block);
        $this->assertStringContainsString('Stock Remaining', $block);
        $this->assertMatchesRegularExpression('/recipe-priority-pill is-high[^>]*>High</', $block);
        $this->assertMatchesRegularExpression('/recipe-priority-pill is-low[^>]*>Low</', $block);
        $this->assertMatchesRegularExpression('/recipe-stock recipe-stock-\w+">6 pcs</', $block);
        $this->assertMatchesRegularExpression('/recipe-stock recipe-stock-\w+">12\.5 g</', $block);
    }

    /**
     * The pill's look must apply at every width. It once sat inside the
     * max-width: 600px block, which left a bare grey button on desktop while
     * every other check passed.
     */
    public function test_the_pill_styles_are_not_inside_a_media_query(): void
    {
        $source = file_get_contents(resource_path('views/admin/menu-items.blade.php'));
        $style = substr($source, strpos($source, '<style>'));
        $rule = strpos($style, '.recipe-priority-pill {');

        $this->assertNotFalse($rule);

        $before = substr($style, 0, $rule);
        $this->assertSame(
            substr_count($before, '{'),
            substr_count($before, '}'),
            'The rule is nested inside another block.'
        );
    }

    public function test_staff_get_no_recipe_editor_at_all(): void
    {
        $branch = $this->sizeBranch('A');
        $this->plate($branch);

        $this->actingAs($this->sizeStaff($branch->id), 'admin')
            ->get(route('admin.menu-items'))
            ->assertOk()
            ->assertDontSee('class="recipe-priority-pill', false)
            ->assertDontSee('Priority Level');
    }

    public function test_adding_an_ingredient_answers_with_its_priority_and_stock(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);
        $inv = $this->sizeInventory($branch->id, 42);

        $this->actingAs($this->sizeOwner(), 'admin')
            ->postJson(route('admin.menu-items.ingredients.add', $f['item']->id), ['inventory_id' => $inv->id, 'quantity_used' => 1])
            ->assertOk()
            ->assertJsonPath('ingredient.priority', 'high')
            ->assertJsonPath('ingredient.stock_remaining', '42');
    }

    public function test_a_new_item_saves_the_priority_chosen_on_its_draft_row(): void
    {
        $branch = $this->sizeBranch('A');
        $high = $this->sizeInventory($branch->id);
        $low = $this->sizeInventory($branch->id);
        $name = 'Draft Priority ' . uniqid();

        $this->actingAs($this->sizeOwner(), 'admin')
            ->withSession(['selected_branch_id' => $branch->id])
            ->post(route('admin.new-menu-item.post'), [
                'category_id' => $this->sizeCategory()->id,
                'name'        => $name,
                'price'       => 100,
                'ingredients' => [
                    ['inventory_id' => $high->id, 'quantity_used' => 1],
                    ['inventory_id' => $low->id, 'quantity_used' => 1, 'priority' => 'low'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $item = MenuItem::where('name', $name)->firstOrFail();
        $this->assertSame('high', $item->recipeIngredients()->where('inventory_id', $high->id)->value('priority'));
        $this->assertSame('low', $item->recipeIngredients()->where('inventory_id', $low->id)->value('priority'));
    }

    // ══════════ 4. CUSTOMER ITEM PAGE ══════════

    public function test_the_card_lists_each_low_ingredient_and_never_a_high_one(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);

        $page = $this->asGuest($branch)->get(route('customer.item', $f['item']->id))->assertOk();

        $page->assertSee('Remove Ingredients');
        $page->assertSee('Included by default');
        $page->assertSee('Optional');
        $page->assertSee("Choose ingredients you don't want included in your order.", false);
        $page->assertSee('Remove Pancake Syrup');
        $page->assertDontSee('Remove Corned Beef');
        $page->assertSee('name="removed_ingredients[]" value="' . $f['low']->id . '"', false);
        $page->assertDontSee('value="' . $f['high']->id . '"', false);
        // In stock: the "Not available today" wording ships hidden, for the quantity script.
        $this->assertMatchesRegularExpression('/data-removal-off\s+hidden/', $page->getContent());
    }

    public function test_the_card_is_hidden_when_the_item_has_no_low_ingredient(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        $this->baseRecipeLine($item, $this->sizeInventory($branch->id), 1);

        $this->asGuest($branch)->get(route('customer.item', $item->id))->assertOk()
            ->assertDontSee('id="removeIngredientsCard"', false)
            ->assertDontSee('Included by default');
    }

    public function test_an_out_of_stock_low_ingredient_reads_not_available_and_cannot_be_selected(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 100, 0);

        $html = $this->asGuest($branch)->get(route('customer.item', $f['item']->id))->assertOk()->getContent();

        $this->assertStringContainsString('Not available today', $html);
        $this->assertStringContainsString('It will not be included in your order.', $html);
        $this->assertMatchesRegularExpression('/data-removal-on\s+hidden/', $html);
        $this->assertMatchesRegularExpression(
            '/value="' . $f['low']->id . '"\s+id="[^"]+"\s+class="removal-input sr-only"\s+disabled/',
            $html
        );
        $this->assertStringContainsString('data-servings="0"', $html);
    }

    public function test_a_servings_figure_ships_so_the_page_can_judge_the_chosen_quantity(): void
    {
        $branch = $this->sizeBranch('A');
        // 7 on the shelf at 2 per serving covers 3 servings.
        $f = $this->plate($branch, 100, 7);

        $this->asGuest($branch)->get(route('customer.item', $f['item']->id))->assertOk()
            ->assertSee('data-servings="3"', false);
    }

    public function test_what_open_orders_hold_counts_against_the_low_ingredient(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 100, 2);

        // Another customer's open order holds the only 2 of syrup.
        $this->placePickUp($branch, $this->cartLine($f['item'], null, 1))->assertRedirect(route('customer.orders'));
        $this->assertSame([], $this->removalsOf($this->latestOrderAt($branch)->items->first()));

        $reserved = app(InventoryDeductionService::class)->committedQuantities();
        $choice = $this->loaded($f['item'])->removableIngredients($reserved)[0];

        $this->assertSame(0, $choice['servings']);
        $this->assertFalse($choice['available']);
    }

    public function test_a_sized_item_lists_each_sizes_own_low_ingredients(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $regularLow = $this->named($this->sizeInventory($branch->id), 'Regular Garnish');
        $largeLow = $this->named($this->sizeInventory($branch->id), 'Large Garnish');
        MenuItemSizeIngredient::create(['menu_item_size_id' => $f['regular']->id, 'inventory_id' => $regularLow->id, 'quantity' => 1, 'priority' => 'low']);
        MenuItemSizeIngredient::create(['menu_item_size_id' => $f['large']->id, 'inventory_id' => $largeLow->id, 'quantity' => 1, 'priority' => 'low']);

        $html = $this->asGuest($branch)->get(route('customer.item', $f['item']->id))->assertOk()->getContent();

        $this->assertStringContainsString('data-removal-group="' . $f['regular']->id . '"', $html);
        $this->assertMatchesRegularExpression('/data-removal-group="' . $f['large']->id . '"\s+hidden/', $html);
        $this->assertStringContainsString('Remove Regular Garnish', $html);
        $this->assertStringContainsString('Remove Large Garnish', $html);
    }

    // ══════════ 5. CART, ORDER AND RECEIPTS ══════════

    public function test_a_removal_reaches_the_cart_as_its_own_line_at_the_same_price(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);
        $add = fn (array $extra = []) => $this->asGuest($branch)
            ->from(route('customer.item', $f['item']->id))
            ->post(route('customer.cart.add'), array_merge(['item_id' => $f['item']->id, 'quantity' => 1], $extra));

        $add();
        $add(['removed_ingredients' => [$f['low']->id]]);

        $cart = session('cart');
        $this->assertCount(2, $cart, 'The same item with different removals is a separate line.');
        $this->assertArrayHasKey((string) $f['item']->id, $cart);
        $key = $f['item']->id . '_r' . $f['low']->id;
        $this->assertArrayHasKey($key, $cart);
        $this->assertSame([$f['low']->id], $cart[$key]['removed_ingredients']);
        $this->assertSame((float) $cart[(string) $f['item']->id]['price'], (float) $cart[$key]['price'], 'Removing never changes the price.');

        // The same choice again adds to that line.
        $add(['removed_ingredients' => [$f['low']->id]]);
        $this->assertCount(2, session('cart'));
        $this->assertSame(2, session('cart')[$key]['quantity']);
    }

    public function test_a_crafted_request_cannot_remove_a_high_ingredient(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);

        $this->asGuest($branch, ['cart' => []])
            ->from(route('customer.item', $f['item']->id))
            ->post(route('customer.cart.add'), [
                'item_id' => $f['item']->id, 'quantity' => 1, 'removed_ingredients' => [$f['high']->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Corned Beef cannot be removed from Plate.');

        $this->assertEmpty(session('cart'));
    }

    public function test_a_crafted_request_cannot_remove_another_items_ingredient(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);
        $other = $this->plate($branch, 100, 100, 'Other');

        $this->asGuest($branch, ['cart' => []])
            ->from(route('customer.item', $f['item']->id))
            ->post(route('customer.cart.add'), [
                'item_id' => $f['item']->id, 'quantity' => 1, 'removed_ingredients' => [$other['low']->id],
            ])
            ->assertSessionHas('error', 'That ingredient cannot be removed from Plate.');

        $this->assertEmpty(session('cart'));
    }

    public function test_a_crafted_removal_must_belong_to_the_chosen_size(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $largeLow = $this->sizeInventory($branch->id);
        MenuItemSizeIngredient::create(['menu_item_size_id' => $f['large']->id, 'inventory_id' => $largeLow->id, 'quantity' => 1, 'priority' => 'low']);

        $this->asGuest($branch, ['cart' => []])
            ->from(route('customer.item', $f['item']->id))
            ->post(route('customer.cart.add'), [
                'item_id' => $f['item']->id, 'quantity' => 1, 'size_id' => $f['regular']->id, 'removed_ingredients' => [$largeLow->id],
            ])
            ->assertSessionHas('error');
        $this->assertEmpty(session('cart'));

        $this->asGuest($branch, ['cart' => []])
            ->from(route('customer.item', $f['item']->id))
            ->post(route('customer.cart.add'), [
                'item_id' => $f['item']->id, 'quantity' => 1, 'size_id' => $f['large']->id, 'removed_ingredients' => [$largeLow->id],
            ])
            ->assertSessionHas('success');
        $this->assertCount(1, session('cart'));
    }

    public function test_the_cart_says_what_was_removed_and_what_is_not_available(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);
        $garnish = $this->named($this->sizeInventory($branch->id, 0), 'Garnish');
        MenuItemIngredient::create(['menu_item_id' => $f['item']->id, 'inventory_id' => $garnish->id, 'quantity_used' => 1, 'priority' => 'low']);

        $cart = $this->cartLineRemoving($f['item'], 1, [$f['low']->id]);

        $page = $this->asGuest($branch, ['cart' => $cart])->get(route('customer.cart'))->assertOk();

        $page->assertSee('No Pancake Syrup');
        $page->assertSee('Garnish not available');
        $page->assertDontSee('out of stock due to ingredient availability');
    }

    public function test_the_quantity_sync_reports_when_a_low_ingredient_runs_out_of_room(): void
    {
        $branch = $this->sizeBranch('A');
        // 5 of syrup at 2 per serving covers 2 servings, not 3.
        $f = $this->plate($branch, 100, 5);
        $key = (string) $f['item']->id;

        $ok = $this->asGuest($branch, ['cart' => $this->cartLine($f['item'], null, 1)])
            ->putJson(route('customer.cart.update', $key), ['quantity' => 2])
            ->assertOk();
        $this->assertSame([], $ok->json('lines.' . $key . '.removal_labels'));

        $short = $this->asGuest($branch, ['cart' => $this->cartLine($f['item'], null, 1)])
            ->putJson(route('customer.cart.update', $key), ['quantity' => 3])
            ->assertOk();
        $this->assertSame(['Pancake Syrup not available'], $short->json('lines.' . $key . '.removal_labels'));
    }

    public function test_a_customer_removal_is_saved_as_a_snapshot_with_the_name(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);

        $this->placePickUp($branch, $this->cartLineRemoving($f['item'], 1, [$f['low']->id]))
            ->assertRedirect(route('customer.orders'));

        $line = $this->latestOrderAt($branch)->items->first();

        $this->assertSame(
            [['name' => 'Pancake Syrup', 'reason' => 'customer', 'inventory_id' => $f['low']->id]],
            $this->removalsOf($line)
        );
        $this->assertSame(150.0, (float) $line->item_price, 'The price does not change.');
    }

    public function test_an_out_of_stock_low_ingredient_is_recorded_as_unavailable_and_does_not_block(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 100, 0);

        $this->placePickUp($branch, $this->cartLine($f['item'], null, 1))
            ->assertRedirect(route('customer.orders'));

        $this->assertSame(
            [['name' => 'Pancake Syrup', 'reason' => 'unavailable', 'inventory_id' => $f['low']->id]],
            $this->removalsOf($this->latestOrderAt($branch)->items->first())
        );
    }

    public function test_not_enough_for_the_quantity_counts_as_unavailable_for_the_whole_line(): void
    {
        $branch = $this->sizeBranch('A');
        // Covers 2 servings, 3 ordered: none of it goes on this line.
        $f = $this->plate($branch, 100, 5);

        $this->placePickUp($branch, $this->cartLine($f['item'], null, 3))
            ->assertRedirect(route('customer.orders'));

        $order = $this->latestOrderAt($branch);
        $this->assertSame('unavailable', $this->removalsOf($order->items->first())[0]['reason']);

        $this->assertArrayNotHasKey(
            $f['low']->id,
            app(InventoryDeductionService::class)->committedQuantities(),
            'Nothing of it is held for a line that goes out without it.'
        );
    }

    public function test_a_high_shortage_still_refuses_the_order(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 0, 100);

        $this->placePickUp($branch, $this->cartLine($f['item'], null, 1))->assertSessionHasErrors('items');

        $this->assertNull($this->latestOrderAt($branch));
    }

    public function test_a_low_line_never_starves_a_high_need_elsewhere_in_the_cart(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 100, 2);
        // The same 2 of syrup is ESSENTIAL to this item, and it sits later in the cart.
        $bottle = $this->sizeItem($branch->id, 'Syrup Bottle', 80);
        $this->baseRecipeLine($bottle, $f['low'], 2);

        $service = app(InventoryDeductionService::class);
        $settled = $service->settleCart([
            ['menu_item' => $f['item'], 'quantity' => 1],
            ['menu_item' => $bottle, 'quantity' => 1],
        ], $branch->id);

        $this->assertSame([], $settled['messages']);
        $this->assertSame([0 => [['inventory_id' => $f['low']->id, 'name' => 'Pancake Syrup']]], $settled['unavailable']);
    }

    public function test_two_lines_share_a_low_ingredient_in_cart_order(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 100, 2);
        $other = $this->sizeItem($branch->id, 'Other', 80);
        MenuItemIngredient::create(['menu_item_id' => $other->id, 'inventory_id' => $f['low']->id, 'quantity_used' => 2, 'priority' => 'low']);

        $settled = app(InventoryDeductionService::class)->settleCart([
            ['menu_item' => $f['item'], 'quantity' => 1],
            ['menu_item' => $other, 'quantity' => 1],
        ], $branch->id);

        $this->assertArrayNotHasKey(0, $settled['unavailable']);
        $this->assertSame($f['low']->id, $settled['unavailable'][1][0]['inventory_id']);
    }

    public function test_an_add_on_using_the_same_ingredient_still_needs_it_when_the_recipe_line_is_removed(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 100, 1);
        $option = $this->addOn($f['item'], $branch);
        \App\Models\MenuOptionIngredient::create(['menu_option_id' => $option->id, 'inventory_id' => $f['low']->id, 'quantity_used' => 3]);

        $service = app(InventoryDeductionService::class);

        // Add-ons are not priority-rated: 3 needed, 1 on the shelf, so it refuses.
        $this->assertCount(1, $service->cartShortfalls([[
            'menu_item' => $f['item'], 'quantity' => 1, 'selected_option_ids' => [$option->id], 'removed_inventory_ids' => [$f['low']->id],
        ]], $branch->id));
    }

    public function test_checkout_refuses_a_removal_that_has_since_become_high(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);
        $cart = $this->cartLineRemoving($f['item'], 1, [$f['low']->id]);

        $f['lowLine']->update(['priority' => 'high']);

        $this->asGuest($branch, ['cart' => $cart])->get(route('customer.cart'))->assertOk()
            ->assertSee('An ingredient you removed from an item can no longer be removed.');

        $this->placePickUp($branch, $cart)->assertSessionHasErrors('items');
        $this->assertNull($this->latestOrderAt($branch));
    }

    public function test_a_removal_of_a_line_since_deleted_from_the_recipe_is_simply_moot(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);
        $cart = $this->cartLineRemoving($f['item'], 1, [$f['low']->id]);

        $f['lowLine']->delete();

        $this->placePickUp($branch, $cart)->assertRedirect(route('customer.orders'));
        $this->assertSame([], $this->removalsOf($this->latestOrderAt($branch)->items->first()));
    }

    public function test_the_order_page_and_both_receipts_show_the_removals(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);
        $garnish = $this->named($this->sizeInventory($branch->id, 0), 'Garnish');
        MenuItemIngredient::create(['menu_item_id' => $f['item']->id, 'inventory_id' => $garnish->id, 'quantity_used' => 1, 'priority' => 'low']);

        $this->placePickUp($branch, $this->cartLineRemoving($f['item'], 1, [$f['low']->id]))
            ->assertRedirect(route('customer.orders'));
        $order = $this->latestOrderAt($branch);

        foreach ([route('customer.orders'), route('customer.receipt', $order->id)] as $url) {
            $this->get($url)->assertOk()->assertSee('No Pancake Syrup')->assertSee('Garnish not available');
        }

        $this->actingAs($this->sizeOwner(), 'admin')
            ->get(route('admin.receipt', $order->id))
            ->assertOk()
            ->assertSee('No Pancake Syrup')
            ->assertSee('Garnish not available');
    }

    public function test_the_kitchen_board_and_its_details_show_the_removals(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);

        $this->placePickUp($branch, $this->cartLineRemoving($f['item'], 1, [$f['low']->id]))
            ->assertRedirect(route('customer.orders'));

        $html = $this->actingAs($this->sizeOwner(), 'admin')
            ->withSession(['selected_branch_id' => $branch->id])
            ->get(route('admin.home'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No Pancake Syrup', $html);
    }

    public function test_completed_orders_show_the_removals_on_screen_print_and_csv(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);
        $owner = $this->sizeOwner();

        $this->placePickUp($branch, $this->cartLineRemoving($f['item'], 1, [$f['low']->id]))
            ->assertRedirect(route('customer.orders'));
        $this->completeAs($owner, $this->latestOrderAt($branch))->assertSessionHasNoErrors();

        $session = ['selected_branch_id' => $branch->id];

        $this->actingAs($owner, 'admin')->withSession($session)->get(route('admin.completed-orders'))
            ->assertOk()->assertSee('1x Plate (No Pancake Syrup)');
        $this->actingAs($owner, 'admin')->withSession($session)->get(route('admin.completed-orders.print'))
            ->assertOk()->assertSee('1x Plate (No Pancake Syrup)');

        $csv = $this->actingAs($owner, 'admin')->withSession($session)->get(route('admin.completed-orders.export'))->streamedContent();
        $this->assertStringContainsString('1x Plate (No Pancake Syrup)', $csv);
    }

    // ══════════ 6. INVENTORY DEDUCTION AND COGS ══════════

    public function test_completion_skips_a_customer_removal_and_cogs_excludes_it(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);

        $this->placePickUp($branch, $this->cartLineRemoving($f['item'], 3, [$f['low']->id]))
            ->assertRedirect(route('customer.orders'));
        $order = $this->latestOrderAt($branch);

        $this->completeAs($this->sizeOwner(), $order)->assertSessionHasNoErrors();

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(97.0, $this->rawQty($f['high']));
        $this->assertSame(100.0, $this->rawQty($f['low']), 'A removed ingredient is not deducted.');
        $this->assertSame(10.0, (float) $order->items->first()->fresh()->ingredient_cost, 'COGS is the ₱10 HIGH line only.');
        $this->assertSame(0, DB::table('stock_movements')->where('inventory_id', $f['low']->id)->count());
    }

    public function test_completion_skips_an_unavailable_low_ingredient(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 100, 1);

        $this->placePickUp($branch, $this->cartLine($f['item'], null, 1))
            ->assertRedirect(route('customer.orders'));
        $order = $this->latestOrderAt($branch);

        $this->completeAs($this->sizeOwner(), $order)->assertSessionHasNoErrors();

        $this->assertSame(1.0, $this->rawQty($f['low']));
        $this->assertSame(99.0, $this->rawQty($f['high']));
        $this->assertSame(10.0, (float) $order->items->first()->fresh()->ingredient_cost);
    }

    public function test_an_included_low_ingredient_is_deducted_and_costed_like_any_other(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);

        $this->placePickUp($branch, $this->cartLine($f['item'], null, 2))
            ->assertRedirect(route('customer.orders'));
        $order = $this->latestOrderAt($branch);

        $this->assertSame([], $this->removalsOf($order->items->first()));
        $this->completeAs($this->sizeOwner(), $order)->assertSessionHasNoErrors();

        $this->assertSame(96.0, $this->rawQty($f['low']));
        $this->assertSame(98.0, $this->rawQty($f['high']));
        $this->assertSame(20.0, (float) $order->items->first()->fresh()->ingredient_cost);
    }

    public function test_committed_stock_follows_the_same_rule(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);
        $g = $this->plate($branch, 100, 100, 'Second');

        $this->placePickUp($branch, $this->cartLineRemoving($f['item'], 2, [$f['low']->id]) + $this->cartLine($g['item'], null, 2))
            ->assertRedirect(route('customer.orders'));

        $committed = app(InventoryDeductionService::class)->committedQuantities();

        $this->assertSame(2.0, (float) $committed[$f['high']->id]);
        $this->assertArrayNotHasKey($f['low']->id, $committed, 'A removed ingredient holds no stock.');
        $this->assertSame(4.0, (float) $committed[$g['low']->id], 'An included LOW ingredient does.');
    }

    public function test_a_low_ingredient_lost_after_placement_does_not_block_completion(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);

        $this->placePickUp($branch, $this->cartLine($f['item'], null, 1))
            ->assertRedirect(route('customer.orders'));
        $order = $this->latestOrderAt($branch);
        $this->assertSame([], $this->removalsOf($order->items->first()));

        // Staff stock out the syrup while the order sits on the board.
        DB::table('inventory')->where('id', $f['low']->id)->update(['quantity' => 0]);

        $this->completeAs($this->sizeOwner(), $order)->assertSessionHasNoErrors();

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(0.0, $this->rawQty($f['low']));
        $this->assertSame(99.0, $this->rawQty($f['high']));
        $this->assertSame(
            [['name' => 'Pancake Syrup', 'reason' => 'unavailable', 'inventory_id' => $f['low']->id]],
            $this->removalsOf($order->items->first())
        );
        $this->assertSame(10.0, (float) $order->items->first()->fresh()->ingredient_cost);
    }

    public function test_a_high_ingredient_lost_after_placement_still_blocks_completion(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);

        $this->placePickUp($branch, $this->cartLine($f['item'], null, 1))
            ->assertRedirect(route('customer.orders'));
        $order = $this->latestOrderAt($branch);

        DB::table('inventory')->where('id', $f['high']->id)->update(['quantity' => 0]);

        $this->completeAs($this->sizeOwner(), $order)->assertSessionHasErrors('order');

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(100.0, $this->rawQty($f['low']));
    }

    public function test_a_removal_stays_honoured_after_the_ingredient_is_marked_high(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);

        $this->placePickUp($branch, $this->cartLineRemoving($f['item'], 1, [$f['low']->id]))
            ->assertRedirect(route('customer.orders'));
        $order = $this->latestOrderAt($branch);

        $f['lowLine']->update(['priority' => 'high']);

        $this->completeAs($this->sizeOwner(), $order)->assertSessionHasNoErrors();
        $this->assertSame(100.0, $this->rawQty($f['low']));
    }

    public function test_a_sized_line_freezes_the_priority_and_honours_it_at_completion(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $syrup = $this->named($this->sizeInventory($branch->id, 10, 4), 'Size Syrup');
        $garnish = $this->named($this->sizeInventory($branch->id, 10, 4), 'Size Garnish');

        foreach ([$syrup, $garnish] as $low) {
            MenuItemSizeIngredient::create(['menu_item_size_id' => $f['regular']->id, 'inventory_id' => $low->id, 'quantity' => 1, 'priority' => 'low']);
        }

        $item = $this->loaded($f['item']);
        $this->placePickUp($branch, $this->cartLineRemoving($item, 1, [$syrup->id], $f['regular']))
            ->assertRedirect(route('customer.orders'));

        $order = $this->latestOrderAt($branch);
        $line = $order->items->first();

        $this->assertSame('low', DB::table('order_item_size_ingredients')->where('order_item_id', $line->id)->where('inventory_id', $garnish->id)->value('priority'));
        $this->assertSame('high', DB::table('order_item_size_ingredients')->where('order_item_id', $line->id)->where('inventory_id', $f['regInv']->id)->value('priority'));

        // Editing the live size afterwards changes nothing about this line.
        MenuItemSizeIngredient::where('menu_item_size_id', $f['regular']->id)->where('inventory_id', $garnish->id)->update(['priority' => 'high']);

        // Lost after placement: frozen as LOW, so it drops off rather than blocks.
        DB::table('inventory')->where('id', $garnish->id)->update(['quantity' => 0]);

        $this->completeAs($this->sizeOwner(), $order)->assertSessionHasNoErrors();

        $this->assertSame(10.0, $this->rawQty($syrup), 'Customer removal: not deducted.');
        $this->assertSame(0.0, $this->rawQty($garnish));
        $this->assertSame(99.0, $this->rawQty($f['regInv']));
        $this->assertSame(2.0, (float) $line->fresh()->ingredient_cost, 'Only the ₱2 HIGH line.');
        $this->assertSame(
            ['Size Syrup' => 'customer', 'Size Garnish' => 'unavailable'],
            collect($this->removalsOf($line))->pluck('reason', 'name')->all()
        );
    }

    public function test_a_line_with_everything_removed_reports_a_true_zero_cost(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id, 'All Low', 50);
        $inv = $this->sizeInventory($branch->id, 10, 7);
        $this->baseRecipeLine($item, $inv, 1)->update(['priority' => 'low']);

        $this->placePickUp($branch, $this->cartLineRemoving($item->fresh(), 1, [$inv->id]))
            ->assertRedirect(route('customer.orders'));
        $order = $this->latestOrderAt($branch);

        $this->completeAs($this->sizeOwner(), $order)->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) $order->items->first()->fresh()->ingredient_cost);

        $report = app(ProfitCalculationService::class)->forRange(now()->subHour(), now()->addHour(), $branch->id);
        $this->assertSame(0.0, (float) $report['cogs'], 'Not the ₱7 recipe cost the fallback would have used.');
    }

    // ══════════ History: old orders unaffected, snapshots stay put ══════════

    public function test_an_order_placed_before_a_line_was_marked_low_completes_as_sold(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id, 'Old', 50);
        $inv = $this->sizeInventory($branch->id, 10, 3);
        $line = $this->baseRecipeLine($item, $inv, 2);

        $this->placePickUp($branch, $this->cartLine($item->fresh(), null, 1))
            ->assertRedirect(route('customer.orders'));
        $order = $this->latestOrderAt($branch);

        $line->update(['priority' => 'low']);

        $this->completeAs($this->sizeOwner(), $order)->assertSessionHasNoErrors();

        $this->assertSame(8.0, $this->rawQty($inv), 'Still deducted: the line was not removed.');
        $this->assertSame(6.0, (float) $order->items->first()->fresh()->ingredient_cost);
        $this->assertSame([], $this->removalsOf($order->items->first()));
    }

    public function test_a_line_with_no_removals_prints_exactly_as_it_always_did(): void
    {
        $branch = $this->sizeBranch('A');
        $tea = $this->unsizedItem($branch, $this->sizeInventory($branch->id, 50));

        $this->placePickUp($branch, $this->cartLine($tea, null, 1))->assertRedirect(route('customer.orders'));
        $line = $this->latestOrderAt($branch)->items->first();

        $this->assertSame([], $line->removalLabels());
        $this->assertSame('Plain Tea', $line->displayName());
    }

    public function test_the_snapshot_survives_a_recipe_change_a_rename_and_a_delete(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);

        $this->placePickUp($branch, $this->cartLineRemoving($f['item'], 1, [$f['low']->id]))
            ->assertRedirect(route('customer.orders'));
        $line = $this->latestOrderAt($branch)->items->first();

        $f['low']->update(['item_name' => 'Renamed Syrup']);
        $f['lowLine']->delete();
        $this->assertSame(['No Pancake Syrup'], OrderItem::find($line->id)->removalLabels());

        // A permanently deleted inventory row leaves the name and loses the link.
        DB::table('inventory')->where('id', $f['low']->id)->delete();
        $this->assertSame(['No Pancake Syrup'], OrderItem::find($line->id)->removalLabels());
        $this->assertNull($this->removalsOf($line)[0]['inventory_id']);
    }

    public function test_the_wording_is_one_place(): void
    {
        $this->assertSame('No Pancake Syrup', OrderItemRemovedIngredient::labelFor('Pancake Syrup', OrderItemRemovedIngredient::REASON_CUSTOMER));
        $this->assertSame('Pancake Syrup not available', OrderItemRemovedIngredient::labelFor('Pancake Syrup', OrderItemRemovedIngredient::REASON_UNAVAILABLE));
    }

    // ══════════ Walk-in counter ══════════

    public function test_the_counter_card_ships_its_remove_choices(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 100, 0);

        $html = $this->actingAs($this->sizeStaff($branch->id), 'admin')
            ->get(route('admin.home'))
            ->assertOk()
            ->getContent();

        $start = strpos($html, 'data-id="' . $f['item']->id . '"');
        $this->assertNotFalse($start);
        preg_match('/data-removable="([^"]*)"/', substr($html, $start), $m);
        $rows = json_decode(html_entity_decode($m[1]), true)['base'];

        $this->assertCount(1, $rows);
        $this->assertSame('Pancake Syrup', $rows[0]['name']);
        $this->assertFalse($rows[0]['available']);
    }

    public function test_a_counter_order_removes_snapshots_and_ignores_a_low_shortage(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);
        $garnish = $this->named($this->sizeInventory($branch->id, 0), 'Garnish');
        MenuItemIngredient::create(['menu_item_id' => $f['item']->id, 'inventory_id' => $garnish->id, 'quantity_used' => 1, 'priority' => 'low']);
        $staff = $this->sizeStaff($branch->id);

        $this->counterOrder($staff, $branch, [[
            'menu_item_id' => $f['item']->id, 'quantity' => 2, 'removed_ingredients' => [$f['low']->id],
        ]])->assertSessionHasNoErrors();

        $order = $this->latestOrderAt($branch);
        $line = $order->items->first();

        $this->assertSame(
            ['Pancake Syrup' => 'customer', 'Garnish' => 'unavailable'],
            collect($this->removalsOf($line))->pluck('reason', 'name')->all()
        );
        $this->assertSame(300.0, (float) $line->subtotal, 'The price does not change.');

        $this->completeAs($staff, $order)->assertSessionHasNoErrors();
        $this->assertSame(100.0, $this->rawQty($f['low']));
        $this->assertSame(98.0, $this->rawQty($f['high']));
    }

    public function test_the_counter_refuses_a_crafted_high_removal(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch);

        $this->counterOrder($this->sizeStaff($branch->id), $branch, [[
            'menu_item_id' => $f['item']->id, 'quantity' => 1, 'removed_ingredients' => [$f['high']->id],
        ]])->assertSessionHasErrors(['items' => 'Corned Beef cannot be removed from Plate.']);

        $this->assertNull($this->latestOrderAt($branch));
    }

    public function test_the_counter_still_refuses_a_high_shortage(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->plate($branch, 0, 100);

        $this->counterOrder($this->sizeStaff($branch->id), $branch, [[
            'menu_item_id' => $f['item']->id, 'quantity' => 1,
        ]])->assertSessionHasErrors('items');

        $this->assertNull($this->latestOrderAt($branch));
    }

    public function test_the_migration_refuses_to_roll_back_while_low_lines_exist(): void
    {
        $f = $this->plate($this->sizeBranch('A'));
        $this->assertSame('low', $f['lowLine']->priority);

        $migration = require database_path('migrations/2026_10_31_100000_add_ingredient_priority_and_order_item_removed_ingredients.php');

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}

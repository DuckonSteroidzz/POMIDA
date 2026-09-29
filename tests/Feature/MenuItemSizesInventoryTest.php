<?php

namespace Tests\Feature;

use App\Exceptions\MenuItemSizeUnavailableException;
use App\Models\MenuItem;
use App\Models\MenuItemSize;
use App\Models\MenuOption;
use App\Models\MenuOptionIngredient;
use App\Services\InventoryDeductionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\MenuItemSizeFixtures;
use Tests\TestCase;

/**
 * Menu Item Sizes, Phase 1 — size-aware inventory functions.
 *
 * requirementsForLine(), hasIngredientStock() and remainingServings() take an
 * optional size. With one, the size's OWN recipe replaces the base recipe,
 * through one resolver (MenuItem::sizeRecipe()); a size that is missing, not
 * this item's, inactive, archived or recipe-less is refused and never falls
 * back to the base recipe. With none, nothing changed.
 *
 * Each test builds its own branch, inventory, item and sizes.
 */
class MenuItemSizesInventoryTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeFixtures;

    private InventoryDeductionService $deduction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
        $this->deduction = app(InventoryDeductionService::class);
    }

    /**
     * A sized "Test Coffee" whose base recipe, Regular recipe and Large recipe
     * each use DIFFERENT inventory rows, so any leak between them is visible.
     */
    private function coffee(): array
    {
        $branch = $this->sizeBranch('A');
        $beans = $this->sizeInventory($branch->id, 1000, 1, 'Beans');
        $milk = $this->sizeInventory($branch->id, 1000, 1, 'Milk');
        $cup = $this->sizeInventory($branch->id, 1000, 1, 'Base cup');

        $item = $this->sizeItem($branch->id);
        $this->baseRecipeLine($item, $cup, 1);          // base recipe: 1 cup only

        [$regular, $large] = $this->enableSizes($item, 100, 150);
        $this->sizeRecipeLine($regular, $beans, 18);    // Regular: 18 beans + 150 milk
        $this->sizeRecipeLine($regular, $milk, 150);
        $this->sizeRecipeLine($large, $beans, 24.5);    // Large: 24.5 beans + 250 milk
        $this->sizeRecipeLine($large, $milk, 250);

        return compact('branch', 'beans', 'milk', 'cup', 'item', 'regular', 'large');
    }

    private function assertRefused(callable $call, string $reason): void
    {
        try {
            $call();
        } catch (MenuItemSizeUnavailableException $e) {
            $this->assertSame($reason, $e->reason());

            return;
        }

        $this->fail('Expected the size to be refused (' . $reason . '), but it was accepted.');
    }

    // ══════════ 13-16. The size recipe is the recipe ══════════

    public function test_requirements_for_regular_use_the_regular_recipe(): void
    {
        $c = $this->coffee();

        $this->assertEquals(
            [$c['beans']->id => 36.0, $c['milk']->id => 300.0],
            $this->deduction->requirementsForLine($c['item'], 2, [], $c['branch']->id, $c['regular'])
        );
    }

    public function test_requirements_for_large_use_the_large_recipe(): void
    {
        $c = $this->coffee();

        $this->assertEquals(
            [$c['beans']->id => 73.5, $c['milk']->id => 750.0],
            $this->deduction->requirementsForLine($c['item'], 3, [], $c['branch']->id, $c['large']->id)
        );
    }

    public function test_regular_and_large_recipes_are_independent(): void
    {
        $c = $this->coffee();
        $sugar = $this->sizeInventory($c['branch']->id, 1000, 1, 'Sugar');

        $this->sizeRecipeLine($c['regular'], $sugar, 5);
        DB::table('menu_item_size_ingredients')->where('menu_item_size_id', $c['regular']->id)
            ->where('inventory_id', $c['milk']->id)->update(['quantity' => 100]);

        $this->assertEquals(
            [$c['beans']->id => 18.0, $c['milk']->id => 100.0, $sugar->id => 5.0],
            $this->deduction->requirementsForLine($c['item'], 1, [], $c['branch']->id, $c['regular'])
        );
        $this->assertEquals(
            [$c['beans']->id => 24.5, $c['milk']->id => 250.0],
            $this->deduction->requirementsForLine($c['item'], 1, [], $c['branch']->id, $c['large']),
            'editing Regular must not touch Large'
        );
    }

    public function test_a_size_recipe_replaces_the_base_recipe_rather_than_adding_to_it(): void
    {
        $c = $this->coffee();

        $sized = $this->deduction->requirementsForLine($c['item'], 1, [], $c['branch']->id, $c['regular']);
        $this->assertArrayNotHasKey($c['cup']->id, $sized, 'the base recipe line must not be part of a size');

        // And with NO size, the same item still reads its base recipe — sizes
        // never engage implicitly.
        $this->assertEquals(
            [$c['cup']->id => 1.0],
            $this->deduction->requirementsForLine($c['item'], 1, [], $c['branch']->id)
        );
    }

    public function test_add_ons_are_added_the_same_way_on_top_of_a_size_recipe(): void
    {
        $c = $this->coffee();
        $syrup = $this->sizeInventory($c['branch']->id, 1000, 1, 'Syrup');
        $option = MenuOption::create(['name' => 'MIS Syrup ' . uniqid(), 'additional_price' => 10, 'is_active' => true, 'display_order' => 0]);
        MenuOptionIngredient::create(['menu_option_id' => $option->id, 'inventory_id' => $syrup->id, 'quantity_used' => 7]);

        $this->assertEquals(
            [$c['beans']->id => 36.0, $c['milk']->id => 300.0, $syrup->id => 14.0],
            $this->deduction->requirementsForLine($c['item'], 2, [$option->id], $c['branch']->id, $c['regular'])
        );
        // Same add-on quantity whether Regular or Large — add-ons are not size-dependent.
        $large = $this->deduction->requirementsForLine($c['item'], 2, [$option->id], $c['branch']->id, $c['large']);
        $this->assertSame(14.0, $large[$syrup->id]);
    }

    // ══════════ 17-18. No recipe = No Recipe Set, never a fallback ══════════

    public function test_a_size_with_no_recipe_is_no_recipe_set_on_every_function(): void
    {
        $branch = $this->sizeBranch('A');
        $item = $this->sizeItem($branch->id);
        [$regular] = $this->enableSizes($item);

        $this->assertRefused(
            fn () => $this->deduction->requirementsForLine($item, 1, [], $branch->id, $regular),
            MenuItemSizeUnavailableException::NO_RECIPE
        );
        $this->assertFalse($item->hasRecipe($regular));
        $this->assertTrue($item->isMissingRecipe($regular));
        $this->assertFalse($item->hasIngredientStock(1, null, $regular), 'no recipe is NOT "unconstrained" for a size');
        $this->assertSame(0, $item->remainingServings(null, $regular), '0, never null ("unlimited")');
        $this->assertFalse($item->isOrderable(1, $regular));
        $this->assertTrue($item->isIngredientOutOfStock($regular));
        $this->assertFalse($item->isLowOnIngredientStock(null, $regular));
        $this->assertSame(
            'Sorry, Test Coffee (Regular) is unavailable right now — no recipe has been set for it yet.',
            $item->orderBlockedReason(1, $regular)
        );
    }

    public function test_a_size_with_no_recipe_never_falls_back_to_the_base_recipe_or_legacy_link(): void
    {
        $branch = $this->sizeBranch('A');
        $plenty = $this->sizeInventory($branch->id, 100000);
        $item = $this->sizeItem($branch->id);
        $this->baseRecipeLine($item, $plenty, 1);
        $item->inventory_item_id = $plenty->id;
        $item->inventory_amount_used = 1;
        $item->save();
        [$regular] = $this->enableSizes($item);

        $item = $item->fresh();
        $this->assertTrue($item->hasRecipe(), 'control: the item itself has a base recipe');
        $this->assertTrue($item->hasIngredientStock(), 'control: and stock for it');

        $this->assertFalse($item->hasRecipe($regular));
        $this->assertFalse($item->hasIngredientStock(1, null, $regular));
        $this->assertSame(0, $item->remainingServings(null, $regular));
        $this->assertRefused(
            fn () => $this->deduction->requirementsForLine($item, 1, [], $branch->id, $regular),
            MenuItemSizeUnavailableException::NO_RECIPE
        );
    }

    // ══════════ 19. Invalid / cross-item sizes ══════════

    public function test_a_size_of_another_item_is_refused_and_never_falls_back(): void
    {
        $c = $this->coffee();
        $other = $this->coffee(); // a different item with fully stocked sizes

        foreach ([$other['regular'], $other['large']->id] as $foreign) {
            $this->assertRefused(
                fn () => $this->deduction->requirementsForLine($c['item'], 1, [], $c['branch']->id, $foreign),
                MenuItemSizeUnavailableException::WRONG_ITEM
            );
            $this->assertFalse($c['item']->hasIngredientStock(1, null, $foreign));
            $this->assertFalse($c['item']->hasRecipe($foreign));
            $this->assertSame(0, $c['item']->remainingServings(null, $foreign));
        }

        $this->assertSame(
            'Sorry, that size of Test Coffee is not available.',
            $c['item']->orderBlockedReason(1, $other['regular']),
            'the refusal never names the other item'
        );
    }

    public function test_missing_inactive_and_archived_sizes_are_refused(): void
    {
        $c = $this->coffee();
        $missingId = (int) DB::table('menu_item_sizes')->max('id') + 1000;

        $this->assertRefused(fn () => $c['item']->sizeRecipe($missingId), MenuItemSizeUnavailableException::NOT_FOUND);

        $c['large']->is_active = false;
        $c['large']->save();
        $this->assertRefused(fn () => $c['item']->sizeRecipe($c['large']->id), MenuItemSizeUnavailableException::INACTIVE);
        $this->assertFalse($c['item']->hasIngredientStock(1, null, $c['large']->id));

        $c['regular']->archive();
        $this->assertRefused(fn () => $c['item']->sizeRecipe($c['regular']->id), MenuItemSizeUnavailableException::ARCHIVED);
        $this->assertRefused(
            fn () => $this->deduction->requirementsForLine($c['item'], 1, [], $c['branch']->id, $c['regular']->id),
            MenuItemSizeUnavailableException::ARCHIVED
        );
        $this->assertSame('Sorry, Test Coffee (Regular) is no longer available.', $c['item']->orderBlockedReason(1, $c['regular']->id));
    }

    // ══════════ 20-21. The three functions agree ══════════

    public function test_has_ingredient_stock_agrees_with_requirements_for_line_at_the_boundary(): void
    {
        $c = $this->coffee();
        $item = $c['item'];

        foreach ([$c['regular'], $c['large']] as $size) {
            foreach ([1, 2, 5] as $servings) {
                $needs = $this->deduction->requirementsForLine($item, $servings, [], $c['branch']->id, $size);

                // Exactly enough of every ingredient: in stock.
                foreach ($needs as $invId => $amount) {
                    DB::table('inventory')->where('id', $invId)->update(['quantity' => $amount]);
                }
                $this->assertTrue($item->fresh()->hasIngredientStock($servings, null, $size), "{$size->name} x{$servings}: exactly enough");

                // One ingredient a hair short (the decimal(12,3) step): out of stock.
                $firstId = array_key_first($needs);
                DB::table('inventory')->where('id', $firstId)->update(['quantity' => $needs[$firstId] - 0.001]);
                $this->assertFalse($item->fresh()->hasIngredientStock($servings, null, $size), "{$size->name} x{$servings}: 0.001 short");

                // Committed (reserved) stock counts against it the same way.
                DB::table('inventory')->where('id', $firstId)->update(['quantity' => $needs[$firstId]]);
                $this->assertFalse(
                    $item->fresh()->hasIngredientStock($servings, [$firstId => 0.001], $size),
                    "{$size->name} x{$servings}: reserved stock is not available"
                );
            }
        }
    }

    public function test_remaining_servings_agrees_with_requirements_for_line(): void
    {
        $c = $this->coffee();
        DB::table('inventory')->where('id', $c['beans']->id)->update(['quantity' => 100]);   // Regular 18 -> 5, Large 24.5 -> 4
        DB::table('inventory')->where('id', $c['milk']->id)->update(['quantity' => 1000]);   // Regular 150 -> 6, Large 250 -> 4

        $item = $c['item']->fresh();

        foreach ([$c['regular'], $c['large']] as $size) {
            $perUnit = $this->deduction->requirementsForLine($item, 1, [], $c['branch']->id, $size);
            $expected = min(array_map(
                fn ($invId, $amount) => (int) floor((float) DB::table('inventory')->where('id', $invId)->value('quantity') / $amount),
                array_keys($perUnit),
                $perUnit
            ));

            $this->assertSame($expected, $item->remainingServings(null, $size), $size->name);
            $this->assertTrue($item->hasIngredientStock($expected, null, $size), "{$size->name}: can make {$expected}");
            $this->assertFalse($item->hasIngredientStock($expected + 1, null, $size), "{$size->name}: cannot make " . ($expected + 1));
        }

        $this->assertSame(5, $item->remainingServings(null, $c['regular']));
        $this->assertSame(4, $item->remainingServings(null, $c['large']));
        // 100 - 20 reserved = 80 beans free; 80 / 24.5 = 3.27 -> 3 Large.
        $this->assertSame(3, $item->remainingServings([$c['beans']->id => 20], $c['large']), 'reserved stock reduces the count');
    }

    public function test_the_checkout_gate_judges_a_sized_line_by_its_size_recipe(): void
    {
        $c = $this->coffee();
        DB::table('inventory')->where('id', $c['beans']->id)->update(['quantity' => 50]); // Regular: 2, Large: 2

        $item = $c['item']->fresh();

        $this->assertSame([], $this->deduction->cartShortfalls([
            ['menu_item' => $item, 'quantity' => 2, 'size' => $c['regular']],
        ], $c['branch']->id));

        $messages = $this->deduction->cartShortfalls([
            ['menu_item' => $item, 'quantity' => 3, 'size' => $c['large']->id],
        ], $c['branch']->id);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Only 2 ', $messages[0]);

        // A recipe-less size fails the gate loudly rather than slipping through
        // as "no recipe, not my business".
        $bare = $this->sizeItem($c['branch']->id);
        [$bareRegular] = $this->enableSizes($bare);
        $this->assertRefused(
            fn () => $this->deduction->cartShortfalls([['menu_item' => $bare, 'quantity' => 1, 'size' => $bareRegular]], $c['branch']->id),
            MenuItemSizeUnavailableException::NO_RECIPE
        );

        // A line with no size is judged exactly as before (base recipe: cups).
        DB::table('inventory')->where('id', $c['cup']->id)->update(['quantity' => 1]);
        $this->assertCount(1, $this->deduction->cartShortfalls([['menu_item' => $item, 'quantity' => 2]], $c['branch']->id));
    }

    // ══════════ 22. Unsized items are unchanged ══════════

    public function test_an_unsized_item_keeps_its_exact_base_behaviour(): void
    {
        $branch = $this->sizeBranch('A');
        $flour = $this->sizeInventory($branch->id, 10);

        $recipe = $this->sizeItem($branch->id, 'Recipe item');
        $this->baseRecipeLine($recipe, $flour, 3);
        $recipe = $recipe->fresh();
        $this->assertEquals([$flour->id => 6.0], $this->deduction->requirementsForLine($recipe, 2, [], $branch->id));
        $this->assertTrue($recipe->hasIngredientStock(3));
        $this->assertFalse($recipe->hasIngredientStock(4));
        $this->assertSame(3, $recipe->remainingServings());
        $this->assertTrue($recipe->isOrderable());

        $legacy = $this->sizeItem($branch->id, 'Legacy item');
        $legacy->inventory_item_id = $flour->id;
        $legacy->inventory_amount_used = 4;
        $legacy->save();
        $legacy = $legacy->fresh();
        $this->assertEquals([$flour->id => 8.0], $this->deduction->requirementsForLine($legacy, 2, [], $branch->id));
        $this->assertSame(2, $legacy->remainingServings());

        $bare = $this->sizeItem($branch->id, 'No recipe item');
        $this->assertSame([], $this->deduction->requirementsForLine($bare, 1, [], $branch->id), 'no recipe: [] as before');
        $this->assertTrue($bare->hasIngredientStock(), 'as before: nothing to measure = not blocked by stock');
        $this->assertNull($bare->remainingServings(), 'as before: unmeasurable = null');
        $this->assertTrue($bare->isMissingRecipe());
        $this->assertSame(
            'Sorry, No recipe item is unavailable right now — no recipe has been set for it yet.',
            $bare->orderBlockedReason()
        );
    }

    // ══════════ 34. Eager-loaded sizes cost no queries ══════════

    public function test_size_stock_checks_on_eager_loaded_sizes_issue_no_queries(): void
    {
        $c = $this->coffee();
        $item = MenuItem::with('allSizes.ingredients.inventory')->findOrFail($c['item']->id);
        $large = $item->allSizes->firstWhere('name', MenuItemSize::LARGE);

        $queries = 0;
        $listening = true;
        DB::listen(function () use (&$queries, &$listening) {
            if ($listening) {
                $queries++;
            }
        });

        try {
            $item->hasIngredientStock(1, null, $large);
            $item->remainingServings(null, $large);
            $item->hasRecipe($large);
            $item->orderBlockedReason(1, $large);
            $item->hasIngredientStock(1, null, $large->id);   // by id: found in the loaded relation
            $this->deduction->requirementsForLine($item, 1, [], $c['branch']->id, $large);
        } finally {
            $listening = false;
        }

        $this->assertSame(0, $queries, 'every size function must reuse the eager-loaded sizes, recipes and inventory');
    }
}

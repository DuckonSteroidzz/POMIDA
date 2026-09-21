<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\User;
use App\Services\MenuItemCosting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3b F3 — admin/menu-items was O(N) in menu items: 48 queries at 10
 * items, 128 at 30 (~4/item). Two causes, both fixed here:
 *
 *   1. AdminController::showMenuItems() did not eager-load
 *      recipeIngredients, so requirementsForLine() paid a fresh
 *      menu_item_ingredients query per item — AND, separately, the page's
 *      own per-item "Edit" recipe block (admin/partials/recipe-ingredients)
 *      re-triggered the same relation uncached, then paid a further
 *      single-row inventory lookup per RECIPE ROW for ->inventory->unit_cost/
 *      item_name/unit. Three uncoordinated N+1s from one missing eager-load.
 *   2. MenuItemCosting::breakdownForMany() looped breakdownFor() — its own
 *      docblock already said this was an N+1 the moment a caller had a list,
 *      because breakdownFor() takes its own Inventory::whereIn() every call.
 *
 * CORRECTNESS METHOD: breakdownFor() (single item) is completely unchanged
 * and is independently covered by MenuItemRecipeCostTest (18 passing tests).
 * It is therefore the trustworthy baseline breakdownForMany() must still
 * match exactly — comparing the new batched method against it, item by item,
 * on every field, is the same guarantee a literal before/after snapshot would
 * give, without depending on a point-in-time capture that would go stale the
 * moment fixture data changed.
 */
class MenuItemCostingBatchParityTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'F3ParityTest';

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function inventory(float $unitCost, float $quantity = 500): Inventory
    {
        return Inventory::create([
            'branch_id'       => 1,
            'item_name'       => self::PREFIX . ' Inv ' . uniqid(),
            'item_code'       => 'F3P-' . strtoupper(substr(uniqid(), -9)),
            'category'        => self::PREFIX,
            'quantity'        => $quantity,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => $unitCost,
            'is_active'       => true,
        ]);
    }

    private function menuItem(float $price, float $typedCost = 0): MenuItem
    {
        $catId = Category::where('is_active', true)->value('id') ?? Category::value('id');

        return MenuItem::create([
            'category_id'   => $catId,
            'branch_id'     => 1,
            'name'          => self::PREFIX . ' Item ' . uniqid(),
            'price'         => $price,
            'cost'          => $typedCost,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    private function link(MenuItem $item, Inventory $inv, float $qty): void
    {
        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $inv->id,
            'quantity_used' => $qty,
        ]);
    }

    /**
     * A representative catalogue: a simple one-ingredient recipe, a
     * multi-ingredient recipe, two items SHARING one inventory row (so the
     * batched whereIn must not double-count or drop either), a legacy
     * single-ingredient-link item with no recipe rows at all, and an item
     * with neither a recipe nor a legacy link (the pure typed-cost fallback).
     * Fractional unit costs and quantities are used throughout so rounding
     * behaviour is exercised, not just whole numbers.
     */
    public function test_the_batched_breakdown_matches_the_single_item_method_for_every_item(): void
    {
        $cheapInv = $this->inventory(2.50);
        $pricyInv = $this->inventory(11.333);
        $sharedInv = $this->inventory(4.00);

        $simple = $this->menuItem(100);
        $this->link($simple, $cheapInv, 3);

        $multi = $this->menuItem(250);
        $this->link($multi, $cheapInv, 2);
        $this->link($multi, $pricyInv, 1.5);

        $sharedA = $this->menuItem(80);
        $this->link($sharedA, $sharedInv, 1);

        $sharedB = $this->menuItem(120);
        $this->link($sharedB, $sharedInv, 4);

        $legacy = $this->menuItem(60, 20);
        $legacy->update(['inventory_item_id' => $cheapInv->id, 'inventory_amount_used' => 2]);

        $fallback = $this->menuItem(45, 18);

        $items = MenuItem::whereIn('id', [
            $simple->id, $multi->id, $sharedA->id, $sharedB->id, $legacy->id, $fallback->id,
        ])->with('recipeIngredients')->get();

        $costing = app(MenuItemCosting::class);

        // The oracle: breakdownFor() run per item, exactly as before this
        // pass — untouched code, already independently tested.
        $expected = [];
        foreach ($items as $item) {
            $expected[$item->id] = $costing->breakdownFor($item);
        }

        $actual = $costing->breakdownForMany($items);

        $this->assertSame(
            array_keys($expected),
            array_keys($actual),
            'the batched method returned a different set of item ids than the single-item method'
        );

        foreach ($expected as $id => $expectedBreakdown) {
            $this->assertSame(
                $expectedBreakdown,
                $actual[$id],
                "item {$id}'s batched breakdown does not match its single-item breakdown byte-for-byte"
            );
        }
    }

    /**
     * The batched method must cost 1 query for the inventory lookup total,
     * however many items it is handed — not one per item.
     */
    public function test_breakdown_for_many_costs_one_inventory_query_regardless_of_item_count(): void
    {
        $inv = $this->inventory(3.00);
        $items = [];
        for ($i = 0; $i < 12; $i++) {
            $item = $this->menuItem(90);
            $this->link($item, $inv, 1);
            $items[] = $item->id;
        }

        $collection = MenuItem::whereIn('id', $items)->with('recipeIngredients')->get();
        $costing = app(MenuItemCosting::class);

        $inventoryQueries = 0;
        DB::listen(function ($q) use (&$inventoryQueries) {
            if (str_contains($q->sql, 'from `inventory`')) {
                $inventoryQueries++;
            }
        });

        $costing->breakdownForMany($collection);

        $this->assertSame(
            1,
            $inventoryQueries,
            'breakdownForMany() should cost exactly one Inventory query for the whole batch, not one per item'
        );
    }

    /**
     * The end-to-end page: 10 items vs 30 items must cost the SAME total
     * query count. This is the assertion that would catch the N+1 coming
     * back on the real page, not just inside the service in isolation.
     */
    public function test_admin_menu_items_page_query_count_does_not_grow_with_catalogue_size(): void
    {
        $admin = $this->admin();
        $inv = $this->inventory(2.00);

        $countAt = function (int $totalNewItems) use ($admin, $inv): int {
            for ($i = 0; $i < $totalNewItems; $i++) {
                $item = $this->menuItem(75);
                $this->link($item, $inv, 1);
            }

            $n = 0;
            $listening = false;
            DB::listen(function () use (&$n, &$listening) { if ($listening) { $n++; } });
            $listening = true;
            $this->actingAs($admin, 'admin')->get('/admin/menu-items')->assertOk();
            $listening = false;

            return $n;
        };

        $atTen = $countAt(10);
        $atThirty = $countAt(20); // 10 already seeded above + 20 more = 30 total

        $this->assertSame(
            $atTen,
            $atThirty,
            "admin/menu-items query count grew with catalogue size: {$atTen} at ~10 items, {$atThirty} at ~30 items — the N+1 is back"
        );
    }
}

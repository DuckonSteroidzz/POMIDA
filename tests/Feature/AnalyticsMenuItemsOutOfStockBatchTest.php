<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\InventoryDeductionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3b F4 — admin/summary was O(N) in AVAILABLE menu items: 25 queries
 * empty, 68 at 30 items (+2/item), because
 * AnalyticsService::menuItemsOutOfStock() looped every available menu item,
 * paying one uncached menu_item_ingredients query (requirementsForLine() with
 * no eager-loaded relation) AND one Inventory::whereIn() PER ITEM inside the
 * loop. Exactly one call site (AdminController::showSummary()), so the fix is
 * contained.
 *
 * Fix: eager-load recipeIngredients on the MenuItem query, and hoist the
 * inventory lookup to a single whereIn outside the loop — same
 * collect-then-whereIn shape as MenuItemCosting::breakdownForMany() (F3).
 *
 * CORRECTNESS METHOD: legacyMenuItemsOutOfStock() below is a literal
 * transcription of the pre-fix method body (per-item Inventory::whereIn
 * inside the loop, same requirementsForLine() calls) kept ONLY in this test
 * as the baseline oracle — the real service no longer contains this code.
 * The new batched method must return the exact same set of short menu item
 * ids for every scenario this test constructs.
 */
class AnalyticsMenuItemsOutOfStockBatchTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'F4ParityTest';

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function inventory(int $branchId, float $quantity): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => self::PREFIX . ' Inv ' . uniqid(),
            'item_code'       => 'F4P-' . strtoupper(substr(uniqid(), -9)),
            'category'        => self::PREFIX,
            'quantity'        => $quantity,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 3,
            'is_active'       => true,
        ]);
    }

    private function menuItem(int $branchId, float $price = 90): MenuItem
    {
        $catId = Category::where('is_active', true)->value('id') ?? Category::value('id');

        return MenuItem::create([
            'category_id'   => $catId,
            'branch_id'     => $branchId,
            'name'          => self::PREFIX . ' Item ' . uniqid(),
            'price'         => $price,
            'cost'          => 0,
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
     * Literal transcription of the method this pass replaced — see the class
     * docblock. Deliberately duplicated rather than reused, so a future
     * change to the real (batched) method cannot accidentally rot this
     * baseline into matching itself.
     */
    private function legacyMenuItemsOutOfStock(string $branchScope): \Illuminate\Support\Collection
    {
        $deduction = app(InventoryDeductionService::class);

        $menuItems = MenuItem::query()
            ->where('is_available', true)
            ->when($branchScope !== 'all', fn ($q) => $q->where('branch_id', $branchScope))
            ->get();

        $short = collect();

        foreach ($menuItems as $menuItem) {
            $needs = $deduction->requirementsForLine($menuItem, 1, []);
            if (empty($needs)) {
                continue;
            }

            $inventory = Inventory::whereIn('id', array_keys($needs))->get()->keyBy('id');

            foreach ($needs as $inventoryId => $amount) {
                $inv = $inventory->get($inventoryId);
                if (!$inv || $amount > (float) $inv->quantity) {
                    $short->push($menuItem);
                    break;
                }
            }
        }

        return $short;
    }

    /**
     * A representative catalogue covering every branch this method has to
     * reason about correctly: plenty of stock, exactly one short ingredient
     * among several, every ingredient short, a legacy single-link item short,
     * an item with no recipe and no legacy link (never counted), and a
     * SECOND branch's items (to prove branch scope is unchanged).
     */
    public function test_the_batched_method_matches_the_legacy_algorithm_for_every_scenario(): void
    {
        $plentyInv = $this->inventory(1, 500);
        $shortInv = $this->inventory(1, 2);
        $secondShortInv = $this->inventory(1, 1);

        $wellStocked = $this->menuItem(1);
        $this->link($wellStocked, $plentyInv, 3);

        $oneShortAmongMany = $this->menuItem(1);
        $this->link($oneShortAmongMany, $plentyInv, 2);
        $this->link($oneShortAmongMany, $shortInv, 5); // needs 5, only 2 on hand

        $everythingShort = $this->menuItem(1);
        $this->link($everythingShort, $shortInv, 10);
        $this->link($everythingShort, $secondShortInv, 10);

        $legacyShort = $this->menuItem(1);
        $legacyShort->update(['inventory_item_id' => $shortInv->id, 'inventory_amount_used' => 10]);

        $noRecipeNoLink = $this->menuItem(1);

        // Branch 2: a well-stocked item and a short one, to prove branch
        // scope narrows the result correctly both ways.
        $branch2Inv = $this->inventory(2, 1);
        $branch2Short = $this->menuItem(2);
        $this->link($branch2Short, $branch2Inv, 5);

        $branch2Plenty = $this->menuItem(2);
        $branch2PlentyInv = $this->inventory(2, 500);
        $this->link($branch2Plenty, $branch2PlentyInv, 1);

        foreach (['1', '2', 'all'] as $scope) {
            $expected = $this->legacyMenuItemsOutOfStock($scope)->pluck('id')->sort()->values();
            $actual = (new AnalyticsService($scope))->menuItemsOutOfStock()->pluck('id')->sort()->values();

            $this->assertSame(
                $expected->all(),
                $actual->all(),
                "branch scope '{$scope}': the batched method reported a different set of short items than the legacy algorithm"
            );
        }

        // And pin the actual expected membership for scope '1' against a
        // hardcoded truth table, so a bug that happens to agree with the
        // (also broken) legacy oracle cannot slip through unnoticed. Checked
        // as membership rather than an exact set, since the real database may
        // already carry other short items unrelated to this fixture.
        $shortIds = (new AnalyticsService('1'))->menuItemsOutOfStock()->pluck('id')->all();

        $this->assertContains($wellStocked->id, MenuItem::where('branch_id', 1)->pluck('id')->all()); // control: exists
        $this->assertNotContains($wellStocked->id, $shortIds, 'a fully-stocked item was reported short');
        $this->assertContains($oneShortAmongMany->id, $shortIds, 'an item short on only ONE of several ingredients was missed');
        $this->assertContains($everythingShort->id, $shortIds, 'an item short on every ingredient was missed');
        $this->assertContains($legacyShort->id, $shortIds, 'a legacy single-ingredient-link item short on stock was missed');
        $this->assertNotContains($noRecipeNoLink->id, $shortIds, 'an item with no recipe and no legacy link must never be reported short');
    }

    public function test_menu_items_out_of_stock_costs_one_inventory_query_regardless_of_item_count(): void
    {
        $inv = $this->inventory(1, 1); // short for everyone, forces every item into $needs
        for ($i = 0; $i < 12; $i++) {
            $item = $this->menuItem(1);
            $this->link($item, $inv, 5);
        }

        $inventoryQueries = 0;
        DB::listen(function ($q) use (&$inventoryQueries) {
            if (str_contains($q->sql, 'from `inventory`')) {
                $inventoryQueries++;
            }
        });

        (new AnalyticsService('1'))->menuItemsOutOfStock();

        $this->assertSame(
            1,
            $inventoryQueries,
            'menuItemsOutOfStock() should cost exactly one Inventory query for the whole batch, not one per item'
        );
    }

    /**
     * The end-to-end page: 10 available items vs 30 must cost the SAME total
     * admin/summary query count.
     */
    public function test_admin_summary_query_count_does_not_grow_with_catalogue_size(): void
    {
        $admin = $this->admin();
        $inv = $this->inventory(1, 500);

        $countAt = function (int $totalNewItems) use ($admin, $inv): int {
            for ($i = 0; $i < $totalNewItems; $i++) {
                $item = $this->menuItem(1);
                $this->link($item, $inv, 1);
            }

            $n = 0;
            $listening = false;
            DB::listen(function () use (&$n, &$listening) { if ($listening) { $n++; } });
            $listening = true;
            $this->actingAs($admin, 'admin')->get('/admin/summary')->assertOk();
            $listening = false;

            return $n;
        };

        $atTen = $countAt(10);
        $atThirty = $countAt(20); // 10 already seeded above + 20 more = 30 total

        $this->assertSame(
            $atTen,
            $atThirty,
            "admin/summary query count grew with catalogue size: {$atTen} at ~10 items, {$atThirty} at ~30 items — the N+1 is back"
        );
    }
}

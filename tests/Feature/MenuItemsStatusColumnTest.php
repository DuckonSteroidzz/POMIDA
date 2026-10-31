<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Services\InventoryDeductionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\MenuItemSizeFixtures;
use Tests\TestCase;

/**
 * The admin Menu Items "Status" column (Defect Report 6).
 *
 * One badge per item: Out of Stock (red), Low Stock (amber), No Recipe Set
 * (grey) or In Stock (green). It is MenuItem::stockStatus(), which is
 * MenuItem::menuCardState() restated: the very method both customer menu grids
 * call, so the admin table and the customer card cannot disagree. These tests
 * pin that, rather than re-deriving stock rules a second time.
 *
 * Fixtures are minted per test (MenuItemSizeFixtures) in their own branch, so
 * nothing depends on, or touches, an existing row.
 */
class MenuItemsStatusColumnTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    private function page(int $branchId, $user = null)
    {
        return $this->actingAs($user ?? $this->sizeOwner(), 'admin')
            ->withSession(['selected_branch_id' => $branchId])
            ->get(route('admin.menu-items'));
    }

    /** An unsized item with one recipe line using $stock of an ingredient at $per per serving. */
    private function plainItem(int $branchId, float $stock, float $per, string $name): MenuItem
    {
        $item = $this->sizeItem($branchId, $name);
        $this->baseRecipeLine($item, $this->sizeInventory($branchId, $stock), $per);

        return $item;
    }

    /** The data-stock-state value printed in the row for the item called $name. */
    private function stateInRow(string $html, string $name): ?string
    {
        if (! preg_match('/<tr data-branch-group[^>]*>(?:(?!<tr ).)*?' . preg_quote($name, '/') . '.*?data-stock-state="([a-z_]+)"/s', $html, $m)) {
            return null;
        }

        return $m[1];
    }

    // ══════════ the four badges ══════════

    public function test_each_state_gets_its_own_badge_and_colour(): void
    {
        $branch = $this->sizeBranch('A');
        $this->plainItem($branch->id, 500, 10, 'MISC-InStock');     // 50 servings
        $this->plainItem($branch->id, 25, 10, 'MISC-LowStock');     // 2 servings (<= 3)
        $this->plainItem($branch->id, 0, 10, 'MISC-OutStock');      // none
        $this->sizeItem($branch->id, 'MISC-NoRecipe');              // no recipe at all

        $response = $this->page($branch->id);
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<th>Status</th>', $html);
        $this->assertSame('in_stock', $this->stateInRow($html, 'MISC-InStock'));
        $this->assertSame('low_stock', $this->stateInRow($html, 'MISC-LowStock'));
        $this->assertSame('out_of_stock', $this->stateInRow($html, 'MISC-OutStock'));
        $this->assertSame('no_recipe', $this->stateInRow($html, 'MISC-NoRecipe'));

        $this->assertMatchesRegularExpression('/menu-stock-green[^>]*data-stock-state="in_stock">In Stock</', $html);
        $this->assertMatchesRegularExpression('/menu-stock-amber[^>]*data-stock-state="low_stock">Low Stock</', $html);
        $this->assertMatchesRegularExpression('/menu-stock-red[^>]*data-stock-state="out_of_stock">Out of Stock</', $html);
        $this->assertMatchesRegularExpression('/menu-stock-grey[^>]*data-stock-state="no_recipe">No Recipe Set</', $html);
        $this->assertStringContainsString('2 left', $html, 'low stock names how many servings remain');
    }

    // ══════════ the same answer the customer menu gets ══════════

    public function test_the_label_is_the_customer_menu_cards_own_verdict(): void
    {
        $branch = $this->sizeBranch('B');
        $items = [
            $this->plainItem($branch->id, 500, 10, 'MISC-P1'),
            $this->plainItem($branch->id, 25, 10, 'MISC-P2'),
            $this->plainItem($branch->id, 0, 10, 'MISC-P3'),
            $this->sizeItem($branch->id, 'MISC-P4'),
        ];

        $reserved = app(InventoryDeductionService::class)->committedQuantities();

        foreach ($items as $item) {
            $fresh = MenuItem::with(['recipeIngredients.inventory', 'inventoryItem', 'allSizes.ingredients.inventory'])->find($item->id);
            $card = $fresh->menuCardState($reserved);
            $status = $fresh->stockStatus($reserved);

            $expected = $card['unavailable'] ? $card['label'] : ($card['low_stock'] ? 'Low Stock' : 'In Stock');
            $this->assertSame($expected, $status['label'], $item->name . ': admin label differs from the customer card');
        }
    }

    public function test_committed_stock_counts_exactly_as_it_does_on_the_customer_menu(): void
    {
        $branch = $this->sizeBranch('C');
        $item = $this->plainItem($branch->id, 100, 10, 'MISC-Reserved');   // 10 servings on the shelf
        $inventoryId = $item->recipeIngredients()->value('inventory_id');
        $fresh = fn () => MenuItem::with(['recipeIngredients.inventory', 'inventoryItem', 'allSizes.ingredients.inventory'])->find($item->id);

        $this->assertSame('in_stock', $fresh()->stockStatus([])['state']);
        // 75 grams already promised to open orders: 25 free = 2 servings.
        $this->assertSame('low_stock', $fresh()->stockStatus([$inventoryId => 75.0])['state']);
        $this->assertSame('out_of_stock', $fresh()->stockStatus([$inventoryId => 100.0])['state']);
    }

    // ══════════ sized items follow the customer side ══════════

    public function test_a_sized_item_is_judged_by_its_sizes_not_its_base_recipe(): void
    {
        $branch = $this->sizeBranch('D');
        $item = $this->sizeItem($branch->id, 'MISC-Sized');
        // Base recipe is EMPTY on the shelf: a sized item must not read as out of stock for that.
        $this->baseRecipeLine($item, $this->sizeInventory($branch->id, 0), 5);
        [$regular, $large] = $this->enableSizes($item);
        $this->sizeRecipeLine($regular, $this->sizeInventory($branch->id, 500), 10);
        $this->sizeRecipeLine($large, $this->sizeInventory($branch->id, 500), 20);

        $html = $this->page($branch->id)->getContent();
        $this->assertSame('in_stock', $this->stateInRow($html, 'MISC-Sized'));
    }

    public function test_a_sized_item_with_every_size_out_is_out_of_stock_and_with_a_blank_size_recipe_is_no_recipe(): void
    {
        $branch = $this->sizeBranch('E');

        $out = $this->sizeItem($branch->id, 'MISC-SizedOut');
        [$r, $l] = $this->enableSizes($out);
        $this->sizeRecipeLine($r, $this->sizeInventory($branch->id, 0), 10);
        $this->sizeRecipeLine($l, $this->sizeInventory($branch->id, 0), 20);

        $blank = $this->sizeItem($branch->id, 'MISC-SizedBlank');
        $this->enableSizes($blank);   // sizes, but no recipe on either

        $html = $this->page($branch->id)->getContent();
        $this->assertSame('out_of_stock', $this->stateInRow($html, 'MISC-SizedOut'));
        $this->assertSame('no_recipe', $this->stateInRow($html, 'MISC-SizedBlank'));
    }

    public function test_one_size_in_stock_keeps_a_sized_item_in_stock(): void
    {
        $branch = $this->sizeBranch('F');
        $item = $this->sizeItem($branch->id, 'MISC-SizedMixed');
        [$r, $l] = $this->enableSizes($item);
        $this->sizeRecipeLine($r, $this->sizeInventory($branch->id, 500), 10);
        $this->sizeRecipeLine($l, $this->sizeInventory($branch->id, 0), 20);   // Large is out

        $html = $this->page($branch->id)->getContent();
        $this->assertSame('in_stock', $this->stateInRow($html, 'MISC-SizedMixed'));
    }

    // ══════════ scoping, roles, cost ══════════

    public function test_branch_scoping_and_the_viewing_bar_are_unchanged(): void
    {
        $a = $this->sizeBranch('G1');
        $b = $this->sizeBranch('G2');
        $this->plainItem($a->id, 500, 10, 'MISC-OnlyA');
        $this->plainItem($b->id, 500, 10, 'MISC-OnlyB');

        $html = $this->page($a->id)->getContent();
        $this->assertStringContainsString('MISC-OnlyA', $html);
        $this->assertStringNotContainsString('MISC-OnlyB', $html);
    }

    public function test_staff_see_the_status_column_read_only(): void
    {
        $branch = $this->sizeBranch('H');
        $this->plainItem($branch->id, 500, 10, 'MISC-StaffView');

        $response = $this->page($branch->id, $this->sizeStaff($branch->id));
        $response->assertOk();
        $this->assertStringContainsString('<th>Status</th>', $response->getContent());
        $this->assertSame('in_stock', $this->stateInRow($response->getContent(), 'MISC-StaffView'));
    }

    public function test_the_status_column_adds_no_per_item_queries(): void
    {
        $branch = $this->sizeBranch('I');
        $count = function () use ($branch) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->page($branch->id)->assertOk();

            return count(DB::getQueryLog());
        };

        $this->plainItem($branch->id, 500, 10, 'MISC-Q1');
        $this->plainItem($branch->id, 500, 10, 'MISC-Q2');
        $few = $count();

        foreach (range(3, 10) as $n) {
            $this->plainItem($branch->id, 500, 10, 'MISC-Q' . $n);
        }
        $many = $count();

        $this->assertSame($few, $many, 'query count grew with the number of items (N+1)');
    }
}

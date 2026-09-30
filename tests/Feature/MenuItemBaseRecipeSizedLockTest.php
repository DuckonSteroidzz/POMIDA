<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\MenuItemSizeFixtures;
use Tests\TestCase;

/**
 * Batch 1 UI fix #3 — the base "Recipe Ingredients" editor above Sizes used to
 * stay fully editable once a menu item had Regular/Large sizes, even though
 * each size's own recipe (not the base one) is what a sized order actually
 * uses — confusing for staff. applyRecipeLock() now hides that editor and
 * shows a note once the item has sizes; an unsized item is unchanged.
 *
 * Investigation note (reported, not fixed here — out of scope for this
 * minimal UI-only batch): neither admin.menu-items.ingredients.add nor
 * .ingredients.delete checks whether the target menu item has sizes, so the
 * base recipe can still be edited server-side even while hidden client-side.
 * MenuItemCosting / ProfitCalculationService / AnalyticsService /
 * ProductionCapacityService all still read a sized item's base
 * recipeIngredients (Phase 2's own note) — hiding rows here does not affect
 * that, and no row is ever deleted.
 */
class MenuItemBaseRecipeSizedLockTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    private function page(User $viewer, int $branchId): string
    {
        return $this->actingAs($viewer, 'admin')
            ->withSession(['selected_branch_id' => $branchId])
            ->get(route('admin.menu-items'))
            ->assertOk()
            ->getContent();
    }

    public function test_unsized_item_carries_has_sizes_zero_and_note_stays_hidden_by_default(): void
    {
        $branch = $this->sizeBranch('U');
        $item = $this->sizeItem($branch->id, 'Unsized Item');

        $html = $this->page($this->sizeOwner(), $branch->id);

        $this->assertMatchesRegularExpression('/data-id="' . $item->id . '"[^>]*data-has-sizes="0"/s', $html);
        $this->assertStringContainsString(
            '<p class="size-hint" id="recipeSizedNote" style="display:none;">',
            $html
        );
    }

    public function test_sized_item_carries_the_has_sizes_flag_the_lock_reads(): void
    {
        $branch = $this->sizeBranch('S');
        $item = $this->sizeItem($branch->id, 'Sized Item');
        $this->enableSizes($item, 100, 150);

        $html = $this->page($this->sizeOwner(), $branch->id);

        $this->assertMatchesRegularExpression('/data-id="' . $item->id . '"[^>]*data-has-sizes="1"/s', $html);
    }

    public function test_recipe_sized_note_text_is_rendered(): void
    {
        $branch = $this->sizeBranch('N');
        $this->sizeItem($branch->id);

        $html = $this->page($this->sizeOwner(), $branch->id);

        $this->assertStringContainsString('This item uses per-size recipes below.', $html);
    }

    public function test_apply_recipe_lock_hides_the_block_and_note_and_is_wired_into_both_modals(): void
    {
        $branch = $this->sizeBranch('J');
        $this->sizeItem($branch->id);

        $html = $this->page($this->sizeOwner(), $branch->id);

        $this->assertStringContainsString('function applyRecipeLock(id, isSized)', $html);

        $fnStart = strpos($html, 'function applyRecipeLock(id, isSized)');
        $fnBody = substr($html, $fnStart, 500);
        $this->assertStringContainsString("getElementById('recipeSizedNote')", $fnBody);
        $this->assertStringContainsString("getElementById('recipe-' + id)", $fnBody);
        $this->assertStringContainsString("block.style.display = 'none'", $fnBody);

        // Edit mode reads THIS item's own flag — bounded at the next function
        // declaration (not a magic byte count), same as this codebase's other
        // section-extraction tests (see MenuItemAddWithIngredientsTest).
        $editStart = strpos($html, 'function openEditModal(btn)');
        $this->assertNotFalse($editStart);
        $editEnd = strpos($html, 'function applyCostLock(', $editStart);
        $this->assertNotFalse($editEnd, 'function applyCostLock not found after openEditModal');
        $editBody = substr($html, $editStart, $editEnd - $editStart);
        $this->assertStringContainsString("applyRecipeLock(id, btn.dataset.hasSizes === '1')", $editBody);

        // ...Add mode is always unsized (a brand-new item has no sizes yet).
        $addStart = strpos($html, 'function prepareAddModalChrome()');
        $this->assertNotFalse($addStart);
        $addEnd = strpos($html, 'function openEditModal(', $addStart);
        $this->assertNotFalse($addEnd, 'function openEditModal not found after prepareAddModalChrome');
        $addBody = substr($html, $addStart, $addEnd - $addStart);
        $this->assertStringContainsString("applyRecipeLock('add', false)", $addBody);
    }

    public function test_base_recipe_rows_are_not_deleted_when_an_item_gets_sizes(): void
    {
        $branch = $this->sizeBranch('K');
        $item = $this->sizeItem($branch->id);
        $inventory = $this->sizeInventory($branch->id);
        $this->baseRecipeLine($item, $inventory, 2.5);

        $this->enableSizes($item, 100, 150);

        $html = $this->page($this->sizeOwner(), $branch->id);

        // The saved row survives in the database...
        $this->assertDatabaseHas('menu_item_ingredients', [
            'menu_item_id' => $item->id,
            'inventory_id' => $inventory->id,
        ]);

        // ...and is still rendered inside its (JS-hidden) block, not stripped
        // from the markup — hiding is a client-side display toggle only.
        $editStart = strpos($html, 'id="recipe-' . $item->id . '"');
        $this->assertNotFalse($editStart, 'no per-item recipe block was rendered');
        $editEnd = strpos($html, 'id="recipe-profit-' . $item->id . '"', $editStart);
        $this->assertNotFalse($editEnd);
        $editBlock = substr($html, $editStart, $editEnd - $editStart);

        $this->assertStringContainsString($inventory->item_name, $editBlock);
    }
}

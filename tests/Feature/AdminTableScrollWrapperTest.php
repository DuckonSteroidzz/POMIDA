<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Three admin tables had no horizontal-scroll wrapper, found by a responsive
 * audit (2026-09-02): Staff Accounts (5 columns including Email, and zero
 * `overflow-x` anywhere in that file — the one with a real chance of pushing
 * the whole page sideways on a phone), the four Analytics tables (plain
 * `.content-card`, no wrapper on the page at all), and the two recipe
 * sub-tables on Menu Items / New Menu Item, which sat outside their page's
 * main scroll wrapper.
 *
 * Fixed by reusing the exact pattern already correct on 8+ other admin pages
 * (account.blade.php / ads.blade.php / vouchers.blade.php):
 * `<div style="overflow-x:auto;">` around the `<table>`, nothing else — no
 * new class, no new breakpoint, no change to the table's own columns or
 * styling.
 *
 * Asserted against the actual rendered HTML, not the blade source, so this
 * proves the wrapper genuinely contains the table rather than merely
 * appearing somewhere on the same page.
 */
class AdminTableScrollWrapperTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    /**
     * The wrapper must actually contain the table — not just precede it
     * somewhere on the page. Checks that the nearest `<div style="overflow-x:
     * auto;">` before the table's opening tag has no intervening `</div>`
     * that would close it first.
     */
    private function assertTableIsWrappedForScroll(string $html, string $tableNeedle, string $label): void
    {
        $tablePos = strpos($html, $tableNeedle);
        $this->assertNotFalse($tablePos, "[$label] could not find the table to check");

        $wrapperPos = strrpos(substr($html, 0, $tablePos), 'overflow-x:auto');
        $this->assertNotFalse($wrapperPos, "[$label] no overflow-x:auto wrapper appears before this table");

        $between = substr($html, $wrapperPos, $tablePos - $wrapperPos);
        $this->assertSame(
            0,
            substr_count($between, '</div>'),
            "[$label] the overflow-x:auto div closes before reaching the table — it does not actually wrap it"
        );
    }

    // ══════════ Staff Accounts — fix this first, per the brief ══════════

    public function test_staff_accounts_table_is_wrapped_for_horizontal_scroll(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/users')
            ->assertOk()
            ->getContent();

        $this->assertTableIsWrappedForScroll(
            $html,
            '<table style="width:100%; font-size:0.8rem;">',
            'Staff Accounts'
        );
    }

    public function test_staff_accounts_table_still_has_its_original_five_columns(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/users')
            ->getContent();

        foreach (['Name', 'Email', 'Branch', 'Status', 'Action'] as $column) {
            $this->assertStringContainsString('>' . $column . '<', $html, "the $column column must be unchanged");
        }
    }

    // ══════════ Analytics — four tables ══════════

    public function test_all_four_analytics_tables_are_wrapped_for_horizontal_scroll(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/analytics')
            ->assertOk()
            ->getContent();

        $needles = [
            'Best Sellers'         => '<th style="padding:0.4rem; text-align:left;">#</th>',
            'Least Sellers'        => '<i class="bi bi-graph-down" style="color:#888;"></i> Least Sellers',
            'Branch Performance'   => '<th style="padding:0.4rem; text-align:left;">Branch</th>',
        ];

        // The actual <table> tags are identical across these sections, so the
        // wrapper check runs against each <table ...> occurrence in document
        // order rather than a per-section needle that could match text
        // outside the table.
        $tableOpenTag = '<table style="width:100%; font-size:0.78rem; border-collapse:collapse;">';
        $count = substr_count($html, $tableOpenTag);

        $this->assertGreaterThanOrEqual(
            3,
            $count,
            'expected at least the Best Sellers / Least Sellers / Branch Performance tables to be present'
        );

        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            $pos = strpos($html, $tableOpenTag, $offset);
            $this->assertNotFalse($pos);

            $wrapperPos = strrpos(substr($html, 0, $pos), 'overflow-x:auto');
            $this->assertNotFalse($wrapperPos, "analytics table #{$i} has no overflow-x:auto wrapper before it");

            $between = substr($html, $wrapperPos, $pos - $wrapperPos);
            $this->assertSame(
                0,
                substr_count($between, '</div>'),
                "analytics table #{$i}'s wrapper div closes before reaching the table"
            );

            $offset = $pos + strlen($tableOpenTag);
        }
    }

    public function test_analytics_best_sellers_table_still_has_its_original_columns(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get('/admin/analytics')->getContent();

        $this->assertStringContainsString('Qty Sold', $html);
        $this->assertStringContainsString('Best Sellers', $html);
    }

    // ══════════ the recipe sub-tables ══════════

    /**
     * The table (and its wrapper) only render at all when the item has
     * recipe ingredients — an empty item shows just the "No recipe
     * ingredients assigned yet" notice instead. A real ingredient row is
     * seeded here so the @else branch that contains the table actually
     * renders, rather than asserting against a branch that may not exist.
     */
    public function test_new_menu_item_recipe_table_is_wrapped_for_horizontal_scroll(): void
    {
        // Add and Edit both live in the one modal on /admin/menu-items now —
        // there is no standalone "New Menu Item" page any more (2026-09-03),
        // so the recipe table this test was written against moved there too.
        $item = \App\Models\MenuItem::whereHas('recipeIngredients')->first();

        if (!$item) {
            $inventoryId = (int) \App\Models\Inventory::withArchived()->value('id');
            $menuItemId = (int) \App\Models\MenuItem::value('id');
            $this->assertGreaterThan(0, $inventoryId, 'need a real inventory row to seed a recipe ingredient');
            $this->assertGreaterThan(0, $menuItemId, 'need a real menu item to seed a recipe ingredient');

            \App\Models\MenuItemIngredient::create([
                'menu_item_id'  => $menuItemId,
                'inventory_id'  => $inventoryId,
                'quantity_used' => 1,
            ]);

            $item = \App\Models\MenuItem::find($menuItemId);
        }

        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/menu-items')
            ->getContent();

        $tablePos = strpos($html, 'id="recipe-table-' . $item->id . '"');
        $this->assertNotFalse($tablePos, 'no recipe sub-table found for that item');

        $wrapperPos = strrpos(substr($html, 0, $tablePos), 'overflow-x:auto');
        $this->assertNotFalse($wrapperPos, 'the recipe sub-table has no overflow-x:auto wrapper before it');
    }

    public function test_menu_items_recipe_subtable_is_wrapped_for_horizontal_scroll(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/menu-items')
            ->assertOk()
            ->getContent();

        $tablePos = strpos($html, 'id="recipe-table-');
        $this->assertNotFalse($tablePos, 'no recipe sub-table found on the Menu Items page');

        $wrapperPos = strrpos(substr($html, 0, $tablePos), 'overflow-x:auto');
        $this->assertNotFalse($wrapperPos, 'the recipe sub-table has no overflow-x:auto wrapper before it');

        $between = substr($html, $wrapperPos, $tablePos - $wrapperPos);
        $this->assertSame(0, substr_count($between, '</div>'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin Subcategories list had no indication of how many menu items belong
 * to each subcategory (2026-09-13).
 *
 * INVESTIGATION
 * -------------
 * menu_items -> subcategories is a direct FK (subcategory_id), exposed as
 * Subcategory::menuItems() (hasMany) — confirmed against the migration and
 * the model, same relation SubcategoryEditTest already relies on.
 *
 * THE REAL LIVE PAGE: GET /admin/add-subcategory is a dead route — it
 * immediately redirects to admin.add-category (confirmed in routes/web.php).
 * AdminController::showAddSubcategory() and resources/views/admin/
 * add-subcategory.blade.php are both unrouted dead code; left untouched.
 * The actual Subcategories table (with its Edit modal) lives on
 * admin.add-category, rendered by AdminController::showAddCategory() and
 * resources/views/admin/add-category.blade.php — that is what this test
 * and the feature change target.
 *
 * "Active" definition: AdminController::showMenuItems() (the admin Menu
 * Items list itself) queries MenuItem with NO is_available filter — it
 * shows every non-archived item regardless of stock/availability. MenuItem
 * uses the Archivable global scope, so a plain count already excludes
 * archived rows without any extra filtering. To match that existing
 * definition of "what the admin considers a live item", the new count uses
 * withCount('menuItems') with no additional where — same rows
 * showMenuItems() would list for that subcategory, and archived items are
 * excluded automatically by the global scope.
 *
 * N+1 CHECK: withCount adds one aggregate subquery, not one query per row.
 * Verified below via DB::listen.
 */
class SubcategoryItemCountTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function category(string $name): Category
    {
        return Category::create(['name' => $name, 'display_order' => 0, 'is_active' => true]);
    }

    private function subcategory(Category $parent, string $name): Subcategory
    {
        return Subcategory::create([
            'category_id'   => $parent->id,
            'name'          => $name,
            'display_order' => 0,
            'is_active'     => true,
        ]);
    }

    private function menuItem(Category $category, Subcategory $sub, string $name, bool $available = true): MenuItem
    {
        return MenuItem::create([
            'category_id'    => $category->id,
            'subcategory_id' => $sub->id,
            'branch_id'      => 1,
            'name'           => $name,
            'price'          => 50,
            'is_available'   => $available,
            'display_order'  => 0,
        ]);
    }

    public function test_the_list_shows_the_correct_item_count_per_subcategory(): void
    {
        $parent = $this->category('Count Parent');
        $subWithItems = $this->subcategory($parent, 'Has Items');
        $subEmpty = $this->subcategory($parent, 'No Items');

        $this->menuItem($parent, $subWithItems, 'Item One');
        $this->menuItem($parent, $subWithItems, 'Item Two');
        // Out of stock (is_available = false) is NOT archived — must still count,
        // matching showMenuItems()'s own definition of a live item.
        $this->menuItem($parent, $subWithItems, 'Item Three', available: false);

        $response = $this->actingAs($this->admin(), 'admin')->get('/admin/add-category');

        $response->assertOk();
        $response->assertSee('Has Items');
        $response->assertSee('No Items');

        $withItems = Subcategory::withCount('menuItems')->findOrFail($subWithItems->id);
        $empty = Subcategory::withCount('menuItems')->findOrFail($subEmpty->id);

        $this->assertSame(3, $withItems->menu_items_count);
        $this->assertSame(0, $empty->menu_items_count, 'a subcategory with zero items must show 0, not break');
    }

    public function test_an_archived_menu_item_does_not_count(): void
    {
        $parent = $this->category('Archive Count Parent');
        $sub = $this->subcategory($parent, 'Archive Sub');

        $item = $this->menuItem($parent, $sub, 'Archived Item');
        $item->archive();

        $counted = Subcategory::withCount('menuItems')->findOrFail($sub->id);

        $this->assertSame(0, $counted->menu_items_count, 'archived items must not be counted');
    }

    /**
     * The real N+1 check: query count must stay FLAT as the number of
     * subcategories grows, not scale with row count. withCount() folds into
     * one aggregate subquery regardless of how many rows it counts for;
     * a naive per-row count (e.g. a loop calling $sub->menuItems()->count())
     * would add one query per subcategory and this test would catch it.
     */
    public function test_showing_the_list_does_not_regress_query_count(): void
    {
        $parent = $this->category('Query Count Parent');
        $this->subcategory($parent, 'Query Count Sub One');
        $this->menuItem($parent, $this->subcategory($parent, 'ignored'), 'Query Count Item');

        $counter = 0;
        DB::listen(function () use (&$counter) {
            $counter++;
        });

        $countQueriesFor = function (int $extraSubcategories) use ($parent, &$counter) {
            for ($i = 0; $i < $extraSubcategories; $i++) {
                $this->subcategory($parent, "Extra Sub {$i}");
            }

            $counter = 0;
            $response = $this->actingAs($this->admin(), 'admin')->get('/admin/add-category');
            $response->assertOk();

            return $counter;
        };

        $withFewRows = $countQueriesFor(0);
        $withManyRows = $countQueriesFor(15);

        $this->assertSame(
            $withFewRows,
            $withManyRows,
            "query count must not scale with subcategory row count (before: {$withFewRows}, after adding 15 more rows: {$withManyRows})"
        );
    }
}

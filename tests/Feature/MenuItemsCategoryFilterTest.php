<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * admin/menu-items — Main / Sub Category filter (Batch 2, 2026-09-29).
 *
 * Reported from team testing: the two dropdowns "do not filter and do not
 * show what is selected". Reproduced in headless Chrome before the fix: the
 * filter was JS that hid rows whose WHOLE text contained the category name
 * (a substring test, so one category's name inside another item's name or
 * description kept that item), typing in the search box replaced the filter
 * outright while the dropdown still named a category, and any reload lost it.
 *
 * It is now ?category=<id>&subcategory=<id>, applied by showMenuItems() by id,
 * on top of the unchanged branch scope. These tests pin the matching, the
 * rendered selection, the "Filtering: X > Y" indicator and its Clear link,
 * bad input, the branch scope (owner views and a locked supervisor), the
 * by-branch grouping, and that archived items never come back through it.
 *
 * Every row is created inside DatabaseTransactions with the MICF prefix.
 */
class MenuItemsCategoryFilterTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'MICF';

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function supervisorAt(int $branchId): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Supervisor',
            'email'     => strtolower(self::PREFIX) . '-sup-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => 'supervisor',
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
    }

    private function branch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Branch ' . uniqid(),
            'code'      => 'MF' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function category(string $name): Category
    {
        return Category::create(['name' => $name, 'is_active' => true, 'display_order' => 0]);
    }

    private function sub(Category $category, string $name): Subcategory
    {
        return Subcategory::create(['category_id' => $category->id, 'name' => $name, 'is_active' => true, 'display_order' => 0]);
    }

    private function item(string $name, Category $category, ?Subcategory $sub, ?int $branchId, ?string $description = null): MenuItem
    {
        return MenuItem::create([
            'category_id'    => $category->id,
            'subcategory_id' => $sub?->id,
            'branch_id'      => $branchId,
            'name'           => $name,
            'description'    => $description,
            'price'          => 100,
            'is_available'   => true,
            'display_order'  => 0,
        ]);
    }

    private function page(User $actor, array $query = [], string $selectedBranch = 'all'): string
    {
        return $this->actingAs($actor, 'admin')
            ->withSession(['selected_branch_id' => $selectedBranch])
            ->get(route('admin.menu-items', $query))
            ->assertOk()
            ->content();
    }

    /** Item names in the table's "Item Name" cells, in order. */
    private function listed(string $html): array
    {
        preg_match_all('/<td data-label="Item Name"[^>]*>([^<]*)<\/td>/', $html, $m);

        return array_map(fn ($n) => html_entity_decode(trim($n)), $m[1]);
    }

    /** A category pair whose names collide as substrings — the reported false positive. */
    private function fixture(): array
    {
        $u = strtoupper(substr(uniqid(), -5));
        $rice = $this->category(self::PREFIX . " Rice $u");
        $riceMeals = $this->category(self::PREFIX . " Rice $u Meals");
        $plain = $this->sub($rice, self::PREFIX . " Plain $u");
        $fried = $this->sub($rice, self::PREFIX . " Fried $u");
        $combo = $this->sub($riceMeals, self::PREFIX . " Combo $u");

        return [
            'u' => $u, 'rice' => $rice, 'riceMeals' => $riceMeals, 'plain' => $plain, 'fried' => $fried, 'combo' => $combo,
            'plainItem' => $this->item(self::PREFIX . " Plain Rice $u", $rice, $plain, 1),
            'friedItem' => $this->item(self::PREFIX . " Garlic Fried $u", $rice, $fried, 1),
            // Its NAME and DESCRIPTION both contain the other category's name.
            'comboItem' => $this->item(self::PREFIX . " Rice $u Combo", $riceMeals, $combo, 1, 'Best with ' . self::PREFIX . " Rice $u"),
        ];
    }

    // ══════════ matching ══════════

    public function test_the_category_filter_matches_by_id_not_by_text(): void
    {
        $f = $this->fixture();

        $listed = $this->listed($this->page($this->admin(), ['category' => $f['rice']->id]));

        $this->assertContains($f['plainItem']->name, $listed);
        $this->assertContains($f['friedItem']->name, $listed);
        $this->assertNotContains($f['comboItem']->name, $listed, 'another category\'s item must not match on its text');

        foreach (MenuItem::whereIn('name', $listed)->get() as $item) {
            $this->assertSame($f['rice']->id, (int) $item->category_id, 'only the chosen category is listed');
        }
    }

    public function test_the_sub_category_narrows_within_the_category(): void
    {
        $f = $this->fixture();

        $listed = $this->listed($this->page($this->admin(), ['category' => $f['rice']->id, 'subcategory' => $f['fried']->id]));

        $this->assertSame([$f['friedItem']->name], array_values(array_filter($listed, fn ($n) => str_starts_with($n, self::PREFIX))));
    }

    public function test_no_filter_lists_everything_as_before(): void
    {
        $f = $this->fixture();

        $listed = $this->listed($this->page($this->admin()));

        foreach (['plainItem', 'friedItem', 'comboItem'] as $key) {
            $this->assertContains($f[$key]->name, $listed);
        }
    }

    // ══════════ what is selected is visible ══════════

    public function test_the_dropdowns_render_the_selection_and_the_indicator_says_it(): void
    {
        $f = $this->fixture();

        $html = $this->page($this->admin(), ['category' => $f['rice']->id, 'subcategory' => $f['plain']->id]);

        $this->assertMatchesRegularExpression('/<option value="' . $f['rice']->id . '"\s+selected>/', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $f['plain']->id . '"\s+selected>/', $html);
        $this->assertStringContainsString('name="category"', $html);
        $this->assertStringContainsString('name="subcategory"', $html);

        $this->assertStringContainsString('id="menuFilterIndicator"', $html);
        $this->assertStringContainsString(
            'Filtering: <strong>' . e($f['rice']->name) . '</strong> &gt; <strong>' . e($f['plain']->name) . '</strong>',
            $html
        );
        $this->assertMatchesRegularExpression('/<a href="' . preg_quote(route('admin.menu-items'), '/') . '"[^>]*>\s*<i class="bi bi-x-lg"><\/i> Clear filter/', $html);

        // With a category chosen, the Sub filter offers only its own
        // subcategories (the Add/Edit modal's own list is untouched).
        $subFilter = $this->between($html, 'id="subCategoryFilter"', '</select>');
        $this->assertStringContainsString('>' . e($f['fried']->name) . '<', $subFilter);
        $this->assertStringNotContainsString('>' . e($f['combo']->name) . '<', $subFilter);
    }

    public function test_no_indicator_without_a_filter(): void
    {
        $html = $this->page($this->admin());

        $this->assertStringNotContainsString('id="menuFilterIndicator"', $html);
        $this->assertStringNotContainsString('selected>', $this->between($html, 'id="categoryFilter"', '</select>'));
    }

    public function test_a_subcategory_alone_implies_its_category(): void
    {
        $f = $this->fixture();

        $html = $this->page($this->admin(), ['subcategory' => $f['combo']->id]);

        $this->assertSame(
            [$f['comboItem']->name],
            array_values(array_filter($this->listed($html), fn ($n) => str_starts_with($n, self::PREFIX)))
        );
        $this->assertStringContainsString('Filtering: <strong>' . e($f['riceMeals']->name) . '</strong> &gt;', $html);
    }

    public function test_a_subcategory_from_another_category_is_dropped(): void
    {
        $f = $this->fixture();

        $html = $this->page($this->admin(), ['category' => $f['rice']->id, 'subcategory' => $f['combo']->id]);
        $listed = $this->listed($html);

        $this->assertContains($f['plainItem']->name, $listed, 'the category filter still applies');
        $this->assertContains($f['friedItem']->name, $listed);
        $this->assertStringNotContainsString(' &gt; <strong>', $html, 'no sub named in the indicator');
    }

    /** @dataProvider junkQueries */
    public function test_junk_filter_input_is_ignored_not_an_error(array $query): void
    {
        $html = $this->page($this->admin(), $query);

        $this->assertStringNotContainsString('id="menuFilterIndicator"', $html);
    }

    public static function junkQueries(): array
    {
        return [
            'text'          => [['category' => 'abc']],
            'unknown id'    => [['category' => '999999999']],
            'negative'      => [['category' => '-1']],
            'array'         => [['category' => ['1', '2']]],
            'sql-ish'       => [['category' => '1 OR 1=1', 'subcategory' => "1'--"]],
            'unknown sub'   => [['subcategory' => '999999999']],
        ];
    }

    // ══════════ branch scope and grouping unchanged ══════════

    public function test_a_locked_supervisor_sees_only_their_branch_within_the_filter(): void
    {
        $f = $this->fixture();
        $far = $this->branch();
        $farItem = $this->item(self::PREFIX . ' Far Rice ' . $f['u'], $f['rice'], $f['plain'], $far->id);

        // Even with an "all" session value, the supervisor stays in their branch.
        $listed = $this->listed($this->page($this->supervisorAt($far->id), ['category' => $f['rice']->id], 'all'));

        $this->assertContains($farItem->name, $listed);
        $this->assertNotContains($f['plainItem']->name, $listed, 'Main Branch items never leak in through the filter');
        $this->assertNotContains($f['friedItem']->name, $listed);
    }

    public function test_the_owner_branch_view_and_all_branches_grouping_both_filter(): void
    {
        $f = $this->fixture();
        $far = $this->branch();
        $farItem = $this->item(self::PREFIX . ' Far Rice ' . $f['u'], $f['rice'], $f['plain'], $far->id);
        $farOther = $this->item(self::PREFIX . ' Far Combo ' . $f['u'], $f['riceMeals'], $f['combo'], $far->id);

        $one = $this->listed($this->page($this->admin(), ['category' => $f['rice']->id], (string) $far->id));
        $this->assertContains($farItem->name, $one);
        $this->assertNotContains($f['plainItem']->name, $one);
        $this->assertNotContains($farOther->name, $one);

        $html = $this->page($this->admin(), ['category' => $f['rice']->id], 'all');
        $all = $this->listed($html);
        $this->assertContains($farItem->name, $all);
        $this->assertContains($f['plainItem']->name, $all);
        $this->assertNotContains($farOther->name, $all);

        // The far branch gets its own heading, counting only the filtered rows.
        $this->assertMatchesRegularExpression(
            '/<span class="value">' . preg_quote(e($far->name), '/') . '<\/span>\s*<span class="count">1 item<\/span>/',
            $html
        );
    }

    public function test_archived_items_never_come_back_through_the_filter(): void
    {
        $f = $this->fixture();
        $archived = $this->item(self::PREFIX . ' Archived Rice ' . $f['u'], $f['rice'], $f['plain'], 1);
        DB::table('menu_items')->where('id', $archived->id)->update(['archived_at' => now()]);

        $before = $this->page($this->admin());
        preg_match('/Archived \((\d+)\)/', $before, $countBefore);

        $html = $this->page($this->admin(), ['category' => $f['rice']->id, 'subcategory' => $f['plain']->id]);

        $this->assertNotContains($archived->name, $this->listed($html));
        $this->assertStringContainsString('href="' . route('admin.archived') . '"', $html, 'the Archived link is still there');
        preg_match('/Archived \((\d+)\)/', $html, $countAfter);
        $this->assertSame($countBefore[1] ?? null, $countAfter[1] ?? null, 'the archived count ignores the filter');
    }

    // ══════════ the search box only narrows ══════════

    public function test_the_old_whole_row_text_filter_is_gone(): void
    {
        $html = $this->page($this->admin());

        $this->assertStringNotContainsString('function filterTable()', $html);
        $this->assertStringNotContainsString('onchange="filterTable()"', $html);
        $this->assertStringContainsString('function searchTable()', $html);
        $this->assertStringContainsString('<form method="GET" action="' . route('admin.menu-items') . '" id="menuFilterForm"', $html);
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);

        return $start === false ? '' : substr($html, $start, (int) strpos($html, $to, $start) - $start);
    }
}

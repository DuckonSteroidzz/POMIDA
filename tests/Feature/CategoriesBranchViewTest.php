<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * admin/add-category — the branch view (Batch 2, 2026-09-29).
 *
 * Team request: a branch filter on Categories like Menu Items / Inventory,
 * with the "Viewing: <Branch>" bar for locked roles.
 *
 * Investigated first: categories and subcategories have NO branch column —
 * they are global by design — so no branch ownership was added and nothing
 * about who may create, rename or delete them changed. The least invasive
 * version: the same layout branch bar as the other tabs, and the branch
 * changes only the Items counts, which are exactly the items the Menu Items
 * page lists for that branch. Every category is always listed.
 *
 * Rows carry the CBV prefix and live inside DatabaseTransactions.
 */
class CategoriesBranchViewTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'CBV';

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function locked(string $role, int $branchId): User
    {
        return User::create([
            'name'      => self::PREFIX . ' ' . $role,
            'email'     => strtolower(self::PREFIX) . '-' . $role . '-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => $role,
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
    }

    private function branch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Branch ' . uniqid(),
            'code'      => 'CB' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    /** A category + subcategory with 2 items at Main (branch 1), 1 at $far, and 1 shared (NULL). */
    private function fixture(Branch $far): array
    {
        $u = strtoupper(substr(uniqid(), -6));
        $cat = Category::create(['name' => self::PREFIX . " Cat $u", 'is_active' => true, 'display_order' => 0]);
        $sub = Subcategory::create(['category_id' => $cat->id, 'name' => self::PREFIX . " Sub $u", 'is_active' => true, 'display_order' => 0]);
        $empty = Category::create(['name' => self::PREFIX . " Empty $u", 'is_active' => true, 'display_order' => 0]);

        foreach ([1, 1, $far->id, null] as $i => $branchId) {
            MenuItem::create([
                'category_id' => $cat->id, 'subcategory_id' => $sub->id, 'branch_id' => $branchId,
                'name' => self::PREFIX . " Item $u $i", 'price' => 100, 'is_available' => true, 'display_order' => 0,
            ]);
        }

        return [$cat, $sub, $empty];
    }

    private function page(User $actor, string $selectedBranch = 'all'): string
    {
        return $this->actingAs($actor, 'admin')
            ->withSession(['selected_branch_id' => $selectedBranch])
            ->get(route('admin.add-category'))
            ->assertOk()
            ->content();
    }

    /** The Items badge on the category's (or subcategory's) own row. */
    private function itemCount(string $html, string $name): ?int
    {
        $pattern = '/class="pchy-name">' . preg_quote(e($name), '/') . '<\/td>.*?<td data-l="Items"><span class="pchy-badge"[^>]*>(\d+)/s';

        return preg_match($pattern, $html, $m) ? (int) $m[1] : null;
    }

    private function viewingValue(string $html): string
    {
        preg_match('/Viewing:\s*<span class="value">\s*(.*?)\s*<\/span>/s', $html, $m);

        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1] ?? '')));
    }

    public function test_all_branches_counts_every_item_as_before(): void
    {
        [$cat, $sub, $empty] = $this->fixture($this->branch());

        $html = $this->page($this->admin(), 'all');

        $this->assertSame(4, $this->itemCount($html, $cat->name));
        $this->assertSame(4, $this->itemCount($html, $sub->name));
        $this->assertSame(0, $this->itemCount($html, $empty->name));
        $this->assertStringContainsString('Item counts are for <strong>All Branches</strong>', $html);
    }

    public function test_the_owner_gets_the_branch_bar_with_its_picker(): void
    {
        $html = $this->page($this->admin(), 'all');

        $this->assertStringContainsString('class="pc-branchbar"', $html);
        $this->assertSame('All Branches', $this->viewingValue($html));
        $this->assertMatchesRegularExpression('/<select name="branch_id"\s+onchange=/', $html);
    }

    public function test_the_owner_branch_view_changes_only_the_counts(): void
    {
        $far = $this->branch();
        [$cat, $sub, $empty] = $this->fixture($far);

        $main = $this->page($this->admin(), '1');
        $this->assertSame(2, $this->itemCount($main, $cat->name), 'Main Branch items only — the shared one is listed only under All Branches, as on Menu Items');
        $this->assertSame(2, $this->itemCount($main, $sub->name));

        $farHtml = $this->page($this->admin(), (string) $far->id);
        $this->assertSame(1, $this->itemCount($farHtml, $cat->name));
        $this->assertSame(1, $this->itemCount($farHtml, $sub->name));
        $this->assertSame($far->name, $this->viewingValue($farHtml));
        $this->assertStringContainsString('Item counts are for <strong>' . e($far->name) . '</strong>', $farHtml);

        // Categories are global: every one is still listed, even at zero.
        $this->assertSame(0, $this->itemCount($farHtml, $empty->name));
    }

    public function test_the_counts_match_what_menu_items_lists_for_that_branch(): void
    {
        $far = $this->branch();
        [$cat] = $this->fixture($far);

        foreach (['all', '1', (string) $far->id] as $view) {
            $listed = $this->actingAs($this->admin(), 'admin')
                ->withSession(['selected_branch_id' => $view])
                ->get(route('admin.menu-items', ['category' => $cat->id]))
                ->content();
            preg_match_all('/<td data-label="Item Name"[^>]*>/', $listed, $rows);

            $this->assertSame(count($rows[0]), $this->itemCount($this->page($this->admin(), $view), $cat->name), "view $view");
        }
    }

    public function test_a_supervisor_sees_viewing_their_branch_and_its_counts_only(): void
    {
        $far = $this->branch();
        [$cat, $sub] = $this->fixture($far);
        $supervisor = $this->locked('supervisor', $far->id);

        // An 'all' session value cannot widen a locked role.
        $html = $this->page($supervisor, 'all');

        $this->assertSame($far->name, $this->viewingValue($html));
        $this->assertDoesNotMatchRegularExpression('/<select name="branch_id"\s+onchange=/', $html, 'no picker for a locked role');
        $this->assertSame(1, $this->itemCount($html, $cat->name));
        $this->assertSame(1, $this->itemCount($html, $sub->name));
    }

    public function test_who_may_reach_or_edit_categories_is_unchanged(): void
    {
        [$cat] = $this->fixture($this->branch());

        // Staff still have no Categories page at all.
        $staff = $this->locked('staff', 1);
        $this->assertNotSame(200, $this->actingAs($staff, 'admin')->get(route('admin.add-category'))->getStatusCode());

        // Renaming a shared category stays Owner-only.
        $supervisor = $this->locked('supervisor', 1);
        $this->actingAs($supervisor, 'admin')
            ->put(route('admin.add-category.update', $cat->id), ['name' => self::PREFIX . ' Renamed']);
        $this->assertSame($cat->name, $cat->fresh()->name);

        $route = app('router')->getRoutes()->getByName('admin.add-category.update');
        $this->assertContains('role:admin', $route->gatherMiddleware());
    }
}

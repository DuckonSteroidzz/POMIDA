<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The "Viewing: <branch>" bar on admin/menu-options is functional (Oct 2026),
 * the same remembered choice (App\Support\ViewingBranch) the Promotions pages
 * use.
 *
 *  - All Branches renders as the page always did.
 *  - One branch narrows the Menu Items picker to that branch's items plus the
 *    company-wide (branch_id NULL) ones, each option's "Used in" list and its
 *    inventory-status badge to the same items, and the Categories chip to
 *    the categories that have an item in the picker.
 *  - Options are global: every one stays listed. The Options and Archived
 *    chips are global too, and are asserted to stay put.
 *  - A branch-locked supervisor has no choice: a crafted ?branch= is ignored.
 *
 * Every row carries the MOVB prefix and runs inside DatabaseTransactions
 * against pomida_db_testing.
 */
class MenuOptionsViewingBranchTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'MOVB';

    // ══════════════════ fixtures ══════════════════

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
            'code'      => 'MOV' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function category(): Category
    {
        return Category::create([
            'name'      => self::PREFIX . ' Cat ' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function item(string $name, ?int $branchId, ?Category $category = null): MenuItem
    {
        return MenuItem::create([
            'category_id'   => ($category ?? $this->category())->id,
            'branch_id'     => $branchId,
            'name'          => $name,
            'price'         => 100,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    private function option(string $name, MenuItem ...$items): MenuOption
    {
        $option = MenuOption::create([
            'name'             => $name,
            'additional_price' => 10,
            'is_active'        => true,
            'display_order'    => 0,
        ]);

        foreach ($items as $item) {
            $option->menuItems()->attach($item->id);
        }

        return $option;
    }

    private function inventoryIn(int $branchId): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => self::PREFIX . ' Syrup ' . uniqid(),
            'item_code'       => 'MOVI-' . strtoupper(substr(uniqid(), -9)),
            'category'        => self::PREFIX,
            'quantity'        => 500,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 2,
            'is_active'       => true,
        ]);
    }

    private function page(User $actor, string $query = ''): string
    {
        return $this->actingAs($actor, 'admin')
            ->get('/admin/menu-options' . $query)
            ->assertOk()
            ->content();
    }

    private function rowFor(string $html, MenuItem $item): string
    {
        $pattern = '/<tr class="menu-item-row menu-filter-row"\s+data-id="' . $item->id . '".*?<\/tr>/s';

        return preg_match($pattern, $html, $m) ? $m[0] : '';
    }

    /** The option's whole Options-table row, or ''. */
    private function optionRowFor(string $html, MenuOption $option): string
    {
        $pattern = '/<tr class="pchy-option option-row"\s+id="opt-' . $option->id . '".*?<\/tr>/s';

        return preg_match($pattern, $html, $m) ? $m[0] : '';
    }

    /** "Used in N items" count for an option, or null when it renders as 0/1 item. */
    private function usedInCount(string $html, MenuOption $option): ?int
    {
        return preg_match('/id="usedin-btn-' . $option->id . '".*?Used in (\d+) items/s', $html, $m)
            ? (int) $m[1]
            : null;
    }

    /** The "Used in" popover's text for an option. */
    private function usedInList(string $html, MenuOption $option): string
    {
        return preg_match('/id="usedin-list-' . $option->id . '".*?<\/div>/s', $html, $m) ? $m[0] : '';
    }

    /** The branch ids of the inventory-status badges rendered for an option. */
    private function badgeBranchIds(string $html, MenuOption $option): array
    {
        if (! preg_match('/id="opt-branch-status-' . $option->id . '".*?<\/div>/s', $html, $block)) {
            return [];
        }

        preg_match_all('/data-branch-id="(\d+)"/', $block[0], $m);

        return array_map('intval', $m[1]);
    }

    private function chip(string $html, string $label): ?int
    {
        return preg_match('/<span class="pchy-chip">\s*(\d+) ' . $label . '\s*<\/span>/', $html, $m) ? (int) $m[1] : null;
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. The bar is there
    // ══════════════════════════════════════════════════════════════════

    public function test_the_owner_sees_the_viewing_bar_with_a_branch_picker(): void
    {
        $html = $this->page($this->admin());

        $this->assertStringContainsString('pc-branchbar', $html);
        $this->assertStringContainsString('data-pc-branch-select', $html);
        $this->assertStringContainsString('window.pcBranchSelectHandler', $html);
    }

    public function test_a_supervisor_sees_their_own_branch_and_no_picker(): void
    {
        $branch = $this->branch();

        $html = $this->page($this->supervisorAt($branch->id));

        $this->assertStringContainsString('pc-branchbar', $html);
        $this->assertStringContainsString($branch->name, $html);
        $this->assertStringNotContainsString('data-pc-branch-select', $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. All Branches is unchanged
    // ══════════════════════════════════════════════════════════════════

    public function test_all_branches_lists_every_branchs_items(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $itemA = $this->item(self::PREFIX . ' Item A ' . uniqid(), $a->id);
        $itemB = $this->item(self::PREFIX . ' Item B ' . uniqid(), $b->id);
        $shared = $this->item(self::PREFIX . ' Shared ' . uniqid(), null);

        $html = $this->page($this->admin(), '?branch=all');

        foreach ([$itemA, $itemB, $shared] as $item) {
            $this->assertNotSame('', $this->rowFor($html, $item), 'All Branches must list ' . $item->name);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. One branch: the Menu Items picker
    // ══════════════════════════════════════════════════════════════════

    public function test_one_branch_shows_its_items_and_the_company_wide_ones_only(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $itemA = $this->item(self::PREFIX . ' Item A ' . uniqid(), $a->id);
        $itemB = $this->item(self::PREFIX . ' Item B ' . uniqid(), $b->id);
        $shared = $this->item(self::PREFIX . ' Shared ' . uniqid(), null);

        $html = $this->page($this->admin(), '?branch=' . $a->id);

        $this->assertNotSame('', $this->rowFor($html, $itemA));
        $this->assertNotSame('', $this->rowFor($html, $shared), 'company-wide items belong to every branch');
        $this->assertSame('', $this->rowFor($html, $itemB), "another branch's item must not be listed");
    }

    public function test_the_company_wide_group_is_labelled_when_viewing_a_branch(): void
    {
        $a = $this->branch();
        $this->item(self::PREFIX . ' Shared ' . uniqid(), null);

        $viewing = $this->page($this->admin(), '?branch=' . $a->id);
        $this->assertStringContainsString('All Branches (company-wide)', $viewing);

        $all = $this->page($this->admin(), '?branch=all');
        $this->assertStringNotContainsString('(company-wide)', $all);
    }

    public function test_the_category_dropdown_offers_only_categories_with_items_in_the_branch(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $catA = $this->category();
        $catB = $this->category();
        $this->item(self::PREFIX . ' Item A ' . uniqid(), $a->id, $catA);
        $this->item(self::PREFIX . ' Item B ' . uniqid(), $b->id, $catB);

        $html = $this->page($this->admin(), '?branch=' . $a->id);

        $this->assertStringContainsString('value="' . strtolower($catA->name) . '"', $html);
        $this->assertStringNotContainsString('value="' . strtolower($catB->name) . '"', $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // 4. One branch: the Options table
    // ══════════════════════════════════════════════════════════════════

    public function test_used_in_counts_and_lists_only_the_viewing_branchs_items_plus_company_wide(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $itemA = $this->item(self::PREFIX . ' Item A ' . uniqid(), $a->id);
        $itemB = $this->item(self::PREFIX . ' Item B ' . uniqid(), $b->id);
        $shared = $this->item(self::PREFIX . ' Shared ' . uniqid(), null);
        $option = $this->option(self::PREFIX . ' Cheese ' . uniqid(), $itemA, $itemB, $shared);

        $all = $this->page($this->admin(), '?branch=all');
        $this->assertSame(3, $this->usedInCount($all, $option));

        $viewing = $this->page($this->admin(), '?branch=' . $a->id);
        $this->assertSame(2, $this->usedInCount($viewing, $option));
        $list = $this->usedInList($viewing, $option);
        $this->assertStringContainsString($itemA->name, $list);
        $this->assertStringContainsString($shared->name, $list);
        $this->assertStringNotContainsString($itemB->name, $list);
    }

    public function test_an_option_used_only_elsewhere_stays_listed_but_unused_here(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $itemB = $this->item(self::PREFIX . ' Item B ' . uniqid(), $b->id);
        $option = $this->option(self::PREFIX . ' Bacon ' . uniqid(), $itemB);

        $html = $this->page($this->admin(), '?branch=' . $a->id);

        $this->assertStringContainsString($option->name, $html, 'options are global and stay assignable');
        $this->assertStringContainsString('Not assigned to any menu item yet.', $this->optionRowFor($html, $option) ?: $html);
        $this->assertSame([], $this->badgeBranchIds($html, $option));
    }

    public function test_the_status_badge_is_the_viewing_branchs_only(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $itemA = $this->item(self::PREFIX . ' Item A ' . uniqid(), $a->id);
        $itemB = $this->item(self::PREFIX . ' Item B ' . uniqid(), $b->id);
        $option = $this->option(self::PREFIX . ' Egg ' . uniqid(), $itemA, $itemB);

        $all = $this->page($this->admin(), '?branch=all');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->badgeBranchIds($all, $option));

        $viewing = $this->page($this->admin(), '?branch=' . $a->id);
        $this->assertSame([$a->id], $this->badgeBranchIds($viewing, $option));
    }

    public function test_a_company_wide_only_option_shows_the_viewing_branchs_status(): void
    {
        $a = $this->branch();
        $shared = $this->item(self::PREFIX . ' Shared ' . uniqid(), null);
        $option = $this->option(self::PREFIX . ' Ham ' . uniqid(), $shared);
        $this->inventoryIn($a->id);

        $html = $this->page($this->admin(), '?branch=' . $a->id);

        $this->assertSame([$a->id], $this->badgeBranchIds($html, $option));
    }

    // ══════════════════════════════════════════════════════════════════
    // 5. Chips
    // ══════════════════════════════════════════════════════════════════

    public function test_the_categories_chip_follows_the_branch_and_the_global_chips_do_not(): void
    {
        $a = $this->branch();
        $this->item(self::PREFIX . ' Item A ' . uniqid(), $a->id);

        $all = $this->page($this->admin(), '?branch=all');
        $viewing = $this->page($this->admin(), '?branch=' . $a->id);

        $this->assertSame(1, $this->chip($viewing, 'Categories'), 'the new branch has one item, in one category');
        $this->assertGreaterThan(1, $this->chip($all, 'Categories'));
        $this->assertSame($this->chip($all, 'Options'), $this->chip($viewing, 'Options'));
        $this->assertSame(
            preg_match('/(\d+) Archived/', $all, $x) ? $x[1] : null,
            preg_match('/(\d+) Archived/', $viewing, $y) ? $y[1] : null
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 6. The choice is shared and remembered; bad values are ignored
    // ══════════════════════════════════════════════════════════════════

    public function test_the_choice_is_remembered_in_the_shared_session_key_and_survives_a_plain_reload(): void
    {
        $a = $this->branch();
        $b = $this->branch();
        $itemA = $this->item(self::PREFIX . ' Item A ' . uniqid(), $a->id);
        $itemB = $this->item(self::PREFIX . ' Item B ' . uniqid(), $b->id);

        $this->actingAs($this->admin(), 'admin');
        $this->get('/admin/menu-options?branch=' . $a->id)->assertOk();
        $this->assertSame($a->id, session('selected_branch_id'));

        $reload = $this->get('/admin/menu-options')->assertOk()->content();
        $this->assertNotSame('', $this->rowFor($reload, $itemA));
        $this->assertSame('', $this->rowFor($reload, $itemB));

        $this->get('/admin/menu-options?branch=all')->assertOk();
        $this->assertSame('all', session('selected_branch_id'));
    }

    public function test_a_choice_made_on_another_admin_page_applies_here(): void
    {
        $a = $this->branch();
        $itemA = $this->item(self::PREFIX . ' Item A ' . uniqid(), $a->id);
        $itemB = $this->item(self::PREFIX . ' Item B ' . uniqid(), $this->branch()->id);

        $this->actingAs($this->admin(), 'admin');
        $this->get('/admin/branches/select/' . $a->id);

        $html = $this->get('/admin/menu-options')->assertOk()->content();
        $this->assertNotSame('', $this->rowFor($html, $itemA));
        $this->assertSame('', $this->rowFor($html, $itemB));
    }

    public function test_a_nonexistent_or_junk_branch_is_ignored_and_the_remembered_choice_stands(): void
    {
        $a = $this->branch();
        $this->actingAs($this->admin(), 'admin');
        $this->get('/admin/menu-options?branch=' . $a->id)->assertOk();

        foreach (['999999', 'abc', '1;DROP', '-1'] as $junk) {
            $this->get('/admin/menu-options?branch=' . urlencode($junk))->assertOk();
            $this->assertSame($a->id, session('selected_branch_id'), "'{$junk}' must not change the choice");
        }

        $this->get('/admin/menu-options?branch[]=1')->assertOk();
        $this->assertSame($a->id, session('selected_branch_id'));
    }

    public function test_a_supervisor_cannot_widen_their_view_with_a_crafted_branch(): void
    {
        $mine = $this->branch();
        $other = $this->branch();
        $mineItem = $this->item(self::PREFIX . ' Mine ' . uniqid(), $mine->id);
        $otherItem = $this->item(self::PREFIX . ' Other ' . uniqid(), $other->id);
        $shared = $this->item(self::PREFIX . ' Shared ' . uniqid(), null);
        $option = $this->option(self::PREFIX . ' Pickle ' . uniqid(), $mineItem, $otherItem, $shared);

        $this->actingAs($this->supervisorAt($mine->id), 'admin');

        foreach (['?branch=' . $other->id, '?branch=all'] as $query) {
            $html = $this->get('/admin/menu-options' . $query)->assertOk()->content();

            $this->assertNotSame('', $this->rowFor($html, $mineItem));
            $this->assertSame('', $this->rowFor($html, $otherItem), "{$query} must not show another branch");
            $this->assertSame('', $this->rowFor($html, $shared), 'a locked supervisor never sees company-wide rows here');
            $this->assertStringNotContainsString($otherItem->name, $html);
            $this->assertNull(session('selected_branch_id'), 'a locked account never writes the choice');
            $this->assertSame([$mine->id], $this->badgeBranchIds($html, $option));
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // 7. Assigning still works inside a viewed branch
    // ══════════════════════════════════════════════════════════════════

    public function test_assigning_options_to_an_item_still_works_while_viewing_a_branch(): void
    {
        $a = $this->branch();
        $itemA = $this->item(self::PREFIX . ' Item A ' . uniqid(), $a->id);
        $option = $this->option(self::PREFIX . ' Sauce ' . uniqid());

        $this->actingAs($this->admin(), 'admin');
        $this->get('/admin/menu-options?branch=' . $a->id)->assertOk();

        $this->postJson('/admin/menu-options/assign/' . $itemA->id, ['option_ids' => [$option->id]])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue($itemA->fresh()->options()->where('menu_options.id', $option->id)->exists());

        $html = $this->get('/admin/menu-options')->assertOk()->content();
        $this->assertStringContainsString('data-options="[' . $option->id . ']"', $this->rowFor($html, $itemA));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\MenuOption;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The Menu Items picker on admin/menu-options — branch identity, and the
 * branch scope the list never applied. Sept 2026, raised by the owner.
 *
 * THE REPORT
 * ----------
 * The picker showed two rows both reading "coke", with nothing to say which
 * branch either belonged to, so assigning add-ons meant guessing. Confirmed in
 * the live dev data: menu_items #29 "coke" (branch 1, Main Branch) and #30
 * "coke" (branch 2, Branch 1) — and it is not a one-off, "roasted chicken"
 * exists twice as well (#27 branch 1, #31 branch 3). Menu item names are not
 * unique per branch and nothing intends them to be.
 *
 * WHAT CHANGED
 * ------------
 *  1. Each row carries a branch chip and a data-branch-name attribute, and the
 *     "Assign selected options to: <name>" confirmation echoes the chip — so
 *     neither the choice nor the confirmation is ambiguous. The chip reuses
 *     .pchy-ing-branch, the chip the saved ingredient rows already use to name
 *     a branch, rather than the green/red .pchy-branch-ok/off badge, which
 *     means Mapped/Unmapped and not a branch name.
 *
 *  2. The list is branch-scoped. It was Category::with(['menuItems']) — every
 *     branch's items for every viewer — while assignOptions() has always
 *     resolved its target through AdminOrderAccess::resolveRecordInScope(). So
 *     a branch-locked supervisor was shown rows they could not act on and got
 *     a bare 404 from Save Options when they tried. The picker now offers only
 *     what the save will accept. This is a read-side branch-scope gap of
 *     exactly the kind AdminOrderAccess was created to close on the write
 *     side; see its header comment on "a filter on a list, not an
 *     authorisation boundary".
 *
 * Every row this file creates carries the MOBL prefix and runs inside
 * DatabaseTransactions against pomida_db_testing, so nothing survives the run
 * and no pre-existing row is modified or deleted.
 */
class MenuOptionsPageBranchLabelTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'MOBL';
    private const MAIN = 1;

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

    private function farBranch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Far Branch ' . uniqid(),
            'code'      => 'MOB' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function inventoryIn(int $branchId): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => self::PREFIX . ' Cola Syrup ' . uniqid(),
            'item_code'       => 'MOBI-' . strtoupper(substr(uniqid(), -9)),
            'category'        => self::PREFIX,
            'quantity'        => 500,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 2,
            'is_active'       => true,
        ]);
    }

    /**
     * A menu item with an explicit name so two of them can share one, and a
     * real recipe so it is a normal sellable row rather than an edge case.
     * $branchId null is a shared "all branches" item.
     */
    private function itemNamed(string $name, ?int $branchId): MenuItem
    {
        $item = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $branchId,
            'name'          => $name,
            'price'         => 100,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $this->inventoryIn($branchId ?? self::MAIN)->id,
            'quantity_used' => 10,
        ]);

        return $item->fresh();
    }

    private function page(?User $actor = null): string
    {
        return $this->actingAs($actor ?? $this->admin(), 'admin')
            ->get('/admin/menu-options')
            ->assertOk()
            ->content();
    }

    /**
     * The one rendered .menu-item-row for $item, or '' if it is not on the page.
     *
     * The picker is a branch-grouped <table> now, so the row is a <tr> rather
     * than the nested <div><div> it used to be. Only the tag changed — every
     * assertion below still reads the same row, with the same data-* payload
     * and the same branch chip inside it.
     */
    private function rowFor(string $html, MenuItem $item): string
    {
        $pattern = '/<tr class="menu-item-row menu-filter-row"\s+data-id="' . $item->id . '".*?<\/tr>/s';

        return preg_match($pattern, $html, $m) ? $m[0] : '';
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. Branch identity is in the payload, per item
    // ══════════════════════════════════════════════════════════════════

    public function test_each_menu_item_row_exposes_its_branch_name_as_data(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Coke ' . uniqid(), self::MAIN);

        $row = $this->rowFor($this->page(), $item);

        $this->assertNotSame('', $row, 'the item must appear in the picker');
        $this->assertStringContainsString('data-branch-name="Main Branch"', $row);
    }

    public function test_a_shared_all_branches_item_is_labelled_rather_than_left_blank(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Shared Item ' . uniqid(), null);

        $row = $this->rowFor($this->page(), $item);

        $this->assertNotSame('', $row);
        $this->assertStringContainsString('data-branch-name="All Branches"', $row);
        $this->assertStringContainsString('All Branches', $row);
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. THE REPORTED CASE — two items sharing one name
    // ══════════════════════════════════════════════════════════════════

    /**
     * The live "two rows both called coke" shape. Both rows must render, and
     * each must carry its OWN branch — asserted on the two rows separately, so
     * a single correct chip on the page cannot satisfy it.
     */
    public function test_two_items_sharing_a_name_across_branches_each_carry_their_own_branch_chip(): void
    {
        $far = $this->farBranch();
        $sharedName = self::PREFIX . ' coke ' . uniqid();

        $mainCoke = $this->itemNamed($sharedName, self::MAIN);
        $farCoke = $this->itemNamed($sharedName, $far->id);

        $this->assertSame($mainCoke->name, $farCoke->name, 'the two items must genuinely share a name');
        $this->assertNotSame($mainCoke->id, $farCoke->id);

        $html = $this->page();

        $mainRow = $this->rowFor($html, $mainCoke);
        $farRow = $this->rowFor($html, $farCoke);

        $this->assertNotSame('', $mainRow, 'the Main Branch twin must be listed');
        $this->assertNotSame('', $farRow, 'the far-branch twin must be listed');

        $this->assertStringContainsString('data-branch-name="Main Branch"', $mainRow);
        $this->assertStringContainsString('Main Branch', $mainRow);
        $this->assertStringNotContainsString($far->name, $mainRow);

        $this->assertStringContainsString('data-branch-name="' . $far->name . '"', $farRow);
        $this->assertStringContainsString($far->name, $farRow);
        $this->assertStringNotContainsString('Main Branch', $farRow);
    }

    /**
     * The chip reuses the branch-NAME chip the ingredient rows already use, not
     * a new class and not the Mapped/Unmapped badge. Asserted because the point
     * of reusing it is that there is one visual language for "this belongs to
     * branch X" on this page.
     */
    public function test_the_branch_chip_reuses_the_existing_branch_name_chip_class(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Coke ' . uniqid(), self::MAIN);

        $row = $this->rowFor($this->page(), $item);

        $this->assertMatchesRegularExpression(
            '/<span class="pchy-ing-branch pchy-menu-item-branch">\s*Main Branch\s*<\/span>/',
            $row,
            'the row must use .pchy-ing-branch, the chip already used to name a branch on this page'
        );

        $this->assertStringNotContainsString(
            'pchy-branch-ok',
            $row,
            'the green Mapped badge means something else and must not be reused for a branch name'
        );
        $this->assertStringNotContainsString('pchy-branch-off', $row);
    }

    /**
     * The "Assign selected options to:" confirmation is populated by
     * selectMenuItem() from the row's data-branch-name, so the branch reaches
     * it too. The wiring is what is asserted here — the page must read
     * data-branch-name and append the chip class the CSS places.
     */
    public function test_the_assign_confirmation_is_wired_to_echo_the_branch(): void
    {
        $this->itemNamed(self::PREFIX . ' Coke ' . uniqid(), self::MAIN);

        $html = $this->page();

        $this->assertStringContainsString('el.dataset.branchName', $html);

        // The banner holds a fixed span for the branch now, filled with
        // textContent, rather than a chip element appended to the name span
        // after the fact. Both halves are asserted: the span exists in the
        // markup, and the script writes the row's branch into it.
        $this->assertStringContainsString('id="selectedItemBranch"', $html);
        $this->assertStringContainsString('class="pchy-selected-branch"', $html);
        $this->assertStringContainsString('selectedBranchEl.textContent', $html);
        $this->assertStringContainsString('.pchy-selected-name{', $html);
    }

    /** The search filter still matches on the item name, not on the branch text now beside it. */
    public function test_the_row_still_carries_its_lowercased_name_for_the_search_filter(): void
    {
        $name = self::PREFIX . ' Coke ' . uniqid();
        $item = $this->itemNamed($name, self::MAIN);

        $row = $this->rowFor($this->page(), $item);

        $this->assertStringContainsString('data-name="' . strtolower($name) . '"', $row);
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. BRANCH SCOPE — the read-side gap
    // ══════════════════════════════════════════════════════════════════

    /**
     * A branch-locked supervisor must not be offered another branch's menu
     * items. Before this pass the list was unscoped, so the far-branch row was
     * rendered, indistinguishable from their own, and Save Options answered a
     * bare 404.
     */
    public function test_a_branch_locked_supervisor_is_not_offered_another_branchs_menu_items(): void
    {
        $far = $this->farBranch();

        $ownItem = $this->itemNamed(self::PREFIX . ' Own Coke ' . uniqid(), self::MAIN);
        $farItem = $this->itemNamed(self::PREFIX . ' Far Coke ' . uniqid(), $far->id);

        $html = $this->page($this->supervisorAt(self::MAIN));

        $this->assertNotSame('', $this->rowFor($html, $ownItem), 'their own branch\'s item must still be listed');
        $this->assertSame(
            '',
            $this->rowFor($html, $farItem),
            'another branch\'s menu item must not be offered to a branch-locked supervisor'
        );
    }

    /**
     * The picker and the save must agree. This is the rule that made the
     * unscoped list a dead end, and it has to keep holding — the list is now
     * narrowed to match it, not the other way round.
     */
    public function test_the_save_still_refuses_a_far_branch_item_for_a_supervisor(): void
    {
        $far = $this->farBranch();
        $farItem = $this->itemNamed(self::PREFIX . ' Far Coke ' . uniqid(), $far->id);
        $option = MenuOption::create([
            'name'             => self::PREFIX . ' extra ice ' . uniqid(),
            'additional_price' => 10,
            'is_active'        => true,
            'display_order'    => 0,
        ]);

        $this->actingAs($this->supervisorAt(self::MAIN), 'admin')
            ->post('/admin/menu-options/assign/' . $farItem->id, ['option_ids' => [$option->id]])
            ->assertNotFound();

        $this->assertSame(
            0,
            $farItem->options()->count(),
            'a refused assignment must not have been written'
        );
    }

    /**
     * A NULL-branch shared item is withheld from a locked supervisor too,
     * deliberately: resolveRecordInScope() matches branch_id = <theirs>, so
     * Save Options would 404 on it exactly as it does for a far-branch item.
     * Listing it would recreate the dead end this pass removed.
     */
    public function test_a_shared_item_is_withheld_from_a_supervisor_because_the_save_would_refuse_it(): void
    {
        $shared = $this->itemNamed(self::PREFIX . ' Shared Item ' . uniqid(), null);
        $supervisor = $this->supervisorAt(self::MAIN);

        $this->assertSame(
            '',
            $this->rowFor($this->page($supervisor), $shared),
            'a shared item must not be offered to a supervisor whose save cannot accept it'
        );

        $option = MenuOption::create([
            'name'             => self::PREFIX . ' extra ice ' . uniqid(),
            'additional_price' => 10,
            'is_active'        => true,
            'display_order'    => 0,
        ]);

        $this->actingAs($supervisor, 'admin')
            ->post('/admin/menu-options/assign/' . $shared->id, ['option_ids' => [$option->id]])
            ->assertNotFound();
    }

    /** An admin is not branch-locked and keeps seeing every branch, unchanged. */
    public function test_an_admin_still_sees_every_branchs_menu_items(): void
    {
        $far = $this->farBranch();

        $mainItem = $this->itemNamed(self::PREFIX . ' Main Coke ' . uniqid(), self::MAIN);
        $farItem = $this->itemNamed(self::PREFIX . ' Far Coke ' . uniqid(), $far->id);
        $sharedItem = $this->itemNamed(self::PREFIX . ' Shared Coke ' . uniqid(), null);

        $html = $this->page();

        $this->assertNotSame('', $this->rowFor($html, $mainItem));
        $this->assertNotSame('', $this->rowFor($html, $farItem));
        $this->assertNotSame('', $this->rowFor($html, $sharedItem));
    }

    // ══════════════════════════════════════════════════════════════════
    // 4. assignOptions() input hardening
    // ══════════════════════════════════════════════════════════════════

    /**
     * A NESTED array inside option_ids used to be coerced to the integer 1 by
     * array_map('intval', …) — silently, since intval(array) is 1 for any
     * non-empty array — so the payload assigned option #1 rather than being
     * refused. Now refused as malformed.
     *
     * Asserted with a real option #1-shaped target: the lowest-id option that
     * actually exists, so the test would have caught the old behaviour rather
     * than passing because nothing was there to assign.
     */
    public function test_a_nested_array_in_option_ids_is_refused_rather_than_coerced(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Coke ' . uniqid(), self::MAIN);
        $lowestOption = MenuOption::orderBy('id')->firstOrFail();

        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, [
                'option_ids' => [[$lowestOption->id]],
            ])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame(
            0,
            $item->options()->count(),
            'a malformed payload must assign nothing at all'
        );
    }

    /** Non-numeric junk is refused the same way, rather than becoming option #0. */
    public function test_non_numeric_option_ids_are_refused(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Coke ' . uniqid(), self::MAIN);

        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, [
                'option_ids' => ['not-an-id'],
            ])
            ->assertStatus(422);

        $this->assertSame(0, $item->options()->count());
    }

    /**
     * The hardening must not narrow what the page actually sends. The real
     * saveAssignment() posts checkbox values, which arrive as NUMERIC STRINGS,
     * and OptionAssignmentPaginationSafetyTest pins that contract — so a
     * string id has to keep working.
     */
    public function test_numeric_string_ids_still_save_exactly_as_the_page_sends_them(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Coke ' . uniqid(), self::MAIN);
        $option = MenuOption::create([
            'name'             => self::PREFIX . ' extra ice ' . uniqid(),
            'additional_price' => 10,
            'is_active'        => true,
            'display_order'    => 0,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, [
                'option_ids' => [(string) $option->id],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame([$option->id], $item->options()->pluck('menu_options.id')->all());
    }

    /** And an admin's assignment still goes through, so the scoping did not narrow them. */
    public function test_an_admin_can_still_assign_options_to_any_branchs_item(): void
    {
        $far = $this->farBranch();
        $farItem = $this->itemNamed(self::PREFIX . ' Far Coke ' . uniqid(), $far->id);
        $option = MenuOption::create([
            'name'             => self::PREFIX . ' extra ice ' . uniqid(),
            'additional_price' => 10,
            'is_active'        => true,
            'display_order'    => 0,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/menu-options/assign/' . $farItem->id, ['option_ids' => [$option->id]])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, $farItem->options()->count());
    }
}

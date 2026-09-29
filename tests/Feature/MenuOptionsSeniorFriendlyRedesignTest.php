<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\MenuOptionIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * admin/menu-options — the senior-friendly pass on top of the table
 * restructure (Sept 2026).
 *
 * The restructure turned two card lists into two tables. This pass is about
 * what those tables SAY, for an owner who is not a developer and does not
 * read the page as a database:
 *
 *   1. The Menu Items picker's second column was Category — a fact this page
 *      never needed (it is still filterable from the dropdown above, and it
 *      still has a column of its own on the Menu Items admin page). It is
 *      Assigned Add-ons now: the actual option names and prices already on
 *      that item, where before the row gave a bare "N option(s)" count and
 *      made the owner click through to find out which N.
 *
 *   2. An option's per-branch status rendered up to TWO pills per branch — a
 *      red "<branch>: Unmapped" badge and, when that branch had no stock at
 *      all, an amber "No active inventory — add inventory first" hint beside
 *      it. One pill per branch now, in plain language, under a heading that
 *      says Inventory Status rather than Ingredient Mapping.
 *
 *   3. The assignment area was one small grey line above a button. It is a
 *      banner naming the item AND its branch, over a large Save Add-ons
 *      button.
 *
 * WHAT MUST NOT HAVE CHANGED, and is asserted here as hard as the new things:
 *
 *   - The branch section headers from the restructure still group the Menu
 *     Items picker. Losing them would undo that pass.
 *   - Used In and Inventory Status are still TWO columns saying two
 *     different things — Used In is the branch of each MENU ITEM using the
 *     option, Inventory Status is the branch whose INVENTORY the option
 *     links into. Simplifying the second must not have merged the first into
 *     it, in wording or in markup.
 *   - storeMenuOption()'s price rule (required|numeric|gt:0) is untouched.
 *     This pass rewrote the label and the placeholder AROUND that rule.
 *   - Nothing reaches the page through a per-row query: the add-on names in
 *     the new column come from the relation showMenuOptions() already eager
 *     loads.
 *
 * Every row created here carries the MOSF prefix and runs inside
 * DatabaseTransactions against pomida_db_testing, so nothing survives the
 * run and no pre-existing row is modified or deleted.
 */
class MenuOptionsSeniorFriendlyRedesignTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'MOSF';
    private const MAIN = 1;

    // ══════════════════════════════════════════════════════════════════
    // fixtures
    // ══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function supervisorAt(?int $branchId): User
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

    private function freshBranch(string $label): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' ' . $label . ' ' . uniqid(),
            'code'      => 'MS' . strtoupper(substr(uniqid(), -7)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function itemNamed(string $name, ?int $branchId): MenuItem
    {
        return MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $branchId,
            'name'          => $name,
            'price'         => 100,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    private function optionNamed(string $name, float $price = 10): MenuOption
    {
        return MenuOption::create([
            'name'             => $name,
            'additional_price' => $price,
            'is_active'        => true,
            'display_order'    => 0,
        ]);
    }

    private function inventoryIn(int $branchId): Inventory
    {
        return Inventory::create([
            'item_name'     => self::PREFIX . ' Inv ' . uniqid(),
            'item_code'     => 'MS' . strtoupper(substr(uniqid(), -9)),
            'category'      => 'Other',
            'quantity'      => 500,
            'unit'          => 'pcs',
            'reorder_level' => 1,
            'cost_per_unit' => 1,
            'branch_id'     => $branchId,
            'is_active'     => true,
        ]);
    }

    private function page(?User $actor = null): string
    {
        return $this->actingAs($actor ?? $this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get('/admin/menu-options')
            ->assertOk()
            ->content();
    }

    /** One menu item's <tr> in the picker on the right. */
    private function itemRow(string $html, MenuItem $item): string
    {
        $start = strpos($html, 'data-id="' . $item->id . '"');
        $this->assertNotFalse($start, "menu item #{$item->id} is not in the picker");

        // Walk back to the opening <tr, forward to its </tr>.
        $open = strrpos(substr($html, 0, $start), '<tr ');
        $close = strpos($html, '</tr>', $start);

        return substr($html, $open, $close - $open);
    }

    /** One option's whole <tbody>. */
    private function optionBlock(string $html, MenuOption $option): string
    {
        $start = strpos($html, 'id="opt-' . $option->id . '"');
        $this->assertNotFalse($start, "option #{$option->id} is not on the page");

        $end = strlen($html);
        $rest = substr($html, $start + 1);

        if (preg_match('/id="opt-\d+"/', $rest, $m, PREG_OFFSET_CAPTURE)) {
            $end = min($end, $start + 1 + $m[0][1]);
        }

        $pager = strpos($html, 'class="pchy-pager"', $start);
        if ($pager !== false) {
            $end = min($end, $pager);
        }

        return substr($html, $start, $end - $start);
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. Assigned Add-ons — names and prices, not a count
    // ══════════════════════════════════════════════════════════════════

    public function test_the_picker_heads_its_second_column_assigned_add_ons(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('<th>Assigned Add-ons</th>', $html);

        // The column it replaced is gone from THIS table. Asserted on the
        // header, not on the word "Category" anywhere on the page: the
        // category FILTER above the table is still there and still says it.
        $this->assertStringNotContainsString('<th>Category</th>', $html);
        $this->assertStringNotContainsString('data-l="Category"', $html);
    }

    public function test_an_items_row_lists_each_assigned_add_on_by_name_and_price(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Roasted ' . uniqid(), self::MAIN);

        $cheese = $this->optionNamed(self::PREFIX . ' Extra Cheese ' . uniqid(), 10);
        $gravy  = $this->optionNamed(self::PREFIX . ' Unli Gravy ' . uniqid(), 25.5);

        $item->options()->attach([$cheese->id, $gravy->id]);

        $row = $this->itemRow($this->page(), $item->fresh());

        // Name and price together, as one readable chip — not a count, and
        // not a name with the price left somewhere else.
        $this->assertStringContainsString($cheese->name, $row);
        $this->assertStringContainsString('(+₱10.00)', $row);

        $this->assertStringContainsString($gravy->name, $row);
        $this->assertStringContainsString('(+₱25.50)', $row);

        // The count is still there beside it — the two are not alternatives.
        $this->assertStringContainsString('2 option(s)', $row);
    }

    public function test_an_item_with_no_add_ons_says_so_quietly(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Plain ' . uniqid(), self::MAIN);

        $row = $this->itemRow($this->page(), $item);

        $this->assertStringContainsString('No add-ons assigned', $row);
        $this->assertStringContainsString('class="pchy-noaddons"', $row);

        // Grey and quiet, never the page's red/amber language: an item with
        // no add-ons is a normal item, not a problem to fix.
        $this->assertStringNotContainsString('pchy-branch-off', $row);
        $this->assertStringNotContainsString('pchy-branch-empty', $row);

        // And no empty chip list is emitted for it.
        $this->assertStringNotContainsString('pchy-addon-chip', $row);
    }

    public function test_a_legacy_zero_priced_add_on_reads_free_rather_than_a_peso_amount_of_nothing(): void
    {
        // storeMenuOption() refuses price 0 (gt:0), so this can only be a row
        // created before that rule — but those rows exist, and the column has
        // to render one without saying "+₱0.00".
        $item = $this->itemNamed(self::PREFIX . ' Legacy ' . uniqid(), self::MAIN);
        $free = $this->optionNamed(self::PREFIX . ' Free Sauce ' . uniqid(), 0);

        $item->options()->attach($free->id);

        $row = $this->itemRow($this->page(), $item->fresh());

        $this->assertStringContainsString($free->name, $row);
        $this->assertStringContainsString('(Free)', $row);
        $this->assertStringNotContainsString('(+₱0.00)', $row);
    }

    public function test_the_category_is_still_on_the_row_for_the_filter_that_reads_it(): void
    {
        // The column went; the FACT did not. The "All categories" dropdown
        // filters on the row's data-category attribute, so dropping the
        // attribute with the cell would have silently broken that dropdown
        // while leaving it on screen.
        $category = Category::where('is_active', true)->first() ?? Category::first();
        $item = $this->itemNamed(self::PREFIX . ' Filtered ' . uniqid(), self::MAIN);

        $row = $this->itemRow($this->page(), $item);

        $this->assertStringContainsString(
            'data-category="' . strtolower($category->name) . '"',
            $row,
            'the category filter reads this attribute, not the cell that was removed'
        );
    }

    public function test_the_add_on_names_cost_no_query_per_row(): void
    {
        // The names come from $item->options, which showMenuOptions() already
        // eager loads for the "N option(s)" count. Rendering them must not
        // have turned that into one query per menu item.
        $branch = $this->freshBranch('Counted');
        $options = collect(range(1, 6))->map(fn ($i) => $this->optionNamed(self::PREFIX . ' Opt ' . $i . ' ' . uniqid(), $i));

        foreach (range(1, 12) as $i) {
            $this->itemNamed(self::PREFIX . ' Item ' . $i . ' ' . uniqid(), $branch->id)
                ->options()
                ->attach($options->take(3)->pluck('id')->all());
        }

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $this->page();

        $this->assertLessThanOrEqual(
            25,
            $queries,
            'a page with 12 extra items carrying 3 add-ons each must not be running a query per row'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. The branch grouping from the restructure survives
    // ══════════════════════════════════════════════════════════════════

    public function test_the_picker_is_still_grouped_under_branch_section_headers(): void
    {
        $far = $this->freshBranch('Far');

        $mainItem = $this->itemNamed(self::PREFIX . ' Main Twin ' . uniqid(), self::MAIN);
        $farItem  = $this->itemNamed(self::PREFIX . ' Far Twin ' . uniqid(), $far->id);

        $html = $this->page();

        $pickerStart = strpos($html, 'id="menuItemsList"');
        $this->assertNotFalse($pickerStart);
        $picker = substr($html, $pickerStart, strpos($html, '</table>', $pickerStart) - $pickerStart);

        // The header row shape, its branch name, and its count chip.
        $this->assertStringContainsString('class="pchy-branch-head"', $picker);
        $this->assertStringContainsString('class="pchy-branch-section"', $picker);
        $this->assertStringContainsString('>' . $far->name . '</span>', $picker);

        // Each item sits under its own group, and the group spans the table's
        // three columns — a colspan left at the old count would have split
        // the header out of the table.
        $this->assertStringContainsString('colspan="3"', $picker);
        $this->assertStringContainsString('data-branch-group="' . $far->id . '"', $picker);
        $this->assertStringContainsString('data-branch-group="' . self::MAIN . '"', $picker);

        // And the per-row branch chip the restructure kept alongside the
        // header (the list pages client-side, so a group can straddle a page).
        $this->assertStringContainsString(
            'class="pchy-ing-branch pchy-menu-item-branch"',
            $this->itemRow($html, $farItem)
        );
        $this->assertStringContainsString(
            'data-branch-name="Main Branch"',
            $this->itemRow($html, $mainItem)
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. Used In and Inventory Status stay two different things
    // ══════════════════════════════════════════════════════════════════

    public function test_both_columns_are_present_and_headed_as_the_different_things_they_are(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('<th>Used In</th>', $html);
        $this->assertStringContainsString('<th>Inventory Status</th>', $html);

        // Neither heading absorbed the other, and the old jargon is gone.
        $this->assertStringNotContainsString('<th>Ingredient Mapping</th>', $html);
        $this->assertStringNotContainsString('Used In / Inventory', $html);
        $this->assertStringNotContainsString('Option Branch', $html);
    }

    public function test_one_option_row_carries_both_displays_in_separate_cells(): void
    {
        $far = $this->freshBranch('Divergent');

        $option = $this->optionNamed(self::PREFIX . ' Unli Gravy ' . uniqid(), 15);

        $mainItem = $this->itemNamed(self::PREFIX . ' Roasted Main ' . uniqid(), self::MAIN);
        $farItem  = $this->itemNamed(self::PREFIX . ' Roasted Far ' . uniqid(), $far->id);
        $option->menuItems()->attach([$mainItem->id, $farItem->id]);

        // Linked to MAIN stock only, so the two columns must disagree about
        // the far branch — which is the whole point of their being two.
        MenuOptionIngredient::create([
            'menu_option_id' => $option->id,
            'inventory_id'   => $this->inventoryIn(self::MAIN)->id,
            'quantity_used'  => 1,
        ]);

        $block = $this->optionBlock($this->page(), $option->fresh());

        // Two cells, each labelled for the phone layout, in this order.
        $usedInCell = strpos($block, 'data-l="Used In"');
        $statusCell = strpos($block, 'data-l="Inventory Status"');

        $this->assertNotFalse($usedInCell, 'the Used In cell must still be rendered');
        $this->assertNotFalse($statusCell, 'the Inventory Status cell must be rendered');
        $this->assertLessThan($statusCell, $usedInCell, 'Used In comes first, as it did');

        // Each bounded at its own </td>: an option block runs on into the
        // detail row (the Used In chip list, the ingredient editor), and
        // reading to the end of the block would have let one column's
        // content count as the other's.
        $usedIn = substr($block, $usedInCell, strpos($block, '</td>', $usedInCell) - $usedInCell);
        $status = substr($block, $statusCell, strpos($block, '</td>', $statusCell) - $statusCell);

        // USED IN: counts menu items, offers them behind its own toggle, and
        // names no inventory state at all.
        $this->assertStringContainsString('Used in 2 items', $usedIn);
        $this->assertStringContainsString('usedin-btn-' . $option->id, $usedIn);
        $this->assertStringNotContainsString('Ready', $usedIn);
        $this->assertStringNotContainsString('Needs Inventory', $usedIn);
        $this->assertStringNotContainsString('pchy-branch-badge', $usedIn);

        // INVENTORY STATUS: pills per branch, and no menu-item name anywhere
        // in it — the far ITEM's name belongs to the other column.
        $this->assertStringContainsString('pchy-branch-badge', $status);
        $this->assertStringNotContainsString($farItem->name, $status);
        $this->assertStringNotContainsString('Used in', $status);

        // And they genuinely disagree: Main is Ready, the far branch is not.
        $mainName = Branch::find(self::MAIN)->name;
        $this->assertStringContainsString($mainName . ' Ready</span>', $status);
        $this->assertMatchesRegularExpression(
            '/' . preg_quote($far->name, '/') . ' (Needs Inventory|Add Inventory First)<\/span>/',
            $status
        );

        // The far ITEM's name is still on the row — under Used In, where the
        // expandable chip list lives.
        $this->assertStringContainsString($farItem->name, $block);
    }

    public function test_the_inventory_status_pill_replaced_the_stacked_pair(): void
    {
        $stocked = $this->freshBranch('Stocked');
        $bare    = $this->freshBranch('Bare');
        $this->inventoryIn($stocked->id);

        $option = $this->optionNamed(self::PREFIX . ' Pillar ' . uniqid(), 12);
        $option->menuItems()->attach([
            $this->itemNamed(self::PREFIX . ' A ' . uniqid(), $stocked->id)->id,
            $this->itemNamed(self::PREFIX . ' B ' . uniqid(), $bare->id)->id,
        ]);

        $block = $this->optionBlock($this->page(), $option->fresh());
        $status = substr($block, strpos($block, 'data-l="Inventory Status"'));

        // Exactly one pill per branch — the amber hint that used to sit
        // beside a red one is the pill's own third state now.
        $this->assertSame(2, substr_count($status, 'data-branch-id='), 'one pill per branch');
        $this->assertStringNotContainsString('pchy-branch-hint', $status);
        $this->assertStringNotContainsString('pchy-branch-group', $status);

        // Plain language, in place of Mapped/Unmapped.
        $this->assertStringContainsString($stocked->name . ' Needs Inventory</span>', $status);
        $this->assertStringContainsString($bare->name . ' Add Inventory First</span>', $status);
        $this->assertStringNotContainsString(': Mapped', $status);
        $this->assertStringNotContainsString(': Unmapped', $status);

        // Each pill carries what the live re-sync reads.
        $this->assertStringContainsString('data-title-empty="', $status);
        $this->assertSame(2, substr_count($status, 'data-empty-inventory='));
    }

    // ══════════════════════════════════════════════════════════════════
    // 4. The assignment banner
    // ══════════════════════════════════════════════════════════════════

    public function test_the_banner_is_wired_to_name_the_item_and_its_branch(): void
    {
        $far = $this->freshBranch('Bannered');
        $item = $this->itemNamed(self::PREFIX . ' Coke ' . uniqid(), $far->id);

        $html = $this->page();

        // The heading itself.
        $this->assertStringContainsString('Assigning Add-ons to:', $html);

        // Two named spans, and the parentheses around the branch as real
        // characters rather than CSS — a screen reader and a find-in-page
        // both have to see them.
        $this->assertStringContainsString('id="selectedItemName"', $html);
        $this->assertStringContainsString('id="selectedItemBranch"', $html);
        $this->assertMatchesRegularExpression(
            '/>\s*\(<span id="selectedItemBranch"/',
            $html,
            'the branch must render inside literal parentheses'
        );

        // selectMenuItem() fills both from the clicked row, so the banner and
        // the row cannot disagree about which item a save is about to rewrite.
        $this->assertStringContainsString('selectedNameEl.textContent', $html);
        $this->assertStringContainsString('selectedBranchEl.textContent', $html);
        $this->assertStringContainsString('el.dataset.branchName', $html);

        // And the row it reads really does carry that item's own branch name.
        $row = $this->itemRow($html, $item);
        $this->assertStringContainsString('data-branch-name="' . $far->name . '"', $row);
        $this->assertStringContainsString('data-id="' . $item->id . '"', $row);
    }

    public function test_an_all_branches_item_names_that_rather_than_going_blank(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Shared ' . uniqid(), null);

        $row = $this->itemRow($this->page(), $item);

        $this->assertStringContainsString('data-branch-name="All Branches"', $row);
    }

    public function test_the_save_button_is_large_high_contrast_and_says_save_add_ons(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('Save Add-ons', $html);
        $this->assertStringContainsString('pchy-assign-save', $html);
        $this->assertStringContainsString('.pchy-assign-save{min-height:48px', $html);

        // The old label is gone from every place the script restores it, not
        // just from the markup — a failed save used to repaint the button
        // with its own hard-coded copy.
        $this->assertStringNotContainsString('Save Options', $html);

        // The banner does NOT duplicate the option checkboxes; it counts the
        // ticks on the real ones instead.
        $this->assertStringContainsString('id="assignCount"', $html);
        $this->assertStringContainsString('refreshAssignCount', $html);
    }

    public function test_a_saved_row_repaints_its_add_ons_rather_than_going_stale(): void
    {
        // The save already rewrites the row's count and its data-options in
        // place. The new column has to be rewritten with them, or the row
        // shows a fresh count beside a stale list of names.
        $html = $this->page();

        $this->assertStringContainsString('repaintAssignedAddons', $html);
        $this->assertStringContainsString("td[data-l=\"Assigned Add-ons\"]", $html);

        // Built with createElement/textContent, never by concatenating an
        // admin-entered option name into innerHTML.
        $this->assertStringContainsString("chip.className = 'pchy-addon-chip'", $html);
        $this->assertStringNotContainsString(".innerHTML = '<span class=\"pchy-addon-chip\">'", $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // 5. The price rule this pass wrote copy around, not instead of
    // ══════════════════════════════════════════════════════════════════

    public function test_the_form_copy_changed_and_the_price_rule_did_not(): void
    {
        $html = $this->page();

        // New copy.
        $this->assertStringContainsString('Option / Add-on Name *', $html);
        $this->assertStringContainsString('placeholder="Enter Add-on Name"', $html);
        $this->assertStringContainsString('Price (₱) *', $html);
        $this->assertStringContainsString('placeholder="0.00"', $html);
        $this->assertStringNotContainsString('placeholder="e.g. Extra Cheese"', $html);

        // The price input's own guards are untouched: required, and a
        // minimum that cannot be satisfied by zero.
        $priceField = substr($html, strpos($html, 'name="price"'), 400);
        $this->assertStringContainsString('required', $priceField);
        $this->assertStringContainsString('min="0.01"', $priceField);
        $this->assertStringContainsString('max="99999999.99"', $priceField);
    }

    public function test_the_server_still_refuses_a_missing_or_zero_or_negative_price(): void
    {
        foreach ([['price' => ''], ['price' => '0'], ['price' => '-5'], []] as $payload) {
            $this->actingAs($this->admin(), 'admin')
                ->post('/admin/menu-options', array_merge(
                    ['name' => self::PREFIX . ' Rejected ' . uniqid()],
                    $payload
                ))
                ->assertSessionHasErrors('price');
        }

        $this->assertSame(
            0,
            MenuOption::where('name', 'like', self::PREFIX . ' Rejected %')->count(),
            'no option may have been created by any of those'
        );
    }

    public function test_a_valid_price_still_goes_through(): void
    {
        $name = self::PREFIX . ' Accepted ' . uniqid();

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/menu-options', ['name' => $name, 'price' => '12.50'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            '12.50',
            number_format((float) MenuOption::where('name', $name)->value('additional_price'), 2),
            'the copy pass must not have changed what a valid submission stores'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 5b. A layout bug found while measuring this pass across breakpoints
    // ══════════════════════════════════════════════════════════════════

    public function test_the_stacked_grid_track_cannot_be_floored_by_its_content(): void
    {
        // Found by measuring document width in headless Chrome from 360px to
        // 1600px while checking this pass: at some tablet widths the PAGE
        // itself scrolled sideways, on the original markup as much as on the
        // new — so it predates this work and is fixed with it.
        //
        // The two-column track is spelled minmax(0,1.62fr) minmax(0,1fr)
        // precisely so a wide table cannot floor it. The <=1180px rule that
        // stacks the panels was a bare 1fr, and a 1fr track floors at its
        // item's MIN-CONTENT width — so the stacked card refused to shrink
        // below its widest unbreakable row and pushed the document past the
        // viewport. The table is supposed to scroll inside .pchy-tblwrap;
        // the document is not supposed to scroll at all.
        //
        // Same shape of fix, and same shape of assertion, as the .staff-cols
        // track already pinned in DeadCodeRemovalAndNavConsistencyTest.
        $html = $this->page();

        $this->assertMatchesRegularExpression(
            '/@media\(max-width:1180px\)\{.*?\.pchy-grid\{grid-template-columns:minmax\(0,1fr\)\}/s',
            $html,
            'the stacked grid track must be minmax(0,1fr), never a bare 1fr'
        );

        // And the wrapper that is meant to absorb a wide table still does.
        $this->assertStringContainsString('.pchy-tblwrap{overflow-x:auto', $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // 6. Branch scope — the new column must not become a side door
    // ══════════════════════════════════════════════════════════════════

    public function test_a_branch_locked_supervisor_sees_no_other_branchs_item_in_the_new_column(): void
    {
        // The picker is scoped to the actor's branch, and the Assigned
        // Add-ons cell is rendered from those rows. Asserted because the new
        // cell is the first thing added to that table since the scope was
        // applied, and it reads a relation rather than a column.
        $far = $this->freshBranch('Forbidden');

        $farItem = $this->itemNamed(self::PREFIX . ' Far Secret ' . uniqid(), $far->id);
        $farItem->options()->attach($this->optionNamed(self::PREFIX . ' Far Addon ' . uniqid(), 9)->id);

        $ownItem = $this->itemNamed(self::PREFIX . ' Own ' . uniqid(), self::MAIN);

        $html = $this->page($this->supervisorAt(self::MAIN));

        $pickerStart = strpos($html, 'id="menuItemsList"');
        $picker = substr($html, $pickerStart, strpos($html, '</table>', $pickerStart) - $pickerStart);

        $this->assertStringNotContainsString($farItem->name, $picker);
        $this->assertStringContainsString($ownItem->name, $picker);
    }

    public function test_the_assign_endpoint_still_refuses_another_branchs_item(): void
    {
        // Unchanged by this pass, re-asserted because the banner it feeds was
        // rewritten: the refusal is a 404, not a 403, so a supervisor cannot
        // learn that the id exists.
        $far = $this->freshBranch('Unreachable');
        $farItem = $this->itemNamed(self::PREFIX . ' Unreachable Item ' . uniqid(), $far->id);
        $option = $this->optionNamed(self::PREFIX . ' Any ' . uniqid(), 5);

        $this->actingAs($this->supervisorAt(self::MAIN), 'admin')
            ->postJson('/admin/menu-options/assign/' . $farItem->id, ['option_ids' => [$option->id]])
            ->assertNotFound();

        $this->assertSame(0, $farItem->options()->count(), 'nothing may have been written');
    }
}

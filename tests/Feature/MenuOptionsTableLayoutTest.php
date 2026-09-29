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
 * admin/menu-options — the card-list -> table restructure (Sept 2026).
 *
 * The page put Menu Option cards on the left and a flat list of Menu Item
 * rows on the right. Both are tables now, the Menu Items one grouped by the
 * ITEM's branch, following the Categories page's table shape and the branch
 * section headers 891490d established on menu-items.blade.php.
 *
 * WHAT THIS FILE EXISTS TO PIN. The restructure had one way to go wrong that
 * matters more than any layout detail: menu_options has no branch_id — an
 * option is GLOBAL, and only its ingredient mapping is per-branch — so
 * grouping the option side by branch, the way the item side is grouped,
 * would have turned one "Extra Cheese" into an apparent "Main Branch: Extra
 * Cheese" and "Branch 2: Extra Cheese". These tests assert that an option is
 * one row no matter how many branches it reaches, and that the page's two
 * branch-shaped columns keep saying two different things:
 *
 *   Used In            the branch of each MENU ITEM using this option
 *   Ingredient Mapping the branch whose INVENTORY this option links into
 *
 * Both can disagree for the same option and the same branch — an option used
 * by a Branch 2 item while mapped only to Main Branch stock — and the page
 * has to show that, not resolve it.
 *
 * Every row created here carries the MOTL prefix and runs inside
 * DatabaseTransactions against pomida_db_testing, so nothing survives the run
 * and no pre-existing row is modified or deleted.
 */
class MenuOptionsTableLayoutTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'MOTL';
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
            'code'      => 'MT' . strtoupper(substr(uniqid(), -7)),
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
            'item_code'     => 'MT' . strtoupper(substr(uniqid(), -9)),
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
            ->get('/admin/menu-options')
            ->assertOk()
            ->content();
    }

    /** Every <tr> that is one option row, keyed by option id. */
    private function optionRowIds(string $html): array
    {
        preg_match_all('/<tr class="pchy-option option-row"\s+id="opt-(\d+)"/s', $html, $m);

        return array_map('intval', $m[1]);
    }

    /** One option's whole block: its <tr>, up to the next option's. */
    private function optionBlock(string $html, MenuOption $option): string
    {
        $start = strpos($html, 'id="opt-' . $option->id . '"');
        $this->assertNotFalse($start, "option #{$option->id} is not on the page");

        $end = strlen($html);
        if (preg_match('/id="opt-\d+"/', substr($html, $start + 1), $m, PREG_OFFSET_CAPTURE)) {
            $end = min($end, $start + 1 + $m[0][1]);
        }

        return substr($html, $start, $end - $start);
    }

    /** The picker table only, so page chrome cannot satisfy an assertion. */
    private function pickerTable(string $html): string
    {
        $start = strpos($html, 'id="menuItemsList"');
        $this->assertNotFalse($start, 'the Menu Items picker table is missing');

        return substr($html, $start, strpos($html, '</table>', $start) - $start);
    }

    /**
     * The picker in document order: ['head', <branch group>, <label>] for a
     * section header and ['item', <id>, <name>] for a row.
     */
    private function pickerSequence(string $html): array
    {
        preg_match_all(
            '/<tr class="pchy-branch-head" data-branch-group="([^"]*)">.*?<span class="value">([^<]*)<\/span>'
            . '|<tr class="menu-item-row menu-filter-row"\s+data-id="(\d+)"\s+data-name="([^"]*)"/s',
            $this->pickerTable($html),
            $m,
            PREG_SET_ORDER
        );

        return array_map(
            fn ($r) => $r[1] !== ''
                ? ['head', $r[1], trim($r[2])]
                : ['item', $r[3], $r[4]],
            $m
        );
    }

    /** The branch group each item id was rendered under, from the headers above it. */
    private function sectionOfEachItem(string $html): array
    {
        $current = null;
        $out = [];

        foreach ($this->pickerSequence($html) as $row) {
            if ($row[0] === 'head') {
                $current = $row[1];
            } else {
                $out[(int) $row[1]] = $current;
            }
        }

        return $out;
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. A GLOBAL OPTION IS ONE ROW — the thing this restructure could
    //    most easily have broken
    // ══════════════════════════════════════════════════════════════════

    public function test_an_option_used_across_three_branches_is_still_exactly_one_row(): void
    {
        $b2 = $this->freshBranch('B2');
        $b3 = $this->freshBranch('B3');

        $option = $this->optionNamed(self::PREFIX . ' Extra Cheese ' . uniqid());
        $option->menuItems()->attach($this->itemNamed(self::PREFIX . ' Pizza ' . uniqid(), self::MAIN)->id);
        $option->menuItems()->attach($this->itemNamed(self::PREFIX . ' Pizza ' . uniqid(), $b2->id)->id);
        $option->menuItems()->attach($this->itemNamed(self::PREFIX . ' Pizza ' . uniqid(), $b3->id)->id);

        $html = $this->page();

        $this->assertSame(
            1,
            substr_count($html, 'id="opt-' . $option->id . '"'),
            'a global option reaching three branches must still render as ONE row — menu_options has no branch_id'
        );

        $this->assertCount(
            1,
            array_keys($this->optionRowIds($html), $option->id, true),
            'the option appears more than once in the options table'
        );
    }

    public function test_the_options_table_is_not_grouped_by_branch_the_way_the_item_table_is(): void
    {
        $option = $this->optionNamed(self::PREFIX . ' Unli Gravy ' . uniqid());
        $option->menuItems()->attach($this->itemNamed(self::PREFIX . ' Chicken ' . uniqid(), self::MAIN)->id);

        $html = $this->page();

        $optionsTableStart = strpos($html, 'id="optionsList"');
        $optionsTableEnd = strpos($html, '</table>', $optionsTableStart);
        $optionsTable = substr($html, $optionsTableStart, $optionsTableEnd - $optionsTableStart);

        $this->assertStringNotContainsString(
            'pchy-branch-head',
            $optionsTable,
            'the options table must have no branch section headers — an option belongs to no branch'
        );
    }

    public function test_the_two_branch_columns_are_headed_as_the_different_things_they_are(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('<th>Used In</th>', $html);

        // Was "Ingredient Mapping". Same column, same fact, plain-language
        // heading — the pills under it now read "<branch> Ready" rather than
        // "<branch>: Mapped".
        $this->assertStringContainsString('<th>Inventory Status</th>', $html);

        // And the wording never claims the option itself belongs to a branch.
        $this->assertStringNotContainsString('Option Branch', $html);
        $this->assertStringNotContainsString('Options by Branch', $html);
    }

    /**
     * THE PART F DISTINCTION, on one option at once.
     *
     * "Used by a Branch 2 menu item" and "mapped to Branch 2 inventory" are
     * independent facts. An option assigned to a Branch 2 item but linked
     * only to Main Branch stock must say both — the item chip naming Branch 2
     * under Used In, and a Branch 2: Unmapped badge under Ingredient Mapping.
     */
    public function test_an_option_can_be_used_by_a_branch_it_is_not_mapped_to_and_says_both(): void
    {
        $far = $this->freshBranch('Far');

        $option = $this->optionNamed(self::PREFIX . ' Unli Gravy ' . uniqid());

        $mainItem = $this->itemNamed(self::PREFIX . ' Roasted Main ' . uniqid(), self::MAIN);
        $farItem = $this->itemNamed(self::PREFIX . ' Roasted Far ' . uniqid(), $far->id);
        $option->menuItems()->attach([$mainItem->id, $farItem->id]);

        // Mapped to MAIN stock only.
        MenuOptionIngredient::create([
            'menu_option_id' => $option->id,
            'inventory_id'   => $this->inventoryIn(self::MAIN)->id,
            'quantity_used'  => 1,
        ]);

        $block = $this->optionBlock($this->page(), $option->fresh());

        // Used In names the FAR branch, because a far-branch ITEM uses it.
        $this->assertStringContainsString($farItem->name, $block);
        $this->assertMatchesRegularExpression(
            '/' . preg_quote($farItem->name, '/') . '\s*<span class="pchy-ing-branch">' . preg_quote($far->name, '/') . '<\/span>/s',
            $block,
            'the Used In chip must tag the item with the ITEM\'s branch'
        );

        // Inventory Status says the far branch is NOT ready, at the same time.
        // The far branch is stocked (inventoryIn() above is Main's, but the
        // fixture branch itself has no active inventory), so the wording is
        // whichever of the two not-ready states applies — what is asserted is
        // that it is not the Ready one, and that it names the far branch.
        $this->assertMatchesRegularExpression(
            '/' . preg_quote($far->name, '/') . ' (Needs Inventory|Add Inventory First)<\/span>/',
            $block,
            'the far branch must be flagged as not ready under Inventory Status'
        );

        // ...and that Main, which does have the link, is Ready.
        $mainName = Branch::find(self::MAIN)->name;
        $this->assertStringContainsString($mainName . ' Ready</span>', $block);
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. MENU ITEMS ARE GROUPED UNDER THEIR OWN BRANCH
    // ══════════════════════════════════════════════════════════════════

    public function test_each_item_renders_under_a_header_for_its_own_branch(): void
    {
        $far = $this->freshBranch('Grouped');

        $mainItem = $this->itemNamed(self::PREFIX . ' Main Item ' . uniqid(), self::MAIN);
        $farItem = $this->itemNamed(self::PREFIX . ' Far Item ' . uniqid(), $far->id);

        $sections = $this->sectionOfEachItem($this->page());

        $this->assertSame((string) self::MAIN, $sections[$mainItem->id] ?? null);
        $this->assertSame((string) $far->id, $sections[$farItem->id] ?? null);
    }

    public function test_a_null_branch_item_gets_its_own_all_branches_section_rather_than_being_dropped(): void
    {
        $shared = $this->itemNamed(self::PREFIX . ' Shared Item ' . uniqid(), null);

        $html = $this->page();
        $sections = $this->sectionOfEachItem($html);

        $this->assertArrayHasKey($shared->id, $sections, 'a NULL-branch item must still be listed');
        $this->assertSame('unassigned', $sections[$shared->id]);

        $labels = array_map(
            fn ($r) => $r[2],
            array_values(array_filter($this->pickerSequence($html), fn ($r) => $r[0] === 'head' && $r[1] === 'unassigned'))
        );
        $this->assertSame(['All Branches'], $labels);
    }

    public function test_the_shared_all_branches_section_sorts_last(): void
    {
        $this->itemNamed(self::PREFIX . ' Shared Last ' . uniqid(), null);
        $far = $this->freshBranch('Sorted');
        $this->itemNamed(self::PREFIX . ' Far Sorted ' . uniqid(), $far->id);

        $groups = array_values(array_unique(array_map(
            fn ($r) => $r[1],
            array_filter($this->pickerSequence($this->page()), fn ($r) => $r[0] === 'head')
        )));

        $this->assertSame('unassigned', end($groups), 'the shared section must come after every real branch');

        $realBranches = array_map('intval', array_filter($groups, fn ($g) => $g !== 'unassigned'));
        $sorted = $realBranches;
        sort($sorted);
        $this->assertSame($sorted, $realBranches, 'real branch sections must be in branch-id order');
    }

    /**
     * The live confusion this restructure was asked to fix: two menu items
     * called "coke", one per branch. They are separate menu_items rows and
     * must stay separate, each under its own heading — never merged, never
     * made to look like one row shown twice.
     */
    public function test_two_items_sharing_a_name_land_in_different_branch_sections(): void
    {
        $far = $this->freshBranch('Twin');
        $name = self::PREFIX . ' coke ' . uniqid();

        $mainCoke = $this->itemNamed($name, self::MAIN);
        $farCoke = $this->itemNamed($name, $far->id);

        $html = $this->page();
        $sections = $this->sectionOfEachItem($html);

        $this->assertNotSame(
            $sections[$mainCoke->id],
            $sections[$farCoke->id],
            'same-named items in different branches must land in different sections'
        );
        $this->assertSame((string) self::MAIN, $sections[$mainCoke->id]);
        $this->assertSame((string) $far->id, $sections[$farCoke->id]);

        // Both rows survive as distinct, addressable records.
        $this->assertStringContainsString('data-id="' . $mainCoke->id . '"', $html);
        $this->assertStringContainsString('data-id="' . $farCoke->id . '"', $html);
    }

    public function test_a_section_header_counts_exactly_the_rows_rendered_under_it(): void
    {
        $far = $this->freshBranch('Counted');
        $this->itemNamed(self::PREFIX . ' Counted A ' . uniqid(), $far->id);
        $this->itemNamed(self::PREFIX . ' Counted B ' . uniqid(), $far->id);
        $this->itemNamed(self::PREFIX . ' Counted C ' . uniqid(), $far->id);

        $table = $this->pickerTable($this->page());

        $pattern = '/data-branch-group="' . $far->id . '">.*?<span class="count">(\d+) items?<\/span>/s';
        $this->assertSame(1, preg_match($pattern, $table, $m), 'the new branch has no section header');
        $this->assertSame(3, (int) $m[1], 'the header count must match the rows under it');

        $rendered = count(array_filter(
            $this->sectionOfEachItem($this->page()),
            fn ($g) => $g === (string) $far->id
        ));
        $this->assertSame(3, $rendered);
    }

    /** Every row carries the group key the client reads to show/hide headers. */
    public function test_every_item_row_carries_the_branch_group_its_header_is_keyed_by(): void
    {
        $far = $this->freshBranch('Keyed');
        $item = $this->itemNamed(self::PREFIX . ' Keyed Item ' . uniqid(), $far->id);

        $table = $this->pickerTable($this->page());

        $this->assertMatchesRegularExpression(
            '/<tr class="menu-item-row menu-filter-row"\s+data-id="' . $item->id . '"[^>]*data-branch-group="' . $far->id . '"/s',
            $table
        );
        $this->assertStringContainsString(
            '<tr class="pchy-branch-head" data-branch-group="' . $far->id . '">',
            $table
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. BRANCH SCOPE IS UNCHANGED BY THE REGROUPING
    // ══════════════════════════════════════════════════════════════════

    public function test_a_locked_supervisor_sees_only_their_own_branch_section(): void
    {
        $far = $this->freshBranch('Hidden');
        $mine = $this->itemNamed(self::PREFIX . ' Mine ' . uniqid(), self::MAIN);
        $theirs = $this->itemNamed(self::PREFIX . ' Theirs ' . uniqid(), $far->id);

        $html = $this->page($this->supervisorAt(self::MAIN));
        $sections = $this->sectionOfEachItem($html);

        $this->assertArrayHasKey($mine->id, $sections);
        $this->assertArrayNotHasKey($theirs->id, $sections, 'another branch\'s item must not be listed');

        $this->assertStringNotContainsString($theirs->name, $this->pickerTable($html));
        $this->assertStringNotContainsString(
            'data-branch-group="' . $far->id . '"',
            $this->pickerTable($html),
            'no section header for a branch the supervisor cannot act in'
        );
    }

    public function test_a_branchless_supervisor_gets_no_sections_at_all_not_even_the_shared_one(): void
    {
        $this->itemNamed(self::PREFIX . ' Shared Denied ' . uniqid(), null);
        $this->itemNamed(self::PREFIX . ' Main Denied ' . uniqid(), self::MAIN);

        $html = $this->page($this->supervisorAt(null));

        $this->assertSame([], $this->sectionOfEachItem($html), 'a branchless supervisor must be offered nothing');
        $this->assertStringNotContainsString('All Branches', $this->pickerTable($html));
    }

    public function test_a_locked_supervisors_used_in_column_stays_scoped_too(): void
    {
        $far = $this->freshBranch('UsedInScope');

        $option = $this->optionNamed(self::PREFIX . ' Scoped Option ' . uniqid());
        $mine = $this->itemNamed(self::PREFIX . ' Visible ' . uniqid(), self::MAIN);
        $theirs = $this->itemNamed(self::PREFIX . ' Invisible ' . uniqid(), $far->id);
        $option->menuItems()->attach([$mine->id, $theirs->id]);

        $block = $this->optionBlock($this->page($this->supervisorAt(self::MAIN)), $option->fresh());

        $this->assertStringContainsString($mine->name, $block);
        $this->assertStringNotContainsString(
            $theirs->name,
            $block,
            'Used In must not name another branch\'s item to a locked supervisor'
        );

        // The scoping leaves this supervisor ONE visible item, and one item
        // renders as its own chip rather than behind a "Used in N items"
        // disclosure — a click to reveal a single name buys nothing. What is
        // asserted is therefore the chip, and, separately, that the count
        // button is not offered for a list of one.
        $this->assertStringContainsString('pchy-usedin-solo', $block);
        $this->assertStringNotContainsString('usedin-btn-', $block);
    }

    // ══════════════════════════════════════════════════════════════════
    // 4. THE CONTROLS THE RESTRUCTURE HAD TO CARRY OVER
    // ══════════════════════════════════════════════════════════════════

    public function test_search_price_category_and_page_size_controls_all_survive(): void
    {
        $html = $this->page();

        foreach ([
            'id="optionSearch"', 'id="optionPriceFilter"', 'id="optionPageSize"', 'id="optionPager"',
            'id="menuSearch"', 'id="menuCategoryFilter"', 'id="menuPageSize"', 'id="menuPager"',
            'id="assignSection"',
        ] as $hook) {
            $this->assertStringContainsString($hook, $html, "the page lost {$hook}");
        }
    }

    /**
     * ONE LOGICAL ROW PER OPTION, AND NO DEAD MARKUP BEHIND IT.
     *
     * An option used to be a <tbody> holding a summary row plus a detail
     * row, because the detail row carried the edit form, the recipe editor
     * and the Used-in chips and the pair had to show, hide and page
     * together. All three have moved — two into the shared modal, the chips
     * into a popover — so the wrapper and the second row are gone rather
     * than kept as an empty shell that keeps an old selector true.
     *
     * The pager still hides an option by toggling .pchy-row-hidden on the
     * element carrying .option-row; that element is simply the <tr> now, so
     * there is nothing left that could be hidden separately from it.
     */
    public function test_an_option_is_exactly_one_row_with_no_leftover_detail_markup(): void
    {
        $option = $this->optionNamed(self::PREFIX . ' Paired ' . uniqid());

        $html = $this->page();
        $block = $this->optionBlock($html, $option);

        $this->assertStringContainsString('<tr class="pchy-option option-row"', $block);

        // Exactly one <tr> opens and one closes inside the option's block.
        $this->assertSame(1, substr_count($block, '<tr '), 'an option must open exactly one row');
        $this->assertSame(1, substr_count($block, '</tr>'), 'an option must close exactly one row');

        // The structures the detail row existed for are gone from the page
        // entirely, not merely from this block.
        foreach ([
            'pchy-opt-detail',
            'pchy-opt-main',
            '<tbody class="pchy-option',
            'id="edit-' . $option->id . '"',
            'id="ing-' . $option->id . '"',
            'id="opt-ing-list-' . $option->id . '"',
            'id="opt-ing-empty-' . $option->id . '"',
            'id="opt-ing-error-' . $option->id . '"',
            'toggleOptionEdit(',
            'toggleIngredients(',
        ] as $dead) {
            $this->assertStringNotContainsString(
                $dead,
                $html,
                "{$dead} is dead markup from the two-row layout and must not survive the cleanup"
            );
        }

        // What replaced them: both action buttons open the one modal, and
        // the row carries what that modal needs to become this option.
        $this->assertStringContainsString('openOptionModal(' . $option->id . ", 'edit')", $block);
        $this->assertStringContainsString('openOptionModal(' . $option->id . ", 'recipe')", $block);
        $this->assertStringContainsString('data-update-url="', $block);
        $this->assertStringContainsString('data-ingredients="', $block);
    }

    /**
     * The options table is one <tbody> holding one row per option, the same
     * shape the Menu Items picker beside it already used. Asserted on the
     * table itself rather than on the page, since the picker has a <tbody>
     * of its own.
     */
    public function test_the_options_table_holds_one_tbody_for_the_whole_list(): void
    {
        $this->optionNamed(self::PREFIX . ' Single A ' . uniqid());
        $this->optionNamed(self::PREFIX . ' Single B ' . uniqid());

        $html = $this->page();

        $start = strpos($html, 'id="optionsList"');
        $this->assertNotFalse($start);
        $end = strpos($html, '</table>', $start);
        $table = substr($html, $start, $end - $start);

        $this->assertSame(1, substr_count($table, '<tbody>'), 'the options table must have exactly one tbody');
        $this->assertSame(1, substr_count($table, '</tbody>'));
    }

    public function test_the_client_shows_a_branch_header_only_while_one_of_its_rows_is_on_screen(): void
    {
        $html = $this->page();

        // The header pass runs off the same visibleGroups set the row pass builds.
        $this->assertStringContainsString('visibleGroups', $html);
        $this->assertStringContainsString("'#menuItemsList .pchy-branch-head'", $html);
        $this->assertStringContainsString('head.dataset.branchGroup', $html);
        $this->assertStringContainsString("'pchy-row-hidden'", $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // 5. THE ADD / EDIT FORMS
    // ══════════════════════════════════════════════════════════════════

    public function test_the_add_form_still_posts_a_name_and_price_and_creates_an_option(): void
    {
        $name = self::PREFIX . ' Created ' . uniqid();

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/menu-options', ['name' => $name, 'price' => '12.50'])
            ->assertRedirect();

        $created = MenuOption::where('name', $name)->first();
        $this->assertNotNull($created, 'the Add New Option form no longer creates an option');
        $this->assertSame('12.50', (string) $created->additional_price);

        $this->assertStringContainsString($name, $this->page());
    }

    /**
     * Both forms share one proportional grid class, which is what keeps the
     * Add form and each Edit form sized alike. The ratio lives on that class,
     * so asserting the class is asserting the sizing.
     */
    public function test_both_forms_share_one_proportional_grid_class_in_the_asked_for_ratio(): void
    {
        $option = $this->optionNamed(self::PREFIX . ' Sized ' . uniqid());
        $html = $this->page();

        $this->assertStringContainsString('class="pchy-name-price-form pchy-add-form"', $html);
        $this->assertStringContainsString('class="pchy-name-price-form pchy-edit-form"', $html);

        $this->assertMatchesRegularExpression(
            '/\.pchy-name-price-form\{[^}]*grid-template-columns:minmax\(0,63fr\) minmax\(110px,15fr\) minmax\(150px,22fr\)/',
            $html,
            'the name/price/button ratio is no longer roughly 63 / 15 / 22'
        );

        // ...and still collapses to one column on a phone.
        $this->assertMatchesRegularExpression(
            '/@media \(max-width:520px\)\{ \.pchy-name-price-form\{grid-template-columns:1fr\} \}/',
            $html
        );

        // The edit form is the modal's, and there is ONE of it for the whole
        // page — a per-option copy is what the two-row layout used to ship.
        $this->assertSame(
            1,
            substr_count($html, 'class="pchy-name-price-form pchy-edit-form"'),
            'the edit form must exist once, in the modal, not once per option'
        );
        $this->assertStringContainsString('id="optionModalForm"', $html);
        $this->assertStringContainsString('openOptionModal(' . $option->id . ", 'edit')", $html);
    }

    public function test_the_name_and_price_inputs_are_still_present_and_required_on_both_forms(): void
    {
        $option = $this->optionNamed(self::PREFIX . ' Inputs ' . uniqid());
        $html = $this->page();

        // Add form.
        $addStart = strpos($html, 'class="pchy-name-price-form pchy-add-form"');
        $addForm = substr($html, $addStart, strpos($html, '</form>', $addStart) - $addStart);
        $this->assertMatchesRegularExpression('/name="name"[^>]*class="pchy-in"[^>]*required/s', $addForm);
        $this->assertMatchesRegularExpression('/name="price"[^>]*class="pchy-in"[^>]*required/s', $addForm);

        // The edit form is the modal's one form now, and its fields are
        // filled from the clicked row rather than rendered pre-filled N
        // times. So the inputs are asserted on the form, and the VALUES are
        // asserted on the row that feeds them.
        $editAnchor = strpos($html, 'id="optionModalForm"');
        $this->assertNotFalse($editAnchor, 'the modal has no edit form');

        // Back up to the <form that carries that id, so the opening tag's own
        // attributes are inside the slice and not just the fields under it.
        $editStart = strrpos(substr($html, 0, $editAnchor), '<form ');
        $editForm = substr($html, $editStart, strpos($html, '</form>', $editStart) - $editStart);

        $this->assertMatchesRegularExpression('/name="name"[^>]*class="pchy-in"[^>]*required/s', $editForm);
        $this->assertStringContainsString('name="price"', $editForm);
        $this->assertStringContainsString('method="POST"', $editForm);
        $this->assertStringContainsString('name="_method" value="PUT"', $editForm);

        // The row carries this option's own name and price for the modal to
        // load, and the update URL the form will post to.
        $row = $this->optionBlock($html, $option);
        $this->assertStringContainsString('data-option-name="' . e($option->name) . '"', $row);
        $this->assertStringContainsString('data-option-price="' . $option->additional_price . '"', $row);
        $this->assertStringContainsString(
            'data-update-url="' . route('admin.menu-options.update', $option->id) . '"',
            $row
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 6. THE LAYOUT COSTS NO QUERIES
    // ══════════════════════════════════════════════════════════════════

    /**
     * Branch headings, the used-in chips and the mapping badges are all
     * derived in-memory from relations showMenuOptions() already eager-loads,
     * so the page's query count must not move with the number of options,
     * items or branches on it. Asserted as "same count at 4x the data"
     * rather than as a fixed ceiling, so it measures growth — the thing that
     * would actually hurt — instead of pinning a number that honest changes
     * elsewhere would break.
     */
    public function test_the_page_costs_the_same_number_of_queries_at_four_times_the_data(): void
    {
        $small = $this->measurePageQueries(fn () => $this->seedPickerData(2, 3));
        $large = $this->measurePageQueries(fn () => $this->seedPickerData(8, 12));

        $this->assertSame(
            $small,
            $large,
            "the page grew from {$small} to {$large} queries when the data grew 4x — something is an N+1"
        );
    }

    private function seedPickerData(int $options, int $items): void
    {
        $branches = [self::MAIN, $this->freshBranch('Q1')->id, $this->freshBranch('Q2')->id];

        $inventory = [];
        foreach ($branches as $bid) {
            $inventory[$bid] = $this->inventoryIn($bid)->id;
        }

        $made = [];
        for ($i = 0; $i < $items; $i++) {
            $made[] = $this->itemNamed(self::PREFIX . ' QItem ' . $i . ' ' . uniqid(), $branches[$i % 3]);
        }

        for ($o = 0; $o < $options; $o++) {
            $option = $this->optionNamed(self::PREFIX . ' QOpt ' . $o . ' ' . uniqid());

            foreach ($made as $k => $item) {
                if ($k % 3 === $o % 3) {
                    $option->menuItems()->attach($item->id);
                }
            }

            MenuOptionIngredient::create([
                'menu_option_id' => $option->id,
                'inventory_id'   => $inventory[$branches[$o % 3]],
                'quantity_used'  => 1,
            ]);
        }
    }

    private function measurePageQueries(callable $seed): int
    {
        $seed();

        $count = 0;
        $counting = true;
        DB::listen(function () use (&$count, &$counting) {
            if ($counting) {
                $count++;
            }
        });

        $this->page();
        $counting = false;

        return $count;
    }
}

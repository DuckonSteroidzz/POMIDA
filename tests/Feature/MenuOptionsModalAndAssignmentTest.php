<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * admin/menu-options — Phase 2: the modal, the optgroups, and assignment.
 *
 * WHAT CHANGED, AND WHAT THIS FILE IS FOR.
 *
 * Editing an option used to expand a second table row underneath it, holding
 * a name/price form and a complete recipe editor. Two or three clicks and the
 * table was taller than the screen with the row being worked on pushed off
 * it. Both editors live in one modal now, the second row is gone, and an
 * option is one <tr>.
 *
 * That move had three ways to go wrong, and each one is pinned here rather
 * than described:
 *
 *   1. A SHARED dialog can show the wrong option's data. Every field it
 *      loads comes from the clicked row's own data-*, so the row is asserted
 *      to carry exactly what the dialog will read, and the script is asserted
 *      to clear on close rather than on open.
 *
 *   2. ONE editor means one inventory <select>. Per-option copies cost
 *      options x inventory items in <option> elements; the count is measured
 *      here, not assumed.
 *
 *   3. The branch rules must survive being re-expressed as JSON. can_remove
 *      and the withheld delete URL are asserted in
 *      OptionIngredientBranchLabelTest; what is asserted HERE is the other
 *      half — that a branch-locked supervisor is never offered another
 *      branch's inventory to pick from in the first place, and that the
 *      endpoint refuses regardless of what the page offered.
 *
 * It also covers assignOptions() as an N-option sync, including the
 * archived-option hole that was found and closed while this page was open.
 *
 * Every row created here carries the MOMA prefix and runs inside
 * DatabaseTransactions against pomida_db_testing, so nothing survives the run
 * and no pre-existing row is modified or deleted.
 */
class MenuOptionsModalAndAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'MOMA';
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
            'code'      => 'MM' . strtoupper(substr(uniqid(), -7)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function itemNamed(string $name, ?int $branchId = self::MAIN): MenuItem
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

    private function inventoryIn(?int $branchId, string $label = 'Inv'): Inventory
    {
        return Inventory::create([
            'item_name'     => self::PREFIX . ' ' . $label . ' ' . uniqid(),
            'item_code'     => 'MM' . strtoupper(substr(uniqid(), -9)),
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

    /** One option's whole row, from its opt-{id} anchor to the next option's. */
    private function optionRow(string $html, MenuOption $option): string
    {
        $marker = 'id="opt-' . $option->id . '"';
        $anchor = strpos($html, $marker);
        $this->assertNotFalse($anchor, "option #{$option->id} is not on the page");

        // Back up to the <tr that carries that id, so the opening tag's own
        // class and data-* are inside the slice and not just the cells.
        $start = strrpos(substr($html, 0, $anchor), '<tr ');
        $end = strpos($html, '</tr>', $anchor);

        return substr($html, $start, $end - $start);
    }

    /** The modal's markup only, so page chrome cannot satisfy an assertion. */
    private function modalHtml(string $html): string
    {
        $start = strpos($html, 'id="optionModal"');
        $this->assertNotFalse($start, 'the page has no option modal');

        // Bounded at the page's script block: everything after that is the
        // behaviour, not the dialog, and it names these same hooks.
        $end = strpos($html, '<script', $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    /** The inventory picker's <select>, bounded by its own </select>. */
    private function inventorySelect(string $html): string
    {
        $start = strpos($html, 'id="optionModalIngSelect"');
        $this->assertNotFalse($start, 'the modal has no inventory picker');

        $end = strpos($html, '</select>', $start);

        return substr($html, $start, $end - $start);
    }

    private function assign(User $actor, MenuItem $item, array $optionIds)
    {
        return $this->actingAs($actor, 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, ['option_ids' => $optionIds]);
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. THE MODAL EXISTS ONCE, AND THE ROW FEEDS IT
    // ══════════════════════════════════════════════════════════════════

    /**
     * One shell for the whole page. A per-option copy is precisely what the
     * two-row layout shipped, and is the thing this pass removed.
     */
    public function test_there_is_exactly_one_modal_no_matter_how_many_options(): void
    {
        foreach (range(1, 4) as $i) {
            $this->optionNamed(self::PREFIX . " Many {$i} " . uniqid());
        }

        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'id="optionModal"'));
        $this->assertSame(1, substr_count($html, 'id="optionModalForm"'));
        $this->assertSame(1, substr_count($html, 'id="optionModalIngSelect"'));
        $this->assertSame(1, substr_count($html, 'id="optionModalIngList"'));
        $this->assertSame(1, substr_count($html, 'id="optionModalIngError"'));
    }

    /** The dialog says what it is, and offers a way out of itself. */
    public function test_the_modal_has_the_asked_for_title_a_close_button_and_a_backdrop(): void
    {
        $modal = $this->modalHtml($this->page());

        $this->assertStringContainsString('Edit Option / Add-on', $modal);
        $this->assertStringContainsString('class="pchy-modal-backdrop"', $modal);
        $this->assertStringContainsString('id="optionModalClose"', $modal);
        $this->assertStringContainsString('aria-label="Close"', $modal);

        // Announced as a dialog, and labelled by its own title.
        $this->assertStringContainsString('role="dialog"', $modal);
        $this->assertStringContainsString('aria-modal="true"', $modal);
        $this->assertStringContainsString('aria-labelledby="optionModalTitle"', $modal);

        // The X and the backdrop share one dismiss hook rather than each
        // carrying its own handler.
        $this->assertSame(2, substr_count($modal, 'data-modal-dismiss'));
    }

    /**
     * THE NAME AND PRICE THE DIALOG WILL SHOW.
     *
     * Nothing is rendered pre-filled per option any more, so what has to be
     * right is the row: the dialog can only be correct if the attributes it
     * reads are. The raw price is asserted as well as the displayed one,
     * because the cell carries "+P1,234.50" and the number input needs
     * "1234.50".
     */
    public function test_each_row_carries_the_name_and_raw_price_the_modal_loads(): void
    {
        $option = $this->optionNamed(self::PREFIX . ' Loaded ' . uniqid(), 1234.5);

        $row = $this->optionRow($this->page(), $option);

        $this->assertStringContainsString('data-option-name="' . e($option->name) . '"', $row);
        $this->assertStringContainsString('data-option-price="1234.50"', $row);

        // The displayed cell is formatted and the attribute is not — if these
        // two ever became the same string, the number input would be fed a
        // comma and silently refuse to load.
        $this->assertStringContainsString('+₱1,234.50', $row);
    }

    /** The PUT route stays the authority for a rename or a reprice. */
    public function test_the_modal_form_posts_put_to_the_rows_own_update_route(): void
    {
        $option = $this->optionNamed(self::PREFIX . ' Routed ' . uniqid());

        $html = $this->page();

        $this->assertStringContainsString(
            'data-update-url="' . route('admin.menu-options.update', $option->id) . '"',
            $this->optionRow($html, $option)
        );

        $modal = $this->modalHtml($html);
        $this->assertStringContainsString('name="_method" value="PUT"', $modal);
        $this->assertStringContainsString('name="_token"', $modal);

        // The script sets the action from that attribute and never builds a
        // URL out of an id, so route() stays the one thing that knows it.
        $view = file_get_contents(resource_path('views/admin/menu-options.blade.php'));
        $this->assertStringContainsString('form.action = row.dataset.updateUrl', $view);
    }

    /** And that route still does what it always did. */
    public function test_saving_the_modal_form_renames_and_reprices_the_option(): void
    {
        $option = $this->optionNamed(self::PREFIX . ' Before ' . uniqid(), 5);
        $newName = self::PREFIX . ' After ' . uniqid();

        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.menu-options.update', $option->id), [
                'name'  => $newName,
                'price' => '42.25',
            ])
            ->assertRedirect(route('admin.menu-options'));

        $option->refresh();
        $this->assertSame($newName, $option->name);
        $this->assertSame('42.25', (string) $option->additional_price);
    }

    /** The positive-price rule is untouched by the move into a dialog. */
    public function test_the_modal_route_still_refuses_a_zero_price(): void
    {
        $option = $this->optionNamed(self::PREFIX . ' Priced ' . uniqid(), 7.5);

        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.menu-options.update', $option->id), [
                'name'  => $option->name,
                'price' => '0',
            ])
            ->assertSessionHasErrors('price');

        $this->assertSame('7.50', (string) $option->fresh()->additional_price);
    }

    /** Both forms now introduce the field with the same words. */
    public function test_the_edit_label_matches_the_add_form(): void
    {
        $html = $this->page();

        $this->assertSame(
            2,
            substr_count($html, 'Option / Add-on Name *'),
            'the Add form and the Edit form must introduce the field identically'
        );
        $this->assertStringNotContainsString('>Option Name *<', $html);
    }

    /**
     * SWITCHING OPTIONS MUST NOT LEAVE THE LAST ONE'S RECIPE ON SCREEN.
     *
     * A PHP test cannot drive the dialog, so this pins the two properties
     * that make A -> B -> A safe, the way this repo pins other page scripts:
     * the list is REBUILT rather than appended to, and the dialog is emptied
     * on close rather than only refilled on open. The behaviour itself was
     * exercised in a real browser for this pass — see the commit message.
     */
    public function test_the_modal_rebuilds_its_recipe_and_empties_itself_on_close(): void
    {
        $view = file_get_contents(resource_path('views/admin/menu-options.blade.php'));

        // Rebuilt from scratch every time it is filled.
        $this->assertStringContainsString("list.innerHTML = '';", $view);
        $this->assertStringContainsString('optIngRenderList(readRowIngredients(row));', $view);

        // Emptied on the way out, so nothing of the option just closed is on
        // screen for the moment before the next one loads.
        $this->assertStringContainsString("modalEl('optionModalIngList').innerHTML = '';", $view);
        $this->assertStringContainsString('modalOptionId = null;', $view);

        // Which option the dialog stands in for is one variable, not a value
        // re-derived from an element id on every call.
        $this->assertStringContainsString('let modalOptionId = null;', $view);
        $this->assertStringContainsString("ingForm.dataset.option = modalOptionId;", $view);
    }

    /**
     * The add path used to find its option by walking up the tree from its
     * own form. That form is in the modal now and is inside no row at all,
     * so it looks the row up by id — the shape the remove path already used.
     */
    public function test_the_ingredient_count_is_updated_by_id_not_by_walking_up_from_the_form(): void
    {
        $view = file_get_contents(resource_path('views/admin/menu-options.blade.php'));

        $this->assertStringNotContainsString(
            "form.closest('.pchy-option')",
            $view,
            'the add path must not walk up from a form that no longer sits inside a row'
        );

        $this->assertStringContainsString('function optIngSetCount(optionId, n) {', $view);
        $this->assertStringContainsString("document.getElementById('opt-' + optionId)", $view);
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. THE INVENTORY PICKER IS GROUPED BY BRANCH
    // ══════════════════════════════════════════════════════════════════

    public function test_inventory_is_grouped_under_an_optgroup_per_branch(): void
    {
        $alpha = $this->freshBranch('Alpha');
        $bravo = $this->freshBranch('Bravo');

        $alphaInv = $this->inventoryIn($alpha->id, 'Alpha Stock');
        $bravoInv = $this->inventoryIn($bravo->id, 'Bravo Stock');

        $select = $this->inventorySelect($this->page());

        $this->assertStringContainsString('<optgroup label="' . e($alpha->name) . '">', $select);
        $this->assertStringContainsString('<optgroup label="' . e($bravo->name) . '">', $select);

        // Each item sits under its OWN branch's group, which is the whole
        // point — asserted by slicing the group rather than by the page
        // merely containing both strings somewhere.
        $this->assertStringContainsString($alphaInv->item_name, $this->optgroupBody($select, $alpha->name));
        $this->assertStringNotContainsString($bravoInv->item_name, $this->optgroupBody($select, $alpha->name));

        $this->assertStringContainsString($bravoInv->item_name, $this->optgroupBody($select, $bravo->name));
        $this->assertStringNotContainsString($alphaInv->item_name, $this->optgroupBody($select, $bravo->name));
    }

    /** One <optgroup>'s contents, up to the next group or the end. */
    private function optgroupBody(string $select, string $label): string
    {
        $marker = '<optgroup label="' . e($label) . '">';
        $start = strpos($select, $marker);
        $this->assertNotFalse($start, "no optgroup for {$label}");

        $end = strpos($select, '</optgroup>', $start);

        return substr($select, $start, $end - $start);
    }

    /**
     * The branch was repeated on every single <option> before, because there
     * was nothing else to say which one it was. The heading says it now, so
     * the trailing text is gone rather than left to say it twice.
     */
    public function test_the_branch_name_is_not_repeated_inside_each_option(): void
    {
        $branch = $this->freshBranch('Repeat');
        $inv = $this->inventoryIn($branch->id, 'Repeat Stock');

        $select = $this->inventorySelect($this->page());

        $this->assertStringContainsString($inv->item_name . ' (' . $inv->unit . ')', $select);
        $this->assertStringNotContainsString('— ' . $branch->name, $select);

        // The branch name appears once for this branch: as the heading.
        $this->assertSame(
            1,
            substr_count($select, e($branch->name)),
            'the branch name must appear once, as the optgroup label'
        );
    }

    /**
     * inventory.branch_id is nullable (ON DELETE SET NULL), so a row can
     * belong to no branch. Dropping those from the picker would silently
     * withhold a row the actor is entitled to link, which is a worse bug than
     * an awkward heading.
     */
    public function test_branchless_inventory_gets_its_own_group_rather_than_being_dropped(): void
    {
        $orphan = $this->inventoryIn(null, 'Orphan Stock');

        $select = $this->inventorySelect($this->page());

        $this->assertStringContainsString('<optgroup label="Unassigned / No Branch">', $select);
        $this->assertStringContainsString(
            $orphan->item_name,
            $this->optgroupBody($select, 'Unassigned / No Branch')
        );
    }

    /** The branchless group sorts after every real branch. */
    public function test_the_unassigned_group_comes_last(): void
    {
        $branch = $this->freshBranch('Ordered');
        $this->inventoryIn($branch->id, 'Ordered Stock');
        $this->inventoryIn(null, 'Last Stock');

        $select = $this->inventorySelect($this->page());

        $this->assertGreaterThan(
            strpos($select, '<optgroup label="' . e($branch->name) . '">'),
            strpos($select, '<optgroup label="Unassigned / No Branch">'),
            'the branchless group must sort after the real branches'
        );
    }

    /**
     * A LOCKED SUPERVISOR IS NOT OFFERED ANOTHER BRANCH'S STOCK AT ALL.
     *
     * Absent from the markup, not hidden in it: showMenuOptions() narrows
     * $inventoryItems before the view ever sees it, and the grouping added
     * here regroups that collection rather than widening it. Asserted on the
     * whole page, so a row cannot be somewhere else on it either.
     */
    public function test_a_locked_supervisor_is_offered_no_other_branchs_inventory(): void
    {
        $far = $this->freshBranch('Far');
        $mine = $this->inventoryIn(self::MAIN, 'Mine');
        $theirs = $this->inventoryIn($far->id, 'Theirs');
        $orphan = $this->inventoryIn(null, 'Nobodys');

        $html = $this->page($this->supervisorAt(self::MAIN));
        $select = $this->inventorySelect($html);

        $this->assertStringContainsString($mine->item_name, $select);

        foreach ([$theirs, $orphan] as $withheld) {
            $this->assertStringNotContainsString($withheld->item_name, $html);
        }

        // Exactly one group: their own branch's.
        $this->assertSame(1, substr_count($select, '<optgroup'));
        $this->assertStringNotContainsString(e($far->name), $select);
        $this->assertStringNotContainsString('Unassigned / No Branch', $select);
    }

    /** And the endpoint refuses what the page never offered. */
    public function test_the_endpoint_still_refuses_another_branchs_inventory(): void
    {
        $far = $this->freshBranch('Refused');
        $option = $this->optionNamed(self::PREFIX . ' Guarded ' . uniqid());
        $theirs = $this->inventoryIn($far->id, 'Theirs');

        $this->actingAs($this->supervisorAt(self::MAIN), 'admin')
            ->postJson(route('admin.menu-options.ingredients.add', $option->id), [
                'inventory_id'  => $theirs->id,
                'quantity_used' => 1,
            ])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame(0, $option->ingredients()->count());
    }

    /**
     * ONE SELECT, NOT ONE PER OPTION.
     *
     * The inline editor rendered the whole picker into every option's hidden
     * panel, so the page grew with options x inventory items. Measured as a
     * count of rendered <option> elements against a fixture where a per-option
     * copy would be unmistakable: 6 options and 5 inventory rows is 30 if the
     * old shape came back, and 5 if it did not.
     */
    public function test_the_inventory_picker_is_rendered_once_not_once_per_option(): void
    {
        $branch = $this->freshBranch('Counted');

        $invNames = [];
        foreach (range(1, 5) as $i) {
            $invNames[] = $this->inventoryIn($branch->id, "Counted {$i}")->item_name;
        }

        foreach (range(1, 6) as $i) {
            $this->optionNamed(self::PREFIX . " Counted {$i} " . uniqid());
        }

        $html = $this->page();

        foreach ($invNames as $name) {
            $this->assertSame(
                1,
                substr_count($html, $name),
                "inventory item {$name} is rendered more than once — the picker is being duplicated per option"
            );
        }

        $this->assertSame(1, substr_count($html, '-- Select inventory item --'));
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. THE FILTER AND PAGER HOOKS SURVIVED THE ROW CHANGE
    // ══════════════════════════════════════════════════════════════════

    /**
     * The search, the price filter and the pager all read the element
     * carrying .option-row. That element used to be the <tbody> and is the
     * <tr> now, so what has to hold is that the attributes moved with the
     * class and did not get left behind on a wrapper that no longer exists.
     */
    public function test_the_option_row_still_carries_every_attribute_the_filters_read(): void
    {
        $paid = $this->optionNamed(self::PREFIX . ' Paid Filter ' . uniqid(), 25);
        $free = $this->optionNamed(self::PREFIX . ' Free Filter ' . uniqid(), 0);

        $html = $this->page();

        $paidRow = $this->optionRow($html, $paid);
        $this->assertStringContainsString('class="pchy-option option-row"', $paidRow);
        $this->assertStringContainsString('data-name="' . strtolower($paid->name) . '"', $paidRow);
        $this->assertStringContainsString('data-price="paid"', $paidRow);

        $freeRow = $this->optionRow($html, $free);
        $this->assertStringContainsString('data-price="free"', $freeRow);

        // And the script still reads them off .option-row, one element.
        $this->assertMatchesRegularExpression(
            '/querySelectorAll\(\s*\x27\.option-row\x27\s*\)/s',
            $html,
            'the pager must still collect options by .option-row'
        );
        $this->assertStringContainsString('row.dataset.name', $html);
        $this->assertStringContainsString('row.dataset.price', $html);
        $this->assertStringContainsString("'pchy-row-hidden'", $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // 3b. TWO BUGS THE BROWSER FOUND IN THIS PASS
    // ══════════════════════════════════════════════════════════════════

    /**
     * READING AN ITEM'S ADD-ONS MUST NOT RE-AIM THE NEXT SAVE AT IT.
     *
     * The "N Add-ons" button sits inside a <tr onclick="selectMenuItem(this)">,
     * so the click that opens the popover also reached the row and made that
     * item the target of the next "Save Add-ons" — a click that looks like a
     * question silently answering a different one, on the single control that
     * decides which row a save overwrites. An admin checking what is already
     * on item B, mid-way through assigning to item A, would then have saved
     * A's ticks onto B.
     *
     * Found by clicking the button in a real browser (the popover appeared
     * not to open at all, because selecting a row expands the assignment
     * banner and that layout shift closed it again). Pinned here because the
     * markup and the repaint both have to keep doing it and there are two
     * places to forget.
     */
    public function test_opening_the_add_ons_popover_does_not_select_the_menu_item_row(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Bubbling ' . uniqid());

        $ids = collect(range(1, 3))
            ->map(fn ($i) => $this->optionNamed(self::PREFIX . " Bub {$i} " . uniqid())->id)
            ->all();

        $item->options()->attach($ids);

        $html = $this->page();

        // The rendered button hands its event to the handler...
        $this->assertStringContainsString('onclick="toggleAssignedAddons(this, event)"', $html);

        // ...and the handler stops it before it reaches the row.
        $view = file_get_contents(resource_path('views/admin/menu-options.blade.php'));
        $this->assertStringContainsString('event.stopPropagation();', $view);

        // The repainted button, rebuilt after every save, must do the same —
        // this is the copy that is easy to forget.
        $this->assertStringContainsString('toggleAssignedAddons(btn, e);', $view);

        // And the row itself still selects, so the fix did not break the
        // thing the row is for.
        $this->assertStringContainsString('onclick="selectMenuItem(this)"', $html);
    }

    /**
     * A SCROLL MUST MOVE A POPOVER, NOT CLOSE IT.
     *
     * The panels are position:fixed, so something has to keep them under
     * their button. The first version closed them on scroll — which looked
     * right and was not: scroll events are dispatched ASYNCHRONOUSLY, at a
     * frame boundary, so a click that first scrolls its own button into view
     * (browsers do this for a control near the viewport edge) opened the
     * popover and then closed it again on the next frame, from a scroll
     * nobody made. The button read as dead.
     *
     * Repositioning has no such race. Closing is kept for the one case where
     * it is the honest answer: the button has left the viewport, so there is
     * nothing to anchor to.
     */
    public function test_a_scroll_repositions_the_popover_instead_of_closing_it(): void
    {
        $view = file_get_contents(resource_path('views/admin/menu-options.blade.php'));

        $this->assertStringContainsString("window.addEventListener('scroll', repositionPop, true);", $view);
        $this->assertStringContainsString("window.addEventListener('resize', repositionPop);", $view);

        $this->assertStringNotContainsString(
            "window.addEventListener('scroll', closePop, true);",
            $view,
            'closing on scroll races the click that caused the scroll'
        );

        // It still closes when the anchor is genuinely gone.
        $this->assertStringContainsString('if (gone) {', $view);

        // Capture phase, because scroll does not bubble and the table has
        // its own scrolling wrapper.
        $this->assertStringContainsString('function repositionPop() {', $view);
    }

    /**
     * The popover is taken out of flow, which is the whole reason a row's
     * height cannot change when one opens. Pinned as CSS because a PHP test
     * cannot measure a rendered row; the measurement itself was done in a
     * real browser for this pass, at 1440, 768 and 390 (see the commit).
     */
    public function test_both_popovers_are_fixed_and_hidden_until_opened(): void
    {
        $html = $this->page();

        $this->assertMatchesRegularExpression('/\.pchy-pop\{display:none;position:fixed/', $html);
        $this->assertMatchesRegularExpression('/\.pchy-pop\.show\{display:flex/', $html);

        // Clamped to the viewport rather than allowed to run off it.
        $this->assertStringContainsString('max-width:min(320px,calc(100vw - 24px))', $html);
        $this->assertStringContainsString('max-height:min(320px,calc(100vh - 24px))', $html);
        $this->assertStringContainsString('overflow-y:auto', $html);

        // Both disclosures use the one class, so there is one behaviour to
        // keep right rather than two.
        $this->assertStringContainsString('class="pchy-pop pchy-usedin-list"', $html);
    }

    /**
     * The modal's own body scrolls, so a long recipe cannot push the header
     * and the close button off a short screen, and the page behind it is
     * locked while it is open.
     */
    public function test_the_modal_scrolls_internally_and_locks_the_page_behind_it(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('.pchy-modal-bd{padding:1rem;overflow-y:auto', $html);
        $this->assertStringContainsString('flex-direction:column', $html);

        // svh as well as vh: a mobile browser's toolbars make 100vh taller
        // than what is actually on screen.
        $this->assertStringContainsString('max-height:calc(100svh - 2rem)', $html);

        $view = file_get_contents(resource_path('views/admin/menu-options.blade.php'));
        $this->assertStringContainsString("document.body.style.overflow = 'hidden';", $view);
        $this->assertStringContainsString("document.body.style.overflow = '';", $view);
    }

    // ══════════════════════════════════════════════════════════════════
    // 4. ASSIGNING N OPTIONS AT ONCE
    // ══════════════════════════════════════════════════════════════════

    public function test_three_options_can_be_assigned_in_one_save(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Triple ' . uniqid());

        $ids = collect(range(1, 3))
            ->map(fn ($i) => $this->optionNamed(self::PREFIX . " Tri {$i} " . uniqid())->id)
            ->all();

        $this->assign($this->admin(), $item, $ids)->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsCanonicalizing($ids, $item->options()->pluck('menu_options.id')->all());
        $this->assertSame(3, $item->options()->count());
    }

    /**
     * TEN AND MORE. sync() has no cap, and none was invented here — what is
     * proved is that it genuinely does not have one, and that twelve ids
     * produce twelve pivot rows rather than twelve attempts at one.
     */
    public function test_twelve_options_can_be_assigned_in_one_save(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Dozen ' . uniqid());

        $ids = collect(range(1, 12))
            ->map(fn ($i) => $this->optionNamed(self::PREFIX . " Doz {$i} " . uniqid())->id)
            ->all();

        $this->assign($this->admin(), $item, $ids)->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsCanonicalizing($ids, $item->options()->pluck('menu_options.id')->all());

        // Twelve rows, not twelve-and-a-duplicate: the pivot is counted
        // directly rather than through a relation that might dedupe.
        $this->assertSame(
            12,
            DB::table('menu_item_options')->where('menu_item_id', $item->id)->count()
        );
    }

    /** A + B, then A + B + C: the existing two are kept and C joins them. */
    public function test_adding_a_third_option_keeps_the_first_two(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Grow ' . uniqid());

        $a = $this->optionNamed(self::PREFIX . ' Grow A ' . uniqid())->id;
        $b = $this->optionNamed(self::PREFIX . ' Grow B ' . uniqid())->id;
        $c = $this->optionNamed(self::PREFIX . ' Grow C ' . uniqid())->id;

        $this->assign($this->admin(), $item, [$a, $b])->assertOk();
        $this->assertEqualsCanonicalizing([$a, $b], $item->options()->pluck('menu_options.id')->all());

        $this->assign($this->admin(), $item, [$a, $b, $c])->assertOk();
        $this->assertEqualsCanonicalizing([$a, $b, $c], $item->options()->pluck('menu_options.id')->all());

        // A survived the second save rather than being detached and
        // re-attached under a new pivot row.
        $this->assertSame(
            3,
            DB::table('menu_item_options')->where('menu_item_id', $item->id)->count()
        );
    }

    /**
     * A + B, then A + C. B goes, C arrives, A stays.
     *
     * This is sync()'s INTENDED semantic and not an overwrite bug: the page
     * sends every ticked box, so an id that is absent was unticked on
     * purpose. Pinned so that a future "merge instead of replace" change
     * cannot be made by accident.
     */
    public function test_replacing_one_option_detaches_only_the_one_left_out(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Swap ' . uniqid());

        $a = $this->optionNamed(self::PREFIX . ' Swap A ' . uniqid())->id;
        $b = $this->optionNamed(self::PREFIX . ' Swap B ' . uniqid())->id;
        $c = $this->optionNamed(self::PREFIX . ' Swap C ' . uniqid())->id;

        $this->assign($this->admin(), $item, [$a, $b])->assertOk();

        $this->assign($this->admin(), $item, [$a, $c])->assertOk();

        $final = $item->options()->pluck('menu_options.id')->all();
        $this->assertEqualsCanonicalizing([$a, $c], $final);
        $this->assertNotContains($b, $final, 'B was unticked, so it must be detached');
        $this->assertContains($a, $final, 'A was ticked both times and must not have been disturbed');
    }

    /** The same id sent twice is one pivot row, not two. */
    public function test_a_repeated_id_does_not_create_a_duplicate_pivot_row(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Dupe ' . uniqid());
        $a = $this->optionNamed(self::PREFIX . ' Dupe A ' . uniqid())->id;

        $this->assign($this->admin(), $item, [$a, $a, $a])->assertOk();

        $this->assertSame(
            1,
            DB::table('menu_item_options')->where('menu_item_id', $item->id)->count()
        );
    }

    /** Assigning to one item leaves the same options on another item alone. */
    public function test_assigning_to_one_item_does_not_touch_another_items_assignments(): void
    {
        $itemA = $this->itemNamed(self::PREFIX . ' Item A ' . uniqid());
        $itemB = $this->itemNamed(self::PREFIX . ' Item B ' . uniqid());

        $x = $this->optionNamed(self::PREFIX . ' Shared X ' . uniqid())->id;
        $y = $this->optionNamed(self::PREFIX . ' Shared Y ' . uniqid())->id;

        $this->assign($this->admin(), $itemA, [$x, $y])->assertOk();
        $this->assign($this->admin(), $itemB, [$x])->assertOk();

        $this->assertEqualsCanonicalizing([$x, $y], $itemA->options()->pluck('menu_options.id')->all());
        $this->assertEqualsCanonicalizing([$x], $itemB->options()->pluck('menu_options.id')->all());
    }

    // ══════════════════════════════════════════════════════════════════
    // 5. ARCHIVED OPTIONS ARE NOT ASSIGNABLE (found while in this area)
    // ══════════════════════════════════════════════════════════════════

    /**
     * assignOptions() resolved its ids through withArchived(), which lifts
     * the Archivable global scope, so an archived add-on passed the existence
     * check like any other. The rendered checkbox list has never held one, so
     * the page could not send it — but option_ids is a JSON array this
     * endpoint takes on trust once the ids resolve, and a crafted request
     * naming an archived id was accepted and synced.
     *
     * Nothing appeared on a customer menu as a result; the same global scope
     * hides an archived row everywhere it is read. The damage was deferred,
     * not absent: restoring the option later would have put it back on a menu
     * item nobody chose to put it on.
     */
    public function test_a_crafted_request_cannot_assign_an_archived_option(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Archived Target ' . uniqid());
        $option = $this->optionNamed(self::PREFIX . ' Archived Addon ' . uniqid());

        $option->archive();
        $this->assertTrue($option->fresh()->isArchived());

        // The page does not offer it, which is why this has to be crafted.
        $this->assertStringNotContainsString('data-id="' . $option->id . '"', $this->page());

        $this->assign($this->admin(), $item, [$option->id])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame(
            0,
            DB::table('menu_item_options')
                ->where('menu_item_id', $item->id)
                ->where('menu_option_id', $option->id)
                ->count(),
            'an archived add-on must not be newly assigned'
        );
    }

    /** One archived id in an otherwise valid payload changes nothing at all. */
    public function test_an_archived_id_mixed_with_live_ones_saves_nothing(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Mixed ' . uniqid());

        $live = $this->optionNamed(self::PREFIX . ' Mixed Live ' . uniqid())->id;
        $existing = $this->optionNamed(self::PREFIX . ' Mixed Existing ' . uniqid())->id;
        $archived = $this->optionNamed(self::PREFIX . ' Mixed Archived ' . uniqid());

        $this->assign($this->admin(), $item, [$existing])->assertOk();

        $archived->archive();

        $this->assign($this->admin(), $item, [$existing, $live, $archived->id])
            ->assertStatus(422);

        // The refusal is all-or-nothing: the item still carries exactly what
        // it carried before, so a rejected save cannot half-apply.
        $this->assertEqualsCanonicalizing([$existing], $item->options()->pluck('menu_options.id')->all());
    }

    /** Restoring it first makes it an ordinary option again. */
    public function test_restoring_an_option_makes_it_assignable_again(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Restored Target ' . uniqid());
        $option = $this->optionNamed(self::PREFIX . ' Restored Addon ' . uniqid());

        $option->archive();
        $this->assign($this->admin(), $item, [$option->id])->assertStatus(422);

        $option->unarchive();
        $this->assertFalse($option->fresh()->isArchived());

        $this->assign($this->admin(), $item, [$option->id])->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsCanonicalizing([$option->id], $item->options()->pluck('menu_options.id')->all());
    }

    /** A live option is still assignable, which is the point of the fix. */
    public function test_a_live_option_is_unaffected_by_the_archived_check(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Live Target ' . uniqid());
        $option = $this->optionNamed(self::PREFIX . ' Live Addon ' . uniqid());

        $this->assign($this->admin(), $item, [$option->id])->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsCanonicalizing([$option->id], $item->options()->pluck('menu_options.id')->all());
    }

    /**
     * ARCHIVING IS NOT WHAT CHANGED. An option archived while already
     * assigned keeps its pivot rows — that is CatalogueLifecycle's business
     * and MenuOptionArchivePreservesLinksTest pins it. This fix refuses a NEW
     * assignment and removes nothing, which is asserted here so the two
     * cannot be confused for each other later.
     */
    public function test_archiving_still_leaves_an_existing_assignment_in_place(): void
    {
        $item = $this->itemNamed(self::PREFIX . ' Keeps ' . uniqid());
        $option = $this->optionNamed(self::PREFIX . ' Keeps Addon ' . uniqid());

        $this->assign($this->admin(), $item, [$option->id])->assertOk();

        $option->archive();

        $this->assertSame(
            1,
            DB::table('menu_item_options')
                ->where('menu_item_id', $item->id)
                ->where('menu_option_id', $option->id)
                ->count(),
            'archiving must not delete an assignment that already existed'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 6. BRANCH SCOPE ON ASSIGNMENT SURVIVED THE MODAL CONVERSION
    // ══════════════════════════════════════════════════════════════════

    /**
     * The modal is a presentation change. resolveRecordInScope() still
     * decides whether a supervisor may touch a menu item at all, and still
     * answers 404 rather than 403 so an id's existence is not disclosed.
     */
    public function test_a_locked_supervisor_still_cannot_assign_to_another_branchs_item(): void
    {
        $far = $this->freshBranch('Denied');
        $farItem = $this->itemNamed(self::PREFIX . ' Far Item ' . uniqid(), $far->id);
        $option = $this->optionNamed(self::PREFIX . ' Denied Addon ' . uniqid());

        $this->assign($this->supervisorAt(self::MAIN), $farItem, [$option->id])
            ->assertNotFound();

        $this->assertSame(0, $farItem->options()->count());
    }

    /** ...including the shared "All Branches" item, which would 404 too. */
    public function test_a_locked_supervisor_cannot_assign_to_a_shared_item_either(): void
    {
        $sharedItem = $this->itemNamed(self::PREFIX . ' Shared Item ' . uniqid(), null);
        $option = $this->optionNamed(self::PREFIX . ' Shared Addon ' . uniqid());

        $this->assign($this->supervisorAt(self::MAIN), $sharedItem, [$option->id])
            ->assertNotFound();

        $this->assertSame(0, $sharedItem->options()->count());
    }

    /** A supervisor assigning several options to their OWN item still works. */
    public function test_a_locked_supervisor_can_still_assign_several_options_to_their_own_item(): void
    {
        $mine = $this->itemNamed(self::PREFIX . ' Mine ' . uniqid(), self::MAIN);

        $ids = collect(range(1, 4))
            ->map(fn ($i) => $this->optionNamed(self::PREFIX . " Sup {$i} " . uniqid())->id)
            ->all();

        $this->assign($this->supervisorAt(self::MAIN), $mine, $ids)->assertOk();

        $this->assertEqualsCanonicalizing($ids, $mine->options()->pluck('menu_options.id')->all());
    }

    // ══════════════════════════════════════════════════════════════════
    // 7. THE PAGE DID NOT GET MORE EXPENSIVE
    // ══════════════════════════════════════════════════════════════════

    /**
     * The modal reads relations showMenuOptions() already eager-loads, and
     * the optgroups regroup a collection that is already in memory, so the
     * query count must not move with the number of options or inventory rows
     * on the page. Asserted as "same count at more data" rather than as a
     * fixed ceiling, so it measures GROWTH — the thing that would actually
     * hurt — instead of pinning a number an honest change elsewhere would
     * break.
     */
    public function test_the_page_costs_the_same_number_of_queries_with_far_more_data(): void
    {
        $small = $this->measurePageQueries(function () {
            $branch = $this->freshBranch('QSmall');
            $this->inventoryIn($branch->id, 'QS');
            $this->optionNamed(self::PREFIX . ' QSmall ' . uniqid());
        });

        $large = $this->measurePageQueries(function () {
            $branch = $this->freshBranch('QLarge');
            foreach (range(1, 8) as $i) {
                $this->inventoryIn($branch->id, "QL {$i}");
            }
            foreach (range(1, 8) as $i) {
                $option = $this->optionNamed(self::PREFIX . " QLarge {$i} " . uniqid());
                $item = $this->itemNamed(self::PREFIX . " QLItem {$i} " . uniqid());
                $item->options()->attach($option->id);
            }
        });

        $this->assertSame(
            $small,
            $large,
            "the page must not cost more queries as options and inventory grow (small={$small}, large={$large})"
        );
    }

    private function measurePageQueries(callable $seed): int
    {
        $seed();

        $actor = $this->admin();

        // Warm first, so a one-off lookup is not counted as page cost.
        $this->actingAs($actor, 'admin')->get('/admin/menu-options')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($actor, 'admin')->get('/admin/menu-options')->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }
}

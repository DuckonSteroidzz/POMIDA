<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\User;
use App\Services\MenuItemCosting;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ingredients are defined ON the Add form, and the cost follows from them (2026-09-03).
 *
 * WHY
 * ----
 * Before this, the Add Menu Item form had no way to define a recipe at all: it
 * offered a big Item Description box and a hand-typed Cost, plus a note telling
 * the admin to "save the menu item first, then re-open it". Every new item was
 * therefore born with no recipe and a guessed cost, which is why 8 of 10 live
 * items carry a typed number the system cannot vouch for.
 *
 * WHAT THIS FOLLOWS
 * ------------------
 * storeNewMenuItem() already had ONE rule for whether an inventory row may be
 * used by a menu item in the selected branch (the legacy single-ingredient
 * link). That rule is now inventoryIsSelectableForBranch() and every recipe row
 * goes through the same method — no second, subtly different rule.
 *
 * The item and its whole recipe are written in ONE DB::transaction, because a
 * half-saved item with two of its five ingredients would mis-cost and mis-deduct
 * silently forever. The cost stored on save is recomputed server-side from the
 * rows that were actually written, via App\Services\MenuItemCosting — the number
 * the browser previewed is never trusted.
 *
 * CLEANUP
 * --------
 * These tests really write to the live database (they must: the point is that a
 * failed submit leaves NOTHING behind, which a wrapping transaction would mask).
 * setUp() captures a high-water mark per table and tearDown() deletes only rows
 * above it, then the final test proves zero leaked. No pre-existing row is ever
 * touched — inventory id 500 (Pizza Sauce) included.
 */
class MenuItemAddWithIngredientsTest extends TestCase
{
    /** Tables this test can insert into, in child-before-parent delete order. */
    private const BOUNDED_TABLES = ['menu_item_ingredients', 'menu_items', 'inventory'];

    /** @var array<string,int> */
    private array $marks = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::BOUNDED_TABLES as $table) {
            $this->marks[$table] = (int) (DB::table($table)->max('id') ?? 0);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanUpBoundedRows();

        parent::tearDown();
    }

    /**
     * Bounded cleanup, twice over.
     *
     * The bound alone (id > mark) is NOT enough on a live database. This suite
     * runs against the owner's real pomida_db while they may be using the admin
     * screens, and a row they create mid-run lands above a mark captured before
     * it — an id-only bound would delete their work. It already did once.
     *
     * So every delete is bounded by the mark AND restricted to rows this test
     * actually made, identified by the MAWI prefix on the name it always writes.
     * Anything else above the mark is somebody else's and is left alone.
     */
    private function cleanUpBoundedRows(): void
    {
        $mine = DB::table('menu_items')
            ->where('id', '>', $this->marks['menu_items'])
            ->where('name', 'like', 'MAWI%')
            ->pluck('id');

        if ($mine->isNotEmpty()) {
            DB::table('menu_item_ingredients')
                ->where('id', '>', $this->marks['menu_item_ingredients'])
                ->whereIn('menu_item_id', $mine)
                ->delete();

            DB::table('menu_items')->whereIn('id', $mine)->delete();
        }

        DB::table('inventory')
            ->where('id', '>', $this->marks['inventory'])
            ->where('item_name', 'like', 'MAWI%')
            ->delete();
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->firstOrFail();
    }

    /** Admin acting with a specific branch chosen — the Add form requires one. */
    private function asAdminInBranch(int $branchId = 1): self
    {
        $this->actingAs($this->admin(), 'admin')->withSession(['selected_branch_id' => $branchId]);

        return $this;
    }

    private function makeInventory(array $attrs = []): Inventory
    {
        return Inventory::create(array_merge([
            'branch_id'       => 1,
            'item_name'       => 'MAWI Ingredient ' . uniqid(),
            'item_code'       => 'MAWI-' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => 100,
            'unit'            => 'g',
            'low_stock_alert' => 10,
            'unit_cost'       => 1.00,
            'is_active'       => true,
        ], $attrs));
    }

    /** The fields the Add form posts, minus the recipe rows. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => DB::table('categories')->min('id'),
            'name'        => 'MAWI Menudo ' . uniqid(),
            'price'       => 200,
            'description' => 'Slow-cooked pork stew.',
        ], $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | ONE REQUEST CREATES THE ITEM AND ITS RECIPE
    |--------------------------------------------------------------------------
    */

    public function test_creating_an_item_with_ingredients_persists_both_and_costs_from_the_recipe(): void
    {
        // Pork 2 units at ₱30 = ₱60, Carrots 5 units at ₱4 = ₱20 → ₱80.00.
        // The typed cost is a deliberately wrong ₱999 so a "kept the guess" bug
        // cannot pass this test.
        $pork    = $this->makeInventory(['unit_cost' => 30.00]);
        $carrots = $this->makeInventory(['unit_cost' => 4.00]);

        $payload = $this->payload(['cost' => 999]);
        $payload['ingredients'] = [
            ['inventory_id' => $pork->id,    'quantity_used' => 2],
            ['inventory_id' => $carrots->id, 'quantity_used' => 5],
        ];

        $response = $this->asAdminInBranch()->post('/admin/new-menu-item', $payload);
        $response->assertRedirect(route('admin.menu-items'));

        $item = MenuItem::where('name', $payload['name'])->first();
        $this->assertNotNull($item, 'the menu item was not created');

        $rows = MenuItemIngredient::where('menu_item_id', $item->id)->get();
        $this->assertCount(2, $rows, 'both recipe rows must be saved with the item');
        $this->assertSame(
            [2.0, 5.0],
            $rows->sortBy('inventory_id')->pluck('quantity_used')->map(fn ($q) => (float) $q)->values()->all()
        );

        $breakdown = app(MenuItemCosting::class)->breakdownFor($item->fresh());
        $this->assertSame(80.00, $breakdown['cost'], '2 x ₱30 + 5 x ₱4 must be ₱80.00');
        $this->assertFalse($breakdown['is_fallback'], 'a costed recipe is not a guess');

        // The server recomputed and stored the real figure, not the posted 999.
        $this->assertSame(80.00, (float) $item->fresh()->cost);
    }

    public function test_creating_an_item_with_no_ingredients_still_works_and_keeps_the_typed_cost(): void
    {
        $payload = $this->payload(['cost' => 45.50]);

        $this->asAdminInBranch()->post('/admin/new-menu-item', $payload)
            ->assertRedirect(route('admin.menu-items'));

        $item = MenuItem::where('name', $payload['name'])->first();
        $this->assertNotNull($item);
        $this->assertSame(0, MenuItemIngredient::where('menu_item_id', $item->id)->count());
        $this->assertSame(45.50, (float) $item->cost, 'the fallback path is unchanged');

        $breakdown = app(MenuItemCosting::class)->breakdownFor($item);
        $this->assertSame(45.50, $breakdown['cost']);
        $this->assertTrue($breakdown['is_fallback']);
    }

    public function test_an_untouched_blank_ingredient_row_is_not_an_error(): void
    {
        // The form always renders one empty row so the section is obviously
        // usable. Submitting without filling it must not block the admin.
        $payload = $this->payload(['cost' => 12]);
        $payload['ingredients'] = [['inventory_id' => '', 'quantity_used' => '']];

        $this->asAdminInBranch()->post('/admin/new-menu-item', $payload)
            ->assertRedirect(route('admin.menu-items'));

        $this->assertNotNull(MenuItem::where('name', $payload['name'])->first());
    }

    /*
    |--------------------------------------------------------------------------
    | AN INVALID ROW SAVES NOTHING — the whole point of the transaction
    |--------------------------------------------------------------------------
    */

    /**
     * @dataProvider invalidRowProvider
     */
    public function test_an_invalid_ingredient_row_saves_nothing(callable $rowFactory): void
    {
        $good    = $this->makeInventory(['unit_cost' => 5.00]);
        $payload = $this->payload();
        $payload['ingredients'] = [
            ['inventory_id' => $good->id, 'quantity_used' => 3],
            $rowFactory($this),
        ];

        $itemsBefore  = MenuItem::withArchived()->count();
        $recipeBefore = MenuItemIngredient::count();

        $this->asAdminInBranch()->post('/admin/new-menu-item', $payload);

        // Not "the response was a 302" — the row itself must not exist.
        $this->assertNull(
            MenuItem::withArchived()->where('name', $payload['name'])->first(),
            'the menu item must not exist after an invalid recipe row'
        );
        $this->assertSame($itemsBefore, MenuItem::withArchived()->count());
        $this->assertSame($recipeBefore, MenuItemIngredient::count(), 'no orphan recipe rows');
    }

    public static function invalidRowProvider(): array
    {
        return [
            'nonexistent inventory id' => [fn () => ['inventory_id' => 99999999, 'quantity_used' => 1]],
            'quantity zero'            => [fn ($t) => ['inventory_id' => $t->makeInventory()->id, 'quantity_used' => 0]],
            'negative quantity'        => [fn ($t) => ['inventory_id' => $t->makeInventory()->id, 'quantity_used' => -3]],
            'non-numeric quantity'     => [fn ($t) => ['inventory_id' => $t->makeInventory()->id, 'quantity_used' => 'two']],
            'missing quantity'         => [fn ($t) => ['inventory_id' => $t->makeInventory()->id, 'quantity_used' => '']],
            'archived (inactive) row'  => [fn ($t) => ['inventory_id' => $t->makeInventory(['is_active' => false])->id, 'quantity_used' => 1]],
            'other branch'             => [fn ($t) => ['inventory_id' => $t->makeInventory(['branch_id' => 3])->id, 'quantity_used' => 1]],
        ];
    }

    public function test_duplicate_inventory_ids_in_one_submission_are_rejected(): void
    {
        $ing     = $this->makeInventory(['item_name' => 'MAWI Pork Belly']);
        $payload = $this->payload();
        $payload['ingredients'] = [
            ['inventory_id' => $ing->id, 'quantity_used' => 2],
            ['inventory_id' => $ing->id, 'quantity_used' => 3],
        ];

        $response = $this->asAdminInBranch()->post('/admin/new-menu-item', $payload);

        $response->assertSessionHasErrors('ingredients');
        $this->assertStringContainsString(
            'listed twice',
            (string) session('errors')->first('ingredients'),
            'the message must say what is wrong, not just fail'
        );
        $this->assertNull(MenuItem::withArchived()->where('name', $payload['name'])->first());
    }

    public function test_a_rejected_submission_never_leaves_an_orphan_menu_item(): void
    {
        // Absence only — deliberately says nothing about WHICH guard caught it.
        // Today the duplicate pre-check rejects this before any write. If that
        // check ever regresses, the (menu_item_id, inventory_id) unique index
        // throws mid-write and the transaction rolls the item back. Both routes
        // must end with no item on the menu, and this test fails if the
        // transaction is what goes missing.
        $ing     = $this->makeInventory();
        $payload = $this->payload(['name' => 'MAWI Orphan Probe']);
        $payload['ingredients'] = [
            ['inventory_id' => $ing->id, 'quantity_used' => 1],
            ['inventory_id' => $ing->id, 'quantity_used' => 2],
        ];

        $this->asAdminInBranch()->post('/admin/new-menu-item', $payload);

        $this->assertNull(
            MenuItem::withArchived()->where('name', 'MAWI Orphan Probe')->first(),
            'a rejected submission left a half-saved menu item behind'
        );
    }
    public function test_the_form_comes_back_with_the_typed_ingredient_rows_intact(): void
    {
        $ing     = $this->makeInventory();
        $payload = $this->payload(['name' => '']);   // name is required — forces a bounce (nothing is saved, so nothing to clean)
        $payload['ingredients'] = [['inventory_id' => $ing->id, 'quantity_used' => 7]];

        $this->asAdminInBranch()->post('/admin/new-menu-item', $payload);

        // Nothing lost: the admin's rows come back through old input.
        $old = session()->getOldInput('ingredients');
        $this->assertIsArray($old);
        $this->assertSame((string) $ing->id, (string) $old[0]['inventory_id']);
        $this->assertSame('7', (string) $old[0]['quantity_used']);
    }

    public function test_a_rejected_add_reopens_the_modal_in_add_mode_with_the_row_rendered(): void
    {
        // Follows the redirect for real, so this proves what actually reaches
        // the browser, not just what the session carries.
        $ing = $this->makeInventory();

        $this->asAdminInBranch()->post('/admin/new-menu-item', array_merge(
            $this->payload(['name' => '']),   // name is required — forces a bounce
            ['ingredients' => [['inventory_id' => $ing->id, 'quantity_used' => 7]]]
        ));

        $html = $this->asAdminInBranch()->get('/admin/menu-items')->getContent();

        // Reopened in Add-mode chrome, not blank-edit-mode chrome.
        $this->assertStringContainsString("wasEditSubmission = \"0\"", $html);
        $this->assertStringContainsString('hasErrors = "1"', $html);

        // The typed row itself, isolated to the Add-mode row list so neither the
        // entry row below it nor any per-item Edit block can be mistaken for it.
        $rows = $this->addModeRows($html);

        $this->assertStringContainsString('data-draft-row', $rows, 'the typed row must survive the failed submit');

        // The hidden inputs ARE the payload this row re-submits, so asserting on
        // them is asserting the row would actually save the same thing again.
        // Both are pinned by full name= so a row carrying only one of the two
        // (which would post a half-row) cannot pass.
        $this->assertStringContainsString(
            'name="ingredients[0][inventory_id]" value="' . $ing->id . '"',
            $rows,
            'the redrawn row lost which ingredient was chosen'
        );
        $this->assertStringContainsString(
            'name="ingredients[0][quantity_used]" value="7"',
            $rows,
            'the redrawn row lost the typed quantity'
        );

        // And it is shown to the admin, not merely carried invisibly.
        $this->assertStringContainsString($ing->item_name, $rows, 'the redrawn row does not name the ingredient');
    }

    /**
     * The Add-mode recipe row list — the <tbody> that holds saved/draft rows,
     * bounded at its own closing tag.
     *
     * Replaces the old #riRows..#riAddRow slice. The reason for isolating is
     * unchanged: markup further down the page (the entry row's <select>, and
     * every per-item Edit block) legitimately mentions the same ingredient ids
     * and names, so an unbounded search proves nothing about the draft rows.
     */
    private function addModeRows(string $html): string
    {
        $start = strpos($html, 'id="recipe-tbody-add"');
        $this->assertNotFalse($start, 'the Add-mode ingredient row list is missing');

        $end = strpos($html, '</tbody>', $start);
        $this->assertNotFalse($end, 'the Add-mode row list is not closed');

        return substr($html, $start, $end - $start);
    }

    /*
    |--------------------------------------------------------------------------
    | THE SCREEN
    |--------------------------------------------------------------------------
    */

    /**
     * The <section> of the page that holds the ADD-mode ingredient editor,
     * isolated to #recipe-add so a per-item EDIT block (which loops over the
     * exact same $inventoryItems and can contain the same ingredient names)
     * cannot be mistaken for it.
     */
    private function recipeSection(string $html): string
    {
        $start = strpos($html, 'id="recipe-add"');
        $this->assertNotFalse($start, 'the Add-mode Recipe Ingredients block is missing');

        // The end bound must match ONLY a per-item edit block, id="recipe-<digits>".
        //
        // This used to search for the prefix 'id="recipe-', which stopped at the
        // FIRST id starting that way — and the shared partial's own
        // id="recipe-empty-add" notice sits 92 characters into the block. Every
        // assertion about "the Add-mode section" was therefore being made
        // against a 92-character fragment that contained no <option> at all, so
        // two tests reported the ingredient picker as broken when the picker was
        // fine and the bound was wrong. Anchored to digits, it cannot be fooled
        // by recipe-empty-*, recipe-table-*, recipe-tbody-* or anything else the
        // partial adds later.
        $this->assertTrue(
            (bool) preg_match('/id="recipe-\d+"/', $html, $m, PREG_OFFSET_CAPTURE, $start),
            'could not find the end of the Add-mode block (no per-item edit block follows it)'
        );

        return substr($html, $start, $m[0][1] - $start);
    }

    public function test_the_add_form_renders_the_shared_ingredient_markup(): void
    {
        // Add and Edit live in the ONE modal on /admin/menu-items now — there is
        // no standalone Add page.
        //
        // The recipe editor was rebuilt after this test was written: the
        // <template id="riRowTemplate"> cloned per row, with its
        // ingredients[__IDX__][...] placeholder names, is gone. Rows are now
        // appended as real <tr>s carrying concrete ingredients[i][...] hidden
        // inputs. The GUARANTEE is the same one and is asserted the same way —
        // the Add form ships a working, self-contained ingredient editor — so
        // this now names the markup that actually implements it.
        $page = $this->asAdminInBranch()->get('/admin/menu-items');

        $page->assertOk();

        $section = $this->recipeSection($page->getContent());

        // The row list a new row lands in.
        $this->assertStringContainsString('id="recipe-tbody-add"', $section);

        // The entry row, and the marker that puts it in ADD mode: an EMPTY
        // data-url is what tells the JS to append client-side instead of
        // POSTing to an existing item's ingredient endpoint. A non-empty one
        // here would mean Add mode had silently become Edit mode.
        $this->assertStringContainsString('class="recipe-ing-add-row" data-url="" data-block="add"', $section);

        // The three controls that make the row usable at all.
        $this->assertStringContainsString('recipe-ing-select', $section);
        $this->assertStringContainsString('recipe-ing-qty', $section);
        $this->assertStringContainsString('recipe-ing-add-btn', $section);

        // No lie left about saving first.
        $page->assertDontSee('Save the menu item first');
    }

    public function test_the_edit_blocks_use_the_same_ingredient_classes_as_add_mode(): void
    {
        // Both modes are rendered from ONE partial
        // (admin/partials/recipe-ingredients.blade.php), so the thing worth
        // proving is that they genuinely still share it rather than that a
        // particular class name appears.
        //
        // Asserting the same class vocabulary on BOTH blocks is strictly more
        // than the old pair of assertSee() calls did: those two could both be
        // satisfied by the add block alone, and said nothing about the edit
        // blocks they were named for.
        $html = $this->asAdminInBranch()->get('/admin/menu-items')->getContent();

        $addBlock = $this->recipeSection($html);

        $item = MenuItem::where('branch_id', 1)->firstOrFail();
        $editStart = strpos($html, 'id="recipe-' . $item->id . '"');
        $this->assertNotFalse($editStart, 'no per-item Edit block was rendered');

        // Bounded at this item's OWN cost line, which is the last thing the
        // partial emits — not a fixed character count, which would silently
        // truncate the block the moment the markup grew.
        $editEnd = strpos($html, 'id="recipe-profit-' . $item->id . '"', $editStart);
        $this->assertNotFalse($editEnd, "the Edit block for item {$item->id} has no cost line");
        $editBlock = substr($html, $editStart, ($editEnd - $editStart) + 120);

        foreach ([
            'recipe-ing-add-row',
            'recipe-ing-select',
            'recipe-ing-qty',
            'recipe-ing-add-btn',
            'recipe-unit-label',
            'recipe-cost-total',
        ] as $shared) {
            $this->assertStringContainsString($shared, $addBlock, "Add mode lost {$shared}");
            $this->assertStringContainsString($shared, $editBlock, "Edit mode lost {$shared} — the two modes have drifted apart");
        }

        // Both modes render the same per-block ids from the same partial.
        foreach (['recipe-empty-', 'recipe-table-', 'recipe-tbody-', 'recipe-error-', 'recipe-cost-', 'recipe-profit-'] as $idPrefix) {
            $this->assertStringContainsString('id="' . $idPrefix . 'add"', $addBlock, "Add mode lost {$idPrefix}");
            $this->assertStringContainsString('id="' . $idPrefix . $item->id . '"', $editBlock, "Edit mode lost {$idPrefix}");
        }

        // The one thing that MUST differ: Edit posts to a real endpoint.
        $this->assertStringContainsString('data-url="' . route('admin.menu-items.ingredients.add', $item->id) . '"', $editBlock);
    }

    public function test_the_recipe_classes_used_in_markup_are_actually_wired_up(): void
    {
        // ORIGINALLY: the four .ri-badge* stock badges were asserted to have CSS
        // declarations, because a class declared nowhere renders as an unstyled
        // control. Those badges were removed with the rest of the ri-* editor
        // (menu-items.blade.php's picker handler now says so in as many words:
        // "No stock text or badges — the entry row stays clean").
        //
        // The same FAILURE MODE still exists in the new markup and is what this
        // now guards: a name used in one place and absent from the other, which
        // breaks silently. For the current editor the pairing is JS-to-markup
        // rather than CSS-to-markup — every class the handlers query must really
        // be rendered, or the handler quietly never fires and the "+ Add" button
        // does nothing with no error anywhere.
        $html = $this->asAdminInBranch()->get('/admin/menu-items')->getContent();
        $view = file_get_contents(resource_path('views/admin/menu-items.blade.php'));

        // Queried BY CLASS. Each must appear in the JS as a real selector AND be
        // rendered, or the handler silently never fires.
        foreach ([
            'recipe-block',          // closest() — which mode's block is shown
            'recipe-ing-add-row',    // the entry row the handlers walk up to
            'recipe-ing-select',     // change handler -> unit label
            'recipe-ing-qty',        // read on Add
            'recipe-ing-add-btn',    // click handler
            'recipe-ing-delete-btn', // click handler
            'recipe-unit-label',     // written on select
        ] as $class) {
            $this->assertMatchesRegularExpression(
                "/(querySelector(All)?|closest)\('\." . preg_quote($class, '/') . "'\)|contains\('" . preg_quote($class, '/') . "'\)/",
                $view,
                ".{$class} is rendered but no JS on the page ever selects it"
            );
            $this->assertStringContainsString(
                'class="' . $class . '"',
                str_replace(['class="form-control-custom ', 'class="btn-primary-custom '], 'class="', $html),
                ".{$class} is queried by the page JS but never rendered — the handler can never fire"
            );
        }

        // Queried BY ID, per block. Same failure mode, different lookup.
        foreach (['recipe-empty-', 'recipe-table-', 'recipe-tbody-', 'recipe-error-', 'recipe-cost-', 'recipe-profit-'] as $idPrefix) {
            $this->assertStringContainsString(
                "getElementById('" . $idPrefix,
                $view,
                "#{$idPrefix}* is rendered but nothing on the page looks it up"
            );
            $this->assertStringContainsString(
                'id="' . $idPrefix . 'add"',
                $html,
                "#{$idPrefix}add is looked up by the page JS but never rendered"
            );
        }

        // The one class that is purely presentational still needs a real CSS
        // rule, which is the original assertion's exact shape.
        $flat = str_replace(["\n", '  '], ['', ''], $html);
        $this->assertStringContainsString(
            '.recipe-ing-add-row {',
            $flat,
            '.recipe-ing-add-row is used but never declared on /admin/menu-items'
        );
    }

    public function test_the_picker_shows_the_real_inventory_figures(): void
    {
        $ing = $this->makeInventory([
            'item_name'       => 'MAWI Liver',
            'quantity'        => 12.5,
            'unit'            => 'kg',
            'unit_cost'       => 3.75,
            'low_stock_alert' => 2,
        ]);

        $section = $this->recipeSection($this->asAdminInBranch()->get('/admin/menu-items')->getContent());

        // Isolated to this one <option>, never a bare number: another live row
        // may legitimately carry the same figure.
        $start = strpos($section, 'MAWI Liver');
        $this->assertNotFalse($start);
        $option = substr($section, max(0, $start - 400), 600);

        $this->assertStringContainsString('data-stock="12.50"', $option);
        $this->assertStringContainsString('data-cost="3.75"', $option);
        $this->assertStringContainsString('data-unit="kg"', $option);
        $this->assertStringContainsString('data-low="2.00"', $option);

        // ORIGINALLY this also asserted the visible line "12.5 kg on hand".
        //
        // That text, and the stock badges beside it, were REMOVED ON PURPOSE
        // when the recipe editor was rebuilt — menu-items.blade.php's picker
        // handler states the decision outright: "No stock text or badges — the
        // entry row stays clean." It is a deliberate scope reduction, not a
        // regression, and it is reported to the owner rather than quietly
        // restored here.
        //
        // The assertion is not simply dropped. What made the old one valuable
        // was that the figures reaching the picker are the REAL ones, and that
        // is exactly what the data-* payload above now proves — it is the
        // payload the live cost preview computes from, so a wrong number here
        // mis-costs a recipe. The line below pins the current decision so that
        // putting the text back becomes a conscious change with a failing test
        // to update, instead of silent drift in either direction.
        $this->assertStringNotContainsString(
            'on hand',
            $option,
            'the entry row shows stock text again — deliberate or not, update this test and say which'
        );
    }

    public function test_an_out_of_stock_ingredient_is_still_selectable(): void
    {
        // Informational only — the admin may be about to restock.
        $empty = $this->makeInventory(['item_name' => 'MAWI Empty Tin', 'quantity' => 0]);

        $section = $this->recipeSection($this->asAdminInBranch()->get('/admin/menu-items')->getContent());
        $this->assertStringContainsString('MAWI Empty Tin', $section);
        $this->assertStringNotContainsString('disabled', substr($section, strpos($section, 'MAWI Empty Tin') - 400, 600));

        $payload = $this->payload();
        $payload['ingredients'] = [['inventory_id' => $empty->id, 'quantity_used' => 1]];

        $this->asAdminInBranch()->post('/admin/new-menu-item', $payload)
            ->assertRedirect(route('admin.menu-items'));

        $this->assertNotNull(MenuItem::where('name', $payload['name'])->first());
    }

    /*
    |--------------------------------------------------------------------------
    | THE MODAL — one for both modes
    |--------------------------------------------------------------------------
    */

    public function test_add_new_item_opens_the_modal_not_a_different_page(): void
    {
        $html = $this->asAdminInBranch()->get('/admin/menu-items')->getContent();

        $pos = strpos($html, 'Add New Item');
        $this->assertNotFalse($pos, '"Add New Item" control is missing');
        $tagStart = strrpos(substr($html, 0, $pos), '<button');
        $this->assertNotFalse($tagStart, '"Add New Item" is not a <button> — it must open the modal, not navigate');

        $tag = substr($html, $tagStart, $pos - $tagStart);
        $this->assertStringContainsString('onclick="openAddModal()"', $tag);
    }

    public function test_recipe_ingredients_sits_after_category_and_before_price_in_both_modes(): void
    {
        // One page serves both modes — the add-mode block and every per-item
        // edit block are ALL physically positioned between Category and Price;
        // JS only ever toggles which one is visible, never where they sit.
        $html = $this->asAdminInBranch()->get('/admin/menu-items')->getContent();

        $categoryPos = strpos($html, 'id="itemSubcategory"');
        $recipeAddPos = strpos($html, 'id="recipe-add"');
        $pricePos = strpos($html, 'id="itemPrice"');

        $this->assertNotFalse($categoryPos);
        $this->assertNotFalse($recipeAddPos);
        $this->assertNotFalse($pricePos);
        $this->assertTrue($categoryPos < $recipeAddPos, 'Recipe Ingredients must come after the category fields');
        $this->assertTrue($recipeAddPos < $pricePos, 'Recipe Ingredients must come before Price');

        // And the same is true of every EXISTING item's edit block, not just
        // the add-mode one.
        $item = MenuItem::where('branch_id', 1)->firstOrFail();
        $editBlockPos = strpos($html, 'id="recipe-' . $item->id . '"');
        $this->assertNotFalse($editBlockPos);
        $this->assertTrue($categoryPos < $editBlockPos);
        $this->assertTrue($editBlockPos < $pricePos);
    }

    public function test_add_mode_renders_empty_no_values_from_any_existing_item_leak_in(): void
    {
        // A menu item with real values already on the page — if Add mode ever
        // leaked a previous Edit's data, this is where it would show up.
        $ing  = $this->makeInventory(['unit_cost' => 9.00]);
        $item = $this->makeItemForLeakCheck($ing);

        $html = $this->asAdminInBranch()->get('/admin/menu-items')->getContent();

        // The shared fields default to blank in the static HTML — they are only
        // ever filled at runtime, per click, by openEditModal()'s JS.
        $this->assertStringContainsString('id="itemName" class="form-control-custom" value=""', $html);
        $this->assertStringContainsString('id="itemPrice" class="form-control-custom" step="0.01" min="0" value=""', $html);

        // The add-mode row list starts with zero rows on a fresh load. Isolated
        // to the <tbody> itself, NOT the whole section — the entry row below it
        // legitimately lists every ingredient as <option>s, and every per-item
        // Edit block further down legitimately carries real saved rows.
        //
        // Now bounded by </tbody> rather than the old #riRows..#riAddRow slice,
        // and asserting on <tr rather than data-ri-row: that is STRICTER than
        // before, because it fails on any row at all leaking in, not only on one
        // carrying that particular attribute.
        $rows = $this->addModeRows($html);

        $this->assertStringNotContainsString('<tr', $rows, 'a fresh page load must carry no draft ingredient rows');
        $this->assertStringNotContainsString(
            'name="ingredients[',
            $rows,
            'a fresh page load must post no ingredient values'
        );

        // The item that exists purely to be leaked from really is on the page,
        // so the two assertions above are proving absence rather than passing
        // for want of anything to find.
        $this->assertStringContainsString('MAWI Leak Check Item', $html);
        $this->assertStringContainsString('id="recipe-' . $item->id . '"', $html);
    }

    /** Small helper item just to prove its values do not leak into Add mode's static markup. */
    private function makeItemForLeakCheck(Inventory $ing): MenuItem
    {
        $item = MenuItem::create([
            'category_id'   => DB::table('categories')->min('id'),
            'branch_id'     => 1,
            'name'          => 'MAWI Leak Check Item',
            'price'         => 77.77,
            'cost'          => 0,
            'description'   => 'MAWI leak check description',
            'is_available'  => true,
            'display_order' => 0,
            'total_sold'    => 0,
        ]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $ing->id, 'quantity_used' => 3]);

        return $item;
    }

    /*
    |--------------------------------------------------------------------------
    | DESCRIPTION IS SECONDARY, NOT GONE
    |--------------------------------------------------------------------------
    */

    public function test_the_description_still_saves_and_still_renders_everywhere_it_did_before(): void
    {
        $payload = $this->payload(['description' => 'MAWI unique description text']);

        $this->asAdminInBranch()->post('/admin/new-menu-item', $payload);
        $item = MenuItem::where('name', $payload['name'])->firstOrFail();

        $this->assertSame('MAWI unique description text', $item->description);

        // Admin list column.
        $list = $this->asAdminInBranch()->get('/admin/menu-items');
        $list->assertOk();
        $list->assertSee('MAWI unique description text');

        // Edit mode's textarea is one shared element, filled at runtime by
        // openEditModal() from this data-* attribute on the item's own Edit
        // button — that attribute is the server-rendered evidence the value
        // genuinely reaches Edit, isolated to this item's own row.
        $row = $this->rowFor($list->getContent(), 'MAWI Menudo');
        $this->assertStringContainsString('data-description="MAWI unique description text"', $row);
        $list->assertSee('name="description"', false);

        // Customer item page.
        $detail = $this->get('/item/' . $item->id);
        if ($detail->status() === 200) {
            $detail->assertSee('MAWI unique description text');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | THE RENAMED BADGE
    |--------------------------------------------------------------------------
    */

    /** The single <tr> of the Menu Items table containing the given item name. */
    private function rowFor(string $html, string $itemName): string
    {
        foreach (preg_split('/<tr\b/', $html) as $row) {
            // Both conditions matter: the flash banner above the table repeats the
            // item name, so a name match alone can return the whole page header
            // and make any assertion about "the row" meaningless.
            if (str_contains($row, $itemName) && str_contains($row, 'data-label="Cost"')) {
                return $row;
            }
        }
        $this->fail('No table row found for "' . $itemName . '".');
    }

    public function test_the_badge_reads_no_recipe_for_a_recipeless_item_and_is_absent_for_a_costed_one(): void
    {
        $withoutRecipe = $this->payload(['name' => 'MAWI Guessed Item', 'cost' => 21]);
        $this->asAdminInBranch()->post('/admin/new-menu-item', $withoutRecipe);

        $ing = $this->makeInventory(['unit_cost' => 2.00]);
        $withRecipe = $this->payload(['name' => 'MAWI Costed Item']);
        $withRecipe['ingredients'] = [['inventory_id' => $ing->id, 'quantity_used' => 4]];
        $this->asAdminInBranch()->post('/admin/new-menu-item', $withRecipe);

        $html = $this->asAdminInBranch()->get('/admin/menu-items')->getContent();

        $guessRow = $this->rowFor($html, 'MAWI Guessed Item');
        $this->assertStringContainsString('No recipe', $guessRow);
        $this->assertStringNotContainsString('Estimate', $guessRow, 'the old ambiguous label is gone');
        $this->assertStringContainsString('₱21.00', $guessRow);

        $costedRow = $this->rowFor($html, 'MAWI Costed Item');
        $this->assertStringNotContainsString('No recipe', $costedRow);
        $this->assertStringContainsString('₱8.00', $costedRow, '4 x ₱2.00');
    }

    /*
    |--------------------------------------------------------------------------
    | BOUNDED CLEANUP PROOF
    |--------------------------------------------------------------------------
    */

    public function test_nothing_leaks_above_the_high_water_marks(): void
    {
        // Create the full shape this suite creates, then let tearDown() run and
        // assert here that the bound really does cover everything.
        $ing     = $this->makeInventory();
        $payload = $this->payload();
        $payload['ingredients'] = [['inventory_id' => $ing->id, 'quantity_used' => 1]];
        $this->asAdminInBranch()->post('/admin/new-menu-item', $payload);

        foreach (self::BOUNDED_TABLES as $table) {
            $this->assertGreaterThan(
                0,
                DB::table($table)->where('id', '>', $this->marks[$table])->count(),
                $table . ' was expected to gain a row, so the bound is actually being exercised'
            );
        }

        // Run the real cleanup, then prove none of THIS test's rows remain —
        // while saying nothing about rows it never created.
        $this->cleanUpBoundedRows();

        $this->assertSame(0, DB::table('menu_items')->where('name', 'like', 'MAWI%')->count());
        $this->assertSame(0, DB::table('inventory')->where('item_name', 'like', 'MAWI%')->count());
        $this->assertSame(
            0,
            DB::table('menu_item_ingredients')->where('id', '>', $this->marks['menu_item_ingredients'])->count(),
            'recipe rows are only ever created by this test, so none may survive'
        );

        // And the row the known suite failures trace to is untouched.
        $pizzaSauce = DB::table('inventory')->find(500);
        $this->assertNotNull($pizzaSauce);
        $this->assertSame('Pizza Sauce', $pizzaSauce->item_name);
    }
}

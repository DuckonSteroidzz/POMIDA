<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * VERIFICATION, not a fix — 2026-09-02. Whether "Save Options" is safe when
 * the Options list is paginated/filtered so not every assigned option is on
 * screen.
 *
 * THE PERSISTENCE CALL
 * ---------------------
 * AdminController::assignOptions(): a single line,
 * `$menuItem->options()->sync($optionIds)`, where $optionIds is exactly
 * request()->input('option_ids', []) — nothing else. sync() is a full
 * replace: any id not present in that array gets DETACHED, with no concept
 * of "not on screen" versus "deliberately unchecked". In isolation, sync()
 * given a PARTIAL id list is NOT safe — see
 * test_sync_itself_would_detach_an_id_missing_from_a_partial_payload() below,
 * which proves that half of the claim directly rather than asserting it from
 * documentation.
 *
 * WHAT THE FORM ACTUALLY SUBMITS
 * -------------------------------
 * saveAssignment() in menu-options.blade.php builds option_ids from
 * `document.querySelectorAll('.option-check:checked')` — the WHOLE
 * document, not a selector scoped to any visible container or page slice.
 *
 * That matters because of how pagination and filtering are implemented on
 * this specific list: showMenuOptions() renders EVERY MenuOption server-side
 * on every load — `MenuOption::with(...)->orderBy('name')->get()`, no
 * ->paginate() anywhere — and the client-side pager/search/price-filter code
 * (state.option.page/size/search/price, applied via one shared
 * `.pchy-row-hidden { display:none!important }` class toggle) only ever adds
 * or removes that CSS class on rows that are already in the DOM. No option
 * row, and therefore no .option-check checkbox, is ever removed from the
 * document by pagination or filtering — confirmed by grep: nothing in this
 * file replaces #optionsList's innerHTML or otherwise regenerates the row
 * markup after first render.
 *
 * openAssignPanel() sets every checkbox's `.checked` from the menu item's
 * REAL assigned-option ids the moment the assign panel opens — including
 * ones currently hidden by the pager/filter — and nothing un-checks a
 * hidden checkbox except the admin explicitly clicking it (which a hidden
 * element cannot receive). So an assigned option that is off-screen when
 * "Save Options" is pressed is still `checked` in the DOM, and
 * `querySelectorAll('.option-check:checked')` — being document-wide —
 * still finds it and includes its id in the submitted payload.
 *
 * ANSWER TO "DOES B STAY ATTACHED?"
 * -----------------------------------
 * B stays attached. Not because sync() is inherently safe against a partial
 * list (it demonstrably is not), but because this list has no server-side
 * pagination at all — pagination and filtering here are decorative CSS over
 * a fully-rendered set, so the submitted id list is always complete
 * regardless of what the admin can currently see.
 *
 * FORWARD-LOOKING CAUTION, not something to fix now: this safety is a
 * property of "the whole list is always in the DOM", not of the persistence
 * call. If this list is ever switched to real server-side pagination (e.g.
 * once the option count grows enough that rendering all of them becomes a
 * real cost), sync($optionIds) would immediately become unsafe again exactly
 * as test_sync_itself_would_detach_an_id_missing_from_a_partial_payload()
 * demonstrates, and the fix this task's brief describes (submit the
 * rendered option ids alongside the checked ones) would become necessary at
 * that point.
 *
 * No code changed in this pass. These tests exist so a future change to
 * either half — the controller's sync() call, or the client's
 * document-wide querySelectorAll — cannot silently reintroduce data loss.
 */
class OptionAssignmentPaginationSafetyTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->first();
    }

    private function freshOption(string $name): MenuOption
    {
        return MenuOption::create([
            'name' => $name,
            'additional_price' => 5,
            'is_active' => true,
            'display_order' => 0,
        ]);
    }

    private function freshItem(string $name): MenuItem
    {
        return MenuItem::create([
            'category_id'   => Category::value('id'),
            'branch_id'     => 1,
            'name'          => $name,
            'price'         => 100,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    private function pivotRowsFor(MenuItem $item): array
    {
        return DB::table('menu_item_options')
            ->where('menu_item_id', $item->id)
            ->orderBy('menu_option_id')
            ->pluck('menu_option_id')
            ->all();
    }

    // ══════════ the controller method and its exact call ══════════

    public function test_the_controller_uses_a_plain_sync_of_the_submitted_ids(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Admin/AdminController.php'));

        $this->assertStringContainsString(
            '$menuItem->options()->sync($optionIds);',
            $source,
            'assignOptions() no longer calls sync() the way this investigation is based on — re-verify before trusting this file\'s conclusions'
        );
    }

    /**
     * sync() ITSELF, given a partial id list, is not safe — proven directly
     * rather than asserted from documentation. This is the half of the
     * mechanism that makes the front-end's "always submit every checked box,
     * document-wide" behaviour load-bearing rather than incidental.
     */
    public function test_sync_itself_would_detach_an_id_missing_from_a_partial_payload(): void
    {
        $item = $this->freshItem('Sync Behaviour Probe');
        $a = $this->freshOption('Probe A');
        $b = $this->freshOption('Probe B');

        $item->options()->sync([$a->id, $b->id]);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->pivotRowsFor($item));

        // A partial list — exactly what a "only send the visible page" bug
        // would produce.
        $item->options()->sync([$a->id]);

        $this->assertSame(
            [$a->id],
            $this->pivotRowsFor($item),
            'sync() should have detached B when B was missing from the payload — '
            . 'if this assertion fails, sync() has changed behaviour and the whole '
            . 'safety analysis in this file needs redoing'
        );
    }

    // ══════════ what the real front-end actually sends ══════════

    /**
     * The mechanism that makes the system safe today: saveAssignment() reads
     * checked state from the WHOLE DOCUMENT, not a container scoped to the
     * visible page or the search/filter result. A selector scoped to, say,
     * '#optionsList .option-check:checked' would still find hidden-but-
     * present checkboxes (pchy-row-hidden is just display:none, not DOM
     * removal), so this specifically pins that it queries `document`, the
     * broadest possible scope, leaving no ambiguity about which container is
     * meant.
     */
    public function test_the_client_reads_checked_state_from_the_whole_document(): void
    {
        $source = file_get_contents(resource_path('views/admin/menu-options.blade.php'));

        $this->assertStringContainsString(
            "document\n                .querySelectorAll(\n                    '.option-check'\n                )",
            $source,
            'saveAssignment() should still query the checked options from `document`, not a scoped container — '
            . 'if this changed, re-verify it still covers options hidden by the pager/filter'
        );
    }

    /**
     * The other half of the mechanism: nothing regenerates the options list
     * markup after first render. If a future change introduced something
     * like `optionsList.innerHTML = ...` for search or pagination, freshly
     * built checkboxes would lose whatever `checked` state the admin had not
     * yet saved, and — more importantly for THIS investigation — a hidden
     * page's checkboxes could stop existing in the DOM at all.
     */
    public function test_the_options_list_markup_is_never_regenerated_by_filtering_or_paging(): void
    {
        $source = file_get_contents(resource_path('views/admin/menu-options.blade.php'));

        $this->assertStringNotContainsString('optionsList.innerHTML', $source);
        $this->assertStringNotContainsString("getElementById('optionsList').innerHTML", $source);
    }

    // ══════════ required scenario 1: off-screen option survives ══════════

    /**
     * THE TASK'S CORE SCENARIO. A menu item has two options assigned. Only
     * one is "present in the submitted set" (i.e. what a real Save Options
     * click would send per the mechanism proven above: every checked box,
     * document-wide) — modelled here by sending exactly the payload the real
     * front-end constructs when B is off-screen and untouched: A's id only
     * is NOT what gets sent (A is unchecked, so it drops out), but B's id
     * DOES get sent (B is still checked, just hidden). The realistic payload
     * is therefore [B], not [A] or [] — see the class docblock for why.
     */
    public function test_unchecking_an_onscreen_option_detaches_only_that_one_leaving_the_offscreen_one_attached(): void
    {
        $item = $this->freshItem('Two Assigned Item');
        $a = $this->freshOption('Onscreen Option A');
        $b = $this->freshOption('Offscreen Option B');

        $item->options()->sync([$a->id, $b->id]);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->pivotRowsFor($item));

        // A is visible and gets unchecked by the admin. B is off-screen
        // (paginated/filtered away) and was never interacted with — its
        // checkbox is still `checked` in the DOM, so the real front-end
        // still includes it. This is the actual payload saveAssignment()
        // sends in exactly the scenario the brief describes.
        $response = $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, [
                'option_ids' => [(string) $b->id],
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertSame(
            [$b->id],
            $this->pivotRowsFor($item),
            'A should be detached (unchecked on screen) and B should remain attached '
            . '(never on screen, never unchecked)'
        );
    }

    // ══════════ required scenario 2: normal check/uncheck regression ══════════

    public function test_normal_check_and_uncheck_with_everything_visible_still_works(): void
    {
        $item = $this->freshItem('Normal Flow Item');
        $a = $this->freshOption('Normal A');
        $b = $this->freshOption('Normal B');
        $c = $this->freshOption('Normal C');

        // Start with nothing assigned, check A and B, save.
        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, [
                'option_ids' => [(string) $a->id, (string) $b->id],
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->pivotRowsFor($item));

        // Uncheck A, check C — everything visible, everything intentional.
        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, [
                'option_ids' => [(string) $b->id, (string) $c->id],
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$b->id, $c->id],
            $this->pivotRowsFor($item),
            'A should be detached, B should stay, C should be newly attached'
        );

        // Uncheck everything.
        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, ['option_ids' => []])
            ->assertOk();

        $this->assertSame([], $this->pivotRowsFor($item));
    }

    // ══════════ required scenario 3: item isolation ══════════

    public function test_detaching_from_one_menu_item_does_not_affect_the_same_options_assignment_elsewhere(): void
    {
        $itemA = $this->freshItem('Isolation Item A');
        $itemB = $this->freshItem('Isolation Item B');
        $shared = $this->freshOption('Shared Option');

        $itemA->options()->sync([$shared->id]);
        $itemB->options()->sync([$shared->id]);

        $this->assertSame([$shared->id], $this->pivotRowsFor($itemA));
        $this->assertSame([$shared->id], $this->pivotRowsFor($itemB));

        // Detach it from A only.
        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/menu-options/assign/' . $itemA->id, ['option_ids' => []])
            ->assertOk();

        $this->assertSame(
            [],
            $this->pivotRowsFor($itemA),
            'the shared option should be detached from item A'
        );
        $this->assertSame(
            [$shared->id],
            $this->pivotRowsFor($itemB),
            "item B's assignment of the same option must be completely unaffected"
        );
    }

    // ══════════ nonexistent option ids (fixed 2026-09-02) ══════════

    /**
     * Flagged as an incidental finding by the previous pass, fixed in this
     * one: an option id that does not exist used to reach the pivot INSERT,
     * where menu_item_options' foreign key rejected it — an uncaught
     * QueryException rendered as a raw 500.
     *
     * Reachable without crafting anything: this options list renders once and
     * filters client-side, so a tab left open while another admin deletes an
     * option still holds that id in its DOM and submits it on the next save.
     */
    public function test_a_nonexistent_option_id_is_refused_cleanly_without_a_500(): void
    {
        $item = $this->freshItem('Ghost Id Item');
        $real = $this->freshOption('Real Option');

        $item->options()->sync([$real->id]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, [
                'option_ids' => [(string) $real->id, '999999999'],
            ]);

        // A clean, readable refusal — not an exception, not a 500.
        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $this->assertStringContainsString(
            'no longer exist',
            $response->json('message'),
            'the refusal must tell the admin what actually went wrong'
        );
    }

    /**
     * NO PARTIAL WRITE. sync() is a single full replace, so letting it start
     * with a bad id in the list would detach the item's real assignments
     * before the foreign key stopped it. The validation runs first precisely
     * so a mixed valid/invalid payload changes nothing at all.
     */
    public function test_one_valid_and_one_invalid_id_changes_nothing(): void
    {
        $item = $this->freshItem('No Partial Write Item');
        $a = $this->freshOption('Keep A');
        $b = $this->freshOption('Keep B');

        $item->options()->sync([$a->id, $b->id]);
        $before = $this->pivotRowsFor($item);

        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, [
                // Would have detached B and attached the ghost.
                'option_ids' => [(string) $a->id, '999999999'],
            ])
            ->assertStatus(422);

        $this->assertSame(
            $before,
            $this->pivotRowsFor($item),
            'a refused save must leave the existing assignments byte-for-byte unchanged'
        );
    }

    public function test_only_valid_ids_still_save_exactly_as_before(): void
    {
        // The normal path must be completely unaffected by the new check.
        $item = $this->freshItem('Still Works Item');
        $a = $this->freshOption('Valid A');
        $b = $this->freshOption('Valid B');

        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, [
                'option_ids' => [(string) $a->id, (string) $b->id],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->pivotRowsFor($item));

        // And clearing everything still works — an empty list is valid, not
        // "nothing to validate, bail out".
        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/menu-options/assign/' . $item->id, ['option_ids' => []])
            ->assertOk();

        $this->assertSame([], $this->pivotRowsFor($item));
    }

    /**
     * The refusal has to actually reach the admin. saveAssignment() used to
     * do a bare `return` on a non-success response, so a rejected save looked
     * exactly like one that had not finished yet.
     */
    public function test_the_client_reports_a_failed_save_instead_of_swallowing_it(): void
    {
        $source = file_get_contents(resource_path('views/admin/menu-options.blade.php'));

        $this->assertStringContainsString(
            'showAssignError(',
            $source,
            'a failed save must surface a message to the admin'
        );

        // The silent bare-return must be gone: the failure branch has to do
        // something before returning.
        $this->assertDoesNotMatchRegularExpression(
            '/if\s*\(!data\.success\)\s*\{\s*return;\s*\}/',
            $source,
            'the failure branch is silently returning again'
        );
    }
}

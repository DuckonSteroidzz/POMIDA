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
 * Branch-scoped option-ingredient mapping — the UI-clarity pass that followed
 * the investigation which proved option-level branch mapping and order-time
 * deduction CORRECT (OptionBranchAwareDeductionTest / OptionBranchEndToEnd-
 * DeductionTest pin that). The "Branch 2 add-on had no effect" report was
 * missing setup — no Branch 2 inventory, no gravy link, no recipe — that the
 * page gave no hint of. So this covers what the page now says:
 *
 *   1. every saved option-ingredient row names the branch it deducts from, and
 *      addOptionIngredient()'s JSON carries branch_id / branch_name so the row
 *      the page appends says the same;
 *   2. the per-branch Mapped/Unmapped badges carry the hooks the live update
 *      reads (they are re-derived after every add/remove, no reload);
 *   3. a branch with NO active inventory gets an "add inventory first" hint;
 *   4. a branch-locked supervisor is not offered a remove button on another
 *      branch's link (cosmetic — DeleteOptionIngredientCrossBranchTest pins the
 *      server refusal, and is re-run unchanged alongside this).
 *
 * Every row here carries the OIBL prefix and runs in DatabaseTransactions
 * against pomida_db_testing, so nothing survives the run.
 */
class OptionIngredientBranchLabelTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'OIBL';
    private const HOME_BRANCH = 1;

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

    private function branch(string $label): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' ' . $label . ' ' . uniqid(),
            'code'      => 'OIB' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function inventoryIn(?int $branchId, string $name = 'Gravy', bool $active = true): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'item_code'       => 'OIBI-' . strtoupper(substr(uniqid(), -9)),
            'category'        => self::PREFIX,
            'quantity'        => 100,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 1,
            'is_active'       => $active,
        ]);
    }

    private function option(string $name = 'Add-on'): MenuOption
    {
        return MenuOption::create([
            'name'             => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'additional_price' => 10,
            'is_active'        => true,
            'display_order'    => 0,
        ]);
    }

    private function link(MenuOption $option, Inventory $inventory, float $qty = 1): MenuOptionIngredient
    {
        return MenuOptionIngredient::create([
            'menu_option_id' => $option->id,
            'inventory_id'   => $inventory->id,
            'quantity_used'  => $qty,
        ]);
    }

    /** Assign $option to a fresh menu item in $branchId — this is what makes a branch's badge appear. */
    private function assignToBranch(MenuOption $option, int $branchId): MenuItem
    {
        $item = MenuItem::create([
            'category_id'   => Category::query()->value('id'),
            'branch_id'     => $branchId,
            'name'          => self::PREFIX . ' Item ' . uniqid(),
            'price'         => 100,
            'is_available'  => true,
            'display_order' => 0,
        ]);
        $item->options()->attach($option->id);

        return $item;
    }

    private function pageHtml(User $user): string
    {
        return $this->actingAs($user, 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get('/admin/menu-options')
            ->assertOk()
            ->getContent();
    }

    /** One option's whole block: from its own id up to the next option (or the pager). */
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

    /**
     * One saved ingredient link, as the option's row carries it.
     *
     * These used to be rendered <div class="pchy-ing-row"> markup, one copy
     * of the whole recipe editor per option. There is one editor now, in the
     * modal, and what each option's row carries is the DATA that editor is
     * filled from — so this reads the same fields out of that payload
     * instead of out of markup. Every field is still decided on the server,
     * including can_remove; the browser is told what to draw, it does not
     * work the branch rule out for itself.
     */
    private function ingredientPayload(string $block, MenuOptionIngredient $link): array
    {
        $found = preg_match('/data-ingredients="([^"]*)"/s', $block, $m);
        $this->assertSame(1, $found, "the option's row carries no data-ingredients");

        $rows = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
        $this->assertIsArray($rows, 'data-ingredients is not valid JSON');

        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $link->id) {
                return $row;
            }
        }

        $this->fail("ingredient link #{$link->id} is not in the option's payload");
    }

    /** Every saved link on one option's row, in payload order. */
    private function ingredientPayloadAll(string $block): array
    {
        $found = preg_match('/data-ingredients="([^"]*)"/s', $block, $m);
        $this->assertSame(1, $found, "the option's row carries no data-ingredients");

        $rows = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
        $this->assertIsArray($rows);

        return $rows;
    }

    private function statusBlock(string $block): string
    {
        $found = preg_match('/<div class="pchy-branch-status".*?\n\s*<\/div>\s*\n/s', $block, $m);
        $this->assertSame(1, $found, 'the option has no per-branch status block');

        return $m[0];
    }

    // ══════════════════ 1. the JSON carries the branch ══════════════════

    public function test_the_add_json_names_the_branch_the_link_deducts_from(): void
    {
        $branch = $this->branch('Far Branch');
        $inv    = $this->inventoryIn($branch->id, 'Gravy');
        $option = $this->option();

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->postJson('/admin/menu-options/' . $option->id . '/ingredients', [
                'inventory_id'  => $inv->id,
                'quantity_used' => 2.5,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('ingredient.branch_id', $branch->id)
            ->assertJsonPath('ingredient.branch_name', $branch->name)
            // Additive: every key the page's row builder already read is still there.
            ->assertJsonStructure([
                'success',
                'message',
                'ingredient' => ['id', 'name', 'quantity_used', 'unit', 'branch_id', 'branch_name', 'delete_url'],
            ])
            ->assertJsonPath('ingredient.name', $inv->item_name)
            ->assertJsonPath('ingredient.unit', 'g');

        $this->assertSame(1, MenuOptionIngredient::where('menu_option_id', $option->id)->count());
    }

    public function test_an_inventory_row_with_no_branch_gets_null_branch_fields_not_an_error(): void
    {
        // inventory.branch_id is nullable (ON DELETE SET NULL). The link must
        // still save, with the two keys present and null — the page shows no
        // chip for it rather than a blank or an error.
        $inv    = $this->inventoryIn(null, 'Orphan');
        $option = $this->option();

        $response = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->postJson('/admin/menu-options/' . $option->id . '/ingredients', [
                'inventory_id'  => $inv->id,
                'quantity_used' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        // Present AND null — assertJsonPath(..., null) alone would also pass
        // if the key were missing altogether.
        $ingredient = $response->json('ingredient');
        $this->assertArrayHasKey('branch_id', $ingredient);
        $this->assertArrayHasKey('branch_name', $ingredient);
        $this->assertNull($ingredient['branch_id']);
        $this->assertNull($ingredient['branch_name']);
    }

    public function test_a_refused_add_keeps_its_original_422_shape_with_no_ingredient_payload(): void
    {
        $far    = $this->branch('Far Branch');
        $inv    = $this->inventoryIn($far->id, 'Far Gravy');
        $option = $this->option();

        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->postJson('/admin/menu-options/' . $option->id . '/ingredients', [
                'inventory_id'  => $inv->id,
                'quantity_used' => 1,
            ])
            ->assertStatus(422)
            ->assertExactJson([
                'success' => false,
                'message' => "You can only link ingredients from your own branch's inventory.",
            ]);

        $this->assertSame(0, MenuOptionIngredient::where('menu_option_id', $option->id)->count());
    }

    public function test_the_delete_json_success_shape_is_exactly_what_it_was(): void
    {
        // The badge re-sync reads the rows on screen, not the delete response,
        // so this endpoint deliberately gained NOTHING. Pinned exactly so a
        // future "helpful" extra key is a conscious change; the refusal shape
        // (F6) is pinned by DeleteOptionIngredientCrossBranchTest.
        $inv    = $this->inventoryIn(self::HOME_BRANCH);
        $option = $this->option();
        $link   = $this->link($option, $inv);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->deleteJson('/admin/menu-options/' . $option->id . '/ingredients/' . $link->id)
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Option ingredient removed successfully.']);

        $this->assertNull(MenuOptionIngredient::find($link->id));
    }

    // ══════════════════ 1b. the saved rows render it ══════════════════

    public function test_each_saved_link_row_shows_its_own_branch_name_and_carries_the_branch_id(): void
    {
        $a = $this->branch('Alpha');
        $b = $this->branch('Bravo');
        $option = $this->option();
        $linkA = $this->link($option, $this->inventoryIn($a->id, 'Gravy A'));
        $linkB = $this->link($option, $this->inventoryIn($b->id, 'Gravy B'));

        $block = $this->optionBlock($this->pageHtml($this->admin()), $option);

        $rowA = $this->ingredientPayload($block, $linkA);
        $rowB = $this->ingredientPayload($block, $linkB);

        $this->assertSame($a->name, $rowA['branch_name']);
        $this->assertSame($a->id, $rowA['branch_id']);
        $this->assertNotSame($b->name, $rowA['branch_name'], 'link A must not name branch B');

        $this->assertSame($b->name, $rowB['branch_name']);
        $this->assertSame($b->id, $rowB['branch_id']);
        $this->assertNotSame($a->name, $rowB['branch_name'], 'link B must not name branch A');

        // The one builder that draws both a restored row and a freshly added
        // one still puts the branch on .pchy-ing-branch and the id on the
        // row's dataset, which is what optSyncBranchBadges() reads back.
        $view = file_get_contents(resource_path('views/admin/menu-options.blade.php'));
        $this->assertStringContainsString("branch.className = 'pchy-ing-branch'", $view);
        $this->assertStringContainsString('row.dataset.branchId = ing.branch_id', $view);
    }

    public function test_a_link_to_inventory_with_no_branch_renders_no_chip_and_no_branch_id(): void
    {
        $option = $this->option();
        $link   = $this->link($option, $this->inventoryIn(null, 'Orphan'));

        $row = $this->ingredientPayload($this->optionBlock($this->pageHtml($this->admin()), $option), $link);

        // Null, not an empty string and not a guess: the builder draws no
        // chip for a falsy branch_name and sets no dataset.branchId for a
        // null branch_id, so the badge resync cannot credit this link to any
        // branch.
        $this->assertNull($row['branch_name']);
        $this->assertNull($row['branch_id']);
    }

    public function test_the_row_builder_script_is_wired_to_the_branch_keys_and_the_badge_resync(): void
    {
        // A PHP test cannot run the page's script, so this pins the wiring
        // the way MenuItemAddWithIngredientsTest pins the recipe editor's:
        // the names the script reads from the JSON, the class it appends, and
        // that BOTH mutation paths (add, remove) re-sync the badges. The
        // behaviour itself was exercised in a real browser when this pass
        // shipped — see the commit message.
        $view = file_get_contents(resource_path('views/admin/menu-options.blade.php'));

        foreach (['ing.branch_id', 'ing.branch_name', 'pchy-ing-branch', 'row.dataset.branchId'] as $needle) {
            $this->assertStringContainsString($needle, $view, "the row builder no longer uses {$needle}");
        }

        $this->assertSame(
            2,
            substr_count($view, 'optSyncBranchBadges(optionId);'),
            'both the add path and the remove path must re-sync the branch badges'
        );

        // Hardening: a URL that comes back in the response is set as a
        // property, never concatenated into innerHTML (Phase 3b F11's rule).
        $this->assertStringContainsString('del.dataset.url = ing.delete_url', $view);
        $this->assertStringNotContainsString("data-url=\"' + ing.delete_url", $view);

        // ONE BUILDER, for a row restored from the option's payload and for a
        // row that has just come back from the server. They were two before —
        // Blade rendered the saved ones and the script built the new ones —
        // which is two descriptions of one row that can quietly stop
        // matching.
        $this->assertSame(
            1,
            substr_count($view, 'function optIngRow(ing) {'),
            'there must be exactly one ingredient-row builder'
        );
        $this->assertStringContainsString('list.appendChild(optIngRow(ing));', $view);

        // Free text from the server is set as text, never concatenated into
        // markup.
        $this->assertStringContainsString('name.textContent = ing.name;', $view);
        $this->assertStringContainsString('branch.textContent = ing.branch_name;', $view);
    }

    // ══════════════════ 2. badges are live-updatable ══════════════════

    public function test_the_badges_carry_the_hooks_the_live_update_reads(): void
    {
        $mapped   = $this->branch('Mapped Branch');
        $unmapped = $this->branch('Unmapped Branch');
        $option   = $this->option();
        $this->assignToBranch($option, $mapped->id);
        $this->assignToBranch($option, $unmapped->id);
        $this->link($option, $this->inventoryIn($mapped->id));

        $block  = $this->optionBlock($this->pageHtml($this->admin()), $option);
        $status = $this->statusBlock($block);

        $this->assertStringContainsString('id="opt-branch-status-' . $option->id . '"', $status);
        $this->assertStringContainsString('data-title-ok="Has an ingredient link for this branch."', $status);
        $this->assertStringContainsString('data-title-off="', $status);

        // Third title, for the third state: the amber "add inventory first"
        // hint is a state of the badge itself now, not a second pill.
        $this->assertStringContainsString('data-title-empty="', $status);

        // Each badge names its branch id + name in data-*, and keeps its
        // visible "<name> Ready|Needs Inventory|Add Inventory First" text as
        // one contiguous run.
        $this->assertMatchesRegularExpression(
            '/pchy-branch-ok"\s+data-branch-id="' . $mapped->id . '"\s+data-branch-name="' . preg_quote(e($mapped->name), '/') . '"/',
            $status
        );
        $this->assertMatchesRegularExpression(
            '/pchy-branch-(off|empty)"\s+data-branch-id="' . $unmapped->id . '"\s+data-branch-name="' . preg_quote(e($unmapped->name), '/') . '"/',
            $status
        );
        $this->assertStringContainsString(e($mapped->name) . ' Ready</span>', $status);
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(e($unmapped->name), '/') . ' (Needs Inventory|Add Inventory First)<\/span>/',
            $status
        );

        // data-empty-inventory is on EVERY badge, not only the empty ones:
        // optSyncBranchBadges() reads it to decide which of the two not-ready
        // wordings a badge falls to when its last ingredient link is removed,
        // and a badge that is Ready today can be the one that falls.
        $this->assertSame(
            2,
            substr_count($status, 'data-empty-inventory='),
            'both badges must carry the flag the live update reads'
        );
    }

    // ══════════════════ 3. "add inventory first" ══════════════════

    public function test_a_branch_with_no_active_inventory_gets_the_add_inventory_first_hint(): void
    {
        $empty   = $this->branch('Empty Branch');
        $stocked = $this->branch('Stocked Branch');
        $this->inventoryIn($stocked->id, 'Stock');

        $option = $this->option();
        $this->assignToBranch($option, $empty->id);
        $this->assignToBranch($option, $stocked->id);

        $status = $this->statusBlock($this->optionBlock($this->pageHtml($this->admin()), $option));

        // ONE pill per branch. The amber "add inventory first" hint used to be
        // a SECOND pill beside a red one; it is the badge's own third state
        // now, so the count below is the count of branches, not of branches
        // plus hints.
        // Counted on data-branch-id, which every badge carries exactly once:
        // 'class="pchy-branch-badge' would also match the badge's inner
        // .pchy-branch-badge-text span and quietly double the count.
        $this->assertSame(2, substr_count($status, 'data-branch-id='), 'one pill per branch, no more');

        $this->assertSame(1, substr_count($status, 'pchy-branch-empty'), 'exactly one branch is empty');
        $this->assertMatchesRegularExpression(
            '/pchy-branch-empty"\s+data-branch-id="' . $empty->id . '"/',
            $status,
            'the empty state must belong to the EMPTY branch'
        );
        $this->assertMatchesRegularExpression(
            '/pchy-branch-off"\s+data-branch-id="' . $stocked->id . '"/',
            $status,
            'a branch that has active inventory must never be told to add some'
        );

        // It still says what to do, in the badge's own text and title.
        $this->assertStringContainsString(e($empty->name) . ' Add Inventory First</span>', $status);
        $this->assertStringContainsString('data-empty-inventory="1"', $status);

        // And the old two-pill markup is gone, not merely unused.
        $this->assertStringNotContainsString('pchy-branch-hint', $status);
    }

    public function test_inventory_that_is_only_archived_still_counts_as_none(): void
    {
        $branch = $this->branch('Archived Only');
        $this->inventoryIn($branch->id, 'Old Stock', false);   // is_active = false

        $option = $this->option();
        $this->assignToBranch($option, $branch->id);

        $status = $this->statusBlock($this->optionBlock($this->pageHtml($this->admin()), $option));

        $this->assertStringContainsString('pchy-branch-empty', $status);
        $this->assertStringContainsString('data-empty-inventory="1"', $status);
    }

    public function test_the_hint_stays_in_the_markup_but_hidden_while_the_branch_is_mapped(): void
    {
        // Mapped through an ARCHIVED inventory row: the branch is green, yet it
        // has zero ACTIVE inventory. The hint must be present (so removing the
        // last link can bring it back without a reload) but hidden now.
        $branch = $this->branch('Mapped Via Archived');
        $old    = $this->inventoryIn($branch->id, 'Old Stock', false);

        $option = $this->option();
        $this->assignToBranch($option, $branch->id);
        $this->link($option, $old);

        $status = $this->statusBlock($this->optionBlock($this->pageHtml($this->admin()), $option));

        // Mapped wins: the badge is green and reads Ready, NOT the amber
        // "Add Inventory First" — the branch does have a link, it just runs
        // through an archived inventory row.
        $this->assertStringContainsString('pchy-branch-ok', $status, 'fixture sanity: the branch is Mapped');
        $this->assertStringContainsString(e($branch->name) . ' Ready</span>', $status);

        // But the flag that says "this branch has no ACTIVE inventory" stays
        // on the badge, so optSyncBranchBadges() can fall to Add Inventory
        // First — not to Needs Inventory — the moment that last link is
        // removed, without a reload. This is the whole reason the flag is an
        // attribute rather than something re-derived from the rows on screen.
        $this->assertStringContainsString('data-empty-inventory="1"', $status);
    }

    public function test_a_branch_locked_supervisor_gets_the_hint_for_their_own_empty_branch_only(): void
    {
        // A supervisor sees only their own branch's inventory, so the page can
        // vouch for "empty" only there. Branch B is stocked but invisible to
        // them — flagging it "no inventory" would be a claim the page cannot
        // back (and they could not act on it anyway).
        $own    = $this->branch('Own Empty');
        $other  = $this->branch('Other Stocked');
        $this->inventoryIn($other->id, 'Other Stock');

        $option = $this->option();
        $this->assignToBranch($option, $own->id);
        $this->assignToBranch($option, $other->id);

        $status = $this->statusBlock($this->optionBlock($this->pageHtml($this->supervisorAt($own->id)), $option));

        $this->assertMatchesRegularExpression('/pchy-branch-empty"\s+data-branch-id="' . $own->id . '"/', $status);
        $this->assertDoesNotMatchRegularExpression('/pchy-branch-empty"\s+data-branch-id="' . $other->id . '"/', $status);
    }

    public function test_a_branch_locked_supervisor_gets_no_hint_for_a_branch_they_cannot_act_on(): void
    {
        // Their own branch has stock (no hint); the OTHER branch really is
        // empty but is not theirs to fix — still no hint.
        $own   = $this->branch('Own Stocked');
        $empty = $this->branch('Other Empty');
        $this->inventoryIn($own->id, 'Own Stock');

        $option = $this->option();
        $this->assignToBranch($option, $own->id);
        $this->assignToBranch($option, $empty->id);

        $status = $this->statusBlock($this->optionBlock($this->pageHtml($this->supervisorAt($own->id)), $option));

        $this->assertStringNotContainsString('pchy-branch-empty', $status);
    }

    // ══════════════════ 4. supervisor: no dead remove buttons ══════════════════

    public function test_a_branch_locked_supervisor_is_offered_remove_only_on_their_own_branchs_links(): void
    {
        $far    = $this->branch('Far Branch');
        $option = $this->option();
        $own    = $this->link($option, $this->inventoryIn(self::HOME_BRANCH, 'Own Gravy'));
        $other  = $this->link($option, $this->inventoryIn($far->id, 'Far Gravy'));

        $block = $this->optionBlock($this->pageHtml($this->supervisorAt(self::HOME_BRANCH)), $option);

        // Their own branch's link: removable, with the URL to do it.
        $ownRow = $this->ingredientPayload($block, $own);
        $this->assertTrue($ownRow['can_remove']);
        $this->assertSame(
            route('admin.menu-options.ingredients.delete', [$option->id, $own->id]),
            $ownRow['delete_url']
        );

        // The other branch's: not removable, and the URL is WITHHELD rather
        // than handed over to be ignored — the markup this replaced rendered
        // neither a button nor a URL, and the payload keeps that promise.
        $farRow = $this->ingredientPayload($block, $other);
        $this->assertFalse($farRow['can_remove'], "another branch's link must not be offered for removal");
        $this->assertNull($farRow['delete_url']);
        $this->assertStringNotContainsString(
            route('admin.menu-options.ingredients.delete', [$option->id, $other->id]),
            $block,
            "another branch's delete URL must not be anywhere on the option's row"
        );

        // And the builder draws the lock, not a dead button, for that state.
        $view = file_get_contents(resource_path('views/admin/menu-options.blade.php'));
        $this->assertStringContainsString("if (ing.can_remove === false) {", $view);
        $this->assertStringContainsString("lock.className = 'pchy-ing-lock'", $view);
    }

    public function test_an_admin_is_offered_remove_on_every_link(): void
    {
        $far    = $this->branch('Far Branch');
        $option = $this->option();
        $own    = $this->link($option, $this->inventoryIn(self::HOME_BRANCH, 'Own Gravy'));
        $other  = $this->link($option, $this->inventoryIn($far->id, 'Far Gravy'));

        $block = $this->optionBlock($this->pageHtml($this->admin()), $option);

        foreach ([$own, $other] as $link) {
            $row = $this->ingredientPayload($block, $link);
            $this->assertTrue($row['can_remove'], 'an admin may remove any branch\'s link');
            $this->assertSame(
                route('admin.menu-options.ingredients.delete', [$option->id, $link->id]),
                $row['delete_url']
            );
        }

        $this->assertCount(2, $this->ingredientPayloadAll($block));
    }

    public function test_the_hidden_button_is_cosmetic_the_endpoint_still_refuses_the_supervisor(): void
    {
        // Belt and braces alongside DeleteOptionIngredientCrossBranchTest: the
        // page no longer offers the button, and a hand-built request is still
        // refused with F6's exact shape.
        $far    = $this->branch('Far Branch');
        $option = $this->option();
        $link   = $this->link($option, $this->inventoryIn($far->id, 'Far Gravy'));

        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->deleteJson('/admin/menu-options/' . $option->id . '/ingredients/' . $link->id)
            ->assertStatus(422)
            ->assertExactJson([
                'success' => false,
                'message' => "You can only remove ingredients from your own branch's inventory.",
            ]);

        $this->assertNotNull(MenuOptionIngredient::find($link->id));
    }

    // ══════════════════ hardening: quantity_used is bounded ══════════════════

    public function test_an_oversized_quantity_is_a_422_not_a_server_error(): void
    {
        // menu_option_ingredients.quantity_used is decimal(10,3). Before the
        // `max` rule, anything above it passed validation and died in the
        // INSERT (SQLSTATE 22003) as a 500.
        $inv    = $this->inventoryIn(self::HOME_BRANCH);
        $option = $this->option();

        foreach (['10000000', '99999999999999', '1e30'] as $tooBig) {
            $this->actingAs($this->admin(), 'admin')
                ->withSession(['selected_branch_id' => 'all'])
                ->postJson('/admin/menu-options/' . $option->id . '/ingredients', [
                    'inventory_id'  => $inv->id,
                    'quantity_used' => $tooBig,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('quantity_used');
        }

        $this->assertSame(0, MenuOptionIngredient::where('menu_option_id', $option->id)->count());
    }

    public function test_the_largest_quantity_the_column_can_hold_is_still_accepted(): void
    {
        $inv    = $this->inventoryIn(self::HOME_BRANCH);
        $option = $this->option();

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->postJson('/admin/menu-options/' . $option->id . '/ingredients', [
                'inventory_id'  => $inv->id,
                'quantity_used' => '9999999.999',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            '9999999.999',
            MenuOptionIngredient::where('menu_option_id', $option->id)->value('quantity_used')
        );
    }

    // ══════════════════ no per-row lookups ══════════════════

    public function test_branch_names_on_the_rows_cost_no_extra_query_per_linked_option(): void
    {
        // Options with links but NO menu-item assignment, so the page's older
        // per-menu-item query cannot mask or contribute: any growth between
        // the two measurements would be the branch label (or hint) going
        // per-row. Each measurement is its own function scope — DB::listen
        // closures cannot be removed, so a shared scope would double-count.
        $branch = $this->branch('Query Branch');
        $inv    = $this->inventoryIn($branch->id, 'Q Stock');

        for ($i = 0; $i < 2; $i++) {
            $this->link($this->option('Q'), $inv);
        }
        $small = $this->queriesFor('/admin/menu-options');

        for ($i = 0; $i < 6; $i++) {
            $this->link($this->option('Q'), $inv);
        }
        $large = $this->queriesFor('/admin/menu-options');

        $this->assertSame($small, $large, "menu-options query count grew with linked options: {$small} -> {$large}");
    }

    private function queriesFor(string $url): int
    {
        $n = 0;
        $listening = true;
        DB::listen(function () use (&$n, &$listening) {
            if ($listening) {
                $n++;
            }
        });

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get($url)
            ->assertOk();
        $listening = false;

        return $n;
    }
}

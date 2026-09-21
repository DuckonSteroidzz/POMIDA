<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The menu-items recipe-ingredient picker, made unambiguous across branches.
 *
 * Under "All Branches" the controller hands the page EVERY branch's active
 * inventory, and the picker used to list all of it unlabeled — two same-named
 * "Cheese" rows, one per branch, indistinguishable, in the recipe of an item
 * that can only use its own branch's. addIngredient() /
 * inventoryIsSelectableForBranch() already refuse the mismatch server-side, so
 * this is a UX fix, not a security one; the last test pins that the server
 * check is untouched.
 *
 * What the page now does:
 *   - every picker option carries its branch name after the unit;
 *   - each item's own picker (Edit mode) offers only ITS branch's inventory;
 *   - the option's data-name is the bare item name, which the Add/Edit script
 *     reads for the row it appends (the visible text now ends in the label).
 *
 * Every row here carries the RPBL prefix and runs in DatabaseTransactions
 * against pomida_db_testing, so nothing survives the run.
 */
class RecipePickerBranchLabelTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'RPBL';

    // ══════════════════ fixtures ══════════════════

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function branch(string $label): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' ' . $label . ' ' . uniqid(),
            'code'      => 'RPB' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function inventoryIn(int $branchId, string $name): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'item_code'       => 'RPBI-' . strtoupper(substr(uniqid(), -9)),
            'category'        => self::PREFIX,
            'quantity'        => 100,
            'unit'            => 'kg',
            'low_stock_alert' => 1,
            'unit_cost'       => 2,
            'is_active'       => true,
        ]);
    }

    private function itemIn(?int $branchId, string $name = 'Item'): MenuItem
    {
        return MenuItem::create([
            'category_id'   => Category::query()->value('id'),
            'branch_id'     => $branchId,
            'name'          => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'price'         => 100,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    private function pageHtml(string|int $selectedBranch): string
    {
        return $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $selectedBranch])
            ->get('/admin/menu-items')
            ->assertOk()
            ->getContent();
    }

    /** The <select> of one recipe block: 'add' or a menu item's id. */
    private function picker(string $html, string|int $blockId): string
    {
        $start = strpos($html, 'id="recipe-' . $blockId . '"');
        $this->assertNotFalse($start, "recipe block {$blockId} is not on the page");

        $found = preg_match('/<select[^>]*recipe-ing-select.*?<\/select>/s', $html, $m, 0, $start);
        $this->assertSame(1, $found, "recipe block {$blockId} has no ingredient picker");

        return $m[0];
    }

    // ══════════════════ the picker narrows to the item's branch ══════════════════

    public function test_under_all_branches_each_items_picker_offers_only_its_own_branchs_inventory(): void
    {
        $a = $this->branch('Alpha');
        $b = $this->branch('Bravo');
        $invA = $this->inventoryIn($a->id, 'Cheese A');
        $invB = $this->inventoryIn($b->id, 'Cheese B');
        $itemA = $this->itemIn($a->id, 'Pizza A');
        $itemB = $this->itemIn($b->id, 'Pizza B');

        $html = $this->pageHtml('all');

        $pickerA = $this->picker($html, $itemA->id);
        $this->assertStringContainsString($invA->item_name, $pickerA);
        $this->assertStringNotContainsString($invB->item_name, $pickerA, "Branch A's item must not be offered Branch B's stock");

        $pickerB = $this->picker($html, $itemB->id);
        $this->assertStringContainsString($invB->item_name, $pickerB);
        $this->assertStringNotContainsString($invA->item_name, $pickerB, "Branch B's item must not be offered Branch A's stock");
    }

    public function test_the_add_block_under_all_branches_still_lists_every_branch_but_labeled(): void
    {
        // The Add form has no item yet, so there is no branch to narrow to —
        // (and storeNewMenuItem() refuses a save under "All Branches" anyway).
        // Labeling is what keeps the two same-named rows apart here.
        $a = $this->branch('Alpha');
        $b = $this->branch('Bravo');
        $invA = $this->inventoryIn($a->id, 'Cheese A');
        $invB = $this->inventoryIn($b->id, 'Cheese B');

        $add = $this->picker($this->pageHtml('all'), 'add');

        $this->assertStringContainsString($invA->item_name . ' (kg) — ' . e($a->name), $add);
        $this->assertStringContainsString($invB->item_name . ' (kg) — ' . e($b->name), $add);
    }

    // ══════════════════ labels ══════════════════

    public function test_every_option_in_an_items_picker_is_labeled_with_its_branch(): void
    {
        $a = $this->branch('Alpha');
        $invA1 = $this->inventoryIn($a->id, 'Cheese');
        $invA2 = $this->inventoryIn($a->id, 'Flour');
        $item = $this->itemIn($a->id);

        $picker = $this->picker($this->pageHtml('all'), $item->id);

        $this->assertStringContainsString($invA1->item_name . ' (kg) — ' . e($a->name), $picker);
        $this->assertStringContainsString($invA2->item_name . ' (kg) — ' . e($a->name), $picker);
    }

    public function test_a_single_branch_view_is_labeled_too_and_still_scoped_by_the_controller(): void
    {
        $a = $this->branch('Alpha');
        $b = $this->branch('Bravo');
        $invA = $this->inventoryIn($a->id, 'Cheese A');
        $invB = $this->inventoryIn($b->id, 'Cheese B');
        $itemA = $this->itemIn($a->id);

        $html = $this->pageHtml($a->id);

        $picker = $this->picker($html, $itemA->id);
        $this->assertStringContainsString($invA->item_name . ' (kg) — ' . e($a->name), $picker);

        // Not offered anywhere on the page: the controller already scoped the
        // whole inventory list to the selected branch.
        $this->assertStringNotContainsString($invB->item_name, $html);
    }

    public function test_the_option_carries_the_bare_item_name_for_the_script_to_read(): void
    {
        $a = $this->branch('Alpha');
        $inv = $this->inventoryIn($a->id, 'Cheese');
        $item = $this->itemIn($a->id);

        $picker = $this->picker($this->pageHtml('all'), $item->id);

        // data-name is the item name alone; the branch label lives only in the
        // visible text, so it can never leak into the recipe row's name.
        $this->assertStringContainsString('data-name="' . e($inv->item_name) . '"', $picker);
        $this->assertStringNotContainsString('data-name="' . e($inv->item_name) . ' (', $picker);
        $this->assertStringNotContainsString('data-name="' . e($inv->item_name) . ' —', $picker);

        // The figures the live cost preview reads are still on the option.
        $this->assertStringContainsString('data-unit="kg"', $picker);
        $this->assertStringContainsString('data-cost="2.00"', $picker);
    }

    public function test_the_script_reads_the_row_name_from_data_name_not_the_option_text(): void
    {
        // Behaviour was exercised in a real browser (see the commit message);
        // this pins the wiring so the old strip-a-trailing-"(unit)" regex can
        // never quietly become the ONLY source again — with the label after
        // the unit it would carry "— Branch" into every appended row.
        $view = file_get_contents(resource_path('views/admin/menu-items.blade.php'));

        $this->assertStringContainsString('opt.dataset.name', $view, 'Add-mode row name');
        $this->assertStringContainsString('dupOpt.dataset.name', $view, 'the "already in the recipe" message');

        // Hardening: the delete URL from the response is set as a property.
        $this->assertStringContainsString('.dataset.url = ing.delete_url', $view);
        $this->assertStringNotContainsString("data-url=\"' + ing.delete_url", $view);
    }

    // ══════════════════ the deliberately unchanged edge ══════════════════

    public function test_a_legacy_shared_item_with_no_branch_keeps_its_full_labeled_picker(): void
    {
        // KNOWN EDGE, currently unreachable (0 such rows in pomida_db_testing;
        // the UI can no longer create one). addIngredient() skips its branch
        // check for a NULL-branch item, so narrowing its picker to "no rows"
        // would REMOVE something the server still allows. Behaviour is
        // deliberately unchanged — pinned so it stays a conscious decision.
        $a = $this->branch('Alpha');
        $b = $this->branch('Bravo');
        $invA = $this->inventoryIn($a->id, 'Cheese A');
        $invB = $this->inventoryIn($b->id, 'Cheese B');
        $shared = $this->itemIn(null, 'Shared');

        $picker = $this->picker($this->pageHtml('all'), $shared->id);

        $this->assertStringContainsString($invA->item_name . ' (kg) — ' . e($a->name), $picker);
        $this->assertStringContainsString($invB->item_name . ' (kg) — ' . e($b->name), $picker);
    }

    // ══════════════════ the server check is untouched ══════════════════

    public function test_the_server_still_refuses_a_cross_branch_recipe_row_whatever_the_picker_shows(): void
    {
        // The narrowing above is UX. The rule that actually protects the data
        // is inventoryIsSelectableForBranch(), and a hand-built POST bypasses
        // the picker entirely.
        $a = $this->branch('Alpha');
        $b = $this->branch('Bravo');
        $invB = $this->inventoryIn($b->id, 'Cheese B');
        $itemA = $this->itemIn($a->id);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->postJson('/admin/menu-items/' . $itemA->id . '/ingredients', [
                'inventory_id'  => $invB->id,
                'quantity_used' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Selected inventory item must belong to the same branch as this menu item.');
    }

    // ══════════════════ no per-option lookups ══════════════════

    public function test_branch_labels_cost_no_extra_query_per_inventory_row(): void
    {
        // Each measurement is its own function scope: DB::listen closures
        // cannot be removed, so sharing one would double-count.
        $a = $this->branch('Alpha');
        $b = $this->branch('Bravo');
        $this->itemIn($a->id);

        for ($i = 0; $i < 2; $i++) {
            $this->inventoryIn($a->id, 'Few');
            $this->inventoryIn($b->id, 'Few');
        }
        $small = $this->queriesFor('/admin/menu-items');

        for ($i = 0; $i < 8; $i++) {
            $this->inventoryIn($a->id, 'Many');
            $this->inventoryIn($b->id, 'Many');
        }
        $large = $this->queriesFor('/admin/menu-items');

        $this->assertSame($small, $large, "menu-items query count grew with inventory rows: {$small} -> {$large}");
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

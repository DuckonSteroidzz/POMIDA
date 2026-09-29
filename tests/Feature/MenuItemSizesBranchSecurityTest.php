<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminController;
use App\Models\MenuItem;
use App\Models\MenuItemSizeIngredient;
use App\Services\InventoryDeductionService;
use App\Services\MenuItemCosting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Feature\Concerns\MenuItemSizeFixtures;
use Tests\TestCase;

/**
 * Menu Item Sizes, Phase 1 — branch inheritance and security.
 *
 * A size has no branch of its own: it belongs to its menu item, and every size
 * endpoint resolves the PARENT through AdminOrderAccess::resolveRecordInScope()
 * before touching a size. A size recipe takes inventory through the same rule
 * as the base recipe (menuItemRecipeAcceptsInventory(), against the ITEM's
 * branch). None of it names a branch id.
 *
 * Branches here are created per test (A, B, and a brand-new C with nothing
 * else in it); no test relies on a pre-existing branch or its id.
 */
class MenuItemSizesBranchSecurityTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    /** Same-name "Test Coffee" in two branches, each with its own sizes, prices and recipes. */
    private function twinCoffees(): array
    {
        $a = $this->sizeBranch('A');
        $b = $this->sizeBranch('B');

        $invA = $this->sizeInventory($a->id, 1000, 1, 'Beans A');
        $invB = $this->sizeInventory($b->id, 1000, 1, 'Beans B');

        $coffeeA = $this->sizeItem($a->id, 'Test Coffee');
        $coffeeB = $this->sizeItem($b->id, 'Test Coffee');

        [$regA, $lrgA] = $this->enableSizes($coffeeA, 100, 150);
        [$regB, $lrgB] = $this->enableSizes($coffeeB, 110, 160);

        $this->sizeRecipeLine($regA, $invA, 18);
        $this->sizeRecipeLine($lrgA, $invA, 24);
        $this->sizeRecipeLine($regB, $invB, 20);
        $this->sizeRecipeLine($lrgB, $invB, 30);

        return compact('a', 'b', 'invA', 'invB', 'coffeeA', 'coffeeB', 'regA', 'lrgA', 'regB', 'lrgB');
    }

    /** Everything about B that A's edits must not move. */
    private function snapshotOf(array $t): array
    {
        return [
            'item_price' => $this->rawItemPrice($t['coffeeB']->id),
            'sizes'      => DB::table('menu_item_sizes')->where('menu_item_id', $t['coffeeB']->id)->orderBy('id')
                ->get(['id', 'name', 'price', 'is_active', 'archived_at'])->map(fn ($r) => (array) $r)->all(),
            'recipes'    => DB::table('menu_item_size_ingredients')->whereIn('menu_item_size_id', [$t['regB']->id, $t['lrgB']->id])
                ->orderBy('id')->get(['id', 'menu_item_size_id', 'inventory_id', 'quantity'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    // ══════════ 23, 25. Same name, two branches, fully independent ══════════

    public function test_same_name_items_in_two_branches_have_independent_sizes_prices_and_recipes(): void
    {
        $t = $this->twinCoffees();
        $deduction = app(InventoryDeductionService::class);

        $this->assertNotSame($t['regA']->id, $t['regB']->id);
        $this->assertSame('100.00', $this->rawItemPrice($t['coffeeA']->id));
        $this->assertSame('110.00', $this->rawItemPrice($t['coffeeB']->id));
        $this->assertEquals([$t['invA']->id => 18.0], $deduction->requirementsForLine($t['coffeeA'], 1, [], $t['a']->id, $t['regA']));
        $this->assertEquals([$t['invB']->id => 20.0], $deduction->requirementsForLine($t['coffeeB'], 1, [], $t['b']->id, $t['regB']));

        // Each item accepts only its own sizes — B's Regular is not A's.
        $this->assertFalse($t['coffeeA']->hasRecipe($t['regB']));
        $this->assertFalse($t['coffeeB']->hasRecipe($t['regA']->id));
    }

    public function test_changing_branch_a_sizes_never_touches_branch_b(): void
    {
        $t = $this->twinCoffees();
        $before = $this->snapshotOf($t);
        $owner = $this->sizeOwner();
        $sugarA = $this->sizeInventory($t['a']->id, 1000, 1, 'Sugar A');

        $this->actingAs($owner, 'admin')->put(route('admin.menu-items.sizes.update', [$t['coffeeA']->id, $t['regA']->id]), ['price' => 95, 'is_active' => 0])->assertSessionHas('success');
        $this->actingAs($owner, 'admin')->delete(route('admin.menu-items.sizes.archive', [$t['coffeeA']->id, $t['lrgA']->id]))->assertSessionHas('success');
        $this->actingAs($owner, 'admin')->post(route('admin.menu-items.sizes.restore', [$t['coffeeA']->id, $t['lrgA']->id]))->assertSessionHas('success');
        $this->actingAs($owner, 'admin')->postJson(route('admin.menu-items.sizes.ingredients.add', [$t['coffeeA']->id, $t['regA']->id]), [
            'inventory_id' => $sugarA->id, 'quantity_used' => 3,
        ])->assertOk();
        $line = MenuItemSizeIngredient::where('menu_item_size_id', $t['lrgA']->id)->firstOrFail();
        $this->actingAs($owner, 'admin')->deleteJson(route('admin.menu-items.sizes.ingredients.delete', [$t['coffeeA']->id, $t['lrgA']->id, $line->id]))->assertOk();

        // A really did change...
        $this->assertSame('95.00', (string) $this->rawSize($t['regA']->id)->price);
        $this->assertSame('150.00', $this->rawItemPrice($t['coffeeA']->id), 'A: Regular inactive, so Large is the starting price');
        // ...and B did not move at all.
        $this->assertSame($before, $this->snapshotOf($t));
    }

    // ══════════ 24. A brand-new branch needs nothing special ══════════

    public function test_a_brand_new_branch_with_no_other_items_takes_a_sized_item_end_to_end(): void
    {
        $c = $this->sizeBranch('C');
        $this->assertSame(0, DB::table('menu_items')->where('branch_id', $c->id)->count(), 'setup: branch C starts empty');
        $cupC = $this->sizeInventory($c->id, 500, 2, 'Cup C');
        $milkC = $this->sizeInventory($c->id, 5000, 0.1, 'Milk C');
        $owner = $this->sizeOwner();
        $category = $this->sizeCategory();

        // Created through the real Add form, with branch C picked.
        $this->actingAs($owner, 'admin')
            ->withSession(['selected_branch_id' => $c->id])
            ->post(route('admin.new-menu-item.post'), ['category_id' => $category->id, 'name' => 'Test Coffee', 'price' => 90])
            ->assertRedirect(route('admin.menu-items'));
        $item = MenuItem::where('branch_id', $c->id)->orderByDesc('id')->firstOrFail();

        $this->actingAs($owner, 'admin')
            ->post(route('admin.menu-items.sizes.enable', $item->id), ['regular_price' => 120, 'large_price' => 145])
            ->assertSessionHas('success');
        $sizes = $item->fresh()->sizes->keyBy('name');

        // Branch C's own supervisor manages it like any other branch's item.
        $supervisorC = $this->sizeSupervisor($c->id);
        foreach (['Regular' => 150, 'Large' => 250] as $name => $ml) {
            $this->actingAs($supervisorC, 'admin')
                ->postJson(route('admin.menu-items.sizes.ingredients.add', [$item->id, $sizes[$name]->id]), ['inventory_id' => $cupC->id, 'quantity_used' => 1])
                ->assertOk()->assertJsonPath('success', true);
            $this->actingAs($supervisorC, 'admin')
                ->postJson(route('admin.menu-items.sizes.ingredients.add', [$item->id, $sizes[$name]->id]), ['inventory_id' => $milkC->id, 'quantity_used' => $ml])
                ->assertOk();
        }

        $item = $item->fresh();
        $this->assertSame('120.00', $this->rawItemPrice($item->id));
        $this->assertEquals(
            [$cupC->id => 2.0, $milkC->id => 500.0],
            app(InventoryDeductionService::class)->requirementsForLine($item, 2, [], $c->id, $sizes['Large'])
        );
        $this->assertSame(20, $item->remainingServings(null, $sizes['Large']), '5000 ml / 250 ml');

        $this->actingAs($supervisorC, 'admin')->get(route('admin.menu-items'))
            ->assertOk()
            ->assertSee('id="sizes-' . $item->id . '"', false)
            ->assertSee('id="recipe-tbody-size-' . $sizes['Large']->id . '"', false);
    }

    // ══════════ 26-27. Branch-locked supervisors ══════════

    public function test_a_supervisor_cannot_touch_another_branchs_item_sizes(): void
    {
        $t = $this->twinCoffees();
        $plainB = $this->sizeItem($t['b']->id, 'Plain B');
        $supervisorA = $this->sizeSupervisor($t['a']->id);
        $before = $this->snapshotOf($t);
        $lineB = MenuItemSizeIngredient::where('menu_item_size_id', $t['regB']->id)->firstOrFail();

        $this->actingAs($supervisorA, 'admin')->post(route('admin.menu-items.sizes.enable', $plainB->id), ['regular_price' => 1, 'large_price' => 2])->assertNotFound();
        $this->actingAs($supervisorA, 'admin')->put(route('admin.menu-items.sizes.update', [$t['coffeeB']->id, $t['regB']->id]), ['price' => 1, 'is_active' => 0])->assertNotFound();
        $this->actingAs($supervisorA, 'admin')->delete(route('admin.menu-items.sizes.archive', [$t['coffeeB']->id, $t['regB']->id]))->assertNotFound();
        $this->actingAs($supervisorA, 'admin')->postJson(route('admin.menu-items.sizes.ingredients.add', [$t['coffeeB']->id, $t['regB']->id]), ['inventory_id' => $t['invA']->id, 'quantity_used' => 1])->assertNotFound();
        $this->actingAs($supervisorA, 'admin')->deleteJson(route('admin.menu-items.sizes.ingredients.delete', [$t['coffeeB']->id, $t['regB']->id, $lineB->id]))->assertNotFound();

        // B's size id smuggled under A's (in-scope) item id: still a 404.
        $this->actingAs($supervisorA, 'admin')->put(route('admin.menu-items.sizes.update', [$t['coffeeA']->id, $t['regB']->id]), ['price' => 1, 'is_active' => 1])->assertNotFound();
        $this->actingAs($supervisorA, 'admin')->deleteJson(route('admin.menu-items.sizes.ingredients.delete', [$t['coffeeA']->id, $t['regA']->id, $lineB->id]))->assertNotFound();

        $this->assertSame($before, $this->snapshotOf($t));
        $this->assertSame(0, DB::table('menu_item_sizes')->where('menu_item_id', $plainB->id)->count());
    }

    public function test_a_refused_cross_branch_size_write_is_indistinguishable_from_a_missing_id(): void
    {
        $t = $this->twinCoffees();
        $supervisorA = $this->sizeSupervisor($t['a']->id);
        $missingItem = (int) DB::table('menu_items')->max('id') + 1000;

        $foreign = $this->actingAs($supervisorA, 'admin')->put(route('admin.menu-items.sizes.update', [$t['coffeeB']->id, $t['regB']->id]), ['price' => 1, 'is_active' => 1]);
        $missing = $this->actingAs($supervisorA, 'admin')->put(route('admin.menu-items.sizes.update', [$missingItem, $t['regB']->id]), ['price' => 1, 'is_active' => 1]);

        $this->assertSame(404, $foreign->getStatusCode());
        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
    }

    public function test_a_supervisor_cannot_attach_another_branchs_inventory_to_a_size_recipe(): void
    {
        $t = $this->twinCoffees();
        $supervisorA = $this->sizeSupervisor($t['a']->id);
        $url = route('admin.menu-items.sizes.ingredients.add', [$t['coffeeA']->id, $t['lrgA']->id]);
        $countBefore = MenuItemSizeIngredient::where('menu_item_size_id', $t['lrgA']->id)->count();

        $this->actingAs($supervisorA, 'admin')
            ->postJson($url, ['inventory_id' => $t['invB']->id, 'quantity_used' => 5])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Selected inventory item must belong to the same branch as this menu item.');

        // An inactive row of the supervisor's OWN branch is refused by the same rule.
        $retired = $this->sizeInventory($t['a']->id, 1000, 1, 'Retired A', false);
        $this->actingAs($supervisorA, 'admin')->postJson($url, ['inventory_id' => $retired->id, 'quantity_used' => 5])->assertStatus(422);

        $this->assertSame($countBefore, MenuItemSizeIngredient::where('menu_item_size_id', $t['lrgA']->id)->count());

        // Control: an active row of their own branch is accepted.
        $sugarA = $this->sizeInventory($t['a']->id, 1000, 1, 'Sugar A');
        $this->actingAs($supervisorA, 'admin')->postJson($url, ['inventory_id' => $sugarA->id, 'quantity_used' => 5])
            ->assertOk()->assertJsonPath('ingredient.quantity_used', '5')->assertJsonPath('ingredient.inventory_id', $sugarA->id);
    }

    public function test_the_owner_is_held_to_the_items_branch_for_size_recipes_too(): void
    {
        $t = $this->twinCoffees();

        $this->actingAs($this->sizeOwner(), 'admin')
            ->postJson(route('admin.menu-items.sizes.ingredients.add', [$t['coffeeA']->id, $t['regA']->id]), ['inventory_id' => $t['invB']->id, 'quantity_used' => 1])
            ->assertStatus(422);

        $this->assertFalse(MenuItemSizeIngredient::where('menu_item_size_id', $t['regA']->id)->where('inventory_id', $t['invB']->id)->exists());
    }

    // ══════════ 28. Shared (NULL-branch) items ══════════

    public function test_shared_items_follow_the_existing_shared_item_rule(): void
    {
        $a = $this->sizeBranch('A');
        $b = $this->sizeBranch('B');
        $invA = $this->sizeInventory($a->id);
        $invB = $this->sizeInventory($b->id);
        $shared = $this->sizeItem(null, 'Shared Coffee');
        $supervisorA = $this->sizeSupervisor($a->id);
        $owner = $this->sizeOwner();

        // A branch-locked supervisor cannot reach a shared item at all (the
        // same 404 updateMenuItem()/addIngredient() give them).
        $this->actingAs($supervisorA, 'admin')->post(route('admin.menu-items.sizes.enable', $shared->id), ['regular_price' => 1, 'large_price' => 2])->assertNotFound();
        $this->actingAs($supervisorA, 'admin')->postJson(route('admin.menu-items.ingredients.add', $shared->id), ['inventory_id' => $invA->id, 'quantity_used' => 1])->assertNotFound();

        // The owner can, and a shared item's recipe is not narrowed to a
        // branch — exactly what addIngredient() already does for the base recipe.
        $this->actingAs($owner, 'admin')->post(route('admin.menu-items.sizes.enable', $shared->id), ['regular_price' => 80, 'large_price' => 95])->assertSessionHas('success');
        $regular = $shared->fresh()->sizes->first();

        $this->actingAs($supervisorA, 'admin')->put(route('admin.menu-items.sizes.update', [$shared->id, $regular->id]), ['price' => 1, 'is_active' => 1])->assertNotFound();

        foreach ([$invA, $invB] as $inv) {
            $this->actingAs($owner, 'admin')->postJson(route('admin.menu-items.sizes.ingredients.add', [$shared->id, $regular->id]), ['inventory_id' => $inv->id, 'quantity_used' => 1])->assertOk();
            $this->actingAs($owner, 'admin')->postJson(route('admin.menu-items.ingredients.add', $shared->id), ['inventory_id' => $inv->id, 'quantity_used' => 1])->assertOk();
        }

        $this->assertSame(2, MenuItemSizeIngredient::where('menu_item_size_id', $regular->id)->count());
        $this->assertSame(2, DB::table('menu_item_ingredients')->where('menu_item_id', $shared->id)->count(), 'parity: the base recipe editor accepts the same rows');
    }

    // ══════════ 29. No literal branch id ══════════

    public function test_the_size_feature_names_no_branch_id(): void
    {
        $literal = '/branch_?id[\'"\]]?\s*(?:===|!==|==|!=|=>|=|,)\s*[\'"]?\d/i';
        // A branch value defaulted to a literal (the old `branch_id ?? 1` staff
        // lock). Scoped to branch expressions: `unit_cost ?? 0` is not one.
        $coalesce = '/branch\w*\)?\s*\?\?\s*\d/i';

        // Controls: the patterns catch the shapes they exist to catch, and
        // only those.
        foreach (["where('branch_id', 1)", "'branch_id' => 2", '$x->branch_id === 1', 'branch_id = 3'] as $bad) {
            $this->assertMatchesRegularExpression($literal, $bad);
        }
        $this->assertMatchesRegularExpression($coalesce, '$user->branch_id ?? 1');
        $this->assertMatchesRegularExpression($coalesce, '$branchId ?? 1');
        $this->assertDoesNotMatchRegularExpression($coalesce, '$row->inventory->unit_cost ?? 0');
        $this->assertDoesNotMatchRegularExpression($literal, '$menuItem->branch_id === null');

        $sources = [];
        foreach ([
            'app/Models/MenuItemSize.php',
            'app/Models/MenuItemSizeIngredient.php',
            'app/Services/MenuItemSizes.php',
            'app/Exceptions/MenuItemSizeUnavailableException.php',
            'database/migrations/2026_09_27_160000_create_menu_item_sizes_table.php',
            'database/migrations/2026_09_27_160100_create_menu_item_size_ingredients_table.php',
        ] as $file) {
            $sources[$file] = file_get_contents(base_path($file));
        }

        foreach ([
            [AdminController::class, ['enableMenuItemSizes', 'updateMenuItemSize', 'archiveMenuItemSize', 'restoreMenuItemSize',
                'addSizeIngredient', 'deleteSizeIngredient', 'menuItemRecipeAcceptsInventory', 'sizeEditorRedirect']],
            [MenuItem::class, ['sizes', 'allSizes', 'hasSizes', 'syncStartingPriceFromSizes', 'resolveOrderableSize', 'sizeRecipe', 'priceForSize']],
            [MenuItemCosting::class, ['sizeCostLines']],
        ] as [$class, $methods]) {
            foreach ($methods as $method) {
                $ref = new ReflectionMethod($class, $method);
                $lines = file($ref->getFileName());
                $sources[$class . '::' . $method] = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
            }
        }

        $blade = file_get_contents(resource_path('views/admin/menu-items.blade.php'));
        $start = strpos($blade, 'SIZES (Menu Item Sizes, Phase 1)');
        $end = strpos($blade, '{{-- Branch Assignment --}}');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $sources['menu-items.blade.php sizes section'] = substr($blade, $start, $end - $start);

        foreach ($sources as $where => $code) {
            $this->assertDoesNotMatchRegularExpression($literal, $code, "{$where} compares branch_id to a literal");
            $this->assertDoesNotMatchRegularExpression($coalesce, $code, "{$where} coalesces to a literal id");
        }
    }

    // ══════════ 30. Unauthorised callers are refused server-side ══════════

    public function test_staff_and_guests_are_refused_by_the_server_not_just_the_ui(): void
    {
        $t = $this->twinCoffees();
        $plainA = $this->sizeItem($t['a']->id, 'Plain A');
        $before = $this->snapshotOf($t);
        $staffA = $this->sizeStaff($t['a']->id);
        $line = MenuItemSizeIngredient::where('menu_item_size_id', $t['regA']->id)->firstOrFail();

        $calls = [
            ['post',   route('admin.menu-items.sizes.enable', $plainA->id), ['regular_price' => 1, 'large_price' => 2]],
            ['put',    route('admin.menu-items.sizes.update', [$t['coffeeA']->id, $t['regA']->id]), ['price' => 1, 'is_active' => 0]],
            ['delete', route('admin.menu-items.sizes.archive', [$t['coffeeA']->id, $t['regA']->id]), []],
            ['post',   route('admin.menu-items.sizes.restore', [$t['coffeeA']->id, $t['regA']->id]), []],
            ['post',   route('admin.menu-items.sizes.ingredients.add', [$t['coffeeA']->id, $t['regA']->id]), ['inventory_id' => $t['invA']->id, 'quantity_used' => 1]],
            ['delete', route('admin.menu-items.sizes.ingredients.delete', [$t['coffeeA']->id, $t['regA']->id, $line->id]), []],
        ];

        foreach ($calls as [$verb, $url, $data]) {
            // Staff of the SAME branch: role gate refuses (View Menu Items only).
            $this->actingAs($staffA, 'admin')->{$verb}($url, $data)
                ->assertRedirect(route('admin.home'))
                ->assertSessionHas('error', "You don't have permission to access that.");
        }

        $this->app['auth']->forgetGuards();
        foreach ($calls as [$verb, $url, $data]) {
            $this->{$verb}($url, $data)->assertRedirect(route('admin.login'));
        }

        $this->assertSame(0, DB::table('menu_item_sizes')->where('menu_item_id', $plainA->id)->count());
        $this->assertSame('100.00', (string) $this->rawSize($t['regA']->id)->price);
        $this->assertNull($this->rawSize($t['regA']->id)->archived_at);
        $this->assertTrue(MenuItemSizeIngredient::whereKey($line->id)->exists());
        $this->assertSame($before, $this->snapshotOf($t));
    }

    // ══════════ 31. Numeric route ids ══════════

    public function test_non_numeric_route_ids_are_a_clean_404(): void
    {
        $t = $this->twinCoffees();
        $owner = $this->sizeOwner();
        $item = $t['coffeeA']->id;
        $size = $t['regA']->id;

        $this->actingAs($owner, 'admin')->post("/admin/menu-items/abc/sizes", ['regular_price' => 1, 'large_price' => 2])->assertNotFound();
        $this->actingAs($owner, 'admin')->put("/admin/menu-items/{$item}/sizes/abc", ['price' => 1, 'is_active' => 1])->assertNotFound();
        $this->actingAs($owner, 'admin')->put("/admin/menu-items/1x/sizes/{$size}", ['price' => 1, 'is_active' => 1])->assertNotFound();
        $this->actingAs($owner, 'admin')->delete("/admin/menu-items/{$item}/sizes/-1")->assertNotFound();
        $this->actingAs($owner, 'admin')->post("/admin/menu-items/{$item}/sizes/{$size}x/restore")->assertNotFound();
        $this->actingAs($owner, 'admin')->postJson("/admin/menu-items/{$item}/sizes/abc/ingredients", ['inventory_id' => 1, 'quantity_used' => 1])->assertNotFound();
        $this->actingAs($owner, 'admin')->deleteJson("/admin/menu-items/{$item}/sizes/{$size}/ingredients/abc")->assertNotFound();

        $this->assertSame('100.00', (string) $this->rawSize($size)->price);
    }

    // ══════════ 32. CSRF ══════════

    public function test_size_mutations_require_a_csrf_token(): void
    {
        $t = $this->twinCoffees();
        $owner = $this->sizeOwner();
        $update = route('admin.menu-items.sizes.update', [$t['coffeeA']->id, $t['regA']->id]);
        $addLine = route('admin.menu-items.sizes.ingredients.add', [$t['coffeeA']->id, $t['lrgA']->id]);
        $sugarA = $this->sizeInventory($t['a']->id, 1000, 1, 'Sugar A');

        // ValidateCsrfToken skips itself under APP_ENV=testing — move off it
        // first, as CsrfProtectionTest and MenuItemPermanentDeleteTest do.
        $this->app['env'] = 'production';
        $this->assertFalse($this->app->runningUnitTests(), 'CSRF is still being skipped — this test would prove nothing');

        $this->assertSame(419, $this->actingAs($owner, 'admin')->put($update, ['price' => 120, 'is_active' => 1])->getStatusCode());
        $this->assertSame(419, $this->actingAs($owner, 'admin')->postJson($addLine, ['inventory_id' => $sugarA->id, 'quantity_used' => 2])->getStatusCode());
        $this->assertSame('100.00', (string) $this->rawSize($t['regA']->id)->price);
        $this->assertFalse(MenuItemSizeIngredient::where('menu_item_size_id', $t['lrgA']->id)->where('inventory_id', $sugarA->id)->exists());

        // Control: with a valid token both go through.
        $token = 'mis-valid-token';
        $this->actingAs($owner, 'admin')->withSession(['_token' => $token])
            ->put($update, ['_token' => $token, 'price' => 120, 'is_active' => 1])
            ->assertRedirect(route('admin.menu-items'));
        $this->actingAs($owner, 'admin')->withSession(['_token' => $token])
            ->postJson($addLine, ['inventory_id' => $sugarA->id, 'quantity_used' => 2], ['X-CSRF-TOKEN' => $token])
            ->assertOk();

        $this->assertSame('120.00', (string) $this->rawSize($t['regA']->id)->price);
        $this->assertTrue(MenuItemSizeIngredient::where('menu_item_size_id', $t['lrgA']->id)->where('inventory_id', $sugarA->id)->exists());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\MenuOptionIngredient;
use App\Models\Order;
use App\Models\User;
use App\Services\InventoryDeductionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Branch parity audit Phase 3, Finding #3 — Sept 2026.
 *
 * OPUS investigation (rolled-back transaction against pomida_db_testing)
 * confirmed and reproduced that menu add-on OPTIONS are global rows while
 * the INGREDIENTS they deduct (MenuOptionIngredient -> Inventory) are
 * branch-specific, and InventoryDeductionService::requirementsForLine() took
 * every one of an option's ingredient links regardless of branch:
 *
 *   requirementsForLine(branch-B item, qty 1, [option]) =>
 *     inventory #B (Cheese, branch B) -> 5     <- base recipe, correct
 *     inventory #A (Cheese, branch A) -> 20    <- option, WRONG BRANCH
 *
 * Two live consequences, both reproduced:
 *   PHANTOM DRAIN    — a Branch B sale silently removed stock from Branch A.
 *   PHANTOM SHORTAGE — a Branch A shortage blocked a Branch B order with a
 *                       message indistinguishable from a real local shortage.
 *
 * DECISION (Option B — this pass implements it): menu_options,
 * menu_item_options and order_item_options stay GLOBAL, unchanged schema.
 * Deduction becomes branch-aware instead: requirementsForLine() now takes
 * the order/cart's own branch and only deducts the ONE ingredient link
 * (of possibly several) whose inventory belongs to that branch — reusing
 * the existing unique(menu_option_id, inventory_id) constraint, which
 * already permitted one link per branch. An option with NO link at all for
 * a branch is UNMAPPED there and hidden from that branch's customer menu
 * (MenuOption::isMappedForBranch()), with a hard refusal at checkout as
 * defense in depth.
 *
 * Every row this file creates carries the OPTBR prefix and runs inside
 * DatabaseTransactions, so nothing survives the run in pomida_db_testing.
 * Live rows this file only ever READS, never writes: menu_options #8
 * ("extra creamer"), menu_option_ingredients #3 (#8 -> inventory #139),
 * inventory #139 (Creamer, branch 1), menu_items #26 (Brewed Coffee,
 * branch 1).
 */
class OptionBranchAwareDeductionTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'OPTBR';
    private const HOME_BRANCH = 1;

    // Live rows this file's "existing mapping still works" test reads only.
    private const LIVE_OPTION_CREAMER = 8;
    private const LIVE_INVENTORY_CREAMER_BRANCH1 = 139;
    private const LIVE_ITEM_BREWED_COFFEE = 26;

    // ══════════════════ fixtures ══════════════════

    private function otherBranch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Far Branch ' . uniqid(),
            'code'      => 'OXB' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function managerAt(int $branchId): User
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

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function inventoryIn(int $branchId, string $name, float $qty = 100): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'item_code'       => 'OXI-' . strtoupper(substr(uniqid(), -10)),
            'category'        => self::PREFIX,
            'quantity'        => $qty,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 1,
            'is_active'       => true,
        ]);
    }

    private function menuItemIn(int $branchId, string $name): MenuItem
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

    private function option(string $name): MenuOption
    {
        return MenuOption::create([
            'name'             => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'additional_price' => 10,
            'is_active'        => true,
            'display_order'    => 0,
        ]);
    }

    private function linkIngredient(MenuOption $option, Inventory $inventory, float $qty = 1): MenuOptionIngredient
    {
        return MenuOptionIngredient::create([
            'menu_option_id' => $option->id,
            'inventory_id'   => $inventory->id,
            'quantity_used'  => $qty,
        ]);
    }

    // ══════════════════ phantom drain — fixed at the deduction walker ══════════════════

    /**
     * The exact reproduction from the investigation: an option assigned to a
     * Branch B item, whose only ingredient link points at Branch A's
     * inventory. Before this fix, requirementsForLine() returned BOTH the
     * Branch B base-recipe need AND the Branch A option need. It must now
     * return only the Branch B need — the Branch A link is invisible to a
     * Branch B order.
     */
    public function test_an_options_ingredient_link_for_a_different_branch_is_never_deducted(): void
    {
        $branchA = $this->otherBranch();
        $branchB = self::HOME_BRANCH;

        $invA = $this->inventoryIn($branchA->id, 'Cheese A');
        $invB = $this->inventoryIn($branchB, 'Cheese B');

        $item = $this->menuItemIn($branchB, 'Pizza');
        \App\Models\MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $invB->id,
            'quantity_used' => 5,
        ]);

        $option = $this->option('Extra Cheese');
        $item->options()->attach($option->id);
        $this->linkIngredient($option, $invA, 20); // misconfigured: only a Branch-A link

        $needs = app(InventoryDeductionService::class)
            ->requirementsForLine($item, 1, [$option->id], $branchB);

        $this->assertArrayHasKey($invB->id, $needs, 'the base recipe must still be required');
        $this->assertSame(5.0, (float) $needs[$invB->id]);
        $this->assertArrayNotHasKey(
            $invA->id,
            $needs,
            'a Branch A ingredient link must never be deducted for a Branch B order — this is the phantom drain'
        );
    }

    /** The positive case: a link that DOES match the order's branch is deducted. */
    public function test_an_options_ingredient_link_for_the_matching_branch_is_deducted(): void
    {
        $branchB = self::HOME_BRANCH;
        $invB = $this->inventoryIn($branchB, 'Cheese B');
        $item = $this->menuItemIn($branchB, 'Pizza');

        $option = $this->option('Extra Cheese');
        $item->options()->attach($option->id);
        $this->linkIngredient($option, $invB, 20);

        $needs = app(InventoryDeductionService::class)
            ->requirementsForLine($item, 1, [$option->id], $branchB);

        $this->assertSame(20.0, (float) $needs[$invB->id]);
    }

    /** A null branch context (should never happen on a real path) fails closed, not open. */
    public function test_a_null_branch_context_deducts_nothing_from_an_option_rather_than_guessing(): void
    {
        $branchB = self::HOME_BRANCH;
        $invB = $this->inventoryIn($branchB, 'Cheese B');
        $item = $this->menuItemIn($branchB, 'Pizza');

        $option = $this->option('Extra Cheese');
        $item->options()->attach($option->id);
        $this->linkIngredient($option, $invB, 20);

        $needs = app(InventoryDeductionService::class)
            ->requirementsForLine($item, 1, [$option->id], null);

        $this->assertArrayNotHasKey($invB->id, $needs, 'no branch context must never fall back to deducting anyway');
    }

    // ══════════════════ phantom shortage — fixed the same way ══════════════════

    /**
     * The exact reproduction: Branch A's stock for the misconfigured link is
     * empty. A Branch B order selecting the option must NOT be blocked by
     * it — Branch A's shortage is invisible to Branch B.
     */
    public function test_a_branch_as_shortage_does_not_block_a_branch_b_order(): void
    {
        $branchA = $this->otherBranch();
        $branchB = self::HOME_BRANCH;

        $invA = $this->inventoryIn($branchA->id, 'Cheese A', qty: 0); // empty
        $item = $this->menuItemIn($branchB, 'Pizza');
        // No base recipe on purpose — isolates the assertion to the option link.
        // (orderBlockedReason()'s no-recipe guard is not under test here.)

        $option = $this->option('Extra Cheese');
        $item->options()->attach($option->id);
        $this->linkIngredient($option, $invA, 20);

        $errors = app(InventoryDeductionService::class)->validateCartLines([
            ['menu_item' => $item, 'quantity' => 1, 'selected_option_ids' => [$option->id]],
        ], $branchB);

        $this->assertSame([], $errors, "Branch A's own empty stock must not block a Branch B order");
    }

    /** The mirror: a genuine Branch B shortage on the MATCHING link still blocks, correctly attributed. */
    public function test_a_genuine_branch_b_shortage_on_the_matching_link_still_blocks(): void
    {
        $branchB = self::HOME_BRANCH;
        $invB = $this->inventoryIn($branchB, 'Cheese B', qty: 2); // not enough for 20 needed
        $item = $this->menuItemIn($branchB, 'Pizza');

        $option = $this->option('Extra Cheese');
        $item->options()->attach($option->id);
        $this->linkIngredient($option, $invB, 20);

        $errors = app(InventoryDeductionService::class)->validateCartLines([
            ['menu_item' => $item, 'quantity' => 1, 'selected_option_ids' => [$option->id]],
        ], $branchB);

        $this->assertNotEmpty($errors, 'a real Branch B shortage must still block the order');
        $this->assertStringContainsString($invB->item_name, $errors[0]);
    }

    // ══════════════════ hidden from the customer menu when unmapped ══════════════════

    public function test_an_unmapped_option_is_hidden_from_that_branchs_item_details_page(): void
    {
        $item = $this->menuItemIn(self::HOME_BRANCH, 'Hideable Item');
        $option = $this->option('Unmapped Add-On');
        $item->options()->attach($option->id);

        $html = $this->withSession(['branch_id' => self::HOME_BRANCH])
            ->get('/customer/item/' . $item->id)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($option->name, $html, 'an unmapped option must not appear on the option list');
    }

    /** Pairing: once mapped for the branch, it appears. */
    public function test_a_now_mapped_option_appears_on_that_branchs_item_details_page(): void
    {
        $inv = $this->inventoryIn(self::HOME_BRANCH, 'Sprinkles');
        $item = $this->menuItemIn(self::HOME_BRANCH, 'Visible Item');
        $option = $this->option('Mapped Add-On');
        $item->options()->attach($option->id);
        $this->linkIngredient($option, $inv, 1);

        $html = $this->withSession(['branch_id' => self::HOME_BRANCH])
            ->get('/customer/item/' . $item->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($option->name, $html);
    }

    /** An option mapped only for a DIFFERENT branch stays hidden here. */
    public function test_an_option_mapped_only_for_a_different_branch_is_still_hidden_here(): void
    {
        $branchA = $this->otherBranch();
        $invA = $this->inventoryIn($branchA->id, 'Elsewhere');
        $item = $this->menuItemIn(self::HOME_BRANCH, 'Cross Item');
        $option = $this->option('Elsewhere Add-On');
        $item->options()->attach($option->id);
        $this->linkIngredient($option, $invA, 1);

        $html = $this->withSession(['branch_id' => self::HOME_BRANCH])
            ->get('/customer/item/' . $item->id)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($option->name, $html);
    }

    // ══════════════════ checkout-time hard refusal (bypassing the UI) ══════════════════

    /** add-to-cart itself refuses an unmapped option even when posted directly. */
    public function test_add_to_cart_refuses_an_unmapped_option_posted_directly(): void
    {
        $item = $this->menuItemIn(self::HOME_BRANCH, 'Guard Item');
        $option = $this->option('Guarded Add-On');
        $item->options()->attach($option->id);

        $this->withSession(['branch_id' => self::HOME_BRANCH])
            ->post('/customer/cart/add', [
                'item_id'  => $item->id,
                'quantity' => 1,
                'options'  => [$option->id],
            ]);

        $this->assertSame([], session('cart', []), 'an unmapped option must never reach the cart, even bypassing the checkbox list');
    }

    /** Pairing: a mapped option is accepted normally. */
    public function test_add_to_cart_accepts_a_mapped_option(): void
    {
        $inv = $this->inventoryIn(self::HOME_BRANCH, 'Ok Ingredient');
        $item = $this->menuItemIn(self::HOME_BRANCH, 'Guard Item Ok');
        // A base recipe so orderBlockedReason()'s "no recipe" guard does not
        // itself block the add — this test is isolated to the option guard.
        \App\Models\MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $this->inventoryIn(self::HOME_BRANCH, 'Guard Item Ok Base')->id,
            'quantity_used' => 1,
        ]);
        $option = $this->option('Mapped Guarded Add-On');
        $item->options()->attach($option->id);
        $this->linkIngredient($option, $inv, 1);

        $this->withSession(['branch_id' => self::HOME_BRANCH])
            ->post('/customer/cart/add', [
                'item_id'  => $item->id,
                'quantity' => 1,
                'options'  => [$option->id],
            ]);

        $this->assertNotEmpty(session('cart', []), 'a mapped option must be added normally');
    }

    /**
     * The defense-in-depth case the task asked for by name: a cart crafted
     * directly in the session (simulating a stale tab, a cached page, or a
     * hand-built request that skipped addToCart()'s own guard entirely)
     * must still be refused at checkout.
     */
    public function test_checkout_refuses_an_order_whose_cart_holds_an_unmapped_option_even_bypassing_addtocart(): void
    {
        $item = $this->menuItemIn(self::HOME_BRANCH, 'Bypass Item');
        \App\Models\MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $this->inventoryIn(self::HOME_BRANCH, 'Bypass Base')->id,
            'quantity_used' => 1,
        ]);
        $option = $this->option('Bypass Add-On');
        $item->options()->attach($option->id);
        // Deliberately NO ingredient link at all for this branch.

        $cart = [$item->id . '_' . $option->id => [
            'menu_item_id' => $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price + (float) $option->additional_price,
            'quantity'     => 1,
            'options'      => [[
                'id'    => $option->id,
                'name'  => $option->name,
                'price' => (float) $option->additional_price,
            ]],
        ]];

        $before = Order::max('id');

        $this->withSession(['cart' => $cart, 'branch_id' => self::HOME_BRANCH, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('items');

        $this->assertSame($before, Order::max('id'), 'no order may be created while the cart holds an unmapped option');
    }

    /** Pairing: the identical flow succeeds once the option is properly mapped. */
    public function test_checkout_succeeds_once_the_carts_option_is_mapped(): void
    {
        $inv = $this->inventoryIn(self::HOME_BRANCH, 'Bypass Ok Ingredient');
        $item = $this->menuItemIn(self::HOME_BRANCH, 'Bypass Ok Item');
        \App\Models\MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $this->inventoryIn(self::HOME_BRANCH, 'Bypass Ok Base')->id,
            'quantity_used' => 1,
        ]);
        $option = $this->option('Bypass Ok Add-On');
        $item->options()->attach($option->id);
        $this->linkIngredient($option, $inv, 1);

        $cart = [$item->id . '_' . $option->id => [
            'menu_item_id' => $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price + (float) $option->additional_price,
            'quantity'     => 1,
            'options'      => [[
                'id'    => $option->id,
                'name'  => $option->name,
                'price' => (float) $option->additional_price,
            ]],
        ]];

        $before = Order::max('id');

        $this->withSession(['cart' => $cart, 'branch_id' => self::HOME_BRANCH, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ]);

        $this->assertGreaterThan($before, (int) Order::max('id'), 'a properly mapped option must still allow checkout to succeed');
    }

    // ══════════════════ admin ingredient-link picker: branch-locked vs admin ══════════════════

    public function test_a_branch_locked_supervisor_cannot_link_a_far_branchs_inventory_to_an_option(): void
    {
        $far = $this->otherBranch();
        $farInventory = $this->inventoryIn($far->id, 'Far Stock');
        $option = $this->option('Picker Guard');

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->post('/admin/menu-options/' . $option->id . '/ingredients', [
                'inventory_id'   => $farInventory->id,
                'quantity_used'  => 1,
            ]);

        $this->assertSame(
            0,
            MenuOptionIngredient::where('menu_option_id', $option->id)->count(),
            'a supervisor must not be able to link another branch\'s inventory to an option'
        );
    }

    public function test_a_branch_locked_supervisor_can_link_their_own_branchs_inventory_to_an_option(): void
    {
        $ownInventory = $this->inventoryIn(self::HOME_BRANCH, 'Own Stock');
        $option = $this->option('Picker Own');

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->post('/admin/menu-options/' . $option->id . '/ingredients', [
                'inventory_id'   => $ownInventory->id,
                'quantity_used'  => 1,
            ]);

        $this->assertSame(
            1,
            MenuOptionIngredient::where('menu_option_id', $option->id)
                ->where('inventory_id', $ownInventory->id)
                ->count(),
            'a supervisor must be able to link their own branch\'s inventory'
        );
    }

    public function test_admin_can_link_any_branchs_inventory_to_an_option(): void
    {
        $far = $this->otherBranch();
        $farInventory = $this->inventoryIn($far->id, 'Admin Far Stock');
        $option = $this->option('Picker Admin');

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->post('/admin/menu-options/' . $option->id . '/ingredients', [
                'inventory_id'   => $farInventory->id,
                'quantity_used'  => 1,
            ]);

        $this->assertSame(
            1,
            MenuOptionIngredient::where('menu_option_id', $option->id)
                ->where('inventory_id', $farInventory->id)
                ->count(),
            'an admin must remain unrestricted when linking ingredients to an option'
        );
    }

    // ══════════════════ the one live mapping must keep working ══════════════════

    /**
     * opt#8 "extra creamer" -> inventory #139 (Creamer, branch 1) is the
     * ONE ingredient link that existed in live data before this pass. It
     * must continue to resolve exactly as before: deducted for a Branch 1
     * order, because branch 1 IS the matching branch — never altered,
     * only read.
     */
    public function test_the_existing_live_creamer_mapping_still_resolves_for_branch_1(): void
    {
        $item = MenuItem::findOrFail(self::LIVE_ITEM_BREWED_COFFEE);
        $this->assertSame(1, (int) $item->branch_id, 'fixture sanity: Brewed Coffee must still be branch 1');

        $needs = app(InventoryDeductionService::class)
            ->requirementsForLine($item, 1, [self::LIVE_OPTION_CREAMER], 1);

        $this->assertArrayHasKey(
            self::LIVE_INVENTORY_CREAMER_BRANCH1,
            $needs,
            'the existing live opt#8 -> inv#139 mapping must still resolve for a branch 1 order'
        );
    }

    /** And the model-level read the admin badge and the customer guard both use agrees. */
    public function test_the_live_creamer_option_is_mapped_for_branch_1_and_not_for_a_different_branch(): void
    {
        $option = MenuOption::with('ingredients.inventory')->findOrFail(self::LIVE_OPTION_CREAMER);

        $this->assertTrue($option->isMappedForBranch(1));
        $this->assertFalse($option->isMappedForBranch(999999));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 3 audit, finding #8: a branch marked closed (is_active = false)
 * could still take pick-up orders, because neither the branch picker's
 * server side nor placeOrder() ever checked the branch's own status —
 * only the picker's own dropdown query hid closed branches, which a
 * stale session or a direct POST bypasses entirely.
 *
 * Mirrors the no-recipe guard's two-layer shape: a server-side refusal at
 * the point a branch is chosen (selectBranch), and a hard backstop at
 * checkout (placeOrder) that does not trust the session got there
 * honestly. Branch 1 is the main branch and can never be closed
 * (AdminController::toggleBranch() guard), so branch 2 is used here as
 * the one flipped open/closed for each test.
 */
class ClosedBranchPickupTest extends TestCase
{
    use DatabaseTransactions;

    private function closeBranch(int $branchId): void
    {
        Branch::where('id', $branchId)->update(['is_active' => false]);
    }

    private function openBranch(int $branchId): void
    {
        Branch::where('id', $branchId)->update(['is_active' => true]);
    }

    private function orderableItem(int $branchId): MenuItem
    {
        $item = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $branchId,
            'name'          => 'Closed Branch Probe ' . uniqid(),
            'price'         => 120,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        $inv = Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => 'Closed Branch Ingredient ' . uniqid(),
            'item_code'       => 'CB-' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => 500,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 1,
            'is_active'       => true,
        ]);

        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $inv->id,
            'quantity_used' => 5,
        ]);

        return $item;
    }

    // ── selectBranch() ──────────────────────────────────────────────────────

    public function test_selecting_a_closed_branch_is_refused(): void
    {
        $this->closeBranch(2);

        $this->post('/customer/select-branch', ['branch_id' => 2])
            ->assertRedirect(route('customer.menu'));

        $this->assertStringContainsString('currently closed', (string) session('error'));
        $this->assertNotSame(2, session('branch_id'), 'a closed branch must not become the active branch');
    }

    public function test_selecting_an_open_branch_still_succeeds(): void
    {
        $this->openBranch(2);

        $this->post('/customer/select-branch', ['branch_id' => 2])
            ->assertRedirect(route('customer.menu'));

        $this->assertSame(2, session('branch_id'));
        $this->assertSame('pick_up', session('order_type'));
    }

    // ── placeOrder() ─────────────────────────────────────────────────────────

    public function test_checkout_refuses_a_pickup_order_for_a_closed_branch(): void
    {
        $this->closeBranch(2);
        $item = $this->orderableItem(2);

        $cart = [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'quantity'     => 1,
            'options'      => [],
        ]];

        $before = Order::max('id');

        $this->withSession(['cart' => $cart, 'branch_id' => 2, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('error');

        $this->assertStringContainsString(
            'currently closed',
            session('errors')->first('error')
        );
        $this->assertSame($before, Order::max('id'), 'no order may be created for a closed branch');
    }

    public function test_checkout_still_succeeds_for_a_pickup_order_at_an_open_branch(): void
    {
        $this->openBranch(2);
        $item = $this->orderableItem(2);

        $cart = [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'quantity'     => 1,
            'options'      => [],
        ]];

        $before = Order::max('id');

        $this->withSession(['cart' => $cart, 'branch_id' => 2, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertGreaterThan($before, Order::max('id'), 'an order must be created for an open branch');
    }
}

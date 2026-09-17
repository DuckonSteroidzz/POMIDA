<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * OVERSELL AT CHECKOUT.
 *
 * Inventory is only deducted when STAFF completes an order
 * (InventoryDeductionService::deductWithLock(), called from
 * AdminController::completeOrder). Until then a placed order has spoken for
 * stock without touching inventory.quantity. Checkout used to validate against
 * the raw inventory.quantity alone, so N customers could each be told there was
 * enough of the last unit and all N orders were accepted — the shortage only
 * surfaced later, when staff tried to complete the second one and got
 * "Not enough <ingredient>" for an order the customer had already paid for.
 *
 * The fix makes "available" mean inventory MINUS what already-placed,
 * not-yet-completed orders (pending/preparing/serving) have committed, and runs
 * that check inside the order-creating transaction under a row lock on the
 * inventory rows so two simultaneous checkouts cannot both pass it.
 */
class CheckoutStockOversellTest extends TestCase
{
    use DatabaseTransactions;

    private function inventory(float $quantity): Inventory
    {
        return Inventory::create([
            'branch_id'       => 1,
            'item_name'       => 'OVS Ingredient ' . uniqid(),
            'item_code'       => 'OVS-' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => $quantity,
            'unit'            => 'pc',
            'low_stock_alert' => 1,
            'unit_cost'       => 1,
            'is_active'       => true,
        ]);
    }

    /** A menu item whose recipe needs exactly 1 unit of $inv per serving. */
    private function itemNeedingOnePer(Inventory $inv, string $label = 'Coke'): MenuItem
    {
        $item = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => 1,
            'name'          => 'OVS ' . $label . ' ' . uniqid(),
            'price'         => 50,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $inv->id,
            'quantity_used' => 1,
        ]);

        return $item;
    }

    private function cartLine(MenuItem $item, int $qty): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => (float) $item->price,
            'quantity'     => $qty,
            'image'        => $item->image,
            'options'      => [],
        ]];
    }

    /**
     * Place an order as a fresh guest session. Returns the created Order, or
     * null when checkout refused it.
     */
    private function placeAsGuest(array $cart, array $items): ?Order
    {
        $before = (int) Order::max('id');

        $this->withSession([
            'cart'         => $cart,
            'branch_id'    => 1,
            'order_type'   => 'dine_in',
            'table_number' => '7',
        ])->post('/customer/place-order', [
            'order_type'     => 'dine_in',
            'table_number'   => '7',
            'payment_method' => 'cash',
            'items'          => $items,
        ]);

        return Order::where('id', '>', $before)->orderByDesc('id')->first();
    }

    // ══════════════════════════════════════════════════════════════════
    // The reported bug: stock committed by an existing pending order
    // ══════════════════════════════════════════════════════════════════

    /**
     * Someone ELSE's order, already placed and awaiting the kitchen. Seeded
     * directly rather than through checkout so this test measures the stock
     * rule alone — placing two orders from the test client would instead trip
     * rejectIfActiveOrder(), the unrelated "one active order per customer"
     * guard, and pass for the wrong reason.
     */
    private function competingOrder(MenuItem $item, int $qty, string $status = 'pending'): Order
    {
        $order = Order::create([
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'dine_in',
            'table_number'   => '12',
            'order_number'   => 'OVS-' . strtoupper(uniqid()),
            'subtotal'       => $item->price * $qty,
            'total'          => $item->price * $qty,
            'discount_amount' => 0,
            'tax_amount'     => 0,
            'status'         => $status,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'amount_paid'    => 0,
            'change_amount'  => 0,
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'menu_item_id' => $item->id,
            'item_name'    => $item->name,
            'quantity'     => $qty,
            'item_price'   => $item->price,
            'subtotal'     => $item->price * $qty,
        ]);

        return $order;
    }

    public function test_a_second_order_cannot_claim_stock_a_pending_order_already_committed(): void
    {
        $inv  = $this->inventory(1);           // exactly one serving in the pantry
        $item = $this->itemNeedingOnePer($inv);

        $this->competingOrder($item, 1);

        // Inventory is untouched until staff completes that order, so the raw
        // quantity still reads 1 here. That is exactly what let the next order
        // through.
        $this->assertEquals(1.0, (float) $inv->fresh()->quantity);

        $second = $this->placeAsGuest($this->cartLine($item, 1), [
            ['menu_item_id' => $item->id, 'quantity' => 1],
        ]);

        $this->assertNull(
            $second,
            'the second order claimed the same single unit the pending order already committed — this is the oversell'
        );
    }

    public function test_a_cancelled_order_does_not_hold_stock(): void
    {
        $inv  = $this->inventory(1);
        $item = $this->itemNeedingOnePer($inv);

        $this->competingOrder($item, 1, 'cancelled');

        $second = $this->placeAsGuest($this->cartLine($item, 1), [
            ['menu_item_id' => $item->id, 'quantity' => 1],
        ]);

        $this->assertNotNull(
            $second,
            'a cancelled order never deducts, so the unit it was holding must stay orderable'
        );
    }

    public function test_a_completed_order_does_not_hold_stock_twice(): void
    {
        // A completed order has ALREADY been deducted from inventory.quantity,
        // so counting it as committed demand again would double-charge it and
        // wrongly block a sale the pantry can still cover.
        $inv  = $this->inventory(1);
        $item = $this->itemNeedingOnePer($inv);

        $this->competingOrder($item, 1, 'completed');

        $second = $this->placeAsGuest($this->cartLine($item, 1), [
            ['menu_item_id' => $item->id, 'quantity' => 1],
        ]);

        $this->assertNotNull($second, 'a completed order was counted twice against stock');
    }

    // ══════════════════════════════════════════════════════════════════
    // Quantity beyond stock in a single order
    // ══════════════════════════════════════════════════════════════════

    public function test_checkout_refuses_more_units_than_the_stock_can_cover(): void
    {
        $inv  = $this->inventory(2);
        $item = $this->itemNeedingOnePer($inv);

        $order = $this->placeAsGuest($this->cartLine($item, 5), [
            ['menu_item_id' => $item->id, 'quantity' => 5],
        ]);

        $this->assertNull($order, '5 units were accepted against stock for only 2');
    }

    public function test_the_refusal_names_the_item_and_how_many_are_actually_left(): void
    {
        $inv  = $this->inventory(2);
        $item = $this->itemNeedingOnePer($inv);

        $response = $this->withSession([
            'cart'         => $this->cartLine($item, 5),
            'branch_id'    => 1,
            'order_type'   => 'dine_in',
            'table_number' => '7',
        ])->post('/customer/place-order', [
            'order_type'     => 'dine_in',
            'table_number'   => '7',
            'payment_method' => 'cash',
            'items'          => [['menu_item_id' => $item->id, 'quantity' => 5]],
        ]);

        $errors = $response->getSession()->get('errors');
        $this->assertNotNull($errors, 'checkout should have refused with an error bag');

        $message = implode(' ', $errors->all());

        $this->assertStringContainsString($item->name, $message, 'the message must name the item');
        $this->assertStringContainsString(
            'Only 2 ' . $item->name . ' left in stock',
            $message,
            'the message must state how many units are actually available, not just "out of stock"'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // Multiple short items must ALL be reported
    // ══════════════════════════════════════════════════════════════════

    public function test_every_short_item_is_reported_not_just_the_first(): void
    {
        $invA = $this->inventory(1);
        $invB = $this->inventory(1);
        $itemA = $this->itemNeedingOnePer($invA, 'Coke');
        $itemB = $this->itemNeedingOnePer($invB, 'Sprite');

        $cart = $this->cartLine($itemA, 4) + $this->cartLine($itemB, 3);

        $response = $this->withSession([
            'cart'         => $cart,
            'branch_id'    => 1,
            'order_type'   => 'dine_in',
            'table_number' => '7',
        ])->post('/customer/place-order', [
            'order_type'     => 'dine_in',
            'table_number'   => '7',
            'payment_method' => 'cash',
            'items'          => [
                ['menu_item_id' => $itemA->id, 'quantity' => 4],
                ['menu_item_id' => $itemB->id, 'quantity' => 3],
            ],
        ]);

        $message = implode(' ', $response->getSession()->get('errors')->all());

        $this->assertStringContainsString($itemA->name, $message);
        $this->assertStringContainsString($itemB->name, $message, 'the second short item was not reported');
    }

    // ══════════════════════════════════════════════════════════════════
    // The UI must not keep advertising stock an open order has claimed
    // ══════════════════════════════════════════════════════════════════

    public function test_the_menu_shows_out_of_stock_once_an_open_order_has_claimed_the_last_unit(): void
    {
        $inv  = $this->inventory(1);
        $item = $this->itemNeedingOnePer($inv);

        $this->competingOrder($item, 1);

        $html = $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/items/' . $item->category_id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($item->name, $html);
        $this->assertStringContainsString(
            'Out of Stock',
            $html,
            'the grid still advertised a unit that an open order had already claimed'
        );
    }

    public function test_the_menu_badge_counts_down_as_open_orders_claim_stock(): void
    {
        $inv  = $this->inventory(5);
        $item = $this->itemNeedingOnePer($inv);

        $this->competingOrder($item, 3);   // 5 in the pantry, 3 already promised

        $html = $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/items/' . $item->category_id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '2 stocks left',
            $html,
            'the low-stock badge showed the raw pantry count instead of what is really orderable'
        );
    }

    public function test_the_cart_quantity_control_will_not_exceed_what_is_really_available(): void
    {
        $inv  = $this->inventory(3);
        $item = $this->itemNeedingOnePer($inv);

        $this->competingOrder($item, 2);   // only 1 genuinely left

        $this->withSession([
            'cart' => $this->cartLine($item, 1),
            'branch_id'  => 1,
            'order_type' => 'pick_up',
        ])
            ->putJson('/customer/cart/update/' . $item->id, ['quantity' => 3])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'max_quantity' => 1]);
    }

    public function test_add_to_cart_refuses_beyond_what_is_really_available(): void
    {
        $inv  = $this->inventory(3);
        $item = $this->itemNeedingOnePer($inv);

        $this->competingOrder($item, 2);   // only 1 genuinely left

        $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/cart/add', ['item_id' => $item->id, 'quantity' => 2]);

        $this->assertStringContainsString(
            'Only 1 ' . $item->name . ' left in stock',
            (string) session('error')
        );
        $this->assertEmpty(session('cart') ?? [], 'nothing should have reached the cart');
    }

    // ══════════════════════════════════════════════════════════════════
    // Requirement 6: a normally-stocked order must be untouched
    // ══════════════════════════════════════════════════════════════════

    public function test_a_well_stocked_order_still_goes_through_unchanged(): void
    {
        $inv  = $this->inventory(500);
        $item = $this->itemNeedingOnePer($inv);

        $order = $this->placeAsGuest($this->cartLine($item, 3), [
            ['menu_item_id' => $item->id, 'quantity' => 3],
        ]);

        $this->assertNotNull($order, 'a comfortably-stocked order must not be affected by the new guard');
        $this->assertSame('pending', $order->status);
        $this->assertEquals(500.0, (float) $inv->fresh()->quantity, 'checkout must NOT deduct — staff completion still does that');
    }
}

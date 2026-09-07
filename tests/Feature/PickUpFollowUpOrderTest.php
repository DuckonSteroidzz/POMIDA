<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use App\Services\CustomerOrderAccess;
use App\Services\TableOccupancy;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * A pick-up customer ordering again while their first order is still in flight.
 *
 * THE BUG THIS EXISTS FOR
 * -----------------------
 * Reported live: sign in as a real customer, place a pick-up order with Cash,
 * then try to order again — nothing could be added to the cart until staff had
 * run the first order all the way through Prepare, Serving and Complete. The
 * owner's case is the ordinary one: order 1 is drinks, then you remember you
 * wanted food, and order 2 follows a minute later.
 *
 * Item 43 had already fixed exactly this symptom, but deliberately only for
 * DINE-IN, because a pick-up order has no table and therefore no "still seated
 * at the same visit" signal to hang the permission on. That was caution rather
 * than a requirement — nothing else in the app assumed a customer had at most
 * one open order — so the rule now covers pick-up too.
 *
 * WHAT MUST NOT BREAK
 * -------------------
 * Ownership. A second pick-up order must not orphan the first one: a guest's
 * claim lives in App\Support\GuestOrders, which is a SET of order ids (item 43),
 * and a signed-in customer's claim is user_id. Both are re-checked here.
 * Dine-in behaviour and the item-40 table guarantees are covered by
 * OrderingDuringActiveOrderTest and TableOccupancyTest and are untouched.
 */
class PickUpFollowUpOrderTest extends TestCase
{
    use DatabaseTransactions;

    private const ITEM_ID = 25;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('qr-scan:127.0.0.1');
    }

    // ══════════ helpers ══════════

    private function customer(): User
    {
        return User::where('email', 'pedro@gmail.com')->firstOrFail();
    }

    private function addToCart(int $itemId = self::ITEM_ID)
    {
        return $this->from('/customer/menu')
            ->post('/customer/cart/add', ['item_id' => $itemId, 'quantity' => 1]);
    }

    private function cartQuantity(): int
    {
        $total = 0;

        foreach (session('cart', []) as $line) {
            $total += $line['quantity'];
        }

        return $total;
    }

    private function makePickUpOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'PU-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'table_number'   => null,
            'status'         => 'preparing',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 200,
            'total'          => 200,
        ], $attrs));
    }

    public static function activeStatuses(): array
    {
        return [
            'pending'   => ['pending'],
            'preparing' => ['preparing'],
            'serving'   => ['serving'],
        ];
    }

    // ══════════ the reported bug ══════════

    /**
     * @dataProvider activeStatuses
     */
    public function test_a_logged_in_pick_up_customer_can_add_to_cart_again(string $status): void
    {
        $customer = $this->customer();

        $this->makePickUpOrder(['user_id' => $customer->id, 'status' => $status]);

        $this->actingAs($customer, 'customer');
        session(['cart' => [], 'branch_id' => 1, 'order_type' => 'pick_up']);

        $this->addToCart();

        $this->assertSame(
            1,
            $this->cartQuantity(),
            "add to cart was blocked while the pick-up order was '{$status}'"
        );
        $this->assertNull(session('active_order_warning'));
    }

    public function test_a_guest_pick_up_customer_can_add_to_cart_again(): void
    {
        $first = $this->makePickUpOrder();
        GuestOrders::remember($first->id);

        session(['cart' => [], 'branch_id' => 1, 'order_type' => 'pick_up']);

        $this->addToCart();

        $this->assertSame(1, $this->cartQuantity(), 'a guest pick-up customer was still locked out');
        $this->assertNull(session('active_order_warning'));
    }

    public function test_a_logged_in_pick_up_customer_can_place_a_second_order(): void
    {
        $customer = $this->customer();
        $first = $this->makePickUpOrder(['user_id' => $customer->id, 'status' => 'preparing']);

        $this->actingAs($customer, 'customer');
        session(['cart' => [], 'branch_id' => 1, 'order_type' => 'pick_up']);

        $this->addToCart();

        $before = Order::count();

        // placeOrder() prices from the SESSION cart but still validates an
        // items[] array, so the checkout form posts both.
        $this->from('/customer/cart')->post('/customer/place-order', [
            'order_type'     => 'pick_up',
            'payment_method' => 'cash',
            'items'          => [
                ['menu_item_id' => self::ITEM_ID, 'quantity' => 1],
            ],
        ]);

        $this->assertSame($before + 1, Order::count(), 'the follow-up pick-up order was refused');

        $second = Order::orderByDesc('id')->first();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame((int) $customer->id, (int) $second->user_id);
    }

    public function test_a_guest_keeps_its_claim_on_both_pick_up_orders(): void
    {
        $first = $this->makePickUpOrder();
        GuestOrders::remember($first->id);

        $second = $this->makePickUpOrder(['status' => 'pending']);
        GuestOrders::remember($second->id);

        foreach ([$first, $second] as $order) {
            $this->assertTrue(
                GuestOrders::owns($order->id),
                "order {$order->id} was orphaned by the follow-up order"
            );
            $this->assertNotNull(
                CustomerOrderAccess::resolveOwnedOrder($order->id),
                "order {$order->id} stopped being readable by the guest that placed it"
            );
        }
    }

    // ══════════ the customer's own Orders page ══════════

    public function test_the_orders_page_lists_every_in_flight_order(): void
    {
        $customer = $this->customer();

        $first  = $this->makePickUpOrder(['user_id' => $customer->id, 'status' => 'preparing']);
        $second = $this->makePickUpOrder(['user_id' => $customer->id, 'status' => 'pending']);

        $response = $this->actingAs($customer, 'customer')->get('/customer/orders');

        $response->assertOk();
        // Before this, showOrders() took ->first() and the earlier order simply
        // vanished from the customer's own page the moment they ordered again.
        $response->assertSee($first->order_number);
        $response->assertSee($second->order_number);
    }

    public function test_the_orders_page_lists_every_in_flight_order_for_a_guest(): void
    {
        $first  = $this->makePickUpOrder(['status' => 'preparing']);
        $second = $this->makePickUpOrder(['status' => 'pending']);

        GuestOrders::remember($first->id);
        GuestOrders::remember($second->id);

        $response = $this->get('/customer/orders');

        $response->assertOk();
        $response->assertSee($first->order_number);
        $response->assertSee($second->order_number);
    }

    // ══════════ nothing else moved ══════════

    public function test_a_dine_in_order_still_needs_the_table_to_still_be_held(): void
    {
        $order = Order::create([
            'order_number'   => 'PUDI-' . substr(uniqid(), -6),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'dine_in',
            'table_number'   => '77',
            'status'         => 'preparing',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 200,
            'total'          => 200,
        ]);

        // No occupancy on table 77 at all: the dine-in branch of the rule must
        // still say no, so widening pick-up cannot be read as widening dine-in.
        $this->assertNull(TableOccupancy::activeFor(1, '77'));
        $this->assertFalse(CustomerOrderAccess::mayOrderAlongside($order));
    }

    public function test_a_pick_up_order_never_touches_table_occupancy(): void
    {
        $before = TableSession::count();

        $order = $this->makePickUpOrder();
        TableOccupancy::attachOrder($order);
        TableOccupancy::attachStaffOrder($order, null);

        $this->assertSame(
            $before,
            TableSession::count(),
            'a pick-up order must never create a table occupancy'
        );
    }
}

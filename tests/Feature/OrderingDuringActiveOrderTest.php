<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Ordering a second time during the same dine-in visit.
 *
 * THE BUG THIS EXISTS FOR
 * -----------------------
 * A dine-in customer whose order was still being prepared could not add
 * anything else to their cart. AuthController::rejectIfActiveOrder() bounced
 * every cart action while the customer had an order in pending, preparing or
 * serving, and only stopped once staff marked it completed or cancelled — which
 * is exactly the symptom that was reported. In a café that is wrong: people
 * order a second round while they are sitting there.
 *
 * THE PART THAT MUST NOT BREAK
 * ----------------------------
 * The one-live-session-per-table rule. Letting the seated party order again must
 * not let a DIFFERENT party onto an occupied table, and must not free a table
 * that still has an unfinished order on it. TableOccupancyTest covers the
 * original rule and is deliberately left untouched; the cases at the bottom of
 * this file re-check the same guarantee from this feature's angle.
 */
class OrderingDuringActiveOrderTest extends TestCase
{
    use DatabaseTransactions;

    private const ITEM_ID = 25;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
        parent::tearDown();
    }

    // ══════════ helpers ══════════

    private function scan(int $branchId, string $table)
    {
        // The QR carries the table's permanent code as `k` now, and every door
        // requires it (App\Services\TableEntry::validate()). Register the table
        // and scan the URL its real QR would encode.
        $code = TableEntry::findOrRegister($branchId, $table)->code;

        return $this->from('/customer/dineinqr')->post('/customer/dineinqr', [
            'tableData' => "branch_id={$branchId}&table={$table}&k={$code}",
            'next'      => 'guest',
        ]);
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

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'AO-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'dine_in',
            'table_number'   => '81',
            'status'         => 'preparing',
            'payment_method' => 'gcash',
            'payment_status' => 'paid',
            'subtotal'       => 200,
            'total'          => 200,
        ], $attrs));
    }

    /** Put the current session in "seated, one order already placed" state. */
    private function seatedWithOrder(string $table, array $attrs = []): Order
    {
        $this->scan(1, $table);

        $order = $this->makeOrder(array_merge(['table_number' => $table], $attrs));

        GuestOrders::remember($order->id);
        TableOccupancy::attachOrder($order);

        session(['cart' => []]);

        return $order;
    }

    // ══════════ the reported bug ══════════

    public static function activeStatuses(): array
    {
        return [
            'pending'   => ['pending'],
            'preparing' => ['preparing'],
            'serving'   => ['serving'],
        ];
    }

    /**
     * @dataProvider activeStatuses
     */
    public function test_a_seated_guest_can_still_add_to_cart(string $status): void
    {
        $order = $this->seatedWithOrder('81');
        $order->status = $status;
        $order->save();

        $this->addToCart();

        $this->assertSame(1, $this->cartQuantity(), "add to cart was blocked while the order was '{$status}'");
        $this->assertNull(session('active_order_warning'));
    }

    public function test_a_seated_logged_in_customer_can_still_add_to_cart(): void
    {
        $customer = User::where('role', 'customer')->firstOrFail();
        $this->actingAs($customer, 'customer');

        $this->scan(1, '82');

        $order = $this->makeOrder([
            'table_number' => '82',
            'user_id'      => $customer->id,
            'status'       => 'preparing',
        ]);
        TableOccupancy::attachOrder($order);
        session(['cart' => []]);

        $this->addToCart();

        $this->assertSame(1, $this->cartQuantity());
        $this->assertNull(session('active_order_warning'));
    }

    public function test_a_seated_guest_can_update_and_remove_cart_lines(): void
    {
        $this->seatedWithOrder('83');

        $this->addToCart();
        $this->assertSame(1, $this->cartQuantity());

        $key = array_key_first(session('cart'));

        $this->from('/customer/cart')->put("/customer/cart/update/{$key}", ['quantity' => 3]);
        $this->assertSame(3, $this->cartQuantity());

        $this->from('/customer/cart')->delete("/customer/cart/remove/{$key}");
        $this->assertSame(0, $this->cartQuantity());
    }

    public function test_a_seated_guest_can_place_a_second_order(): void
    {
        $first = $this->seatedWithOrder('84');

        $this->addToCart();

        $before = Order::count();

        // placeOrder() prices from the SESSION cart but still validates an
        // items[] array, so the checkout form posts both.
        $this->from('/customer/cart')->post('/customer/place-order', [
            'order_type'     => 'dine_in',
            'payment_method' => 'cash',
            'table_number'   => '84',
            'items'          => [
                ['menu_item_id' => self::ITEM_ID, 'quantity' => 1],
            ],
        ]);

        $this->assertSame($before + 1, Order::count(), 'the second order of the visit was refused');

        $second = Order::orderByDesc('id')->first();
        $this->assertNotSame($first->id, $second->id);

        // BOTH orders must still belong to this session. The old single
        // guest_order_id would have been overwritten here, quietly orphaning
        // the first order from its own customer.
        $this->assertTrue(GuestOrders::owns($first->id), 'the first order was orphaned by the second');
        $this->assertTrue(GuestOrders::owns($second->id));
    }

    public function test_both_orders_of_a_visit_stay_readable_by_the_guest(): void
    {
        $first  = $this->seatedWithOrder('85');
        $second = $this->makeOrder(['table_number' => '85', 'status' => 'pending']);
        GuestOrders::remember($second->id);

        foreach ([$first, $second] as $order) {
            $this->assertNotNull(
                \App\Services\CustomerOrderAccess::resolveOwnedOrder($order->id),
                "order {$order->id} stopped being readable by the guest that placed it"
            );
        }
    }

    // ══════════ the scope of the relaxation ══════════

    /**
     * DELIBERATE REVERSAL of the assertion that used to live here.
     *
     * Item 43 scoped the relaxation to dine-in and this test pinned that:
     * "pick-up was not part of this change and must be unchanged". The owner
     * then hit the same wall from the counter queue — order drinks, remember
     * the food a minute later, cart dead until the kitchen finishes — and asked
     * for pick-up to be included. The old scoping was caution, not a
     * requirement, so the expectation is now the opposite. See
     * CustomerOrderAccess::mayOrderAlongside() for what did and did not depend
     * on the "still seated" signal.
     */
    public function test_a_pick_up_customer_may_now_order_again(): void
    {
        $order = $this->makeOrder(['type' => 'pick_up', 'table_number' => null, 'status' => 'preparing']);
        GuestOrders::remember($order->id);
        session(['cart' => []]);

        $this->addToCart();

        $this->assertSame(1, $this->cartQuantity(), 'a pick-up customer could not add a follow-up item');
        $this->assertNull(session('active_order_warning'));
    }

    public function test_a_dine_in_customer_who_no_longer_holds_the_table_is_blocked(): void
    {
        $order = $this->seatedWithOrder('86');

        // Staff cleared the table out from under them.
        TableOccupancy::releaseTable(1, '86', null);

        session(['cart' => []]);
        $this->addToCart();

        $this->assertSame(0, $this->cartQuantity());
        $this->assertTrue((bool) session('active_order_warning'));
    }

    // ══════════ one session per table must still hold ══════════
    //
    // These used to assert that a different visitor was REFUSED. Occupancy is
    // shared now — the second person at a table of four must get in — so what
    // is asserted is the invariant that actually still holds and that
    // everything else depends on: the table keeps exactly ONE session row, and
    // a joiner does not acquire the order sitting on it.

    public function test_a_different_visitor_joins_the_one_session_on_the_table(): void
    {
        $this->seatedWithOrder('87');

        $opened = TableOccupancy::activeFor(1, '87');
        $this->assertNotNull($opened);

        // A different phone: different session cookie, no order of its own.
        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');

        $this->scan(1, '87');

        $this->assertNull(session('error'));
        $this->assertSame('87', session('table_number'));

        $joined = TableOccupancy::activeFor(1, '87');
        $this->assertSame($opened->id, $joined->id, 'must join, not open a rival session');
        $this->assertSame(1, TableSession::where('active_lock', '1:87')->count());
        $this->assertSame($opened->order_id, $joined->order_id, 'the existing order must stay linked');
    }

    public function test_a_typed_permanent_code_joins_the_same_session(): void
    {
        $this->seatedWithOrder('88');

        $opened = TableOccupancy::activeFor(1, '88');

        $this->flushSession();
        RateLimiter::clear('table-code:127.0.0.1');

        $code = TableEntry::findOrRegister(1, '88')->code;

        $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', ['table_code' => $code, 'next' => 'guest']);

        $this->assertNull(session('error'));
        $this->assertSame($opened->id, TableOccupancy::activeFor(1, '88')->id);
        $this->assertSame(1, TableSession::where('active_lock', '1:88')->count());
    }

    /**
     * A visit with two orders must not free the table when the first one
     * finishes. Before the hand-off in TableOccupancy::releaseForOrder(), the
     * occupancy pointed only at the most recent order, so finishing the other
     * one released a table that was still very much in use.
     */
    public function test_the_table_is_held_until_every_order_on_it_is_finished(): void
    {
        $first  = $this->seatedWithOrder('89');
        $second = $this->makeOrder(['table_number' => '89', 'status' => 'pending']);
        GuestOrders::remember($second->id);
        TableOccupancy::attachOrder($second);

        // First round finishes; the customer is still eating the second.
        $first->status = 'completed';
        $first->completed_at = now();
        $first->save();

        $held = TableOccupancy::activeFor(1, '89');
        $this->assertNotNull($held, 'the table was freed while a second order was still open');
        $this->assertSame($second->id, $held->order_id, 'the occupancy should now track the order still running');

        // And another scan joins the same held session rather than starting a
        // second one on a table that is mid-meal.
        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');
        $this->scan(1, '89');

        $this->assertNull(session('error'));
        $this->assertSame($held->id, TableOccupancy::activeFor(1, '89')->id);
        $this->assertSame(1, TableSession::where('active_lock', '1:89')->count());
    }

    public function test_the_table_is_released_once_the_last_order_finishes(): void
    {
        $first  = $this->seatedWithOrder('90');
        $second = $this->makeOrder(['table_number' => '90', 'status' => 'pending']);
        GuestOrders::remember($second->id);
        TableOccupancy::attachOrder($second);

        $first->status = 'completed';
        $first->completed_at = now();
        $first->save();

        $second->status = 'completed';
        $second->completed_at = now();
        $second->save();

        $this->assertNull(
            TableOccupancy::activeFor(1, '90'),
            'the table must be free once nothing is outstanding'
        );
    }

    public function test_a_new_visit_does_not_inherit_the_previous_party_orders(): void
    {
        $old = $this->seatedWithOrder('91');
        $this->assertTrue(GuestOrders::owns($old->id));

        $old->status = 'completed';
        $old->completed_at = now();
        $old->save();

        // Same browser, new party sitting down and scanning again.
        $this->scan(1, '91');

        $this->assertFalse(
            GuestOrders::owns($old->id),
            'starting a new visit must drop the previous party claims'
        );
    }
}

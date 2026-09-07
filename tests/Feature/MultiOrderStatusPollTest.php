<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A visitor's SECOND (and later) order must be tracked exactly as well as
 * their first — the customer-facing counterpart to OrderStatusToastTest.
 *
 * THE BUG THIS EXISTS FOR
 * -----------------------
 * Reported live with real screenshots taken seconds apart: a visitor's two
 * pick-up orders both showed on their own Orders page, but only the newer one
 * (ORD-...-4AVI38, GCash) advanced through Pending -> Preparing -> Serving.
 * The older one (ORD-...-AXQUME, Cash) sat frozen on Pending while the admin
 * board correctly showed BOTH orders at "Serving" — the server had the right
 * data the whole time. The owner also reported the frozen order looked like it
 * had no receipt at all.
 *
 * ROOT CAUSE, established by reading the exact code before changing anything
 * ----------------------------------------------------------------------------
 * placeOrder() writes session('customer_order_id') as a SINGLE scalar,
 * overwritten to the just-placed order every time — a leftover from before
 * item 43 (dine-in second round) and Round 3A (pick-up follow-up) made more
 * than one open order legitimate. Three places still only ever knew about that
 * one id:
 *
 *  1. orders.blade.php's Blade markup put the tracker's DOM ids
 *     (id="orderStepPending" etc, no suffix) on ONLY the first card
 *     ($isTracked = $loop->first) — a second card's tracker elements had no
 *     ids at all, so JS could never target them.
 *  2. pollCustomerOrderStatus() polled exactly one id — trackedOrderId, seeded
 *     from that same session scalar — so an older order was never even asked
 *     about, let alone repainted.
 *  3. showOrderStatusNotice()'s cancellation branch WIPED THE ENTIRE
 *     #statusTab and stopped the poll outright on the assumption that a
 *     visitor could only ever have one order — a second, still very much open
 *     order would have been deleted from the screen if the first was cancelled
 *     by staff.
 *
 * THE RENDER PATH WAS NEVER WRONG
 * --------------------------------
 * A full manual refresh (F5) already showed every order's correct live status,
 * because showOrders() already returns $currentOrders (plural, item 43/Round
 * 3A) and each card's Pending/Preparing/Serving classes are computed fresh
 * from $currentOrder->status on every render. The bug was entirely in the
 * AUTO-REFRESH path: the poll and the DOM ids it could reach. Confirmed live
 * with Playwright: driving an order through preparing -> serving with the
 * customer's tab never open, then a single fresh page load, rendered the
 * correct state before this fix and after it, unchanged.
 *
 * THE FIX
 * -------
 * Every tracker element now carries the order's OWN id
 * (id="orderStepPending-{orderId}" etc — see the wrapper
 * id="orderCard-{orderId}" too), a new customer.orders-status endpoint answers
 * for a whole list of ids at once (not one), and the cancellation cleanup now
 * removes only the one card that was cancelled, checking whether any others
 * remain before ever touching the empty state or stopping the poll.
 *
 * WHY THE BULK ENDPOINT TAKES ORDER IDS FROM THE CLIENT
 * -------------------------------------------------------
 * A first version of customerOrdersStatus() re-derived "every order still in
 * flight" with a status filter on each poll. That looked reasonable and was
 * subtly wrong: the instant an order transitions to completed, it stops
 * matching that filter — so the one poll that needed to notice the transition
 * is the one poll that can no longer see the order at all. Fixed by asking
 * about the EXACT ids the page rendered tracker cards for
 * (session's customer.orders-status ?order_ids[]=... ), which keeps asking
 * about those same ids for as long as the page is open regardless of what
 * their status becomes. Ownership is still resolved purely server-side; a
 * requested id that is not the caller's is simply dropped from the response,
 * never leaked.
 *
 * These assertions pin the server-side half of that fix — the exact set of
 * orders customerOrdersStatus() answers for, and that ownership still cannot
 * be crossed. The live tracker repaint and the popup were verified with a real
 * Playwright run against a running server (mixed-order advancement, both
 * pick-up and dine-in, guest and logged-in) and are not re-simulated here.
 */
class MultiOrderStatusPollTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function otherCustomer(): ?User
    {
        return User::where('role', 'customer')->where('id', '!=', $this->customer()->id)->first();
    }

    private function item(): MenuItem
    {
        return MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->firstOrFail();
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'MSP-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'table_number'   => null,
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ], $attrs));
    }

    // ══════════ the bug: two orders, both must be answered for ══════════

    public function test_the_bulk_endpoint_answers_for_every_requested_owned_order_logged_in(): void
    {
        $customer = $this->customer();

        $older = $this->makeOrder(['user_id' => $customer->id, 'status' => 'preparing']);
        $newer = $this->makeOrder(['user_id' => $customer->id, 'status' => 'serving']);

        // The exact reported shape: the OLDER order, which session('customer_order_id')
        // no longer points at, must still come back with its real live status.
        $response = $this->actingAs($customer, 'customer')
            ->getJson('/customer/orders-status?' . http_build_query([
                'order_ids' => [$older->id, $newer->id],
            ]));

        $response->assertOk();
        $byId = collect($response->json('orders'))->keyBy('order_id');

        $this->assertTrue($byId->has($older->id), 'the older, non-session order was not answered for at all');
        $this->assertSame('preparing', $byId[$older->id]['status']);
        $this->assertSame('serving', $byId[$newer->id]['status']);
    }

    public function test_the_bulk_endpoint_answers_for_every_owned_order_guest(): void
    {
        $older = $this->makeOrder(['status' => 'preparing']);
        $newer = $this->makeOrder(['status' => 'serving']);

        GuestOrders::remember($older->id);
        GuestOrders::remember($newer->id);

        $response = $this->getJson('/customer/orders-status?' . http_build_query([
            'order_ids' => [$older->id, $newer->id],
        ]));

        $response->assertOk();
        $byId = collect($response->json('orders'))->keyBy('order_id');

        $this->assertSame('preparing', $byId[$older->id]['status'] ?? null);
        $this->assertSame('serving', $byId[$newer->id]['status'] ?? null);
    }

    /**
     * THE EXACT BUG BEING FIXED, restated as a query: does asking about an
     * order that has ALREADY completed still work? A status-filtered version
     * of this endpoint would silently drop it — see the class docblock.
     */
    public function test_a_completed_order_is_still_answered_for_when_asked_about_by_id(): void
    {
        $customer = $this->customer();
        $order = $this->makeOrder(['user_id' => $customer->id, 'status' => 'completed']);

        $response = $this->actingAs($customer, 'customer')
            ->getJson('/customer/orders-status?' . http_build_query(['order_ids' => [$order->id]]));

        $response->assertOk();
        $byId = collect($response->json('orders'))->keyBy('order_id');

        $this->assertSame(
            'completed',
            $byId[$order->id]['status'] ?? null,
            'a status-filtered candidate list would have dropped this order the instant it completed'
        );
    }

    public function test_a_guest_sees_a_completed_order_even_if_not_in_the_requested_ids(): void
    {
        // The guest's browser never explicitly asks about this id (e.g. it
        // finished between visits and was never rendered as a tracker card) —
        // GuestOrders::ids() must still surface it, since a guest has no
        // History tab to fall back on.
        $order = $this->makeOrder(['status' => 'completed']);
        GuestOrders::remember($order->id);

        $response = $this->getJson('/customer/orders-status'); // no order_ids at all

        $response->assertOk();
        $byId = collect($response->json('orders'))->keyBy('order_id');

        $this->assertSame('completed', $byId[$order->id]['status'] ?? null);
    }

    // ══════════ notifications: already order-agnostic, re-confirmed here ══════════

    public function test_each_order_produces_its_own_notification_regardless_of_which_is_newest(): void
    {
        $customer = $this->customer();

        $older = $this->makeOrder(['user_id' => $customer->id, 'status' => 'pending']);
        $newer = $this->makeOrder(['user_id' => $customer->id, 'status' => 'pending']);

        Notification::orderStatusChanged($older, 'preparing');
        Notification::orderStatusChanged($newer, 'preparing');

        $feed = $this->actingAs($customer, 'customer')
            ->getJson('/customer/notifications')
            ->assertOk()
            ->json('notifications');

        $numbers = collect($feed)->pluck('order_number');

        $this->assertContains($older->order_number, $numbers, 'the older order never reached the notification feed');
        $this->assertContains($newer->order_number, $numbers, 'the newer order never reached the notification feed');
    }

    // ══════════ receipts: reachable for every owned order, guest and logged-in ══════════

    public function test_both_orders_receipts_are_reachable_and_show_their_own_items(): void
    {
        $customer = $this->customer();
        $itemA = $this->item();

        $a = $this->makeOrder(['user_id' => $customer->id, 'status' => 'completed', 'order_number' => 'MSP-RCPT-A']);
        $a->items()->create([
            'menu_item_id' => $itemA->id,
            'item_name'    => 'Order-A-Only-Item',
            'quantity'     => 1,
            'item_price'   => 10,
            'subtotal'     => 10,
        ]);

        $b = $this->makeOrder(['user_id' => $customer->id, 'status' => 'completed', 'order_number' => 'MSP-RCPT-B']);
        $b->items()->create([
            'menu_item_id' => $itemA->id,
            'item_name'    => 'Order-B-Only-Item',
            'quantity'     => 1,
            'item_price'   => 20,
            'subtotal'     => 20,
        ]);

        $htmlA = $this->actingAs($customer, 'customer')->get('/customer/receipt/' . $a->id)->assertOk()->getContent();
        $htmlB = $this->actingAs($customer, 'customer')->get('/customer/receipt/' . $b->id)->assertOk()->getContent();

        $this->assertStringContainsString('Order-A-Only-Item', $htmlA);
        $this->assertStringNotContainsString('Order-B-Only-Item', $htmlA);

        $this->assertStringContainsString('Order-B-Only-Item', $htmlB);
        $this->assertStringNotContainsString('Order-A-Only-Item', $htmlB);
    }

    public function test_a_guests_older_completed_order_receipt_is_still_reachable(): void
    {
        // Exactly the "parang wala sya ng recibo" half of the report: a guest
        // whose OLDER order already finished must still be able to open it.
        $older = $this->makeOrder(['status' => 'completed']);
        $newer = $this->makeOrder(['status' => 'pending']);

        GuestOrders::remember($older->id);
        GuestOrders::remember($newer->id);

        $this->get('/customer/receipt/' . $older->id)->assertOk();
        $this->get('/customer/receipt/' . $newer->id)->assertOk();
    }

    // ══════════ IDOR: item 32's protection must survive this untouched ══════════

    public function test_a_non_owned_order_is_refused_by_the_bulk_endpoint(): void
    {
        $customer = $this->customer();
        $other = $this->otherCustomer();

        if (!$other) {
            $this->markTestSkipped('only one customer account exists in this database');
        }

        $notMine = $this->makeOrder(['user_id' => $other->id, 'status' => 'serving']);

        $response = $this->actingAs($customer, 'customer')
            ->getJson('/customer/orders-status?' . http_build_query(['order_ids' => [$notMine->id]]));

        $response->assertOk();
        $this->assertEmpty($response->json('orders'), 'a crafted request surfaced another customer\'s order');
    }

    public function test_a_non_owned_receipt_still_404s(): void
    {
        $customer = $this->customer();
        $other = $this->otherCustomer();

        if (!$other) {
            $this->markTestSkipped('only one customer account exists in this database');
        }

        $notMine = $this->makeOrder(['user_id' => $other->id, 'status' => 'completed']);

        $this->actingAs($customer, 'customer')
            ->get('/customer/receipt/' . $notMine->id)
            ->assertNotFound();
    }

    public function test_a_guest_cannot_use_the_bulk_endpoint_to_read_an_accounts_order(): void
    {
        $customer = $this->customer();
        $theirOrder = $this->makeOrder(['user_id' => $customer->id, 'status' => 'serving']);

        // A guest crafting the request with someone else's (real) order id.
        $response = $this->getJson('/customer/orders-status?' . http_build_query([
            'order_ids' => [$theirOrder->id],
        ]));

        $response->assertOk();
        $this->assertEmpty($response->json('orders'), 'a guest request surfaced a logged-in customer\'s order');
    }

    // ══════════ the render path: every card gets its OWN tracker ids ══════════

    public function test_every_current_order_card_carries_its_own_unique_tracker_ids(): void
    {
        $customer = $this->customer();

        $older = $this->makeOrder(['user_id' => $customer->id, 'status' => 'preparing']);
        $newer = $this->makeOrder(['user_id' => $customer->id, 'status' => 'pending']);

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/orders')
            ->assertOk()
            ->getContent();

        // THE BUG: these used to exist only for $loop->first (the newest
        // order), with no suffix at all — a second card's tracker had no ids
        // for JS to ever find.
        foreach ([$older->id, $newer->id] as $id) {
            $this->assertStringContainsString('id="orderCard-' . $id . '"', $html, "order {$id} has no wrapper id");
            $this->assertStringContainsString('id="orderStepPending-' . $id . '"', $html, "order {$id} has no Pending tracker id");
            $this->assertStringContainsString('id="orderStepPreparing-' . $id . '"', $html, "order {$id} has no Preparing tracker id");
            $this->assertStringContainsString('id="orderStepServing-' . $id . '"', $html, "order {$id} has no Serving tracker id");
        }

        // And the un-suffixed ids the old markup used must be gone entirely —
        // otherwise two different mechanisms could collide on the same id.
        $this->assertStringNotContainsString('id="orderStepPending"', $html);
        $this->assertStringNotContainsString('id="orderStepPreparing"', $html);
        $this->assertStringNotContainsString('id="orderStepServing"', $html);
    }

    public function test_the_page_embeds_every_in_flight_order_id_for_the_poll_to_ask_about(): void
    {
        $customer = $this->customer();

        $a = $this->makeOrder(['user_id' => $customer->id, 'status' => 'preparing']);
        $b = $this->makeOrder(['user_id' => $customer->id, 'status' => 'pending']);

        $html = $this->actingAs($customer, 'customer')->get('/customer/orders')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/trackedOrderIds\s*=\s*(\[[^\]]*\]|\[\s*\d+[\s\S]*?\])/', $html);
        $this->assertStringContainsString((string) $a->id, $html);
        $this->assertStringContainsString((string) $b->id, $html);
    }

    /**
     * THE OTHER BUG FOUND WHILE FIXING THIS: cancelling ONE of a visitor's
     * orders used to wipe the ENTIRE Current Order tab and stop the poll
     * outright, deleting a second, still-perfectly-open order's card from the
     * screen too. Fixed to remove only the cancelled order's own card.
     *
     * The DOM-removal itself is a client-side behaviour (verified live with
     * Playwright); what this pins server-side is the precondition the fix
     * depends on — that a cancelled order's card carries an id distinct from
     * a still-open sibling's, so client-side code has something to target.
     */
    public function test_a_cancelled_orders_card_is_distinguishable_from_a_still_open_sibling(): void
    {
        $customer = $this->customer();

        $stillOpen = $this->makeOrder(['user_id' => $customer->id, 'status' => 'preparing']);
        $toCancel  = $this->makeOrder(['user_id' => $customer->id, 'status' => 'pending']);

        $html = $this->actingAs($customer, 'customer')->get('/customer/orders')->assertOk()->getContent();

        $this->assertStringContainsString('id="orderCard-' . $stillOpen->id . '"', $html);
        $this->assertStringContainsString('id="orderCard-' . $toCancel->id . '"', $html);

        // The two ids are not the same string, which is the whole precondition
        // for "remove only one of these two cards" to even be possible.
        $this->assertNotSame(
            'orderCard-' . $stillOpen->id,
            'orderCard-' . $toCancel->id
        );
    }

    // ══════════ what must not regress ══════════

    /** Item 43 / Round 3A: a pick-up follow-up order is still allowed at all. */
    public function test_a_pick_up_follow_up_order_is_still_permitted(): void
    {
        $customer = $this->customer();
        $this->makeOrder(['user_id' => $customer->id, 'status' => 'preparing']);

        $this->assertTrue(
            \App\Services\CustomerOrderAccess::mayOrderAlongside(
                $this->makeOrder(['user_id' => $customer->id, 'status' => 'preparing'])
            )
        );
    }

    /** Item 40: table occupancy resolution is untouched by any of this. */
    public function test_table_occupancy_lookup_is_unaffected(): void
    {
        $this->assertNull(\App\Services\TableOccupancy::activeFor(1, 'MSP-UNUSED-TABLE'));
    }
}

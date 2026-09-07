<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The Confirm Your Order modal: no forced wait, and a real double-submit
 * still produces exactly one order.
 *
 * WHY THE COUNTDOWN WAS STILL SHOWING
 * ------------------------------------
 * An earlier round added the item-by-item order review to this modal (the
 * comment still in cart.blade.php next to it says as much: "The heading above
 * told customers to 'review your items' while the modal showed nothing but a
 * countdown") but left the countdown timer and the button's forced-disabled
 * state completely untouched. So the modal gained a real review AND kept the
 * 5-second "Please wait..." lock on top of it — the review was added
 * alongside the bug, not instead of it. This was not a caching issue: the
 * countdown markup and JS were still genuinely in the source.
 *
 * THIS PASS
 * ---------
 * Removed the countdown markup, the setInterval loop, and the disabled
 * attribute — the button now reads "Place Order" and is usable the instant
 * the modal opens. The item review (items, quantities, subtotal, total,
 * cancel option) is untouched.
 *
 * Removing the countdown took away an ACCIDENTAL double-submit guard: while
 * the button was disabled for 5 whole seconds, there was no window for a
 * second POST to happen. What replaces it deliberately:
 *
 *   - client-side: confirmOrderNow() already disabled the button
 *     synchronously on click, before submitting the form — a real double
 *     click on the rendered page cannot fire two submits, because JS runs
 *     single-threaded and the second call sees the button already disabled.
 *   - server-side (new): OrderController::placeOrder() takes a short-lived,
 *     non-blocking Cache::lock() keyed on the same visitor identity
 *     RateLimitServiceProvider already uses, held only for the duration of
 *     one request and always released in a finally block. This is what
 *     catches a request that reaches the server twice some OTHER way — two
 *     browser tabs, a back-button resubmission, a flaky retry — none of
 *     which the client-side guard can see.
 */
class OrderConfirmModalTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function item(): MenuItem
    {
        return MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->firstOrFail();
    }

    private function cartFor(MenuItem $item, int $qty = 2): array
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

    // ══════════════════════════════════════════════════════════════════
    // 1. No forced wait
    // ══════════════════════════════════════════════════════════════════

    /**
     * The rendered page must not ship a countdown or a disabled confirm
     * button at all — not "disabled until JS enables it after 5 seconds",
     * genuinely absent from the markup the server sends.
     */
    public function test_the_confirm_button_ships_enabled_with_no_countdown(): void
    {
        $customer = $this->customer();
        $item = $this->item();

        $html = $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $this->cartFor($item), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/cart')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'orderCountdown',
            $html,
            'the countdown element is still present in the rendered page'
        );
        $this->assertStringNotContainsString(
            'Please wait for the countdown',
            $html
        );
        $this->assertStringNotContainsString(
            'Please wait...',
            $html,
            'the confirm button still ships with its old disabled label'
        );

        // The confirm button itself: present, not disabled, reads "Place Order".
        $this->assertMatchesRegularExpression(
            '/id="confirmOrderNow"[^>]*onclick="confirmOrderNow\(\)"[^>]*>\s*Place Order/s',
            $html,
            'the confirm button is not rendered as an immediately-usable "Place Order" button'
        );

        // Explicitly: the `disabled` attribute must not sit on this button.
        $this->assertDoesNotMatchRegularExpression(
            '/id="confirmOrderNow"[^>]*disabled/s',
            $html,
            'the confirm button still ships with the disabled attribute'
        );

        // The order review this modal is FOR must still be there — the fix is
        // removing the artificial wait, not the thing the customer is
        // actually meant to be looking at.
        $this->assertStringContainsString('id="orderReview"', $html);
        $this->assertStringContainsString($item->name, $html);
        $this->assertStringContainsString('Cancel', $html);
    }

    /**
     * A real order, placed in a single request, with no artificial delay
     * anywhere server-side to wait out. There never was a server-side timer —
     * this pins that placing an order still works end to end after the
     * modal-side change, not a claim about frontend timing PHPUnit cannot see.
     */
    public function test_placing_an_order_completes_immediately_in_one_request(): void
    {
        $customer = $this->customer();
        $item = $this->item();

        $before = (int) Order::max('id');

        $response = $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $this->cartFor($item), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 2]],
            ]);

        $response->assertSessionHasNoErrors();

        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();
        $this->assertNotNull($order, 'the order was not created in a single request');
        $this->assertSame('pending', $order->status);
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. A rapid double-submit still results in exactly ONE order
    // ══════════════════════════════════════════════════════════════════

    /**
     * The real race: a second request arrives while the first is still being
     * processed.
     *
     * PHPUnit cannot run two HTTP requests on two real threads, so — matching
     * this codebase's own established technique for testing the analogous
     * claim-spend race (GuestVoucherClaimTest::
     * test_a_race_on_one_claim_spends_it_only_once, which performs both
     * conditional UPDATEs directly rather than truly threading them) — the
     * "first request is mid-flight" state is produced directly: acquire the
     * SAME lock placeOrder() acquires, for the SAME visitor, before making the
     * real HTTP call. That is exactly the state the lock exists to detect.
     */
    public function test_a_request_arriving_while_another_is_in_flight_creates_no_order(): void
    {
        $customer = $this->customer();
        $item = $this->item();

        $before = (int) Order::max('id');

        /*
         * Simulate "request 1 is mid-flight": acquire the exact lock
         * placeOrder() would acquire for this visitor. The key format
         * ('customer:{id}') matches RateLimitServiceProvider::visitorKey()'s
         * signed-in branch exactly — see
         * test_the_lock_reuses_the_shared_visitor_key_function() below, which
         * pins that placeOrder() calls that function rather than a second
         * definition this test's assumption could silently drift from.
         */
        $inFlightLock = Cache::lock('place-order-inflight:customer:' . $customer->id, 20);
        $this->assertTrue($inFlightLock->get(), 'setup: could not simulate an in-flight request');

        // The "second" request: a real POST, while the lock above is held.
        $response = $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $this->cartFor($item), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 2]],
            ]);

        $this->assertSame(
            0,
            Order::where('id', '>', $before)->count(),
            'a request arriving mid-flight created an order anyway'
        );
        $response->assertSessionHasErrors('error');

        $inFlightLock->release();

        // CONTROL: once the "in-flight" request is done and the lock is
        // released, the SAME visitor placing a genuine order works normally.
        // Without this, the refusal above could just as easily mean the
        // endpoint is broken for everyone.
        $again = $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $this->cartFor($item), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 2]],
            ]);

        $again->assertSessionHasNoErrors();
        $this->assertSame(
            1,
            Order::where('id', '>', $before)->count(),
            'CONTROL FAILED: the same visitor could not place an order once the lock cleared'
        );
    }

    /**
     * The lock must not survive past the request that acquired it. A second,
     * LATER order from the same visitor — the ordinary "second round" case —
     * must never be blocked by a lock left over from an earlier, already-
     * finished request.
     */
    public function test_the_lock_does_not_survive_a_completed_request(): void
    {
        $customer = $this->customer();
        $item = $this->item();

        $before = (int) Order::max('id');

        // A genuine, complete first order.
        $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $this->cartFor($item), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            Order::where('id', '>', $before)->count(),
            'CONTROL: the first order should have been created'
        );

        // A second, independent order request from the SAME visitor,
        // immediately afterwards. Pick-up customers are explicitly allowed to
        // have more than one order in flight (CustomerOrderAccess::
        // mayOrderAlongside) — this is the "remembered I wanted food too"
        // case, not a duplicate.
        $second = $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $this->cartFor($item), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ]);

        $second->assertSessionHasNoErrors();
        $this->assertSame(
            2,
            Order::where('id', '>', $before)->count(),
            'a legitimate second order was blocked by a lock the first, completed request should have released'
        );
    }

    /**
     * A validation failure (no cart) must not hold the lock either — a
     * customer who fixes the problem and resubmits immediately must not be
     * wrongly blocked.
     */
    public function test_a_failed_attempt_does_not_hold_the_lock(): void
    {
        $customer = $this->customer();
        $item = $this->item();

        // First attempt: empty cart, fails validation-style (not a real order).
        $this->actingAs($customer, 'customer')
            ->withSession(['cart' => [], 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('items');

        $before = (int) Order::max('id');

        // Second attempt, immediately after: cart now has something in it.
        $second = $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $this->cartFor($item), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ]);

        $second->assertSessionHasNoErrors();
        $this->assertSame(
            1,
            Order::where('id', '>', $before)->count(),
            'a failed first attempt held the lock and blocked the corrected resubmission'
        );
    }

    /**
     * The lock is keyed on visitor identity — the same function
     * RateLimitServiceProvider already uses — so a DIFFERENT visitor's
     * in-flight request never blocks this one.
     */
    public function test_a_different_visitors_in_flight_lock_does_not_block_this_one(): void
    {
        $customer = $this->customer();
        $other = User::where('role', 'customer')->where('id', '!=', $customer->id)->first();

        if (!$other) {
            $this->markTestSkipped('needs two distinct customer accounts');
        }

        $item = $this->item();

        $otherLock = Cache::lock('place-order-inflight:customer:' . $other->id, 20);
        $this->assertTrue($otherLock->get());

        $before = (int) Order::max('id');

        $response = $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $this->cartFor($item), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(
            1,
            Order::where('id', '>', $before)->count(),
            "a different visitor's in-flight lock blocked an unrelated order"
        );

        $otherLock->release();
    }

    /**
     * The visitor key used by the lock is the SAME function
     * RateLimitServiceProvider already exposes, not a second, parallel
     * definition of "who is this" that could drift from it.
     */
    public function test_the_lock_reuses_the_shared_visitor_key_function(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Customer/OrderController.php'));

        $this->assertStringContainsString(
            'RateLimitServiceProvider::visitorKey(',
            $source,
            'placeOrder() should reuse the same visitor-identity function RateLimitServiceProvider uses, '
            . 'not a second, parallel definition of "who is this" that could drift from it'
        );
    }
}

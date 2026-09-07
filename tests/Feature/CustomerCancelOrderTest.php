<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Customer self-service order cancellation (2026-09-02).
 *
 * WHAT THIS EXISTS FOR
 * --------------------
 * Confirmed live: after placing a Pick Up or Dine In order there was no way
 * for a customer to cancel it themselves. A cancelCustomerOrder() endpoint
 * did already exist, but the only thing in the whole UI that reached it was
 * the PWD/Senior discount-rejection popup — so a customer with an ordinary
 * cash order never saw a cancel affordance anywhere.
 *
 * The endpoint is now shared by that popup and a general "Cancel Order"
 * button, which is why these tests hit the route directly rather than
 * through either caller: the route is the thing both depend on, and it is
 * one guessable integer away from anyone who wants to POST at it.
 *
 * THE RULES BEING PINNED
 * ----------------------
 * 1. Pending only. Once staff are cooking, the customer does not get to
 *    withdraw the order unilaterally.
 * 2. Your own order only, for signed-in customers and guests alike.
 * 3. Money. A GCash order cancelled AFTER "I have paid" may involve real
 *    money that already moved, and there is no merchant API here to reverse
 *    it — so it must land in a distinct refund_pending state AND raise a
 *    staff notification. Cancelled before that tap, nothing moved, so it is
 *    an ordinary cancellation.
 */
class CustomerCancelOrderTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): User
    {
        return User::where('email', 'pedro@gmail.com')->firstOrFail();
    }

    private function otherCustomer(): User
    {
        return User::where('email', '!=', 'pedro@gmail.com')
            ->where('role', 'customer')
            ->firstOrFail();
    }

    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'CX-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'table_number'   => null,
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 250,
            'total'          => 250,
        ], $attrs));
    }

    private function cancel(Order $order)
    {
        return $this->post('/customer/orders/' . $order->id . '/cancel');
    }

    // ══════════ 1. the plain cases ══════════

    public function test_a_pending_cash_order_is_cancelled_by_its_owner(): void
    {
        $customer = $this->customer();
        $order = $this->makeOrder(['user_id' => $customer->id]);

        $this->actingAs($customer, 'customer');

        $this->cancel($order)->assertRedirect(route('customer.orders'));

        $order->refresh();

        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($order->cancelled_at, 'a cancellation should be timestamped');

        // No money was ever claimed, so nothing is owed back.
        $this->assertSame(
            'pending',
            $order->payment_status,
            'a cash cancellation must not invent a refund obligation'
        );
    }

    public function test_a_dine_in_order_can_also_be_cancelled(): void
    {
        // Both order types were named in the report; pick-up is covered above.
        $customer = $this->customer();
        $order = $this->makeOrder([
            'user_id'      => $customer->id,
            'type'         => 'dine_in',
            'table_number' => '7',
        ]);

        $this->actingAs($customer, 'customer');
        $this->cancel($order);

        $this->assertSame('cancelled', $order->refresh()->status);
    }

    public function test_a_pending_gcash_order_with_no_payment_claim_cancels_plainly(): void
    {
        $customer = $this->customer();

        // payment_status 'pending' = the customer never tapped "I have paid".
        $order = $this->makeOrder([
            'user_id'        => $customer->id,
            'payment_method' => 'gcash',
            'payment_status' => 'pending',
        ]);

        $before = Notification::max('id');

        $this->actingAs($customer, 'customer');
        $this->cancel($order);

        $order->refresh();

        $this->assertSame('cancelled', $order->status);
        $this->assertSame(
            'pending',
            $order->payment_status,
            'no money was claimed, so this must not become a refund'
        );

        $this->assertSame(
            0,
            Notification::where('id', '>', $before)->where('type', 'refund_pending')->count(),
            'staff must not be sent chasing a refund for money that was never sent'
        );
    }

    // ══════════ 2. the money case ══════════

    public function test_a_gcash_order_cancelled_after_i_have_paid_becomes_refund_pending(): void
    {
        $customer = $this->customer();

        $order = $this->makeOrder([
            'user_id'        => $customer->id,
            'payment_method' => 'gcash',
            'payment_status' => 'awaiting_verification',
        ]);

        $before = Notification::max('id');

        $this->actingAs($customer, 'customer');
        $this->cancel($order);

        $order->refresh();

        $this->assertSame('cancelled', $order->status);
        $this->assertSame(
            'refund_pending',
            $order->payment_status,
            'a cancellation of money the customer says they already sent must be '
            . 'distinguishable from an ordinary one, or the refund never happens'
        );

        // The reason has to say why, for whoever reads this row later.
        $this->assertStringContainsString('Refund', $order->cancellation_reason);
    }

    public function test_the_refund_case_raises_an_immediate_staff_notification(): void
    {
        $customer = $this->customer();

        $order = $this->makeOrder([
            'user_id'        => $customer->id,
            'payment_method' => 'gcash',
            'payment_status' => 'awaiting_verification',
        ]);

        $before = Notification::max('id');

        $this->actingAs($customer, 'customer');
        $this->cancel($order);

        $notification = Notification::where('id', '>', $before)
            ->where('type', 'refund_pending')
            ->where('order_id', $order->id)
            ->first();

        $this->assertNotNull(
            $notification,
            'nothing told staff a refund was owed — the customer is simply out of pocket'
        );

        $this->assertSame(
            Notification::AUDIENCE_STAFF,
            $notification->audience,
            'a refund is staff work, not a customer-facing message'
        );

        // Reusing the same delivery path as the existing "I have paid"
        // notification is the point — it is what staff already watch.
        $this->assertStringContainsString($order->order_number, $notification->message);
    }

    public function test_cancelling_twice_does_not_raise_a_second_refund_notification(): void
    {
        $customer = $this->customer();

        $order = $this->makeOrder([
            'user_id'        => $customer->id,
            'payment_method' => 'gcash',
            'payment_status' => 'awaiting_verification',
        ]);

        $before = Notification::max('id');

        $this->actingAs($customer, 'customer');

        $this->cancel($order);
        // The second press is refused by the pending-only rule, which is also
        // what stops staff being told twice to refund the same order.
        $this->cancel($order);

        $this->assertSame(
            1,
            Notification::where('id', '>', $before)->where('type', 'refund_pending')->count(),
            'a repeated cancel must not duplicate the refund request'
        );
    }

    // ══════════ 3. the pending-only rule, server-side ══════════

    public static function uncancellableStatuses(): array
    {
        return [
            'preparing' => ['preparing'],
            'serving'   => ['serving'],
            'completed' => ['completed'],
        ];
    }

    /**
     * @dataProvider uncancellableStatuses
     */
    public function test_the_customer_cannot_cancel_once_it_is_past_pending(string $status): void
    {
        $customer = $this->customer();
        $order = $this->makeOrder(['user_id' => $customer->id, 'status' => $status]);

        $this->actingAs($customer, 'customer');

        // Posted straight at the route, exactly as someone bypassing the UI
        // would — the button not being drawn is not the control.
        $this->cancel($order);

        $this->assertSame(
            $status,
            $order->refresh()->status,
            "an order being '{$status}' was cancelled anyway — food and staff time "
            . 'have already been spent by this point'
        );
    }

    public function test_the_refusal_is_reported_rather_than_silently_ignored(): void
    {
        $customer = $this->customer();
        $order = $this->makeOrder(['user_id' => $customer->id, 'status' => 'preparing']);

        $this->actingAs($customer, 'customer');

        $this->cancel($order)->assertSessionHasErrors('cancel');
    }

    // ══════════ 4. ownership ══════════

    public function test_a_customer_cannot_cancel_someone_elses_order(): void
    {
        $victim = $this->customer();
        $attacker = $this->otherCustomer();

        $order = $this->makeOrder(['user_id' => $victim->id]);

        $this->actingAs($attacker, 'customer');

        // 404, not a redirect: an order that is not yours should not exist as
        // far as you are concerned, and the same answer for "not yours" and
        // "no such id" is what stops the endpoint being used to enumerate
        // which ids are real. ReceiptAccessTest pins the same contract from
        // the other direction.
        $this->cancel($order)->assertNotFound();

        $this->assertSame(
            'pending',
            $order->refresh()->status,
            "one customer cancelled another customer's order"
        );
    }

    public function test_a_guest_cannot_cancel_an_order_their_session_never_placed(): void
    {
        $victim = $this->customer();
        $order = $this->makeOrder(['user_id' => $victim->id]);

        // A guest session that has placed nothing at all.
        $this->cancel($order)->assertNotFound();

        $this->assertSame('pending', $order->refresh()->status);
    }

    public function test_a_guest_can_cancel_the_order_their_own_session_placed(): void
    {
        // The mirror of the test above: the guest rule has to actually let the
        // real owner through, or dine-in QR customers lose the feature.
        $order = $this->makeOrder(['user_id' => null, 'type' => 'dine_in', 'table_number' => '4']);

        $this->withSession([GuestOrders::KEY => [$order->id]]);
        $this->cancel($order);

        $this->assertSame('cancelled', $order->refresh()->status);
    }

    // ══════════ 5. the admin side ══════════

    public function test_staff_see_a_refund_pending_order_on_the_live_board(): void
    {
        $order = $this->makeOrder([
            'user_id'        => $this->customer()->id,
            'status'         => 'cancelled',
            'payment_method' => 'gcash',
            'payment_status' => 'refund_pending',
            'cancelled_at'   => now(),
        ]);

        $html = $this->actingAs($this->staff(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->getContent();

        // It must be findable on the screen staff actually work from. A
        // cancelled order is excluded from Active Orders, so without its own
        // band this would appear nowhere but the archive.
        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringContainsString('Refunds Owed', $html);
    }

    public function test_staff_can_resolve_a_refund_pending_order(): void
    {
        $order = $this->makeOrder([
            'user_id'        => $this->customer()->id,
            'status'         => 'cancelled',
            'payment_method' => 'gcash',
            'payment_status' => 'refund_pending',
            'cancelled_at'   => now(),
        ]);

        $this->actingAs($this->staff(), 'admin')
            ->put('/admin/orders/' . $order->id . '/payment/refunded');

        $order->refresh();

        $this->assertSame('refunded', $order->payment_status);

        // Resolving the refund must not resurrect the order.
        $this->assertSame(
            'cancelled',
            $order->status,
            'marking the money returned should settle the payment, not revive the order'
        );
    }

    public function test_a_resolved_refund_leaves_the_live_board(): void
    {
        $order = $this->makeOrder([
            'user_id'        => $this->customer()->id,
            'status'         => 'cancelled',
            'payment_method' => 'gcash',
            'payment_status' => 'refunded',
            'cancelled_at'   => now(),
        ]);

        $html = $this->actingAs($this->staff(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            $order->order_number,
            $html,
            'an already-refunded order should stop showing as outstanding work'
        );
    }

    public function test_the_resolve_action_refuses_an_order_that_owes_nothing(): void
    {
        // Guards against a stray PUT quietly rewriting the money state of an
        // ordinary paid order.
        $order = $this->makeOrder([
            'user_id'        => $this->customer()->id,
            'payment_method' => 'gcash',
            'payment_status' => 'paid',
        ]);

        $this->actingAs($this->staff(), 'admin')
            ->put('/admin/orders/' . $order->id . '/payment/refunded');

        $this->assertSame('paid', $order->refresh()->payment_status);
    }

    public function test_a_customer_cannot_reach_the_staff_resolve_action(): void
    {
        $order = $this->makeOrder([
            'user_id'        => $this->customer()->id,
            'status'         => 'cancelled',
            'payment_method' => 'gcash',
            'payment_status' => 'refund_pending',
            'cancelled_at'   => now(),
        ]);

        $this->actingAs($this->customer(), 'customer')
            ->put('/admin/orders/' . $order->id . '/payment/refunded');

        $this->assertSame(
            'refund_pending',
            $order->refresh()->payment_status,
            'a customer marked their own refund as already sent'
        );
    }

    // ══════════ 6. the button itself ══════════

    public function test_the_orders_page_offers_cancel_only_while_pending(): void
    {
        $customer = $this->customer();
        $order = $this->makeOrder(['user_id' => $customer->id, 'status' => 'pending']);

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/orders')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('customer.orders.cancel', $order->id),
            $html,
            'a pending order gave the customer no way to cancel — the reported bug'
        );

        // Matching the cart's Remove button: confirm before it takes effect.
        $this->assertMatchesRegularExpression('/onsubmit="return confirm\(/', $html);

        $order->update(['status' => 'preparing']);

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/orders')
            ->assertOk()
            ->getContent();

        /*
         * UPDATED 2026-09-02, deliberately — not a silent weakening.
         *
         * This asserted the cancel route was entirely ABSENT once an order
         * left pending. That was correct at the time it was written and
         * stayed correct until this order type's presentation was
         * intentionally changed: this file's makeOrder() defaults to
         * 'pick_up' (see above), and a pick-up order in 'preparing' now
         * renders the SAME control disabled-in-place, with an explanatory
         * reason, instead of removing it — see
         * CancelControlStaysVisibleDisabledTest for the full history of that
         * change and why. Dine-in orders still render nothing at all past
         * pending, which is what the old assertion here actually described
         * for every type; it just happened to also describe pick-up until
         * now.
         *
         * What still has to hold, and is asserted here in its place: the
         * route may appear in the markup (the form's own action="..."), but
         * the button controlling it must be disabled — i.e. the guarantee
         * this test exists for ("cancellation was still offered after the
         * kitchen had started") is preserved as "not clickable", not as
         * "not present".
         */
        $this->assertMatchesRegularExpression(
            '/<button[^>]* disabled/',
            $html,
            'a preparing pick-up order must render its cancel button disabled, not clickable'
        );
        $this->assertStringContainsString(
            'this order can no longer be cancelled',
            $html,
            'the disabled control must explain why, not just be disabled silently'
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A refused cancel must be honest: the order unchanged, and the customer
 * actually told why (2026-09-02).
 *
 * WHAT REPRODUCED, AND WHAT DID NOT
 * ---------------------------------
 * Reproduced directly against the endpoint. The SERVER side was already
 * correct and is unchanged by this pass — for a non-pending order it refuses,
 * leaves the row untouched, and returns the reason in both shapes the page
 * can send:
 *
 *   form POST -> 302 back to /customer/orders, error flashed
 *                ("This order can no longer be cancelled."), and that message
 *                really does render on the reloaded page.
 *   JSON POST -> 422 {"success":false,"message":"This order can no longer be
 *                cancelled."}
 *
 * So "said nothing" did NOT reproduce on the main Cancel Order control, and
 * no server change was invented for it. What was genuinely wrong, and is
 * fixed here, is on the client:
 *
 *   1. The main Cancel Order button's visibility is decided ONCE, server-side
 *      (@if status === 'pending'), and was never re-evaluated. Combined with
 *      the stale-page bug in OrderStatusStaleDisplayTest — where the poll
 *      discarded status updates entirely — a page left open kept offering a
 *      cancel for an order staff had already started. That is how a customer
 *      reaches a cancel that can only ever be refused. The poll now withdraws
 *      the control as soon as the order stops being pending.
 *
 *   2. The discount-modal cancel path dimmed and un-clicked its control
 *      BEFORE the request was sent, so the UI reacted to a cancellation the
 *      server had not agreed to. Removed: nothing about the control changes
 *      until the server confirms. Double-submission is still prevented, by
 *      the cancelRequestInProgress flag — a guard on the action, not on the
 *      UI.
 *
 * Deliberately NOT changed: which statuses may be cancelled, and the shape of
 * the refusal. That is a separate decision.
 */
class RefusedCancelIsVisibleTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): User
    {
        return User::create([
            'name'      => 'Refused Cancel Test Customer',
            'email'     => 'refused-cancel-' . uniqid() . '@example.test',
            'password'  => Hash::make('irrelevant-' . uniqid()),
            'role'      => 'customer',
            'points'    => 0,
            'is_active' => true,
        ]);
    }

    private function order(User $user, string $status): Order
    {
        return Order::create([
            'order_number'   => 'RCT-' . substr(uniqid(), -8),
            'user_id'        => $user->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => $status,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 80,
            'total'          => 80,
        ]);
    }

    // ══════════ the refusal carries a message the UI can show ══════════

    /**
     * @dataProvider nonCancellableStatuses
     */
    public function test_a_refused_cancel_returns_a_readable_message_and_changes_nothing(string $status): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, $status);

        $response = $this->actingAs($customer, 'customer')
            ->postJson('/customer/orders/' . $order->id . '/cancel');

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);

        // The message itself, not just the status code — this is what the UI
        // has to be able to show the customer.
        $message = $response->json('message');
        $this->assertNotEmpty($message, 'a refusal must carry a message the UI can display');
        $this->assertStringContainsString('no longer be cancelled', $message);

        // The order is completely untouched.
        $order->refresh();
        $this->assertSame($status, $order->status);
        $this->assertNull($order->cancelled_at);
        $this->assertNull($order->cancellation_reason);
    }

    public static function nonCancellableStatuses(): array
    {
        return [
            'preparing' => ['preparing'],
            'serving'   => ['serving'],
            'completed' => ['completed'],
        ];
    }

    /**
     * The same refusal through the form POST the main Cancel Order button
     * actually sends — and the message must survive the redirect and reach
     * the rendered page, which is the only place the customer can read it.
     */
    public function test_the_refusal_message_reaches_the_rendered_page_after_a_form_post(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'preparing');

        $this->actingAs($customer, 'customer')
            ->from('/customer/orders')
            ->post('/customer/orders/' . $order->id . '/cancel')
            ->assertRedirect('/customer/orders');

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/orders')
            ->getContent();

        $this->assertStringContainsString(
            'This order can no longer be cancelled.',
            $html,
            'the customer was given no visible reason for the refused cancel'
        );

        $this->assertSame('preparing', $order->fresh()->status);
    }

    // ══════════ regression: a genuine cancel still works ══════════

    public function test_cancelling_a_genuinely_pending_order_still_works(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'pending');

        $this->actingAs($customer, 'customer')
            ->post('/customer/orders/' . $order->id . '/cancel')
            ->assertRedirect(route('customer.orders'));

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($order->cancelled_at);
    }

    // ══════════ the client must not react before the server agrees ══════════

    /**
     * The cancel control must not be dimmed or made unclickable on an
     * unconfirmed action — that is what made a refused cancel look like it
     * had worked.
     */
    public function test_the_cancel_control_is_not_disabled_before_the_server_confirms(): void
    {
        $source = file_get_contents(resource_path('views/customer/orders.blade.php'));

        $cancelFn = substr($source, strpos($source, 'window.cancelAfterDiscountDecision'));
        $cancelFn = substr($cancelFn, 0, strpos($cancelFn, 'const response = await fetch'));

        $this->assertStringNotContainsString(
            "actions.style.pointerEvents = 'none'",
            $cancelFn,
            'the cancel control is being disabled before the server has confirmed anything'
        );
        $this->assertStringNotContainsString(
            "actions.style.opacity = '0.6'",
            $cancelFn,
            'the cancel control is being dimmed before the server has confirmed anything'
        );
    }

    /**
     * A stale page must stop offering a cancel once the order is no longer
     * pending — the control is rendered from the status at page-load time and
     * is otherwise never re-evaluated.
     */
    public function test_the_poll_withdraws_the_cancel_control_once_the_order_is_no_longer_pending(): void
    {
        $source = file_get_contents(resource_path('views/customer/orders.blade.php'));

        $this->assertStringContainsString(
            'data-cancel-form="',
            $source,
            'the cancel form needs a hook the poll can find it by'
        );

        $fn = substr($source, strpos($source, 'function updateOrderProgress'));
        $this->assertStringContainsString(
            "if (status !== 'pending') {",
            $fn,
            'the poll must withdraw the cancel control once the order leaves pending'
        );
        $this->assertStringContainsString('cancelForm.remove();', $fn);
    }

    /**
     * The rendered page really does carry the hook, for a pending order.
     */
    public function test_a_pending_order_renders_the_cancel_hook(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'pending');

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/orders')
            ->getContent();

        $this->assertStringContainsString('data-cancel-form="' . $order->id . '"', $html);
    }
}

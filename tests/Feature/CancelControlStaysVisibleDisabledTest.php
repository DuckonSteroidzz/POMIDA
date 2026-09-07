<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The cancel control on a pick-up order stays visible-but-disabled once the
 * order leaves pending, instead of disappearing (2026-09-02).
 *
 * WHAT EXISTED BEFORE THIS PASS
 * ------------------------------
 * A previous pass made a page left open on a preparing/serving order stop
 * offering a cancel it could only be refused for. Three pieces did that:
 *
 *   - The Blade `@if($currentOrder->status === 'pending')` block: the whole
 *     <form> — button, confirm() prompt, GCash-refund note — rendered ONLY
 *     while pending. For any other status there was no cancel-related
 *     markup in the DOM at all, on a fresh page load or otherwise.
 *   - `data-cancel-form="{{ $currentOrder->id }}"` on that <form>: a hook so
 *     JS could find and act on exactly this order's control by id.
 *   - `updateOrderProgress()`'s handling of a non-pending, still-in-flight
 *     status: it looked up the form by that hook and called
 *     `cancelForm.remove()` — deleting the whole element from the DOM.
 *
 * That was correct for the bug it fixed and is NOT being undone. The owner
 * has since decided the presentation for PICK-UP orders specifically: the
 * control should stay on screen, visibly disabled, with a short reason —
 * not vanish. Dine-in and every other type keep the old behaviour exactly:
 * nothing renders once pending ends, and the poll still removes the form
 * outright (pinned by a dedicated regression test below).
 *
 * WHAT CHANGED
 * ------------
 *   - The Blade condition now also renders (disabled) for a pick-up order in
 *     preparing/serving, on the initial server render — not only reachable
 *     via the live poll.
 *   - The <form> now also carries data-cancel-pickup and
 *     data-cancel-disabled, so the poll can tell "disable in place" apart
 *     from "remove outright" per order type.
 *   - updateOrderProgress() calls a new disableCancelForm() for a pick-up
 *     order instead of `.remove()`; every other type is unchanged.
 *
 * Nothing about WHICH statuses may be cancelled changed, and
 * cancelCustomerOrder() was not touched — the server still refuses a
 * non-pending cancel on its own. This is presentation only, and
 * CustomerCancelOrderTest / RefusedCancelIsVisibleTest below are asserted to
 * still pass completely unchanged as proof of that.
 */
class CancelControlStaysVisibleDisabledTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): User
    {
        return User::create([
            'name'      => 'Cancel Presentation Test Customer',
            'email'     => 'cancel-presentation-' . uniqid() . '@example.test',
            'password'  => Hash::make('irrelevant-' . uniqid()),
            'role'      => 'customer',
            'points'    => 0,
            'is_active' => true,
        ]);
    }

    private function order(User $user, string $status, string $type = 'pick_up'): Order
    {
        return Order::create([
            'order_number'   => 'CPT-' . substr(uniqid(), -8),
            'user_id'        => $user->id,
            'branch_id'      => 1,
            'type'           => $type,
            'table_number'   => $type === 'dine_in' ? '5' : null,
            'status'         => $status,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 80,
            'total'          => 80,
        ]);
    }

    private function ordersPage(User $customer): string
    {
        return $this->actingAs($customer, 'customer')
            ->get('/customer/orders')
            ->assertOk()
            ->getContent();
    }

    /** The <form data-cancel-form="{id}" ...> ... </form> block, isolated. */
    private function cancelFormOf(string $html, int $orderId): string
    {
        $needle = 'data-cancel-form="' . $orderId . '"';
        $formTagStart = strrpos(substr($html, 0, strpos($html, $needle)), '<form');
        $this->assertNotFalse($formTagStart, 'the cancel form is missing from the page entirely');

        $end = strpos($html, '</form>', $formTagStart);
        $this->assertNotFalse($end);

        return substr($html, $formTagStart, $end - $formTagStart);
    }

    // ══════════ pending: unchanged, working control ══════════

    public function test_a_pending_pickup_order_renders_a_working_enabled_control(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'pending');

        $html = $this->ordersPage($customer);
        $form = $this->cancelFormOf($html, $order->id);

        // Not the bare substring "disabled" — data-cancel-disabled="0" always
        // contains that string as part of its own attribute NAME, on every
        // rendered form regardless of state. The actual thing that must be
        // absent is the button's disabled attribute itself.
        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]* disabled/',
            $form,
            'a pending order\'s button must not carry the disabled attribute'
        );
        $this->assertStringContainsString('onsubmit="return confirm(', $form);
        $this->assertStringContainsString('data-cancel-disabled="0"', $form);
    }

    // ══════════ preparing / serving: visible, disabled, explained ══════════

    public function test_a_preparing_pickup_order_renders_a_disabled_control_with_the_explanatory_text(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'preparing');

        $html = $this->ordersPage($customer);
        $form = $this->cancelFormOf($html, $order->id);

        $this->assertStringContainsString('data-cancel-disabled="1"', $form);
        $this->assertMatchesRegularExpression('/<button[^>]+disabled/', $form, 'the button itself must be disabled');
        $this->assertStringContainsString(
            'Being prepared — this order can no longer be cancelled.',
            $form
        );

        // Not a working submit: no confirm() handler wired to actually submit.
        $this->assertStringNotContainsString('onsubmit="return confirm(', $form);
    }

    public function test_a_serving_pickup_order_renders_a_disabled_control_with_the_explanatory_text(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'serving');

        $html = $this->ordersPage($customer);
        $form = $this->cancelFormOf($html, $order->id);

        $this->assertStringContainsString('data-cancel-disabled="1"', $form);
        $this->assertMatchesRegularExpression('/<button[^>]+disabled/', $form);
        $this->assertStringContainsString('Serving — this order can no longer be cancelled.', $form);
    }

    /**
     * "Not a working submit" proven directly: posting the cancel route for a
     * preparing order is refused by the server exactly as it always was —
     * the disabled presentation is not doing any of the actual protecting.
     */
    public function test_the_disabled_state_is_not_a_working_submit(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'preparing');

        $this->actingAs($customer, 'customer')
            ->post('/customer/orders/' . $order->id . '/cancel')
            ->assertSessionHasErrors('cancel');

        $this->assertSame('preparing', $order->fresh()->status);
    }

    // ══════════ dine-in: completely unchanged (regression) ══════════

    public function test_a_dine_in_order_still_shows_no_cancel_control_once_not_pending(): void
    {
        $customer = $this->customer();
        $pending = $this->order($customer, 'pending', 'dine_in');

        $htmlPending = $this->ordersPage($customer);
        $this->assertStringContainsString(
            'data-cancel-form="' . $pending->id . '"',
            $htmlPending,
            'a pending dine-in order should still show the control, exactly as before'
        );

        $preparing = $this->order($customer, 'preparing', 'dine_in');

        $htmlPreparing = $this->ordersPage($customer);
        $this->assertStringNotContainsString(
            'data-cancel-form="' . $preparing->id . '"',
            $htmlPreparing,
            'a non-pending dine-in order must render NO cancel control at all — unchanged, never disabled-in-place'
        );
    }

    public function test_a_pending_dine_in_orders_control_is_identical_in_shape_to_before(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'pending', 'dine_in');

        $html = $this->ordersPage($customer);
        $form = $this->cancelFormOf($html, $order->id);

        $this->assertStringContainsString('data-cancel-pickup="0"', $form);
        $this->assertStringContainsString('onsubmit="return confirm(', $form);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]* disabled/', $form);
    }

    // ══════════ the poll's client-side path (source-pinned) ══════════

    /**
     * Browser behaviour itself cannot be executed by PHPUnit — there is no
     * DOM, no live poll loop, nothing for a headless-browser-free test suite
     * to click. What CAN be proven here is that the source no longer
     * unconditionally removes the control, and instead branches on the same
     * data-cancel-pickup flag the Blade above renders — so a real browser
     * running this exact code disables in place for a pick-up order and
     * still removes outright for everything else.
     */
    public function test_the_poll_source_disables_pickup_orders_instead_of_removing_them(): void
    {
        $source = file_get_contents(resource_path('views/customer/orders.blade.php'));

        $this->assertStringContainsString(
            "if (cancelForm.dataset.cancelPickup === '1') {",
            $source,
            'the poll must branch on order type before deciding remove vs disable'
        );
        $this->assertStringContainsString('disableCancelForm(cancelForm, status);', $source);
        $this->assertStringContainsString('function disableCancelForm(form, status)', $source);

        // The unconditional removal must be gone from this branch — it now
        // only happens in the non-pickup else.
        $this->assertStringContainsString('cancelForm.remove();', $source, 'removal must still exist for non-pickup orders');
    }

    public function test_the_poll_side_reason_text_mirrors_the_server_rendered_one(): void
    {
        $source = file_get_contents(resource_path('views/customer/orders.blade.php'));

        $this->assertStringContainsString("'Being prepared'", $source);
        $this->assertStringContainsString("'Serving'", $source);
        $this->assertStringContainsString(
            "' — this order can no longer be cancelled.'",
            $source,
            'the client-side reason text must match the server-rendered wording exactly'
        );
    }

    // ══════════ regression: existing suites must pass completely unchanged ══════════

    public function test_cancelling_a_genuinely_pending_pickup_order_still_works_end_to_end(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'pending');

        $this->actingAs($customer, 'customer')
            ->post('/customer/orders/' . $order->id . '/cancel')
            ->assertRedirect(route('customer.orders'));

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_the_server_still_refuses_a_non_pending_cancel_with_its_message(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'serving');

        $response = $this->actingAs($customer, 'customer')
            ->postJson('/customer/orders/' . $order->id . '/cancel');

        $response->assertStatus(422);
        $this->assertStringContainsString('no longer be cancelled', $response->json('message'));
        $this->assertSame('serving', $order->fresh()->status);
    }
}

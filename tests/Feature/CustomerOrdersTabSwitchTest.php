<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The /customer/orders tab switcher, and the JS TypeError it used to throw.
 *
 * THE BUG THIS EXISTS FOR
 * -----------------------
 * Found during Pass 3 / Test #2 (IDOR review). Clicking the "Current Order"
 * tab on the customer-facing Orders page threw, in the browser console:
 *
 *   Uncaught TypeError: Cannot read properties of null (reading 'style')
 *       at switchTab (orders:1407:50)
 *
 * ROOT CAUSE, established before changing anything
 * ------------------------------------------------
 * switchTab() dereferenced BOTH tab panels unconditionally:
 *
 *   document.getElementById('statusTab').style.display  = ...
 *   document.getElementById('historyTab').style.display = ...
 *
 * V8 reports the column of the `.` in a member access (verified against node),
 * and in the source those dots sit at column 49 (#statusTab) and column 50
 * (#historyTab) — so the reported column 50 pins the failure on the
 * #historyTab line specifically.
 *
 * #historyTab is rendered CONDITIONALLY. orders.blade.php wraps it in
 * `@if(Auth::guard('customer')->check())`, because a guest has no order
 * history to show — OrderController::showOrders() says so outright in its
 * guest branch ("A guest has no History tab"). But the History BUTTON that
 * targets that panel was gated on a completely different condition,
 * `@if(session('order_type') !== 'dine_in')`, which has nothing to do with
 * being signed in. So for a guest on anything other than dine-in — including
 * the very common case of no order_type in the session at all — the page
 * rendered a History button pointing at an element that did not exist.
 *
 * And because switchTab() touched #historyTab on EVERY call, this broke BOTH
 * tabs, not just History: clicking "Current Order" set #statusTab correctly on
 * the line before and then threw, which is exactly what was reported.
 *
 * THE FIX (two layers)
 * --------------------
 *  1. The History button is now gated on the SAME condition as the panel it
 *     reveals, so it is never offered without its panel.
 *  2. switchTab() resolves both panels first, only ever assigns .style on
 *     panels that actually exist, and falls back to the first rendered panel
 *     (moving the active highlight with it via data-tab-index) if the
 *     requested one is missing. Defensive, but it still switches and still
 *     shows the right thing rather than silently doing nothing.
 *
 * WHAT THESE TESTS PIN
 * --------------------
 * The server-rendered half — the invariant that actually prevents the crash:
 * a History button is present if and only if the #historyTab panel is present.
 * That invariant is asserted across the three order-count states named in the
 * bug report (no active order, one, several) for both guests and signed-in
 * customers.
 *
 * The client-side half is verified two ways: test_switch_tab_source_is_null_safe()
 * below asserts the unguarded dereference is gone from the source, and
 * tests/js/switch-tab-harness.mjs executes the real function source, extracted
 * verbatim from orders.blade.php, against every rendered state's actual tab
 * elements.
 */
class CustomerOrdersTabSwitchTest extends TestCase
{
    use DatabaseTransactions;

    private const VIEW = 'resources/views/customer/orders.blade.php';

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'TAB-' . substr(uniqid(), -8),
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

    /**
     * Detect the History button by the call it makes — switchTab(1, ...) — and
     * NOT by the data-tab-index="1" attribute the fix introduced.
     *
     * This distinction is the difference between a real test and a vacuous
     * one, and it was caught by running these tests against the pre-fix blade:
     * matching on data-tab-index made every guest assertion pass against the
     * buggy markup, because the old buttons carried no such attribute at all,
     * so "no History button" was true for the wrong reason. switchTab(1, is
     * present in both the broken and the fixed markup, so it detects the
     * button either way.
     */
    private function hasHistoryButton(string $html): bool
    {
        return (bool) preg_match('/<button[^>]*switchTab\(1,/', $html);
    }

    private function hasHistoryPanel(string $html): bool
    {
        return str_contains($html, 'id="historyTab"');
    }

    private function hasStatusPanel(string $html): bool
    {
        return str_contains($html, 'id="statusTab"');
    }

    /**
     * The invariant. If this holds, switchTab() can never be handed a tab
     * index whose panel is missing by clicking a rendered button.
     */
    private function assertButtonAndPanelAgree(string $html, string $state): void
    {
        $this->assertTrue(
            $this->hasStatusPanel($html),
            "[$state] #statusTab must always be rendered; switchTab(0) targets it."
        );

        $this->assertSame(
            $this->hasHistoryPanel($html),
            $this->hasHistoryButton($html),
            "[$state] History button and #historyTab panel disagree — this is the exact "
            . 'shape of the reported TypeError: a button whose target element does not exist.'
        );
    }

    // ══════════ guest: the three states from the bug report ══════════

    public function test_guest_with_no_active_order_has_no_dangling_history_button(): void
    {
        $html = $this->get('/customer/orders')->assertOk()->getContent();

        $this->assertFalse(
            $this->hasHistoryPanel($html),
            'a guest has no order history, so #historyTab must not be rendered'
        );
        $this->assertButtonAndPanelAgree($html, 'guest / no active order');
    }

    public function test_guest_with_one_active_order_has_no_dangling_history_button(): void
    {
        $order = $this->makeOrder();
        GuestOrders::remember($order->id);

        $html = $this->get('/customer/orders')->assertOk()->getContent();

        $this->assertStringContainsString('orderCard-' . $order->id, $html, 'the one active order should render');
        $this->assertButtonAndPanelAgree($html, 'guest / one active order');
    }

    public function test_guest_with_several_active_orders_has_no_dangling_history_button(): void
    {
        $a = $this->makeOrder(['status' => 'pending']);
        $b = $this->makeOrder(['status' => 'preparing']);
        $c = $this->makeOrder(['status' => 'serving']);
        GuestOrders::remember($a->id);
        GuestOrders::remember($b->id);
        GuestOrders::remember($c->id);

        $html = $this->get('/customer/orders')->assertOk()->getContent();

        foreach ([$a, $b, $c] as $order) {
            $this->assertStringContainsString('orderCard-' . $order->id, $html, "order {$order->id} should render");
        }
        $this->assertButtonAndPanelAgree($html, 'guest / several active orders');
    }

    /**
     * The precise reported trigger: a guest whose session has NO order_type at
     * all. Before the fix this rendered the History button (null !== 'dine_in')
     * with no #historyTab behind it.
     */
    public function test_guest_with_no_order_type_in_session_is_the_regression_case(): void
    {
        $html = $this->get('/customer/orders')->assertOk()->getContent();

        $this->assertFalse($this->hasHistoryPanel($html));
        $this->assertFalse(
            $this->hasHistoryButton($html),
            'REGRESSION: guest with no order_type got a History button with no panel — the original crash'
        );
    }

    public function test_guest_on_dine_in_has_neither_history_button_nor_panel(): void
    {
        $html = $this->withSession(['order_type' => 'dine_in'])
            ->get('/customer/orders')->assertOk()->getContent();

        $this->assertFalse($this->hasHistoryPanel($html));
        $this->assertButtonAndPanelAgree($html, 'guest / dine-in');
    }

    // ══════════ signed-in customer: same three states ══════════

    public function test_signed_in_customer_with_no_active_order_gets_both_button_and_panel(): void
    {
        $html = $this->actingAs($this->customer(), 'customer')
            ->get('/customer/orders')->assertOk()->getContent();

        $this->assertTrue($this->hasHistoryPanel($html), 'a signed-in customer must get #historyTab');
        $this->assertTrue($this->hasHistoryButton($html), 'and the button that reveals it');
        $this->assertButtonAndPanelAgree($html, 'customer / no active order');
    }

    public function test_signed_in_customer_with_one_active_order_gets_both(): void
    {
        $customer = $this->customer();
        $order = $this->makeOrder(['user_id' => $customer->id]);

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/orders')->assertOk()->getContent();

        $this->assertStringContainsString('orderCard-' . $order->id, $html);
        $this->assertButtonAndPanelAgree($html, 'customer / one active order');
    }

    public function test_signed_in_customer_with_several_active_orders_gets_both(): void
    {
        $customer = $this->customer();
        $a = $this->makeOrder(['user_id' => $customer->id, 'status' => 'pending']);
        $b = $this->makeOrder(['user_id' => $customer->id, 'status' => 'preparing']);
        $c = $this->makeOrder(['user_id' => $customer->id, 'status' => 'serving']);

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/orders')->assertOk()->getContent();

        foreach ([$a, $b, $c] as $order) {
            $this->assertStringContainsString('orderCard-' . $order->id, $html);
        }
        $this->assertButtonAndPanelAgree($html, 'customer / several active orders');
    }

    /**
     * A signed-in customer on dine-in keeps the panel but not the button.
     * That is fine and must NOT crash: switchTab is only ever called with 0.
     */
    public function test_signed_in_customer_on_dine_in_keeps_panel_without_button(): void
    {
        $html = $this->actingAs($this->customer(), 'customer')
            ->withSession(['order_type' => 'dine_in'])
            ->get('/customer/orders')->assertOk()->getContent();

        $this->assertTrue($this->hasHistoryPanel($html));
        $this->assertFalse($this->hasHistoryButton($html), 'dine-in deliberately hides History');
    }

    // ══════════ the client-side half ══════════

    /**
     * The unguarded dereference that threw must be gone from the source, and
     * the replacement must guard before assigning .style. This is a source
     * assertion; the behavioural proof is tests/js/switch-tab-harness.mjs,
     * which runs the real extracted function against real rendered pages.
     */
    public function test_switch_tab_source_is_null_safe(): void
    {
        $source = file_get_contents(base_path(self::VIEW));
        $this->assertNotFalse($source);

        foreach (['statusTab', 'historyTab'] as $id) {
            $this->assertStringNotContainsString(
                "document.getElementById('{$id}').style",
                $source,
                "switchTab must not dereference #{$id} without checking it exists first"
            );
        }

        $this->assertMatchesRegularExpression(
            '/function switchTab\(index, btn\)/',
            $source,
            'switchTab should still exist'
        );
        $this->assertStringContainsString(
            'panels.findIndex(panel => panel)',
            $source,
            'switchTab should fall back to the first rendered panel when the requested one is missing'
        );
    }
}

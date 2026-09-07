<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The More page's "Order History" card landed on the Orders page's Current
 * Order tab (Pending/Preparing/Serving) instead of the History tab
 * (Completed/Cancelled) the card promised (2026-09-02).
 *
 * ROOT CAUSE
 * ----------
 * Not a broken query — OrderController::showOrders() already builds
 * $orderHistory correctly: scoped to Auth::guard('customer')->id() (same
 * pattern $currentOrders uses) and filtered to
 * whereIn('status', ['completed', 'cancelled']). CustomerOrdersTabSwitchTest
 * already covers that the History tab exists and is safe to switch to.
 *
 * The bug was purely default-tab selection. more.blade.php's "Order History"
 * card was a plain route('customer.orders') link with no way to say which
 * tab it meant, and orders.blade.php always rendered tab 0 (Current Order)
 * as 'active' with #statusTab visible and #historyTab hard-coded
 * style="display:none" — switchTab() only runs on a click, so nothing on
 * page LOAD ever looked at the URL at all.
 *
 * THE FIX
 * -------
 * The card now links to route('customer.orders', ['tab' => 'history']).
 * orders.blade.php computes $initialTab server-side from
 * request()->query('tab') and uses it to decide the initial 'active' class
 * on both tab buttons and the initial display:none on both panels — so the
 * correct tab is already showing in the HTML the server sends, before any
 * JS runs. switchTab() itself is untouched; this only changes where the page
 * starts.
 *
 * Gated on the same condition #historyTab itself renders under (signed-in
 * customer) rather than the stricter one the History BUTTON uses (also
 * excludes dine-in): a request for a tab that exists but currently has no
 * button pointing at it should still open successfully; a request for a tab
 * that was never rendered at all (guest) must fall back to Current Order.
 */
class CustomerOrdersHistoryLinkTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function otherCustomer(): User
    {
        return User::where('role', 'customer')
            ->where('is_active', true)
            ->where('id', '!=', $this->customer()->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'OHL-' . substr(uniqid(), -8),
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
     * Isolate #historyTab from the rest of the page (particularly #statusTab,
     * which can legitimately contain the same order numbers in other tests
     * and would make a naive assertStringContainsString pass for the wrong
     * reason).
     */
    private function historySectionOf(string $html): string
    {
        $start = strpos($html, 'id="historyTab"');
        $this->assertNotFalse($start, 'the History panel is missing from the page entirely');

        $end = strpos($html, '</main>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function statusSectionOf(string $html): string
    {
        $start = strpos($html, 'id="statusTab"');
        $this->assertNotFalse($start);

        $end = strpos($html, 'id="historyTab"', $start);
        // Guests have no #historyTab; fall back to </main> as the boundary.
        $end = $end !== false ? $end : strpos($html, '</main>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    // ══════════ the More page link itself ══════════

    public function test_the_more_page_order_history_card_links_with_the_history_tab_param(): void
    {
        $html = $this->actingAs($this->customer(), 'customer')
            ->get('/customer/more')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('customer.orders', ['tab' => 'history']),
            $html,
            'the Order History card must link to the History tab, not the bare Orders page'
        );
    }

    // ══════════ which tab actually opens ══════════

    public function test_tab_history_lands_on_the_history_panel_for_a_signed_in_customer(): void
    {
        $customer = $this->customer();

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/orders?tab=history')
            ->assertOk()
            ->getContent();

        // The panel itself must be visible (no inline display:none)...
        $this->assertDoesNotMatchRegularExpression(
            '/id="historyTab"[^>]*style="display:none;"/',
            $html,
            'REGRESSION: History panel still hidden when landing via ?tab=history'
        );

        // ...and Current Order must be the one hidden instead.
        $this->assertMatchesRegularExpression(
            '/id="statusTab"[^>]*style="display:none;"/',
            $html,
            'Current Order tab should be hidden when arriving on History'
        );

        // The History button, not Current Order, should carry the active class.
        $this->assertMatchesRegularExpression(
            '/<button class="tab-btn active" data-tab-index="1"/',
            $html,
            'the History tab button should be the one marked active'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<button class="tab-btn active" data-tab-index="0"/',
            $html
        );
    }

    public function test_no_tab_param_still_defaults_to_current_order(): void
    {
        // Guards the ordinary case: someone landing on /customer/orders with
        // no query string at all (bottom nav, browser history, a bookmark)
        // must keep seeing Current Order first, unaffected by this fix.
        $html = $this->actingAs($this->customer(), 'customer')
            ->get('/customer/orders')
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/id="statusTab"[^>]*style="display:none;"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="historyTab"[^>]*style="display:none;"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<button class="tab-btn active" data-tab-index="0"/',
            $html
        );
    }

    public function test_tab_history_for_a_guest_falls_back_to_current_order(): void
    {
        // A guest has no #historyTab panel at all (no account to hold
        // history against) — requesting a tab that does not exist must not
        // leave the page with BOTH tabs hidden or crash the render.
        $html = $this->get('/customer/orders?tab=history')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('id="historyTab"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/id="statusTab"[^>]*style="display:none;"/',
            $html,
            'a guest requesting a tab that does not exist ended up with no visible tab at all'
        );
    }

    // ══════════ the actual regression case: mixed statuses, own customer only ══════════

    public function test_history_shows_only_this_customers_own_completed_and_cancelled_orders(): void
    {
        $customer = $this->customer();
        $stranger = $this->otherCustomer();

        $mine_pending   = $this->makeOrder(['user_id' => $customer->id, 'status' => 'pending']);
        $mine_completed = $this->makeOrder(['user_id' => $customer->id, 'status' => 'completed']);
        $mine_cancelled = $this->makeOrder(['user_id' => $customer->id, 'status' => 'cancelled']);
        $theirs_completed = $this->makeOrder(['user_id' => $stranger->id, 'status' => 'completed']);

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/orders?tab=history')
            ->assertOk()
            ->getContent();

        $history = $this->historySectionOf($html);

        $this->assertStringContainsString(
            $mine_completed->order_number,
            $history,
            "the customer's own completed order is missing from History"
        );
        $this->assertStringContainsString(
            $mine_cancelled->order_number,
            $history,
            "the customer's own cancelled order is missing from History"
        );

        $this->assertStringNotContainsString(
            $mine_pending->order_number,
            $history,
            'a still-pending order leaked into History'
        );
        $this->assertStringNotContainsString(
            $theirs_completed->order_number,
            $history,
            "another customer's order leaked into this customer's History"
        );

        // And the pending order should still be exactly where it belongs.
        $status = $this->statusSectionOf($html);
        $this->assertStringContainsString($mine_pending->order_number, $status);
    }

    public function test_a_fresh_pending_order_never_leaks_someone_elses_history_alongside_it(): void
    {
        /*
         * NOT asserting an empty history here: this test's customer is a
         * shared row in a long-lived database and may genuinely have real
         * completed orders from outside this test (it does — caught by an
         * earlier draft asserting "No order history yet" against a customer
         * who actually has prior history, which is a flawed assumption about
         * a live database, not a bug in the fix). What is actually
         * guaranteed regardless of that pre-existing history: a brand new
         * pending order does not appear in History, and a stranger's order
         * never does either.
         */
        $customer = $this->customer();
        $stranger = $this->otherCustomer();

        $pending = $this->makeOrder(['user_id' => $customer->id, 'status' => 'pending']);
        $theirs  = $this->makeOrder(['user_id' => $stranger->id, 'status' => 'completed']);

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/orders?tab=history')
            ->assertOk()
            ->getContent();

        $history = $this->historySectionOf($html);

        $this->assertStringNotContainsString($pending->order_number, $history);
        $this->assertStringNotContainsString($theirs->order_number, $history);
    }
}

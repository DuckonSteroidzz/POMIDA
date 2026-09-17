<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\TableEntry;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Three related reports on the GCash payment -> Orders flow, investigated
 * together because the screenshot for all three was the same "Your Orders"
 * page for a dine-in GCash order still awaiting payment/verification.
 *
 * (a) CONFIRMED, FIXED: no way back to customer.gcash-payment from the Orders
 *     view once a customer navigates away from it. The route itself already
 *     accepted payment_status 'pending' and 'awaiting_verification' (see
 *     OrderController::showGcashPayment()) — this page simply never linked
 *     to it. Fixed by adding that link to the order summary card.
 *
 * (b) NOT REPRODUCED. Direct reproduction (PHPUnit client, real HTTP against
 *     `php artisan serve` with the database session driver, and a real
 *     headless-Chrome click via puppeteer-core) never produced a 500 clicking
 *     Cart from this page. Cross-checked against the separate, also
 *     unreproduced "notification click -> cart -> 500" report: no shared
 *     root cause was found, because no root cause was found in either case.
 *     No fix applied; not covered by a regression test here since there is
 *     nothing confirmed to regress-test.
 *
 * (c) THE REPORTED SYMPTOM ("Cancel Order doesn't cancel") DID NOT
 *     REPRODUCE — cancelCustomerOrder() works exactly as CustomerCancelOrderTest
 *     already pins, including for a GCash order awaiting verification (see
 *     $needsRefund there). But investigating it with a REAL browser (puppeteer
 *     -core, headless Chrome, 390x844 viewport) surfaced two real, related
 *     bugs, both fixed here:
 *
 *       1. WRONG ATTRIBUTION: the "Order Cancelled" popup on both
 *          orders.blade.php and menu.blade.php hard-coded "has been cancelled
 *          BY STAFF" even when the customer had just cancelled it themselves
 *          by tapping this exact button — directly plausible as the source of
 *          "I clicked Cancel and it seems like it didn't work [staff did it
 *          instead?]" confusion. Fixed to neutral wording.
 *
 *       2. UNCLICKABLE CONTROL: on a short page (an order with few items),
 *          the Cancel Order button's on-screen position can coincide with the
 *          customer bottom nav's fixed screen band. That nav is
 *          `position: fixed`, which always paints above ordinary in-flow
 *          content regardless of DOM order — confirmed live with
 *          `document.elementFromPoint()` at the button's own center resolving
 *          to the nav's "Spin & Win" tab, not the button. A real tap there
 *          hits the nav and does nothing to the order, matching "the button
 *          doesn't actually cancel the order" exactly. This is a layout/paint
 *          -order issue invisible to PHPUnit's HTTP test client (it never
 *          renders a page), so it went uncaught until reproduced in a real
 *          browser as the task asked. Fixed by giving the Cancel Order form
 *          (and the new GCash re-entry button next to it, which the SAME
 *          browser test caught freshly regressing after fix (a) was added)
 *          a higher stacking order (`relative z-[45]`) than the nav's z-40,
 *          so a coincidental overlap always resolves to the button.
 *
 * PHPUnit cannot exercise real click hit-testing or CSS stacking, so the
 * tests below pin what IS testable here: the markup fixes exist and are
 * wired correctly. The layout fix itself was verified with a real headless
 * Chrome session during this investigation, not re-verified by these tests.
 */
class GcashOrdersViewFixesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
    }

    private function scan(int $branchId, string $table)
    {
        $code = TableEntry::findOrRegister($branchId, $table)->code;

        return $this->from('/customer/dineinqr')->post('/customer/dineinqr', [
            'tableData' => "branch_id={$branchId}&table={$table}&k={$code}",
            'next'      => 'guest',
        ]);
    }

    private function makeGcashOrder(string $paymentStatus): Order
    {
        $order = Order::create([
            'order_number'   => 'GJ-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'dine_in',
            'table_number'   => '2',
            'status'         => 'pending',
            'payment_method' => 'gcash',
            'payment_status' => $paymentStatus,
            'subtotal'       => 200,
            'total'          => 200,
        ]);

        $order->items()->create([
            'menu_item_id' => 25,
            'item_name'    => 'Peperoni Pizza',
            'item_price'   => 200,
            'quantity'     => 1,
            'subtotal'     => 200,
        ]);

        GuestOrders::remember($order->id);

        return $order;
    }

    // ── (a) GCash re-entry link ──────────────────────────────────────────

    public function test_orders_page_links_back_to_gcash_payment_while_pending(): void
    {
        $this->scan(1, '2');
        $order = $this->makeGcashOrder('pending');

        $html = $this->get('/customer/orders')->getContent();

        $expectedUrl = route('customer.gcash-payment', $order->id);
        $this->assertStringContainsString($expectedUrl, $html);
        $this->assertStringContainsString('Pay via GCash', $html);
    }

    public function test_orders_page_links_back_to_gcash_payment_while_awaiting_verification(): void
    {
        $this->scan(1, '2');
        $order = $this->makeGcashOrder('awaiting_verification');

        $html = $this->get('/customer/orders')->getContent();

        $expectedUrl = route('customer.gcash-payment', $order->id);
        $this->assertStringContainsString($expectedUrl, $html);
        $this->assertStringContainsString('View GCash Payment Status', $html);
    }

    public function test_gcash_re_entry_link_is_absent_once_payment_is_settled(): void
    {
        $this->scan(1, '2');
        $order = $this->makeGcashOrder('paid');

        $html = $this->get('/customer/orders')->getContent();

        $this->assertStringNotContainsString(
            route('customer.gcash-payment', $order->id),
            $html,
            'a settled GCash order should not still invite the customer back to the payment page'
        );
    }

    public function test_gcash_re_entry_link_is_absent_for_a_cash_order(): void
    {
        $this->scan(1, '2');
        $order = $this->makeGcashOrder('pending');
        $order->update(['payment_method' => 'cash']);

        $html = $this->get('/customer/orders')->getContent();

        $this->assertStringNotContainsString('Pay via GCash', $html);
        $this->assertStringNotContainsString('View GCash Payment Status', $html);
    }

    public function test_gcash_re_entry_link_actually_reaches_the_payment_page(): void
    {
        $this->scan(1, '2');
        $order = $this->makeGcashOrder('awaiting_verification');

        $response = $this->get(route('customer.gcash-payment', $order->id));

        $response->assertOk();
    }

    // ── (c)(1) neutral "Order Cancelled" wording ─────────────────────────

    public function test_orders_page_cancelled_popup_does_not_blame_staff(): void
    {
        $html = $this->get('/customer/orders')->getContent();

        $this->assertStringNotContainsString(
            'has been cancelled by staff',
            $html,
            'orders.blade.php still hard-codes staff as the canceller, even for a customer self-cancellation'
        );
    }

    public function test_menu_page_cancelled_popup_does_not_blame_staff(): void
    {
        $this->scan(1, '2');

        $html = $this->get('/customer/menu')->getContent();

        $this->assertStringNotContainsString(
            'has been cancelled by staff',
            $html,
            'menu.blade.php still hard-codes staff as the canceller, even for a customer self-cancellation'
        );
    }

    // ── (c)(2) the Cancel Order control is never behind the fixed nav ───

    public function test_cancel_order_form_outranks_the_fixed_bottom_nav(): void
    {
        $this->scan(1, '2');
        $this->makeGcashOrder('pending');

        $html = $this->get('/customer/orders')->getContent();

        $this->assertMatchesRegularExpression(
            '/data-cancel-form="\d+"[^>]*class="[^"]*relative z-\[45\]/',
            $html,
            'the Cancel Order form lost its stacking fix against the fixed mobile nav (z-40)'
        );
    }

    public function test_gcash_button_outranks_the_fixed_bottom_nav(): void
    {
        $this->scan(1, '2');
        $order = $this->makeGcashOrder('pending');

        $html = $this->get('/customer/orders')->getContent();

        $expectedUrl = route('customer.gcash-payment', $order->id);
        $needle = 'href="' . $expectedUrl . '"';
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, 'gcash link not found to check its stacking classes');

        // The class attribute sits right before href in this markup; look at
        // a small window around the link rather than assuming exact order.
        $window = substr($html, max(0, $pos - 300), 600);
        $this->assertStringContainsString('relative z-[45]', $window);
    }
}

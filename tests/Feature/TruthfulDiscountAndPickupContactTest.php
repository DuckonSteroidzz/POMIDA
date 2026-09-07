<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Support\GuestOrders;
use App\Support\StoreContact;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Two customer-facing gaps found on a real phone, pinned here.
 *
 * 1. THE ACTIVE ORDERS CARD MISLABELLED A VOUCHER DISCOUNT
 *    The admin Active Orders card was hard-coded to the words "PWD/SENIOR
 *    DISCOUNT" for every discounted order, even though the detail modal for the
 *    same order already showed the real type. The card now reads its label from
 *    Order::discountDisplayLabel() — the single source of truth also used by the
 *    modal, the customer's order page, the receipt and the completed-orders
 *    table.
 *
 * 2. A PICK-UP CUSTOMER HAD NO WAY TO REACH THE SHOP
 *    "Request Assistance" calls a server to a table and is refused for pick-up,
 *    and nothing replaced it. The order page and receipt now show pick-up
 *    customers (signed in or guest) the shop's REAL stored phone and email,
 *    resolved by App\Support\StoreContact. Dine-in keeps Request Assistance.
 *
 * Cleanup: this suite uses DatabaseTransactions, so every row it writes is
 * rolled back at the end of each test — nothing is committed to the live
 * database and no pre-existing row is touched.
 */
class TruthfulDiscountAndPickupContactTest extends TestCase
{
    use DatabaseTransactions;

    private function staff(): User
    {
        // Branch 1 staff — the Active Orders board is scoped to their branch.
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function customer(): User
    {
        return User::where('email', 'pedro@gmail.com')->firstOrFail();
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'TDPC-' . strtoupper(substr(uniqid(), -9)),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'table_number'   => null,
            'status'         => 'pending',
            'payment_method' => 'gcash',
            'payment_status' => 'awaiting_verification',
            'subtotal'       => 500,
            'total'          => 500,
        ], $attrs));
    }

    /** The slice of the board HTML that belongs to one order's card. */
    private function cardFor(Order $order, string $html): string
    {
        $start = strpos($html, 'Order #' . $order->order_number);
        $this->assertNotFalse($start, "order {$order->order_number} is not on the board");
        $next = strpos($html, 'Order #', $start + 10);

        return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
    }

    private function board(): string
    {
        return $this->actingAs($this->staff(), 'admin')->get('/admin/home')->assertOk()->getContent();
    }

    // ────────────────────────── Defect 1: the card ──────────────────────────

    public function test_active_orders_card_calls_a_voucher_discount_a_voucher(): void
    {
        $order = $this->makeOrder([
            'branch_id'       => 1,
            'discount_type'   => 'voucher',
            'discount_amount' => 100,
            'discount_status' => 'approved',
            'total'           => 400,
        ]);

        $card = $this->cardFor($order, $this->board());

        $this->assertStringContainsString('VOUCHER DISCOUNT', $card);
        $this->assertStringNotContainsString('PWD/SENIOR DISCOUNT', $card);
        $this->assertStringNotContainsString('PWD DISCOUNT', $card);
        // The modal is fed from the same label, via this data attribute.
        $this->assertStringContainsString('data-type="Voucher"', $card);
    }

    public function test_active_orders_card_still_says_pwd_for_a_pwd_discount(): void
    {
        $order = $this->makeOrder([
            'branch_id'       => 1,
            'discount_type'   => 'pwd',
            'discount_amount' => 100,
            'discount_status' => 'approved',
            'total'           => 400,
        ]);

        $card = $this->cardFor($order, $this->board());

        $this->assertStringContainsString('PWD DISCOUNT', $card);
        $this->assertStringNotContainsString('VOUCHER DISCOUNT', $card);
    }

    public function test_active_orders_card_shows_no_discount_line_when_there_is_no_discount(): void
    {
        $order = $this->makeOrder([
            'branch_id'       => 1,
            'discount_type'   => null,
            'discount_amount' => 0,
            'total'           => 500,
        ]);

        $card = $this->cardFor($order, $this->board());

        $this->assertStringNotContainsString('pc-discount-trigger', $card);
        $this->assertStringNotContainsString('DISCOUNT', $card);
    }

    public function test_completed_orders_table_tags_a_voucher_discount(): void
    {
        $order = $this->makeOrder([
            'branch_id'       => 1,
            'status'          => 'completed',
            'completed_at'    => now(),
            'discount_type'   => 'voucher',
            'discount_amount' => 100,
            'discount_status' => 'approved',
            'total'           => 400,
        ]);

        $html = $this->actingAs($this->staff(), 'admin')
            ->get('/admin/completed-orders')->assertOk()->getContent();

        $start = strpos($html, $order->order_number);
        $this->assertNotFalse($start);
        $row = substr($html, $start, 900);

        $this->assertStringContainsString('co-discount-tag">Voucher<', $row);
        $this->assertStringNotContainsString('co-discount-tag">PWD<', $row);
    }

    // ─────────────── Defect 1: the customer's own surfaces ───────────────

    public function test_customer_order_page_names_the_real_discount_type(): void
    {
        $voucher = $this->makeOrder([
            'user_id'         => $this->customer()->id,
            'discount_type'   => 'voucher',
            'discount_amount' => 100,
            'discount_status' => 'approved',
            'total'           => 400,
        ]);

        $html = $this->actingAs($this->customer(), 'customer')
            ->get('/customer/orders')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="label text-green-600">Voucher Discount</span>', $html);
        $this->assertStringNotContainsString('>PWD Discount<', $html);

        $voucher->forceFill(['discount_type' => 'pwd'])->save();

        $html = $this->actingAs($this->customer(), 'customer')
            ->get('/customer/orders')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="label text-green-600">PWD Discount</span>', $html);
    }

    public function test_receipt_names_the_real_discount_type(): void
    {
        $order = $this->makeOrder([
            'status'          => 'completed',
            'completed_at'    => now(),
            'discount_type'   => 'voucher',
            'discount_amount' => 100,
            'discount_status' => 'approved',
            'total'           => 400,
        ]);

        $this->withSession([GuestOrders::KEY => [$order->id]]);
        $html = $this->get('/customer/receipt/' . $order->id)->assertOk()->getContent();

        // Isolated to the discount line's own <span>.
        $this->assertMatchesRegularExpression(
            '/<i class="bi bi-tag"><\/i>\s*Voucher Discount/',
            $html
        );
        $this->assertStringNotContainsString('PWD Discount', $html);
    }

    // ────────────────── Defect 2: pick-up store contact ──────────────────

    public function test_guest_pickup_sees_the_shops_real_contact_details_on_the_order_page(): void
    {
        // Branch 2 carries a real stored phone and email.
        $order = $this->makeOrder(['branch_id' => 2, 'status' => 'pending']);
        $stored = StoreContact::forBranch(2);

        $this->withSession([GuestOrders::KEY => [$order->id]]);
        $html = $this->get('/customer/orders')->assertOk()->getContent();

        $this->assertStringContainsString('Need help with this pick-up order?', $html);
        $this->assertStringContainsString($stored['contact_number'], $html);
        $this->assertStringContainsString($stored['email'], $html);
        $this->assertNotEmpty($stored['contact_number']);
    }

    public function test_signed_in_pickup_sees_the_same_contact_details(): void
    {
        $order = $this->makeOrder([
            'branch_id' => 2,
            'user_id'   => $this->customer()->id,
            'status'    => 'preparing',
        ]);
        $stored = StoreContact::forBranch(2);

        $html = $this->actingAs($this->customer(), 'customer')
            ->get('/customer/orders')->assertOk()->getContent();

        $this->assertStringContainsString('Need help with this pick-up order?', $html);
        $this->assertStringContainsString($stored['contact_number'], $html);
    }

    public function test_pickup_customer_sees_contact_details_on_the_receipt(): void
    {
        $order = $this->makeOrder([
            'branch_id'    => 2,
            'status'       => 'completed',
            'completed_at' => now(),
        ]);
        $stored = StoreContact::forBranch(2);

        $this->withSession([GuestOrders::KEY => [$order->id]]);
        $html = $this->get('/customer/receipt/' . $order->id)->assertOk()->getContent();

        $this->assertStringContainsString('Need help with this pick-up order?', $html);
        $this->assertStringContainsString($stored['contact_number'], $html);
    }

    public function test_dine_in_order_page_does_not_show_the_pickup_contact_block(): void
    {
        $order = $this->makeOrder([
            'type'         => 'dine_in',
            'table_number' => '77',
            'user_id'      => $this->customer()->id,
            'status'       => 'pending',
        ]);

        $html = $this->actingAs($this->customer(), 'customer')
            ->withSession(['order_type' => 'dine_in', 'table_number' => '77', 'branch_id' => 1])
            ->get('/customer/orders')->assertOk()->getContent();

        $this->assertStringNotContainsString('Need help with this pick-up order?', $html);
    }

    public function test_dine_in_more_page_keeps_request_assistance_unchanged(): void
    {
        $html = $this->actingAs($this->customer(), 'customer')
            ->withSession(['order_type' => 'dine_in', 'table_number' => '77', 'branch_id' => 1])
            ->get('/customer/more')->assertOk()->getContent();

        $this->assertStringContainsString('Request Assistance', $html);
        $this->assertStringContainsString('onclick="openHelpModal()"', $html);
        $this->assertStringNotContainsString('Need help with this pick-up order?', $html);
    }

    public function test_pickup_contact_partial_carries_its_overflow_guards(): void
    {
        $src = file_get_contents(resource_path('views/partials/pickup-store-contact.blade.php'));

        // Same long-string guards the proven More-page contact rows use.
        $this->assertStringContainsString('break-all', $src);
        $this->assertStringContainsString('min-w-0', $src);
    }
}

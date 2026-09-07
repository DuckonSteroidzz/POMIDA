<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Every order card on the Active Orders board shows a Payment section.
 *
 * THE BUG THIS EXISTS FOR
 * -----------------------
 * Reported live: a dine-in order paid by GCash showed a PAYMENT block with the
 * GCash icon and a green "Paid" badge, while a dine-in order paid by Cash showed
 * no Payment section at all — the card went straight from the item list to
 * Subtotal/Total, so the counter could not tell from the board whether the money
 * had been taken.
 *
 * The data was never the problem. storeManualOrder() writes payment_method and
 * payment_status for cash and GCash alike, and the first assertions below
 * re-prove that against the real database rather than assuming it. The whole
 * cause was one condition in the view: the Payment block was wrapped in
 * @if($isGcash), and the method name inside it was the hard-coded string
 * "GCash".
 */
class ActiveOrdersPaymentSectionTest extends TestCase
{
    use DatabaseTransactions;

    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function line(): array
    {
        $item = \App\Models\MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->firstOrFail();

        return [
            (string) $item->id => ['menu_item_id' => (string) $item->id, 'quantity' => '1'],
        ];
    }

    private function counterOrder(string $method): Order
    {
        $this->actingAs($this->staff(), 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', [
                'branch_id'      => 1,
                'order_type'     => 'dine_in',
                'table_number'   => '5' . random_int(10, 99),
                'payment_method' => $method,
                'amount_paid'    => '5000',
                'items'          => $this->line(),
            ])
            ->assertSessionHasNoErrors();

        return Order::orderByDesc('id')->firstOrFail();
    }

    // ══════════ step 1: the data really is the same shape ══════════

    public static function methods(): array
    {
        return ['cash' => ['cash'], 'gcash' => ['gcash']];
    }

    /**
     * @dataProvider methods
     */
    public function test_both_methods_are_recorded_identically(string $method): void
    {
        $order = $this->counterOrder($method);

        $this->assertSame($method, $order->payment_method);
        $this->assertSame('paid', $order->payment_status);
    }

    // ══════════ step 3: the board renders it for every method ══════════

    /**
     * The card markup for one order, so an assertion about "the Cash card" is
     * not accidentally satisfied by some other order already on the board.
     */
    private function cardFor(Order $order, string $html): string
    {
        $start = strpos($html, 'Order #' . $order->order_number);
        $this->assertNotFalse($start, "order {$order->order_number} is not on the board");

        // From this order's number up to the next order's, or the end.
        $next = strpos($html, 'Order #', $start + 10);

        return $next === false
            ? substr($html, $start)
            : substr($html, $start, $next - $start);
    }

    public function test_a_cash_order_card_shows_a_payment_section(): void
    {
        $order = $this->counterOrder('cash');

        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->assertOk()->getContent();
        $card = $this->cardFor($order, $html);

        $this->assertStringContainsString('Payment', $card, 'the Cash card has no Payment section at all');
        $this->assertStringContainsString('Cash', $card);
        $this->assertStringContainsString('bi-cash-coin', $card, 'the Cash card has no payment icon');
        $this->assertStringContainsString('Paid', $card, 'the Cash card does not say whether it is paid');
    }

    public function test_the_gcash_card_still_renders_exactly_as_before(): void
    {
        $order = $this->counterOrder('gcash');

        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->assertOk()->getContent();
        $card = $this->cardFor($order, $html);

        $this->assertStringContainsString('Payment', $card);
        $this->assertStringContainsString('GCash', $card);
        $this->assertStringContainsString('bi-phone', $card);
        $this->assertStringContainsString('Paid', $card);
    }

    public function test_an_unpaid_cash_order_is_not_shown_as_paid(): void
    {
        $order = $this->counterOrder('cash');
        $order->payment_status = 'pending';
        $order->save();

        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->assertOk()->getContent();
        $card = $this->cardFor($order, $html);

        $this->assertStringContainsString('Payment', $card);
        $this->assertStringContainsString('Pending', $card);
    }

    // ══════════ step 4: the other two places that show an order ══════════

    public function test_the_order_details_modal_carries_the_payment_information(): void
    {
        $order = $this->counterOrder('cash');

        // The "… N more items" button that opens the modal only renders once a
        // card has more than four lines, so give this order five.
        for ($i = 0; $i < 5; $i++) {
            \App\Models\OrderItem::create([
                'order_id'     => $order->id,
                'menu_item_id' => $order->items()->first()->menu_item_id,
                'item_name'    => 'Filler ' . $i,
                'quantity'     => 1,
                'item_price'   => 10,
                'subtotal'     => 10,
            ]);
        }

        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->assertOk()->getContent();

        // The modal is populated from data-* attributes on the card's button,
        // and it previously carried no payment information for ANY method.
        $this->assertStringContainsString('pcOrderModalPayment', $html);
        $this->assertStringContainsString('data-payment=', $html);
        $this->assertStringContainsString('data-payment-status=', $html);
    }

    public function test_the_completed_orders_page_shows_method_and_status(): void
    {
        $order = $this->counterOrder('cash');
        $order->status = 'completed';
        $order->completed_at = now();
        $order->save();

        $html = $this->actingAs($this->staff(), 'admin')
            ->get('/admin/completed-orders')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringContainsString('Cash', $html);
        $this->assertStringContainsString('Paid', $html);
    }
}

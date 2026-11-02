<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tester report (2026-11-01): a CANCELLED order still showed its payment as
 * "Cash · Pending".
 *
 * The rule lives in ONE place, Order::paymentStatusText(), and is display
 * only — the stored column is never rewritten, so old cancelled orders read
 * the same as new ones and nothing that filters on payment_status moves:
 *
 *   cancelled + nothing paid ('pending' / empty / 'unpaid')  -> "Cancelled"
 *   cancelled + money involved                               -> its own words
 *       paid / awaiting verification / rejected / Refund owed / Refunded
 *   any live order                                           -> unchanged
 *
 * Every row is created inside DatabaseTransactions and carries the CNPS prefix.
 */
class CancelledOrderPaymentStatusTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'CNPS';

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function order(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => self::PREFIX . '-' . strtoupper(substr(uniqid(), -8)),
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'cancelled',
            'subtotal'       => 100,
            'total'          => 100,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'completed_at'   => now(),
            'cancelled_at'   => now(),
        ], $attrs));
    }

    // ══════════ the one rule ══════════

    public function test_the_rule_for_every_stored_state(): void
    {
        $cases = [
            // [payment_status, order status, expected]
            ['pending',               'cancelled', 'Cancelled'],
            ['',                      'cancelled', 'Cancelled'],
            ['unpaid',                'cancelled', 'Cancelled'],
            [null,                    'cancelled', 'Cancelled'],
            ['paid',                  'cancelled', 'Paid'],
            ['awaiting_verification', 'cancelled', 'Awaiting verification'],
            ['rejected',              'cancelled', 'Rejected'],
            ['refund_pending',        'cancelled', 'Refund owed'],
            ['refunded',              'cancelled', 'Refunded'],
            // Live orders are untouched.
            ['pending',               'pending',   'Pending'],
            ['pending',               'preparing', 'Pending'],
            ['paid',                  'completed', 'Paid'],
            ['awaiting_verification', 'pending',   'Awaiting verification'],
            [null,                    'pending',   'Unpaid'],
        ];

        foreach ($cases as [$payment, $status, $expected]) {
            $this->assertSame(
                $expected,
                Order::paymentStatusText($payment, $status),
                'payment_status=' . var_export($payment, true) . ' on a ' . $status . ' order'
            );
        }
    }

    public function test_the_model_method_reads_the_orders_own_columns_and_never_writes(): void
    {
        $order = $this->order(['payment_status' => 'pending']);

        $this->assertSame('Cancelled', $order->paymentStatusLabel());
        $this->assertSame('pending', $order->fresh()->payment_status, 'display only: the stored value is untouched');
    }

    // ══════════ every screen agrees ══════════

    public function test_completed_orders_screen_shows_cancelled_not_pending(): void
    {
        $order = $this->order();

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get(route('admin.completed-orders'))
            ->assertOk()
            ->getContent();

        $row = $this->rowContaining($html, $order->order_number);
        $this->assertMatchesRegularExpression('/Cash\s*<span[^>]*>\s*Cancelled\s*<\/span>/', $row);
        $this->assertStringNotContainsString('Pending', $row);
    }

    public function test_completed_orders_print_shows_cancelled_not_pending(): void
    {
        $order = $this->order();

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get(route('admin.completed-orders.print'))
            ->assertOk()
            ->getContent();

        $row = $this->rowContaining($html, $order->order_number);
        $this->assertStringContainsString('Cash (Cancelled)', $row);
        $this->assertStringNotContainsString('Pending', $row);
    }

    public function test_completed_orders_csv_shows_cancelled_and_keeps_refund_states(): void
    {
        $plain    = $this->order();
        $refund   = $this->order(['payment_method' => 'gcash', 'payment_status' => 'refund_pending']);
        $refunded = $this->order(['payment_method' => 'gcash', 'payment_status' => 'refunded']);

        $raw = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get(route('admin.completed-orders.export'))
            ->assertOk()
            ->streamedContent();

        $byNumber = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $cells = str_getcsv($line, ',', '"', '\\');
            if (isset($cells[0]) && str_starts_with($cells[0], self::PREFIX . '-')) {
                $byNumber[$cells[0]] = $cells;
            }
        }

        // Columns: ... 'Payment Method'(9), 'Payment Status'(10), 'Status'(11)
        $this->assertSame('Cancelled', $byNumber[$plain->order_number][10]);
        $this->assertSame('Refund owed', $byNumber[$refund->order_number][10]);
        $this->assertSame('Refunded', $byNumber[$refunded->order_number][10]);
    }

    // ══════════ staff cancel of a GCash order the customer marked paid ══════════

    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function liveOrder(array $attrs = []): Order
    {
        return $this->order(array_merge([
            'status' => 'pending', 'completed_at' => null, 'cancelled_at' => null,
            'payment_method' => 'gcash', 'payment_status' => 'awaiting_verification',
        ], $attrs));
    }

    public function test_staff_cancelling_a_gcash_order_marked_paid_goes_to_refunds_owed_like_a_customer_cancel(): void
    {
        $order = $this->liveOrder();
        $before = \App\Models\Notification::max('id');

        $this->actingAs($this->staff(), 'admin')
            ->put('/admin/orders/' . $order->id . '/cancel')
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame('refund_pending', $order->payment_status);
        $this->assertSame('Refund owed', $order->paymentStatusLabel());

        $note = \App\Models\Notification::where('id', '>', $before)->where('type', 'refund_pending')->where('order_id', $order->id)->first();
        $this->assertNotNull($note, 'the refund is raised for staff exactly as on the customer path');
        $this->assertStringContainsString('Staff cancelled', $note->message);

        // And it is on the screen staff work from: the Refunds Owed band.
        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->assertOk()->getContent();
        $this->assertStringContainsString('Refunds Owed', $html);
        $this->assertStringContainsString($order->order_number, $html);
    }

    public function test_staff_cancelling_an_unpaid_gcash_or_a_cash_order_is_still_a_plain_cancellation(): void
    {
        $before = \App\Models\Notification::max('id');

        foreach ([
            ['payment_method' => 'gcash', 'payment_status' => 'pending'],
            ['payment_method' => 'cash',  'payment_status' => 'pending'],
        ] as $attrs) {
            $order = $this->liveOrder($attrs);

            $this->actingAs($this->staff(), 'admin')->put('/admin/orders/' . $order->id . '/cancel');

            $order->refresh();
            $this->assertSame('cancelled', $order->status);
            $this->assertSame('pending', $order->payment_status, 'no money moved, so no refund obligation');
            $this->assertSame('Cancelled', $order->paymentStatusLabel());
        }

        $this->assertSame(0, \App\Models\Notification::where('id', '>', $before)->where('type', 'refund_pending')->count());
    }

    // ══════════ the GCash page never offers to pay a cancelled order ══════════

    private function customer(): User
    {
        return User::where('email', 'pedro@gmail.com')->firstOrFail();
    }

    public function test_the_gcash_page_for_a_cancelled_order_shows_no_qr_and_links_back_to_orders(): void
    {
        $customer = $this->customer();

        foreach (['pending', 'awaiting_verification', 'refund_pending'] as $paymentStatus) {
            $order = $this->order([
                'user_id' => $customer->id, 'payment_method' => 'gcash', 'payment_status' => $paymentStatus,
            ]);

            $html = $this->actingAs($customer, 'customer')
                ->get(route('customer.gcash-payment', $order->id))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('This order was cancelled.', $html, $paymentStatus);
            $this->assertStringContainsString(route('customer.orders'), $html, $paymentStatus);
            $this->assertStringNotContainsString('I Have Paid', $html, $paymentStatus . ': no pay button');
            $this->assertStringNotContainsString('Scan the store', $html, $paymentStatus . ': no QR instructions');
            $this->assertStringNotContainsString('Waiting for Verification', $html, $paymentStatus);
        }
    }

    public function test_the_gcash_page_for_a_live_unpaid_order_still_offers_the_pay_flow(): void
    {
        $customer = $this->customer();
        $order = $this->liveOrder(['user_id' => $customer->id, 'payment_status' => 'pending']);

        $html = $this->actingAs($customer, 'customer')
            ->get(route('customer.gcash-payment', $order->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Complete Your Payment', $html);
        $this->assertStringNotContainsString('This order was cancelled.', $html);
    }

    public function test_i_have_paid_is_refused_for_a_cancelled_order(): void
    {
        $customer = $this->customer();
        $order = $this->order(['user_id' => $customer->id, 'payment_method' => 'gcash', 'payment_status' => 'pending']);
        $before = \App\Models\Notification::max('id');

        $this->actingAs($customer, 'customer')
            ->post(route('customer.gcash-payment.paid', $order->id))
            ->assertRedirect(route('customer.gcash-payment', $order->id));

        $this->assertSame('pending', $order->fresh()->payment_status, 'a dead order must not enter the verification queue');
        $this->assertSame(0, \App\Models\Notification::where('id', '>', $before)->count());
    }

    private function rowContaining(string $html, string $needle): string
    {
        foreach (preg_split('/<tr\b/', $html) as $row) {
            if (str_contains($row, $needle)) {
                return $row;
            }
        }
        $this->fail('No table row found for ' . $needle);
    }
}

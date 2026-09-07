<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The customer order tracker: a 4-step progress bar that reads differently for
 * pick-up and dine-in.
 *
 *   Pick-up : Pending → Preparing → Ready for Pick-up → Completed
 *   Dine-in : Pending → Preparing → Served           → Completed
 *
 * The app has ONE 'serving' status; only the third step's LABEL and the helper
 * line change with the order type. The first three step ids keep their original
 * names so the existing live poll (and MultiOrderStatusPollTest /
 * OrderStatusStaleDisplayTest) keep working.
 */
class CustomerOrderTrackerTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function order(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'TRK-' . substr(uniqid(), -8),
            'user_id'        => $this->customer()->id,
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

    private function render(Order $order): string
    {
        return $this->actingAs($this->customer(), 'customer')
            ->get('/customer/orders')
            ->assertOk()
            ->getContent();
    }

    /**
     * The Current Order tab renders EVERY in-flight order the customer owns, so
     * a bare assertSee() on the whole page can be satisfied (or broken) by some
     * OTHER order that happens to be open — e.g. the owner testing a real
     * pick-up order on a phone. Every tracker element already carries its own
     * order id, so pull just this order's third-step label out and assert on
     * that in isolation.
     */
    private function thirdStepLabel(string $html, int $orderId): string
    {
        $pattern = '/id="orderLabelServing-' . $orderId . '"[^>]*>(.*?)<\/span>/s';
        $this->assertMatchesRegularExpression($pattern, $html, "no tracker rendered for order {$orderId}");
        preg_match($pattern, $html, $m);

        return trim($m[1]);
    }

    // ── the four steps ──────────────────────────────────────────────────────

    public function test_the_tracker_renders_all_four_steps(): void
    {
        $order = $this->order(['status' => 'preparing']);
        $html  = $this->render($order);

        foreach (['Pending', 'Preparing', 'Completed'] as $step) {
            $this->assertStringContainsString('id="orderStep' . str_replace(' ', '', $step) . '-' . $order->id . '"', $html);
        }
        $this->assertStringContainsString('id="orderStepServing-' . $order->id . '"', $html);
        $this->assertStringContainsString('id="orderStepCompleted-' . $order->id . '"', $html);
        $this->assertStringContainsString('>Completed</span>', $html);
    }

    // ── type-aware third step ───────────────────────────────────────────────

    public function test_a_pickup_order_labels_the_third_step_ready_for_pick_up(): void
    {
        $order = $this->order(['type' => 'pick_up', 'status' => 'serving']);
        $html  = $this->render($order);

        $this->assertSame('Ready for Pick-up', $this->thirdStepLabel($html, $order->id));
    }

    public function test_a_dine_in_order_labels_the_third_step_served(): void
    {
        $order = $this->order([
            'type'         => 'dine_in',
            'table_number' => 5,
            'status'       => 'serving',
        ]);
        $html = $this->render($order);

        $this->assertSame('Served', $this->thirdStepLabel($html, $order->id));
    }

    // ── helper text ─────────────────────────────────────────────────────────

    /**
     * @dataProvider helperCases
     */
    public function test_the_helper_text_matches_the_status(string $type, string $status, string $needle): void
    {
        $html = $this->render($this->order([
            'type'         => $type,
            'table_number' => $type === 'dine_in' ? 3 : null,
            'status'       => $status,
        ]));

        $this->assertStringContainsString($needle, $html);
    }

    public static function helperCases(): array
    {
        return [
            'pending'          => ['pick_up', 'pending', 'waiting for the kitchen to start'],
            'preparing'        => ['pick_up', 'preparing', 'being prepared'],
            'pickup serving'   => ['pick_up', 'serving', 'proceed to the counter for pick-up'],
            'dine-in serving'  => ['dine_in', 'serving', 'served. Enjoy your meal'],
        ];
    }

    // ── the active step is still lit correctly (regression) ─────────────────

    public function test_a_serving_order_still_lights_the_third_step(): void
    {
        $order = $this->order(['status' => 'serving']);
        $html  = $this->render($order);

        $this->assertMatchesRegularExpression(
            '/id="orderStepServing-' . $order->id . '"[^>]*class="[^"]*active/',
            $html
        );
    }

    // ── the poll payload carries the type ──────────────────────────────────

    public function test_the_status_endpoint_reports_the_order_type(): void
    {
        $order = $this->order(['type' => 'dine_in', 'table_number' => 2, 'status' => 'serving']);

        $json = $this->actingAs($this->customer(), 'customer')
            ->getJson('/customer/orders-status?' . http_build_query(['order_ids' => [$order->id]]))
            ->assertOk()
            ->json('orders.0');

        $this->assertSame('dine_in', $json['type']);
        $this->assertSame('serving', $json['status']);
    }

    // ── the client mirror of the helper text exists ────────────────────────

    public function test_the_view_ships_the_js_helpers_the_poll_needs(): void
    {
        $source = file_get_contents(resource_path('views/customer/orders.blade.php'));

        $this->assertStringContainsString('function orderHelperTextFor(status, type)', $source);
        $this->assertStringContainsString('function updateOrderProgress(orderId, status, type)', $source);
        $this->assertStringContainsString('updateOrderProgress(order.order_id, order.status, order.type)', $source);
    }
}

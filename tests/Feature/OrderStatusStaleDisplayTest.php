<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A completed order stayed stuck under "Current Order" with its tracker
 * frozen on Preparing, and never appeared under History (2026-09-02).
 *
 * WHAT WAS ACTUALLY WRONG — the server was never the problem
 * ----------------------------------------------------------
 * Reproduced end to end against the owner's real order
 * (ORD-20260902-APZMFO): the row reads status='completed', completed_at set,
 * cancelled_at and cancellation_reason both NULL — so the earlier refused
 * cancel left no residue on it. A FRESH page load already files it correctly
 * (currentOrders empty, order present in orderHistory), and
 * /customer/orders-status already reports "completed" verbatim. Nothing
 * server-side wrote or recognised a wrong value, and there is no case
 * mismatch: 'completed' is written and 'completed' is what every server read
 * compares against.
 *
 * The disagreement was entirely in the browser, on a page left OPEN while
 * staff finished the order:
 *
 *   1. updateOrderProgress() ended at
 *          if (!['pending','preparing','serving'].includes(status)) return;
 *      so a poll reporting 'completed' was silently discarded. The card was
 *      left exactly as rendered — frozen mid-tracker, in a tab it no longer
 *      belonged in.
 *
 *   2. Card removal lived inside showOrderStatusNotice(), and only inside its
 *      `cancelled` branch. The `completed` branch showed the popup and
 *      prepared the rating control but never removed the card. That popup is
 *      also fired only when !alreadyNotified(...) — a localStorage dedupe —
 *      so whether a completed order left the tab depended on whether the
 *      customer happened to be seeing that popup for the first time.
 *
 * WHY RATING WORKED WHILE THE PAGE DID NOT. Both are driven by the SAME poll
 * response. The popup branch handled 'completed' (and calls
 * preparePopupRating(), which is where the owner rated from); the
 * card/tracker branch threw the same value away. One payload, two consumers,
 * only one of which understood a terminal status.
 *
 * THE FIX is at that root: terminal statuses are handled in
 * updateOrderProgress() before anything else, and removal moved into
 * removeFinishedOrderCard() so completed and cancelled leave by the same
 * route and removal is a consequence of the order's STATE rather than of a
 * one-time notification. No status is special-cased in the view.
 */
class OrderStatusStaleDisplayTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): User
    {
        return User::create([
            'name'      => 'Stale Display Test Customer',
            'email'     => 'stale-display-' . uniqid() . '@example.test',
            'password'  => Hash::make('irrelevant-' . uniqid()),
            'role'      => 'customer',
            'points'    => 0,
            'is_active' => true,
        ]);
    }

    private function order(User $user, string $status, array $extra = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'SDT-' . substr(uniqid(), -8),
            'user_id'        => $user->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => $status,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 80,
            'total'          => 80,
        ], $extra));
    }

    // ══════════ the reported bug, through the real admin action ══════════

    /**
     * THE REPORTED BUG, driven through the actual admin action the owner
     * used rather than by writing 'completed' directly — so the status the
     * admin action really writes is the status these assertions are made
     * against.
     */
    public function test_an_order_completed_by_admin_leaves_current_order_and_appears_in_history(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'preparing');
        $admin = User::where('role', 'admin')->orderBy('id')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->put('/admin/orders/' . $order->id . '/complete');

        // The exact value the admin action wrote.
        $this->assertSame('completed', $order->fresh()->status);

        $response = $this->actingAs($customer, 'customer')->get('/customer/orders');

        $current = $response->viewData('currentOrders')->pluck('order_number')->all();
        $history = $response->viewData('orderHistory')->pluck('order_number')->all();

        $this->assertNotContains(
            $order->order_number,
            $current,
            'a completed order must not stay under Current Order'
        );
        $this->assertContains(
            $order->order_number,
            $history,
            'a completed order must appear under History'
        );
    }

    /**
     * The auto-refresh endpoint itself, asserted on its REAL response rather
     * than on blade output — this is what the open page actually consumes,
     * and it is the half the browser was throwing away.
     */
    public function test_the_polling_endpoint_reports_a_completed_order_as_completed(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'preparing');
        $admin = User::where('role', 'admin')->orderBy('id')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->put('/admin/orders/' . $order->id . '/complete');

        $poll = $this->actingAs($customer, 'customer')
            ->getJson('/customer/orders-status?order_ids[]=' . $order->id);

        $poll->assertOk();
        $this->assertSame(
            'completed',
            $poll->json('orders.0.status'),
            'the poll must report the terminal status the browser needs in order to update'
        );
        $this->assertSame((int) $order->id, (int) $poll->json('orders.0.order_id'));
    }

    public function test_a_cancelled_order_also_leaves_current_order_and_shows_in_history(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'pending');

        $this->actingAs($customer, 'customer')
            ->post('/customer/orders/' . $order->id . '/cancel');

        $this->assertSame('cancelled', $order->fresh()->status);

        $response = $this->actingAs($customer, 'customer')->get('/customer/orders');

        $this->assertNotContains(
            $order->order_number,
            $response->viewData('currentOrders')->pluck('order_number')->all()
        );
        $this->assertContains(
            $order->order_number,
            $response->viewData('orderHistory')->pluck('order_number')->all()
        );
    }

    // ══════════ the client half, where the bug actually lived ══════════

    /**
     * The line that caused it: a terminal status must no longer be discarded
     * by updateOrderProgress(). Pinned on the source because this is browser
     * behaviour PHPUnit cannot execute — but the specific defect was a single
     * early-return, and its absence (plus the terminal branch that replaced
     * it) is exactly what can be asserted.
     */
    public function test_the_poll_consumer_no_longer_discards_terminal_statuses(): void
    {
        $source = file_get_contents(resource_path('views/customer/orders.blade.php'));

        $fn = substr($source, strpos($source, 'function updateOrderProgress'));

        $terminalBranch = strpos($fn, "if (status === 'completed' || status === 'cancelled') {");
        $unknownGuard = strpos($fn, "if (!['pending', 'preparing', 'serving'].includes(status)) return;");

        $this->assertNotFalse(
            $terminalBranch,
            'updateOrderProgress() must act on a terminal status'
        );

        /*
         * The in-flight guard is legitimate and stays — it protects against a
         * status this tracker has no stage for. What must never happen again
         * is a terminal status REACHING it, because that guard discards
         * anything it does not recognise, which is precisely how 'completed'
         * was thrown away. So the ordering is the property under test, not
         * the guard's existence.
         */
        if ($unknownGuard !== false) {
            $this->assertLessThan(
                $unknownGuard,
                $terminalBranch,
                'terminal statuses must be handled BEFORE the in-flight guard, or they are '
                . 'silently discarded again — the exact bug that left a completed order stuck on Preparing'
            );
        }

        $this->assertStringContainsString(
            'removeFinishedOrderCard(orderId);',
            $source,
            'a finished order must be taken out of the Current Order tab'
        );
    }

    /**
     * Removal must be driven by the order's state, not by a popup that is
     * deduped in localStorage — otherwise whether a completed order leaves
     * the tab depends on whether the customer has seen its notification
     * before.
     */
    public function test_card_removal_is_not_coupled_to_the_one_time_popup(): void
    {
        $source = file_get_contents(resource_path('views/customer/orders.blade.php'));

        $this->assertStringContainsString(
            'function removeFinishedOrderCard(orderId)',
            $source,
            'removal should live in one shared place, reachable from the poll'
        );

        // The completed branch of the popup must not be the only thing that
        // can retire a card: the poll calls it directly.
        $pollSection = substr($source, strpos($source, 'function updateOrderProgress'));
        $this->assertStringContainsString('removeFinishedOrderCard', $pollSection);
    }

    /**
     * The tracker must never show a stage the order has already passed. The
     * server only ever renders a tracker for in-flight orders, so the
     * guarantee is that a terminal order has no tracker card rendered at all.
     */
    public function test_the_tracker_never_renders_a_stage_the_order_has_passed(): void
    {
        $customer = $this->customer();
        $completed = $this->order($customer, 'completed', ['completed_at' => now()]);
        $serving   = $this->order($customer, 'serving');

        $html = $this->actingAs($customer, 'customer')
            ->get('/customer/orders')
            ->getContent();

        // No tracker card at all for the finished order.
        $this->assertStringNotContainsString(
            'orderStepPending-' . $completed->id,
            $html,
            'a completed order must not render a progress tracker'
        );

        // The still-live order does render one, and is marked at its true
        // stage rather than an earlier one.
        $this->assertStringContainsString('orderStepPending-' . $serving->id, $html);
        $this->assertMatchesRegularExpression(
            '/id="orderStepServing-' . $serving->id . '"[^>]*class="[^"]*active/',
            $html,
            'a serving order should have its Serving step lit, not an earlier one'
        );
    }
}

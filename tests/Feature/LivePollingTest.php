<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer pages poll ONE cheap endpoint, on ONE guarded timer chain.
 *
 * THE REPORT (Defect Report 5, item 1)
 * ------------------------------------
 * A tester watched DevTools > Network on a customer page and saw
 * unread-count and order-status requests "firing nonstop and increasing".
 *
 * WHAT WAS MEASURED (Playwright, 5 minutes per page, guest and signed-in)
 * -----------------------------------------------------------------------
 * Nothing STACKED: timer counts stayed constant through back/forward cycles and
 * three minutes of admin background swaps, and every page's request rate was
 * identical in minute 1 and minute 5. What it did was poll relentlessly:
 *
 *   menu 30/min, Orders 50/min, every other page 10/min; the menu and Orders
 *   pollers never stopped (not with no order, not after it completed); they
 *   kept firing in a hidden tab (21 requests in 40s while hidden); and a 403
 *   was retried every interval forever (35 requests in 40s).
 *
 * These assertions pin the fix: the combined endpoint, what it answers and what
 * it refuses to answer, and the shape of the client (no setInterval anywhere in
 * the customer pollers, pause/stop/back-off in the one shared chain). The timing
 * and the request counts were verified in a real browser, not simulated here.
 */
class LivePollingTest extends TestCase
{
    use DatabaseTransactions;

    private function order(string $status = 'pending', ?int $userId = null): Order
    {
        $order = Order::create([
            'order_number'   => 'LP-' . substr(uniqid(), -8),
            'user_id'        => $userId,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => $status,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ]);

        if ($userId === null) {
            GuestOrders::remember($order->id);
        }

        return $order;
    }

    private function src(string $path): string
    {
        return file_get_contents(resource_path('views/' . $path));
    }

    /** Source with Blade comments and JS comments removed: assertions are about CODE. */
    private function code(string $path): string
    {
        $s = $this->src($path);
        $s = preg_replace('/\{\{--.*?--\}\}/s', '', $s);
        $s = preg_replace('#/\*.*?\*/#s', '', $s);

        return preg_replace('#(?<![:\'"])//[^
]*#', '', $s);
    }

    /** @return array{0: array, 1: list<string>} the JSON answer and the SQL it cost */
    private function liveWithSql(string $query = ''): array
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql) { $sql[] = $q->sql; });

        $json = $this->getJson('/customer/live-status' . $query)->assertOk()->json();

        return [$json, $sql];
    }

    // ══════════ what the endpoint answers ══════════

    public function test_a_visitor_with_nothing_costs_no_orders_or_notifications_query(): void
    {
        [$json, $sql] = $this->liveWithSql();

        $this->assertSame(['unread' => 0, 'latest_id' => 0, 'orders' => [], 'session_order_id' => null, 'active' => false, 'completed_popups' => []], $json);

        foreach ($sql as $statement) {
            $this->assertStringNotContainsString('from `orders`', $statement);
            $this->assertStringNotContainsString('from `notifications`', $statement);
        }
    }

    public function test_it_answers_the_bell_and_the_order_in_one_cheap_request(): void
    {
        $order = $this->order('pending');
        session()->put('customer_order_id', $order->id);
        Notification::orderStatusChanged($order, 'preparing');

        [$json, $sql] = $this->liveWithSql('?order_ids[]=' . $order->id);

        $this->assertSame(1, $json['unread']);
        $this->assertGreaterThan(0, $json['latest_id']);
        $this->assertTrue($json['active']);
        $this->assertSame($order->id, $json['session_order_id']);
        $this->assertCount(1, $json['orders'], 'the requested order and the session order are the same one');
        $this->assertSame('pending', $json['orders'][0]['status']);

        // TWO orders queries + ONE notifications query. (Before: the bell alone
        // ran two notification queries, plus a separate order endpoint or two.)
        // The second orders query is the Order Completed pop-up's queue (QA, Nov 2
        // 2026): it has to answer on every page, whatever that page is watching.
        // Count statements whose PRIMARY table is X (a guest's notifications query
        // also mentions `orders` inside an EXISTS subquery; that is not a second query).
        $touching = fn (string $table) => count(array_filter($sql, fn ($s) => preg_match('/^select .*? from `(\w+)`/', $s, $m) === 1 && $m[1] === $table));
        $this->assertSame(2, $touching('orders'));
        $this->assertSame(1, $touching('notifications'));
    }

    public function test_the_bell_numbers_match_the_old_unread_count_endpoint(): void
    {
        $order = $this->order('pending');
        Notification::orderStatusChanged($order, 'preparing');
        Notification::orderStatusChanged($order, 'serving');

        $old  = $this->getJson('/customer/notifications/unread-count')->assertOk()->json();
        $live = $this->getJson('/customer/live-status')->assertOk()->json();

        $this->assertSame($old['unread'], $live['unread']);
        $this->assertSame($old['latest_id'], $live['latest_id']);
    }

    public function test_the_order_payload_is_identical_to_the_old_order_status_endpoint(): void
    {
        $order = $this->order('preparing');
        session()->put('customer_order_id', $order->id);

        $old  = $this->getJson('/customer/order-status?order_id=' . $order->id)->assertOk()->json();
        $live = $this->getJson('/customer/live-status?order_ids[]=' . $order->id)->assertOk()->json('orders.0');

        $this->assertSame($old, $live);
    }

    public function test_the_old_endpoints_still_work(): void
    {
        $order = $this->order('pending');
        session()->put('customer_order_id', $order->id);

        $this->getJson('/customer/order-status')->assertOk()->assertJsonPath('has_order', true);
        $this->getJson('/customer/orders-status?order_ids[]=' . $order->id)->assertOk()->assertJsonPath('orders.0.order_id', $order->id);
        $this->getJson('/customer/notifications/unread-count')->assertOk()->assertJsonStructure(['unread', 'latest_id']);
    }

    // ══════════ "only ask about an order while it is open" ══════════

    public function test_a_finished_session_order_is_not_reported_after_the_first_look(): void
    {
        $order = $this->order('completed');
        session()->put('customer_order_id', $order->id);

        // Steady state: nothing is asked about it and it is not volunteered.
        $steady = $this->getJson('/customer/live-status')->assertOk()->json();
        $this->assertSame([], $steady['orders']);
        $this->assertFalse($steady['active']);

        // First poll of a page load: reported once, whatever its status, so an
        // order that finished before the page opened still gets its popup.
        $first = $this->getJson('/customer/live-status?discover=1')->assertOk()->json();
        $this->assertCount(1, $first['orders']);
        $this->assertSame('completed', $first['orders'][0]['status']);
        $this->assertFalse($first['active'], 'a completed order must not keep the fast cadence on');
    }

    public function test_an_order_the_page_asks_about_is_answered_even_when_it_just_finished(): void
    {
        // The poll that must notice the transition is the one that asks by id.
        $order = $this->order('cancelled');

        $json = $this->getJson('/customer/live-status?order_ids[]=' . $order->id)->assertOk()->json();

        $this->assertSame('cancelled', $json['orders'][0]['status']);
        $this->assertFalse($json['active']);
    }

    public function test_an_open_session_order_is_volunteered_without_being_asked_for(): void
    {
        // An order placed in another tab is noticed, and switches to fast polling.
        $order = $this->order('preparing');
        session()->put('customer_order_id', $order->id);

        $json = $this->getJson('/customer/live-status')->assertOk()->json();

        $this->assertTrue($json['active']);
        $this->assertSame($order->id, $json['orders'][0]['order_id']);
    }

    // ══════════ ownership ══════════

    public function test_another_visitors_order_is_never_answered(): void
    {
        $theirs = $this->order('pending');
        $this->flushSession();

        $json = $this->getJson('/customer/live-status?order_ids[]=' . $theirs->id)->assertOk()->json();

        $this->assertSame([], $json['orders']);
        $this->assertSame(0, $json['unread']);
    }

    public function test_a_signed_in_customer_only_gets_their_own_orders(): void
    {
        $me    = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);

        $mine   = $this->order('pending', $me->id);
        $theirs = $this->order('pending', $other->id);

        $json = $this->actingAs($me, 'customer')
            ->getJson('/customer/live-status?order_ids[]=' . $mine->id . '&order_ids[]=' . $theirs->id)
            ->assertOk()->json();

        $this->assertSame([$mine->id], array_column($json['orders'], 'order_id'));
    }

    public function test_a_junk_id_list_is_harmless(): void
    {
        $this->getJson('/customer/live-status?order_ids[]=abc&order_ids[]=-4&order_ids[]=0&order_ids[]=')
            ->assertOk()->assertJsonPath('orders', []);
    }

    public function test_the_endpoint_shares_the_bells_named_limiter_and_is_never_cached(): void
    {
        $route = app('router')->getRoutes()->getByName('customer.live-status');
        $this->assertNotNull($route);
        $this->assertContains('throttle:customer-notifications', $route->gatherMiddleware());

        $this->assertStringContainsString('no-store', (string) $this->getJson('/customer/live-status')->headers->get('Cache-Control'));
    }

    // ══════════ the client: no stacking, pause, stop, back off ══════════

    public static function customerPollerSources(): array
    {
        return [
            'poll-chain'      => ['partials/poll-chain.blade.php'],
            'live-poller'     => ['customer/partials/live-poller.blade.php'],
            'bell'            => ['customer/partials/notification-bell.blade.php'],
            'help status'     => ['customer/partials/help-status-poll.blade.php'],
            'dine-in guard'   => ['customer/partials/dine-in-session-guard.blade.php'],
            'menu'            => ['customer/menu.blade.php'],
            'orders'          => ['customer/orders.blade.php'],
            'cart'            => ['customer/cart.blade.php'],
            'gcash payment'   => ['customer/gcash-payment.blade.php'],
            'admin bell'      => ['admin/partials/notification-bell.blade.php'],
            'admin board'     => ['admin/home.blade.php'],
        ];
    }

    /**
     * @dataProvider customerPollerSources
     */
    public function test_no_background_poller_is_a_bare_setinterval_on_a_network_call(string $path): void
    {
        $source = $this->code($path);

        // setInterval is fine for local clocks (a countdown, a "last updated"
        // label). What must be gone is a setInterval whose body talks to the
        // server: it never waits for the previous request, never slows down and
        // cannot be paused.
        preg_match_all('/setInterval\(([^;]{0,400})/s', $source, $m);
        $this->assertTrue(true);
        foreach ($m[1] as $body) {
            $this->assertDoesNotMatchRegularExpression(
                '/poll|status|check|refresh|fetch|notif/i',
                $body,
                "{$path} still drives a network poll from setInterval: setInterval({$body}"
            );
        }
    }

    public function test_the_chain_is_one_guarded_setTimeout_loop(): void
    {
        $chain = $this->code('partials/poll-chain.blade.php');

        $this->assertSame(0, substr_count($chain, 'setInterval('));
        // Idempotent definition and idempotent start: calling either twice
        // cannot create a second loop.
        $this->assertStringContainsString('if (window.PomidaPollChain) { return; }', $chain);
        $this->assertStringContainsString('if (running) { return; }', $chain);
        // Never two requests in flight, never a timer armed while one is.
        $this->assertStringContainsString('if (!running || document.hidden || inFlight) { return; }', $chain);
        // Each armed timer replaces the last.
        $this->assertStringContainsString('function arm(ms) {', $chain);
        $this->assertMatchesRegularExpression('/function arm\(ms\) \{\s*clear\(\);/', $chain);
    }

    public function test_the_chain_pauses_when_hidden_and_asks_once_when_visible(): void
    {
        $chain = $this->src('partials/poll-chain.blade.php');

        $this->assertStringContainsString("addEventListener('visibilitychange'", $chain);
        $this->assertMatchesRegularExpression('/if \(document\.hidden\) \{ clear\(\); \} else \{ resumeNow\(\); \}/', $chain);
        $this->assertStringContainsString("addEventListener('pageshow'", $chain, 'a bfcache restore must resume the chain');
        $this->assertStringContainsString("addEventListener('pagehide', clear)", $chain);
    }

    public function test_the_chain_backs_off_resets_on_change_and_on_activity_and_stops_on_auth_errors(): void
    {
        $chain = $this->src('partials/poll-chain.blade.php');

        // unchanged → next, slower rung; changed → fastest rung
        $this->assertStringContainsString('if (res.changed) { idx = 0; } else { idx++; }', $chain);
        // a customer touching the screen snaps it back to the fastest rung
        $this->assertStringContainsString("['pointerdown', 'keydown', 'touchstart']", $chain);
        // never retried forever on an auth refusal
        $this->assertStringContainsString('STOP_STATUSES = [401, 403, 419]', $chain);
        $this->assertStringContainsString('return { stop: true };', $chain);
        // a failing request is not hammered
        $this->assertStringContainsString('ERROR_FLOOR_MS', $chain);
    }

    public function test_the_shared_customer_poll_has_the_agreed_ladders(): void
    {
        $poller = $this->src('customer/partials/live-poller.blade.php');

        $this->assertStringContainsString('LADDER_ACTIVE = [5000, 5000, 10000]', $poller);
        $this->assertStringContainsString('LADDER_IDLE = [5000, 10000, 20000, 30000]', $poller);
        // The first poll of a page load also looks at the session's order.
        $this->assertStringContainsString("q.push('discover=1')", $poller);
        // Started exactly once, after the page's own scripts subscribed.
        $this->assertSame(2, substr_count($poller, 'chain.start()'));
        $this->assertStringContainsString("document.addEventListener('DOMContentLoaded'", $poller);
    }

    public function test_pages_subscribe_instead_of_running_their_own_order_status_timer(): void
    {
        foreach (['customer/menu.blade.php', 'customer/orders.blade.php'] as $page) {
            $source = $this->src($page);

            $this->assertStringContainsString('PomidaLive.subscribe(', $source, $page);
            $this->assertStringNotContainsString('statusPollTimer', $source, $page);
            $this->assertStringNotContainsString("route(\"customer.order-status\")", $source, $page);
            $this->assertStringNotContainsString("route(\"customer.orders-status\")", $source, $page);
        }
    }

    public function test_every_customer_page_still_carries_the_poll_through_the_bell(): void
    {
        // The poll lives in the bell's include chain, so a page without the bell
        // would silently stop hearing about its order.
        $bell = $this->src('customer/partials/notification-bell.blade.php');
        $this->assertStringContainsString("@include('customer.partials.live-poller')", $bell);

        $poller = $this->src('customer/partials/live-poller.blade.php');
        $this->assertStringContainsString("@include('partials.poll-chain')", $poller);
    }
}

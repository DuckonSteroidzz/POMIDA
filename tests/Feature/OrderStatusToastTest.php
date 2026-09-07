<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Order status changes have to be NOTICED, not just recorded.
 *
 * THE BUG THIS EXISTS FOR
 * -----------------------
 * Reported live: staff move an order to Preparing and then Serving and the
 * customer sees nothing; the update only turns up if they happen to open the
 * notification bell. The owner's scenario was precise — a customer sitting on
 * the Spin & Win page while their order quietly becomes "Serving", then hunting
 * around the app wondering where it is.
 *
 * WHAT WAS AND WAS NOT BROKEN (established with a real two-browser run before
 * anything was changed):
 *
 *   - Notification rows ARE written for preparing and serving. Never the issue.
 *   - The item-34 toast layer DOES fire for preparing and serving. Also never
 *     the issue — it was verified toasting all three statuses on the menu page.
 *   - The game page carried NO notification surface at all: no bell, no badge,
 *     no toast stack. Driving an order through preparing -> serving ->
 *     completed with a browser parked there produced literally nothing. That
 *     one omission is the whole of the reported bug.
 *   - The 20s poll was additionally too slow to feel like a reaction.
 *
 * These assertions pin the parts that live server-side and in the markup. The
 * timing and the visual result were verified with Playwright against a real
 * server; that is not re-simulated here.
 */
class OrderStatusToastTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Pages a customer can be sitting on while an order is in flight. Every one
     * of them must carry the notification partial.
     */
    public static function orderAwarePages(): array
    {
        return [
            'menu'          => ['menu'],
            'orders'        => ['orders'],
            'cart'          => ['cart'],
            'item-details'  => ['item-details'],
            'more'          => ['more'],
            'receipt'       => ['receipt'],
            'account'       => ['account-settings'],
            // The three added here. 'game' is the reported one.
            'game'          => ['game'],
            'vouchers'      => ['vouchers'],
            'gcash-payment' => ['gcash-payment'],
        ];
    }

    /**
     * @dataProvider orderAwarePages
     */
    public function test_the_page_carries_the_notification_surface(string $page): void
    {
        $source = file_get_contents(resource_path("views/customer/{$page}.blade.php"));

        // The bell reaches most pages through the shared desktop-nav partial;
        // a few pages with a bespoke header still include it directly.
        $hasBell = str_contains($source, "@include('customer.partials.notification-bell')")
            || str_contains($source, "@include('customer.partials.desktop-nav')");

        $this->assertTrue(
            $hasBell,
            "customer/{$page}.blade.php has no notification surface (neither the bell "
            . 'nor the desktop-nav partial that carries it), so an order update cannot '
            . 'reach a customer sitting on it'
        );
    }

    /** The shared nav partial must actually carry the bell. */
    public function test_the_shared_desktop_nav_carries_the_bell(): void
    {
        $this->assertStringContainsString(
            "@include('customer.partials.notification-bell')",
            file_get_contents(resource_path('views/customer/partials/desktop-nav.blade.php')),
            'customer/partials/desktop-nav.blade.php dropped the notification bell — '
            . 'every page that relies on the shared nav just lost its order updates'
        );
    }

    public function test_the_poll_is_fast_enough_to_feel_like_a_reaction(): void
    {
        $partial = file_get_contents(
            resource_path('views/customer/partials/notification-bell.blade.php')
        );

        // 20s was long enough that the customer had already gone looking for
        // someone to ask. Guarded so it cannot quietly drift back.
        $this->assertMatchesRegularExpression('/var POLL_MS = (\d+);/', $partial);
        preg_match('/var POLL_MS = (\d+);/', $partial, $m);

        $this->assertLessThanOrEqual(6000, (int) $m[1], 'the notification poll got slower again');
        // And not so fast that it eats the throttle:60,1 on the endpoint.
        $this->assertGreaterThanOrEqual(5000, (int) $m[1], 'the poll would now outrun its own rate limit');
    }

    public function test_there_is_still_only_one_polling_loop_behind_the_bell(): void
    {
        $partial = file_get_contents(
            resource_path('views/customer/partials/notification-bell.blade.php')
        );

        // The badge and the toast layer deliberately share one poll. A second
        // setInterval here would double the request rate for no benefit.
        $this->assertSame(1, substr_count($partial, 'setInterval('));
    }

    // ══════════ what the toast layer is actually fed ══════════

    private function guestOrder(): Order
    {
        $order = Order::create([
            'order_number'   => 'TS-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ]);

        GuestOrders::remember($order->id);

        return $order;
    }

    public static function announcedStatuses(): array
    {
        return [
            'preparing' => ['preparing'],
            'serving'   => ['serving'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
        ];
    }

    /**
     * @dataProvider announcedStatuses
     */
    public function test_the_feed_carries_the_status_change_to_a_guest(string $status): void
    {
        $order = $this->guestOrder();

        Notification::orderStatusChanged($order, $status);

        $feed = $this->getJson('/customer/notifications')->assertOk()->json('notifications');
        $row  = collect($feed)->firstWhere('order_number', $order->order_number);

        $this->assertNotNull($row, "a '{$status}' change never reached the guest's feed");
        $this->assertSame('order_status_changed', $row['type']);
    }

    public function test_every_notification_says_which_order_it_is_about(): void
    {
        /*
         * A customer can have more than one order in flight at once since item
         * 43 / Round 3A, so "Order is being prepared" on its own is ambiguous.
         * The toast renders this as a chip; without it the customer has to
         * parse the order number out of the end of a sentence.
         */
        $first  = $this->guestOrder();
        $second = $this->guestOrder();

        Notification::orderStatusChanged($first, 'serving');
        Notification::orderStatusChanged($second, 'preparing');

        $feed = collect($this->getJson('/customer/notifications')->assertOk()->json('notifications'));

        foreach ([$first, $second] as $order) {
            $row = $feed->firstWhere('order_number', $order->order_number);
            $this->assertNotNull($row, "order {$order->order_number} is not identified in the feed");
        }

        $this->assertNotSame(
            $feed->firstWhere('order_number', $first->order_number)['id'],
            $feed->firstWhere('order_number', $second->order_number)['id']
        );
    }

    public function test_a_different_session_is_told_nothing(): void
    {
        $order = $this->guestOrder();
        Notification::orderStatusChanged($order, 'serving');

        // Someone else's browser: no claim on this order.
        $this->flushSession();

        $feed = $this->getJson('/customer/notifications')->assertOk()->json('notifications');

        $this->assertNull(
            collect($feed)->firstWhere('order_number', $order->order_number),
            'another visitor was shown this order update'
        );
    }

    public function test_the_unread_count_endpoint_reports_a_rising_latest_id(): void
    {
        $order = $this->guestOrder();

        $before = $this->getJson('/customer/notifications/unread-count')->assertOk()->json('latest_id');

        Notification::orderStatusChanged($order, 'preparing');

        $after = $this->getJson('/customer/notifications/unread-count')->assertOk()->json();

        // The toast layer detects new arrivals by this id rising, not by the
        // unread count, so it is the thing that must move.
        $this->assertGreaterThan($before, $after['latest_id']);
        $this->assertGreaterThanOrEqual(1, $after['unread']);
    }
}

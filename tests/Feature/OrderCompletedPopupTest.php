<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\OrderRating;
use App\Models\User;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The one shared "Order Completed / How was your meal?" pop-up (QA, Nov 2 2026).
 *
 * The bug: two copies of the pop-up, Menu (OK only) and Orders (with stars),
 * shared one localStorage "seen" flag written when either opened, and each only
 * watched one order on its own page. So an order finished on the Menu page could
 * never be rated from a pop-up, and a guest could never rate it at all.
 *
 * These tests pin the server's half: who is asked about what, and that only an
 * answer ends it. The browser half was checked in Playwright; what is asserted
 * about source here is that the old copies and the old flag cannot come back.
 */
class OrderCompletedPopupTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());
    }

    private function customer(): User
    {
        return User::create([
            'name'              => 'Popup Tester',
            'email'             => 'popup-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ])->fresh();
    }

    private function order(?int $userId, string $status = 'completed', string $type = 'pick_up', int $branchId = 1): Order
    {
        return Order::create([
            'order_number'   => 'OCP-' . substr(uniqid(), -8),
            'user_id'        => $userId,
            'branch_id'      => $branchId,
            'type'           => $type,
            'status'         => $status,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ]);
    }

    private function guestOrder(string $status = 'completed', string $type = 'dine_in', int $branchId = 1): Order
    {
        $order = $this->order(null, $status, $type, $branchId);
        GuestOrders::remember($order->id);

        return $order;
    }

    /** @return int[] the order ids the poll says are waiting for the pop-up */
    private function queued(): array
    {
        return array_column($this->getJson('/customer/live-status')->assertOk()->json('completed_popups'), 'order_id');
    }

    private function src(string $path): string
    {
        return file_get_contents(resource_path('views/' . $path));
    }

    // ══════════ every branch, both order types, guest and account ══════════

    public static function branchAndType(): array
    {
        return [
            'main, pick-up' => [1, 'pick_up'],
            'main, dine-in' => [1, 'dine_in'],
            'second branch, pick-up' => [2, 'pick_up'],
            'second branch, dine-in' => [2, 'dine_in'],
        ];
    }

    #[DataProvider('branchAndType')]
    public function test_an_account_is_asked_at_every_branch_and_type(int $branchId, string $type): void
    {
        $user = $this->customer();
        $order = $this->order($user->id, 'completed', $type, $branchId);

        $json = $this->actingAs($user, 'customer')->getJson('/customer/live-status')->assertOk()->json('completed_popups');

        $this->assertSame([[
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'receipt_url' => route('customer.receipt', $order->id),
        ]], $json);
    }

    #[DataProvider('branchAndType')]
    public function test_a_guest_is_asked_at_every_branch_and_type(int $branchId, string $type): void
    {
        $order = $this->guestOrder('completed', $type, $branchId);

        $this->assertSame([$order->id], $this->queued());
    }

    public function test_a_completed_order_is_found_whatever_the_session_is_pointing_at(): void
    {
        // The Menu page only ever watched session('customer_order_id'), the
        // newest order, so the older of two orders completing showed nothing.
        $user = $this->customer();
        $older = $this->order($user->id);
        $newer = $this->order($user->id, 'preparing');

        $this->actingAs($user, 'customer')->withSession(['customer_order_id' => $newer->id]);

        $this->assertSame([$older->id], $this->queued());
    }

    public function test_it_needs_no_session_memory_of_the_order_at_all(): void
    {
        // Signed out while it was open, signed back in on a new session.
        $user = $this->customer();
        $order = $this->order($user->id);

        $this->actingAs($user, 'customer')->withSession(['customer_order_id' => null]);

        $this->assertSame([$order->id], $this->queued());
    }

    public function test_several_orders_come_oldest_first_for_one_at_a_time(): void
    {
        $user = $this->customer();
        $first = $this->order($user->id);
        $second = $this->order($user->id);
        $third = $this->order($user->id);

        $this->actingAs($user, 'customer');

        $this->assertSame([$first->id, $second->id, $third->id], $this->queued());
    }

    public function test_an_order_placed_as_a_guest_before_signing_in_is_still_asked_about(): void
    {
        $user = $this->customer();
        $guestOrder = $this->guestOrder();

        $this->actingAs($user, 'customer');

        $this->assertSame([$guestOrder->id], $this->queued());
    }

    // ══════════ who is NOT asked ══════════

    public function test_only_completed_ratable_orders_of_this_visitor_are_listed(): void
    {
        $user = $this->customer();
        $this->order($user->id, 'pending');
        $this->order($user->id, 'preparing');
        $this->order($user->id, 'serving');
        $this->order($user->id, 'cancelled');
        $this->order($user->id, 'completed', 'walk_in');
        $this->order($this->customer()->id);

        $this->actingAs($user, 'customer');

        $this->assertSame([], $this->queued());
    }

    public function test_a_guest_is_never_asked_about_an_order_this_session_did_not_place(): void
    {
        $this->order(null);
        $this->order($this->customer()->id);

        $this->assertSame([], $this->queued());
    }

    public function test_a_guest_with_orders_of_their_own_sees_only_those(): void
    {
        $mine = $this->guestOrder();
        $this->order(null);

        $this->assertSame([$mine->id], $this->queued());
    }

    public function test_a_guest_session_pointed_at_an_account_order_gets_nothing(): void
    {
        $theirs = $this->order($this->customer()->id);
        GuestOrders::remember($theirs->id);

        $this->assertSame([], $this->queued());
    }

    public function test_a_guest_starting_a_new_table_visit_drops_the_old_orders(): void
    {
        // The rating endpoint would refuse these, so the pop-up must not offer them.
        $this->guestOrder();
        GuestOrders::forget();

        $this->assertSame([], $this->queued());
    }

    // ══════════ only an answer ends it ══════════

    public function test_showing_it_marks_nothing_so_a_reload_or_another_page_asks_again(): void
    {
        $user = $this->customer();
        $order = $this->order($user->id);

        $this->actingAs($user, 'customer');

        foreach (['/customer/menu', '/customer/orders', '/customer/game', '/customer/more'] as $page) {
            $this->get($page)->assertOk();
            $this->assertSame([$order->id], $this->queued(), $page);
        }

        $this->assertNull($order->fresh()->rating_prompt_dismissed_at);
    }

    public function test_submitting_a_rating_ends_it(): void
    {
        $user = $this->customer();
        $order = $this->order($user->id);

        $this->actingAs($user, 'customer')
            ->postJson(route('customer.orders.rating', $order->id), ['rating' => 4, 'rating_type' => 'stars'])
            ->assertOk()
            ->assertJson(['ok' => true, 'rating' => 4]);

        $this->assertSame([], $this->queued());
    }

    public function test_a_rating_made_from_history_ends_it_too(): void
    {
        $user = $this->customer();
        $order = $this->order($user->id);
        OrderRating::create(['order_id' => $order->id, 'user_id' => $user->id, 'rating_type' => 'stars', 'rating' => 5]);

        $this->actingAs($user, 'customer');

        $this->assertSame([], $this->queued());
    }

    public function test_not_now_ends_it_and_the_order_can_still_be_rated_from_history(): void
    {
        $user = $this->customer();
        $order = $this->order($user->id);

        $this->actingAs($user, 'customer')
            ->postJson(route('customer.orders.rating-prompt.dismiss', $order->id))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertNotNull($order->fresh()->rating_prompt_dismissed_at);
        $this->assertSame([], $this->queued());

        $history = $this->get('/customer/orders')->assertOk()->getContent();
        $this->assertStringContainsString('data-rate-meal="' . $order->id . '"', $history);

        $this->postJson(route('customer.orders.rating', $order->id), ['rating' => 2])
            ->assertOk()
            ->assertJson(['ok' => true, 'rating' => 2]);
    }

    public function test_a_guest_can_answer_both_ways(): void
    {
        $rated = $this->guestOrder();
        $skipped = $this->guestOrder();

        $this->postJson(route('customer.orders.rating', $rated->id), ['rating' => 3])->assertOk();
        $this->postJson(route('customer.orders.rating-prompt.dismiss', $skipped->id))->assertOk();

        $this->assertSame([], $this->queued());
    }

    public function test_not_now_only_affects_that_one_order(): void
    {
        $user = $this->customer();
        $first = $this->order($user->id);
        $second = $this->order($user->id);

        $this->actingAs($user, 'customer')->postJson(route('customer.orders.rating-prompt.dismiss', $first->id))->assertOk();

        $this->assertSame([$second->id], $this->queued());
    }

    public function test_not_now_leaves_updated_at_alone_and_is_safe_to_repeat(): void
    {
        $user = $this->customer();
        $order = $this->order($user->id);
        DB::table('orders')->where('id', $order->id)->update(['updated_at' => '2026-01-01 10:00:00']);

        $this->actingAs($user, 'customer');
        $this->postJson(route('customer.orders.rating-prompt.dismiss', $order->id))->assertOk();
        $stamp = $order->fresh()->rating_prompt_dismissed_at;
        $this->postJson(route('customer.orders.rating-prompt.dismiss', $order->id))->assertOk();

        $this->assertSame('2026-01-01 10:00:00', DB::table('orders')->where('id', $order->id)->value('updated_at'));
        $this->assertEquals($stamp, $order->fresh()->rating_prompt_dismissed_at);
    }

    public function test_not_now_refuses_someone_elses_order(): void
    {
        $theirs = $this->order($this->customer()->id);

        $this->actingAs($this->customer(), 'customer')
            ->postJson(route('customer.orders.rating-prompt.dismiss', $theirs->id))
            ->assertNotFound();

        $this->assertNull($theirs->fresh()->rating_prompt_dismissed_at);
    }

    public function test_not_now_refuses_a_guest_on_an_order_this_session_did_not_place(): void
    {
        $other = $this->order(null);

        $this->postJson(route('customer.orders.rating-prompt.dismiss', $other->id))->assertNotFound();

        $this->assertNull($other->fresh()->rating_prompt_dismissed_at);
    }

    public function test_not_now_refuses_an_order_that_is_not_completed(): void
    {
        $user = $this->customer();
        $open = $this->order($user->id, 'preparing');

        $this->actingAs($user, 'customer')
            ->postJson(route('customer.orders.rating-prompt.dismiss', $open->id))
            ->assertStatus(422);

        $this->assertNull($open->fresh()->rating_prompt_dismissed_at);
    }

    public function test_not_now_is_rate_limited_like_the_rating_it_sits_beside(): void
    {
        $route = app('router')->getRoutes()->getByName('customer.orders.rating-prompt.dismiss');

        $this->assertContains('throttle:order-rating', $route->gatherMiddleware());
        $this->assertContains('throttle.friendly', $route->gatherMiddleware());
    }

    // ══════════ one copy, everywhere ══════════

    public function test_every_customer_page_carries_exactly_one_copy(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $user = $this->customer();
        $order = $this->order($user->id);
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1, 'order_type' => 'pick_up']);

        $inventory = Inventory::create([
            'branch_id' => 1, 'item_name' => 'Popup Ingredient ' . uniqid(), 'item_code' => 'OCP-' . strtoupper(substr(uniqid(), -8)),
            'quantity' => 50, 'unit' => 'pc', 'low_stock_alert' => 0, 'unit_cost' => 5, 'is_active' => true,
        ]);
        $item = MenuItem::create([
            'category_id' => Category::value('id'), 'branch_id' => 1, 'name' => 'Popup Item ' . uniqid(),
            'price' => 100, 'cost' => 0, 'is_available' => true, 'display_order' => 0,
        ]);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inventory->id, 'quantity_used' => 1]);

        $pages = [
            '/customer/menu', '/customer/orders', '/customer/game', '/customer/cart', '/customer/more',
            '/customer/vouchers', '/customer/help', '/customer/account',
            route('customer.item', $item->id), route('customer.receipt', $order->id),
        ];

        foreach ($pages as $page) {
            $html = $this->get($page)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'id="orderCompletedPopup"'), $page);
        }
    }

    public function test_a_guest_page_carries_it_too(): void
    {
        $this->guestOrder();

        $html = $this->get('/customer/menu')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="orderCompletedPopup"'));
    }

    public function test_the_copy_always_offers_stars_submit_not_now_and_the_receipt(): void
    {
        $html = $this->get('/customer/menu')->assertOk()->getContent();

        $this->assertStringContainsString('Order Completed', $html);
        $this->assertStringContainsString('How was your meal?', $html);
        $this->assertStringContainsString('Submit Rating', $html);
        $this->assertStringContainsString('Not now', $html);
        $this->assertStringContainsString('View Receipt', $html);

        foreach (range(1, 5) as $star) {
            $this->assertStringContainsString('data-ocp-star="' . $star . '"', $html);
        }
    }

    public function test_the_pages_own_copies_are_gone(): void
    {
        foreach (['customer/menu.blade.php', 'customer/orders.blade.php'] as $view) {
            $source = $this->src($view);

            $this->assertStringNotContainsString('popupRatingBlock', $source, $view);
            $this->assertStringNotContainsString('submitPopupRating', $source, $view);
            $this->assertStringNotContainsString("title.textContent = 'Order Completed'", $source, $view);
            $this->assertStringNotContainsString('showOrderStatusNotice', $source, $view);
        }
    }

    public function test_nothing_in_the_browser_remembers_a_completed_order_as_seen(): void
    {
        // The old flag was written when the pop-up opened: the heart of the bug.
        $this->assertStringNotContainsString('localStorage', $this->src('customer/partials/order-completed-popup.blade.php'));

        foreach (['customer/menu.blade.php', 'customer/orders.blade.php'] as $view) {
            // What remains of that flag is for the cancelled notice only.
            $this->assertStringNotContainsString('if (orderId) markNotified(orderId, status)', $this->src($view), $view);
            $this->assertStringContainsString("if (orderId) markNotified(orderId, 'cancelled')", $this->src($view), $view);
        }
    }

    public function test_a_cancelled_order_keeps_its_own_notice(): void
    {
        foreach (['customer/menu.blade.php', 'customer/orders.blade.php'] as $view) {
            $source = $this->src($view);

            $this->assertStringContainsString('showOrderCancelledNotice', $source, $view);
            $this->assertStringContainsString('Order Cancelled', $source, $view);
        }
    }

    public function test_the_receipt_page_leaves_its_own_order_alone(): void
    {
        $user = $this->customer();
        $order = $this->order($user->id);

        $this->actingAs($user, 'customer');

        $this->assertStringContainsString(
            'data-skip-order="' . $order->id . '"',
            $this->get(route('customer.receipt', $order->id))->assertOk()->getContent()
        );
        $this->assertStringContainsString('data-skip-order="0"', $this->get('/customer/menu')->assertOk()->getContent());
    }

    public function test_the_other_overlays_wait_for_it(): void
    {
        foreach ([
            'customer/partials/free-dessert-prompt.blade.php',
            'customer/partials/dinein-rewards-popup.blade.php',
            'customer/partials/menu-ad-popup.blade.php',
        ] as $partial) {
            $this->assertStringContainsString('orderCompletedPopup', $this->src($partial), $partial);
        }
    }

    /** How many statements one poll spent on the pop-up's queue. */
    private function queueQueries(): int
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql) { $sql[] = $q->sql; });

        $this->getJson('/customer/live-status')->assertOk();

        return count(array_filter($sql, fn ($s) => str_contains($s, 'rating_prompt_dismissed_at')));
    }

    public function test_a_visitor_with_no_orders_costs_the_poll_no_query(): void
    {
        $this->assertSame(0, $this->queueQueries());
    }

    public function test_a_signed_in_customer_costs_the_poll_one_query(): void
    {
        $user = $this->customer();
        $this->order($user->id);
        $this->actingAs($user, 'customer');

        $this->assertSame(1, $this->queueQueries());
    }
}

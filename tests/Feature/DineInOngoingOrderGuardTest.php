<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\TableEntry;
use App\Support\DineInJoin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ConfiguresStreakReward;
use Tests\TestCase;

/**
 * A Dine-In guest cannot log in or sign up while their own order is in
 * progress (item H, Oct 2026 defect report).
 *
 * The bug: tap Sign up / Log in after ordering and the order disappeared for
 * the customer while staff still saw it, because signing in replaces the
 * session that proves the order is theirs. Now, for a GUEST seated at a TABLE
 * whose own session placed an order that is not completed / cancelled /
 * rejected:
 *   - the Orders-page "Want a stamp?" card is not shown;
 *   - the login and register routes (GET and POST) and the Sign up / Log in
 *     join links redirect back with one fixed message, and do nothing else;
 *   - the buttons carry data-ongoing-order-block so the page opens an in-page
 *     prompt instead of navigating.
 * Completed, the card and the routes work as before (same-shop-day claim
 * included, see DineInSignupClaimTest); cancelled or rejected, never a claim,
 * and sign up / log in are open again. A signed-in customer and a pick-up
 * session are never affected.
 *
 * Everything goes through the real doors: the table QR landing, place-order,
 * the customer cancel route, staff Complete, login and register.
 */
class DineInOngoingOrderGuardTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresStreakReward;

    private const NOW_UTC = '2026-10-15 04:00:00';

    private const PASSWORD = 'Peachy!2026';

    private const MESSAGE = 'You have an ongoing order. Please complete or cancel it before creating a new account or logging in.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW_UTC, 'UTC'));

        config(['app.shop_timezone' => 'Asia/Manila']);

        $this->clearStreakRewardSettings();
        Cache::flush();
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ══════════ fixtures ══════════

    private function customer(): User
    {
        return User::create([
            'name'              => 'Ongoing Guard Customer',
            'email'             => 'ongoing-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => self::PASSWORD,
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ])->fresh();
    }

    private function admin(): User
    {
        return User::create([
            'name'              => 'Ongoing Guard Admin',
            'email'             => 'ongoing-admin-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => self::PASSWORD,
            'role'              => 'admin',
            'branch_id'         => null,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function item(int $branchId = 1): MenuItem
    {
        $inv = Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => 'OGD Ingredient ' . uniqid(),
            'item_code'       => 'OGD-' . strtoupper(substr(uniqid(), -8)) . random_int(10, 99),
            'quantity'        => 50,
            'unit'            => 'pc',
            'low_stock_alert' => 0,
            'unit_cost'       => 5,
            'is_active'       => true,
        ]);

        $item = MenuItem::create([
            'category_id'   => Category::value('id'),
            'branch_id'     => $branchId,
            'name'          => 'OGD Item ' . uniqid(),
            'price'         => 100,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        return $item->fresh();
    }

    private function cart(MenuItem $item): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => (float) $item->price,
            'quantity'     => 1,
            'image'        => null,
            'options'      => [],
        ]];
    }

    private function table(int $branchId = 1): RestaurantTable
    {
        do {
            $number = (string) random_int(1000000, 9999999);
        } while (
            RestaurantTable::where('branch_id', $branchId)->where('table_number', $number)->exists()
            || Order::where('branch_id', $branchId)->where('table_number', $number)->exists()
        );

        return TableEntry::findOrRegister($branchId, $number);
    }

    private function qrUrl(RestaurantTable $table): string
    {
        return '/customer/menu?branch_id=' . $table->branch_id . '&table=' . $table->table_number . '&k=' . $table->code;
    }

    /** A guest seated at a table, with one Dine-In order placed through the real route. */
    private function seatedGuestWithOrder(): Order
    {
        $item = $this->item();

        $this->get($this->qrUrl($this->table()))->assertOk();
        $this->assertGuest('customer');

        $before = (int) Order::max('id');
        $this->withSession(['cart' => $this->cart($item)])
            ->post('/customer/place-order', [
                'order_type'     => 'dine_in',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])->assertSessionHasNoErrors();

        $order = Order::where('id', '>', $before)->latest('id')->first();
        $this->assertNotNull($order, 'CONTROL: the guest order was not created');
        $this->assertNull($order->user_id);
        $this->assertSame('dine_in', $order->type);

        return $order;
    }

    private function complete(Order $order): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.orders.complete', $order->id))
            ->assertRedirect();

        $this->assertSame('completed', $order->fresh()->status);
    }

    private function registerBody(string $email): array
    {
        return [
            'name'                  => 'Blocked Sign Up',
            'email'                 => $email,
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'contact_number'        => '09171234567',
            'terms'                 => '1',
        ];
    }

    // ══════════ the four routes, GET and POST ══════════

    public function test_an_ongoing_order_closes_login_and_register_on_the_server(): void
    {
        $order = $this->seatedGuestWithOrder();
        $user = $this->customer();
        $email = 'ongoing-new-' . Str::lower(Str::random(10)) . '@example.test';

        $this->get(route('customer.login'))
            ->assertRedirect()
            ->assertSessionHas(DineInJoin::NOTICE_KEY, self::MESSAGE);

        $this->get(route('customer.register'))
            ->assertRedirect()
            ->assertSessionHas(DineInJoin::NOTICE_KEY, self::MESSAGE);

        $this->post(route('customer.login.post'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect()
            ->assertSessionHas(DineInJoin::NOTICE_KEY, self::MESSAGE);
        $this->assertGuest('customer');

        $this->post(route('customer.register.post'), $this->registerBody($email))
            ->assertRedirect()
            ->assertSessionHas(DineInJoin::NOTICE_KEY, self::MESSAGE);
        $this->assertNull(User::where('email', $email)->first(), 'a blocked register created an account');

        foreach (['signup', 'login'] as $mode) {
            $this->get(route('customer.dinein-join', ['mode' => $mode]))
                ->assertRedirect()
                ->assertSessionHas(DineInJoin::NOTICE_KEY, self::MESSAGE);
        }
        $this->assertNull(session(DineInJoin::SESSION_ACTIVE), 'a blocked join wrote its intent');

        // The guest is still the owner of the order they placed.
        $this->get(route('customer.orders'))->assertOk()->assertSee($order->order_number);
    }

    public function test_the_message_is_exactly_the_one_the_owner_specified(): void
    {
        $this->assertSame(self::MESSAGE, DineInJoin::MSG_ONGOING_ORDER);
    }

    public function test_the_guard_goes_back_where_the_guest_came_from_but_never_to_a_sign_in_page(): void
    {
        $this->seatedGuestWithOrder();

        // From a page that carries the prompt: back to it.
        $this->from(route('customer.more'))->get(route('customer.login'))
            ->assertRedirect(route('customer.more'));

        // From the sign-in pages themselves (a stale tab): the Orders page,
        // not a redirect loop.
        $this->from(route('customer.login'))->post(route('customer.login.post'), ['email' => 'x@example.test', 'password' => 'x'])
            ->assertRedirect(route('customer.orders'));
        $this->from(route('customer.register'))->post(route('customer.register.post'), $this->registerBody('loop@example.test'))
            ->assertRedirect(route('customer.orders'));

        // Typed straight into the address bar, nothing to go back to: Orders.
        $this->flushHeaders();
        $this->withSession(['_previous' => []])->get(route('customer.register'))
            ->assertRedirect(route('customer.orders'));
    }

    public function test_blocked_attempts_do_not_spend_the_login_budget(): void
    {
        $order = $this->seatedGuestWithOrder();
        $user = $this->customer();

        for ($i = 0; $i < 15; $i++) {
            $this->post(route('customer.login.post'), ['email' => $user->email, 'password' => self::PASSWORD])
                ->assertRedirect()
                ->assertSessionHas(DineInJoin::NOTICE_KEY);
        }

        // Finished, the very same request is a normal login, not a 429.
        $this->complete($order);
        $this->post(route('customer.login.post'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user, 'customer');
    }

    // ══════════ the Orders card ══════════

    public function test_the_card_is_hidden_while_the_order_is_ongoing_and_back_after_completion(): void
    {
        $this->chooseBranchReward(1, $this->item(), 7, 30);
        $order = $this->seatedGuestWithOrder();

        $this->get(route('customer.orders'))->assertOk()
            ->assertDontSee('data-dinein-join-card', false)
            ->assertDontSee('Want a stamp for this order?');

        foreach (['preparing', 'serving'] as $status) {
            \DB::table('orders')->where('id', $order->id)->update(['status' => $status]);
            $this->get(route('customer.orders'))->assertOk()->assertDontSee('data-dinein-join-card', false);
        }

        $this->complete($order);

        $this->get(route('customer.orders'))->assertOk()
            ->assertSee('data-dinein-join-card', false)
            ->assertSee('Want a stamp for this order?')
            ->assertSee(route('customer.dinein-join', ['mode' => 'signup']), false)
            ->assertSee(route('customer.dinein-join', ['mode' => 'login']), false);

        // And the links are open again: the join pins the completed order.
        $this->get(route('customer.dinein-join', ['mode' => 'login']))->assertRedirect(route('customer.login'));
        $this->assertSame($order->id, session(DineInJoin::SESSION_ORDER));
        $this->get(route('customer.login'))->assertOk();
        $this->get(route('customer.register'))->assertOk();
    }

    public function test_a_second_ongoing_order_keeps_it_closed_until_both_are_finished(): void
    {
        $first = $this->seatedGuestWithOrder();

        $item = $this->item();
        $before = (int) Order::max('id');
        $this->withSession(['cart' => $this->cart($item)])
            ->post('/customer/place-order', [
                'order_type'     => 'dine_in',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])->assertSessionHasNoErrors();
        $second = Order::where('id', '>', $before)->latest('id')->first();
        $this->assertNotNull($second, 'CONTROL: the second round was not placed');

        $this->complete($first);
        $this->get(route('customer.login'))->assertRedirect()->assertSessionHas(DineInJoin::NOTICE_KEY);

        $this->complete($second);
        $this->get(route('customer.login'))->assertOk();
    }

    public function test_the_orders_page_poller_keeps_or_fetches_the_card_when_the_last_order_finishes(): void
    {
        // The poller repaints #statusTab when the last in-flight order ends; that
        // used to wipe the card (found in a real browser). A guest's page marks
        // the area so the card can be fetched from the server when it was hidden
        // while the order cooked; a signed-in customer's page has no such mark.
        $this->seatedGuestWithOrder();

        $html = $this->get(route('customer.orders'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<div id="statusTab"[^>]* data-dinein-guest/', $html);
        $this->assertStringContainsString("statusTab.querySelector('section.dinein-join')", $html);
        $this->assertStringContainsString('function restoreJoinCard(statusTab)', $html);

        $this->flushSession();
        $html = $this->actingAs($this->customer(), 'customer')->get(route('customer.orders'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<div id="statusTab"[^>]* data-dinein-guest/', $html);
    }

    // ══════════ cancelled / rejected ══════════

    public function test_a_cancelled_order_never_offers_a_claim_and_login_is_open_again(): void
    {
        $this->chooseBranchReward(1, $this->item(), 7, 30);
        $order = $this->seatedGuestWithOrder();
        $user = $this->customer();

        $this->get(route('customer.login'))->assertRedirect()->assertSessionHas(DineInJoin::NOTICE_KEY);

        // The guest cancels it through the real route (pending orders only).
        $this->post(route('customer.orders.cancel', $order->id))->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);

        $this->get(route('customer.orders'))->assertOk()->assertDontSee('data-dinein-join-card', false);

        $this->get(route('customer.login'))->assertOk();
        $this->get(route('customer.register'))->assertOk();

        $this->post(route('customer.login.post'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect()
            ->assertSessionMissing(DineInJoin::NOTICE_KEY);
        $this->assertAuthenticatedAs($user, 'customer');
        $this->assertNull($order->fresh()->user_id, 'a cancelled order was attached');
    }

    public function test_a_rejected_gcash_order_is_not_ongoing(): void
    {
        $order = $this->seatedGuestWithOrder();

        // A rejected GCash payment is status cancelled + payment_status rejected.
        \DB::table('orders')->where('id', $order->id)->update(['status' => 'cancelled', 'payment_status' => 'rejected', 'completed_at' => now()]);
        $this->get(route('customer.login'))->assertOk();

        // And a literal 'rejected' status, should one ever be written, is finished too.
        \DB::table('orders')->where('id', $order->id)->update(['payment_status' => 'pending']);
        $this->assertContains('rejected', DineInJoin::FINISHED_STATUSES);
    }

    // ══════════ the buttons and the prompt ══════════

    public function test_the_buttons_open_the_prompt_instead_of_navigating_while_ongoing(): void
    {
        $order = $this->seatedGuestWithOrder();

        $more = $this->get(route('customer.more'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<a href="' . preg_quote(route('customer.login'), '#') . '" data-ongoing-order-block#', $more);
        $this->assertMatchesRegularExpression('#<a href="' . preg_quote(route('customer.register'), '#') . '" data-ongoing-order-block#', $more);
        $this->assertStringContainsString('id="ongoingOrderPrompt"', $more);
        $this->assertStringContainsString(self::MESSAGE, $more);
        $this->assertStringContainsString('data-ongoing-order-ok', $more);

        // Every other page that carries a Log in / Create account entry.
        foreach (['customer.cart', 'customer.game', 'customer.menu', 'customer.orders'] as $name) {
            $html = $this->get(route($name))->assertOk()->getContent();
            $this->assertStringContainsString('id="ongoingOrderPrompt"', $html, $name . ' has no prompt');
        }

        $this->complete($order);

        $more = $this->get(route('customer.more'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ongoing-order-block', $more, 'the links must be plain links again');
        $this->assertStringNotContainsString('id="ongoingOrderPrompt"', $more);
    }

    public function test_the_prompt_opens_on_arrival_when_the_server_sent_the_guest_back(): void
    {
        $this->seatedGuestWithOrder();

        $html = $this->from(route('customer.more'))->followingRedirects()->get(route('customer.login'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-open-on-load="1"', $html);
        $this->assertStringContainsString(self::MESSAGE, $html);
    }

    public function test_the_rewards_popup_buttons_carry_the_block_while_ongoing(): void
    {
        $this->chooseBranchReward(1, $this->item(), 5, 14);
        $table = $this->table();

        // No order yet: plain links.
        $html = $this->get($this->qrUrl($table))->assertOk()->getContent();
        $this->assertStringContainsString('data-dinein-join-popup', $html);
        $this->assertStringNotContainsString('data-ongoing-order-block', $html);

        $item = $this->item();
        $this->withSession(['cart' => $this->cart($item)])
            ->post('/customer/place-order', [
                'order_type'     => 'dine_in',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])->assertSessionHasNoErrors();

        $html = $this->get(route('customer.menu'))->assertOk()->getContent();
        $this->assertSame(2, preg_match_all('/<a [^>]*data-ongoing-order-block/', $html), 'both pop-up buttons must open the prompt');
        $this->assertStringContainsString('data-dinr-signup', $html);
    }

    // ══════════ who is never affected ══════════

    public function test_a_signed_in_customer_is_never_blocked(): void
    {
        $user = $this->customer();
        $item = $this->item();

        $this->actingAs($user, 'customer');
        $this->get($this->qrUrl($this->table()))->assertOk();

        $before = (int) Order::max('id');
        $this->withSession(['cart' => $this->cart($item)])
            ->post('/customer/place-order', [
                'order_type'     => 'dine_in',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])->assertSessionHasNoErrors();
        $order = Order::where('id', '>', $before)->latest('id')->first();
        $this->assertSame($user->id, $order->user_id);
        $this->assertContains($order->status, DineInJoin::OPEN_STATUSES, 'CONTROL: the account order is in progress');

        $this->assertFalse(DineInJoin::blocksGuestAuth());
        $html = $this->get(route('customer.more'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ongoing-order-block', $html);
        $this->assertStringNotContainsString('id="ongoingOrderPrompt"', $html);
    }

    public function test_pick_up_is_never_blocked(): void
    {
        $item = $this->item();

        $before = (int) Order::max('id');
        $this->withSession(['cart' => $this->cart($item), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])->assertSessionDoesntHaveErrors();
        $order = Order::where('id', '>', $before)->latest('id')->first();
        $this->assertNotNull($order, 'CONTROL: the pick-up order was not placed');
        $this->assertSame('pick_up', $order->type);
        $this->assertContains($order->status, DineInJoin::OPEN_STATUSES);

        $this->assertFalse(DineInJoin::blocksGuestAuth());
        $this->get(route('customer.login'))->assertOk();
        $this->get(route('customer.register'))->assertOk();

        $user = $this->customer();
        $this->post(route('customer.login.post'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect()
            ->assertSessionMissing(DineInJoin::NOTICE_KEY);
        $this->assertAuthenticatedAs($user, 'customer');

        $this->flushSession();
        $html = $this->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get(route('customer.more'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ongoing-order-block', $html);
    }

    public function test_someone_elses_order_does_not_block_this_guest(): void
    {
        $other = $this->seatedGuestWithOrder();
        $this->assertNotNull($other);

        // A different browser at a different table, with no order of its own.
        $this->flushSession();
        $this->get($this->qrUrl($this->table()))->assertOk();

        $this->assertFalse(DineInJoin::blocksGuestAuth(), 'another guest\'s order must not block this one');
        $this->get(route('customer.login'))->assertOk();
    }
}

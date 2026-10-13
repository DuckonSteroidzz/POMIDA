<?php

namespace Tests\Feature;

use App\Mail\VerifyEmailMail;
use App\Models\Branch;
use App\Models\Category;
use App\Models\FreeDessertReward;
use App\Models\Inventory;
use App\Models\LoyaltyAward;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Notification;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\Setting;
use App\Models\User;
use App\Services\StreakCalendar;
use App\Services\TableEntry;
use App\Support\DineInJoin;
use App\Support\GuestOrders;
use Carbon\CarbonImmutable;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ConfiguresStreakReward;
use Tests\TestCase;

/**
 * Dine-In guest joins the loyalty program right after ordering (Oct 2026).
 * See App\Support\DineInJoin. There is no "Remember me" for anyone (retired
 * Sept 2026, see App\Support\SingleSession); returning customers log in.
 *
 * Everything goes through the real doors: the table QR landing, the join
 * link, register, the signed confirmation link, login and staff Complete.
 * Two tests hand-build a session on purpose, because the thing under test
 * is that a forged or stale session still cannot claim the wrong order.
 */
class DineInSignupClaimTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresStreakReward;

    // 12:00 Manila on 15 Oct 2026, the same anchor the streak tests use.
    private const NOW_UTC = '2026-10-15 04:00:00';

    private const PASSWORD = 'Peachy!2026';

    private string $cookieName;

    /** device name => that device's current session id (multi-device tests only) */
    private array $jar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());

        $this->travelTo(CarbonImmutable::parse(self::NOW_UTC, 'UTC'));

        config([
            'app.shop_timezone'                => 'Asia/Manila',
            'loyalty.order_bonus_points'       => 3,
            'loyalty.streak_milestones'        => [3 => 10, 7 => 0],
            'loyalty.free_dessert_visit_days'  => 7,
            'loyalty.free_dessert_counts_from' => '2026-09-01',
            'loyalty.free_dessert_valid_days'  => 30,
        ]);

        $this->clearStreakRewardSettings();
        DB::table('settings')->where('key', Setting::STREAK_CALENDAR_ENABLED)->delete();

        Cache::flush();
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
        $this->cookieName = (string) config('session.cookie');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ══════════ fixtures ══════════

    /** A second branch, so "the right branch" means something. */
    private function otherBranch(): Branch
    {
        return Branch::where('id', '!=', 1)->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function customer(array $attrs = []): User
    {
        return User::create(array_merge([
            'name'              => 'Join Claim Customer',
            'email'             => 'dinein-join-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => self::PASSWORD,
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ], $attrs))->fresh();
    }

    private function admin(): User
    {
        return User::create([
            'name'              => 'Join Claim Admin',
            'email'             => 'dinein-join-admin-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => self::PASSWORD,
            'role'              => 'admin',
            'branch_id'         => null,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function item(int $branchId, string $name): MenuItem
    {
        $inv = Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => 'DJC Ingredient ' . uniqid(),
            'item_code'       => 'DJC-' . strtoupper(substr(uniqid(), -8)) . random_int(10, 99),
            'quantity'        => 50,
            'unit'            => 'pc',
            'low_stock_alert' => 0,
            'unit_cost'       => 5,
            'is_active'       => true,
        ]);

        $item = MenuItem::create([
            'category_id'   => Category::value('id'),
            'branch_id'     => $branchId,
            'name'          => $name . ' ' . uniqid(),
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

    private function table(int $branchId): RestaurantTable
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
        return '/customer/menu?branch_id=' . $table->branch_id
            . '&table=' . $table->table_number . '&k=' . $table->code;
    }

    /** Submit a Dine-In order in the current (seated) session. */
    private function placeDineIn(MenuItem $item): array
    {
        $before = (int) Order::max('id');

        $response = $this->withSession(['cart' => $this->cart($item)])
            ->post('/customer/place-order', [
                'order_type'     => 'dine_in',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ]);

        return [$response, Order::where('id', '>', $before)->latest('id')->first()];
    }

    /** Staff press Complete, as a separate signed-in admin. */
    private function complete(Order $order): void
    {
        // Keep the customer's guard state out of the admin request and back.
        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.orders.complete', $order->id))
            ->assertRedirect();

        $this->assertSame('completed', $order->fresh()->status);
    }

    private function login(User $user, array $extra = []): TestResponse
    {
        return $this->post(route('customer.login.post'), array_merge([
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ], $extra));
    }

    /** Guest at $table with one open Dine-In order. Returns [order, item]. */
    private function seatedGuestWithOrder(RestaurantTable $table): array
    {
        $item = $this->item($table->branch_id, 'DJC Order Item');

        $this->get($this->qrUrl($table))->assertOk();
        $this->assertGuest('customer');

        [$response, $order] = $this->placeDineIn($item);
        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order, 'the guest Dine-In order was not created');
        $this->assertNull($order->user_id);

        return [$order, $item];
    }

    // ══════════ multi-device harness (copied from SingleSessionPerAccountTest) ══════════

    private function on(string $device, callable $request, array $extraCookies = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $id = $this->jar[$device] ??= Str::random(40);

        $store = $this->app['session']->driver();
        $store->flush();
        $store->setId($id);

        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->flushHeaders();
        $this->withCredentials();
        $this->withCookie($this->cookieName, $id);

        foreach ($extraCookies as $name => $value) {
            $this->withCookie($name, $value);
        }

        $response = $request();

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $this->cookieName && (string) $cookie->getValue() !== '') {
                $this->jar[$device] = CookieValuePrefix::remove(decrypt((string) $cookie->getValue(), false));
            }
        }

        return $response;
    }

    /** The remember cookie a response set, decrypted as the browser would send it back. */
    private function rememberCookieFrom(TestResponse $response, string $guard = 'customer'): ?array
    {
        $name = Auth::guard($guard)->getRecallerName();

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name && (string) $cookie->getValue() !== '' && $cookie->getExpiresTime() > time()) {
                return [$name => CookieValuePrefix::remove(decrypt((string) $cookie->getValue(), false))];
            }
        }

        return null;
    }

    // ══════════ 1. guest orders, signs up, staff completes: stamp + reward at the right branch ══════════

    public function test_guest_signs_up_from_the_card_then_the_completed_order_stamps_and_earns_the_reward_at_its_branch(): void
    {
        Mail::fake();

        $branch = $this->otherBranch();
        $reward = $this->item($branch->id, 'DJC Reward Cake');
        $this->chooseBranchReward($branch->id, $reward, 1, 14);

        [$order] = $this->seatedGuestWithOrder($this->table($branch->id));

        // The card is on the order screen, with Sign up and Log in.
        $orders = $this->get(route('customer.orders'))->assertOk();
        $orders->assertSee('data-dinein-join-card', false);
        $orders->assertSee('Want a stamp for this order?');
        $orders->assertSee('Sign up free and confirm your email today to get a stamp for this order.');
        $orders->assertSee(route('customer.dinein-join', ['mode' => 'signup']), false);
        $orders->assertSee(route('customer.dinein-join', ['mode' => 'login']), false);

        // Sign up from the card.
        $this->get(route('customer.dinein-join', ['mode' => 'signup']))->assertRedirect(route('customer.register'));
        $this->assertSame($order->id, session(DineInJoin::SESSION_ORDER), 'the join did not pin this session\'s order');
        $this->get(route('customer.register'))->assertOk()->assertSee('data-dinein-join-note', false);

        $email = 'dinein-join-new-' . Str::lower(Str::random(10)) . '@example.test';

        $this->post(route('customer.register.post'), [
            'name'                  => 'New Dine-In Member',
            'email'                 => $email,
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'contact_number'        => '09171234567',
            'terms'                 => '1',
        ])->assertRedirect(route('customer.email-verification.pending'));

        $user = User::where('email', $email)->firstOrFail();
        $this->assertGuest('customer');
        $this->assertNull($order->fresh()->user_id, 'sign-up alone must not attach the order (nobody is logged in yet)');

        // The real emailed link, in this same browser.
        $url = null;
        Mail::assertSent(VerifyEmailMail::class, function (VerifyEmailMail $mail) use ($user, &$url) {
            $url = $mail->verificationUrl;

            return $mail->user->is($user);
        });
        $this->assertStringContainsString('flow=dinein', $url);

        $this->get($url)->assertRedirect(route('customer.login'));

        // The Dine-In login: the stamp note shown, no Remember me.
        $page = $this->get(route('customer.login'))->assertOk();
        $page->assertDontSee('name="remember"', false);
        $page->assertSee('Log in and your order will count toward your stamp card.');

        $this->login($user)
            ->assertRedirect(route('customer.orders'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, DineInJoin::MSG_CLAIMED));

        $this->assertAuthenticatedAs($user, 'customer');
        $this->assertSame($user->id, $order->fresh()->user_id, 'the order was not attached at login');

        // Same Dine-In session: same table, same branch.
        $this->assertSame('dine_in', session('order_type'));
        $this->assertSame($order->table_number, session('table_number'));
        $this->assertSame($branch->id, (int) session('branch_id'));
        $this->assertNull(session(DineInJoin::SESSION_ACTIVE), 'the intent must be spent by the login');

        // The order shows on the account's order screen, with no card now.
        $this->get(route('customer.orders'))->assertOk()
            ->assertSee($order->order_number)
            ->assertDontSee('data-dinein-join-card', false);

        $this->complete($order);

        $days = StreakCalendar::completedDays($user, $branch->id);
        $this->assertArrayHasKey('2026-10-15', $days, 'the stamp did not count at the order\'s branch');
        $this->assertArrayNotHasKey('2026-10-15', StreakCalendar::completedDays($user, 1), 'the stamp landed on the wrong branch');

        $rewards = FreeDessertReward::where('user_id', $user->id)->get();
        $this->assertCount(1, $rewards, 'the reward was not earned');
        $this->assertSame($branch->id, (int) $rewards->first()->branch_id, 'the reward was issued by the wrong branch');

        $this->assertTrue(
            LoyaltyAward::where('user_id', $user->id)->where('type', LoyaltyAward::TYPE_ORDER_BONUS)->where('award_key', (string) $order->id)->exists(),
            'the order bonus was not paid for the claimed order'
        );
    }

    // ══════════ 2. guest who does not sign up: no stamp, no error ══════════

    public function test_guest_who_does_not_sign_up_orders_cleanly_and_earns_nothing_and_spin_prompt_still_shows(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 1, 14);
        Setting::set(Setting::GAME_ENABLED, '1');

        [$order] = $this->seatedGuestWithOrder($this->table(1));

        // The menu pop-up opened on the QR landing and reported it (once per
        // table session): not again on the next page load.
        $this->postJson(route('customer.dinein-popup-seen'))->assertOk()->assertJson(['recorded' => true]);
        $this->get(route('customer.menu'))->assertOk()->assertDontSee('data-dinein-join-popup', false);

        // The card AND the Spin & Win prompt, both on the same page load.
        $html = $this->get(route('customer.orders'))->assertOk()->getContent();
        $this->assertStringContainsString('data-dinein-join-card', $html);
        $this->assertStringContainsString('id="spinPromptModal"', $html, 'the card must not replace the Spin prompt');

        $rewards = FreeDessertReward::count();
        $awards = LoyaltyAward::count();

        $this->complete($order);

        $this->assertNull($order->fresh()->user_id);
        $this->assertSame($rewards, FreeDessertReward::count());
        $this->assertSame($awards, LoyaltyAward::count());

        // After completion there is no open order, so no card.
        $this->get(route('customer.orders'))->assertOk()->assertDontSee('data-dinein-join-card', false);
    }

    // ══════════ 3. claims that must fail ══════════

    public function test_an_order_id_in_the_url_or_form_is_ignored_only_this_sessions_order_is_claimed(): void
    {
        $user = $this->customer();
        $table = $this->table(1);

        [$mine] = $this->seatedGuestWithOrder($table);

        // Another guest's open Dine-In order: ownerless, not in this session.
        $theirs = $mine->replicate(['spin_prompted_at']);
        $theirs->order_number = 'ORD-DJC-' . Str::upper(Str::random(8));
        $theirs->save();
        $this->assertFalse(GuestOrders::owns($theirs->id));

        $this->get(route('customer.dinein-join', ['mode' => 'login']) . '?order_id=' . $theirs->id . '&order=' . $theirs->id)
            ->assertRedirect(route('customer.login'));
        $this->assertSame($mine->id, session(DineInJoin::SESSION_ORDER));

        $this->login($user, ['order_id' => $theirs->id, 'order' => $theirs->id])
            ->assertRedirect(route('customer.orders'));

        $this->assertNull($theirs->fresh()->user_id, 'another guest\'s order was claimed from a request parameter');
        $this->assertSame($user->id, $mine->fresh()->user_id);
    }

    public function test_a_stale_or_forged_session_cannot_reassign_an_order_that_already_belongs_to_an_account(): void
    {
        $owner = $this->customer(['name' => 'Real Owner']);
        $intruder = $this->customer(['name' => 'Intruder']);
        $table = $this->table(1);

        [$order] = $this->seatedGuestWithOrder($table);
        $order->forceFill(['user_id' => $owner->id])->save();

        // The session claims to own it and has the intent pinned at it.
        $this->withSession([
            GuestOrders::KEY            => [$order->id],
            DineInJoin::SESSION_ACTIVE  => true,
            DineInJoin::SESSION_ORDER   => $order->id,
        ]);

        $this->login($intruder)->assertRedirect(route('customer.orders'));

        $this->assertSame($owner->id, $order->fresh()->user_id, 'an owned order was re-assigned');
    }

    public function test_one_claim_per_order_a_second_account_in_the_same_browser_cannot_take_it(): void
    {
        $first = $this->customer(['name' => 'First']);
        $second = $this->customer(['name' => 'Second']);

        [$order] = $this->seatedGuestWithOrder($this->table(1));

        $this->get(route('customer.dinein-join', ['mode' => 'login']));
        $this->login($first)->assertRedirect(route('customer.orders'));
        $this->assertSame($first->id, $order->fresh()->user_id);

        // Same browser, the first account signs out without ending the visit,
        // and the second tries the flow with the same order pinned.
        Auth::guard('customer')->logoutCurrentDevice();
        $this->withSession([
            GuestOrders::KEY           => [$order->id],
            DineInJoin::SESSION_ACTIVE => true,
            DineInJoin::SESSION_ORDER  => $order->id,
        ]);

        $this->login($second);

        $this->assertSame($first->id, $order->fresh()->user_id, 'a claimed order was claimed a second time');
    }

    /**
     * Shared phone: party A taps Log in on the card (their order is pinned)
     * and leaves while it is still cooking. Party B starts a new visit on the
     * same phone ("Continue as guest", which drops A's active orders from the
     * session) and logs in to B's own account. B must not get A's order.
     *
     * The new visit is at ANOTHER branch's table on purpose. At the same
     * branch the phone cannot switch tables while A's order is open
     * (TableChange's active_order rule); another branch's code is treated as
     * a fresh arrival, so this is the path that really reaches forget().
     */
    public function test_a_new_visit_on_the_same_phone_revokes_the_previous_partys_pinned_order(): void
    {
        $partyB = $this->customer(['name' => 'Party B']);

        [$orderA] = $this->seatedGuestWithOrder($this->table(1));
        $this->get(route('customer.dinein-join', ['mode' => 'login']))->assertRedirect(route('customer.login'));
        $this->assertSame($orderA->id, session(DineInJoin::SESSION_ORDER));

        $tableB = $this->table($this->otherBranch()->id);
        $this->post('/customer/dineinqr', ['table_code' => $tableB->code, 'next' => 'guest'])
            ->assertRedirect(route('customer.menu'));
        $this->assertSame($tableB->table_number, session('table_number'), 'CONTROL: the new visit did not start');
        $this->assertFalse(GuestOrders::owns($orderA->id), 'CONTROL: the new visit kept the old party\'s orders');

        $this->login($partyB);

        $this->assertNull($orderA->fresh()->user_id, 'the previous party\'s order was claimed by the next person on the phone');
    }

    public function test_a_plain_login_outside_the_flow_attaches_nothing(): void
    {
        $user = $this->customer();
        [$order] = $this->seatedGuestWithOrder($this->table(1));

        $this->login($user)->assertRedirect(route('customer.menu'));

        $this->assertNull($order->fresh()->user_id);
    }

    // ══════════ 4. claim after Complete ══════════

    /**
     * The usual new-customer path: Sign up while the order cooks, staff press
     * Complete, THEN the email is confirmed and the customer logs in. Same
     * shop day, so the order is attached and Complete's loyalty step runs
     * now: stamp, order bonus, reward and its pop-up, once each.
     */
    public function test_order_completed_earlier_the_same_shop_day_is_claimed_and_stamps_once(): void
    {
        Mail::fake();

        $branch = $this->otherBranch();
        $this->chooseBranchReward($branch->id, $this->item($branch->id, 'DJC Reward Cake'), 1, 14);

        [$order] = $this->seatedGuestWithOrder($this->table($branch->id));

        $this->get(route('customer.dinein-join', ['mode' => 'signup']))->assertRedirect(route('customer.register'));

        $email = 'dinein-join-late-' . Str::lower(Str::random(10)) . '@example.test';
        $this->post(route('customer.register.post'), [
            'name'                  => 'Late Dine-In Member',
            'email'                 => $email,
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'contact_number'        => '09171234567',
            'terms'                 => '1',
        ])->assertRedirect(route('customer.email-verification.pending'));
        $user = User::where('email', $email)->firstOrFail();

        // Staff finish the order before the customer has confirmed.
        $this->complete($order);
        $this->assertNull($order->fresh()->user_id);
        $this->assertSame(0, LoyaltyAward::where('user_id', $user->id)->count(), 'CONTROL: Complete paid a guest order');

        // Later the same day (inside the link's 60 minutes): confirm, then
        // log in on this phone.
        $this->travel(40)->minutes();

        $url = null;
        Mail::assertSent(VerifyEmailMail::class, function (VerifyEmailMail $mail) use ($user, &$url) {
            $url = $mail->verificationUrl;

            return $mail->user->is($user);
        });
        $this->get($url)->assertRedirect(route('customer.login'));

        $this->login($user)
            ->assertRedirect(route('customer.orders'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, DineInJoin::MSG_CLAIMED_COMPLETED));

        $this->assertSame($user->id, $order->fresh()->user_id, 'the same-day completed order was not attached');

        $this->assertArrayHasKey('2026-10-15', StreakCalendar::completedDays($user, $branch->id), 'no stamp for the late claim');
        $this->assertSame(1, LoyaltyAward::where('user_id', $user->id)->where('type', LoyaltyAward::TYPE_ORDER_BONUS)
            ->where('award_key', (string) $order->id)->count(), 'the order bonus was not paid exactly once');

        $rewards = FreeDessertReward::where('user_id', $user->id)->get();
        $this->assertCount(1, $rewards, 'the reward was not earned by the late claim');
        $this->assertSame($branch->id, (int) $rewards->first()->branch_id);

        // The reward pop-up is waiting on the order screen.
        $this->get(route('customer.orders'))->assertOk()
            ->assertSee('id="freeDessertPrompt"', false)
            ->assertSee('data-pending="1"', false);
    }

    /** The join button tapped only after Complete, the same day, still pins that order. */
    public function test_the_join_tapped_after_complete_the_same_day_still_claims_the_order(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 1, 14);

        $user = $this->customer();
        [$order] = $this->seatedGuestWithOrder($this->table(1));

        $this->complete($order);

        // The order screen has no open order, so the menu pop-up is the way in.
        $this->get(route('customer.dinein-join', ['mode' => 'login']))->assertRedirect(route('customer.login'));
        $this->assertSame($order->id, session(DineInJoin::SESSION_ORDER));

        $this->login($user)->assertRedirect(route('customer.orders'));

        $this->assertSame($user->id, $order->fresh()->user_id);
        $this->assertArrayHasKey('2026-10-15', StreakCalendar::completedDays($user, 1));
        $this->assertSame(1, FreeDessertReward::where('user_id', $user->id)->count());
    }

    public function test_claiming_a_completed_order_twice_credits_it_once(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 1, 14);

        $user = $this->customer();
        [$order] = $this->seatedGuestWithOrder($this->table(1));

        $this->get(route('customer.dinein-join', ['mode' => 'login']));
        $this->complete($order);
        $this->login($user)->assertSessionHas('success', fn ($m) => str_contains($m, DineInJoin::MSG_CLAIMED_COMPLETED));
        $this->assertSame($user->id, $order->fresh()->user_id);

        $awards = LoyaltyAward::where('user_id', $user->id)->count();
        $bonus = (int) $user->fresh()->bonus_points;
        $this->assertGreaterThan(0, $awards, 'CONTROL: the first claim paid nothing');

        // Same browser, same account, the same order pinned again.
        Auth::guard('customer')->logoutCurrentDevice();
        $this->withSession([
            GuestOrders::KEY           => [$order->id],
            DineInJoin::SESSION_ACTIVE => true,
            DineInJoin::SESSION_ORDER  => $order->id,
        ]);
        $this->login($user)->assertSessionHas('success', fn ($m) => ! str_contains($m, DineInJoin::MSG_CLAIMED_COMPLETED));

        // And the loyalty step itself run again (the race with Complete).
        $this->assertSame([], \App\Services\Loyalty::orderCompleted($order->fresh()));

        $this->assertSame($awards, LoyaltyAward::where('user_id', $user->id)->count(), 'a second claim credited the order again');
        $this->assertSame($bonus, (int) $user->fresh()->bonus_points);
        $this->assertSame(1, FreeDessertReward::where('user_id', $user->id)->count());
        $this->assertSame(1, LoyaltyAward::where('user_id', $user->id)->where('type', LoyaltyAward::TYPE_ORDER_BONUS)->count());
    }

    /**
     * Cancelled orders are never attached. A rejected GCash payment is the
     * same thing in the data: payment_status 'rejected' AND status 'cancelled'
     * (AdminController::rejectGcashPayment()).
     */
    public static function cancelledShapes(): array
    {
        return [
            'cancelled'       => [['status' => 'cancelled']],
            'gcash rejected'  => [['status' => 'cancelled', 'payment_status' => 'rejected']],
        ];
    }

    /** @dataProvider cancelledShapes */
    public function test_a_cancelled_or_rejected_order_is_never_claimed(array $shape): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 1, 14);

        $user = $this->customer();
        [$order] = $this->seatedGuestWithOrder($this->table(1));

        $this->get(route('customer.dinein-join', ['mode' => 'login']));
        $this->assertSame($order->id, session(DineInJoin::SESSION_ORDER), 'CONTROL: the open order was not pinned');

        DB::table('orders')->where('id', $order->id)->update($shape + ['completed_at' => now()]);

        $this->login($user)
            ->assertRedirect(route('customer.orders'))
            ->assertSessionHas('success', fn ($m) => ! str_contains($m, 'stamp'));

        $this->assertNull($order->fresh()->user_id, 'a cancelled order was attached');
        $this->assertSame(0, LoyaltyAward::where('user_id', $user->id)->count());
        $this->assertSame(0, FreeDessertReward::where('user_id', $user->id)->count());
    }

    /**
     * Completed before Manila midnight, logged in after it: an earlier shop
     * day, so no retroactive stamp. The customer is told, and the next order
     * counts normally.
     */
    public function test_order_completed_on_an_earlier_shop_day_gives_no_retroactive_stamp_but_later_orders_count(): void
    {
        // 23:55 Manila on 15 Oct.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 15:55:00', 'UTC'));

        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 1, 14);

        $user = $this->customer();
        $table = $this->table(1);

        [$order, $item] = $this->seatedGuestWithOrder($table);

        $this->get(route('customer.dinein-join', ['mode' => 'login']))->assertRedirect(route('customer.login'));

        // Staff finish the order while the guest is still logging in.
        $this->complete($order);

        // 00:05 Manila on 16 Oct: a new shop day.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 16:05:00', 'UTC'));

        $this->login($user)
            ->assertRedirect(route('customer.orders'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, DineInJoin::MSG_TOO_LATE));

        $this->assertNull($order->fresh()->user_id, 'a completed order was attached');
        $this->assertSame([], StreakCalendar::completedDays($user, 1), 'a retroactive stamp was given');
        $this->assertSame(0, FreeDessertReward::where('user_id', $user->id)->count());
        $this->assertSame(0, LoyaltyAward::where('user_id', $user->id)->count());

        // The table was released by the completion; a re-scan seats the
        // signed-in customer again, and the next order counts normally.
        $this->get($this->qrUrl($table))->assertOk();
        $this->assertAuthenticatedAs($user, 'customer');

        [$response, $next] = $this->placeDineIn($item);
        $response->assertSessionHasNoErrors();
        $this->assertSame($user->id, $next->user_id);

        $this->complete($next);

        $this->assertSame(['2026-10-16'], array_keys(StreakCalendar::completedDays($user, 1)));
        $this->assertSame(1, FreeDessertReward::where('user_id', $user->id)->count());
    }

    // ══════════ 5. already logged in when scanning: no prompts, order links ══════════

    public function test_logged_in_customer_scanning_the_qr_sees_no_prompts_and_the_order_links_to_the_account(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 1, 14);

        $user = $this->customer();
        $table = $this->table(1);
        $item = $this->item(1, 'DJC Order Item');

        $this->actingAs($user, 'customer');

        $this->get($this->qrUrl($table))->assertOk()->assertDontSee('data-dinein-join-popup', false);
        $this->get(route('customer.menu'))->assertOk()->assertDontSee('data-dinein-join-popup', false);

        [$response, $order] = $this->placeDineIn($item);
        $response->assertSessionHasNoErrors();
        $this->assertSame($user->id, $order->user_id);

        $this->get(route('customer.orders'))->assertOk()
            ->assertSee($order->order_number)
            ->assertDontSee('data-dinein-join-card', false);

        // The join link has nothing to do for an account.
        $this->get(route('customer.dinein-join', ['mode' => 'signup']))->assertRedirect(route('customer.orders'));
        $this->assertNull(session(DineInJoin::SESSION_ACTIVE));
    }

    // ══════════ 6. no "Remember me", for anyone ══════════

    public function test_the_dine_in_login_offers_no_remember_box_and_ignores_a_posted_remember(): void
    {
        $user = $this->customer();

        $this->on('phone', fn () => $this->get($this->qrUrl($this->table(1))))->assertOk();
        $this->on('phone', fn () => $this->get(route('customer.dinein-join', ['mode' => 'login'])));

        $page = $this->on('phone', fn () => $this->get(route('customer.login')))->assertOk();
        $page->assertDontSee('name="remember"', false);
        $page->assertDontSee('Remember me');

        $login = $this->on('phone', fn () => $this->login($user, ['remember' => '1']));
        $login->assertRedirect();
        $this->assertTrue(Auth::guard('customer')->check(), 'CONTROL: the login failed');

        $this->assertNull($this->rememberCookieFrom($login), 'the Dine-In login set a remember cookie');
        $this->assertSame(0, $login->getCookie($this->cookieName, false)->getExpiresTime(),
            'the session cookie must end with the browser');
    }

    /**
     * Even a customer cookie built from the account's CURRENT login token
     * (the strongest shape a cookie could have) signs nobody in and is
     * deleted. After the browser is closed, the customer logs in again.
     */
    public function test_a_customer_remember_cookie_is_refused_even_with_the_current_login_token(): void
    {
        $user = $this->customer();

        $this->on('phone', fn () => $this->get($this->qrUrl($this->table(1))))->assertOk();
        $this->on('phone', fn () => $this->login($user))->assertRedirect();

        $token = (string) $user->fresh()->remember_token;
        $this->assertStringStartsWith('login.', $token, 'CONTROL: the login wrote no login token');

        $name = Auth::guard('customer')->getRecallerName();
        $cookie = [$name => $user->id . '|' . $token . '|' . $user->fresh()->getAuthPassword()];

        // The browser was closed: only the cookie is left.
        unset($this->jar['phone']);
        $response = $this->on('phone', fn () => $this->get(route('customer.menu')), $cookie);

        $this->assertFalse(Auth::guard('customer')->check(), 'a customer remember cookie signed the phone back in');
        $response->assertCookieExpired($name);
    }

    /**
     * The same for staff: a counter browser holding a cookie built from its
     * current login token is refused and the cookie deleted.
     */
    public function test_a_staff_remember_cookie_is_still_refused_even_with_a_login_token(): void
    {
        $staff = User::create([
            'name' => 'DJC Staff', 'email' => 'dinein-join-staff-' . Str::lower(Str::random(10)) . '@example.test',
            'password' => self::PASSWORD, 'role' => 'staff', 'branch_id' => 1, 'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->on('counter', fn () => $this->post('/admin/login', ['email' => $staff->email, 'password' => self::PASSWORD]))
            ->assertRedirect(route('admin.home'));

        $token = (string) $staff->fresh()->remember_token;
        $this->assertStringStartsWith('login.', $token, 'CONTROL: staff login wrote a login token');

        $name = Auth::guard('admin')->getRecallerName();
        $cookie = [$name => $staff->id . '|' . $token . '|' . $staff->fresh()->getAuthPassword()];

        unset($this->jar['counter']);
        $response = $this->on('counter', fn () => $this->get('/admin/home'), $cookie);

        $this->assertFalse(Auth::guard('admin')->check(), 'a staff remember cookie signed the counter back in');
        $response->assertCookieExpired($name);
    }

    // ══════════ 7. Pick-Up unchanged ══════════

    public function test_pick_up_login_offers_no_remember_box_and_ignores_a_posted_remember(): void
    {
        $user = $this->customer();

        $page = $this->get(route('customer.login', ['order_type' => 'pick_up']))->assertOk();
        $page->assertDontSee('name="remember"', false);
        $page->assertDontSee('Remember me');

        $this->login($user, ['remember' => '1'])
            ->assertRedirect(route('customer.menu'))
            ->assertCookieMissing(Auth::guard('customer')->getRecallerName());

        $this->assertSame('pick_up', session('order_type'));
    }

    public function test_the_join_link_does_nothing_outside_a_dine_in_table(): void
    {
        $this->get(route('customer.login', ['order_type' => 'pick_up']));

        $this->get(route('customer.dinein-join', ['mode' => 'login']))->assertRedirect(route('customer.menu'));
        $this->assertNull(session(DineInJoin::SESSION_ACTIVE));

        $this->get(route('customer.menu'))->assertOk()->assertDontSee('data-dinein-join-popup', false);
    }

    // ══════════ smaller pieces ══════════

    public function test_no_card_or_menu_line_where_the_branch_earns_no_stamps(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 1, 14);
        $this->setBranchSwitch(1, false);

        $this->seatedGuestWithOrder($this->table(1));

        $this->get(route('customer.menu'))->assertOk()->assertDontSee('data-dinein-join-popup', false);
        $this->get(route('customer.orders'))->assertOk()->assertDontSee('data-dinein-join-card', false);
    }

    public function test_the_orders_status_messages_move_with_the_claimed_order(): void
    {
        $user = $this->customer();
        [$order] = $this->seatedGuestWithOrder($this->table(1));

        $note = Notification::orderStatusChanged($order, 'preparing');
        $this->assertNotNull($note);
        $this->assertNull($note->user_id);

        $this->get(route('customer.dinein-join', ['mode' => 'login']));
        $this->login($user);

        $this->assertSame($user->id, $note->fresh()->user_id, 'the guest-era notification did not follow the order');
    }

    public function test_sign_up_never_creates_a_second_account_for_an_existing_email(): void
    {
        $existing = $this->customer();
        [$order] = $this->seatedGuestWithOrder($this->table(1));

        $this->get(route('customer.dinein-join', ['mode' => 'signup']));

        $this->post(route('customer.register.post'), [
            'name'                  => 'Duplicate',
            'email'                 => Str::upper($existing->email),
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'contact_number'        => '09171234567',
            'terms'                 => '1',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', [Str::lower($existing->email)])->count());
        $this->assertNull($order->fresh()->user_id);
    }

    /**
     * Staff press Complete at the same moment the guest's login claims the
     * order: the claim commits after completeOrder() has read the order but
     * before it takes the row lock. The order bonus must still be paid.
     */
    public function test_a_claim_landing_between_completes_read_and_lock_still_pays_the_bonus(): void
    {
        $user = $this->customer();
        [$order] = $this->seatedGuestWithOrder($this->table(1));

        $admin = $this->admin();
        $fired = false;

        DB::listen(function ($query) use (&$fired, $order, $user) {
            // completeOrder()'s first, unlocked read (AdminOrderAccess::resolveInScope).
            $sql = strtolower($query->sql);
            if ($fired || ! str_starts_with($sql, 'select * from `orders` where `orders`.`id` = ?')
                || str_contains($sql, 'for update')) {
                return;
            }

            $fired = true;
            DB::table('orders')->where('id', $order->id)->update(['user_id' => $user->id]);
        });

        $this->actingAs($admin, 'admin')->put(route('admin.orders.complete', $order->id))->assertRedirect();

        $this->assertTrue($fired, 'CONTROL: the simulated claim never ran');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame($user->id, $order->fresh()->user_id);
        $this->assertTrue(
            LoyaltyAward::where('user_id', $user->id)->where('type', LoyaltyAward::TYPE_ORDER_BONUS)->where('award_key', (string) $order->id)->exists(),
            'the order bonus was skipped because completion used the pre-lock owner'
        );
    }

    // ══════════ Dine-In menu rewards pop-up (replaces the small menu line) ══════════

    public function test_a_dine_in_guest_gets_the_rewards_popup_once_per_table_session(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 5, 14);
        $table = $this->table(1);

        $first = $this->get($this->qrUrl($table))->assertOk();
        $first->assertSee('data-dinein-join-popup', false);
        $first->assertSee('role="dialog"', false);
        $first->assertSee('aria-modal="true"', false);
        $first->assertSee('A little something for you');
        $first->assertSee('Sweet visits.');
        $first->assertSee('Even sweeter rewards.');
        $first->assertSee('Your favourite treats, with a little extra.');
        $first->assertSee('Join Peachy to collect stamps and earn rewards.');
        $first->assertSee('Sign up for free');
        $first->assertSee('Already part of the family?');
        $first->assertSee('A little thank-you for every visit');
        $first->assertDontSee('Sign up free to collect stamps and earn rewards.');
        $first->assertDontSee('data-dinein-join-line', false);
        // Nothing to type into.
        $this->assertStringNotContainsString('<form', $this->between($first->getContent(), 'id="dineInRewardsPopup"', 'A little thank-you for every visit'));

        // Building the page is not seeing the pop-up: until the browser reports
        // that it really opened, a refresh still gets it.
        $this->assertNull(session(DineInJoin::SESSION_POPUP_SEEN), 'loading the page must not use up the pop-up');
        $this->get(route('customer.menu'))->assertOk()->assertSee('data-dinein-join-popup', false);

        // The browser reports it opened (the pop-up's own script does this).
        $this->postJson(route('customer.dinein-popup-seen'))->assertOk()->assertJson(['recorded' => true]);

        // Menu again, refreshed, QR URL re-opened: not shown again.
        $this->get(route('customer.menu'))->assertOk()->assertDontSee('data-dinein-join-popup', false);
        $this->get(route('customer.menu'))->assertOk()->assertDontSee('data-dinein-join-popup', false);
        $this->get($this->qrUrl($table))->assertOk()->assertDontSee('data-dinein-join-popup', false);

        // A new table session (a fresh browser at another table) is a new visit.
        $this->flushSession();
        $this->get($this->qrUrl($this->table(1)))->assertOk()->assertSee('data-dinein-join-popup', false);
    }

    /**
     * The bug as the owner met it: a guest types the table code, lands on the
     * menu, and the pop-up (which waits for the welcome popup) never appears.
     * The page load used to spend its one showing before the browser could
     * open it, so a reload or a tap-away in that window lost it for the whole
     * table session. Real routes, real session, no hand-built state.
     */
    public function test_a_guest_who_types_the_table_code_still_gets_the_popup_after_leaving_and_returning(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 5, 14);
        $table = $this->table(1);

        // The typed-code door, Continue as Guest: lands on the menu.
        $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', ['table_code' => $table->code, 'next' => 'guest'])
            ->assertRedirect(route('customer.menu'));

        // First menu load: the pop-up is on the page. It is the welcome popup's
        // turn first, so the guest taps away before it opens.
        $this->get(route('customer.menu'))->assertOk()
            ->assertSee('data-dinein-join-popup', false)
            ->assertSee('Sweet visits.');
        $this->get(route('customer.orders'))->assertOk();

        // Back on the menu (and again after a refresh): still due, since the
        // browser never reported it open.
        $this->get(route('customer.menu'))->assertOk()->assertSee('data-dinein-join-popup', false);
        $this->get(route('customer.menu'))->assertOk()->assertSee('data-dinein-join-popup', false);
        $this->assertNull(session(DineInJoin::SESSION_POPUP_SEEN));

        // This time it opens and reports; the table session has had its one.
        $this->postJson(route('customer.dinein-popup-seen'))->assertOk()->assertJson(['recorded' => true]);
        $this->get(route('customer.menu'))->assertOk()->assertDontSee('data-dinein-join-popup', false);
    }

    public function test_the_menu_page_carries_the_script_that_reports_the_popup_opened(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 5, 14);

        $html = $this->get($this->qrUrl($this->table(1)))->assertOk()->getContent();

        $this->assertStringContainsString(
            json_encode(route('customer.dinein-popup-seen')),
            $this->between($html, 'id="dineInRewardsPopup"', '</script>'),
            'the pop-up never tells the server it opened, so it could never be used up'
        );
    }

    /**
     * Guests used to wait ~6s for the welcome popup plus a 500ms poll. Now the
     * welcome popup auto-closes at 3s and announces its own closing (Let's go,
     * close, backdrop or timeout), and the rewards pop-up acts on that event
     * at once, so the gap is zero rather than "up to one poll".
     */
    public function test_the_rewards_popup_follows_the_welcome_popup_at_once_and_it_closes_in_three_seconds(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 5, 14);

        $html = $this->get($this->qrUrl($this->table(1)))->assertOk()->getContent();

        // A guest at a fresh table gets both, welcome first.
        $this->assertStringContainsString('id="welcomePopup"', $html);
        $this->assertStringContainsString('data-dinein-join-popup', $html);

        $welcome = $this->between($html, 'id="welcomePopup"', 'id="dineInRewardsPopup"');
        $this->assertStringContainsString('var AUTO_CLOSE_MS = 3000;', $welcome);
        $this->assertStringContainsString('}, AUTO_CLOSE_MS);', $welcome);
        $this->assertStringNotContainsString('6000', $welcome, 'the welcome popup is back to waiting 6s');
        // One close function serves the button, the backdrop and the timer, and it announces itself.
        $this->assertStringContainsString("dispatchEvent(new CustomEvent('welcomepopup:closed'))", $welcome);
        $this->assertSame(2, substr_count($welcome, 'onclick="closeWelcomePopup()"'));

        $rewards = $this->between($html, 'id="dineInRewardsPopup"', '</script>');
        $this->assertStringContainsString("addEventListener('welcomepopup:closed', tick)", $rewards);
        // The fallback poll can never be slower than the 0.5s promised.
        preg_match('/var POLL_MS = (\d+);/', $rewards, $m);
        $this->assertLessThanOrEqual(500, (int) $m[1]);
    }

    public function test_the_popup_report_marks_nobody_it_is_not_shown_to(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 5, 14);

        // No table session at all (pick-up).
        $this->post(route('customer.select-branch'), ['branch_id' => 1]);
        $this->postJson(route('customer.dinein-popup-seen'))->assertOk()->assertJson(['recorded' => false]);
        $this->assertNull(session(DineInJoin::SESSION_POPUP_SEEN));

        // A signed-in customer at a table.
        $this->actingAs($this->customer(), 'customer');
        $this->get($this->qrUrl($this->table(1)))->assertOk();
        $this->postJson(route('customer.dinein-popup-seen'))->assertOk()->assertJson(['recorded' => false]);
        $this->assertNull(session(DineInJoin::SESSION_POPUP_SEEN));
    }

    public function test_the_popup_is_menu_only(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 5, 14);
        $this->get($this->qrUrl($this->table(1)))->assertOk()->assertSee('data-dinein-join-popup', false);

        // Even for a session that has not had it yet, no other page shows it.
        session()->forget(DineInJoin::SESSION_POPUP_SEEN);

        foreach (['customer.orders', 'customer.more', 'customer.cart'] as $name) {
            $this->get(route($name))->assertDontSee('data-dinein-join-popup', false);
        }
    }

    public function test_a_signed_in_customer_never_gets_the_popup(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 5, 14);

        $this->actingAs($this->customer(), 'customer');

        $this->get($this->qrUrl($this->table(1)))->assertOk()->assertDontSee('data-dinein-join-popup', false);
        $this->assertNull(session(DineInJoin::SESSION_POPUP_SEEN), 'a customer must not even be marked as having seen it');
        $this->get(route('customer.menu'))->assertOk()->assertDontSee('data-dinein-join-popup', false);
    }

    public function test_no_popup_in_pick_up_or_where_the_branch_earns_no_stamps(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 5, 14);

        $this->post(route('customer.select-branch'), ['branch_id' => 1]);
        $this->get(route('customer.menu'))->assertOk()->assertDontSee('data-dinein-join-popup', false);

        $this->setBranchSwitch(1, false);
        $this->get($this->qrUrl($this->table(1)))->assertOk()->assertDontSee('data-dinein-join-popup', false);
        $this->assertNull(session(DineInJoin::SESSION_POPUP_SEEN));

        // Switching it back on, the same table session still gets its one showing.
        $this->setBranchSwitch(1, true);
        $this->get(route('customer.menu'))->assertOk()->assertSee('data-dinein-join-popup', false);
    }

    public function test_the_popup_links_keep_the_table_session_and_the_order_claim(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 'DJC Reward'), 5, 14);
        $table = $this->table(1);
        $item = $this->item(1, 'DJC Popup Item');

        $html = $this->get($this->qrUrl($table))->assertOk()->getContent();

        $signup = route('customer.dinein-join', ['mode' => DineInJoin::MODE_SIGNUP]);
        $login = route('customer.dinein-join', ['mode' => DineInJoin::MODE_LOGIN]);
        $this->assertStringContainsString('href="' . $signup . '"', $html);
        $this->assertStringContainsString('href="' . $login . '"', $html);
        $this->assertStringNotContainsString('order_id', $this->between($html, 'id="dineInRewardsPopup"', 'A little thank-you for every visit'));

        [$response, $order] = $this->placeDineIn($item);
        $response->assertSessionHasNoErrors();

        // The sign-up link behaves like the card's.
        $this->get($signup)->assertRedirect(route('customer.register'));
        $this->assertSame($order->id, session(DineInJoin::SESSION_ORDER));

        $user = $this->customer();
        $this->get($login)->assertRedirect(route('customer.login'));
        $this->assertSame($order->id, session(DineInJoin::SESSION_ORDER), 'the popup link did not pin this session\'s order');
        $this->assertSame((string) $table->table_number, (string) session('table_number'), 'the link lost the table session');

        $this->login($user)->assertRedirect();
        $this->assertSame($user->id, $order->fresh()->user_id);
    }

    public function test_the_stamp_row_follows_the_branch_days_when_it_fits_otherwise_five(): void
    {
        $reward = $this->item(1, 'DJC Reward');

        $this->chooseBranchReward(1, $reward, 4, 14);
        $html = $this->get($this->qrUrl($this->table(1)))->assertOk()->getContent();
        $this->assertSame(4, substr_count($html, '<span class="dinr__stamp'), 'the row did not match 4 configured days');
        $this->assertSame(2, substr_count($html, 'class="dinr__stamp dinr__stamp--done'));
        $this->assertSame(1, substr_count($html, 'class="dinr__stamp dinr__stamp--gift'));

        $this->flushSession();
        $this->chooseBranchReward(1, $reward, 9, 14);
        $html = $this->get($this->qrUrl($this->table(1)))->assertOk()->getContent();
        $this->assertSame(5, substr_count($html, '<span class="dinr__stamp'), 'a long card must fall back to the generic five');
    }

    public function test_the_more_page_guest_card_uses_the_new_copy(): void
    {
        $html = $this->get(route('customer.more'))->assertOk()->getContent();

        $this->assertStringContainsString('Create an account or log in to track your order history, earn voucher points, and unlock rewards by playing games and maintaining daily streaks!', $html);
        $this->assertStringNotContainsString('keep your order history and earn voucher points', $html);
        $this->assertStringContainsString('Log In', $html);
        $this->assertStringContainsString('Create Account', $html);
    }

    private function between(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);
        $this->assertNotFalse($start, "CONTROL: '$from' not on the page");
        $end = strpos($haystack, $to, $start);

        return substr($haystack, $start, ($end === false ? strlen($haystack) : $end) - $start);
    }
}

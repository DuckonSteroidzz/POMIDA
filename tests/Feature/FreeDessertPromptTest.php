<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FreeDessertReward;
use App\Models\Inventory;
use App\Models\LoyaltyAward;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\FreeDesserts;
use App\Support\FreeDessertPrompt;
use App\Support\GuestOrders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ConfiguresStreakReward;
use Tests\TestCase;

/**
 * The one-time "You earned a reward!" pop-up (Oct 2026) — see
 * App\Support\FreeDessertPrompt and customer/partials/free-dessert-prompt.
 *
 * Pinned here:
 *   - after staff complete the order that fills the card, the Orders and Menu
 *     pages say a reward is waiting, and the announce endpoint shows it ONCE
 *     (server-side notified_at): a refresh, a second tab or another device
 *     gets nothing more;
 *   - never for guests, unverified accounts, a used / expired / someone
 *     else's reward, or with the stamp card switched off;
 *   - several rewards at once are announced together ("2 rewards");
 *   - announcing grants and changes nothing else;
 *   - its one button opens More → Vouchers & Rewards (#rewards), where the
 *     reward's code is; the old claim link goes there too;
 *   - the pop-up waits for (never stacks on) the Spin prompt, the
 *     order-completed notice and the other overlays, and the order polls on
 *     both pages hand it completed orders for the live case. The real
 *     behaviour in a browser was checked with Playwright; these pin the wiring.
 *
 * Every row is created inside DatabaseTransactions and rolled back.
 */
class FreeDessertPromptTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresStreakReward;

    private MenuItem $rewardItem;

    // 12:00 Manila on 15 Oct 2026.
    private const NOW_UTC = '2026-10-15 04:00:00';

    private const MARKUP = 'id="freeDessertPrompt"';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());

        $this->travelTo(CarbonImmutable::parse(self::NOW_UTC, 'UTC'));

        config([
            'app.shop_timezone'                => 'Asia/Manila',
            'loyalty.streak_milestones'        => [3 => 10, 7 => 0],
            'loyalty.free_dessert_visit_days'  => 7,
            'loyalty.free_dessert_counts_from' => '2026-09-01',
            'loyalty.free_dessert_valid_days'  => 30,
        ]);

        // Nothing is granted until the owner chooses a reward.
        $this->clearStreakRewardSettings();
        $this->rewardItem = $this->chooseRewardItem($this->plainRewardItem(['name' => 'FDQ Reward Cake ' . uniqid()]));
    }

    // ══════════ fixtures ══════════

    private function customer(bool $verified = true): User
    {
        return User::create([
            'name'              => 'Prompt Test',
            'email'             => 'fd-prompt-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => $verified ? now() : null,
        ])->fresh();
    }

    private function admin(): User
    {
        return User::create([
            'name'              => 'Prompt admin',
            'email'             => 'fd-prompt-admin-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'admin',
            'branch_id'         => null,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function order(User $user, string $status, ?string $manilaDay = null): Order
    {
        $order = Order::create([
            'order_number'   => 'FDQ-' . strtoupper(Str::random(8)),
            'user_id'        => $user->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => $status,
            'payment_method' => 'cash',
            'payment_status' => $status === 'completed' ? 'paid' : 'pending',
            'subtotal'       => 100,
            'total'          => 100,
            'completed_at'   => $manilaDay
                ? CarbonImmutable::parse($manilaDay . ' 12:00:00', 'Asia/Manila')->utc()->format('Y-m-d H:i:s')
                : null,
        ]);

        // A stamp day is the day the order was PLACED (created_at, not fillable).
        if ($manilaDay) {
            DB::table('orders')->where('id', $order->id)->update([
                'created_at' => CarbonImmutable::parse($manilaDay . ' 12:00:00', 'Asia/Manila')->utc()->format('Y-m-d H:i:s'),
            ]);
            $order = $order->fresh();
        }

        return $order;
    }

    /** Staff complete an order through the real admin route (which grants). */
    private function complete(Order $order): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.orders.complete', $order->id))
            ->assertRedirect();

        $this->assertSame('completed', $order->fresh()->status);
    }

    /**
     * $earlierDays completed days before today, then staff complete an order
     * today. 6 => the 7th stamp => one reward.
     */
    private function fillCard(User $user, int $earlierDays = 6): void
    {
        for ($i = $earlierDays; $i >= 1; $i--) {
            $this->order($user, 'completed', CarbonImmutable::parse('2026-10-15')->subDays($i)->format('Y-m-d'));
        }

        $this->complete($this->order($user, 'pending'));
    }

    private function rewardsOf(User $user)
    {
        return FreeDessertReward::where('user_id', $user->id)->orderBy('id')->get();
    }

    private function announceAs(User $user)
    {
        return $this->actingAs($user, 'customer')
            ->postJson(route('customer.free-dessert.prompt'))
            ->assertOk();
    }

    private function dessertItem(): MenuItem
    {
        $inv = Inventory::create([
            'branch_id'       => 1,
            'item_name'       => 'FDQ Ingredient ' . uniqid(),
            'item_code'       => 'FDQ-' . strtoupper(substr(uniqid(), -8)) . random_int(10, 99),
            'quantity'        => 50,
            'unit'            => 'pc',
            'low_stock_alert' => 0,
            'unit_cost'       => 5,
            'is_active'       => true,
        ]);

        $item = MenuItem::create([
            'category_id'           => Category::value('id'),
            'branch_id'             => 1,
            'name'                  => 'FDQ Cake ' . uniqid(),
            'price'                 => 150,
            'cost'                  => 0,
            'is_available'          => true,
            'display_order'         => 0,
        ]);

        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        return $item->fresh();
    }

    private function cartWith(MenuItem $item): array
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

    // ══════════ shown once ══════════

    public function test_after_staff_complete_the_earning_order_it_is_shown_once(): void
    {
        $user = $this->customer();
        $this->fillCard($user);

        $this->assertCount(1, $this->rewardsOf($user));
        $this->assertNull($this->rewardsOf($user)[0]->notified_at);

        // Next visit: the Orders page carries the pop-up with a reward waiting.
        $this->actingAs($user, 'customer')->get('/customer/orders')->assertOk()
            ->assertSee(self::MARKUP, false)
            ->assertSee('data-pending="1"', false)
            ->assertSee('You earned a reward!')
            ->assertSee('Open Vouchers &amp; Rewards', false)
            ->assertDontSee('free dessert');

        // The page's script asks: shown, with the right words.
        $this->announceAs($user)->assertExactJson([
            'show'    => true,
            'count'   => 1,
            'title'   => 'You earned a reward!',
            'message' => 'Free ' . $this->rewardItem->name . '. Your reward code is waiting in Vouchers & Rewards. Use it by Nov 14, 2026.',
        ]);

        $this->assertNotNull($this->rewardsOf($user)[0]->notified_at, 'the server did not remember it');

        // Refresh a minute later (a moving clock, so a second stamp would
        // really change the row): nothing waiting, and asking shows nothing.
        $this->travel(1)->minutes();
        $this->actingAs($user, 'customer')->get('/customer/orders')->assertOk()
            ->assertSee(self::MARKUP, false)
            ->assertSee('data-pending="0"', false);

        $this->announceAs($user)->assertExactJson(['show' => false]);
    }

    public function test_the_menu_page_carries_it_too(): void
    {
        $user = $this->customer();
        $this->fillCard($user);

        $this->actingAs($user, 'customer')->get('/customer/menu')->assertOk()
            ->assertSee(self::MARKUP, false)
            ->assertSee('data-pending="1"', false);

        $this->announceAs($user)->assertJson(['show' => true, 'count' => 1]);

        $this->actingAs($user, 'customer')->get('/customer/menu')->assertOk()
            ->assertSee('data-pending="0"', false);
    }

    public function test_a_second_tab_or_another_device_never_shows_it_again(): void
    {
        $user = $this->customer();
        $this->fillCard($user);

        $this->announceAs($user)->assertJson(['show' => true]);

        // A different session for the same account (another phone), later.
        $this->travel(1)->minutes();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->announceAs($user)->assertExactJson(['show' => false]);
    }

    public function test_only_the_request_that_flips_the_marker_shows_it(): void
    {
        $user = $this->customer();
        $this->fillCard($user);
        $reward = $this->rewardsOf($user)[0];

        // Another tab marks it in the instant between "is one waiting?" and
        // this request's own UPDATE: the UPDATE's WHERE decides, not the read.
        $done = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$done, $reward) {
            if (! $done && str_starts_with($sql, 'update `free_dessert_rewards` set `notified_at`')) {
                $done = true;
                DB::table('free_dessert_rewards')->where('id', $reward->id)->update(['notified_at' => now()]);
            }
        });

        $this->actingAs($user, 'customer');
        $this->assertNull(FreeDessertPrompt::announce());
        $this->assertTrue($done, 'the competing write never ran');
    }

    // ══════════ never shown ══════════

    public function test_guests_get_no_markup_and_nothing_to_announce(): void
    {
        $order = Order::create([
            'order_number'   => 'FDQ-' . strtoupper(Str::random(8)),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ]);

        $this->withSession([GuestOrders::KEY => [$order->id]])->get('/customer/orders')->assertOk()
            ->assertDontSee(self::MARKUP, false)
            // The poll's hook is on every Orders page but does nothing
            // without the pop-up's own script, which a guest never gets.
            ->assertDontSee('window.PomidaFreeDessertPrompt = {', false);

        $this->get('/customer/menu')->assertOk()->assertDontSee(self::MARKUP, false);

        $this->postJson(route('customer.free-dessert.prompt'))->assertOk()->assertExactJson(['show' => false]);
    }

    public function test_an_unverified_account_gets_nothing(): void
    {
        $user = $this->customer(false);

        // Even holding a reward row (it cannot earn one), nothing is announced.
        FreeDesserts::grant($user, 1, 1);

        $this->actingAs($user, 'customer')->get('/customer/orders')->assertOk()
            ->assertDontSee(self::MARKUP, false);

        $this->announceAs($user)->assertExactJson(['show' => false]);
        $this->assertNull($this->rewardsOf($user)[0]->notified_at);
    }

    public function test_a_reward_already_used_is_never_announced(): void
    {
        $user = $this->customer();
        $this->fillCard($user);
        $this->rewardsOf($user)[0]->forceFill(['status' => FreeDessertReward::STATUS_USED, 'used_at' => now()])->save();

        $this->actingAs($user, 'customer')->get('/customer/orders')->assertOk()
            ->assertSee('data-pending="0"', false);

        $this->announceAs($user)->assertExactJson(['show' => false]);
        $this->assertNull($this->rewardsOf($user)[0]->notified_at);
    }

    public function test_an_expired_reward_is_never_announced(): void
    {
        $user = $this->customer();
        $this->fillCard($user);

        // Past its date but not yet swept to 'expired' ...
        $this->rewardsOf($user)[0]->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->announceAs($user)->assertExactJson(['show' => false]);

        // ... and swept.
        $this->rewardsOf($user)[0]->forceFill(['status' => FreeDessertReward::STATUS_EXPIRED])->save();
        $this->actingAs($user, 'customer')->get('/customer/menu')->assertOk()
            ->assertSee('data-pending="0"', false);
        $this->announceAs($user)->assertExactJson(['show' => false]);
    }

    public function test_another_customers_reward_is_never_announced_to_you(): void
    {
        $owner = $this->customer();
        $this->fillCard($owner);
        $other = $this->customer();

        $this->actingAs($other, 'customer')->get('/customer/orders')->assertOk()
            ->assertSee('data-pending="0"', false);
        $this->announceAs($other)->assertExactJson(['show' => false]);

        $this->assertNull($this->rewardsOf($owner)[0]->notified_at, "someone else's request marked it");
    }

    public function test_switched_off_there_is_no_pop_up(): void
    {
        // Since stamp cards are per branch (Oct 2026) the pop-up follows the
        // owner's MASTER switch only: a branch switched off hides just its own
        // calendar and stamp card, and config visit_days 0 only means the
        // company default has no days — a reward already earned is still
        // announced in both cases.
        $user = $this->customer();
        $this->fillCard($user);
        \App\Models\Setting::updateOrCreate(
            ['key' => \App\Models\Setting::STREAK_CALENDAR_ENABLED, 'branch_id' => null],
            ['value' => '0', 'group' => 'business', 'type' => 'text']
        );

        $this->actingAs($user, 'customer')->get('/customer/orders')->assertOk()
            ->assertDontSee(self::MARKUP, false);
        $this->announceAs($user)->assertExactJson(['show' => false]);
    }

    public function test_a_reward_handed_back_by_a_cancelled_order_is_not_announced_again(): void
    {
        $user = $this->customer();
        $this->fillCard($user);
        $this->announceAs($user)->assertJson(['show' => true]);

        // Spent, then handed back by a cancellation: available again, but it
        // was already announced once.
        $reward = $this->rewardsOf($user)[0];
        $order = $this->order($user, 'pending');
        $reward->forceFill(['status' => FreeDessertReward::STATUS_USED, 'order_id' => $order->id, 'used_at' => now()])->save();
        $order->forceFill(['status' => 'cancelled'])->save();
        FreeDesserts::releaseForOrder($order);

        $this->assertSame(FreeDessertReward::STATUS_AVAILABLE, $reward->fresh()->status);
        $this->travel(1)->minutes();
        $this->announceAs($user)->assertExactJson(['show' => false]);
    }

    // ══════════ several at once ══════════

    public function test_several_rewards_earned_at_once_are_announced_together(): void
    {
        $user = $this->customer();

        // 13 earlier days + today = 14 stamps = 2 rewards from one completion.
        $this->fillCard($user, 13);
        $this->assertCount(2, $this->rewardsOf($user));

        $this->announceAs($user)->assertExactJson([
            'show'    => true,
            'count'   => 2,
            'title'   => 'You earned 2 rewards!',
            'message' => 'Your reward codes are waiting in Vouchers & Rewards. Use the first one by Nov 14, 2026.',
        ]);

        $this->assertSame(0, $this->rewardsOf($user)->whereNull('notified_at')->count());
        $this->travel(1)->minutes();
        $this->announceAs($user)->assertExactJson(['show' => false]);
    }

    public function test_a_later_reward_gets_its_own_pop_up(): void
    {
        $user = $this->customer();
        $this->fillCard($user);
        $this->announceAs($user)->assertJson(['show' => true, 'count' => 1]);

        // A second full card a week later.
        for ($day = 16; $day <= 21; $day++) {
            $this->order($user, 'completed', '2026-10-' . $day);
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-22 04:00:00', 'UTC'));
        $this->complete($this->order($user, 'pending'));
        $this->assertCount(2, $this->rewardsOf($user));

        // Only the new one is announced, as a single reward.
        $this->announceAs($user)->assertJson(['show' => true, 'count' => 1, 'title' => 'You earned a reward!']);
    }

    public function test_announcing_grants_and_changes_nothing_else(): void
    {
        $user = $this->customer();
        $this->fillCard($user);

        $before = $this->rewardsOf($user)->map(fn ($r) => $r->only(['id', 'status', 'expires_at', 'order_id', 'used_at', 'updated_at']))->all();
        $ledger = LoyaltyAward::where('user_id', $user->id)->count();
        $points = $user->fresh()->only(['points', 'bonus_points']);

        $this->announceAs($user)->assertJson(['show' => true]);

        $this->assertEquals($before, $this->rewardsOf($user)->map(fn ($r) => $r->only(['id', 'status', 'expires_at', 'order_id', 'used_at', 'updated_at']))->all());
        $this->assertSame($ledger, LoyaltyAward::where('user_id', $user->id)->count());
        $this->assertSame($points, $user->fresh()->only(['points', 'bonus_points']));
    }

    // ══════════ the button: Vouchers & Rewards ══════════

    public function test_the_button_opens_vouchers_and_rewards_where_the_code_is(): void
    {
        $user = $this->customer();
        $this->fillCard($user);
        $cart = $this->cartWith($this->dessertItem());
        $session = ['cart' => $cart, 'branch_id' => 1, 'order_type' => 'pick_up'];

        // The old claim link (already in sent notifications) goes there too,
        // whatever the cart holds — there is no chooser any more.
        $this->actingAs($user, 'customer')->withSession($session)
            ->get(route('customer.free-dessert.claim'))
            ->assertRedirect(route('customer.vouchers') . '#rewards');

        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get(route('customer.free-dessert.claim'))
            ->assertRedirect(route('customer.vouchers') . '#rewards');

        // ... and #rewards is the list with the reward's code.
        $this->actingAs($user, 'customer')->get(route('customer.vouchers'))->assertOk()
            ->assertSee('id="rewards"', false)
            ->assertSee($this->rewardsOf($user)[0]->displayCode());

        $this->actingAs($user, 'customer')->withSession($session)->get(route('customer.cart'))->assertOk()
            ->assertDontSee('name="free_dessert_menu_item_id"', false);
    }

    public function test_the_pop_up_has_one_primary_button_and_a_close_x_only(): void
    {
        $user = $this->customer();
        $this->fillCard($user);

        $html = $this->actingAs($user, 'customer')->get('/customer/orders')->assertOk()->getContent();
        $start = strpos($html, self::MARKUP);
        $modal = substr($html, $start, strpos($html, '</div>', strpos($html, 'data-fd-prompt-claim')) - $start);

        $this->assertStringContainsString('role="dialog"', $modal);
        $this->assertStringContainsString('aria-modal="true"', $modal);
        $this->assertStringContainsString('aria-label="Close"', $modal);
        $this->assertStringContainsString('href="' . route('customer.vouchers') . '#rewards"', $modal);
        $this->assertStringNotContainsStringIgnoringCase('maybe later', $modal);
        $this->assertSame(1, substr_count($modal, '<button'), 'only the close X is a button');
        $this->assertSame(1, substr_count($modal, '<a '), 'only the Vouchers & Rewards button is a link');
    }

    // ══════════ never stacked, and the live path ══════════

    public function test_with_the_spin_prompt_due_too_both_render_and_the_dessert_waits_its_turn(): void
    {
        Setting::set(Setting::GAME_ENABLED, '1');
        $user = $this->customer();
        $this->fillCard($user);
        $this->order($user, 'pending'); // a fresh cash order today -> the Spin prompt is due

        $html = $this->actingAs($user, 'customer')->get('/customer/orders')->assertOk()->getContent();

        $spin = strpos($html, 'id="spinPromptModal"');
        $dessert = strpos($html, self::MARKUP);
        $this->assertNotFalse($spin, 'the Spin prompt should be due');
        $this->assertNotFalse($dessert);

        // The dessert script comes after the Spin prompt's, so the Spin
        // prompt's DOMContentLoaded handler opens first, and the dessert
        // script waits while it (or any other overlay) is on screen.
        $this->assertGreaterThan($spin, $dessert);
        foreach (['spinPromptModal', 'orderStatusNotice', 'discountVerificationNotice', 'ratingModal',
                  'dessertRatingModal', 'idleTimeoutModal'] as $overlay) {
            $this->assertStringContainsString("'" . $overlay . "'", substr($html, $dessert), $overlay . ' is not waited for');
        }
    }

    public function test_the_menu_ad_waits_for_the_dessert_pop_up(): void
    {
        $partial = file_get_contents(resource_path('views/customer/partials/menu-ad-popup.blade.php'));

        $this->assertStringContainsString("shown('freeDessertPrompt')", $partial);
    }

    public function test_both_pages_hand_completed_orders_to_the_pop_up_for_the_live_case(): void
    {
        $user = $this->customer();
        $this->order($user, 'pending');

        $orders = $this->actingAs($user, 'customer')->get('/customer/orders')->assertOk()->getContent();
        $menu = $this->actingAs($user, 'customer')->get('/customer/menu')->assertOk()->getContent();

        $this->assertStringContainsString('PomidaFreeDessertPrompt.orderCompleted(', $orders);
        $this->assertStringContainsString('PomidaFreeDessertPrompt.orderCompleted(', $menu);

        // Present and ready even with nothing waiting yet.
        $this->assertStringContainsString('data-pending="0"', $orders);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FreeDessertReward;
use App\Models\Inventory;
use App\Models\LoyaltyAward;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\FreeDesserts;
use App\Services\Loyalty;
use App\Support\GuestOrders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ConfiguresStreakReward;
use Tests\TestCase;

/**
 * The owner's "Streak Calendar on/off" switch (Oct 2026) —
 * Setting::streakCalendarEnabled(), flipped by AdminController::
 * toggleStreakCalendar() from the card at the top of the Streak Rewards page.
 *
 * Built like the Spin & Win switch (one global settings row, branch_id NULL,
 * owner only) except that a MISSING row is ON, so deploying changes nothing.
 *
 * OFF hides the calendar, the stamp card, the progress bar, the benefits
 * lines, the Help topic and the pop-up, and the SERVER stops paying new
 * streak bonuses and granting new rewards. A reward already earned stays
 * listed (with its code) and spendable. ON again: stamps are computed from completed orders,
 * so the next staff completion grants whatever became due meanwhile.
 *
 * Every row is created here inside DatabaseTransactions, including the
 * settings row each test pins; nothing survives the run.
 */
class StreakCalendarSwitchTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresStreakReward;

    // 12:00 Manila on 15 Oct 2026.
    private const NOW_UTC = '2026-10-15 04:00:00';

    private const POPUP = 'id="freeDessertPrompt"';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());

        $this->travelTo(CarbonImmutable::parse(self::NOW_UTC, 'UTC'));

        config([
            'app.shop_timezone'                => 'Asia/Manila',
            'loyalty.welcome_bonus_points'     => 10,
            'loyalty.order_bonus_points'       => 3,
            'loyalty.streak_milestones'        => [3 => 10, 7 => 0],
            'loyalty.free_dessert_visit_days'  => 7,
            'loyalty.free_dessert_counts_from' => '2026-09-01',
            'loyalty.free_dessert_valid_days'  => 30,
        ]);

        // Start every test from the shipped state: no row at all.
        $this->setSwitch(null);

        // Nothing is granted until a reward is chosen.
        $this->clearStreakRewardSettings();
        $this->chooseRewardItem($this->plainRewardItem());
    }

    // ══════════ fixtures ══════════

    /** null = no row (the deploy state); '0' / '1' = one global row. */
    private function setSwitch(?string $value): void
    {
        DB::table('settings')->where('key', Setting::STREAK_CALENDAR_ENABLED)->delete();

        if ($value !== null) {
            DB::table('settings')->insert([
                'key'        => Setting::STREAK_CALENDAR_ENABLED,
                'branch_id'  => null,
                'value'      => $value,
                'group'      => 'business',
                'label'      => 'Streak Calendar & Reward Stamps Enabled',
                'type'       => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function globalRows()
    {
        return DB::table('settings')->where('key', Setting::STREAK_CALENDAR_ENABLED)->whereNull('branch_id')->get();
    }

    private function customer(): User
    {
        return User::create([
            'name'              => 'Switch Test',
            'email'             => 'streak-switch-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ])->fresh();
    }

    private function portalUser(string $role, ?int $branchId = 1): User
    {
        return User::create([
            'name'              => 'Switch ' . $role,
            'email'             => 'streak-switch-' . $role . '-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $role === 'admin' ? null : $branchId,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function manila(string $local): string
    {
        return CarbonImmutable::parse($local, 'Asia/Manila')->utc()->format('Y-m-d H:i:s');
    }

    private function completedOrderOn(?User $user, string $manilaDay): Order
    {
        $order = Order::create([
            'order_number'   => 'SSW-' . strtoupper(Str::random(8)),
            'user_id'        => $user?->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'completed',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'subtotal'       => 100,
            'total'          => 100,
            'completed_at'   => $this->manila($manilaDay . ' 12:00:00'),
        ]);

        // A streak day is the day the order was PLACED (created_at, not fillable).
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $this->manila($manilaDay . ' 12:00:00')]);

        return $order->fresh();
    }

    private function pendingOrder(?User $user): Order
    {
        return Order::create([
            'order_number'   => 'SSP-' . strtoupper(Str::random(8)),
            'user_id'        => $user?->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ]);
    }

    /** Staff complete an order through the real admin route (the path that pays). */
    private function complete(Order $order): void
    {
        $this->actingAs($this->portalUser('admin'), 'admin')
            ->put(route('admin.orders.complete', $order->id))
            ->assertRedirect();

        $this->assertSame('completed', $order->fresh()->status, 'the order did not complete');
    }

    /** Six completed days, 9–14 Oct: one more day fills the card and makes a 7-day run. */
    private function sixEarlierDays(User $user): void
    {
        foreach (range(9, 14) as $day) {
            $this->completedOrderOn($user, '2026-10-' . $day);
        }
    }

    private function awards(User $user, string $type)
    {
        return LoyaltyAward::where('user_id', $user->id)->where('type', $type)->orderBy('id')->get();
    }

    private function inventory(float $quantity): Inventory
    {
        return Inventory::create([
            'branch_id'       => 1,
            'item_name'       => 'SSW Ingredient ' . uniqid(),
            'item_code'       => 'SSW-' . strtoupper(substr(uniqid(), -8)) . random_int(10, 99),
            'quantity'        => $quantity,
            'unit'            => 'pc',
            'low_stock_alert' => 0,
            'unit_cost'       => 5,
            'is_active'       => true,
        ]);
    }

    private function item(array $attrs = []): MenuItem
    {
        $inv = $this->inventory(10);
        $item = MenuItem::create(array_merge([
            'category_id'   => Category::value('id'),
            'branch_id'     => 1,
            'name'          => 'SSW Item ' . uniqid(),
            'price'         => 100,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ], $attrs));

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

    // ══════════ the setting itself ══════════

    public function test_it_is_on_by_default_when_no_row_exists(): void
    {
        $this->assertCount(0, $this->globalRows());
        $this->assertTrue(Setting::streakCalendarEnabled());
        $this->assertTrue(FreeDesserts::active(1));

        $user = $this->customer();
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')->assertOk()
            ->assertSee('id="streak"', false)
            ->assertSee('data-testid="free-dessert-card"', false)
            ->assertSee('data-testid="benefit-streak"', false)
            ->assertSee('data-testid="benefit-free-dessert"', false);

        $this->actingAs($this->portalUser('admin'), 'admin')->get(route('admin.streak-rewards'))->assertOk()
            ->assertSee('Master Streak Calendar', false)
            ->assertSee('Turns the stamp card off for every branch');
    }

    public function test_only_the_exact_value_0_on_the_global_row_turns_it_off(): void
    {
        $this->setSwitch('1');
        $this->assertTrue(Setting::streakCalendarEnabled());

        $this->setSwitch('0');
        $this->assertFalse(Setting::streakCalendarEnabled());
        $this->assertFalse(FreeDesserts::active(1));
        $this->assertTrue(FreeDesserts::enabled(), 'the config half must stay on so earned rewards can be spent');

        // A branch row is not the switch, exactly like game_enabled.
        $this->setSwitch(null);
        DB::table('settings')->insert([
            'key' => Setting::STREAK_CALENDAR_ENABLED, 'branch_id' => 1, 'value' => '0',
            'group' => 'business', 'type' => 'text', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertTrue(Setting::streakCalendarEnabled());
    }

    // ══════════ OFF hides every piece ══════════

    public function test_off_hides_the_calendar_progress_bar_stamp_card_and_benefit_lines_on_the_account_page(): void
    {
        $user = $this->customer();
        $this->completedOrderOn($user, '2026-10-14');
        $this->setSwitch('0');

        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')->assertOk()
            ->assertDontSee('id="streak"', false)
            ->assertDontSee('Streak Calendar')
            ->assertDontSee('data-testid="free-dessert-card"', false)
            ->assertDontSee('data-testid="benefit-streak"', false)
            ->assertDontSee('data-testid="benefit-free-dessert"', false)
            // The rest of the benefits card is untouched.
            ->assertSee('id="benefits"', false)
            ->assertSee('data-testid="benefit-welcome"', false)
            ->assertSee('data-testid="benefit-order"', false)
            ->assertSee('data-testid="benefit-spins"', false);
    }

    public function test_off_hides_the_stamp_card_on_vouchers_and_rewards_and_the_help_topic(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/vouchers')->assertOk()
            ->assertSee('data-testid="free-dessert-card"', false);
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/help')->assertOk()
            ->assertSee('data-testid="help-free-dessert"', false);

        $this->setSwitch('0');

        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/vouchers')->assertOk()
            ->assertDontSee('data-testid="free-dessert-card"', false);
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/help')->assertOk()
            ->assertDontSee('data-testid="help-free-dessert"', false)
            ->assertSee('Spin &amp; Win', false);
    }

    public function test_off_shows_no_claim_pop_up_and_the_endpoint_claims_nothing(): void
    {
        $user = $this->customer();
        $reward = FreeDesserts::grant($user, 1, 1);
        $this->assertNotNull($reward);

        $this->setSwitch('0');

        foreach (['/customer/orders', '/customer/menu'] as $page) {
            $this->actingAs($user, 'customer')->get($page)->assertOk()->assertDontSee(self::POPUP, false);
        }

        $this->actingAs($user, 'customer')->postJson(route('customer.free-dessert.prompt'))
            ->assertOk()->assertExactJson(['show' => false]);
        $this->assertNull($reward->fresh()->notified_at, 'a reward was marked as announced while the switch was off');

        // Back on: the pop-up for that reward is still owed and shows once.
        $this->setSwitch('1');
        $this->actingAs($user, 'customer')->get('/customer/orders')->assertOk()
            ->assertSee(self::POPUP, false)
            ->assertSee('data-pending="1"', false);
    }

    // ══════════ OFF stops earning on the server ══════════

    public function test_off_the_server_grants_no_reward_and_no_streak_bonus_but_still_the_order_bonus(): void
    {
        $user = $this->customer();
        $this->sixEarlierDays($user);
        $this->setSwitch('0');

        $today = $this->pendingOrder($user);
        $this->complete($today);

        $this->assertCount(0, FreeDessertReward::where('user_id', $user->id)->get(), 'a reward was granted while switched off');
        $this->assertCount(0, $this->awards($user, LoyaltyAward::TYPE_FREE_DESSERT));
        $this->assertCount(0, $this->awards($user, LoyaltyAward::TYPE_STREAK), 'a streak bonus was paid while switched off');
        $this->assertSame(0, Notification::where('user_id', $user->id)->where('type', 'free_dessert_earned')->count());

        // Not affected by the switch: the per-order bonus.
        $orderBonus = $this->awards($user, LoyaltyAward::TYPE_ORDER_BONUS);
        $this->assertCount(1, $orderBonus);
        $this->assertSame((string) $today->id, $orderBonus[0]->award_key);
        $this->assertSame(3, (int) $user->fresh()->bonus_points);
    }

    public function test_off_the_services_refuse_directly_not_only_through_the_views(): void
    {
        $user = $this->customer();
        $this->sixEarlierDays($user);
        $this->completedOrderOn($user, '2026-10-15');
        $this->setSwitch('0');

        $this->assertSame([], FreeDesserts::grantDue($user, 1));
        $this->assertNull(FreeDesserts::grant($user, 1, 1));
        $this->assertSame(['enabled' => false], FreeDesserts::summaryFor($user, 1));

        $order = Order::where('user_id', $user->id)->latest('id')->first();
        $paid = Loyalty::orderCompleted($order);
        $this->assertNotContains(LoyaltyAward::TYPE_STREAK, $paid);
        $this->assertNotContains(LoyaltyAward::TYPE_FREE_DESSERT, $paid);
        $this->assertContains(LoyaltyAward::TYPE_ORDER_BONUS, $paid);
    }

    public function test_off_the_welcome_bonus_is_still_paid(): void
    {
        $this->setSwitch('0');
        $user = $this->customer();

        $this->assertTrue(Loyalty::welcome($user));
        $this->assertSame(10, (int) $user->fresh()->bonus_points);
    }

    public function test_off_the_guest_teaser_drops_only_the_streak_and_reward_perks(): void
    {
        $this->assertSame(
            'Create a free account for 10 welcome points, +3 points on every completed order, streak bonuses at 3 days and a reward for every 7 stamps (1 stamp per day you completed an order).',
            Loyalty::guestTeaser()
        );

        $this->setSwitch('0');

        $this->assertSame(
            'Create a free account for 10 welcome points and +3 points on every completed order.',
            Loyalty::guestTeaser()
        );
    }

    // ══════════ rewards already earned stay usable ══════════

    public function test_off_an_already_earned_reward_is_still_listed_and_spent_with_its_code(): void
    {
        $user = $this->customer();
        $dessert = $this->chooseRewardItem($this->item(['name' => 'SSW Cake ' . uniqid(), 'price' => 150]));
        $reward = FreeDesserts::grant($user, 1, 1);
        $this->assertNotNull($reward);
        $this->setSwitch('0');

        // The stamp card is gone, but the reward and its code are still there.
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/vouchers')->assertOk()
            ->assertDontSee('data-testid="free-dessert-card"', false)
            ->assertSee('data-testid="loyalty-reward"', false)
            ->assertSee($reward->displayCode())
            ->assertSee(route('customer.rewards.use', $reward->id), false);

        $paid = $this->item();
        $session = ['cart' => $this->cart($paid), 'branch_id' => 1, 'order_type' => 'pick_up'];

        $before = (int) Order::max('id');
        $this->actingAs($user, 'customer')->withSession($session)
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $paid->id, 'quantity' => 1]],
                'reward_code'    => $reward->displayCode(),
            ])
            ->assertSessionHasNoErrors();

        $order = Order::where('id', '>', $before)->where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame($dessert->id, $order->items()->where('is_free_dessert', true)->sole()->menu_item_id);
        $this->assertSame('used', $reward->fresh()->status);
        $this->assertSame($order->id, $reward->fresh()->order_id);
    }

    // ══════════ ON again: catch-up ══════════

    public function test_switched_back_on_the_next_completion_catches_up_on_stamps_and_the_running_streak(): void
    {
        $user = $this->customer();
        $this->sixEarlierDays($user);

        // Off on the 15th: that completion is a stamp, but grants nothing.
        $this->setSwitch('0');
        $this->complete($this->pendingOrder($user));
        $this->assertCount(0, FreeDessertReward::where('user_id', $user->id)->get());
        $this->assertCount(0, $this->awards($user, LoyaltyAward::TYPE_STREAK));

        // On again the next day; staff complete one more order.
        $this->setSwitch('1');
        $this->travelTo(CarbonImmutable::parse('2026-10-16 04:00:00', 'UTC'));
        $next = $this->pendingOrder($user);
        $this->complete($next);

        // 8 stamps (9–16 Oct, the off days included) = reward #1, granted now.
        $this->assertSame(['visits:1:1'], $this->awards($user, LoyaltyAward::TYPE_FREE_DESSERT)->pluck('award_key')->all());
        $this->assertSame($next->id, $this->awards($user, LoyaltyAward::TYPE_FREE_DESSERT)[0]->order_id);
        $this->assertSame(1, FreeDessertReward::where('user_id', $user->id)->where('status', 'available')->count());
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('type', 'free_dessert_earned')->count());

        // The streak kept going through the off day, so its 3-day bonus is
        // paid now, once, keyed on the streak's first day.
        $this->assertSame(['3:2026-10-09'], $this->awards($user, LoyaltyAward::TYPE_STREAK)->pluck('award_key')->all());

        // The calendar is back on the account page.
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')->assertOk()
            ->assertSee('id="streak"', false)
            ->assertSee('data-testid="free-dessert-available"', false);
    }

    // ══════════ who can switch it ══════════

    public function test_the_owner_switches_it_off_and_on_with_one_row(): void
    {
        $owner = $this->portalUser('admin');
        $gameRows = DB::table('settings')->where('key', 'game_enabled')->orderBy('id')->get(['id', 'value'])->toArray();

        // From the deploy state (no row): the first press turns it OFF.
        $this->actingAs($owner, 'admin')->post(route('admin.streak.toggle'))
            ->assertRedirect(route('admin.streak-rewards'))
            ->assertSessionHas('success', 'Streak Calendar & Reward Stamps disabled!');
        $this->assertSame(['0'], $this->globalRows()->pluck('value')->all());
        $this->assertFalse(Setting::streakCalendarEnabled());

        // The Master Streak Calendar switch now reads Off.
        $this->assertMatchesRegularExpression(
            '/role="switch" aria-checked="false"\s+aria-label="Master Streak Calendar" data-testid="streak-master-switch"/',
            $this->actingAs($owner, 'admin')->get(route('admin.streak-rewards'))->assertOk()->getContent()
        );

        $this->actingAs($owner, 'admin')->post(route('admin.streak.toggle'))
            ->assertRedirect(route('admin.streak-rewards'))
            ->assertSessionHas('success', 'Streak Calendar & Reward Stamps enabled!');
        $this->assertSame(['1'], $this->globalRows()->pluck('value')->all(), 'toggling must update the row in place');
        $this->assertSame(1, DB::table('settings')->where('key', Setting::STREAK_CALENDAR_ENABLED)->count());

        // The Spin & Win switch is a separate row and was not touched.
        $this->assertEquals($gameRows, DB::table('settings')->where('key', 'game_enabled')->orderBy('id')->get(['id', 'value'])->toArray());
    }

    public function test_supervisor_staff_customers_and_guests_cannot_switch_it(): void
    {
        $this->setSwitch('1');

        foreach (['supervisor', 'staff'] as $role) {
            $this->actingAs($this->portalUser($role), 'admin')
                ->post(route('admin.streak.toggle'))
                ->assertRedirect(route('admin.home'));
        }

        // Supervisor can still reach the Streak Rewards page itself (their
        // own branch's settings) but never sees the master switch there.
        $this->actingAs($this->portalUser('supervisor'), 'admin')->get(route('admin.streak-rewards'))->assertOk()
            ->assertDontSee(route('admin.streak.toggle'), false)
            ->assertDontSee('Master Streak Calendar', false);

        // Staff cannot reach the page at all.
        $this->actingAs($this->portalUser('staff'), 'admin')->get(route('admin.streak-rewards'))
            ->assertRedirect(route('admin.home'));

        $this->actingAs($this->customer(), 'customer')->post(route('admin.streak.toggle'))->assertRedirect();

        auth()->guard('admin')->logout();
        auth()->guard('customer')->logout();
        $this->post(route('admin.streak.toggle'))->assertRedirect();

        $this->assertSame(['1'], $this->globalRows()->pluck('value')->all(), 'a refused request changed the switch');
    }

    // ══════════ guests ══════════

    public function test_guests_are_unaffected_either_way(): void
    {
        foreach (['1', '0'] as $value) {
            $this->setSwitch($value);

            $order = $this->pendingOrder(null);
            $this->withSession([GuestOrders::KEY => [$order->id]])->get('/customer/orders')->assertOk()
                ->assertDontSee(self::POPUP, false);
            $this->get('/customer/menu')->assertOk()->assertDontSee(self::POPUP, false);
            $this->get('/customer/help')->assertOk();
            $this->get('/customer/account')->assertRedirect(route('customer.menu'));

            // A guest order completes and earns nothing, as before.
            $before = LoyaltyAward::count();
            $this->complete($order);
            $this->assertSame($before, LoyaltyAward::count(), "a guest order paid something with the switch at '$value'");
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FreeDessertRating;
use App\Models\FreeDessertReward;
use App\Models\Inventory;
use App\Models\LoyaltyAward;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRating;
use App\Models\User;
use App\Models\Voucher;
use App\Services\FreeDesserts;
use App\Services\Loyalty;
use App\Support\RewardCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ConfiguresStreakReward;
use Tests\TestCase;

/**
 * The stamp card and its menu-item rewards (Oct 2026) — App\Services\FreeDesserts.
 *
 * Earning (Loyalty::orderCompleted, staff completion: one stamp per shop day
 * with a completed order, any days, one reward per X stamps), the stamp card
 * and challenge bar on the Account page and Vouchers & Rewards, spending WITH
 * THE REWARD'S CODE (checkout, inside the order transaction), giving back
 * (cancel / GCash reject), expiry, the interplay with vouchers / PWD-Senior /
 * stock, branches, the separate reward rating and the staff label.
 *
 * Since the Streak Rewards pass the reward is chosen in the owner's settings;
 * this class uses a menu-item reward (ConfiguresStreakReward). Voucher-type
 * rewards, the settings, permissions, the code check and the rename are in
 * StreakRewardsTest.
 *
 * Config is pinned per test so the owner can change the shipped values. Every
 * row is created here inside DatabaseTransactions. The two cross-process races
 * (earning twice, spending twice) are in FreeDessertRaceTest.
 */
class FreeDessertRewardTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresStreakReward;

    // 12:00 Manila on 15 Oct 2026.
    private const NOW_UTC = '2026-10-15 04:00:00';

    /** The reward chosen in setUp, for the tests that only earn. */
    private MenuItem $defaultRewardItem;

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
            // Earlier than every fixture day below; the start-date tests move it.
            'loyalty.free_dessert_counts_from' => '2026-09-01',
            'loyalty.free_dessert_valid_days'  => 30,
        ]);

        $this->clearStreakRewardSettings();
        $this->defaultRewardItem = $this->chooseRewardItem($this->plainRewardItem(['name' => 'FD Default Cake ' . uniqid()]));
    }

    // ══════════ fixtures ══════════

    private function customer(bool $verified = true): User
    {
        return User::create([
            'name'              => 'Dessert Test',
            'email'             => 'dessert-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => $verified ? now() : null,
        ])->fresh();
    }

    private function staffUser(string $role, ?int $branchId = 1): User
    {
        return User::create([
            'name'              => 'Dessert ' . $role,
            'email'             => 'dessert-' . $role . '-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $branchId,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function inventory(float $quantity, int $branchId = 1): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => 'FD Ingredient ' . uniqid(),
            'item_code'       => 'FD-' . strtoupper(substr(uniqid(), -8)) . random_int(10, 99),
            'quantity'        => $quantity,
            'unit'            => 'pc',
            'low_stock_alert' => 0,
            'unit_cost'       => 5,
            'is_active'       => true,
        ]);
    }

    /** A menu item whose recipe uses 1 unit of $inv per serving. */
    private function item(Inventory $inv, array $attrs = []): MenuItem
    {
        $item = MenuItem::create(array_merge([
            'category_id'   => Category::value('id'),
            'branch_id'     => $inv->branch_id,
            'name'          => 'FD Item ' . uniqid(),
            'price'         => 100,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ], $attrs));

        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $inv->id,
            'quantity_used' => 1,
        ]);

        return $item->fresh();
    }

    /** A ₱150 cake with its own stock, at $branchId. */
    private function dessert(float $stock = 10, int $branchId = 1, array $attrs = []): MenuItem
    {
        return $this->item($this->inventory($stock, $branchId), array_merge([
            'name'  => 'FD Cake ' . uniqid(),
            'price' => 150,
        ], $attrs));
    }

    private function cart(MenuItem $item, int $qty = 1): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => (float) $item->price,
            'quantity'     => $qty,
            'image'        => null,
            'options'      => [],
        ]];
    }

    /** Grant reward number $number, as $item when given (else the reward chosen now). */
    private function reward(User $user, int $number = 1, ?MenuItem $item = null): FreeDessertReward
    {
        if ($item) {
            $this->chooseRewardItem($item);
        }

        $reward = FreeDesserts::grant($user, 1, $number);
        $this->assertNotNull($reward);

        return $reward;
    }

    /** Checkout as $user. Returns the new order, or null when refused. */
    private function place(User $user, array $cart, array $extra = [], int $branchId = 1, string $type = 'pick_up')
    {
        $before = (int) Order::max('id');

        $session = ['cart' => $cart, 'branch_id' => $branchId, 'order_type' => $type];
        if ($type === 'dine_in') {
            $session['table_number'] = '7';
        }

        $response = $this->actingAs($user, 'customer')
            ->withSession($session)
            ->post('/customer/place-order', array_merge([
                'order_type'     => $type,
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => array_values($cart)[0]['menu_item_id'], 'quantity' => 1]],
            ], $type === 'dine_in' ? ['table_number' => '7'] : [], $extra));

        return [$response, Order::where('id', '>', $before)->where('user_id', $user->id)->latest('id')->first()];
    }

    private function manila(string $local): string
    {
        return CarbonImmutable::parse($local, 'Asia/Manila')->utc()->format('Y-m-d H:i:s');
    }

    private function completedOrderOn(User $user, string $manilaDay, int $branchId = 1): Order
    {
        $order = Order::create([
            'order_number'   => 'FDS-' . strtoupper(Str::random(8)),
            'user_id'        => $user->id,
            'branch_id'      => $branchId,
            'type'           => 'pick_up',
            'status'         => 'completed',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'subtotal'       => 100,
            'total'          => 100,
            'completed_at'   => $this->manila($manilaDay . ' 12:00:00'),
        ]);

        // A stamp day is the day the order was PLACED (created_at, not fillable).
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $this->manila($manilaDay . ' 12:00:00')]);

        return $order->fresh();
    }

    private function pendingOrder(User $user, int $branchId = 1): Order
    {
        return Order::create([
            'order_number'   => 'FDP-' . strtoupper(Str::random(8)),
            'user_id'        => $user->id,
            'branch_id'      => $branchId,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ]);
    }

    /** Six completed days (9–14 Oct) then staff complete a 7th order today. */
    private function completeSevenDayStreak(User $user, array $branches = [1]): Order
    {
        foreach (range(9, 14) as $i => $day) {
            $this->completedOrderOn($user, '2026-10-' . $day, $branches[$i % count($branches)]);
        }

        $today = $this->pendingOrder($user, $branches[6 % count($branches)]);
        $this->complete($today);

        return $today;
    }

    private function complete(Order $order): void
    {
        $this->actingAs($this->staffUser('admin', null), 'admin')
            ->put(route('admin.orders.complete', $order->id))
            ->assertRedirect();

        $this->assertSame('completed', $order->fresh()->status, 'the order did not complete');
    }

    private function rewardsOf(User $user)
    {
        return FreeDessertReward::where('user_id', $user->id)->orderBy('id')->get();
    }

    // ══════════ earning: the stamp card ══════════

    /** A completed order at an exact Manila wall-clock time ('Y-m-d H:i:s'). */
    private function completedOrderAt(User $user, string $manilaDateTime): Order
    {
        $order = $this->completedOrderOn($user, substr($manilaDateTime, 0, 10));
        $order->forceFill(['completed_at' => $this->manila($manilaDateTime)])->save();
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $this->manila($manilaDateTime)]);

        return $order->fresh();
    }

    /** Completed orders on $count consecutive Manila days from $firstDay. */
    private function completedDaysFrom(User $user, string $firstDay, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->completedOrderOn($user, CarbonImmutable::parse($firstDay)->addDays($i)->format('Y-m-d'));
        }
    }

    private function dessertKeys(User $user): array
    {
        return LoyaltyAward::where('user_id', $user->id)
            ->where('type', LoyaltyAward::TYPE_FREE_DESSERT)
            ->orderBy('id')
            ->pluck('award_key')
            ->all();
    }

    public function test_seven_stamp_days_earn_exactly_one_reward_with_its_own_code_and_snapshot(): void
    {
        $user = $this->customer();
        $order = $this->completeSevenDayStreak($user);

        $rewards = $this->rewardsOf($user);
        $this->assertCount(1, $rewards);
        $this->assertSame('available', $rewards[0]->status);

        // Its own code, in the reward-code shape, and the snapshot of the reward chosen now.
        $this->assertTrue(RewardCode::looksLike($rewards[0]->code), 'not a reward code: ' . $rewards[0]->code);
        $this->assertSame('menu_item', $rewards[0]->reward_type);
        $this->assertSame($this->defaultRewardItem->id, $rewards[0]->menu_item_id);
        $this->assertSame($this->defaultRewardItem->name, $rewards[0]->item_name);
        $this->assertSame('Free ' . $this->defaultRewardItem->name, $rewards[0]->description());

        $award = LoyaltyAward::find($rewards[0]->loyalty_award_id);
        $this->assertSame(LoyaltyAward::TYPE_FREE_DESSERT, $award->type);
        $this->assertSame('visits:1:1', $award->award_key);
        $this->assertSame(0, $award->points);
        $this->assertSame($order->id, $award->order_id);
        $this->assertSame('Stamp card reward #1', $award->label());
        $this->assertSame('Stamp card reward', (new LoyaltyAward(['type' => LoyaltyAward::TYPE_FREE_DESSERT, 'award_key' => '7:2026-10-09']))->label());

        // Good through the end of 14 Nov 2026, Manila (earned 15 Oct + 30 days).
        $this->assertTrue($rewards[0]->expires_at->equalTo(CarbonImmutable::parse('2026-11-14 23:59:59', 'Asia/Manila')));

        // The streak POINTS bonuses are untouched: 3-day still paid, 7-day still 0.
        $this->assertSame(0, LoyaltyAward::where('user_id', $user->id)->where('award_key', '7:2026-10-09')->where('type', LoyaltyAward::TYPE_STREAK)->count());
        $this->assertSame(1, LoyaltyAward::where('user_id', $user->id)->where('award_key', '3:2026-10-09')->where('type', LoyaltyAward::TYPE_STREAK)->count());

        $note = Notification::where('user_id', $user->id)->where('type', 'free_dessert_earned')->sole();
        $this->assertSame('You earned a reward!', $note->title);
        $this->assertSame(
            'Thank you! You collected 7 stamps at Main Branch (1 stamp per day you completed an order there). Your reward (Free '
                . $this->defaultRewardItem->name . ') and its code are in More → Vouchers & Rewards. Use it by Nov 14, 2026.',
            $note->message
        );
        // Generic now: the reward may not be a dessert at all.
        $this->assertStringNotContainsString('dessert', strtolower($note->title . ' ' . $note->message));
    }

    public function test_nothing_is_granted_while_no_reward_is_chosen_and_the_next_completion_catches_up(): void
    {
        $this->clearStreakRewardSettings();
        $user = $this->customer();

        $this->completeSevenDayStreak($user);

        $this->assertCount(0, $this->rewardsOf($user));
        $this->assertSame([], $this->dessertKeys($user), 'a ledger number was used up with no reward');
        $this->assertSame(1, FreeDesserts::summaryFor($user, 1)['waiting']);
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')
            ->assertOk()
            ->assertSee('data-testid="free-dessert-waiting"', false)
            ->assertSee('Your card is full! Your reward will be added to your account soon.');

        // The owner chooses a reward; the next completion grants the one owed.
        $this->chooseRewardItem($this->defaultRewardItem);
        $this->travelTo(CarbonImmutable::parse('2026-10-16 04:00:00', 'UTC'));
        $this->complete($this->pendingOrder($user));

        $this->assertSame(['visits:1:1'], $this->dessertKeys($user));
        $this->assertCount(1, $this->rewardsOf($user));
    }

    public function test_non_consecutive_days_count(): void
    {
        $user = $this->customer();

        // Six scattered days, never two in a row, then staff complete a 7th today (15 Oct).
        foreach (['2026-09-20', '2026-09-27', '2026-10-01', '2026-10-04', '2026-10-08', '2026-10-12'] as $day) {
            $this->completedOrderOn($user, $day);
        }
        $this->complete($this->pendingOrder($user));

        $this->assertCount(1, $this->rewardsOf($user));
        $this->assertSame(['visits:1:1'], $this->dessertKeys($user));
        // No run was ever longer than one day, so no streak points were paid.
        $this->assertSame(0, LoyaltyAward::where('user_id', $user->id)->where('type', LoyaltyAward::TYPE_STREAK)->count());
    }

    public function test_several_orders_on_one_day_are_one_stamp(): void
    {
        $user = $this->customer();

        // Three orders on each of 12, 13 and 14 Oct, and two completed today
        // (15 Oct): eleven completed orders, four days.
        foreach (['2026-10-12', '2026-10-13', '2026-10-14'] as $day) {
            foreach (['09:00:00', '12:00:00', '20:00:00'] as $time) {
                $this->completedOrderAt($user, $day . ' ' . $time);
            }
        }
        $this->complete($this->pendingOrder($user));
        $this->complete($this->pendingOrder($user));

        $this->assertSame(11, Order::where('user_id', $user->id)->where('status', 'completed')->count());
        $this->assertSame(4, FreeDesserts::countedDays($user, 1));
        $this->assertCount(0, $this->rewardsOf($user));
    }

    public function test_cancelled_rejected_and_open_orders_never_stamp(): void
    {
        $user = $this->customer();

        $this->completedDaysFrom($user, '2026-10-10', 5);

        // The worst case for the filter: orders on five OTHER days that even
        // carry a completed_at, but were cancelled, GCash-rejected, or are
        // still open. A straight UPDATE, so no model hook is involved.
        foreach ([
            ['2026-10-01', 'cancelled', 'pending'],
            ['2026-10-02', 'cancelled', 'rejected'],
            ['2026-10-03', 'pending', 'pending'],
            ['2026-10-04', 'preparing', 'pending'],
            ['2026-10-05', 'serving', 'paid'],
        ] as [$day, $status, $payment]) {
            $order = $this->completedOrderOn($user, $day);
            DB::table('orders')->where('id', $order->id)->update(['status' => $status, 'payment_status' => $payment]);
        }

        // A sixth real stamp today. Six, not eleven: nothing earned.
        $this->complete($this->pendingOrder($user));

        $this->assertSame(6, FreeDesserts::countedDays($user, 1));
        $this->assertCount(0, $this->rewardsOf($user));
    }

    public function test_the_manila_day_decides_the_stamp_not_the_utc_date(): void
    {
        $user = $this->customer();

        // 23:59 on 12 Oct and 00:01 on 13 Oct, Manila, are the SAME UTC date
        // (12 Oct 15:59 and 16:01) but two shop days: two stamps.
        $this->completedOrderAt($user, '2026-10-12 23:59:00');
        $this->completedOrderAt($user, '2026-10-13 00:01:00');
        // 00:01 and 23:59 on 14 Oct, Manila, are two UTC dates (13 Oct 16:01,
        // 14 Oct 15:59) but one shop day: one stamp.
        $this->completedOrderAt($user, '2026-10-14 00:01:00');
        $this->completedOrderAt($user, '2026-10-14 23:59:00');

        $this->assertSame('2026-10-12 15:59:00', DB::table('orders')->where('user_id', $user->id)->orderBy('id')->value('completed_at'));
        $this->assertSame(['2026-10-12', '2026-10-13', '2026-10-14'], FreeDesserts::stampDays($user, 1));
    }

    public function test_orders_before_the_start_date_never_stamp(): void
    {
        config(['loyalty.free_dessert_counts_from' => '2026-10-08']);
        $user = $this->customer();

        // Twenty days of history before the start date (18 Sep - 7 Oct),
        // ending at 23:59 Manila on 7 Oct; then 00:01 Manila on 8 Oct, the
        // first minute that counts.
        $this->completedDaysFrom($user, '2026-09-18', 19);
        $this->completedOrderAt($user, '2026-10-07 23:59:00');
        $this->completedOrderAt($user, '2026-10-08 00:01:00');
        $this->completedDaysFrom($user, '2026-10-10', 4);
        $this->complete($this->pendingOrder($user));

        $this->assertSame(
            ['2026-10-08', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-15'],
            FreeDesserts::stampDays($user, 1)
        );
        $this->assertCount(0, $this->rewardsOf($user), 'old history created a reward');

        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')
            ->assertOk()
            ->assertSee('6 / 7 stamps');
    }

    public function test_six_stamps_earn_nothing_and_the_seventh_earns_exactly_one(): void
    {
        $user = $this->customer();

        $this->completedDaysFrom($user, '2026-10-09', 5);
        $this->complete($this->pendingOrder($user));
        $this->assertSame(6, FreeDesserts::countedDays($user, 1));
        $this->assertCount(0, $this->rewardsOf($user));

        // The 7th stamp, tomorrow.
        $this->travelTo(CarbonImmutable::parse('2026-10-16 04:00:00', 'UTC'));
        $this->complete($this->pendingOrder($user));
        $this->assertCount(1, $this->rewardsOf($user));

        // The 8th, the day after: still one.
        $this->travelTo(CarbonImmutable::parse('2026-10-17 04:00:00', 'UTC'));
        $this->complete($this->pendingOrder($user));
        $this->assertCount(1, $this->rewardsOf($user));
    }

    public function test_more_orders_on_the_same_card_never_earn_a_second_reward(): void
    {
        $user = $this->customer();
        $order = $this->completeSevenDayStreak($user);

        // Another order the same day, and the next day (8 stamps).
        $this->complete($this->pendingOrder($user));
        $this->travelTo(CarbonImmutable::parse('2026-10-16 04:00:00', 'UTC'));
        $this->complete($this->pendingOrder($user));

        // And the completion hook re-run by hand (a retry).
        Loyalty::orderCompleted($order->fresh());

        $this->assertCount(1, $this->rewardsOf($user));
        $this->assertSame(['visits:1:1'], $this->dessertKeys($user));
    }

    public function test_a_second_full_card_earns_a_second_reward_with_a_different_code(): void
    {
        $user = $this->customer();

        // First card: 1-7 Oct. A gap on 8 Oct changes nothing. Second card: 9-15 Oct.
        $this->completedDaysFrom($user, '2026-10-01', 7);
        Loyalty::orderCompleted(Order::where('user_id', $user->id)->latest('id')->first());
        $this->assertSame(['visits:1:1'], $this->dessertKeys($user));

        $this->completeSevenDayStreak($user);

        $rewards = $this->rewardsOf($user);
        $this->assertCount(2, $rewards);
        $this->assertSame(['visits:1:1', 'visits:1:2'], $this->dessertKeys($user));
        $this->assertNotSame($rewards[0]->code, $rewards[1]->code);
    }

    public function test_several_due_at_once_are_all_granted_by_one_completion(): void
    {
        $user = $this->customer();

        // Twenty stamp days that never went through a completion that could
        // pay (20 Sep - 9 Oct), then one completion today: 21 stamps, 3 rewards.
        $this->completedDaysFrom($user, '2026-09-20', 20);
        $order = $this->pendingOrder($user);
        $this->complete($order);

        $this->assertSame(21, FreeDesserts::countedDays($user, 1));
        $rewards = $this->rewardsOf($user);
        $this->assertCount(3, $rewards);
        $this->assertSame(['available', 'available', 'available'], $rewards->pluck('status')->all());
        $this->assertSame(['visits:1:1', 'visits:1:2', 'visits:1:3'], $this->dessertKeys($user));
        $this->assertCount(3, $rewards->pluck('code')->unique());

        // One notification for the batch, not three.
        $note = Notification::where('user_id', $user->id)->where('type', 'free_dessert_earned')->sole();
        $this->assertSame('You earned 3 rewards!', $note->title);
        $this->assertStringContainsString('You collected 21 stamps at Main Branch (1 stamp per day you completed an order there).', $note->message);
        $this->assertStringContainsString('Vouchers & Rewards', $note->message);
        $this->assertStringContainsString('Use them by Nov 14, 2026.', $note->message);

        // Re-running the completion pays nothing more.
        $this->assertNotContains(LoyaltyAward::TYPE_FREE_DESSERT, Loyalty::orderCompleted($order->fresh()));
        $this->assertCount(3, $this->rewardsOf($user));
    }

    public function test_catching_up_grants_only_the_numbers_not_yet_held(): void
    {
        $user = $this->customer();

        // Reward #1 already granted (and spent); 14 stamps are due two.
        $first = $this->reward($user, 1);
        $first->forceFill(['status' => FreeDessertReward::STATUS_USED, 'used_at' => now()])->save();

        $this->completedDaysFrom($user, '2026-10-02', 13);
        $this->complete($this->pendingOrder($user));

        $this->assertSame(['visits:1:1', 'visits:1:2'], $this->dessertKeys($user));
        $this->assertSame(['used', 'available'], $this->rewardsOf($user)->pluck('status')->all());
    }

    public function test_a_duplicate_ledger_key_creates_no_reward(): void
    {
        // What the losing side of two simultaneous completions sees: the key
        // is already there. No reward row may be created without its ledger row.
        $user = $this->customer();

        $this->assertNotNull(FreeDesserts::grant($user, 1, 1));
        $this->assertNull(FreeDesserts::grant($user, 1, 1));

        $this->assertCount(1, $this->rewardsOf($user));
    }

    public function test_stamps_at_other_branches_never_fill_this_branchs_card(): void
    {
        // Seven days in a row, spread over three branches: no branch has seven
        // stamps of its own (stamp cards are per branch since Oct 2026), so
        // nothing is earned anywhere. Per-branch cards in full:
        // StreakRewardsPerBranchTest.
        $user = $this->customer();
        $this->completeSevenDayStreak($user, [1, 2, 3]);

        $this->assertCount(0, $this->rewardsOf($user));
        $this->assertSame(3, FreeDesserts::countedDays($user, 1));
        $this->assertSame(2, FreeDesserts::countedDays($user, 2));
        $this->assertSame(2, FreeDesserts::countedDays($user, 3));
    }

    public function test_unverified_accounts_and_guests_get_nothing(): void
    {
        $unverified = $this->customer(false);
        $this->completeSevenDayStreak($unverified);
        $this->assertCount(0, $this->rewardsOf($unverified));
        $this->assertSame([], $this->dessertKeys($unverified));

        // A guest order has no account: completing it creates no reward anywhere.
        $before = FreeDessertReward::count();
        $guest = Order::create([
            'order_number' => 'FDG-' . strtoupper(Str::random(8)), 'user_id' => null, 'branch_id' => 1,
            'type' => 'dine_in', 'table_number' => '9', 'status' => 'pending',
            'payment_method' => 'cash', 'payment_status' => 'pending', 'subtotal' => 50, 'total' => 50,
        ]);
        $this->complete($guest);
        $this->assertSame($before, FreeDessertReward::count());
    }

    public function test_the_feature_switched_off_earns_nothing(): void
    {
        config(['loyalty.free_dessert_visit_days' => 0]);

        $user = $this->customer();
        $this->completeSevenDayStreak($user);

        $this->assertCount(0, $this->rewardsOf($user));
        $this->assertSame(['enabled' => false], FreeDesserts::summaryFor($user, 1));
    }

    public function test_valid_days_zero_means_it_never_expires(): void
    {
        config(['loyalty.free_dessert_valid_days' => 0]);

        $this->assertNull($this->reward($this->customer())->expires_at);
    }

    // ══════════ X comes from the settings (config when unset) ══════════

    public function test_x_of_3_drives_the_rule_and_every_line_of_copy(): void
    {
        config(['loyalty.free_dessert_visit_days' => 3]);
        $user = $this->customer();
        $what = 'Free ' . $this->defaultRewardItem->name;

        // Two scattered days and one today: 3 stamps = one reward.
        $this->completedOrderOn($user, '2026-10-03');
        $this->completedOrderOn($user, '2026-10-09');
        $this->complete($this->pendingOrder($user));

        $this->assertCount(1, $this->rewardsOf($user));
        $this->assertStringContainsString(
            'You collected 3 stamps',
            Notification::where('user_id', $user->id)->where('type', 'free_dessert_earned')->sole()->message
        );

        $this->assertStringContainsString('a reward for every 3 stamps (1 stamp per day you completed an order).', (string) Loyalty::guestTeaser());

        $account = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')->assertOk()->getContent();
        $this->assertStringContainsString('1 reward ready to use', $account);
        $this->assertStringContainsString('Earn 1 stamp per day when you complete at least one order at Main Branch. Collect 3 stamps to receive a ' . $what . '. The days do not need to be consecutive.', $account);
        $this->assertStringContainsString('0 / 3 stamps', $account);
        $this->assertStringContainsString('Collect 3 stamps to receive a ' . $what . '.', $account);
        $this->assertSame(3, substr_count($account, 'data-stamp="'));
        $this->assertStringNotContainsString('7 stamps', $account);

        $vouchers = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/vouchers')->assertOk()->getContent();
        $this->assertStringContainsString('Collect 3 stamps for a reward', $vouchers);
        $this->assertStringContainsString('0 / 3 stamps', $vouchers);

        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/help')
            ->assertOk()
            ->assertSee('At Main Branch, collect 3 stamps and a reward (' . $what . ') is added to your account with its own code.');

        // The owner's Days needed field states the same rule.
        $html = $this->actingAs($this->staffUser('admin', null), 'admin')->get(route('admin.streak-rewards'))->assertOk()->getContent();
        $start = strpos($html, 'id="srDays"');
        $this->assertNotFalse($start);
        $daysInput = substr($html, $start, strpos($html, '>', $start) - $start);
        $this->assertStringContainsString('value="3"', $daysInput);
    }

    public function test_x_of_10_drives_the_rule_and_every_line_of_copy(): void
    {
        config(['loyalty.free_dessert_visit_days' => 10]);
        $user = $this->customer();

        // Seven stamps: a reward at 7 under the shipped value, nothing at 10.
        $order = $this->completeSevenDayStreak($user);
        $this->assertCount(0, $this->rewardsOf($user));

        $this->assertStringContainsString('a reward for every 10 stamps', (string) Loyalty::guestTeaser());

        $account = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')->assertOk()->getContent();
        $this->assertStringContainsString('7 / 10 stamps', $account);
        $this->assertStringContainsString('3 more stamps to your next reward.', $account);
        $this->assertStringContainsString('Collect 10 stamps to receive', $account);
        $this->assertSame(7, substr_count($account, 'data-stamp="filled"'));
        $this->assertSame(3, substr_count($account, 'data-stamp="empty"'));
        // 8-12 stamps sit in two rows.
        $this->assertStringContainsString('grid-template-columns: repeat(5, minmax(0, 1fr));', $account);

        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/vouchers')
            ->assertOk()
            ->assertSee('7 / 10 stamps');

        // Three more stamp days: the 10th earns it.
        $this->completedDaysFrom($user, '2026-10-01', 3);
        Loyalty::orderCompleted($order->fresh());
        $this->assertSame(['visits:1:1'], $this->dessertKeys($user));
    }

    public function test_more_than_12_stamps_show_the_progress_bar_instead_of_stamps(): void
    {
        config(['loyalty.free_dessert_visit_days' => 13]);
        $user = $this->customer();
        $this->completedDaysFrom($user, '2026-10-01', 4);

        $account = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="free-dessert-stamp-bar"', $account);
        $this->assertStringNotContainsString('data-testid="free-dessert-stamp-grid"', $account);
        $this->assertStringContainsString('4 / 13 stamps', $account);
    }

    // ══════════ spending, with the code ══════════

    public function test_redeeming_adds_one_free_line_and_consumes_the_reward(): void
    {
        $user = $this->customer();
        $paid = $this->item($this->inventory(10));
        $dessert = $this->dessert();
        $reward = $this->reward($user, 1, $dessert);

        [$response, $order] = $this->place($user, $this->cart($paid, 2), ['reward_code' => $reward->displayCode()]);

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);

        $this->assertEquals(200.00, (float) $order->subtotal, 'the reward changed the subtotal');
        $this->assertEquals(200.00, (float) $order->total);
        $this->assertSame($reward->code, $order->reward_code);

        $free = $order->items()->where('is_free_dessert', true)->get();
        $this->assertCount(1, $free);
        $this->assertSame($dessert->id, $free[0]->menu_item_id);
        $this->assertSame(1, (int) $free[0]->quantity);
        $this->assertEquals(0.0, (float) $free[0]->item_price);
        $this->assertEquals(0.0, (float) $free[0]->subtotal);
        $this->assertSame('LOYALTY REWARD: ' . $dessert->name, $free[0]->displayName());

        $reward->refresh();
        $this->assertSame('used', $reward->status);
        $this->assertSame($order->id, $reward->order_id);
        $this->assertSame($free[0]->id, $reward->order_item_id);
        $this->assertSame(1, $reward->used_branch_id);
        $this->assertNotNull($reward->used_at);
    }

    public function test_the_code_is_matched_without_case_spaces_or_dashes(): void
    {
        $user = $this->customer();
        $reward = $this->reward($user, 1, $this->dessert());
        $typed = strtolower(substr($reward->code, 0, 3) . ' ' . substr($reward->code, 3, 4) . ' ' . substr($reward->code, 7));

        [$response, $order] = $this->place($user, $this->cart($this->item($this->inventory(10))), ['reward_code' => $typed]);

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);
        $this->assertSame('used', $reward->fresh()->status);
    }

    public function test_dine_in_with_gcash_works_too(): void
    {
        $user = $this->customer();
        $paid = $this->item($this->inventory(10));
        $reward = $this->reward($user, 1, $this->dessert());

        [$response, $order] = $this->place($user, $this->cart($paid), [
            'reward_code'    => $reward->displayCode(),
            'payment_method' => 'gcash',
        ], 1, 'dine_in');

        $this->assertNotNull($order, 'dine-in GCash order with a reward was refused: ' . json_encode(session('errors')?->all()));
        $response->assertRedirect(route('customer.gcash-payment', $order->id));
        $this->assertEquals(100.00, (float) $order->total);
        $this->assertSame(1, $order->items()->where('is_free_dessert', true)->count());
    }

    public function test_a_reward_code_can_be_spent_only_once(): void
    {
        $user = $this->customer();
        $reward = $this->reward($user, 1, $this->dessert());

        [, $first] = $this->place($user, $this->cart($this->item($this->inventory(10))), ['reward_code' => $reward->displayCode()]);
        $this->assertNotNull($first);

        // Finish the first so the one-active-order rule is not what refuses the second.
        $this->complete($first);

        [$response, $second] = $this->place($user, $this->cart($this->item($this->inventory(10))), ['reward_code' => $reward->displayCode()]);

        $this->assertNull($second, 'a used code bought a second free item');
        $response->assertSessionHasErrors('reward_code');
        $this->assertStringContainsString('already been used', session('errors')->first('reward_code'));
    }

    public function test_an_expired_reward_cannot_be_spent_and_is_marked_expired(): void
    {
        $user = $this->customer();
        $reward = $this->reward($user, 1, $this->dessert());

        $this->travelTo(CarbonImmutable::parse('2026-11-14 23:59:59', 'Asia/Manila')->utc()->addSecond());

        [$response, $order] = $this->place($user, $this->cart($this->item($this->inventory(10))), ['reward_code' => $reward->displayCode()]);

        $this->assertNull($order);
        $response->assertSessionHasErrors('reward_code');
        $this->assertStringContainsString('has expired', session('errors')->first('reward_code'));

        $this->assertSame(1, FreeDesserts::expireStale($user));
        $this->assertSame('expired', $reward->fresh()->status);
    }

    public function test_the_last_second_before_expiry_still_counts(): void
    {
        $user = $this->customer();
        $this->reward($user);

        $this->travelTo(CarbonImmutable::parse('2026-11-14 23:59:58', 'Asia/Manila')->utc());

        $this->assertNotNull(FreeDesserts::usableFor($user->fresh()));
    }

    public function test_another_customers_code_answers_like_a_code_that_does_not_exist(): void
    {
        $owner = $this->customer();
        $ownerReward = $this->reward($owner, 1, $this->dessert());
        $stranger = $this->customer();

        foreach (['reward_code', 'voucher_code_confirmed'] as $field) {
            [$response, $order] = $this->place($stranger, $this->cart($this->item($this->inventory(10))), [
                $field => $ownerReward->displayCode(),
            ]);

            $this->assertNull($order, 'a stranger spent the owner\'s code via ' . $field);
            $response->assertSessionHasErrors($field);
            $this->assertSame('Invalid voucher code.', session('errors')->first($field));
        }

        // A code that exists nowhere gets the very same answer.
        [$response] = $this->place($stranger, $this->cart($this->item($this->inventory(10))), ['reward_code' => 'RWD-2345-6789']);
        $this->assertSame('Invalid voucher code.', session('errors')->first('reward_code'));

        $this->assertSame('available', $ownerReward->fresh()->status);
    }

    public function test_guests_and_unverified_accounts_cannot_redeem(): void
    {
        $dessert = $this->dessert();
        $paid = $this->item($this->inventory(10));
        $owner = $this->customer();
        $reward = $this->reward($owner, 1, $dessert);

        // Guest, holding a real code.
        $before = (int) Order::max('id');
        $this->withSession(['cart' => $this->cart($paid), 'branch_id' => 1, 'order_type' => 'dine_in', 'table_number' => '7'])
            ->post('/customer/place-order', [
                'order_type' => 'dine_in', 'table_number' => '7', 'payment_method' => 'cash',
                'items' => [['menu_item_id' => $paid->id, 'quantity' => 1]],
                'reward_code' => $reward->displayCode(),
            ])->assertSessionHasErrors('reward_code');
        $this->assertSame(0, Order::where('id', '>', $before)->count());
        $this->assertSame('available', $reward->fresh()->status);

        // An unverified account holding a reward row (e.g. verification revoked).
        $unverified = $this->customer(false);
        $own = $this->reward($unverified, 1, $dessert);
        [$response, $order] = $this->place($unverified, $this->cart($paid), ['reward_code' => $own->displayCode()]);
        $this->assertNull($order);
        $response->assertSessionHasErrors('reward_code');
    }

    public function test_the_item_comes_from_the_reward_never_from_the_request(): void
    {
        $user = $this->customer();
        $dessert = $this->dessert();
        $reward = $this->reward($user, 1, $dessert);
        $steak = $this->item($this->inventory(10), ['name' => 'FD Steak', 'price' => 500]);

        [, $order] = $this->place($user, $this->cart($this->item($this->inventory(10))), [
            'reward_code'               => $reward->displayCode(),
            'free_dessert_menu_item_id' => $steak->id,
            'reward_menu_item_id'       => $steak->id,
        ]);

        $this->assertNotNull($order);
        $this->assertSame($dessert->id, $order->items()->where('is_free_dessert', true)->sole()->menu_item_id);
    }

    public function test_an_unavailable_out_of_stock_archived_or_other_branch_item_is_refused(): void
    {
        $user = $this->customer();

        $switchedOff = $this->dessert(10, 1);
        $noStock = $this->dessert(0);
        $archived = $this->dessert();
        // A branch's reward must be its own or an all-branches item (Oct 2026
        // per-branch rewards), so "another branch's item" now arises only when
        // the item is moved to another branch after the reward was earned.
        $otherBranch = $this->dessert(10, 1);

        $rewards = [];
        foreach ([$switchedOff, $noStock, $archived, $otherBranch] as $i => $item) {
            $rewards[] = [$this->reward($user, $i + 1, $item), $item];
        }

        $switchedOff->forceFill(['is_available' => false])->save();
        $archived->archived_at = now();
        $archived->save();
        $otherBranch->forceFill(['branch_id' => 2])->save();

        foreach ($rewards as [$reward, $item]) {
            [$response, $order] = $this->place($user, $this->cart($this->item($this->inventory(10))), ['reward_code' => $reward->displayCode()]);

            $this->assertNull($order, 'refused item ' . $item->name . ' was given free');
            $response->assertSessionHasErrors();
            $this->assertSame('available', $reward->fresh()->status);
        }
    }

    public function test_the_client_cannot_set_the_price_quantity_or_swap_the_line(): void
    {
        $user = $this->customer();
        $paid = $this->item($this->inventory(10));
        $dessert = $this->dessert();
        $reward = $this->reward($user, 1, $dessert);

        [, $order] = $this->place($user, $this->cart($paid), [
            'reward_code'           => $reward->displayCode(),
            'free_dessert_price'    => 150,
            'free_dessert_quantity' => 5,
            'discount_amount'       => 999,
            'items'                 => [
                ['menu_item_id' => $paid->id, 'quantity' => 1, 'price' => 0],
                ['menu_item_id' => $dessert->id, 'quantity' => 9, 'price' => 0],
            ],
        ]);

        $this->assertNotNull($order);
        $this->assertSame(2, $order->items()->count(), 'posted items[] reached the order');
        $free = $order->items()->where('is_free_dessert', true)->sole();
        $this->assertSame(1, (int) $free->quantity);
        $this->assertEquals(0.0, (float) $free->item_price);
        $this->assertEquals(100.00, (float) $order->total);
        $this->assertEquals(0.0, (float) $order->discount_amount);
    }

    public function test_a_session_cart_line_for_the_reward_item_is_charged_normally(): void
    {
        // Putting the reward item in the cart is buying it: only the code
        // makes a line free, and only one.
        $user = $this->customer();
        $dessert = $this->dessert();
        $reward = $this->reward($user, 1, $dessert);

        [, $order] = $this->place($user, $this->cart($dessert, 2));

        $this->assertEquals(300.00, (float) $order->total);
        $this->assertSame(0, $order->items()->where('is_free_dessert', true)->count());
        $this->assertSame('available', $reward->fresh()->status);
    }

    // ══════════ vouchers, PWD/Senior, stock ══════════

    public function test_the_reward_item_does_not_count_toward_a_voucher_minimum(): void
    {
        $user = $this->customer();
        $reward = $this->reward($user, 1, $this->dessert());
        $voucher = Voucher::create([
            'code' => 'FDMIN' . strtoupper(substr(uniqid(), -6)), 'description' => 'reward minimum test',
            'discount_type' => 'fixed', 'discount_value' => 20, 'max_uses' => 100, 'used_count' => 0,
            'minimum_order' => 200, 'points_required' => 0, 'is_active' => true,
        ]);

        // Paid 100 + a 150 cake free: still below the 200 minimum.
        [$response, $order] = $this->place($user, $this->cart($this->item($this->inventory(10))), [
            'reward_code'            => $reward->displayCode(),
            'voucher_code_confirmed' => $voucher->code,
        ]);

        $this->assertNull($order);
        $response->assertSessionHasErrors('voucher_code_confirmed');
    }

    public function test_a_percent_voucher_beside_an_item_reward_is_computed_on_paid_items_only(): void
    {
        $user = $this->customer();
        $reward = $this->reward($user, 1, $this->dessert());
        $voucher = Voucher::create([
            'code' => 'FDPCT' . strtoupper(substr(uniqid(), -6)), 'description' => 'reward percent test',
            'discount_type' => 'percent', 'discount_value' => 10, 'max_uses' => 100, 'used_count' => 0,
            'minimum_order' => 0, 'points_required' => 0, 'is_active' => true,
        ]);

        [, $order] = $this->place($user, $this->cart($this->item($this->inventory(10)), 2), [
            'reward_code'            => $reward->displayCode(),
            'voucher_code_confirmed' => $voucher->code,
        ]);

        $this->assertNotNull($order);
        $this->assertSame('voucher', $order->discount_type);
        $this->assertEquals(20.00, (float) $order->discount_amount, '10% of 200 paid, not of 350');
        $this->assertEquals(180.00, (float) $order->total);
        $this->assertSame(1, $voucher->fresh()->used_count);
        $this->assertSame('used', $reward->fresh()->status);
        $this->assertSame($reward->code, $order->reward_code);
    }

    public function test_pwd_senior_discount_beside_an_item_reward_is_computed_on_paid_items_only(): void
    {
        $user = $this->customer();
        $reward = $this->reward($user, 1, $this->dessert());

        [, $order] = $this->place($user, $this->cart($this->item($this->inventory(10)), 2), [
            'reward_code'               => $reward->displayCode(),
            'discount_type'             => 'senior',
            'discount_applied'          => '1',
            'discount_beneficiary_name' => 'Juan Dela Cruz',
            'discount_beneficiary_id'   => 'SC-12345',
        ]);

        $this->assertNotNull($order);
        $this->assertSame('senior', $order->discount_type);
        $this->assertEquals(Order::pwdSeniorDiscountFor(200), (float) $order->discount_amount);
        $this->assertEquals(200 - Order::pwdSeniorDiscountFor(200), (float) $order->total);
        $this->assertSame(1, $order->items()->where('is_free_dessert', true)->count());
    }

    public function test_the_reward_item_is_deducted_from_inventory_at_completion(): void
    {
        $user = $this->customer();
        $paidInv = $this->inventory(10);
        $dessertInv = $this->inventory(5);
        $dessert = $this->item($dessertInv, ['price' => 150]);
        $reward = $this->reward($user, 1, $dessert);

        [, $order] = $this->place($user, $this->cart($this->item($paidInv)), ['reward_code' => $reward->displayCode()]);
        $this->assertEquals(5.0, (float) $dessertInv->fresh()->quantity, 'stock moved before completion');

        $this->complete($order);

        $this->assertEquals(4.0, (float) $dessertInv->fresh()->quantity);
        $this->assertEquals(9.0, (float) $paidInv->fresh()->quantity);
        $this->assertSame('used', FreeDessertReward::where('order_id', $order->id)->value('status'), 'completion must keep the reward used');
    }

    public function test_the_reward_item_needs_its_own_serving_of_stock(): void
    {
        // One cake on the shelf: buying one AND taking one free needs two.
        $user = $this->customer();
        $dessert = $this->dessert(1);
        $reward = $this->reward($user, 1, $dessert);

        [$response, $order] = $this->place($user, $this->cart($dessert, 1), ['reward_code' => $reward->displayCode()]);

        $this->assertNull($order);
        $response->assertSessionHasErrors('items');
        $this->assertSame('available', $reward->fresh()->status);
    }

    public function test_an_open_order_holding_the_last_serving_blocks_the_free_one(): void
    {
        $user = $this->customer();
        $dessert = $this->dessert(1);
        $reward = $this->reward($user, 1, $dessert);

        $holder = $this->pendingOrder($this->customer());
        OrderItem::create([
            'order_id' => $holder->id, 'menu_item_id' => $dessert->id, 'item_name' => $dessert->name,
            'quantity' => 1, 'item_price' => 150, 'subtotal' => 150,
        ]);

        [$response, $order] = $this->place($user, $this->cart($this->item($this->inventory(10))), ['reward_code' => $reward->displayCode()]);

        $this->assertNull($order);
        $response->assertSessionHasErrors('items');
        $this->assertNull(FreeDesserts::itemLineFor($reward, 1, app(\App\Services\InventoryDeductionService::class)->committedQuantities()));
    }

    // ══════════ cancel / reject / complete ══════════

    private function orderWithReward(User $user, array $extra = []): Order
    {
        $reward = $this->reward($user, 1, $this->dessert());
        [, $order] = $this->place($user, $this->cart($this->item($this->inventory(10))), array_merge([
            'reward_code' => $reward->displayCode(),
        ], $extra));
        $this->assertNotNull($order);
        $this->assertSame('used', FreeDessertReward::where('order_id', $order->id)->value('status'));

        return $order;
    }

    private function rewardFor(User $user): FreeDessertReward
    {
        return FreeDessertReward::where('user_id', $user->id)->sole();
    }

    public function test_customer_cancel_returns_the_reward_with_the_same_code(): void
    {
        $user = $this->customer();
        $order = $this->orderWithReward($user);
        $code = $this->rewardFor($user)->code;

        $this->actingAs($user, 'customer')->post(route('customer.orders.cancel', $order->id))->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
        $reward = $this->rewardFor($user);
        $this->assertSame('available', $reward->status);
        $this->assertSame($code, $reward->code, 'the code changed on the way back');
        $this->assertNull($reward->order_id);
        $this->assertNull($reward->order_item_id);
        $this->assertNull($reward->used_branch_id);
        $this->assertNull($reward->used_at);
        // The cancelled order keeps the code it once carried, as history.
        $this->assertSame($code, $order->fresh()->reward_code);

        // ...and the same code can be spent again.
        [, $again] = $this->place($user, $this->cart($this->item($this->inventory(10))), ['reward_code' => RewardCode::display($code)]);
        $this->assertNotNull($again);
    }

    public function test_staff_cancel_returns_the_reward(): void
    {
        $user = $this->customer();
        $order = $this->orderWithReward($user);

        $this->actingAs($this->staffUser('admin', null), 'admin')->put(route('admin.orders.cancel', $order->id))->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('available', $this->rewardFor($user)->status);
    }

    public function test_a_gcash_rejection_returns_the_reward(): void
    {
        $user = $this->customer();
        $order = $this->orderWithReward($user, ['payment_method' => 'gcash']);

        $this->actingAs($user, 'customer')->post(route('customer.gcash-payment.paid', $order->id));
        $this->assertSame('awaiting_verification', $order->fresh()->payment_status);

        $this->actingAs($this->staffUser('admin', null), 'admin')->put(route('admin.orders.payment.reject', $order->id))->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('available', $this->rewardFor($user)->status);
    }

    public function test_returning_is_idempotent(): void
    {
        $user = $this->customer();
        $order = $this->orderWithReward($user);

        $this->actingAs($this->staffUser('admin', null), 'admin')->put(route('admin.orders.cancel', $order->id));

        $this->assertFalse(FreeDesserts::releaseForOrder($order->fresh()));
        $this->assertFalse(FreeDesserts::releaseForOrder($order->fresh()));
        $this->assertSame(1, FreeDessertReward::where('user_id', $user->id)->count());
        $this->assertSame('available', $this->rewardFor($user)->status);
    }

    public function test_a_returned_reward_past_its_expiry_comes_back_expired(): void
    {
        $user = $this->customer();
        $order = $this->orderWithReward($user);

        $this->travelTo(CarbonImmutable::parse('2026-11-20 04:00:00', 'UTC'));
        $this->actingAs($this->staffUser('admin', null), 'admin')->put(route('admin.orders.cancel', $order->id));

        $this->assertSame('expired', $this->rewardFor($user)->status);
    }

    public function test_a_pending_order_is_never_released_and_a_completed_one_stays_used(): void
    {
        $user = $this->customer();
        $order = $this->orderWithReward($user);

        $this->assertFalse(FreeDesserts::releaseForOrder($order), 'a live order gave its reward back');

        $this->complete($order);
        $this->assertFalse(FreeDesserts::releaseForOrder($order->fresh()));
        $this->assertSame('used', $this->rewardFor($user)->status);

        // A stale staff tab trying to cancel the completed order is refused, and the reward stays used.
        $this->actingAs($this->staffUser('admin', null), 'admin')->put(route('admin.orders.cancel', $order->id));
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('used', $this->rewardFor($user)->status);
    }

    // ══════════ branches ══════════

    public function test_a_newly_created_branch_gives_its_own_reward_item_with_no_code_change(): void
    {
        $branch = Branch::create([
            'name' => 'FD New Branch ' . uniqid(), 'code' => 'FD' . strtoupper(substr(uniqid(), -5)),
            'address' => 'Test', 'is_active' => true, 'is_main_branch' => false,
        ]);

        // The new branch sets its own reward, an item of its own, and the
        // reward is earned there.
        $user = $this->customer();
        $here = $this->dessert(5, $branch->id);
        $this->chooseBranchReward($branch->id, $here, 7);
        $reward = FreeDesserts::grant($user, $branch->id, 1);
        $this->assertNotNull($reward);
        $this->assertSame($branch->id, $reward->branch_id);

        $this->assertNotNull(FreeDesserts::itemLineFor($reward, $branch->id));
        $this->assertNull(FreeDesserts::itemLineFor($reward, 1), 'another branch\'s item was offered at Main');
        $this->assertSame('This reward can only be used at ' . $branch->name . '.', $reward->branchErrorFor(1));

        [, $order] = $this->place($user, $this->cart($this->item($this->inventory(10, $branch->id))), ['reward_code' => $reward->displayCode()], $branch->id);

        $this->assertNotNull($order);
        $this->assertSame($branch->id, (int) $order->branch_id);
        $this->assertSame('used', $reward->fresh()->status);
        $this->assertSame($branch->id, $reward->fresh()->used_branch_id);
    }

    // ══════════ pages ══════════

    public function test_the_cart_has_no_dessert_chooser_any_more(): void
    {
        $user = $this->customer();
        $this->reward($user, 1, $this->dessert(5, 1, ['name' => 'FD Mango Cake']));

        $html = $this->actingAs($user, 'customer')
            ->withSession(['cart' => $this->cart($this->item($this->inventory(10))), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/cart')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('free_dessert_menu_item_id', $html);
        $this->assertStringNotContainsString('You have a free dessert', $html);
        $this->assertStringNotContainsString('freeDessertOffer', $html);
        // The code box carries reward codes now.
        $this->assertStringContainsString('id="reward_code_hidden"', $html);
        $this->assertStringContainsString('placeholder="Voucher or reward code"', $html);
    }

    public function test_the_guest_teaser_and_benefits_card_mention_the_reward_from_the_settings(): void
    {
        $this->assertSame(
            'Create a free account for 10 welcome points, +3 points on every completed order, streak bonuses at 3 days and a reward for every 7 stamps (1 stamp per day you completed an order).',
            Loyalty::guestTeaser()
        );

        $user = $this->customer();
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')
            ->assertOk()
            ->assertSee('data-testid="benefit-free-dessert"', false)
            ->assertSee('Collect 7 stamps to receive a Free ' . $this->defaultRewardItem->name . '. The days do not need to be consecutive.')
            ->assertDontSee('in a row. Once per streak.');

        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/help')
            ->assertOk()
            ->assertSee('data-testid="help-free-dessert"', false)
            ->assertSee('1 stamp per day you completed an order. Each branch has its own stamp card: only orders at that branch count toward it. Any days count, not only days in a row');

        // Switched off: no card, no bar, no teaser line, no Help topic.
        config(['loyalty.free_dessert_visit_days' => 0]);
        $this->assertStringNotContainsString('stamps', (string) Loyalty::guestTeaser());
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')
            ->assertOk()
            ->assertDontSee('data-testid="free-dessert-card"', false)
            ->assertDontSee('data-testid="benefit-free-dessert"', false);
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/vouchers')
            ->assertOk()
            ->assertDontSee('data-testid="free-dessert-card"', false);
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/help')
            ->assertOk()
            ->assertDontSee('data-testid="help-free-dessert"', false);
    }

    public function test_the_account_page_shows_the_bonus_card_then_the_stamp_card_beside_the_calendar(): void
    {
        $user = $this->customer();

        // Three scattered days: no streak longer than one day, three stamps.
        foreach (['2026-10-02', '2026-10-08', '2026-10-13'] as $day) {
            $this->completedOrderOn($user, $day);
        }

        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')->assertOk()->getContent();

        // Calendar, then the bonus-points card, then the stamp card, then benefits.
        $calendar = strpos($html, 'id="streak"');
        $bar = strpos($html, 'data-testid="bonus-banner"');
        $card = strpos($html, 'data-testid="free-dessert-card"');
        $benefits = strpos($html, 'id="benefits"');
        $this->assertNotFalse($calendar);
        $this->assertTrue($calendar < $bar && $bar < $card && $card < $benefits, 'cards are out of order');

        // The Reward Challenge card ("Day D of X") is gone from this page.
        $this->assertStringNotContainsString('data-testid="free-dessert-challenge"', $html);
        $this->assertStringNotContainsString('Reward Challenge', $html);
        $this->assertStringNotContainsString('Day 3 of 7', $html);

        // The stamp card: 3 of 7 stamped, the copy that names the rule and the reward.
        $this->assertStringContainsString('3 / 7 stamps', $html);
        $this->assertSame(3, substr_count($html, 'data-stamp="filled"'));
        $this->assertSame(4, substr_count($html, 'data-stamp="empty"'));
        $this->assertStringContainsString('Earn 1 stamp per day when you complete at least one order at Main Branch. Collect 7 stamps to receive a Free ' . $this->defaultRewardItem->name . '. The days do not need to be consecutive.', $html);
        $this->assertStringContainsString('4 more stamps to your next reward.', $html);

        // The old consecutive-days wording is gone.
        $this->assertStringNotContainsString('in a row to go', $html);
        $this->assertStringNotContainsString('days in a row. Once per streak', $html);
    }

    public function test_a_new_customer_sees_0_stamps_and_how_to_get_the_first_one(): void
    {
        $html = $this->actingAs($this->customer(), 'customer')->withSession(['branch_id' => 1])->get('/customer/account')->assertOk()->getContent();

        $this->assertStringContainsString('0 / 7 stamps', $html);
        $this->assertStringContainsString('Complete an order to collect your first stamp.', $html);
        $this->assertSame(7, substr_count($html, 'data-stamp="empty"'));
    }

    public function test_a_ready_reward_shows_on_the_stamp_card_and_links_to_the_code(): void
    {
        $user = $this->customer();

        // A full card: 7 stamps, earned by the completion today.
        $this->completeSevenDayStreak($user);

        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="free-dessert-available"', $html);
        $this->assertStringNotContainsString('data-testid="free-dessert-ready"', $html);
        $this->assertStringContainsString(route('customer.vouchers') . '#rewards', $html);
        // The full card was handed in: the stamp card starts a new one.
        $this->assertStringContainsString('0 / 7 stamps', $html);
        $this->assertStringContainsString('1 reward ready to use', $html);

        // Two waiting at once say so.
        $this->reward($user, 2);
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')
            ->assertOk()
            ->assertSee('2 rewards ready to use');

        // Once spent (and a full card not yet reached), the notice is gone.
        foreach ($this->rewardsOf($user) as $reward) {
            $reward->forceFill(['status' => FreeDessertReward::STATUS_USED, 'used_at' => now()])->save();
        }
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')
            ->assertOk()
            ->assertDontSee('data-testid="free-dessert-available"', false)
            ->assertSee('0 / 7 stamps');
    }

    public function test_vouchers_and_rewards_lists_each_reward_with_its_code_status_and_history(): void
    {
        $user = $this->customer();

        foreach (['2026-10-05', '2026-10-12', '2026-10-14'] as $day) {
            $this->completedOrderOn($user, $day);
        }

        // An available reward, and one spent on an order that is then completed.
        $available = $this->reward($user, 2);
        $order = $this->orderWithReward($user);
        $this->complete($order);
        $used = FreeDessertReward::where('order_id', $order->id)->sole();

        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/vouchers')->assertOk()->getContent();

        // Three past days plus the order completed today: 4 stamps.
        $this->assertStringContainsString('data-testid="free-dessert-card"', $html);
        $this->assertStringContainsString('4 / 7 stamps', $html);
        $this->assertStringContainsString('1 reward ready to use', $html);

        // The ready one first, with its code, Copy and Use now.
        $this->assertStringContainsString($available->displayCode(), $html);
        $this->assertStringContainsString('Use by Nov 14, 2026.', $html);
        $this->assertStringContainsString(route('customer.rewards.use', $available->id), $html);
        $this->assertLessThan(strpos($html, $used->displayCode()), strpos($html, $available->displayCode()));

        // The used one, with its order, and no Use now.
        $this->assertStringContainsString('order ' . $order->order_number, $html);
        $this->assertStringNotContainsString(route('customer.rewards.use', $used->id), $html);

        // The Account page shows the card and points at the codes instead.
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get('/customer/account')
            ->assertOk()
            ->assertSee('1 reward ready to use')
            ->assertSee('See code')
            ->assertDontSee($available->displayCode());

    }

    public function test_staff_see_the_loyalty_reward_label_on_the_board_receipts_completed_list_print_and_csv(): void
    {
        $this->assertSame('LOYALTY REWARD', OrderItem::REWARD_LABEL);

        $user = $this->customer();
        $order = $this->orderWithReward($user);
        $line = $order->items()->where('is_free_dessert', true)->sole();
        $label = 'LOYALTY REWARD: ' . $line->item_name;
        $admin = $this->staffUser('admin', null);

        $pages = [];

        $pages['staff board'] = $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => 1])
            ->get(route('admin.home'))->assertOk()->getContent();

        $pages['staff receipt'] = $this->actingAs($admin, 'admin')->get(route('admin.receipt', $order->id))
            ->assertOk()->getContent();

        $this->complete($order);

        $pages['customer receipt'] = $this->actingAs($user, 'customer')->get(route('customer.receipt', $order->id))
            ->assertOk()->getContent();

        $filters = ['date_from' => '2026-10-15'];

        $pages['completed orders'] = $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => 1])
            ->get(route('admin.completed-orders', $filters))->assertOk()->getContent();

        $pages['completed orders print'] = $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => 1])
            ->get(route('admin.completed-orders.print', $filters))->assertOk()->getContent();

        $pages['completed orders CSV'] = $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => 1])
            ->get(route('admin.completed-orders.export', $filters))->assertOk()->streamedContent();

        foreach ($pages as $where => $content) {
            $this->assertStringContainsString($label, $content, $where . ' does not show the label');
            $this->assertStringNotContainsString('FREE DESSERT', $content, $where . ' still says FREE DESSERT');
        }

        // The CSV also names the code the order spent.
        $this->assertStringContainsString(RewardCode::display($order->fresh()->reward_code), $pages['completed orders CSV']);
    }

    // ══════════ the separate reward rating ══════════

    public function test_the_reward_item_is_rated_separately_from_the_meal(): void
    {
        $user = $this->customer();
        $order = $this->orderWithReward($user);
        $this->complete($order);
        $bonusBefore = (int) $user->fresh()->bonus_points;
        $awardsBefore = LoyaltyAward::where('user_id', $user->id)->count();

        $this->actingAs($user, 'customer')->post(route('customer.orders.rating', $order->id), ['rating' => 2])->assertRedirect();
        $this->actingAs($user, 'customer')->post(route('customer.orders.dessert-rating', $order->id), [
            'rating'  => 5,
            'comment' => 'The mango cake was lovely.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $dessertRating = FreeDessertRating::where('order_id', $order->id)->sole();
        $this->assertSame(5, $dessertRating->rating);
        $this->assertSame('The mango cake was lovely.', $dessertRating->comment);
        $this->assertSame($order->items()->where('is_free_dessert', true)->value('id'), $dessertRating->order_item_id);
        $this->assertSame(2, OrderRating::where('order_id', $order->id)->value('rating'), 'the meal rating was changed');

        // Never any reward for rating.
        $this->assertSame($bonusBefore, (int) $user->fresh()->bonus_points);
        $this->assertSame($awardsBefore, LoyaltyAward::where('user_id', $user->id)->count());
        $this->assertSame(1, FreeDessertReward::where('user_id', $user->id)->count());

        // Once only.
        $this->actingAs($user, 'customer')->post(route('customer.orders.dessert-rating', $order->id), ['rating' => 1])
            ->assertSessionHasErrors('dessert_rating');
        $this->assertSame(5, FreeDessertRating::where('order_id', $order->id)->sole()->rating);

        // Admin sees both.
        $this->actingAs($this->staffUser('admin', null), 'admin')->withSession(['selected_branch_id' => 1])
            ->get(route('admin.completed-orders', ['date_from' => '2026-10-15']))
            ->assertOk()
            ->assertSee('(2/5)')
            ->assertSee('Reward item:')
            ->assertSee('The mango cake was lovely.');
    }

    public function test_the_reward_rating_needs_a_completed_order_of_yours_with_a_reward_item(): void
    {
        $user = $this->customer();
        $order = $this->orderWithReward($user);

        // Not completed yet.
        $this->actingAs($user, 'customer')->post(route('customer.orders.dessert-rating', $order->id), ['rating' => 4])
            ->assertSessionHasErrors('dessert_rating');

        $this->complete($order);

        // Someone else's order: 404, nothing stored.
        $this->actingAs($this->customer(), 'customer')->post(route('customer.orders.dessert-rating', $order->id), ['rating' => 4])
            ->assertNotFound();

        // An order without a reward item.
        $plain = $this->completedOrderOn($user, '2026-10-14');
        $this->actingAs($user, 'customer')->post(route('customer.orders.dessert-rating', $plain->id), ['rating' => 4])
            ->assertSessionHasErrors('dessert_rating');

        // Bad input.
        $this->actingAs($user, 'customer')->post(route('customer.orders.dessert-rating', $order->id), ['rating' => 9, 'comment' => str_repeat('a', 501)])
            ->assertSessionHasErrors(['rating', 'comment']);

        $this->assertSame(0, FreeDessertRating::whereIn('order_id', [$order->id, $plain->id])->count());
    }

    public function test_order_history_offers_the_reward_rating_only_on_orders_with_a_reward_item(): void
    {
        $user = $this->customer();
        $order = $this->orderWithReward($user);
        $this->complete($order);
        $this->completedOrderOn($user, '2026-10-14');

        $html = $this->actingAs($user, 'customer')->get(route('customer.orders', ['tab' => 'history']))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-testid="rate-dessert-btn"'));
        $this->assertStringContainsString('Rate your reward', $html);
    }

    // ══════════ the old admin "Free dessert reward" checkbox is gone ══════════

    public function test_the_menu_item_form_no_longer_has_the_free_dessert_checkbox_and_ignores_the_field(): void
    {
        $admin = $this->staffUser('admin', null);

        $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => 1])
            ->get(route('admin.menu-items'))
            ->assertOk()
            ->assertDontSee('name="free_dessert_eligible"', false)
            ->assertDontSee('Free dessert reward');

        // A stale form still posting it changes nothing.
        $item = $this->item($this->inventory(10));
        $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => 1])
            ->put(route('admin.menu-items.update', $item->id), [
                'category_id'           => $item->category_id,
                'name'                  => $item->name,
                'price'                 => $item->price,
                'cost'                  => 0,
                'free_dessert_eligible' => '1',
            ])->assertRedirect(route('admin.menu-items'));

        $this->assertFalse((bool) $item->fresh()->free_dessert_eligible);
    }
}

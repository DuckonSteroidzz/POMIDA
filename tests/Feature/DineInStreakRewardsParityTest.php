<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FreeDessertReward;
use App\Models\Inventory;
use App\Models\LoyaltyAward;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\Setting;
use App\Models\User;
use App\Models\Voucher;
use App\Services\FreeDesserts;
use App\Services\StreakCalendar;
use App\Services\TableEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ConfiguresStreakReward;
use Tests\TestCase;

/**
 * Streak Calendar / Streak Rewards parity for Dine-In vs Pick-Up (Oct 2026
 * verification pass). Nothing in StreakCalendar::completedDays(),
 * Loyalty::orderCompleted() or FreeDesserts branches on orders.type — this
 * suite proves that by going through the REAL dine-in doors (the QR landing,
 * App\Http\Controllers\Customer\AuthController::showMenu(), and the real
 * login()) rather than hand-building a session array, the same way
 * TableMoveByStaffTest seats a device.
 *
 * Covers, for a Dine-In order placed by scanning a table's QR:
 *   1. a logged-in customer's order is linked (user_id), the stamp counts
 *      when staff complete it, and the reward + pop-up fire at that order's
 *      branch, same as Pick-Up (see StreakRewardsTest/FreeDessertPromptTest);
 *   2. a reward code (menu-item AND voucher-type) is accepted, applied,
 *      spent once, and given back if the order is cancelled;
 *   3. a guest's Dine-In order earns no stamp and no reward, with no errors,
 *      and the Spin prompt markup is unaffected by order type;
 *   4. a guest who logs in mid table-session has their NEXT order linked to
 *      the account, while an order already placed as a guest stays unlinked.
 */
class DineInStreakRewardsParityTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresStreakReward;

    // 12:00 Manila on 15 Oct 2026 — same anchor as StreakRewardsTest/FreeDessertPromptTest.
    private const NOW_UTC = '2026-10-15 04:00:00';

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

        $this->clearStreakRewardSettings();
        DB::table('settings')->where('key', Setting::STREAK_CALENDAR_ENABLED)->delete();
    }

    // ══════════ fixtures ══════════

    private function customer(bool $verified = true, string $name = 'Dine-In Reward Customer'): User
    {
        return User::create([
            'name'              => $name,
            'email'             => 'dine-in-streak-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => $verified ? now() : null,
        ])->fresh();
    }

    private function portalUser(string $role, ?int $branchId = 1): User
    {
        return User::create([
            'name'              => 'DIS ' . $role,
            'email'             => 'dine-in-streak-' . $role . '-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $role === 'admin' ? null : $branchId,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function inventory(float $quantity, int $branchId = 1): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => 'DIS Ingredient ' . uniqid(),
            'item_code'       => 'DIS-' . strtoupper(substr(uniqid(), -8)) . random_int(10, 99),
            'quantity'        => $quantity,
            'unit'            => 'pc',
            'low_stock_alert' => 0,
            'unit_cost'       => 5,
            'is_active'       => true,
        ]);
    }

    private function item(int $branchId = 1, float $price = 100, float $stock = 20, array $attrs = []): MenuItem
    {
        $inv = $this->inventory($stock, $branchId);
        $item = MenuItem::create(array_merge([
            'category_id'   => Category::value('id'),
            'branch_id'     => $branchId,
            'name'          => 'DIS Item ' . uniqid(),
            'price'         => $price,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ], $attrs));

        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        return $item->fresh();
    }

    private function voucher(array $attrs = []): Voucher
    {
        return Voucher::create(array_merge([
            'branch_id'       => null,
            'code'            => 'DISV' . strtoupper(Str::random(6)),
            'description'     => 'Dine-in streak reward template',
            'discount_type'   => 'percent',
            'discount_value'  => 10,
            'max_uses'        => 5,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 0,
            'is_active'       => true,
        ], $attrs))->fresh();
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

    /** A freshly registered table at $branchId, never used by any other row. */
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

    /** Scan the table's QR — the real door, exactly like a phone camera app opening it. */
    private function scanQr(RestaurantTable $table)
    {
        return $this->get('/customer/menu?branch_id=' . $table->branch_id
            . '&table=' . $table->table_number . '&k=' . $table->code);
    }

    /**
     * Put $cart in the current (already-seated) session and submit a Dine-In
     * order through the real endpoint. Returns [response, new order or null].
     */
    private function placeDineIn(array $cart, array $extra = []): array
    {
        $before = (int) Order::max('id');

        $response = $this->withSession(['cart' => $cart])
            ->post('/customer/place-order', array_merge([
                'order_type'     => 'dine_in',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => array_values($cart)[0]['menu_item_id'], 'quantity' => 1]],
            ], $extra));

        return [$response, Order::where('id', '>', $before)->latest('id')->first()];
    }

    private function complete(Order $order): void
    {
        $this->actingAs($this->portalUser('admin'), 'admin')
            ->put(route('admin.orders.complete', $order->id))
            ->assertRedirect();

        $this->assertSame('completed', $order->fresh()->status);
    }

    // ══════════ 1: logged-in customer, Dine-In — link, stamp, reward, pop-up ══════════

    public function test_logged_in_customer_dine_in_order_links_account_stamps_and_earns_reward_with_popup(): void
    {
        $user = $this->customer();
        $table = $this->table(1);
        $menuItem = $this->item(1, 120, 20, ['name' => 'DIS Ube Cake']);

        // One stamp earns the reward, so completing a single order proves it.
        $this->chooseBranchReward(1, $menuItem, 1, 14);

        $this->actingAs($user, 'customer');
        $this->scanQr($table)->assertOk();

        $this->assertSame('dine_in', session('order_type'));
        $this->assertSame($table->table_number, session('table_number'));
        $this->assertSame(1, (int) session('branch_id'));

        [$response, $order] = $this->placeDineIn($this->cart($menuItem));

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order, 'the Dine-In order was not created');
        $this->assertSame($user->id, $order->user_id, 'a logged-in Dine-In order was not linked to the account');
        $this->assertSame('dine_in', $order->type);
        $this->assertSame($table->table_number, $order->table_number);
        $this->assertSame(1, $order->branch_id);

        $this->complete($order);

        // The stamp.
        $this->assertArrayHasKey('2026-10-15', StreakCalendar::completedDays($user, 1));

        // The reward.
        $rewards = FreeDessertReward::where('user_id', $user->id)->get();
        $this->assertCount(1, $rewards, 'the Dine-In completion did not earn a reward');
        $reward = $rewards->first();
        $this->assertSame('available', $reward->status);
        $this->assertSame('menu_item', $reward->reward_type);
        $this->assertSame($menuItem->id, $reward->menu_item_id);
        $this->assertSame(1, $reward->branch_id);

        // The pop-up, exactly as FreeDessertPromptTest pins for Pick-Up.
        $this->actingAs($user, 'customer')->get('/customer/orders')->assertOk()
            ->assertSee('id="freeDessertPrompt"', false)
            ->assertSee('data-pending="1"', false)
            ->assertSee('You earned a reward!');

        // The RWD code in Vouchers & Rewards.
        $this->actingAs($user, 'customer')->get(route('customer.vouchers'))->assertOk()
            ->assertSee($reward->displayCode());
    }

    // ══════════ 2: reward codes on a Dine-In order — spend once, give back ══════════

    public function test_item_and_voucher_rewards_are_spent_on_dine_in_orders_once_and_given_back_on_cancel(): void
    {
        $user = $this->customer();
        $table = $this->table(1);

        $dessert = $this->item(1, 150, 20, ['name' => 'DIS Free Cake']);
        $template = $this->voucher(['discount_type' => 'percent', 'discount_value' => 10, 'max_uses' => 5]);

        $this->chooseBranchReward(1, $dessert, 5, 30);
        $itemReward = tap(FreeDesserts::grant($user, 1, 1), fn ($r) => $this->assertNotNull($r));

        $this->chooseBranchReward(1, $template, 5, 30);
        $voucherReward = tap(FreeDesserts::grant($user, 1, 2), fn ($r) => $this->assertNotNull($r));

        $this->actingAs($user, 'customer');
        $this->scanQr($table)->assertOk();

        $paidItem = $this->item(1, 100, 20, ['name' => 'DIS Paid Item']);

        // The item-type reward: a free line, spent once.
        [$response1, $order1] = $this->placeDineIn($this->cart($paidItem), [
            'reward_code' => $itemReward->displayCode(),
        ]);
        $response1->assertSessionHasNoErrors();
        $this->assertNotNull($order1);
        $this->assertSame('used', $itemReward->fresh()->status);
        $this->assertSame(1, $order1->items()->where('is_free_dessert', true)->count());

        // The voucher-type reward: a discount, spent once.
        [$response2, $order2] = $this->placeDineIn($this->cart($paidItem, 2), [
            'voucher_code_confirmed' => $voucherReward->displayCode(),
        ]);
        $response2->assertSessionHasNoErrors();
        $this->assertNotNull($order2);
        $this->assertSame('reward', $order2->discount_type);
        $this->assertEquals(20.00, (float) $order2->discount_amount);
        $this->assertSame('used', $voucherReward->fresh()->status);

        // Reusing a used reward on a further Dine-In order is refused.
        [$response3, $order3] = $this->placeDineIn($this->cart($paidItem), [
            'reward_code' => $itemReward->displayCode(),
        ]);
        $this->assertNull($order3);
        $response3->assertSessionHasErrors('reward_code');

        // Cancelling gives the voucher-type reward back with the same code.
        $this->actingAs($this->portalUser('admin'), 'admin')
            ->put(route('admin.orders.cancel', $order2->id))
            ->assertRedirect();

        $this->assertSame('available', $voucherReward->fresh()->status);
        $this->assertSame($voucherReward->code, $voucherReward->fresh()->code);
    }

    // ══════════ 3: guest Dine-In — no stamp, no reward, no errors, Spin still renders ══════════

    public function test_guest_dine_in_order_earns_nothing_but_places_cleanly_and_spin_prompt_is_unaffected(): void
    {
        $this->chooseBranchReward(1, $this->item(1, 100, 20, ['name' => 'DIS Guest Cake']), 1, 14);
        Setting::set(Setting::GAME_ENABLED, '1');

        $table = $this->table(1);
        $menuItem = $this->item(1, 90, 20, ['name' => 'DIS Guest Order Item']);

        // No actingAs(): this is a guest.
        $this->scanQr($table)->assertOk();
        $this->assertSame('dine_in', session('order_type'));

        $rewardsBefore = FreeDessertReward::count();
        $loyaltyBefore = LoyaltyAward::count();

        [$response, $order] = $this->placeDineIn($this->cart($menuItem));

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order, 'the guest Dine-In order was not created');
        $this->assertNull($order->user_id);
        $this->assertSame('dine_in', $order->type);

        // While the order is still pending, the Spin prompt is due — exactly
        // as it would be for a fresh Pick-Up order (SpinPrompt::qualifies()
        // never reads orders.type). No free-dessert pop-up markup: guests
        // never get it (see FreeDessertPromptTest::test_guests_get_no_markup).
        $html = $this->get('/customer/orders')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="freeDessertPrompt"', $html);
        $this->assertStringContainsString('id="spinPromptModal"', $html);

        $this->complete($order);

        $this->assertSame($rewardsBefore, FreeDessertReward::count(), 'a guest Dine-In completion earned a reward');
        $this->assertSame($loyaltyBefore, LoyaltyAward::count(), 'a guest Dine-In completion earned a loyalty award');
    }

    // ══════════ 4: guest logs in mid table-session — the NEXT order links, the earlier one does not ══════════

    public function test_guest_who_logs_in_mid_table_session_links_the_next_order_not_the_earlier_guest_one(): void
    {
        $user = $this->customer(true, 'Mid-Session Login Customer');
        $table = $this->table(1);
        $menuItem = $this->item(1, 110, 20, ['name' => 'DIS Mid-Session Item']);

        // Guest arrives and places a first order before logging in.
        $this->scanQr($table)->assertOk();
        $this->assertSame('dine_in', session('order_type'));

        [$guestResponse, $guestOrder] = $this->placeDineIn($this->cart($menuItem));
        $guestResponse->assertSessionHasNoErrors();
        $this->assertNotNull($guestOrder);
        $this->assertNull($guestOrder->user_id, 'the guest order should not start out linked');

        // Logging in is closed while this order is in progress (it would take
        // the order away from the guest; see DineInOngoingOrderGuardTest), so
        // staff finish it first. Finished, the login is open and, with no
        // Sign up / Log in join button pressed, attaches nothing.
        $this->complete($guestOrder);

        // The guest logs in to an existing account, mid table-session.
        $this->post(route('customer.login.post'), [
            'email'    => $user->email,
            'password' => 'Aa1!aaaaaa',
        ])->assertRedirect(route('customer.menu'));

        $this->assertAuthenticatedAs($user, 'customer');
        $this->assertSame('dine_in', session('order_type'), 'login dropped the Dine-In context');
        $this->assertSame($table->table_number, session('table_number'), 'login lost the seated table');
        $this->assertSame(1, (int) session('branch_id'));

        // The NEXT order, placed after login, is linked to the account.
        [$response, $order] = $this->placeDineIn($this->cart($menuItem));
        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);
        $this->assertSame($user->id, $order->user_id, 'the order placed after login was not linked to the account');
        $this->assertSame($table->table_number, $order->table_number);

        // The earlier guest order is NOT retroactively linked.
        $this->assertNull($guestOrder->fresh()->user_id);
    }
}

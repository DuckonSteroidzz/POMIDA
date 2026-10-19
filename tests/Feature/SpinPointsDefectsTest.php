<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\LoyaltyAward;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use App\Services\SpinPoints;
use App\Services\TableEntry;
use App\Services\VoucherClaims;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ForcesSpinOutcome;
use Tests\TestCase;

/**
 * The tester's points report (Oct 2026), reproduced through the real routes:
 * real orders from the cart, real spins on /customer/add-points (outcome
 * forced with ForcesSpinOutcome, never posted), real staff Complete/Cancel/
 * GCash-reject, and the balance read off the More and Vouchers pages the
 * customer actually looks at. No balance is written by hand.
 *
 *   B1  spin points vanished when the next order was placed.
 *   B2  the balance dropped while the next order was being processed, and
 *       completion only added the +3 bonus on top of what was left.
 *   D   cancelling an order kept the points its spins won.
 *
 * Plus the rules the fix has to hold: a cancel reverses once, never below 0,
 * records any shortfall; a wheel voucher costs exactly its Points Required;
 * points stack across Dine-In, takeout and Pick-Up orders.
 *
 * Every row is created here and rolled back by DatabaseTransactions.
 *
 * Every customer request acts as $user->fresh(): a real request loads the
 * account from the database, while one User object reused across test
 * requests keeps a stale bonus_points (the bonus is credited by a query, not
 * through that object) and the pages would show the wrong number.
 */
class SpinPointsDefectsTest extends TestCase
{
    use DatabaseTransactions;
    use ForcesSpinOutcome;

    // 12:00 Manila on 15 Oct 2026, the same anchor LoyaltyAwardsTest uses.
    private const NOW_UTC = '2026-10-15 04:00:00';

    // 12:00 Manila the day before.
    private const YESTERDAY_UTC = '2026-10-14 04:00:00';

    private MenuItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());

        $this->travelTo(CarbonImmutable::parse(self::NOW_UTC, 'UTC'));

        config([
            'app.shop_timezone'               => 'Asia/Manila',
            'loyalty.welcome_bonus_points'    => 10,
            'loyalty.order_bonus_points'      => 3,
            // Only the +3 completion bonus moves bonus_points in this file.
            'loyalty.streak_milestones'       => [],
            'loyalty.free_dessert_visit_days' => 0,
        ]);

        // 30 spins a minute per IP would cut the longer scenarios short.
        $this->withoutMiddleware(ThrottleRequests::class);

        Cache::flush();
        DB::table('settings')->where('key', 'game_enabled')->delete();
        DB::table('settings')->insert([
            'key' => 'game_enabled', 'branch_id' => null, 'value' => '1', 'group' => 'business',
            'label' => 'Spin & Win Enabled', 'type' => 'text', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // No copied wheel voucher may be won and quietly spend points; the
        // tests that need one create their own. Rolled back afterwards.
        Voucher::where('points_required', '>', 0)->update(['is_active' => false]);

        $this->item = $this->menuItem();
    }

    // ══════════ fixtures ══════════

    private function customer(): User
    {
        return User::create([
            'name'              => 'Points Tester',
            'email'             => 'points-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ])->fresh();
    }

    private function admin(): User
    {
        return User::create([
            'name'              => 'Points Admin',
            'email'             => 'points-admin-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'admin',
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function menuItem(): MenuItem
    {
        $inventory = Inventory::create([
            'branch_id'       => 1,
            'item_name'       => 'Points Ingredient ' . uniqid(),
            'item_code'       => 'PTS-' . strtoupper(substr(uniqid(), -8)) . random_int(10, 99),
            'quantity'        => 500,
            'unit'            => 'pc',
            'low_stock_alert' => 0,
            'unit_cost'       => 5,
            'is_active'       => true,
        ]);

        $item = MenuItem::create([
            'category_id'   => Category::value('id'),
            'branch_id'     => 1,
            'name'          => 'Points Item ' . uniqid(),
            'price'         => 100,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inventory->id, 'quantity_used' => 1]);

        return $item->fresh();
    }

    private function wheelVoucher(int $pointsRequired): Voucher
    {
        return Voucher::create([
            'branch_id' => null, 'code' => 'PTSW' . strtoupper(Str::random(6)), 'description' => 'Points wheel test',
            'discount_type' => 'fixed', 'discount_value' => 10, 'max_uses' => 100, 'used_count' => 0,
            'minimum_order' => 0, 'expires_at' => now()->addMonth(), 'is_active' => true,
            'points_required' => $pointsRequired,
        ]);
    }

    private function cart(): array
    {
        return [(string) $this->item->id => [
            'menu_item_id' => (string) $this->item->id,
            'name'         => $this->item->name,
            'price'        => (float) $this->item->price,
            'base_price'   => (float) $this->item->price,
            'quantity'     => 1,
            'image'        => null,
            'options'      => [],
        ]];
    }

    private function table(): RestaurantTable
    {
        do {
            $number = (string) random_int(1000000, 9999999);
        } while (
            RestaurantTable::where('branch_id', 1)->where('table_number', $number)->exists()
            || Order::where('branch_id', 1)->where('table_number', $number)->exists()
        );

        return TableEntry::findOrRegister(1, $number);
    }

    /** Scan the table's QR — the real Dine-In door. */
    private function seat(User $user, RestaurantTable $table): void
    {
        $this->actingAs($user->fresh(), 'customer')
            ->get('/customer/menu?branch_id=1&table=' . $table->table_number . '&k=' . $table->code)
            ->assertOk();

        $this->assertSame('dine_in', session('order_type'));
    }

    /** Submit the session cart through the real checkout. Returns the new order. */
    private function place(User $user, array $fields, array $session = []): Order
    {
        $before = (int) Order::max('id');

        $response = $this->actingAs($user->fresh(), 'customer')
            ->withSession(array_merge(['cart' => $this->cart()], $session))
            ->post('/customer/place-order', array_merge([
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
            ], $fields));

        $order = Order::where('id', '>', $before)->latest('id')->first();

        $this->assertNotNull($order, 'the order was not created: '
            . json_encode(session('errors')?->all() ?? []) . ' ' . (string) session('error'));
        $response->assertSessionHasNoErrors();
        $this->assertSame($user->id, (int) $order->user_id);

        return $order;
    }

    private function placePickUp(User $user, string $payment = 'cash'): Order
    {
        $order = $this->place(
            $user,
            ['order_type' => 'pick_up', 'payment_method' => $payment],
            ['branch_id' => 1, 'order_type' => 'pick_up', 'table_number' => null]
        );

        $this->assertSame('pick_up', $order->type);

        return $order;
    }

    private function placeDineIn(User $user, bool $takeout = false): Order
    {
        $order = $this->place($user, ['order_type' => 'dine_in'] + ($takeout ? ['is_takeout' => '1'] : []));

        $this->assertSame('dine_in', $order->type);
        $this->assertSame($takeout, (bool) $order->is_takeout);

        return $order;
    }

    /** Spin $times on the real endpoint, every spin landing on a $points segment. */
    private function spin(User $user, int $times, int $points): void
    {
        $this->forceSpinOutcome($points);

        for ($i = 1; $i <= $times; $i++) {
            $this->actingAs($user->fresh(), 'customer')
                ->postJson('/customer/add-points')
                ->assertOk();
        }
    }

    private function complete(Order $order): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.orders.complete', $order->id))
            ->assertRedirect();

        $this->assertSame('completed', $order->fresh()->status);
    }

    /** The number on the More page's "Vouchers & Rewards" row. */
    private function morePoints(User $user): int
    {
        $html = $this->actingAs($user->fresh(), 'customer')->get('/customer/more')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#text-peach-deep/50">([\d,]+) points</span>#', $html);
        preg_match('#text-peach-deep/50">([\d,]+) points</span>#', $html, $m);

        return (int) str_replace(',', '', $m[1]);
    }

    /** The "You have N pts" on the Vouchers & Rewards page. */
    private function voucherPagePoints(User $user): int
    {
        $html = $this->actingAs($user->fresh(), 'customer')->get(route('customer.vouchers'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#You have <span class="text-peach-red">(\d+)</span> pts#', $html);
        preg_match('#You have <span class="text-peach-red">(\d+)</span> pts#', $html, $m);

        return (int) $m[1];
    }

    private function assertShown(int $expected, User $user, string $when): void
    {
        $this->assertSame($expected, $this->morePoints($user), "More page, {$when}");
        $this->assertSame($expected, $this->voucherPagePoints($user), "Vouchers page, {$when}");
    }

    private function ledger(User $user, string $type)
    {
        return LoyaltyAward::where('user_id', $user->id)->where('type', $type)->orderBy('id')->get();
    }

    /** Yesterday: one Pick-Up order, $spins spins of $points, completed (+3). */
    private function earnedYesterday(User $user, int $spins, int $points): void
    {
        $this->travelTo(CarbonImmutable::parse(self::YESTERDAY_UTC, 'UTC'));

        $order = $this->placePickUp($user);

        if ($spins > 0) {
            $this->spin($user, $spins, $points);
        }

        $this->complete($order);

        $this->travelTo(CarbonImmutable::parse(self::NOW_UTC, 'UTC'));
    }

    // ══════════ B1 ══════════

    public function test_b1_spin_points_survive_placing_the_next_order(): void
    {
        $user = $this->customer();

        $first = $this->placePickUp($user);
        $this->spin($user, 7, 3);
        $this->assertShown(21, $user, 'after all 7 spins');

        $this->complete($first);
        $this->assertShown(24, $user, 'after completion (+3 order bonus)');

        // The next order: Dine-In marked Take Out, cash.
        $this->seat($user, $this->table());
        $this->placeDineIn($user, takeout: true);

        $this->assertShown(24, $user, 'after placing the next order');
        $this->assertSame(21, (int) $user->fresh()->points, 'the spin balance was touched by placing an order');
    }

    // ══════════ B2 ══════════

    public function test_b2_points_hold_while_the_next_order_is_processed_and_completion_adds_only_the_bonus(): void
    {
        $user = $this->customer();
        $this->earnedYesterday($user, 0, 0);   // bonus 3

        $this->seat($user, $this->table());
        $this->placeDineIn($user);

        $this->spin($user, 4, 5);
        $this->spin($user, 3, 0);
        $this->assertShown(23, $user, 'after exhausting the spins');

        $this->actingAs($user->fresh(), 'customer')->postJson('/customer/add-points')
            ->assertStatus(422)->assertJsonPath('blocked', 'window_exhausted');

        // A second round at the same table while the first is still open.
        $next = $this->placeDineIn($user);
        $this->assertShown(23, $user, 'right after placing the next order');

        $this->actingAs($this->admin(), 'admin')->put(route('admin.orders.prepare', $next->id))->assertRedirect();
        $this->assertSame('preparing', $next->fresh()->status);
        $this->assertShown(23, $user, 'while the next order is being prepared');

        $this->complete($next);
        $this->assertShown(26, $user, 'after the next order completed');
    }

    // ══════════ D ══════════

    public function test_d_customer_cancel_reverses_exactly_the_spin_points_that_order_won(): void
    {
        $user = $this->customer();
        $this->earnedYesterday($user, 2, 3);   // 6 spin + 3 bonus
        $this->assertShown(9, $user, 'before today\'s order');

        $order = $this->placePickUp($user);
        $this->spin($user, 2, 5);
        $this->assertShown(19, $user, 'after two spins on the pending order');

        $this->actingAs($user->fresh(), 'customer')
            ->post(route('customer.orders.cancel', $order->id))
            ->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);

        $this->assertShown(9, $user, 'after cancelling');
        $this->assertSame(6, (int) $user->fresh()->points);
        $this->assertSame(3, (int) $user->fresh()->bonus_points, 'bonus points were touched');

        $rows = $this->ledger($user, LoyaltyAward::TYPE_SPIN_REVERSAL);
        $this->assertCount(1, $rows);
        $this->assertSame((string) $order->id, $rows[0]->award_key);
        $this->assertSame(10, $rows[0]->points);
        $this->assertSame($order->id, $rows[0]->order_id);
        $this->assertCount(0, $this->ledger($user, LoyaltyAward::TYPE_SPIN_REVERSAL_SHORTFALL));

        // A reversal is a debit: "Recent bonuses" on the Account page still
        // lists only yesterday's +3, never a "+10".
        $html = $this->actingAs($user->fresh(), 'customer')->get(route('customer.account'))->assertOk()->getContent();
        $this->assertStringContainsString('text-green-700">+3</span>', $html);
        $this->assertStringNotContainsString('text-green-700">+10</span>', $html);
    }

    public function test_d_staff_cancel_reverses_the_spin_points(): void
    {
        $user = $this->customer();
        $order = $this->placePickUp($user);
        $this->spin($user, 2, 8);
        $this->assertShown(16, $user, 'after two spins');

        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.orders.cancel', $order->id))
            ->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertShown(0, $user, 'after staff cancelled');
        $this->assertSame(16, $this->ledger($user, LoyaltyAward::TYPE_SPIN_REVERSAL)->sum('points'));
    }

    public function test_d_gcash_rejection_reverses_the_spin_points(): void
    {
        $user = $this->customer();
        $order = $this->placePickUp($user, 'gcash');
        $this->spin($user, 3, 3);

        $this->actingAs($user->fresh(), 'customer')
            ->post(route('customer.gcash-payment.paid', $order->id))
            ->assertRedirect();
        $this->assertSame('awaiting_verification', $order->fresh()->payment_status);

        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.orders.payment.reject', $order->id))
            ->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertShown(0, $user, 'after the GCash payment was rejected');
        $this->assertSame(9, $this->ledger($user, LoyaltyAward::TYPE_SPIN_REVERSAL)->sum('points'));
    }

    public function test_a_completed_order_keeps_its_spin_points(): void
    {
        $user = $this->customer();
        $order = $this->placePickUp($user);
        $this->spin($user, 2, 5);
        $this->complete($order);

        // Staff cannot cancel a completed order; nothing is reversed.
        $this->actingAs($this->admin(), 'admin')->put(route('admin.orders.cancel', $order->id));
        $this->assertSame('completed', $order->fresh()->status);

        $this->assertShown(13, $user, 'after a refused cancel of a completed order');
        $this->assertCount(0, $this->ledger($user, LoyaltyAward::TYPE_SPIN_REVERSAL));
    }

    // ══════════ cancel twice ══════════

    public function test_cancelling_twice_or_re_saving_reverses_only_once(): void
    {
        $user = $this->customer();
        $this->earnedYesterday($user, 2, 3);   // 6 spin + 3 bonus

        $order = $this->placePickUp($user);
        $this->spin($user, 2, 5);

        $this->actingAs($user->fresh(), 'customer')->post(route('customer.orders.cancel', $order->id))->assertRedirect();
        $this->assertShown(9, $user, 'after the first cancel');

        // Customer presses Cancel again, staff press Cancel on a stale tab.
        $this->actingAs($user->fresh(), 'customer')->post(route('customer.orders.cancel', $order->id));
        $this->actingAs($this->admin(), 'admin')->put(route('admin.orders.cancel', $order->id));

        // A re-save, and a second pending -> cancelled transition on the
        // same row (the model hook fires again on that one).
        $fresh = $order->fresh();
        $fresh->save();
        $fresh->status = 'pending';
        $fresh->saveQuietly();
        $fresh->status = 'cancelled';
        $fresh->save();

        // And the service called directly, twice.
        $this->assertSame(0, SpinPoints::reverseForCancelledOrder($order->fresh()));
        $this->assertSame(0, SpinPoints::reverseForCancelledOrder($order->fresh()));

        $this->assertShown(9, $user, 'after every repeat');
        $this->assertCount(1, $this->ledger($user, LoyaltyAward::TYPE_SPIN_REVERSAL));
    }

    // ══════════ cancel after the points were spent ══════════

    public function test_cancel_after_the_points_were_spent_deducts_what_is_left_and_records_the_shortfall(): void
    {
        $user = $this->customer();
        $this->earnedYesterday($user, 0, 0);   // bonus 3
        $voucher = $this->wheelVoucher(15);

        $order = $this->placePickUp($user);
        $this->spin($user, 1, 8);              // 8 spin + 3 bonus = 11
        $this->spin($user, 1, 8);              // 16 + 3 = 19 -> voucher, 15 from the spin balance

        $claim = UserVoucher::where('user_id', $user->id)->where('voucher_id', $voucher->id)->first();
        $this->assertNotNull($claim, 'the wheel voucher was not won');
        $this->assertSame(1, (int) $user->fresh()->points);
        $this->assertShown(4, $user, 'after winning the voucher');

        $this->actingAs($user->fresh(), 'customer')->post(route('customer.orders.cancel', $order->id))->assertRedirect();

        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->points, 'the spin balance must stop at 0');
        $this->assertSame(3, (int) $fresh->bonus_points, 'bonus points were taken');
        $this->assertShown(3, $user, 'after cancelling');

        $this->assertSame(1, $this->ledger($user, LoyaltyAward::TYPE_SPIN_REVERSAL)->sum('points'));
        $shortfall = $this->ledger($user, LoyaltyAward::TYPE_SPIN_REVERSAL_SHORTFALL);
        $this->assertCount(1, $shortfall);
        $this->assertSame(15, $shortfall[0]->points);
        $this->assertSame($order->id, $shortfall[0]->order_id);

        // The voucher already issued stays issued and usable.
        $this->assertNotNull($claim->fresh());
        $this->assertFalse((bool) $claim->fresh()->is_used);
    }

    // ══════════ voucher redemption ══════════

    public function test_a_wheel_voucher_costs_exactly_its_points_required_and_spending_it_costs_nothing_more(): void
    {
        $user = $this->customer();
        $this->earnedYesterday($user, 7, 3);   // 21 spin + 3 bonus
        $this->assertShown(24, $user, 'after yesterday');

        $voucher = $this->wheelVoucher(30);

        $today = $this->placePickUp($user);
        $this->assertShown(24, $user, 'after placing today\'s order');

        $this->spin($user, 1, 8);              // 32 >= 30 -> won, 32 - 30 = 2
        $claim = UserVoucher::where('user_id', $user->id)->where('voucher_id', $voucher->id)->first();
        $this->assertNotNull($claim, 'stacked points did not reach the wheel voucher');
        $this->assertShown(2, $user, 'after the voucher took its 30 points');

        $this->complete($today);
        $this->assertShown(5, $user, 'after today\'s order completed (+3)');

        // Spend the won voucher on the next order: no further deduction.
        $next = $this->place(
            $user,
            ['order_type' => 'pick_up', 'voucher_code_confirmed' => VoucherClaims::display($claim->claim_code)],
            ['branch_id' => 1, 'order_type' => 'pick_up', 'table_number' => null]
        );
        $this->assertSame($voucher->id, (int) $next->voucher_id, 'the voucher was not redeemed');
        $this->assertTrue((bool) $claim->fresh()->is_used);

        $this->assertShown(5, $user, 'after redeeming the voucher at checkout');
    }

    // ══════════ stacking ══════════

    public function test_points_stack_across_pick_up_dine_in_and_takeout_orders(): void
    {
        $user = $this->customer();

        $pickUp = $this->placePickUp($user);
        $this->spin($user, 5, 3);
        $this->complete($pickUp);
        $this->assertShown(18, $user, 'after the Pick-Up order');

        $this->seat($user, $this->table());
        $dineIn = $this->placeDineIn($user);
        $this->assertShown(18, $user, 'after placing the Dine-In order');
        $this->spin($user, 5, 3);
        $this->complete($dineIn);
        $this->assertShown(36, $user, 'after the Dine-In order');

        $takeout = $this->placeDineIn($user, takeout: true);
        $this->assertShown(36, $user, 'after placing the takeout order');
        $this->spin($user, 5, 3);
        $this->complete($takeout);
        $this->assertShown(54, $user, 'after the takeout order');

        $fresh = $user->fresh();
        $this->assertSame(45, (int) $fresh->points);
        $this->assertSame(9, (int) $fresh->bonus_points);
    }
}

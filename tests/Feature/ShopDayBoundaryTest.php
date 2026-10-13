<?php

namespace Tests\Feature;

use App\Models\GamePlayed;
use App\Models\Order;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use App\Services\VoucherClaims;
use App\Support\GuestOrders;
use App\Support\ShopDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ForcesSpinOutcome;
use Tests\TestCase;

/**
 * The customer-facing "day" is the shop's day (Asia/Manila), not UTC's.
 *
 * THE BUG
 * -------
 * APP_TIMEZONE is UTC, so today(), isToday() and whereDate() put the day
 * boundary at UTC midnight, which is 8:00 AM in Manila. The daily spin cap,
 * the "7 spins per order placed today" count and voucher dates all rolled over
 * at breakfast time, not midnight. App\Support\ShopDay moves the boundary;
 * nothing is stored differently.
 *
 * THE FOUR CLOCK POINTS (Manila -> UTC)
 * -------------------------------------
 *   Oct 7 23:59 Manila = Oct 7 15:59 UTC   last minute of the shop day
 *   Oct 8 00:01 Manila = Oct 7 16:01 UTC   new shop day, SAME UTC day (old rule: still Oct 7)
 *   Oct 8 07:59 Manila = Oct 7 23:59 UTC   old rule: still Oct 7
 *   Oct 8 08:01 Manila = Oct 8 00:01 UTC   the OLD boundary; the shop day does not change here
 *
 * Several tests also assert what the OLD expression answers at the same
 * instant, as a control: that is the proof the case used to be wrong.
 */
class ShopDayBoundaryTest extends TestCase
{
    use DatabaseTransactions;
    use ForcesSpinOutcome;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['app.timezone' => 'UTC', 'app.shop_timezone' => 'Asia/Manila']);
        $this->enableGame();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Freeze the clock at a Manila wall time. The instant is handed over in
     * UTC on purpose: with a Manila-zoned test-now, Carbon parses every stored
     * timestamp as Manila too, and the reads come back 8 hours off.
     */
    private function at(string $manila): void
    {
        $this->travelTo(CarbonImmutable::parse($manila, 'Asia/Manila')->utc());
    }

    private function enableGame(): void
    {
        DB::table('settings')->where('key', 'game_enabled')->delete();
        DB::table('settings')->insert([
            'key' => 'game_enabled', 'branch_id' => null, 'value' => '1',
            'group' => 'business', 'label' => 'Spin & Win Enabled', 'type' => 'text',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function customer(): User
    {
        return User::create([
            'name'              => 'Shop Day Test',
            'email'             => 'shopday-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ])->fresh();
    }

    /** An order placed at the current (travelled) time. created_at is not fillable, so the clock sets it. */
    private function order(?User $user = null): Order
    {
        $order = Order::create([
            'order_number'   => 'SDB-' . substr(uniqid(), -8),
            'user_id'        => $user?->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 500,
            'total'          => 500,
        ]);

        if ($user === null) {
            GuestOrders::remember($order->id);
        }

        return $order;
    }

    private function wheelVoucher(array $attrs = []): Voucher
    {
        return Voucher::create(array_merge([
            'code'            => 'SDB' . strtoupper(substr(uniqid(), -7)),
            'description'     => 'Shop day test voucher',
            'discount_type'   => 'fixed',
            'discount_value'  => 10,
            'max_uses'        => 0,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'expires_at'      => null,
            'valid_from'      => null,
            'is_active'       => true,
            'points_required' => 5,
        ], $attrs));
    }

    private function spinState(?User $user = null): array
    {
        $request = $user ? $this->actingAs($user, 'customer') : $this;

        return $request->get('/customer/game')->assertOk()->viewData('spinState');
    }

    // ══════════════════════════════════════════════════════════════════
    // The helper itself
    // ══════════════════════════════════════════════════════════════════

    public function test_bounds_are_the_manila_day_expressed_in_utc(): void
    {
        $this->at('2026-10-08 00:01:00');

        [$start, $end] = ShopDay::bounds();

        $this->assertSame('2026-10-08', ShopDay::todayString());
        $this->assertSame('2026-10-07 16:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-08 16:00:00', $end->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $start->getTimezone()->getName(), 'bounds must be in the zone timestamps are stored in');

        // CONTROL: the UTC calendar still says the 7th at this instant.
        $this->assertSame('2026-10-07', today()->toDateString());
    }

    public function test_the_shop_date_does_not_change_at_the_old_8am_boundary(): void
    {
        $this->at('2026-10-08 07:59:00');
        $this->assertSame('2026-10-08', ShopDay::todayString());

        $this->at('2026-10-08 08:01:00');
        $this->assertSame('2026-10-08', ShopDay::todayString());
    }

    // ══════════════════════════════════════════════════════════════════
    // Spins per order placed today
    // ══════════════════════════════════════════════════════════════════

    /**
     * THE BOUNDARY CASE THAT USED TO BE WRONG (one of them): an order placed
     * at 7:59 AM Manila lost its spins at 8:00 AM, because whereDate() on UTC
     * put it on the previous day.
     */
    public function test_an_early_morning_order_keeps_its_spins_past_8am(): void
    {
        $this->at('2026-10-08 07:59:00');
        $order = $this->order();

        $this->at('2026-10-08 08:01:00');

        // CONTROL: the old query no longer finds the order at this instant.
        $this->assertFalse(
            Order::whereDate('created_at', today())->whereKey($order->id)->exists(),
            'control: the old UTC rule should drop this order at 08:01 Manila'
        );

        $state = $this->spinState();

        $this->assertSame(7, $state['spins_total'], 'the 07:59 order lost its spins at 08:01 Manila');
        $this->assertTrue($state['can_spin']);
        $this->assertSame($order->id, $state['order_id']);
    }

    public function test_an_order_from_last_night_stops_counting_at_manila_midnight(): void
    {
        $this->at('2026-10-07 23:59:00');
        $this->order();

        $this->assertSame(7, $this->spinState()['spins_total'], 'setup: the order counts on its own day');

        $this->at('2026-10-08 00:01:00');

        // CONTROL: by UTC it is still the same day, so the old rule kept it.
        $this->assertSame('2026-10-07', today()->toDateString());

        $state = $this->spinState();

        $this->assertSame(0, $state['spins_total'], "yesterday's order still granted spins after Manila midnight");
        $this->assertSame('no_active_order', $state['blocked']);
    }

    // ══════════════════════════════════════════════════════════════════
    // The daily spin cap (signed-in customers)
    // ══════════════════════════════════════════════════════════════════

    private function spendDailyCap(User $user, Order $order): void
    {
        for ($i = 1; $i <= 15; $i++) {
            GamePlayed::create([
                'user_id' => $user->id, 'order_id' => $order->id,
                'spin_number' => $i, 'points_awarded' => 0,
            ]);
        }
    }

    public function test_the_daily_cap_does_not_reset_at_8am_manila(): void
    {
        $user = $this->customer();

        $this->at('2026-10-08 00:01:00');
        $this->order($user);
        $this->order($user);
        $this->order($user);
        $this->spendDailyCap($user, $this->order($user)); // 4 orders = 28 spins, 15 used

        $this->at('2026-10-08 07:59:00');
        $this->assertSame(0, $this->spinState($user)['daily_remaining']);

        $this->at('2026-10-08 08:01:00');

        // CONTROL: the old count saw none of this morning's spins.
        $this->assertSame(0, GamePlayed::where('user_id', $user->id)->whereDate('created_at', today())->count());

        $state = $this->spinState($user);
        $this->assertSame(0, $state['daily_remaining'], 'the daily spin cap reset at 8:00 AM Manila');
        $this->assertSame('daily_limit', $state['blocked']);

        // And the endpoint, which re-derives the cap itself, refuses too.
        $this->forceSpinOutcome(3);
        $spin = $this->actingAs($user, 'customer')->postJson('/customer/add-points');
        $this->assertFalse($spin->json('success'), 'a 16th spin was allowed in one Manila day');
        $this->assertSame('daily_limit', $spin->json('blocked'));
    }

    public function test_the_daily_cap_resets_at_manila_midnight(): void
    {
        $user = $this->customer();

        $this->at('2026-10-07 23:59:00');
        $this->spendDailyCap($user, $this->order($user));
        $this->assertSame(0, $this->spinState($user)['daily_remaining'], 'setup: cap spent');

        $this->at('2026-10-08 00:01:00');
        $this->order($user);

        $state = $this->spinState($user);
        $this->assertSame(15, $state['daily_remaining'], "the cap did not reset at Manila midnight");
        $this->assertTrue($state['can_spin']);
    }

    // ══════════════════════════════════════════════════════════════════
    // A won voucher's valid_from
    // ══════════════════════════════════════════════════════════════════

    public function test_a_prize_won_just_after_manila_midnight_is_dated_that_day_and_valid_now(): void
    {
        Voucher::query()->update(['is_active' => false]); // rolled back; makes the prize deterministic
        $voucher = $this->wheelVoucher();

        $this->at('2026-10-08 00:01:00');
        $this->order();
        $this->forceSpinOutcome(8);

        $spin = $this->postJson('/customer/add-points')->assertOk();
        $this->assertTrue($spin->json('success'), json_encode($spin->json()));

        $claim = UserVoucher::where('voucher_id', $voucher->id)->latest('id')->firstOrFail();

        // Old rule: today() = 2026-10-07 at this instant.
        $this->assertSame('2026-10-08', $claim->valid_from->toDateString());
        $this->assertSame('2026-10-08', $claim->acquired_date->toDateString());
        $this->assertNull($voucher->fresh()->redemptionErrorFor(null, 100, $claim), 'the fresh prize is not usable immediately');
    }

    public function test_counter_codes_are_also_dated_by_the_shop_day(): void
    {
        $voucher = $this->wheelVoucher();
        $staff   = $this->customer(); // any users row satisfies the issued_by foreign key

        $this->at('2026-10-08 07:59:00');
        $claim = VoucherClaims::mintForCounter($voucher, $staff->id);

        $this->assertSame('2026-10-08', $claim->valid_from->toDateString());
    }

    // ══════════════════════════════════════════════════════════════════
    // Voucher expiry: a date is valid through that date, Manila time
    // ══════════════════════════════════════════════════════════════════

    /**
     * THE BOUNDARY CASE THAT USED TO BE WRONG: a voucher whose expiry date is
     * the 8th stopped working at 8:00 AM Manila on the 8th.
     */
    public function test_a_voucher_is_valid_through_the_end_of_its_expiry_date_in_manila(): void
    {
        $voucher = $this->wheelVoucher(['points_required' => 0, 'expires_at' => '2026-10-08']);

        foreach (['2026-10-07 23:59:00', '2026-10-08 00:01:00', '2026-10-08 07:59:00', '2026-10-08 08:01:00', '2026-10-08 23:59:00'] as $manila) {
            $this->at($manila);
            $fresh = $voucher->fresh();

            $this->assertFalse($fresh->isExpired(), "expired at {$manila} Manila");
            $this->assertNull($fresh->availabilityErrorFor(null), "refused at {$manila} Manila");
            $this->assertTrue(Voucher::whereKey($voucher->id)->notExpired()->exists(), "SQL twin disagrees at {$manila} Manila");
        }

        // CONTROL: the old check called it expired at 08:01 Manila on the 8th.
        $this->at('2026-10-08 08:01:00');
        $this->assertTrue($voucher->fresh()->expires_at->isPast());

        $this->at('2026-10-09 00:01:00');
        $fresh = $voucher->fresh();
        $this->assertTrue($fresh->isExpired(), 'still valid the day after its expiry date');
        $this->assertSame('This voucher has expired.', $fresh->availabilityErrorFor(null));
        $this->assertFalse(Voucher::whereKey($voucher->id)->notExpired()->exists());
    }

    public function test_an_expiry_with_a_time_of_day_still_expires_at_that_instant(): void
    {
        $this->at('2026-10-08 10:00:00');
        $voucher = $this->wheelVoucher(['points_required' => 0, 'expires_at' => now()->subMinute()]);

        $this->assertTrue($voucher->fresh()->isExpired());
        $this->assertFalse(Voucher::whereKey($voucher->id)->notExpired()->exists());
    }

    // ══════════════════════════════════════════════════════════════════
    // My Vouchers: "Valid now"
    // ══════════════════════════════════════════════════════════════════

    public function test_my_vouchers_shows_valid_now_from_manila_midnight(): void
    {
        $user    = $this->customer();
        $voucher = $this->wheelVoucher();
        $claim   = UserVoucher::create([
            'user_id' => $user->id, 'voucher_id' => $voucher->id,
            'claim_code' => VoucherClaims::mintCode(),
            'acquired_date' => '2026-10-07', 'valid_from' => '2026-10-08', 'is_used' => false,
        ]);

        $this->at('2026-10-07 23:59:00');
        $this->assertSame('not_yet_valid', $claim->fresh()->statusKey());

        $this->at('2026-10-08 00:01:00');

        // CONTROL: UTC's today is still the 7th, so the old check said "not yet".
        $this->assertTrue(today()->lessThan($claim->fresh()->valid_from));

        $this->assertSame('ready', $claim->fresh()->statusKey(), 'not "Valid now" after Manila midnight');

        $cards = $this->actingAs($user, 'customer')->get('/customer/vouchers')->assertOk()->viewData('userVouchers');
        $this->assertSame('ready', $cards->firstWhere('id', $claim->id)->statusKey());
    }

    public function test_my_vouchers_marks_a_voucher_expired_only_after_its_manila_date(): void
    {
        $user    = $this->customer();
        $voucher = $this->wheelVoucher(['expires_at' => '2026-10-08']);
        $claim   = UserVoucher::create([
            'user_id' => $user->id, 'voucher_id' => $voucher->id,
            'claim_code' => VoucherClaims::mintCode(),
            'acquired_date' => '2026-10-01', 'valid_from' => '2026-10-01', 'is_used' => false,
        ]);

        $this->at('2026-10-08 08:01:00');
        $this->assertSame('ready', $claim->fresh()->statusKey(), 'expired at 8:00 AM on its last day');

        $this->at('2026-10-09 00:01:00');
        $this->assertSame('expired', $claim->fresh()->statusKey());
    }

    // ══════════════════════════════════════════════════════════════════
    // The Spin Prompt uses the same day
    // ══════════════════════════════════════════════════════════════════

    public function test_the_spin_prompt_still_offers_an_early_morning_order_after_8am(): void
    {
        $user = $this->customer();

        $this->at('2026-10-08 07:59:00');
        $order = $this->order($user);

        $this->at('2026-10-08 08:01:00');

        // CONTROL: the old isToday() check (UTC) rejects this order now.
        $this->assertFalse($order->fresh()->created_at->isToday());

        $this->actingAs($user, 'customer')->get('/customer/orders')->assertOk()
            ->assertSee('id="spinPromptModal"', false)
            ->assertSee($order->order_number);
    }

    public function test_the_spin_prompt_does_not_offer_last_nights_order_after_manila_midnight(): void
    {
        $user = $this->customer();

        $this->at('2026-10-07 23:59:00');
        $this->order($user);

        $this->at('2026-10-08 00:01:00');

        $this->actingAs($user, 'customer')->get('/customer/orders')->assertOk()
            ->assertDontSee('id="spinPromptModal"', false);
    }
}

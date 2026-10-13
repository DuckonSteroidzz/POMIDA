<?php

namespace Tests\Feature;

use App\Models\LoyaltyAward;
use App\Models\Order;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use App\Services\Loyalty;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ForcesSpinOutcome;
use Tests\TestCase;

/**
 * Registered-customer bonuses (App\Services\Loyalty): when they are paid, to
 * whom, and that each is paid exactly once.
 *
 * The bonus values are pinned per test with config([...]) rather than read
 * from config/loyalty.php, so the owner can change the shipped values without
 * breaking this file. Everything runs inside DatabaseTransactions on accounts
 * and orders created here. The cross-process race is in LoyaltyAwardRaceTest.
 */
class LoyaltyAwardsTest extends TestCase
{
    use DatabaseTransactions;
    use ForcesSpinOutcome;

    // 12:00 Manila on 15 Oct 2026.
    private const NOW_UTC = '2026-10-15 04:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());

        $this->travelTo(CarbonImmutable::parse(self::NOW_UTC, 'UTC'));

        config([
            'app.shop_timezone'            => 'Asia/Manila',
            'loyalty.welcome_bonus_points' => 10,
            'loyalty.order_bonus_points'   => 3,
            'loyalty.streak_milestones'    => [3 => 10, 7 => 25],
            // The free dessert has its own file (FreeDessertRewardTest); off
            // here so this one keeps measuring the points bonuses alone.
            'loyalty.free_dessert_visit_days'  => 0,
        ]);
    }

    // ══════════ fixtures ══════════

    private function customer(bool $verified = true, array $overrides = []): User
    {
        return User::create(array_merge([
            'name'              => 'Loyalty Test',
            'email'             => 'loyalty-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => $verified ? now() : null,
        ], $overrides))->fresh();
    }

    private function admin(): User
    {
        return User::create([
            'name'              => 'Loyalty Admin',
            'email'             => 'loyalty-admin-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'admin',
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function manila(string $local): string
    {
        return CarbonImmutable::parse($local, 'Asia/Manila')->utc()->format('Y-m-d H:i:s');
    }

    /** An order with no lines, so completion has no stock to deduct. */
    private function order(?User $user, string $status = 'pending', ?string $completedManila = null): Order
    {
        return Order::create([
            'order_number'   => 'LOY-' . strtoupper(Str::random(8)),
            'user_id'        => $user?->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => $status,
            'payment_method' => 'cash',
            'payment_status' => $status === 'completed' ? 'paid' : 'pending',
            'subtotal'       => 100,
            'total'          => 100,
            'completed_at'   => $completedManila ? $this->manila($completedManila) : null,
        ]);
    }

    /** Staff press Complete — the real route, the only way an order completes. */
    private function complete(Order $order, ?User $admin = null): void
    {
        $this->actingAs($admin ?? $this->admin(), 'admin')
            ->put(route('admin.orders.complete', $order->id))
            ->assertRedirect();
    }

    private function awards(User $user, ?string $type = null)
    {
        return LoyaltyAward::where('user_id', $user->id)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('id')
            ->get();
    }

    private function bonusOf(User $user): int
    {
        return (int) DB::table('users')->where('id', $user->id)->value('bonus_points');
    }

    // ══════════ completed-order bonus ══════════

    public function test_completing_a_verified_customers_order_pays_the_order_bonus(): void
    {
        $user = $this->customer();
        $order = $this->order($user);

        $this->complete($order);

        $this->assertSame('completed', $order->fresh()->status);
        $awards = $this->awards($user, LoyaltyAward::TYPE_ORDER_BONUS);
        $this->assertCount(1, $awards);
        $this->assertSame((string) $order->id, $awards[0]->award_key);
        $this->assertSame(3, $awards[0]->points);
        $this->assertSame($order->id, $awards[0]->order_id);
        $this->assertSame(3, $this->bonusOf($user));
    }

    public function test_the_order_bonus_is_never_paid_twice_for_one_order(): void
    {
        $user = $this->customer();
        $order = $this->order($user);
        $admin = $this->admin();

        $this->complete($order, $admin);
        // A stale tab pressing Complete again: refused by completeOrder itself.
        $this->complete($order, $admin);
        // And the service called directly again, as a retry would.
        $this->assertSame([], Loyalty::orderCompleted($order->fresh()));
        $this->assertSame([], Loyalty::orderCompleted($order->fresh()));

        $this->assertCount(1, $this->awards($user, LoyaltyAward::TYPE_ORDER_BONUS));
        $this->assertSame(3, $this->bonusOf($user));
    }

    public function test_a_guest_order_earns_nothing(): void
    {
        $order = $this->order(null);
        $before = LoyaltyAward::count();

        $this->complete($order);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame($before, LoyaltyAward::count());
    }

    public function test_an_unverified_account_earns_nothing_for_a_completed_order(): void
    {
        $user = $this->customer(verified: false);

        $this->complete($this->order($user));

        $this->assertCount(0, $this->awards($user));
        $this->assertSame(0, $this->bonusOf($user));
    }

    public function test_a_deactivated_account_earns_nothing(): void
    {
        $user = $this->customer(overrides: ['is_active' => false]);

        $this->complete($this->order($user));

        $this->assertCount(0, $this->awards($user));
    }

    public function test_placing_or_cancelling_an_order_pays_nothing(): void
    {
        $user = $this->customer();
        $order = $this->order($user, 'pending');
        $this->assertSame([], Loyalty::orderCompleted($order));

        $order->forceFill(['status' => 'cancelled'])->save();
        $this->assertSame([], Loyalty::orderCompleted($order->fresh()));

        $this->assertCount(0, $this->awards($user));
    }

    public function test_a_bonus_switched_off_in_config_is_not_paid(): void
    {
        config(['loyalty.order_bonus_points' => 0, 'loyalty.streak_milestones' => []]);
        $user = $this->customer();

        $this->complete($this->order($user));

        $this->assertCount(0, $this->awards($user));
    }

    // ══════════ streak milestones ══════════

    public function test_the_third_consecutive_day_pays_the_three_day_bonus_once(): void
    {
        $user = $this->customer();
        $this->order($user, 'completed', '2026-10-13 12:00');
        $this->order($user, 'completed', '2026-10-14 12:00');
        $admin = $this->admin();

        $this->complete($this->order($user), $admin);

        $streak = $this->awards($user, LoyaltyAward::TYPE_STREAK);
        $this->assertCount(1, $streak);
        $this->assertSame('3:2026-10-13', $streak[0]->award_key);
        $this->assertSame(10, $streak[0]->points);

        // A second completed order the same day: another order bonus, but the
        // same streak cannot pay the same milestone again.
        $this->complete($this->order($user), $admin);

        $this->assertCount(1, $this->awards($user, LoyaltyAward::TYPE_STREAK));
        $this->assertCount(2, $this->awards($user, LoyaltyAward::TYPE_ORDER_BONUS));
        $this->assertSame(3 + 10 + 3, $this->bonusOf($user));
    }

    public function test_the_next_day_of_the_same_streak_does_not_pay_the_three_day_bonus_again(): void
    {
        $user = $this->customer();
        $this->order($user, 'completed', '2026-10-12 12:00');
        $this->order($user, 'completed', '2026-10-13 12:00');
        $this->order($user, 'completed', '2026-10-14 12:00');
        // Day 3 was paid when it happened.
        Loyalty::award($user, LoyaltyAward::TYPE_STREAK, '3:2026-10-12', 10);

        $this->complete($this->order($user)); // day 4

        $this->assertSame(['3:2026-10-12'], $this->awards($user, LoyaltyAward::TYPE_STREAK)->pluck('award_key')->all());
    }

    public function test_two_days_is_not_a_milestone(): void
    {
        $user = $this->customer();
        $this->order($user, 'completed', '2026-10-14 12:00');

        $this->complete($this->order($user));

        $this->assertCount(0, $this->awards($user, LoyaltyAward::TYPE_STREAK));
    }

    public function test_the_seventh_day_pays_the_seven_day_bonus_and_three_is_not_repeated(): void
    {
        $user = $this->customer();
        foreach (range(9, 14) as $day) {
            $this->order($user, 'completed', "2026-10-{$day} 12:00");
        }
        Loyalty::award($user, LoyaltyAward::TYPE_STREAK, '3:2026-10-09', 10); // paid on the 11th

        $this->complete($this->order($user)); // the 15th: day 7

        $keys = $this->awards($user, LoyaltyAward::TYPE_STREAK)->pluck('award_key')->all();
        $this->assertSame(['3:2026-10-09', '7:2026-10-09'], $keys);
        $this->assertSame(25, $this->awards($user, LoyaltyAward::TYPE_STREAK)->last()->points);
    }

    public function test_a_new_streak_after_a_missed_day_can_earn_the_milestone_again(): void
    {
        $user = $this->customer();
        // Old streak 1-3 Oct, already paid.
        foreach (['01', '02', '03'] as $d) {
            $this->order($user, 'completed', "2026-10-{$d} 12:00");
        }
        Loyalty::award($user, LoyaltyAward::TYPE_STREAK, '3:2026-10-01', 10);
        // New streak 13-15 Oct.
        $this->order($user, 'completed', '2026-10-13 12:00');
        $this->order($user, 'completed', '2026-10-14 12:00');

        $this->complete($this->order($user));

        $this->assertSame(
            ['3:2026-10-01', '3:2026-10-13'],
            $this->awards($user, LoyaltyAward::TYPE_STREAK)->pluck('award_key')->all()
        );
    }

    public function test_cancelled_and_pending_orders_cannot_build_a_streak_for_the_bonus(): void
    {
        $user = $this->customer();
        $this->order($user, 'cancelled', '2026-10-13 12:00');
        $this->order($user, 'pending', '2026-10-14 12:00');

        $this->complete($this->order($user));

        $this->assertCount(0, $this->awards($user, LoyaltyAward::TYPE_STREAK));
    }

    public function test_the_streak_day_is_the_manila_day_not_the_utc_day(): void
    {
        $user = $this->customer();
        // 00:30 Manila on the 14th is 16:30 UTC on the 13th.
        $this->order($user, 'completed', '2026-10-13 00:30');
        $this->order($user, 'completed', '2026-10-14 00:30');

        $this->complete($this->order($user));

        $this->assertSame(['3:2026-10-13'], $this->awards($user, LoyaltyAward::TYPE_STREAK)->pluck('award_key')->all());
    }

    // ══════════ the ledger is the guard ══════════

    /**
     * The losing side of a race, deterministically: the row is already there
     * (another request committed it first). award() must refuse on the unique
     * key — it does no "already paid?" read that a race could slip past — and
     * must not credit the points.
     */
    public function test_an_award_whose_key_is_already_in_the_ledger_pays_nothing(): void
    {
        $user = $this->customer();
        LoyaltyAward::create([
            'user_id' => $user->id, 'type' => LoyaltyAward::TYPE_STREAK,
            'award_key' => '3:2026-10-13', 'points' => 10,
        ]);

        $this->assertFalse(Loyalty::award($user, LoyaltyAward::TYPE_STREAK, '3:2026-10-13', 10));
        $this->assertSame(0, $this->bonusOf($user), 'points were credited for a duplicate');
        $this->assertCount(1, $this->awards($user));
    }

    public function test_the_same_key_for_a_different_customer_or_type_is_a_different_award(): void
    {
        $a = $this->customer();
        $b = $this->customer();

        $this->assertTrue(Loyalty::award($a, LoyaltyAward::TYPE_ORDER_BONUS, '42', 3));
        $this->assertTrue(Loyalty::award($b, LoyaltyAward::TYPE_ORDER_BONUS, '42', 3));
        $this->assertTrue(Loyalty::award($a, LoyaltyAward::TYPE_STREAK, '42', 3));
        $this->assertFalse(Loyalty::award($a, LoyaltyAward::TYPE_ORDER_BONUS, '42', 3));

        $this->assertSame(6, $this->bonusOf($a));
        $this->assertSame(3, $this->bonusOf($b));
    }

    public function test_the_unique_constraint_exists_in_the_schema(): void
    {
        $index = collect(DB::select("SHOW INDEX FROM loyalty_awards WHERE Key_name = 'loyalty_awards_user_type_key_unique'"));

        $this->assertSame(['user_id', 'type', 'award_key'], $index->sortBy('Seq_in_index')->pluck('Column_name')->values()->all());
        $this->assertSame(0, (int) $index->first()->Non_unique);
    }

    // ══════════ welcome bonus ══════════

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'customer.email-verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );
    }

    public function test_verifying_the_email_pays_the_welcome_bonus(): void
    {
        $user = $this->customer(verified: false);

        $this->get($this->verificationUrl($user))->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
        $welcome = $this->awards($user, LoyaltyAward::TYPE_WELCOME);
        $this->assertCount(1, $welcome);
        $this->assertSame(10, $welcome[0]->points);
        $this->assertSame(10, $this->bonusOf($user));
    }

    public function test_clicking_the_verification_link_again_does_not_pay_twice(): void
    {
        $user = $this->customer(verified: false);
        $url = $this->verificationUrl($user);

        $this->get($url)->assertRedirect();
        $this->get($url)->assertRedirect();
        Loyalty::welcome($user->fresh());

        $this->assertCount(1, $this->awards($user, LoyaltyAward::TYPE_WELCOME));
        $this->assertSame(10, $this->bonusOf($user));
    }

    public function test_no_welcome_bonus_before_the_email_is_verified(): void
    {
        $user = $this->customer(verified: false);

        $this->assertFalse(Loyalty::welcome($user));
        $this->assertCount(0, $this->awards($user));
    }

    public function test_a_tampered_verification_link_pays_nothing(): void
    {
        $user = $this->customer(verified: false);
        $url = URL::temporarySignedRoute(
            'customer.email-verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('someone-else@example.test')]
        );

        $this->get($url)->assertForbidden();

        $this->assertCount(0, $this->awards($user));
    }

    public function test_portal_accounts_never_get_customer_bonuses(): void
    {
        $staff = $this->customer(overrides: ['role' => 'staff', 'branch_id' => 1]);

        $this->assertFalse(Loyalty::welcome($staff));
        $this->complete($this->order($staff));

        $this->assertCount(0, $this->awards($staff));
    }

    public function test_there_is_no_route_a_customer_can_call_to_claim_a_bonus(): void
    {
        // admin.streak.toggle is the owner-only Streak Calendar master switch
        // (role:admin, pays nothing) — its tiering is pinned by
        // RolePermissionMatrixTest. The Streak Rewards routes (Oct 2026,
        // moved to their own page the same month) are admin-guard too
        // (role:admin,supervisor) and pay nothing either: the page itself,
        // saving a branch's or "all branches" settings, dropping a branch's
        // own settings, a branch's on/off, and the read-only code check —
        // pinned by StreakRewardsTest and StreakRewardsPerBranchTest. These
        // are the only routes allowed to say "streak".
        $staffOnly = [
            'admin.streak.toggle', 'admin.streak-rewards', 'admin.streak.rewards.update',
            'admin.streak.rewards.default', 'admin.streak.branch.toggle', 'admin.streak.rewards.check',
        ];

        $uris = collect(app('router')->getRoutes()->getRoutes())
            ->reject(fn ($r) => in_array($r->getName(), $staffOnly, true))
            ->map(fn ($r) => $r->uri() . ' ' . ($r->getActionName()))
            ->filter(fn ($s) => str_contains(strtolower($s), 'loyalty') || str_contains(strtolower($s), 'streak') || str_contains(strtolower($s), 'bonus'));

        $this->assertSame([], $uris->values()->all());
    }

    // ══════════ the wheel counts bonus points (the one rule change) ══════════

    private function readyToSpin(User $user): void
    {
        Cache::flush();
        DB::table('settings')->where('key', 'game_enabled')->delete();
        DB::table('settings')->insert([
            'key' => 'game_enabled', 'branch_id' => null, 'value' => '1', 'group' => 'business',
            'label' => 'Spin & Win Enabled', 'type' => 'text', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Only this test's prize can be won. Rolled back afterwards.
        Voucher::where('points_required', '>', 0)->update(['is_active' => false]);
        $this->order($user, 'pending');
    }

    private function wheelVoucher(int $pointsRequired): Voucher
    {
        return Voucher::create([
            'branch_id' => null, 'code' => 'LOYW' . strtoupper(Str::random(6)), 'description' => 'Loyalty wheel test',
            'discount_type' => 'fixed', 'discount_value' => 10, 'max_uses' => 100, 'used_count' => 0,
            'minimum_order' => 0, 'expires_at' => now()->addMonth(), 'is_active' => true,
            'points_required' => $pointsRequired,
        ]);
    }

    public function test_bonus_points_count_toward_a_wheel_voucher_and_spin_points_are_spent_first(): void
    {
        $user = $this->customer();
        $user->forceFill(['points' => 0, 'bonus_points' => 30])->save();
        $this->readyToSpin($user);
        $voucher = $this->wheelVoucher(20);
        $this->forceSpinOutcome(3);

        $this->actingAs($user, 'customer')
            ->postJson('/customer/add-points')
            ->assertOk()
            ->assertJsonPath('voucher.code', $voucher->code)
            // 3 spun + 30 bonus - 20 cost
            ->assertJsonPath('total_points', 13);

        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->points, 'the 3 spin points should have been spent first');
        $this->assertSame(13, (int) $fresh->bonus_points);
        $this->assertTrue(UserVoucher::where('user_id', $user->id)->where('voucher_id', $voucher->id)->exists());
    }

    public function test_without_a_voucher_in_reach_the_total_shown_is_spin_plus_bonus(): void
    {
        $user = $this->customer();
        $user->forceFill(['points' => 0, 'bonus_points' => 4])->save();
        $this->readyToSpin($user);
        $this->wheelVoucher(500);
        $this->forceSpinOutcome(5);

        $this->actingAs($user, 'customer')
            ->postJson('/customer/add-points')
            ->assertOk()
            ->assertJsonPath('voucher', null)
            ->assertJsonPath('total_points', 9);

        $this->assertSame(5, (int) $user->fresh()->points);
        $this->assertSame(4, (int) $user->fresh()->bonus_points);
    }

    public function test_a_guest_spin_result_carries_the_signup_teaser_and_a_registered_one_does_not(): void
    {
        $user = $this->customer();
        $this->readyToSpin($user);
        $this->forceSpinOutcome(3);

        $this->actingAs($user, 'customer')
            ->postJson('/customer/add-points')
            ->assertOk()
            ->assertJsonMissingPath('signup_teaser');
    }

    public function test_a_guest_spin_result_carries_the_signup_teaser_built_from_config(): void
    {
        $user = $this->customer();
        $this->readyToSpin($user); // switch + vouchers; the order made here is not the guest's
        $guestOrder = $this->order(null, 'pending');
        \App\Support\GuestOrders::remember($guestOrder->id);
        $this->forceSpinOutcome(3);

        $this->postJson('/customer/add-points')
            ->assertOk()
            ->assertJsonPath('signup_teaser', 'Create a free account for 10 welcome points, +3 points on every completed order and streak bonuses at 3 days and 7 days.');

        config(['loyalty.welcome_bonus_points' => 0, 'loyalty.order_bonus_points' => 0, 'loyalty.streak_milestones' => []]);
        $this->assertNull(Loyalty::guestTeaser(), 'a teaser with every bonus switched off promises nothing');
    }
}

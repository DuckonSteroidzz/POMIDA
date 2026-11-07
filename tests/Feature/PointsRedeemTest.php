<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use App\Services\VoucherClaims;
use App\Support\GuestOrders;
use App\Support\GuestVoucherClaims;
use App\Support\ShopDay;
use App\Support\WheelPrizes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ForcesSpinOutcome;
use Tests\TestCase;

/**
 * Spin & Win points are redeemed, not won (QA, Nov 5 2026).
 *
 * QA: "I only won 1 promo; when can I use the rest of my points? If Peachy adds
 * another promo, customers who already have a lot of points shouldn't need to
 * play again to claim it." A spin now only earns points; the customer presses
 * Redeem to trade them for a voucher, whenever they like.
 *
 * No point amount, spin count, daily limit, balance or voucher term changes.
 */
class PointsRedeemTest extends TestCase
{
    use DatabaseTransactions;
    use ForcesSpinOutcome;

    private const HOME = 1;
    private const FAR = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());

        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();

        DB::table('settings')->where('key', 'game_enabled')->delete();
        DB::table('settings')->insert([
            'key' => 'game_enabled', 'branch_id' => null, 'value' => '1', 'group' => 'business',
            'label' => 'Spin & Win Enabled', 'type' => 'text', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Only this file's prizes are points rewards. Rolled back afterwards.
        Voucher::where('points_required', '>', 0)->update(['is_active' => false]);
    }

    // ══════════ fixtures ══════════

    private function customer(int $points = 0, int $bonus = 0): User
    {
        $user = User::create([
            'name'              => 'Redeem Tester',
            'email'             => 'redeem-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);

        $user->forceFill(['points' => $points, 'bonus_points' => $bonus])->save();

        return $user->fresh();
    }

    private function voucher(int $points, array $extra = []): Voucher
    {
        return Voucher::create(array_merge([
            'branch_id' => null, 'code' => 'PRD' . strtoupper(Str::random(8)), 'description' => 'Redeem desc',
            'discount_type' => 'fixed', 'discount_value' => 50, 'max_uses' => 100, 'used_count' => 0,
            'minimum_order' => 0, 'expires_at' => now()->addMonth(), 'is_active' => true,
            'points_required' => $points,
        ], $extra));
    }

    private function claim(User $user, Voucher $voucher, bool $used = false): UserVoucher
    {
        return UserVoucher::create([
            'user_id' => $user->id, 'voucher_id' => $voucher->id, 'claim_code' => VoucherClaims::mintCode(),
            'acquired_date' => now()->toDateString(), 'valid_from' => now()->toDateString(),
            'is_used' => $used, 'used_at' => $used ? now() : null,
        ]);
    }

    private function order(?int $userId, int $branchId = self::HOME): Order
    {
        return Order::create([
            'order_number' => 'PRD-' . substr(uniqid(), -8), 'user_id' => $userId, 'branch_id' => $branchId,
            'type' => 'pick_up', 'status' => 'pending', 'payment_method' => 'cash', 'payment_status' => 'pending',
            'subtotal' => 100, 'total' => 100,
        ]);
    }

    /** Redeem as $user (a stale copy stands for a tab opened earlier). */
    private function redeem(User $user, Voucher $voucher, int $branchId = self::HOME)
    {
        return $this->actingAs($user, 'customer')
            ->withSession(['branch_id' => $branchId])
            ->postJson('/customer/points/redeem', ['voucher_id' => $voucher->id]);
    }

    private function spin(User $user, int $forcedPoints, int $branchId = self::HOME)
    {
        $this->forceSpinOutcome($forcedPoints);

        return $this->actingAs($user, 'customer')
            ->withSession(['branch_id' => $branchId])
            ->postJson('/customer/add-points')
            ->assertOk();
    }

    /** @return array{0: int, 1: int} [spin points, bonus points] */
    private function balances(User $user): array
    {
        $row = DB::table('users')->where('id', $user->id)->first(['points', 'bonus_points']);

        return [(int) $row->points, (int) $row->bonus_points];
    }

    private function claimsOf(User $user): int
    {
        return UserVoucher::where('user_id', $user->id)->count();
    }

    // ══════════ a spin only earns ══════════

    public function test_a_spin_with_enough_points_for_a_voucher_never_deducts_or_awards_one(): void
    {
        $this->voucher(25);
        $user = $this->customer(100);
        $this->order($user->id);

        $tryAgain = $this->spin($user->fresh(), 0)->json();
        $this->assertSame([100, 0], $this->balances($user));
        $this->assertArrayNotHasKey('voucher', $tryAgain);
        $this->assertSame(100, $tryAgain['total_points']);

        $eight = $this->spin($user->fresh(), 8)->json();
        $this->assertSame([108, 0], $this->balances($user));
        $this->assertArrayNotHasKey('voucher', $eight);
        $this->assertSame(108, $eight['total_points']);

        $this->assertSame(0, $this->claimsOf($user));
    }

    public function test_a_guest_spin_never_deducts_or_awards_either(): void
    {
        $this->voucher(3);
        GuestOrders::remember($this->order(null)->id);
        $this->forceSpinOutcome(8);

        $json = $this->withSession(['branch_id' => self::HOME, 'guest_points' => 40])
            ->postJson('/customer/add-points')->assertOk()->json();

        $this->assertSame(48, $json['total_points']);
        $this->assertSame(48, session('guest_points'));
        $this->assertArrayNotHasKey('voucher', $json);
        $this->assertSame([], GuestVoucherClaims::ids());
    }

    public function test_after_a_spin_the_reply_names_what_the_points_can_already_redeem(): void
    {
        $this->voucher(25, ['discount_value' => 50]);
        $this->voucher(500);
        $user = $this->customer(20);
        $this->order($user->id);

        $json = $this->spin($user->fresh(), 5)->json();

        $this->assertSame('₱50 OFF', $json['can_redeem']);
        $this->assertSame(WheelPrizes::REDEEMABLE, collect($json['prizes'])->firstWhere('points', 25)['status']);

        $page = file_get_contents(resource_path('views/customer/game.blade.php'));
        $this->assertStringContainsString("'You have enough points to redeem ' + esc(data.can_redeem) + '.'", $page);
    }

    public function test_no_redeem_hint_when_the_points_cover_nothing(): void
    {
        $this->voucher(25);
        $user = $this->customer(10);
        $this->order($user->id);

        $this->assertNull($this->spin($user->fresh(), 3)->json('can_redeem'));
    }

    public function test_a_spin_cannot_bring_back_points_another_tab_just_spent(): void
    {
        $prize = $this->voucher(25);
        $user = $this->customer(30);
        $this->order($user->id);
        $oldTab = $user->fresh(); // loaded while the balance was still 30

        $this->redeem($user->fresh(), $prize)->assertOk();
        $this->assertSame([5, 0], $this->balances($user));

        // The old `$user->points += 3; save()` wrote 30 + 3 back over the spend.
        $this->spin($oldTab, 3);

        $this->assertSame([8, 0], $this->balances($user));
    }

    // ══════════ redeem ══════════

    public function test_redeem_with_enough_points_deducts_exactly_and_issues_the_voucher(): void
    {
        $prize = $this->voucher(25, ['discount_value' => 50]);
        $user = $this->customer(40);

        $json = $this->redeem($user->fresh(), $prize)->assertOk()->json();

        $this->assertSame([15, 0], $this->balances($user));
        $this->assertSame(15, $json['total_points']);
        $this->assertSame(25, $json['voucher']['points_spent']);
        $this->assertSame('₱50 OFF', $json['voucher']['name']);

        $claim = UserVoucher::where('user_id', $user->id)->sole();
        $this->assertSame($prize->id, (int) $claim->voucher_id);
        $this->assertFalse((bool) $claim->is_used);
        $this->assertSame(ShopDay::todayString(), $claim->valid_from->toDateString());
        $this->assertSame(ShopDay::todayString(), $claim->acquired_date->toDateString());
        $this->assertSame(VoucherClaims::display($claim->claim_code), $json['voucher']['claim_code']);
        $this->assertSame(WheelPrizes::ALREADY_WON, collect($json['prizes'])->firstWhere('id', $prize->id)['status']);
    }

    public function test_spin_points_are_spent_first_then_bonus_points(): void
    {
        $prize = $this->voucher(25);
        $user = $this->customer(10, 30);

        $this->redeem($user->fresh(), $prize)->assertOk();

        $this->assertSame([0, 15], $this->balances($user));
    }

    public function test_exactly_enough_points_is_enough(): void
    {
        $prize = $this->voucher(25);
        $user = $this->customer(20, 5);

        $this->redeem($user->fresh(), $prize)->assertOk()->assertJsonPath('total_points', 0);
        $this->assertSame([0, 0], $this->balances($user));
    }

    public function test_a_redeemed_voucher_is_usable_at_checkout_like_a_won_one(): void
    {
        $prize = $this->voucher(25, ['discount_value' => 30, 'minimum_order' => 0]);
        $user = $this->customer(25);
        $code = $this->redeem($user->fresh(), $prize)->assertOk()->json('voucher.claim_code');

        $this->actingAs($user->fresh(), 'customer')->withSession(['branch_id' => self::HOME])
            ->postJson('/customer/apply-voucher', ['code' => $code, 'subtotal' => 200])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_a_guest_can_redeem_and_keeps_the_code_in_the_session(): void
    {
        $prize = $this->voucher(25);

        $json = $this->withSession(['branch_id' => self::HOME, 'guest_points' => 30])
            ->postJson('/customer/points/redeem', ['voucher_id' => $prize->id])
            ->assertOk()->json();

        $claim = UserVoucher::whereKey(GuestVoucherClaims::ids()[0])->sole();
        $this->assertNull($claim->user_id);
        $this->assertSame($prize->id, (int) $claim->voucher_id);
        $this->assertSame(VoucherClaims::display($claim->claim_code), $json['voucher']['claim_code']);
        $this->assertSame(5, session('guest_points'));
        $this->assertSame(5, $json['total_points']);

        // Shown again, pinned, on the next visit to Spin & Win.
        $this->get('/customer/game')->assertOk()->assertSee($json['voucher']['claim_code']);

        // And once per guest session, like an account.
        $this->withSession(['guest_points' => 100])
            ->postJson('/customer/points/redeem', ['voucher_id' => $prize->id])
            ->assertStatus(422)->assertJsonPath('status', WheelPrizes::ALREADY_WON);
        $this->assertCount(1, GuestVoucherClaims::ids());
    }

    public function test_a_guest_without_enough_points_is_refused_and_keeps_them(): void
    {
        $prize = $this->voucher(25);

        $this->withSession(['branch_id' => self::HOME, 'guest_points' => 24])
            ->postJson('/customer/points/redeem', ['voucher_id' => $prize->id])
            ->assertStatus(422)
            ->assertJsonPath('status', WheelPrizes::NEED_POINTS)
            ->assertJsonPath('message', 'You need 1 more point for this voucher.');

        $this->assertSame(24, session('guest_points'));
        $this->assertSame([], GuestVoucherClaims::ids());
    }

    public function test_a_guest_holding_two_unused_vouchers_must_use_one_first(): void
    {
        GuestVoucherClaims::remember(VoucherClaims::mintForGuest($this->voucher(3)));
        GuestVoucherClaims::remember(VoucherClaims::mintForGuest($this->voucher(3)));
        $third = $this->voucher(5);

        $this->withSession(['branch_id' => self::HOME, 'guest_points' => 50])
            ->postJson('/customer/points/redeem', ['voucher_id' => $third->id])
            ->assertStatus(422)->assertJsonPath('status', WheelPrizes::HOLDING_MAX);

        $this->assertSame(50, session('guest_points'));
    }

    // ══════════ every refusal leaves points and vouchers untouched ══════════

    /** @return array<string, array{0: callable, 1: string, 2: string}> */
    public static function refusals(): array
    {
        return [
            'not enough points' => [
                fn (self $t, User $u) => $t->voucher(60),
                WheelPrizes::NEED_POINTS, 'You need 10 more points for this voucher.',
            ],
            'already redeemed and used' => [
                function (self $t, User $u) { $v = $t->voucher(10); $t->claim($u, $v, true); return $v; },
                WheelPrizes::ALREADY_WON, 'You have already redeemed this voucher.',
            ],
            'already redeemed, unused' => [
                function (self $t, User $u) { $v = $t->voucher(10); $t->claim($u, $v); return $v; },
                WheelPrizes::ALREADY_WON, 'You have already redeemed this voucher.',
            ],
            'other branch' => [
                fn (self $t, User $u) => $t->voucher(10, ['branch_id' => self::FAR]),
                WheelPrizes::OTHER_BRANCH, 'This voucher is not available at this branch.',
            ],
            'not started' => [
                fn (self $t, User $u) => $t->voucher(10, ['valid_from' => '2099-03-09']),
                WheelPrizes::NOT_STARTED, 'This voucher starts on Mar 09, 2099.',
            ],
            'sold out' => [
                fn (self $t, User $u) => $t->voucher(10, ['max_uses' => 3, 'used_count' => 3]),
                WheelPrizes::SOLD_OUT, 'This voucher is sold out.',
            ],
            'holding two unused' => [
                function (self $t, User $u) { $t->claim($u, $t->voucher(10)); $t->claim($u, $t->voucher(10)); return $t->voucher(10); },
                WheelPrizes::HOLDING_MAX, 'You already have 2 unused vouchers. Use one first, then redeem again.',
            ],
            'inactive' => [
                fn (self $t, User $u) => $t->voucher(10, ['is_active' => false]),
                WheelPrizes::NOT_OFFERED, "This voucher can't be redeemed with points right now.",
            ],
            'expired' => [
                fn (self $t, User $u) => $t->voucher(10, ['expires_at' => now()->subDays(2)]),
                WheelPrizes::NOT_OFFERED, "This voucher can't be redeemed with points right now.",
            ],
            'not a points reward' => [
                fn (self $t, User $u) => $t->voucher(10, ['on_wheel' => false]),
                WheelPrizes::NOT_OFFERED, "This voucher can't be redeemed with points right now.",
            ],
            'a 0-point direct code' => [
                fn (self $t, User $u) => $t->voucher(0),
                WheelPrizes::NOT_OFFERED, "This voucher can't be redeemed with points right now.",
            ],
        ];
    }

    /** @dataProvider refusals */
    public function test_redeem_is_refused_and_nothing_changes(callable $make, string $status, string $message): void
    {
        $user = $this->customer(30, 20);
        $voucher = $make($this, $user);
        $claimsBefore = $this->claimsOf($user);

        $this->redeem($user->fresh(), $voucher)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', $status)
            ->assertJsonPath('message', $message)
            ->assertJsonPath('total_points', 50);

        $this->assertSame([30, 20], $this->balances($user));
        $this->assertSame($claimsBefore, $this->claimsOf($user));
    }

    public function test_an_unknown_or_missing_voucher_id_is_refused(): void
    {
        $user = $this->customer(100);

        $this->actingAs($user, 'customer')->postJson('/customer/points/redeem', ['voucher_id' => 999999999])
            ->assertStatus(422)->assertJsonPath('status', WheelPrizes::NOT_OFFERED);
        $this->actingAs($user, 'customer')->postJson('/customer/points/redeem', [])
            ->assertStatus(422)->assertJsonValidationErrors('voucher_id');
        $this->actingAs($user, 'customer')->postJson('/customer/points/redeem', ['voucher_id' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors('voucher_id');

        $this->assertSame([100, 0], $this->balances($user));
    }

    // ══════════ double clicks and two tabs ══════════

    public function test_a_double_submit_issues_one_voucher_and_takes_the_points_once(): void
    {
        $prize = $this->voucher(25);
        $user = $this->customer(60);

        $this->redeem($user->fresh(), $prize)->assertOk();
        $this->redeem($user->fresh(), $prize)->assertStatus(422)->assertJsonPath('status', WheelPrizes::ALREADY_WON);

        $this->assertSame([35, 0], $this->balances($user));
        $this->assertSame(1, UserVoucher::where('user_id', $user->id)->where('voucher_id', $prize->id)->count());
    }

    public function test_two_tabs_loaded_on_the_same_balance_cannot_spend_it_twice(): void
    {
        $first = $this->voucher(25);
        $second = $this->voucher(25);
        $user = $this->customer(30);
        $tabA = $user->fresh();
        $tabB = $user->fresh(); // both believe the balance is 30

        $this->redeem($tabA, $first)->assertOk();
        $this->redeem($tabB, $second)
            ->assertStatus(422)
            ->assertJsonPath('status', WheelPrizes::NEED_POINTS)
            ->assertJsonPath('total_points', 5);
        $this->redeem($tabB, $first)->assertStatus(422)->assertJsonPath('status', WheelPrizes::ALREADY_WON);

        $this->assertSame([5, 0], $this->balances($user));
        $this->assertSame(1, $this->claimsOf($user));
    }

    public function test_the_redeem_reads_the_balance_under_a_row_lock_inside_one_transaction(): void
    {
        $prize = $this->voucher(25);
        $user = $this->customer(30);
        $seen = [];

        DB::connection()->beforeExecuting(function (string $sql) use (&$seen) {
            $seen[] = [strtolower($sql), DB::transactionLevel()];
        });

        $this->redeem($user->fresh(), $prize)->assertOk();

        $userLock = collect($seen)->search(fn ($q) => str_contains($q[0], 'from `users`') && str_ends_with($q[0], 'for update'));
        $insert = collect($seen)->search(fn ($q) => str_starts_with($q[0], 'insert into `user_vouchers`'));
        $deduct = collect($seen)->search(fn ($q) => str_starts_with($q[0], 'update `users`'));

        $this->assertNotFalse($userLock, 'the users row is locked');
        $this->assertNotFalse($insert);
        $this->assertNotFalse($deduct);
        $this->assertLessThan($deduct, $userLock, 'locked before it is spent');
        $this->assertLessThan($insert, $userLock, 'locked before the voucher is issued');

        // All three run inside the redeem's own transaction (one level above
        // the test's DatabaseTransactions wrapper).
        $outer = $seen[$userLock][1];
        $this->assertGreaterThanOrEqual(2, $outer);
        $this->assertSame($outer, $seen[$insert][1]);
        $this->assertSame($outer, $seen[$deduct][1]);
    }

    public function test_redeem_and_spin_routes_hold_the_session_lock_so_a_guest_cannot_double_spend(): void
    {
        // A guest's balance is in the session, so two tabs are only safe if
        // their requests cannot overlap: Laravel's session blocking.
        foreach (['customer.points.redeem', 'customer.add-points'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route->locksFor(), "{$name} blocks on the session");
            $this->assertNotNull($route->waitsFor(), "{$name} waits for the session");
        }
    }

    public function test_the_database_also_refuses_a_second_claim_on_the_same_voucher(): void
    {
        $prize = $this->voucher(25);
        $user = $this->customer(100);
        $this->claim($user, $prize);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->claim($user, $prize);
    }

    // ══════════ nothing old changes ══════════

    public function test_existing_balances_and_won_vouchers_are_untouched_by_the_new_logic(): void
    {
        $won = $this->voucher(25);
        $this->voucher(30); // affordable, but nothing may take it without a press
        $user = $this->customer(70, 9);
        $old = $this->claim($user, $won);
        $before = $old->fresh()->only(['id', 'voucher_id', 'claim_code', 'valid_from', 'is_used', 'used_at']);

        $this->actingAs($user->fresh(), 'customer')->withSession(['branch_id' => self::HOME])
            ->get('/customer/game')->assertOk();
        $this->actingAs($user->fresh(), 'customer')->withSession(['branch_id' => self::HOME])
            ->get('/customer/vouchers')->assertOk()->assertSee(VoucherClaims::display($old->claim_code));

        $this->assertSame([70, 9], $this->balances($user));
        $this->assertSame(1, $this->claimsOf($user));
        $this->assertEquals($before, $old->fresh()->only(array_keys($before)));
    }

    public function test_redeem_works_while_the_spin_game_is_switched_off(): void
    {
        // Points already earned stay the customer's; the switch stops spinning.
        DB::table('settings')->where('key', 'game_enabled')->update(['value' => '0']);
        $prize = $this->voucher(25);
        $user = $this->customer(25);

        $this->redeem($user->fresh(), $prize)->assertOk();
        $this->assertSame([0, 0], $this->balances($user));
    }

    // ══════════ the pages ══════════

    public function test_spin_and_win_shows_redeem_your_points_with_a_button_only_where_it_can_be_redeemed(): void
    {
        $ready = $this->voucher(25, ['discount_value' => 50]);
        $dear = $this->voucher(60);
        $user = $this->customer(30);

        $html = $this->actingAs($user->fresh(), 'customer')->withSession(['branch_id' => self::HOME])
            ->get('/customer/game')->assertOk()->getContent();

        $this->assertStringContainsString('Redeem your points', $html);
        $this->assertStringContainsString('Spin to earn points. Redeem your points for a voucher anytime.', $html);
        $this->assertStringNotContainsString('What you can win', $html);
        $this->assertStringNotContainsString('your next spin wins it', $html);
        $this->assertMatchesRegularExpression('/data-redeem-id="' . $ready->id . '"\s*>\s*<i class="bi bi-gift"><\/i> Redeem for 25 pts/', $html);
        $this->assertMatchesRegularExpression('/data-redeem-id="' . $dear->id . '"\s*hidden/', $html);
        $this->assertStringContainsString('Need 30 more points', $html);
        $this->assertStringContainsString('data-prize-name="₱50 OFF"', $html);
        $this->assertStringContainsString("'Use ' + row.getAttribute('data-prize-cost') + ' pts for ' + row.getAttribute('data-prize-name') + '?'", $html);
    }

    public function test_the_spin_result_no_longer_talks_about_winning_a_voucher(): void
    {
        $page = file_get_contents(resource_path('views/customer/game.blade.php'));

        $this->assertStringNotContainsString('You won a voucher', $page);
        $this->assertStringNotContainsString('data.voucher', $page);
        $this->assertStringNotContainsString('more pts to win', $page);
    }

    public function test_vouchers_and_rewards_shows_the_same_list(): void
    {
        $ready = $this->voucher(25);
        $user = $this->customer(30);

        $html = $this->actingAs($user->fresh(), 'customer')->withSession(['branch_id' => self::HOME])
            ->get('/customer/vouchers')->assertOk()->getContent();

        $this->assertStringContainsString('Redeem your points', $html);
        $this->assertStringContainsString('data-redeem-id="' . $ready->id . '"', $html);
        $this->assertStringContainsString('data-points-balance', $html);
    }

    public function test_a_guest_sees_the_list_and_their_balance_on_spin_and_win(): void
    {
        $ready = $this->voucher(25);

        $html = $this->withSession(['branch_id' => self::HOME, 'guest_points' => 27])
            ->get('/customer/game')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-redeem-id="' . $ready->id . '"\s*>/', $html);
        $this->assertMatchesRegularExpression('/data-points-balance>27</', $html);
    }

    public function test_the_list_shows_even_when_the_game_is_switched_off(): void
    {
        DB::table('settings')->where('key', 'game_enabled')->update(['value' => '0']);
        $this->voucher(25);

        $this->actingAs($this->customer(30), 'customer')->withSession(['branch_id' => self::HOME])
            ->get('/customer/game')->assertOk()
            ->assertSee('Game is currently unavailable')
            ->assertSee('Redeem your points');
    }
}

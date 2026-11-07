<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use App\Services\VoucherClaims;
use App\Support\GuestOrders;
use App\Support\GuestVoucherClaims;
use App\Support\WheelPrizes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ForcesSpinOutcome;
use Tests\TestCase;

/**
 * Spin & Win: what each points reward means for this customer, and that the
 * list and the server are one rule (QA, Nov 2 2026).
 *
 * The bugs pinned here, each reproduced in a browser first: a voucher won on a
 * Try Again spin showed "Better luck next time" while the points were spent; the
 * list kept offering a prize already won and used; the "X more pts" hint named
 * one too; a prize was awarded before its start date; and two unused claims on
 * expired vouchers blocked every future prize for good.
 *
 * Nov 5 2026: a spin no longer awards anything. The tests that pinned the
 * spin's award now pin the Redeem button (customer.points.redeem), which reads
 * the same statuses. See also PointsRedeemTest.
 *
 * No amount, limit or voucher term is changed by any of this.
 */
class WheelPrizeStatusTest extends TestCase
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

        // Only this file's prizes are on the wheel. Rolled back afterwards.
        Voucher::where('points_required', '>', 0)->update(['is_active' => false]);
    }

    private function customer(int $points = 0, int $bonus = 0): User
    {
        $user = User::create([
            'name'              => 'Prize Tester',
            'email'             => 'prize-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);

        // forceFill: neither balance is mass-assignable.
        $user->forceFill(['points' => $points, 'bonus_points' => $bonus])->save();

        return $user->fresh();
    }

    private function voucher(int $points, array $extra = []): Voucher
    {
        return Voucher::create(array_merge([
            'branch_id' => null, 'code' => 'WPS' . strtoupper(Str::random(8)), 'description' => 'Prize desc ' . Str::random(6),
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
            'order_number' => 'WPS-' . substr(uniqid(), -8), 'user_id' => $userId, 'branch_id' => $branchId,
            'type' => 'pick_up', 'status' => 'pending', 'payment_method' => 'cash', 'payment_status' => 'pending',
            'subtotal' => 100, 'total' => 100,
        ]);
    }

    /** @return array<string, array{status: string, label: string}> keyed by voucher code */
    private function listed(User $user, int $branchId = self::HOME): array
    {
        $prizes = $this->actingAs($user->fresh(), 'customer')
            ->withSession(['branch_id' => $branchId])
            ->get('/customer/game')
            ->assertOk()
            ->viewData('wheelPrizes');

        return collect($prizes)
            ->mapWithKeys(fn ($p) => [$p['voucher']->code => ['status' => $p['status'], 'label' => $p['label']]])
            ->all();
    }

    private function spin(User $user, int $forcedPoints, int $branchId = self::HOME): \Illuminate\Testing\TestResponse
    {
        $this->forceSpinOutcome($forcedPoints);

        return $this->actingAs($user->fresh(), 'customer')
            ->withSession(['branch_id' => $branchId])
            ->postJson('/customer/add-points')
            ->assertOk();
    }

    private function redeem(User $user, Voucher $voucher, int $branchId = self::HOME): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user->fresh(), 'customer')
            ->withSession(['branch_id' => $branchId])
            ->postJson('/customer/points/redeem', ['voucher_id' => $voucher->id]);
    }

    private function balance(User $user): int
    {
        return $user->fresh()->wheelPoints();
    }

    // ══════════ each status, for an account ══════════

    public function test_ready_to_redeem(): void
    {
        $v = $this->voucher(25);

        $this->assertSame(
            ['status' => WheelPrizes::REDEEMABLE, 'label' => 'Ready to redeem'],
            $this->listed($this->customer(25))[$v->code]
        );
    }

    public function test_exactly_enough_points_is_enough_and_bonus_points_count(): void
    {
        $v = $this->voucher(25);

        $this->assertSame(WheelPrizes::REDEEMABLE, $this->listed($this->customer(10, 15))[$v->code]['status']);
    }

    public function test_need_more_points_says_how_many_and_reads_right_for_one(): void
    {
        $ten = $this->voucher(40);
        $one = $this->voucher(31);
        $listed = $this->listed($this->customer(30));

        $this->assertSame(['status' => WheelPrizes::NEED_POINTS, 'label' => 'Need 10 more points'], $listed[$ten->code]);
        $this->assertSame(['status' => WheelPrizes::NEED_POINTS, 'label' => 'Need 1 more point'], $listed[$one->code]);
    }

    public function test_already_won_whether_the_voucher_was_used_or_not(): void
    {
        $user = $this->customer(100);
        $used = $this->voucher(10);
        $held = $this->voucher(10);
        $this->claim($user, $used, true);
        $this->claim($user, $held);

        $listed = $this->listed($user);

        $this->assertSame(['status' => WheelPrizes::ALREADY_WON, 'label' => 'Already won'], $listed[$used->code]);
        $this->assertSame(['status' => WheelPrizes::ALREADY_WON, 'label' => 'Already won'], $listed[$held->code]);
    }

    public function test_qa_report_a_prize_won_and_used_no_longer_reads_as_on_offer(): void
    {
        // "Your Points 50 pts ... 1231 — ₱50.00 off, 25 pts" after winning and using it.
        $user = $this->customer(50);
        $prize = $this->voucher(25, ['code' => 'QA1231', 'description' => null]);
        $this->claim($user, $prize, true);

        $html = $this->actingAs($user->fresh(), 'customer')->withSession(['branch_id' => self::HOME])
            ->get('/customer/game')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/QA1231.*?data-prize-status\s*>Already won</s', $html);
        $this->assertDoesNotMatchRegularExpression('/data-redeem-id="' . $prize->id . '"\s*>/', $html, 'no Redeem button');
    }

    public function test_not_available_at_this_branch(): void
    {
        $far = $this->voucher(10, ['branch_id' => self::FAR]);
        $home = $this->voucher(10, ['branch_id' => self::HOME]);
        $listed = $this->listed($this->customer(100));

        $this->assertSame(['status' => WheelPrizes::OTHER_BRANCH, 'label' => 'Not available at this branch'], $listed[$far->code]);
        $this->assertSame(WheelPrizes::REDEEMABLE, $listed[$home->code]['status']);
    }

    public function test_a_visitor_with_no_branch_can_redeem_only_global_rewards(): void
    {
        $scoped = $this->voucher(10, ['branch_id' => self::HOME]);
        $global = $this->voucher(10);

        $prizes = WheelPrizes::evaluate(100, [], 0, null);
        $byCode = collect($prizes)->mapWithKeys(fn ($p) => [$p['voucher']->code => $p['status']]);

        $this->assertSame(WheelPrizes::OTHER_BRANCH, $byCode[$scoped->code]);
        $this->assertSame(WheelPrizes::REDEEMABLE, $byCode[$global->code]);
    }

    public function test_starts_on_date(): void
    {
        $v = $this->voucher(10, ['valid_from' => '2099-03-09']);

        $this->assertSame(
            ['status' => WheelPrizes::NOT_STARTED, 'label' => 'Starts on Mar 09, 2099'],
            $this->listed($this->customer(100))[$v->code]
        );
    }

    public function test_a_start_date_of_today_or_earlier_has_started(): void
    {
        $today = $this->voucher(10, ['valid_from' => \App\Support\ShopDay::todayString()]);
        $past = $this->voucher(10, ['valid_from' => '2020-01-01']);
        $listed = $this->listed($this->customer(100));

        $this->assertSame(WheelPrizes::REDEEMABLE, $listed[$today->code]['status']);
        $this->assertSame(WheelPrizes::REDEEMABLE, $listed[$past->code]['status']);
    }

    public function test_sold_out(): void
    {
        $v = $this->voucher(10, ['max_uses' => 3, 'used_count' => 3]);

        $this->assertSame(
            ['status' => WheelPrizes::SOLD_OUT, 'label' => 'Sold out'],
            $this->listed($this->customer(100))[$v->code]
        );
    }

    public function test_max_uses_zero_means_unlimited_on_the_list_as_it_does_on_redeem(): void
    {
        // The old list query (`used_count < max_uses`) hid these while the picker awarded them.
        $v = $this->voucher(10, ['max_uses' => 0, 'used_count' => 5]);
        $user = $this->customer(100);

        $this->assertSame(WheelPrizes::REDEEMABLE, $this->listed($user)[$v->code]['status']);
        $this->redeem($user, $v)->assertOk();
    }

    public function test_holding_two_spendable_vouchers_says_use_one_first(): void
    {
        $user = $this->customer(100);
        $this->claim($user, $this->voucher(10));
        $this->claim($user, $this->voucher(10));
        $next = $this->voucher(25);
        $tooDear = $this->voucher(500);

        $listed = $this->listed($user);

        $this->assertSame(['status' => WheelPrizes::HOLDING_MAX, 'label' => 'Use a voucher first'], $listed[$next->code]);
        $this->assertSame(WheelPrizes::NEED_POINTS, $listed[$tooDear->code]['status'], 'more points is the first thing it needs');
    }

    public function test_switched_off_direct_code_expired_and_inactive_are_not_listed(): void
    {
        $off = $this->voucher(10, ['on_wheel' => false]);
        $direct = $this->voucher(0);
        $expired = $this->voucher(10, ['expires_at' => now()->subDays(2)]);
        $inactive = $this->voucher(10, ['is_active' => false]);
        $listed = $this->listed($this->customer(100));

        foreach ([$off, $direct, $expired, $inactive] as $voucher) {
            $this->assertArrayNotHasKey($voucher->code, $listed);
        }
    }

    public function test_a_won_prize_says_already_won_even_if_it_has_since_sold_out_or_moved_branch(): void
    {
        $user = $this->customer(100);
        $soldOut = $this->voucher(10, ['max_uses' => 1, 'used_count' => 1]);
        $moved = $this->voucher(10, ['branch_id' => self::FAR]);
        $this->claim($user, $soldOut, true);
        $this->claim($user, $moved, true);

        $listed = $this->listed($user);

        $this->assertSame(WheelPrizes::ALREADY_WON, $listed[$soldOut->code]['status']);
        $this->assertSame(WheelPrizes::ALREADY_WON, $listed[$moved->code]['status']);
    }

    public function test_the_list_is_cheapest_first(): void
    {
        $dear = $this->voucher(90);
        $cheap = $this->voucher(10);
        $mid = $this->voucher(50);

        $this->assertSame([$cheap->code, $mid->code, $dear->code], array_keys($this->listed($this->customer())));
    }

    // ══════════ each status, for a guest ══════════

    public function test_a_guest_gets_the_same_statuses_from_their_session(): void
    {
        $won = $this->voucher(3);
        $far = $this->voucher(3, ['branch_id' => self::FAR]);
        $need = $this->voucher(60);

        GuestOrders::remember($this->order(null)->id);
        GuestVoucherClaims::remember(VoucherClaims::mintForGuest($won));

        $prizes = $this->withSession(['branch_id' => self::HOME, 'guest_points' => 50])
            ->get('/customer/game')->assertOk()->viewData('wheelPrizes');
        $byCode = collect($prizes)->mapWithKeys(fn ($p) => [$p['voucher']->code => $p['label']]);

        $this->assertSame('Already won', $byCode[$won->code]);
        $this->assertSame('Not available at this branch', $byCode[$far->code]);
        $this->assertSame('Need 10 more points', $byCode[$need->code]);
    }

    public function test_a_guest_redeem_reports_what_it_cost_and_the_new_status(): void
    {
        $prize = $this->voucher(3);
        GuestOrders::remember($this->order(null)->id);
        $this->forceSpinOutcome(5);

        $spin = $this->withSession(['branch_id' => self::HOME])->postJson('/customer/add-points')->assertOk()->json();
        $this->assertSame(5, $spin['total_points'], 'the spin took nothing');
        $this->assertSame(WheelPrizes::REDEEMABLE, collect($spin['prizes'])->firstWhere('id', $prize->id)['status']);

        $json = $this->postJson('/customer/points/redeem', ['voucher_id' => $prize->id])->assertOk()->json();

        $this->assertSame(3, $json['voucher']['points_spent']);
        $this->assertSame(2, $json['total_points']);
        $this->assertSame(WheelPrizes::ALREADY_WON, collect($json['prizes'])->firstWhere('id', $prize->id)['status']);
    }

    // ══════════ Try Again: QA's "points deducted" ══════════

    public function test_a_try_again_spin_with_enough_points_takes_nothing_and_awards_nothing(): void
    {
        $prize = $this->voucher(25);
        $user = $this->customer(50);
        $this->order($user->id);

        $json = $this->spin($user, 0)->json();

        $this->assertSame('lose', $json['outcome']['type']);
        $this->assertArrayNotHasKey('voucher', $json);
        $this->assertSame(50, $json['total_points']);
        $this->assertSame(50, $this->balance($user));
        $this->assertSame(0, UserVoucher::where('user_id', $user->id)->count());
        $this->assertSame(WheelPrizes::rewardName($prize), $json['can_redeem']);
    }

    public function test_the_spin_result_shows_only_points_and_the_balance(): void
    {
        $source = file_get_contents(resource_path('views/customer/game.blade.php'));

        // This exact condition is what showed "Better luck next time" on a win.
        $this->assertStringNotContainsString("won.type === 'points' && data.voucher", $source);
        $this->assertStringNotContainsString('if (data.voucher) {', $source);
        $this->assertStringNotContainsString('voucherWinPoints', $source);
        $this->assertStringContainsString("var line = 'You now have ' + data.total_points + ' points.';", $source);
    }

    public function test_a_try_again_spin_without_enough_points_costs_nothing(): void
    {
        $this->voucher(25);
        $user = $this->customer(24);
        $this->order($user->id);

        $json = $this->spin($user, 0)->json();

        $this->assertArrayNotHasKey('voucher', $json);
        $this->assertNull($json['can_redeem']);
        $this->assertSame(24, $json['total_points']);
        $this->assertSame(24, $this->balance($user));
    }

    public function test_points_only_ever_fall_by_the_cost_of_a_voucher_that_was_really_created(): void
    {
        $user = $this->customer(50);
        $prize = $this->voucher(25);
        $this->order($user->id);

        $this->spin($user, 3);
        $this->assertSame(53, $this->balance($user), 'a spin only adds');

        $this->redeem($user, $prize)->assertOk();
        $this->assertSame(28, $this->balance($user), '53 - 25');
        $this->assertSame(1, UserVoucher::where('user_id', $user->id)->count());

        // Redeemed once: the same voucher cannot take more points.
        $this->redeem($user, $prize)->assertStatus(422);
        $this->spin($user, 0);
        $this->assertSame(28, $this->balance($user));
        $this->assertSame(1, UserVoucher::where('user_id', $user->id)->count());
        $this->assertSame(1, UserVoucher::where('voucher_id', $prize->id)->count());
    }

    public function test_a_new_promo_is_redeemed_with_points_already_held_without_spinning_again(): void
    {
        // QA Nov 2: "Even when I add another promo, I can't use it." QA Nov 5:
        // "customers who already have a lot of points shouldn't need to play again".
        $user = $this->customer(50);
        $first = $this->voucher(25);
        $this->redeem($user, $first)->assertOk();
        UserVoucher::where('user_id', $user->id)->update(['is_used' => true, 'used_at' => now()]);

        $promo = $this->voucher(25);

        $this->assertSame(WheelPrizes::REDEEMABLE, $this->listed($user)[$promo->code]['status']);
        $this->assertSame(WheelPrizes::ALREADY_WON, $this->listed($user)[$first->code]['status']);

        $this->redeem($user, $promo)->assertOk()->assertJsonPath('voucher.code', $promo->code);
        $this->assertSame(0, $this->balance($user));
        $this->assertSame(0, \App\Models\GamePlayed::where('user_id', $user->id)->count(), 'no spin was needed');
    }

    public function test_the_spin_reply_carries_the_new_status_of_every_listed_reward(): void
    {
        $ready = $this->voucher(10);
        $next = $this->voucher(60);
        $user = $this->customer(10);
        $this->order($user->id);

        $prizes = collect($this->spin($user, 0)->json('prizes'))->keyBy('id');

        $this->assertSame('Ready to redeem', $prizes[$ready->id]['label']);
        $this->assertSame('Need 50 more points', $prizes[$next->id]['label']);
    }

    // ══════════ the list and the server are one rule ══════════

    public function test_redeem_refuses_a_reward_before_its_start_date(): void
    {
        $prize = $this->voucher(10, ['valid_from' => '2099-01-01']);
        $user = $this->customer(100);

        $this->redeem($user, $prize)->assertStatus(422)->assertJsonPath('status', WheelPrizes::NOT_STARTED);

        $this->assertSame(100, $this->balance($user));
        $this->assertSame(0, UserVoucher::where('user_id', $user->id)->count());
    }

    public function test_redeem_accepts_only_what_the_list_calls_ready(): void
    {
        $ready = $this->voucher(10);
        $refused = [
            $this->voucher(10, ['branch_id' => self::FAR]),
            $this->voucher(10, ['valid_from' => '2099-01-01']),
            $this->voucher(10, ['max_uses' => 1, 'used_count' => 1]),
            $this->voucher(10, ['on_wheel' => false]),
            $this->voucher(500),
        ];
        $held = $this->voucher(10);
        $user = $this->customer(100);
        $this->claim($user, $held, true);

        $listed = $this->listed($user);
        $this->assertSame(WheelPrizes::REDEEMABLE, $listed[$ready->code]['status']);

        foreach (array_merge($refused, [$held]) as $voucher) {
            $this->assertNotSame(WheelPrizes::REDEEMABLE, $listed[$voucher->code]['status'] ?? null);
            $this->redeem($user, $voucher)->assertStatus(422);
        }

        $this->redeem($user, $ready)->assertOk();
        $this->assertSame(90, $this->balance($user));
    }

    public function test_expired_claims_do_not_use_up_the_two_voucher_limit(): void
    {
        $user = $this->customer(100);
        $this->claim($user, $this->voucher(10, ['expires_at' => now()->subDays(3)]));
        $this->claim($user, $this->voucher(10, ['expires_at' => now()->subDays(3)]));
        $prize = $this->voucher(25);

        $this->assertSame(WheelPrizes::REDEEMABLE, $this->listed($user)[$prize->code]['status']);
        $this->assertSame($prize->code, $this->redeem($user, $prize)->assertOk()->json('voucher.code'));
    }

    public function test_switched_off_and_used_up_claims_do_not_use_up_the_limit_either(): void
    {
        $user = $this->customer(100);
        $this->claim($user, $this->voucher(10, ['is_active' => false]));
        $this->claim($user, $this->voucher(10, ['max_uses' => 1, 'used_count' => 1]));
        $prize = $this->voucher(25);

        $this->assertSame($prize->code, $this->redeem($user, $prize)->assertOk()->json('voucher.code'));
    }

    public function test_two_spendable_claims_still_block_a_third_and_take_no_points(): void
    {
        $user = $this->customer(100);
        $this->claim($user, $this->voucher(10));
        $this->claim($user, $this->voucher(10));
        $third = $this->voucher(25);
        $this->order($user->id);

        $this->redeem($user, $third)->assertStatus(422)->assertJsonPath('status', WheelPrizes::HOLDING_MAX);
        $this->assertSame(100, $this->balance($user));

        $json = $this->spin($user, 3)->json();
        $this->assertSame(103, $this->balance($user));
        $this->assertSame(0, $json['voucher_slots']);
    }

    public function test_a_guests_expired_claims_do_not_use_up_their_limit(): void
    {
        foreach ([1, 2] as $n) {
            GuestVoucherClaims::remember(VoucherClaims::mintForGuest($this->voucher(3, ['expires_at' => now()->subDays(3)])));
        }

        $this->assertSame(0, GuestVoucherClaims::unusedCount());
    }

    // ══════════ the "X more pts" hint ══════════

    public function test_the_hint_never_names_a_prize_that_more_points_would_not_unlock(): void
    {
        $user = $this->customer();
        $this->claim($user, $this->voucher(40, ['description' => 'WON ALREADY']), true);
        $this->voucher(50, ['description' => 'SOLD OUT', 'max_uses' => 1, 'used_count' => 1]);
        $this->voucher(60, ['description' => 'NOT STARTED', 'valid_from' => '2099-01-01']);
        $this->voucher(70, ['description' => 'OTHER BRANCH', 'branch_id' => self::FAR]);
        $this->voucher(80, ['description' => 'THE REAL NEXT ONE']);
        $this->order($user->id);

        $json = $this->spin($user, 8)->json();

        $this->assertSame('THE REAL NEXT ONE', $json['next_voucher']);
        $this->assertSame(72, $json['points_needed']);
    }

    public function test_no_hint_when_nothing_is_left_to_aim_for(): void
    {
        $user = $this->customer();
        $this->claim($user, $this->voucher(40), true);
        $this->order($user->id);

        $json = $this->spin($user, 8)->json();

        $this->assertNull($json['next_voucher']);
        $this->assertSame(0, $json['points_needed']);
    }

    // ══════════ the page ══════════

    public function test_the_page_prints_the_status_and_the_one_line_on_how_points_work(): void
    {
        $user = $this->customer(30);
        $this->voucher(25);
        $this->voucher(40);

        $html = $this->actingAs($user->fresh(), 'customer')->withSession(['branch_id' => self::HOME])
            ->get('/customer/game')->assertOk()->getContent();

        $this->assertStringContainsString('Redeem for 25 pts', $html);
        $this->assertStringContainsString('Need 10 more points', $html);
        $this->assertStringContainsString('Spin to earn points. Redeem your points for a voucher anytime.', $html);
    }

    public function test_a_signed_in_customer_is_not_told_they_have_no_account(): void
    {
        $user = $this->customer();
        $page = $this->actingAs($user->fresh(), 'customer')->withSession(['branch_id' => self::HOME])
            ->get('/customer/game')->assertOk()->getContent();

        $this->assertStringContainsString('var signedIn = true;', $page);
    }

    // ══════════ the admin list agrees ══════════

    public function test_the_admin_wheel_status_agrees_about_a_start_date(): void
    {
        $waiting = $this->voucher(10, ['valid_from' => '2099-03-09']);
        $live = $this->voucher(10);

        $this->assertFalse($waiting->isOnWheel());
        $this->assertSame('Paused: starts Mar 09, 2099', $waiting->wheelPausedReason());
        $this->assertTrue($live->isOnWheel());

        $ids = Voucher::onWheel()->whereIn('id', [$waiting->id, $live->id])->pluck('id')->all();
        $this->assertSame([$live->id], $ids);
    }
}

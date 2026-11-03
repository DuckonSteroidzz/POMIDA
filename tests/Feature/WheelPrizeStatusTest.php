<?php

namespace Tests\Feature;

use App\Http\Controllers\Customer\AuthController;
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
use ReflectionMethod;
use Tests\Feature\Concerns\ForcesSpinOutcome;
use Tests\TestCase;

/**
 * Spin & Win: what each prize means for this customer, and that the list and the
 * picker are one rule (QA, Nov 2 2026).
 *
 * The bugs pinned here, each reproduced in a browser first: a voucher won on a
 * Try Again spin showed "Better luck next time" while the points were spent; the
 * list kept offering a prize already won and used; the "X more pts" hint named
 * one too; a prize was awarded before its start date; and two unused claims on
 * expired vouchers blocked every future prize for good.
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

    private function balance(User $user): int
    {
        return $user->fresh()->wheelPoints();
    }

    // ══════════ each status, for an account ══════════

    public function test_you_can_win_this(): void
    {
        $v = $this->voucher(25);

        $this->assertSame(
            ['status' => WheelPrizes::WINNABLE, 'label' => 'You can win this'],
            $this->listed($this->customer(25))[$v->code]
        );
    }

    public function test_exactly_enough_points_is_enough_and_bonus_points_count(): void
    {
        $v = $this->voucher(25);

        $this->assertSame(WheelPrizes::WINNABLE, $this->listed($this->customer(10, 15))[$v->code]['status']);
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

        $this->assertMatchesRegularExpression('/QA1231.*?data-prize-status>Already won</s', $html);
        $this->assertStringNotContainsString('You can win this', $html);
    }

    public function test_not_available_at_this_branch(): void
    {
        $far = $this->voucher(10, ['branch_id' => self::FAR]);
        $home = $this->voucher(10, ['branch_id' => self::HOME]);
        $listed = $this->listed($this->customer(100));

        $this->assertSame(['status' => WheelPrizes::OTHER_BRANCH, 'label' => 'Not available at this branch'], $listed[$far->code]);
        $this->assertSame(WheelPrizes::WINNABLE, $listed[$home->code]['status']);
    }

    public function test_a_visitor_with_no_branch_can_win_only_global_prizes(): void
    {
        $scoped = $this->voucher(10, ['branch_id' => self::HOME]);
        $global = $this->voucher(10);

        $prizes = WheelPrizes::evaluate(100, [], 0, null);
        $byCode = collect($prizes)->mapWithKeys(fn ($p) => [$p['voucher']->code => $p['status']]);

        $this->assertSame(WheelPrizes::OTHER_BRANCH, $byCode[$scoped->code]);
        $this->assertSame(WheelPrizes::WINNABLE, $byCode[$global->code]);
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

        $this->assertSame(WheelPrizes::WINNABLE, $listed[$today->code]['status']);
        $this->assertSame(WheelPrizes::WINNABLE, $listed[$past->code]['status']);
    }

    public function test_sold_out(): void
    {
        $v = $this->voucher(10, ['max_uses' => 3, 'used_count' => 3]);

        $this->assertSame(
            ['status' => WheelPrizes::SOLD_OUT, 'label' => 'Sold out'],
            $this->listed($this->customer(100))[$v->code]
        );
    }

    public function test_max_uses_zero_means_unlimited_on_the_list_as_it_does_in_the_picker(): void
    {
        // The old list query (`used_count < max_uses`) hid these while the picker awarded them.
        $v = $this->voucher(10, ['max_uses' => 0, 'used_count' => 5]);

        $this->assertSame(WheelPrizes::WINNABLE, $this->listed($this->customer(100))[$v->code]['status']);
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

    public function test_a_guest_spin_reports_the_win_and_what_it_cost(): void
    {
        $prize = $this->voucher(3);
        GuestOrders::remember($this->order(null)->id);
        $this->forceSpinOutcome(5);

        $json = $this->withSession(['branch_id' => self::HOME])->postJson('/customer/add-points')->assertOk()->json();

        $this->assertSame(3, $json['voucher']['points_spent']);
        $this->assertSame(2, $json['total_points']);
        $this->assertSame(WheelPrizes::ALREADY_WON, collect($json['prizes'])->firstWhere('id', $prize->id)['status']);
    }

    // ══════════ Try Again: QA's "points deducted" ══════════

    public function test_a_try_again_spin_with_enough_points_wins_and_the_reply_says_so(): void
    {
        $prize = $this->voucher(25);
        $user = $this->customer(50);
        $this->order($user->id);

        $json = $this->spin($user, 0)->json();

        $this->assertSame('lose', $json['outcome']['type']);
        $this->assertSame($prize->code, $json['voucher']['code']);
        $this->assertSame(25, $json['voucher']['points_spent']);
        $this->assertSame(25, $json['total_points']);
        $this->assertSame(25, $this->balance($user));
        $this->assertSame(1, UserVoucher::where('user_id', $user->id)->where('voucher_id', $prize->id)->count());
    }

    public function test_the_page_shows_any_voucher_the_server_awarded_whatever_the_wheel_landed_on(): void
    {
        $source = file_get_contents(resource_path('views/customer/game.blade.php'));

        // This exact condition is what showed "Better luck next time" on a win.
        $this->assertStringNotContainsString("won.type === 'points' && data.voucher", $source);
        $this->assertStringContainsString('if (data.voucher) {', $source);
        $this->assertStringContainsString('voucherWinPoints', $source);
    }

    public function test_a_try_again_spin_without_enough_points_costs_nothing(): void
    {
        $this->voucher(25);
        $user = $this->customer(24);
        $this->order($user->id);

        $json = $this->spin($user, 0)->json();

        $this->assertNull($json['voucher']);
        $this->assertSame(24, $json['total_points']);
        $this->assertSame(24, $this->balance($user));
    }

    public function test_points_only_ever_fall_by_the_cost_of_a_voucher_that_was_really_created(): void
    {
        $user = $this->customer(50);
        $prize = $this->voucher(25);
        $this->order($user->id);

        $this->spin($user, 3);
        $this->assertSame(28, $this->balance($user), '50 + 3 - 25');
        $this->assertSame(1, UserVoucher::where('user_id', $user->id)->count());

        // Won once: the same prize cannot take more points.
        $this->spin($user, 0);
        $this->assertSame(28, $this->balance($user));
        $this->assertSame(1, UserVoucher::where('user_id', $user->id)->count());
        $this->assertSame(1, UserVoucher::where('voucher_id', $prize->id)->count());
    }

    public function test_a_new_promo_is_won_on_the_next_spin_after_the_first_was_won_and_used(): void
    {
        // QA: "Even when I add another promo, I can't use it."
        $user = $this->customer(50);
        $first = $this->voucher(25);
        $this->order($user->id);
        $this->spin($user, 0);
        UserVoucher::where('user_id', $user->id)->update(['is_used' => true, 'used_at' => now()]);

        $promo = $this->voucher(25);

        $this->assertSame(WheelPrizes::WINNABLE, $this->listed($user)[$promo->code]['status']);
        $this->assertSame(WheelPrizes::ALREADY_WON, $this->listed($user)[$first->code]['status']);

        $json = $this->spin($user, 0)->json();

        $this->assertSame($promo->code, $json['voucher']['code']);
        $this->assertSame(0, $this->balance($user));
    }

    public function test_the_reply_carries_the_new_status_of_every_listed_prize(): void
    {
        $won = $this->voucher(10);
        $next = $this->voucher(60);
        $user = $this->customer(10);
        $this->order($user->id);

        $prizes = collect($this->spin($user, 0)->json('prizes'))->keyBy('id');

        $this->assertSame('Already won', $prizes[$won->id]['label']);
        $this->assertSame('Need 60 more points', $prizes[$next->id]['label']);
    }

    // ══════════ the picker and the list are one rule ══════════

    public function test_the_wheel_refuses_a_prize_before_its_start_date(): void
    {
        $this->voucher(10, ['valid_from' => '2099-01-01']);
        $user = $this->customer(100);
        $this->order($user->id);

        $json = $this->spin($user, 3)->json();

        $this->assertNull($json['voucher']);
        $this->assertSame(103, $this->balance($user));
        $this->assertSame(0, UserVoucher::where('user_id', $user->id)->count());
    }

    public function test_the_picker_awards_only_what_the_list_calls_winnable(): void
    {
        $winnable = $this->voucher(10);
        $this->voucher(10, ['branch_id' => self::FAR]);
        $this->voucher(10, ['valid_from' => '2099-01-01']);
        $this->voucher(10, ['max_uses' => 1, 'used_count' => 1]);
        $this->voucher(10, ['on_wheel' => false]);
        $this->voucher(500);
        $excluded = $this->voucher(10);

        $picker = new ReflectionMethod(AuthController::class, 'winnableVoucherFor');
        session()->put('branch_id', self::HOME);

        for ($i = 0; $i < 25; $i++) {
            $this->assertSame(
                $winnable->id,
                $picker->invoke(app(AuthController::class), 100, [$excluded->id])?->id
            );
        }
    }

    public function test_expired_claims_do_not_use_up_the_two_voucher_limit(): void
    {
        $user = $this->customer(100);
        $this->claim($user, $this->voucher(10, ['expires_at' => now()->subDays(3)]));
        $this->claim($user, $this->voucher(10, ['expires_at' => now()->subDays(3)]));
        $prize = $this->voucher(25);
        $this->order($user->id);

        $this->assertSame(WheelPrizes::WINNABLE, $this->listed($user)[$prize->code]['status']);
        $this->assertSame($prize->code, $this->spin($user, 3)->json('voucher.code'));
    }

    public function test_switched_off_and_used_up_claims_do_not_use_up_the_limit_either(): void
    {
        $user = $this->customer(100);
        $this->claim($user, $this->voucher(10, ['is_active' => false]));
        $this->claim($user, $this->voucher(10, ['max_uses' => 1, 'used_count' => 1]));
        $prize = $this->voucher(25);
        $this->order($user->id);

        $this->assertSame($prize->code, $this->spin($user, 3)->json('voucher.code'));
    }

    public function test_two_spendable_claims_still_block_a_third_and_take_no_points(): void
    {
        $user = $this->customer(100);
        $this->claim($user, $this->voucher(10));
        $this->claim($user, $this->voucher(10));
        $this->voucher(25);
        $this->order($user->id);

        $json = $this->spin($user, 3)->json();

        $this->assertNull($json['voucher']);
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

        $this->assertStringContainsString('You can win this', $html);
        $this->assertStringContainsString('Need 10 more points', $html);
        $this->assertStringContainsString(
            'Points add up from every spin. When you have enough for a prize, your next spin wins it and takes those points. Each prize can be won once.',
            $html
        );
    }

    public function test_a_signed_in_winner_is_not_told_they_have_no_account(): void
    {
        $user = $this->customer();
        $page = $this->actingAs($user->fresh(), 'customer')->withSession(['branch_id' => self::HOME])
            ->get('/customer/game')->assertOk()->getContent();

        $this->assertStringContainsString('var isSignedIn = true;', $page);
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

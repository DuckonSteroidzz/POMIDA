<?php

namespace Tests\Feature;

use App\Models\LoyaltyAward;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Loyalty;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Account page's Streak Calendar and Account benefits cards, for a
 * verified customer, an unverified customer and a guest, plus the guest
 * sign-up teasers on the cart and the Spin & Win page.
 *
 * The bonus values are set to unusual numbers (17, 4, 6 => 11, 9 => 23) so
 * every figure asserted on the page can only have come from config, not from
 * a number typed into the Blade.
 */
class AccountBenefitsPageTest extends TestCase
{
    use DatabaseTransactions;

    private const NOW_UTC = '2026-10-15 04:00:00'; // 12:00 Manila

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());
        $this->travelTo(CarbonImmutable::parse(self::NOW_UTC, 'UTC'));

        config([
            'app.shop_timezone'            => 'Asia/Manila',
            'loyalty.welcome_bonus_points' => 17,
            'loyalty.order_bonus_points'   => 4,
            'loyalty.streak_milestones'    => [6 => 11, 9 => 23],
            // The free dessert's own card and teaser line are covered by
            // FreeDessertRewardTest; off here so these figures stay exact.
            'loyalty.free_dessert_visit_days'  => 0,
        ]);
    }

    private function customer(bool $verified = true): User
    {
        $user = User::create([
            'name'              => 'Benefits Test',
            'email'             => 'benefits-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => $verified ? now() : null,
        ]);
        DB::table('users')->where('id', $user->id)->update(['created_at' => '2026-06-01 00:00:00']);

        return $user->fresh();
    }

    private function order(User $user, string $manila, string $status = 'completed'): void
    {
        Order::create([
            'order_number'   => 'BEN-' . strtoupper(Str::random(8)),
            'user_id'        => $user->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => $status,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'subtotal'       => 100,
            'total'          => 100,
            'completed_at'   => CarbonImmutable::parse($manila, 'Asia/Manila')->utc()->format('Y-m-d H:i:s'),
        ]);
    }

    // ══════════ registered, verified ══════════

    public function test_a_verified_customer_sees_their_streak_marked_from_completed_orders_only(): void
    {
        $user = $this->customer();
        $this->order($user, '2026-10-13 12:00');
        $this->order($user, '2026-10-14 12:00');
        $this->order($user, '2026-10-15 09:00');
        $this->order($user, '2026-10-10 12:00', 'cancelled');
        $this->order($user, '2026-10-11 12:00', 'pending');

        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])
            ->get(route('customer.account'))
            ->assertOk()
            ->assertSee('Streak Calendar')
            ->assertSee('October 2026')
            ->getContent();

        $this->assertMatchesRegularExpression('/data-testid="current-streak">\s*3 days/', $html);
        $this->assertMatchesRegularExpression('/data-testid="best-streak">\s*3 days/', $html);

        preg_match_all('/data-streak-day="([0-9-]+)"/', $html, $m);
        $this->assertSame(['2026-10-13', '2026-10-14', '2026-10-15'], $m[1], 'only completed days may be marked');

        // The next milestone hint comes from config: 6 days => +11.
        $this->assertStringContainsString('3 more days', $html);
        $this->assertStringContainsString('6-day bonus (+11 points)', $html);
    }

    public function test_the_benefits_card_reads_every_figure_from_config(): void
    {
        $user = $this->customer();
        Loyalty::award($user, LoyaltyAward::TYPE_WELCOME, 'welcome', 17);

        $this->actingAs($user->fresh(), 'customer')->withSession(['branch_id' => 1])
            ->get(route('customer.account'))
            ->assertOk()
            ->assertSee('Account benefits')
            ->assertSee('Vouchers &amp; Rewards', false)
            ->assertDontSee('My Vouchers')
            ->assertSee('Order history')
            ->assertSee('+11 points at 6 days in a row,', false)
            ->assertSee('+23 points at 9 days in a row.', false)
            ->assertSee('+4 points for every completed order.', false)
            ->assertSee('Received: +17 points.', false)
            ->assertSee('7 spins with every order, up to 15 a day.', false)
            ->assertSee('17 pts', false) // the bonus balance
            ->assertSee('Welcome bonus')
            ->assertDontSee('data-testid="verify-to-earn"', false);
    }

    public function test_a_bonus_switched_off_is_not_advertised(): void
    {
        config(['loyalty.order_bonus_points' => 0, 'loyalty.streak_milestones' => []]);
        $user = $this->customer();

        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])
            ->get(route('customer.account'))
            ->assertOk()
            ->assertDontSee('data-testid="benefit-order"', false)
            ->assertDontSee('data-testid="benefit-streak"', false)
            ->assertSee('data-testid="benefit-welcome"', false);
    }

    public function test_month_navigation_shows_the_requested_month_with_links_either_side(): void
    {
        $user = $this->customer();
        $this->order($user, '2026-08-20 12:00');

        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])
            ->get(route('customer.account', ['month' => '2026-08']))
            ->assertOk()
            ->assertSee('August 2026')
            ->getContent();

        $this->assertStringContainsString('month=2026-07', $html);
        $this->assertStringContainsString('month=2026-09', $html);
        preg_match_all('/data-streak-day="([0-9-]+)"/', $html, $m);
        $this->assertSame(['2026-08-20'], $m[1]);
    }

    public function test_the_current_month_has_no_next_link_and_junk_months_are_harmless(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])
            ->get(route('customer.account'))
            ->assertOk()
            ->assertSee('data-testid="streak-prev"', false)
            ->assertDontSee('data-testid="streak-next"', false);

        // Future -> this month; before the account (June 2026) -> June; junk -> this month.
        foreach (['2099-01' => 'October 2026', '1999-12' => 'June 2026', 'nonsense' => 'October 2026', '<b>x</b>' => 'October 2026'] as $junk => $shown) {
            $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])
                ->get(route('customer.account', ['month' => $junk]))
                ->assertOk()
                ->assertSee('data-testid="streak-month">', false)
                ->assertSee($shown)
                ->assertDontSee('<b>x</b>', false);
        }
    }

    public function test_recent_bonuses_are_listed_from_the_ledger(): void
    {
        $user = $this->customer();
        Loyalty::award($user, LoyaltyAward::TYPE_ORDER_BONUS, '1', 4);
        Loyalty::award($user, LoyaltyAward::TYPE_STREAK, '6:2026-10-10', 11);

        $this->actingAs($user->fresh(), 'customer')->withSession(['branch_id' => 1])
            ->get(route('customer.account'))
            ->assertOk()
            ->assertSee('data-testid="recent-bonuses"', false)
            ->assertSee('6-day streak bonus')
            ->assertSee('Completed order bonus')
            ->assertSee('15 pts', false);
    }

    public function test_viewing_the_page_never_pays_anything(): void
    {
        $user = $this->customer();
        foreach (range(7, 15) as $d) {
            $this->order($user, sprintf('2026-10-%02d 12:00', $d));
        }

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user, 'customer')->get(route('customer.account'))->assertOk();
        }

        $this->assertSame(0, LoyaltyAward::where('user_id', $user->id)->count());
        $this->assertSame(0, (int) $user->fresh()->bonus_points);
    }

    // ══════════ registered, unverified ══════════

    public function test_an_unverified_customer_is_told_to_verify_before_earning(): void
    {
        $user = $this->customer(verified: false);

        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])
            ->get(route('customer.account'))
            ->assertOk()
            ->assertSee('Streak Calendar')
            ->assertSee('data-testid="verify-to-earn"', false)
            ->assertSee('+17 points once you verify your email.', false)
            // No "N more days to the bonus" promise while they cannot earn it.
            ->assertDontSee('-day bonus (+', false);
    }

    // ══════════ guests ══════════

    public function test_a_guest_cannot_reach_the_account_page(): void
    {
        $this->get(route('customer.account'))
            ->assertRedirect(route('customer.menu'));

        $this->get(route('customer.account', ['month' => '2026-09']))
            ->assertRedirect(route('customer.menu'));
    }

    public function test_a_guest_sees_the_signup_teaser_on_the_cart_and_a_customer_does_not(): void
    {
        $item = MenuItem::query()->firstOrFail();
        $cart = [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'quantity'     => 1,
            'options'      => [],
        ]];
        $session = ['cart' => $cart, 'branch_id' => $item->branch_id ?? 1, 'order_type' => 'pick_up'];
        $teaser = 'Create a free account for 17 welcome points, +4 points on every completed order and streak bonuses at 6 days and 9 days.';

        $this->withSession($session)
            ->get(route('customer.cart'))
            ->assertOk()
            ->assertSee('data-testid="guest-signup-teaser"', false)
            ->assertSee($teaser);

        $this->actingAs($this->customer(), 'customer')
            ->withSession($session)
            ->get(route('customer.cart'))
            ->assertOk()
            ->assertDontSee('data-testid="guest-signup-teaser"', false);
    }

    public function test_a_guest_sees_the_signup_teaser_on_spin_and_win_and_a_customer_does_not(): void
    {
        $this->get(route('customer.game'))
            ->assertOk()
            ->assertSee('data-testid="guest-signup-teaser"', false)
            ->assertSee('17 welcome points');

        $this->actingAs($this->customer(), 'customer')
            ->get(route('customer.game'))
            ->assertOk()
            ->assertDontSee('data-testid="guest-signup-teaser"', false);
    }

    public function test_the_points_shown_elsewhere_include_the_bonus_balance(): void
    {
        $user = $this->customer();
        $user->forceFill(['points' => 5, 'bonus_points' => 12])->save();

        $this->actingAs($user, 'customer')->get(route('customer.game'))
            ->assertOk()->assertSee('17 pts', false);
        $this->actingAs($user, 'customer')->get(route('customer.more'))
            ->assertOk()->assertSee('17 points', false);
        $this->actingAs($user, 'customer')->get(route('customer.vouchers'))
            ->assertOk()->assertSeeInOrder(['You have', '17', 'pts'], false);
    }
}

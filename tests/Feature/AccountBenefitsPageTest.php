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
        $order = Order::create([
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

        // The day a streak counts is the day the order was PLACED (created_at,
        // not fillable), so a back-dated fixture is placed when it completes.
        DB::table('orders')->where('id', $order->id)->update(['created_at' => CarbonImmutable::parse($manila, 'Asia/Manila')->utc()->format('Y-m-d H:i:s')]);
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
            ->assertSee('Account Benefits')
            ->assertSee('Vouchers &amp; Rewards', false)
            ->assertDontSee('My Vouchers')
            ->assertSee('Order history')
            ->assertSee('+11 pts at 6 days,', false)
            ->assertSee('+23 pts at 9 days.', false)
            ->assertSee('+4 points per completed order.', false)
            ->assertSee('Received: +17 points.', false)
            ->assertSee('7 spins per order, up to 15 a day.', false)
            ->assertSeeHtml('17 <span class="text-[22px]">pts</span>') // the bonus balance (the "pts" unit is its own smaller span)
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
            ->assertSeeHtml('15 <span class="text-[22px]">pts</span>');
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
            ->assertSee('+17 points when you verify your email.', false)
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

    public function test_the_spin_result_popup_does_not_repeat_the_signup_teaser_the_banner_already_shows(): void
    {
        // The banner above the wheel keeps the sentence; the result pop-up
        // (Try Again / +N Points) no longer builds a second copy from the
        // spin response, whatever the outcome.
        $html = $this->get(route('customer.game'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-testid="guest-signup-teaser"'));
        $this->assertStringNotContainsString('signupTeaserHtml', $html);
        $this->assertStringNotContainsString('data-signup-teaser', $html);
        $this->assertStringContainsString("showSpinToast('😅', 'Try Again!', 'Better luck next time!');", $html);
    }

    // ══════════ the simpler look (item I, Oct 2026) ══════════

    public function test_the_header_card_shows_who_they_are_and_three_real_numbers(): void
    {
        config(['loyalty.free_dessert_visit_days' => 7]);

        $user = $this->customer();
        $user->forceFill(['name' => 'Peach Tester', 'points' => 5, 'bonus_points' => 12])->save();
        $this->order($user, '2026-10-14 12:00');
        $this->order($user, '2026-10-15 09:00');

        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])
            ->get(route('customer.account'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="account-header"', $html);
        $this->assertMatchesRegularExpression('/data-testid="account-header-name">Peach Tester</', $html);
        $this->assertStringContainsString('>' . $user->email . '<', $html);
        $this->assertStringContainsString('data-testid="account-header-verified"', $html);
        $this->assertStringNotContainsString('data-testid="account-header-unverified"', $html);

        // Points = spin + bonus, the same figure Vouchers & Rewards shows.
        $this->assertMatchesRegularExpression('/data-testid="chip-points">17</', $html);
        // Two completed days in a row at this branch.
        $this->assertMatchesRegularExpression('/data-testid="chip-streak">2 days</', $html);
        // Two stamp days of the 7 on the current card.
        $this->assertMatchesRegularExpression('/data-testid="chip-stamps">2\/7</', $html);
    }

    public function test_chips_whose_feature_is_not_available_show_a_dash_not_a_made_up_zero(): void
    {
        // Stamp card off (config in setUp), and no branch picked: no calendar either.
        $html = $this->actingAs($this->customer(), 'customer')
            ->get(route('customer.account'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-testid="chip-streak">—</', $html);
        $this->assertMatchesRegularExpression('/data-testid="chip-stamps">—</', $html);
        $this->assertMatchesRegularExpression('/data-testid="chip-points">0</', $html);
    }

    public function test_an_unverified_customer_gets_a_not_verified_badge_and_the_resend_button(): void
    {
        $html = $this->actingAs($this->customer(false), 'customer')->withSession(['branch_id' => 1])
            ->get(route('customer.account'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="account-header-unverified"', $html);
        $this->assertStringNotContainsString('data-testid="account-header-verified"', $html);
        $this->assertStringContainsString('onclick="resendVerification()"', $html);
        $this->assertStringContainsString('Verify your email to start earning bonus points.', $html);
    }

    public function test_the_bonus_balance_is_one_banner_not_a_second_copy_in_the_benefits_card(): void
    {
        $user = $this->customer();
        $user->forceFill(['bonus_points' => 12])->save();

        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])
            ->get(route('customer.account'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-testid="bonus-points"'));
        $this->assertMatchesRegularExpression('/data-testid="bonus-banner".*?data-testid="bonus-points">12 <span[^>]*>pts<\/span>/s', $html);
        // Banner sits above the benefits list: it leads the Overview tab, the
        // benefits card is in the Rewards tab after it.
        $this->assertLessThan(strpos($html, 'id="benefits"'), strpos($html, 'data-testid="bonus-banner"'));
        $overview = strpos($html, 'id="p-overview"');
        $rewards  = strpos($html, 'id="p-rewards"');
        $this->assertGreaterThan($overview, strpos($html, 'data-testid="bonus-banner"'));
        $this->assertLessThan($rewards, strpos($html, 'data-testid="bonus-banner"'), 'the banner is in Overview');
        $this->assertGreaterThan($rewards, strpos($html, 'id="benefits"'), 'the benefits card is in Rewards');
    }

    public function test_every_function_of_the_page_is_still_there(): void
    {
        config(['loyalty.free_dessert_visit_days' => 7]);
        $user = $this->customer(false);
        $this->order($user, '2026-10-15 09:00');

        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])
            ->get(route('customer.account'))->assertOk()->getContent();

        // Profile form, password change with both eye toggles, delete, save.
        $this->assertStringContainsString('action="' . route('customer.account.update') . '"', $html);
        foreach (['name', 'address', 'contact_number', 'email', 'password', 'current_password'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html, $field);
        }
        $this->assertSame(2, substr_count($html, 'data-password-toggle="'));
        $this->assertStringContainsString('Save Changes', $html);
        $this->assertStringContainsString('onclick="confirmDelete()"', $html);
        $this->assertStringContainsString('onclick="resendVerification()"', $html);

        // Calendar, bonus-points card, stamp card, benefits: the same sections.
        foreach (['data-testid="streak-calendar"', 'data-testid="bonus-banner"', 'data-testid="free-dessert-card"', 'id="benefits"'] as $section) {
            $this->assertStringContainsString($section, $html, $section);
        }
        $this->assertStringNotContainsString('data-testid="free-dessert-challenge"', $html, 'the Reward Challenge card is gone');
        $this->assertStringContainsString('1 / 7 stamps', $html);

        // Four tabs (Oct 2026): all four panels are in the HTML, in the
        // reference's order, whichever one is showing. Which section sits in
        // which panel is pinned by AccountSettingsTabsTest.
        $this->assertStringContainsString('role="tablist"', $html);
        $positions = [];
        foreach (['overview', 'rewards', 'profile', 'security'] as $tab) {
            $this->assertStringContainsString('id="t-' . $tab . '"', $html, "tab $tab");
            $positions[$tab] = strpos($html, 'id="p-' . $tab . '"');
            $this->assertNotFalse($positions[$tab], "panel $tab");
        }
        $this->assertSame(array_values($positions), collect($positions)->sort()->values()->all(), 'panels are in tab order');
    }

    public function test_the_long_and_repeated_wording_is_gone(): void
    {
        config(['loyalty.free_dessert_visit_days' => 7]);

        $html = $this->actingAs($this->customer(false), 'customer')->withSession(['branch_id' => 1])
            ->get(route('customer.account'))->assertOk()->getContent();

        foreach ([
            'discount IDs and account preferences',
            'Review your changes before saving',
            "Only completed orders count. Pending or cancelled orders",
            'Use the Resend button under Account Details below',
            'Bonus points stay on your account and count toward',
            'Your personal and contact information',
            'Each branch has its own Streak Calendar and stamp card',
        ] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "still on the page: $gone");
        }

        // The tester's updated reference words the stamp rule in full on the
        // stamp card AND in Account Benefits (twice, on purpose), and the old
        // "1 stamp per day you completed an order" wording is gone everywhere.
        $this->assertSame(2, substr_count($html, 'Earn 1 stamp per day when you complete at least one order at'));
        $this->assertSame(0, substr_count($html, '1 stamp per day you completed an order'));
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

<?php

namespace Tests\Feature;

use App\Models\GamePlayed;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The one-time "play Spin & Win while you wait" modal on the Orders page
 * (Oct 2026, capstone adviser request). See App\Support\SpinPrompt.
 *
 * The behaviours pinned here:
 *   - shown after a cash order, dine-in and pick-up, guest and registered;
 *   - GCash: only once staff have verified the payment (payment_status paid);
 *   - shown ONCE per order — the server stamps orders.spin_prompted_at, so a
 *     refresh does not bring it back;
 *   - never shown when no spins remain, when the game is switched off, or while
 *     a PWD/Senior discount awaits staff verification;
 *   - rendering it grants nothing (no games_played rows, same spin state);
 *   - the existing "Play Game While You Wait" button is still there.
 *
 * Every user and order is created inside DatabaseTransactions and rolled back.
 * A brand-new user is used (not "the first customer") because the testing
 * database is a copy of live and its real customers may have open orders that
 * would change the spin count.
 */
class SpinPromptModalTest extends TestCase
{
    use DatabaseTransactions;

    private const MODAL = 'id="spinPromptModal"';

    protected function setUp(): void
    {
        parent::setUp();

        // The game is ON for these tests; one test switches it off.
        Setting::set(Setting::GAME_ENABLED, '1');
    }

    private function customer(): User
    {
        return User::factory()->create([
            'role'      => 'customer',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function order(?User $user, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'SPM-' . strtoupper(substr(uniqid(), -8)),
            'user_id'        => $user?->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'table_number'   => null,
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ], $attrs));
    }

    /** GET the Orders page as a signed-in customer. */
    private function visitAs(User $user)
    {
        return $this->actingAs($user, 'customer')->get('/customer/orders')->assertOk();
    }

    /** GET the Orders page as a guest whose session placed these orders. */
    private function visitAsGuest(array $orderIds)
    {
        return $this->withSession([GuestOrders::KEY => $orderIds])
            ->get('/customer/orders')
            ->assertOk();
    }

    // ── shown ───────────────────────────────────────────────────────────────

    public function test_modal_shows_after_a_cash_pickup_order_for_a_registered_customer(): void
    {
        $user = $this->customer();
        $this->order($user, ['type' => 'pick_up', 'payment_method' => 'cash']);

        $this->visitAs($user)
            ->assertSee(self::MODAL, false)
            ->assertSee('Play now')
            ->assertSee('<strong>7</strong> spins for order', false);
    }

    public function test_modal_shows_after_a_cash_dine_in_order_for_a_registered_customer(): void
    {
        $user = $this->customer();
        $this->order($user, ['type' => 'dine_in', 'table_number' => 7, 'payment_method' => 'cash']);

        $this->visitAs($user)->assertSee(self::MODAL, false);
    }

    public function test_modal_shows_for_a_guest_pickup_order(): void
    {
        $order = $this->order(null, ['type' => 'pick_up']);

        $this->visitAsGuest([$order->id])->assertSee(self::MODAL, false);
    }

    public function test_modal_shows_for_a_guest_dine_in_order(): void
    {
        $order = $this->order(null, ['type' => 'dine_in', 'table_number' => 8]);

        $this->visitAsGuest([$order->id])->assertSee(self::MODAL, false);
    }

    // ── GCash: only after staff verify the payment ──────────────────────────

    public function test_gcash_order_waiting_for_payment_gets_no_modal_and_is_not_stamped(): void
    {
        $user  = $this->customer();
        $order = $this->order($user, ['payment_method' => 'gcash', 'payment_status' => 'pending']);

        $this->visitAs($user)->assertDontSee(self::MODAL, false);

        $this->assertNull($order->fresh()->spin_prompted_at, 'an unqualified order must stay promptable');
    }

    public function test_gcash_order_awaiting_staff_verification_gets_no_modal(): void
    {
        $user = $this->customer();
        $this->order($user, ['payment_method' => 'gcash', 'payment_status' => 'awaiting_verification']);

        $this->visitAs($user)->assertDontSee(self::MODAL, false);
    }

    public function test_gcash_order_gets_the_modal_once_staff_have_verified_payment(): void
    {
        $user  = $this->customer();
        $order = $this->order($user, ['payment_method' => 'gcash', 'payment_status' => 'pending']);

        $this->visitAs($user)->assertDontSee(self::MODAL, false);

        $order->update(['payment_status' => 'awaiting_verification']);
        $this->visitAs($user)->assertDontSee(self::MODAL, false);

        $order->update(['payment_status' => 'paid']);
        $this->visitAs($user)->assertSee(self::MODAL, false);
    }

    public function test_an_order_awaiting_pwd_senior_verification_gets_no_modal(): void
    {
        $user = $this->customer();
        $this->order($user, ['discount_status' => 'pending']);

        $this->visitAs($user)->assertDontSee(self::MODAL, false);
    }

    // ── once only, and the server remembers ─────────────────────────────────

    public function test_modal_is_shown_once_per_order_even_after_a_refresh(): void
    {
        $user  = $this->customer();
        $order = $this->order($user);

        $this->visitAs($user)->assertSee(self::MODAL, false);
        $this->assertNotNull($order->fresh()->spin_prompted_at);

        $this->visitAs($user)->assertDontSee(self::MODAL, false);
        $this->visitAs($user)->assertDontSee(self::MODAL, false);
    }

    public function test_once_only_holds_for_guests_too(): void
    {
        $order = $this->order(null);

        $this->visitAsGuest([$order->id])->assertSee(self::MODAL, false);
        $this->visitAsGuest([$order->id])->assertDontSee(self::MODAL, false);
    }

    public function test_a_new_order_gets_its_own_prompt(): void
    {
        $user = $this->customer();
        $this->order($user);

        $this->visitAs($user)->assertSee(self::MODAL, false);
        $this->visitAs($user)->assertDontSee(self::MODAL, false);

        $second = $this->order($user);

        $this->visitAs($user)->assertSee(self::MODAL, false);
        $this->assertNotNull($second->fresh()->spin_prompted_at);
    }

    public function test_several_open_orders_produce_one_modal_and_all_are_stamped(): void
    {
        $user = $this->customer();
        $a = $this->order($user);
        $b = $this->order($user);

        $html = $this->visitAs($user)->getContent();

        $this->assertSame(1, substr_count($html, self::MODAL), 'one modal per page load');
        $this->assertNotNull($a->fresh()->spin_prompted_at);
        $this->assertNotNull($b->fresh()->spin_prompted_at);
        $this->visitAs($user)->assertDontSee(self::MODAL, false);
    }

    // ── only while spins are left, and only while the game is on ────────────

    public function test_modal_is_hidden_when_no_spins_remain(): void
    {
        $user  = $this->customer();
        $order = $this->order($user);

        // One order = 7 spins. Use them all.
        for ($i = 1; $i <= 7; $i++) {
            GamePlayed::create([
                'user_id'        => $user->id,
                'order_id'       => $order->id,
                'spin_number'    => $i,
                'points_awarded' => 0,
            ]);
        }

        $this->visitAs($user)->assertDontSee(self::MODAL, false);

        $this->assertNull($order->fresh()->spin_prompted_at);
    }

    public function test_modal_is_hidden_for_a_guest_with_no_spins_remaining(): void
    {
        $order = $this->order(null);

        for ($i = 1; $i <= 7; $i++) {
            GamePlayed::create([
                'user_id'        => null,
                'order_id'       => $order->id,
                'spin_number'    => $i,
                'points_awarded' => 0,
            ]);
        }

        $this->visitAsGuest([$order->id])->assertDontSee(self::MODAL, false);
    }

    public function test_modal_reports_the_real_remaining_count(): void
    {
        $user  = $this->customer();
        $order = $this->order($user);

        for ($i = 1; $i <= 6; $i++) {
            GamePlayed::create([
                'user_id' => $user->id, 'order_id' => $order->id,
                'spin_number' => $i, 'points_awarded' => 0,
            ]);
        }

        $this->visitAs($user)
            ->assertSee(self::MODAL, false)
            ->assertSee('<strong>1</strong> spin for order', false);
    }

    public function test_modal_is_hidden_when_the_owner_has_switched_the_game_off(): void
    {
        Setting::set(Setting::GAME_ENABLED, '0');

        $user  = $this->customer();
        $order = $this->order($user);

        $this->visitAs($user)->assertDontSee(self::MODAL, false);
        $this->assertNull($order->fresh()->spin_prompted_at);
    }

    // ── it grants nothing, and it does not replace anything ─────────────────

    public function test_showing_the_modal_grants_no_spins_and_changes_no_spin_state(): void
    {
        $user = $this->customer();
        $this->order($user);

        $before = GamePlayed::count();

        $this->visitAs($user)->assertSee(self::MODAL, false);

        $this->assertSame($before, GamePlayed::count());

        // The game page — the only place spins are counted — still says 7.
        $this->actingAs($user, 'customer')->get('/customer/game')
            ->assertOk()
            ->assertSee('7 spins remaining');
    }

    public function test_the_existing_play_game_while_you_wait_button_is_still_there(): void
    {
        $user = $this->customer();
        $this->order($user);

        // First visit: modal AND button. Second visit: button only.
        foreach ([true, false] as $modalExpected) {
            $html = $this->visitAs($user)->getContent();

            $this->assertSame($modalExpected, str_contains($html, self::MODAL));
            $this->assertStringContainsString('Play Game While You Wait', $html);
            $this->assertStringContainsString('class="game-btn', $html);
        }
    }

    // ── markup the brief asks for ───────────────────────────────────────────

    public function test_modal_markup_is_an_accessible_dialog_with_a_close_x_and_a_play_link(): void
    {
        $user = $this->customer();
        $this->order($user);

        $html = $this->visitAs($user)->getContent();

        $this->assertStringContainsString('role="dialog"', $this->between($html, self::MODAL, 'Your order status stays'));
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="spinPromptTitle"', $html);
        $this->assertStringContainsString('aria-describedby="spinPromptDesc"', $html);

        // Close X: labelled, and a 44px tap target.
        $this->assertMatchesRegularExpression('/data-spin-prompt-close\s+aria-label="Close"/', $html);
        $this->assertMatchesRegularExpression('/\.spin-prompt__close\s*\{[^}]*width:\s*44px;[^}]*height:\s*44px;/s', $html);

        // Play now: a plain link to the existing game, >= 48px high, >= 16px text.
        $this->assertStringContainsString('href="' . route('customer.game') . '" class="spin-prompt__play"', $html);
        $this->assertMatchesRegularExpression('/\.spin-prompt__play\s*\{[^}]*min-height:\s*56px;[^}]*font-size:\s*1\.125rem;/s', $html);

        // No secondary dismiss link.
        $this->assertStringNotContainsStringIgnoringCase('Maybe later', $html);
    }

    public function test_modal_helper_text_is_never_smaller_than_14px(): void
    {
        $css = file_get_contents(resource_path('views/customer/partials/spin-prompt-modal.blade.php'));

        preg_match_all('/font-size:\s*([\d.]+)(rem|px)/', $css, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);

        foreach ($m as [$whole, $n, $unit]) {
            $px = $unit === 'rem' ? (float) $n * 16 : (float) $n;
            $this->assertGreaterThanOrEqual(14, $px, "'{$whole}' is below the 14px helper-text floor");
        }
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $end   = strpos($html, $to, $start ?: 0);

        return substr($html, $start, ($end ?: strlen($html)) - $start);
    }
}

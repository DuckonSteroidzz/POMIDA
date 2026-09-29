<?php

namespace Tests\Feature;

use App\Http\Controllers\Customer\AuthController;
use App\Models\GamePlayed;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Services\SpinWheel;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ForcesSpinOutcome;
use Tests\TestCase;

/**
 * Hardening pass F3 (2026-09-27): the server, not the browser, decides where
 * the Spin & Win wheel lands.
 *
 * WHAT WAS WRONG
 * --------------
 * customer/game.blade.php picked a random landing angle, read off the segment
 * and POSTed its points to /customer/add-points. AuthController::addPoints()
 * only checked the number against GAME_POINT_AWARDS [0,3,5,8], so a client
 * that always posted 8 won 8 on every spin, bounded only by the spin caps.
 * Reproduced before the fix against pomida_db_testing by running this file's
 * test_posting_the_top_prize_every_time_does_not_win_it_every_time on the
 * unfixed code: 168 guest spins, every one posting points=8, left the guest
 * holding 1344 points, which is exactly 168 x 8.
 *
 * NOW
 * ---
 * addPoints() reads nothing from the request body. Once the switch, the order
 * window and the daily cap have all passed, it asks App\Services\SpinWheel
 * for a segment of AuthController::WHEEL_SEGMENTS, records that segment's
 * points on the games_played row, credits them, and returns the segment so
 * the page can animate to it.
 *
 * Every test sets the game switch itself inside its own transaction, which
 * DatabaseTransactions rolls back, so nothing depends on the database's
 * current value.
 */
class SpinOutcomeServerAuthoritativeTest extends TestCase
{
    use DatabaseTransactions;
    use ForcesSpinOutcome;

    protected function setUp(): void
    {
        parent::setUp();
        // add-points is throttled per IP and every request here is 127.0.0.1.
        Cache::flush();

        $this->gameSwitch('1');

        // Wheel vouchers deduct points when won, which would make the credited
        // total differ from the sum of the spins. With none active, every
        // point credited is a point the wheel awarded. Rolled back afterwards.
        Voucher::where('points_required', '>', 0)->update(['is_active' => false]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ══════════ fixtures ══════════

    private function gameSwitch(string $value): void
    {
        DB::table('settings')->where('key', 'game_enabled')->delete();
        DB::table('settings')->insert([
            'key'        => 'game_enabled',
            'branch_id'  => null,
            'value'      => $value,
            'group'      => 'business',
            'label'      => 'Spin & Win Enabled',
            'type'       => 'text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A pending order placed today by this guest session: 7 spins. */
    private function guestOrder(): Order
    {
        $order = Order::create([
            'order_number'   => 'SOA-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 500,
            'total'          => 500,
        ]);

        GuestOrders::remember($order->id);

        return $order;
    }

    /** A new account with 0 points and one pending order today. */
    private function customerWithOrder(): User
    {
        $customer = User::create([
            'name'      => 'Spin Outcome Customer',
            'email'     => 'soa-' . Str::lower(Str::random(12)) . '@invalid.local',
            'password'  => 'SpinOutcome!1',
            'role'      => 'customer',
            'is_active' => true,
        ]);
        $customer->forceFill(['points' => 0])->save();

        Order::create([
            'order_number'   => 'SOA-' . substr(uniqid(), -8),
            'user_id'        => $customer->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 500,
            'total'          => 500,
        ]);

        return $customer;
    }

    /** A picker that uses the real odds but counts how often it is asked. */
    private function countingWheel(): SpinWheel
    {
        $wheel = new class extends SpinWheel {
            public int $calls = 0;

            public function land(array $segments): int
            {
                $this->calls++;

                return parent::land($segments);
            }
        };

        $this->app->instance(SpinWheel::class, $wheel);

        return $wheel;
    }

    // ══════════ the request body cannot choose the prize ══════════

    /**
     * What a tampering client might send, from the old page's own payload to
     * payloads naming the segment directly. None of it is read.
     */
    public static function tamperedBodies(): array
    {
        return [
            'the old page payload, top prize'   => [['points' => 8]],
            'the top prize as a string'         => [['points' => '8']],
            'a value the wheel never had'       => [['points' => 99999]],
            'a negative value'                  => [['points' => -5]],
            'naming the 8-point segment'        => [['segment' => 4]],
            'a forged outcome object'           => [['outcome' => ['segment' => 4, 'type' => 'points', 'points' => 8]]],
            'points_awarded, the ledger column' => [['points_awarded' => 8]],
        ];
    }

    /**
     * @dataProvider tamperedBodies
     *
     * The server's wheel is fixed on Try Again (0). Seven spins ask for the
     * top prize. Every one is credited 0, because the server's pick is the
     * only thing credited.
     */
    public function test_a_guest_cannot_choose_the_prize_by_what_it_posts(array $body): void
    {
        $this->forceSpinOutcome(0);
        $order = $this->guestOrder();

        for ($i = 1; $i <= 7; $i++) {
            $this->postJson('/customer/add-points', $body)
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('outcome.points', 0)
                ->assertJsonPath('outcome.type', 'lose')
                ->assertJsonPath('total_points', 0);
        }

        $this->assertSame(0, (int) session('guest_points', 0), 'the posted value was credited to the guest');
        $this->assertSame(
            [0, 0, 0, 0, 0, 0, 0],
            GamePlayed::where('order_id', $order->id)->orderBy('id')->pluck('points_awarded')->map('intval')->all(),
            'the ledger recorded something other than what the server picked'
        );
    }

    /** The same for a signed-in account, whose points are a real balance. */
    public function test_a_signed_in_customer_cannot_choose_the_prize_by_what_it_posts(): void
    {
        $this->forceSpinOutcome(3);
        $customer = $this->customerWithOrder();

        $this->actingAs($customer, 'customer')
            ->postJson('/customer/add-points', ['points' => 8])
            ->assertOk()
            ->assertJsonPath('outcome.points', 3)
            ->assertJsonPath('total_points', 3);

        $this->assertSame(3, (int) $customer->fresh()->points, 'the account was credited the posted 8, not the server pick');
        $this->assertSame(
            [3],
            GamePlayed::where('user_id', $customer->id)->pluck('points_awarded')->map('intval')->all()
        );
    }

    /**
     * The attack against the REAL wheel, not a fixed one: 168 spins (24 guest
     * orders x 7), every one posting points=8. Before F3 all 168 won 8.
     *
     * Bounds are wide on purpose. The 8-point share is 2/12 = 16.7%; at
     * n=168 its standard error is 2.9 points, so the 40% ceiling is 8 sigma
     * away. The chance of no Try Again at all in 168 spins is 0.75^168,
     * about 1e-21. Neither can flake. Both fail at once if the posted value
     * is ever credited again. The tight distribution check is in
     * SpinWheelBalanceTest, over 60,000 draws of the same picker.
     */
    public function test_posting_the_top_prize_every_time_does_not_win_it_every_time(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $spins = 0;
        $credited = [];

        for ($o = 0; $o < 24; $o++) {
            $this->guestOrder();

            for ($i = 0; $i < 7; $i++) {
                $response = $this->postJson('/customer/add-points', ['points' => 8])->assertOk();
                $credited[] = (int) $response->json('outcome.points');
                $spins++;
            }
        }

        $this->assertSame(168, $spins);

        $counts = array_count_values($credited);
        $topShare = ($counts[8] ?? 0) / $spins;

        $this->assertLessThan(
            0.40,
            $topShare,
            sprintf('posting 8 won 8 on %.1f%% of %d spins, but the wheel pays 8 on 16.7%%', $topShare * 100, $spins)
        );
        $this->assertGreaterThan(0, $counts[0] ?? 0, 'not one Try Again in 168 spins, so the posted value is winning');

        foreach (array_keys($counts) as $value) {
            $this->assertContains($value, [0, 3, 5, 8], "the server credited {$value}, which is not on the wheel");
        }

        // Every credited point is on the ledger, and the guest holds exactly that.
        $this->assertSame(array_sum($credited), (int) session('guest_points'));
        $this->assertSame(
            $credited,
            GamePlayed::whereIn('order_id', GuestOrders::ids())->orderBy('id')->pluck('points_awarded')->map('intval')->all(),
            'what the response said was won is not what the ledger recorded'
        );
    }

    // ══════════ the response tells the page where to land ══════════

    public function test_the_response_names_the_exact_segment_the_server_picked(): void
    {
        $index = $this->forceSpinOutcome(5);
        $this->guestOrder();

        $outcome = $this->postJson('/customer/add-points')->assertOk()->json('outcome');

        $this->assertSame(
            ['segment' => $index] + AuthController::WHEEL_SEGMENTS[$index],
            $outcome,
            'the page animates to outcome.segment, so it must be the segment that was credited'
        );
    }

    /**
     * The new page sends an empty body. The old endpoint required `points`
     * and would have answered 422.
     */
    public function test_an_empty_request_body_is_a_normal_spin(): void
    {
        $this->forceSpinOutcome(8);
        $order = $this->guestOrder();

        $this->postJson('/customer/add-points', [])
            ->assertOk()
            ->assertJson(['success' => true, 'total_points' => 8, 'spin_number' => 1]);

        $this->assertSame(1, GamePlayed::where('order_id', $order->id)->count());
    }

    // ══════════ the gates still come first ══════════

    /**
     * The wheel is only consulted for a spin that has already been granted.
     * Refusals (no order, window used up, switch off) never reach it, so
     * the limits run before an outcome exists.
     */
    public function test_the_wheel_is_only_consulted_after_every_gate_has_passed(): void
    {
        $wheel = $this->countingWheel();

        $this->postJson('/customer/add-points')->assertStatus(422)->assertJsonPath('blocked', 'no_active_order');
        $this->assertSame(0, $wheel->calls, 'the wheel was spun for a visitor with no order');

        $order = $this->guestOrder();
        for ($i = 0; $i < 7; $i++) {
            $this->postJson('/customer/add-points')->assertOk();
        }
        $this->assertSame(7, $wheel->calls);

        $this->postJson('/customer/add-points')->assertStatus(422)->assertJsonPath('blocked', 'window_exhausted');
        $this->assertSame(7, $wheel->calls, 'the wheel was spun for an 8th spin the window does not have');

        $this->guestOrder();
        $this->gameSwitch('0');
        $this->postJson('/customer/add-points')->assertStatus(422)->assertJsonPath('blocked', 'game_disabled');
        $this->assertSame(7, $wheel->calls, 'the wheel was spun while the game was switched off');

        $this->assertSame(7, GamePlayed::where('order_id', $order->id)->count());
    }

    public function test_the_daily_cap_still_applies_before_an_outcome_is_drawn(): void
    {
        $wheel = $this->countingWheel();
        $this->withoutMiddleware(ThrottleRequests::class);

        $customer = User::create([
            'name'      => 'Spin Outcome Daily Cap',
            'email'     => 'soa-cap-' . Str::lower(Str::random(12)) . '@invalid.local',
            'password'  => 'SpinOutcome!1',
            'role'      => 'customer',
            'is_active' => true,
        ]);

        // Three orders = 21 spins on paper, above the 15-a-day cap.
        foreach (range(1, 3) as $n) {
            Order::create([
                'order_number'   => 'SOA-' . substr(uniqid(), -8),
                'user_id'        => $customer->id,
                'branch_id'      => 1,
                'type'           => 'pick_up',
                'status'         => 'completed',
                'payment_method' => 'cash',
                'payment_status' => 'paid',
                'subtotal'       => 500,
                'total'          => 500,
            ]);
        }

        for ($i = 0; $i < 15; $i++) {
            $this->actingAs($customer, 'customer')->postJson('/customer/add-points')->assertOk();
        }

        $this->actingAs($customer, 'customer')
            ->postJson('/customer/add-points', ['points' => 8])
            ->assertStatus(422)
            ->assertJsonPath('blocked', 'daily_limit');

        $this->assertSame(15, $wheel->calls, 'the wheel was spun past the daily cap');
        $this->assertSame(15, GamePlayed::where('user_id', $customer->id)->count());
    }
}

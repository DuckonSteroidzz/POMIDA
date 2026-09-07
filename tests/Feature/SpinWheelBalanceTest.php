<?php

namespace Tests\Feature;

use App\Http\Controllers\Customer\AuthController;
use App\Models\GamePlayed;
use App\Models\Order;
use App\Models\User;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Spin wheel balance: 7 spins per order, and odds that are not Try-Again heavy.
 *
 * WHY THIS PASS HAPPENED
 * ----------------------
 * The owner played it themselves and it felt like there was no real chance of
 * winning anything — "scammy" rather than fun. Two changes: more spins per
 * order, and a rebalance of what the wheel lands on.
 *
 * This is a product decision about game balance. Nothing here touches
 * validation, rate limiting or access control: GAME_POINT_AWARDS still caps
 * which point VALUES the server will accept, and the per-order and per-day
 * spin gates are unchanged apart from the count itself.
 *
 * WHAT THE ODDS ACTUALLY ARE
 * --------------------------
 * Every segment is drawn the same size and the landing angle is uniform
 * (Math.random() * 2π), so a value's probability is exactly how many segments
 * carry it. Repeating a value in WHEEL_SEGMENTS is the weighting mechanism.
 *
 *   BEFORE (8 segments)            AFTER (12 segments)
 *   3 pts      3/8 = 37.5%         3 pts      4/12 = 33.3%
 *   5 pts      2/8 = 25.0%         5 pts      3/12 = 25.0%
 *   8 pts      1/8 = 12.5%         8 pts      2/12 = 16.7%
 *   Try Again  2/8 = 25.0%         Try Again  3/12 = 25.0%
 *
 * Try Again is deliberately UNCHANGED at 25%. It was already at the good end
 * of the 1-in-4 to 1-in-3 target and was never what made the game feel mean.
 * What improved is the mix of winnings — the top prize is a third more likely
 * — and the number of attempts.
 *
 *   expected points per spin :  3.375  ->  3.583
 *   expected points per order: 16.875  -> 25.083   (5 spins -> 7)
 */
class SpinWheelBalanceTest extends TestCase
{
    use DatabaseTransactions;

    /** The distribution the wheel is configured for, as fractions. */
    private const EXPECTED = [
        3 => 4 / 12,
        5 => 3 / 12,
        8 => 2 / 12,
        0 => 3 / 12,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function segments(): array
    {
        return AuthController::WHEEL_SEGMENTS;
    }

    private function constant(string $name): int
    {
        $c = new \ReflectionClass(AuthController::class);
        $p = $c->getConstant($name);

        if ($p === false) {
            $r = new \ReflectionClassConstant(AuthController::class, $name);
            $r->setAccessible(true);
            $p = $r->getValue();
        }

        return (int) $p;
    }

    // ══════════════════════════════════════════════════════════════════
    // The configured odds
    // ══════════════════════════════════════════════════════════════════

    public function test_the_configured_segment_mix_is_what_we_think_it_is(): void
    {
        $segments = $this->segments();
        $this->assertCount(12, $segments, 'the wheel should have 12 segments');

        $counts = array_count_values(array_column($segments, 'points'));

        $this->assertSame(4, $counts[3] ?? 0, '3 pts should appear 4 times');
        $this->assertSame(3, $counts[5] ?? 0, '5 pts should appear 3 times');
        $this->assertSame(2, $counts[8] ?? 0, '8 pts should appear 2 times');
        $this->assertSame(3, $counts[0] ?? 0, 'Try Again should appear 3 times');
    }

    /**
     * The headline requirement: Try Again must not dominate. Target was
     * roughly 1-in-4 to 1-in-3.
     */
    public function test_try_again_lands_between_one_in_four_and_one_in_three(): void
    {
        $segments = $this->segments();
        $tryAgain = count(array_filter($segments, fn ($s) => (int) $s['points'] === 0));
        $share = $tryAgain / count($segments);

        $this->assertGreaterThanOrEqual(0.20, $share, 'Try Again is so rare the wheel is not a game any more');
        $this->assertLessThanOrEqual(
            1 / 3,
            $share,
            'Try Again dominates the wheel — it is ' . round($share * 100, 1) . '% of segments'
        );
    }

    /**
     * Every value the wheel can land on must be one the server will accept.
     * If these drift apart, a legitimate spin gets a 422 and the customer
     * loses a spin for nothing.
     */
    public function test_every_segment_value_is_in_the_server_allowlist(): void
    {
        $allowed = (array) (new \ReflectionClass(AuthController::class))
            ->getConstant('GAME_POINT_AWARDS');

        if (! $allowed) {
            $r = new \ReflectionClassConstant(AuthController::class, 'GAME_POINT_AWARDS');
            $r->setAccessible(true);
            $allowed = (array) $r->getValue();
        }

        foreach ($this->segments() as $segment) {
            $this->assertContains(
                (int) $segment['points'],
                $allowed,
                'segment "' . $segment['label'] . '" awards ' . $segment['points']
                . ', which GAME_POINT_AWARDS would refuse'
            );
        }
    }

    /**
     * The `type` field the view branches on must agree with the points value,
     * or a 0-point landing would render as a win.
     */
    public function test_the_type_field_agrees_with_the_points_value(): void
    {
        foreach ($this->segments() as $segment) {
            $expected = (int) $segment['points'] === 0 ? 'lose' : 'points';

            $this->assertSame(
                $expected,
                $segment['type'],
                'segment "' . $segment['label'] . '" has type ' . $segment['type']
                . ' but awards ' . $segment['points']
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // The statistical test
    // ══════════════════════════════════════════════════════════════════

    /**
     * Simulate the real selection mechanism a large number of times and check
     * the distribution lands near the configured weights.
     *
     * The wheel picks by UNIFORM ANGLE over equal-sized segments, so this
     * reproduces that exactly — a uniform draw over the segment count — rather
     * than sampling the weights directly, which would only prove the weights
     * equal themselves.
     *
     * Tolerance: 60,000 spins, ±2 percentage points. The standard error on a
     * p≈0.25 proportion at n=60,000 is about 0.18pp, so 2pp is roughly 11
     * sigma — comfortably wide enough never to flake, and still tight enough
     * to catch any real mis-weighting (the smallest change that matters here,
     * one segment in twelve, is 8.3pp).
     */
    public function test_simulated_spins_land_close_to_the_configured_odds(): void
    {
        $segments = $this->segments();
        $n = count($segments);
        $spins = 60000;
        $tolerance = 0.02;

        $observed = [0 => 0, 3 => 0, 5 => 0, 8 => 0];

        for ($i = 0; $i < $spins; $i++) {
            // Exactly what game.blade.php does: a uniform angle over the
            // circle, floor-divided by the (equal) arc per segment.
            $angle = mt_rand() / mt_getrandmax();          // [0,1)
            $index = (int) floor($angle * $n) % $n;

            $observed[(int) $segments[$index]['points']]++;
        }

        $this->assertSame($spins, array_sum($observed), 'every spin should have landed somewhere');

        foreach (self::EXPECTED as $points => $expectedShare) {
            $actualShare = $observed[$points] / $spins;

            $this->assertEqualsWithDelta(
                $expectedShare,
                $actualShare,
                $tolerance,
                sprintf(
                    '%s came out at %.2f%% over %d spins, expected %.2f%%',
                    $points === 0 ? 'Try Again' : $points . ' pts',
                    $actualShare * 100,
                    $spins,
                    $expectedShare * 100
                )
            );
        }

        // And the headline number the owner cares about.
        $this->assertLessThan(
            0.34,
            $observed[0] / $spins,
            'more than a third of simulated spins were Try Again'
        );
    }

    /** The rebalance must not have made the game worse than it was. */
    public function test_expected_points_per_spin_improved(): void
    {
        $segments = $this->segments();
        $expectedPerSpin = array_sum(array_column($segments, 'points')) / count($segments);

        // Old wheel: (3+5+3+8+3+5+0+0) / 8 = 3.375
        $this->assertGreaterThan(
            3.375,
            $expectedPerSpin,
            'the new wheel pays less per spin than the old one'
        );
        $this->assertEqualsWithDelta(3.583, $expectedPerSpin, 0.001);
    }

    // ══════════════════════════════════════════════════════════════════
    // Spins per order
    // ══════════════════════════════════════════════════════════════════

    public function test_an_order_grants_exactly_seven_spins(): void
    {
        $this->assertSame(7, $this->constant('SPINS_PER_ORDER'));

        $order = $this->guestOrder();

        $state = $this->get('/customer/game')->assertOk()->viewData('spinState');

        $this->assertSame(7, $state['spins_total'], 'the window should offer 7 spins');
        $this->assertSame(7, $state['spins_remaining'], 'a fresh order should have all 7 left');
        $this->assertSame(0, $state['spins_used']);
        $this->assertTrue($state['can_spin']);
        $this->assertSame($order->id, $state['order_id']);
    }

    /**
     * Not 5, not unlimited: the 8th spin on one order is refused.
     *
     * Driven through the real endpoint rather than the counter, because the
     * server re-derives the window on every spin and that is the thing that
     * actually enforces it.
     */
    public function test_the_eighth_spin_on_one_order_is_refused(): void
    {
        $order = $this->guestOrder();

        for ($i = 1; $i <= 7; $i++) {
            $response = $this->postJson('/customer/add-points', ['points' => 3]);

            $response->assertOk();
            $this->assertTrue(
                $response->json('success'),
                "spin {$i} of 7 was refused: " . json_encode($response->json())
            );
        }

        // CONTROL: exactly seven were recorded against this order.
        $this->assertSame(7, GamePlayed::where('order_id', $order->id)->count());

        $eighth = $this->postJson('/customer/add-points', ['points' => 3]);

        $this->assertFalse($eighth->json('success'), 'an 8th spin was allowed on one order');
        $this->assertSame('window_exhausted', $eighth->json('blocked'));
        $this->assertSame(
            7,
            GamePlayed::where('order_id', $order->id)->count(),
            'the refused spin was still recorded'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // Accumulation — spins stack across every eligible order (2026-09-03)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Unused spins ACCUMULATE into the next order rather than being discarded.
     *
     * Changed 2026-09-03: the wheel used to count only the latest active
     * order, so completing one and placing the next silently threw away any
     * spins left on the first. Now every eligible order (anything not
     * cancelled) is worth SPINS_PER_ORDER and the balances stack — one order
     * = 7, two orders = 14 total, minus whatever the ledger says was spent
     * across all of them.
     */
    public function test_unused_spins_accumulate_into_the_next_order(): void
    {
        $first = $this->guestOrder();

        // Use 3 of 7, leaving 4 unused.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/customer/add-points', ['points' => 3])->assertOk();
        }

        $state = $this->get('/customer/game')->viewData('spinState');
        $this->assertSame(4, $state['spins_remaining'], 'CONTROL: 4 should remain after the first order');

        // Complete it and place a second — its spins add on top.
        Order::whereKey($first->id)->update(['status' => 'completed']);
        $second = $this->guestOrder();

        $state = $this->get('/customer/game')->viewData('spinState');

        $this->assertSame($second->id, $state['order_id'], 'the newest order is the attribution order');
        $this->assertSame(14, $state['spins_total'], 'two orders should total 14 spins');
        $this->assertSame(3, $state['spins_used'], 'the 3 spins from the first order still count');
        $this->assertSame(
            11,
            $state['spins_remaining'],
            'the second order should stack: 14 total - 3 used = 11, not a fresh 7'
        );
    }

    /**
     * A completed order keeps granting the spins it contributed — finishing an
     * order must not wipe the accumulated balance.
     */
    public function test_a_completed_order_still_grants_its_spins(): void
    {
        $order = $this->guestOrder();

        // Use 2 of 7 while it is active.
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/customer/add-points', ['points' => 3])->assertOk();
        }

        Order::whereKey($order->id)->update(['status' => 'completed']);

        $state = $this->get('/customer/game')->viewData('spinState');

        $this->assertTrue($state['can_spin'], 'a completed order should still allow its remaining spins');
        $this->assertSame(5, $state['spins_remaining'], '7 granted - 2 used = 5 still available');

        // And the server honours them.
        $this->postJson('/customer/add-points', ['points' => 3])->assertOk()
            ->assertJson(['success' => true]);
    }

    /**
     * With no order at all — not even a completed one — there are still no
     * spins. An order remains the thing that unlocks the wheel.
     */
    public function test_no_order_at_all_means_no_spins(): void
    {
        $state = $this->get('/customer/game')->viewData('spinState');

        $this->assertFalse($state['can_spin']);
        $this->assertSame('no_active_order', $state['blocked']);
        $this->assertSame(0, $state['spins_total']);

        $refused = $this->postJson('/customer/add-points', ['points' => 3]);
        $this->assertFalse($refused->json('success'), 'the server allowed a spin with no order');
    }

    /**
     * A cancelled order grants nothing, so the cancel-and-reorder farming loop
     * the per-day cap guards is not widened by accumulation.
     */
    public function test_a_cancelled_order_grants_no_spins(): void
    {
        $order = $this->guestOrder();
        Order::whereKey($order->id)->update(['status' => 'cancelled']);

        $state = $this->get('/customer/game')->viewData('spinState');

        $this->assertFalse($state['can_spin'], 'a cancelled order still granted spins');
        $this->assertSame(0, $state['spins_total']);
    }

    /**
     * Only TODAY's orders count. Yesterday's order — even completed and never
     * cancelled — grants no spins today, so the balance does not creep upward
     * across every order the customer has ever placed.
     */
    public function test_an_order_from_a_previous_day_grants_no_spins(): void
    {
        $order = $this->guestOrder();
        Order::whereKey($order->id)->update([
            'status'     => 'completed',
            'created_at' => now()->subDay(),
        ]);

        $state = $this->get('/customer/game')->viewData('spinState');

        $this->assertFalse($state['can_spin'], 'a yesterday order still granted spins today');
        $this->assertSame('no_active_order', $state['blocked']);
        $this->assertSame(0, $state['spins_total']);

        $this->postJson('/customer/add-points', ['points' => 3])->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════════════

    /** An order this guest session placed, which is what opens a spin window. */
    private function guestOrder(): Order
    {
        $order = Order::create([
            'order_number'   => 'SWB-' . substr(uniqid(), -8),
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
}

<?php

namespace Tests\Feature;

use App\Models\GamePlayed;
use App\Models\Order;
use App\Models\User;
use App\Models\UserVoucher;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ForcesSpinOutcome;
use Tests\TestCase;

/**
 * Hardening pass F4 (2026-09-27): the owner's Spin & Win on/off switch is
 * enforced by the spin endpoint, not only by the page.
 *
 * WHAT WAS WRONG
 * --------------
 * The Vouchers page toggle writes settings.game_enabled. The only thing that
 * ever read it was customer/game.blade.php, which hides the wheel when it is
 * off. AuthController::addPoints() never looked, so with the game switched OFF
 * a plain POST to /customer/add-points still recorded a spin and credited
 * points (and could mint a voucher). Reproduced before the fix against
 * pomida_db_testing: game_enabled '0', guest with a pending order, POST
 * points=5 -> 200 {"success":true,"total_points":5,...}, games_played +1.
 *
 * HOW THE SWITCH IS STORED (checked before writing the fix)
 * ---------------------------------------------------------
 * Designed global: the seeder and AdminController::toggleGame() both write
 * branch_id NULL. But BOTH pomida_db and pomida_db_testing actually hold one
 * legacy row, id 1, branch_id = 1, value '1', and every reader takes the first
 * game_enabled row with no branch filter. The endpoint therefore reads it the
 * page's way — the last test here pins that the two always agree, including
 * on that legacy shape. (2026-09-27: that legacy row was corrected to global
 * in both databases, and every reader and the toggle now use only the global
 * row, through Setting::gameEnabled(). See GameSwitchSingleRowTest.)
 *
 * Every test sets the switch itself inside its own transaction (rolled back by
 * DatabaseTransactions), so nothing depends on the database's current value.
 */
class GameEnabledServerSideTest extends TestCase
{
    use DatabaseTransactions;
    use ForcesSpinOutcome;

    protected function setUp(): void
    {
        parent::setUp();
        // add-points is throttled per IP; every request here is 127.0.0.1.
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ══════════ fixtures ══════════

    /** Replace whatever game_enabled rows exist with exactly one, in the given shape. */
    private function setGame(?string $value, ?int $branchId = null): void
    {
        DB::table('settings')->where('key', 'game_enabled')->delete();

        if ($value === null) {
            return; // row genuinely absent
        }

        DB::table('settings')->insert([
            'key'        => 'game_enabled',
            'branch_id'  => $branchId,
            'value'      => $value,
            'group'      => 'business',
            'label'      => 'Spin & Win Enabled',
            'type'       => 'text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A pending order placed today by this guest session, which opens a spin window. */
    private function guestOrder(): Order
    {
        $order = Order::create([
            'order_number'   => 'GEF-' . substr(uniqid(), -8),
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

    private function customerWithOrder(): User
    {
        $customer = User::create([
            'name'      => 'Game Flag Customer',
            'email'     => 'gef-' . Str::lower(Str::random(12)) . '@invalid.local',
            'password'  => 'GameFlag!1',
            'role'      => 'customer',
            'is_active' => true,
        ]);

        // Start from 0: one spin (max 8) then stays below every wheel voucher's
        // points_required, so a won voucher cannot move the balance under test.
        $customer->forceFill(['points' => 0])->save();

        Order::create([
            'order_number'   => 'GEF-' . substr(uniqid(), -8),
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

    // ══════════ OFF: refused, nothing written ══════════

    public function test_a_guest_spin_is_refused_when_the_game_is_switched_off(): void
    {
        $this->setGame('0');
        $order = $this->guestOrder();
        $spinsBefore = GamePlayed::count();

        $this->postJson('/customer/add-points', ['points' => 5])
            ->assertStatus(422)
            ->assertJson([
                'success'  => false,
                'blocked'  => 'game_disabled',
                'can_spin' => false,
            ]);

        $this->assertSame($spinsBefore, GamePlayed::count(), 'a refused spin must not be recorded');
        $this->assertSame(0, GamePlayed::where('order_id', $order->id)->count());
        $this->assertSame(0, (int) session('guest_points', 0), 'a refused spin must not credit guest points');
    }

    public function test_a_signed_in_customer_gets_no_points_and_no_voucher_when_the_game_is_off(): void
    {
        $this->setGame('0');
        $customer = $this->customerWithOrder();
        $vouchersBefore = UserVoucher::where('user_id', $customer->id)->count();

        $this->actingAs($customer, 'customer')
            ->postJson('/customer/add-points', ['points' => 8])
            ->assertStatus(422)
            ->assertJsonPath('blocked', 'game_disabled');

        $this->assertSame(0, (int) $customer->fresh()->points, 'points were credited while the game was off');
        $this->assertSame(0, GamePlayed::where('user_id', $customer->id)->count());
        $this->assertSame($vouchersBefore, UserVoucher::where('user_id', $customer->id)->count());
    }

    /** No row at all is "off" — the same safe default the page already applies. */
    public function test_a_missing_setting_row_counts_as_off(): void
    {
        $this->setGame(null);
        $this->guestOrder();
        $spinsBefore = GamePlayed::count();

        $this->postJson('/customer/add-points', ['points' => 3])
            ->assertStatus(422)
            ->assertJsonPath('blocked', 'game_disabled');

        $this->assertSame($spinsBefore, GamePlayed::count());
    }

    /** Refused before anything else runs, so even a malformed spin gets the switch's answer. */
    public function test_the_switch_is_checked_before_the_payload(): void
    {
        $this->setGame('0');
        $this->guestOrder();

        $this->postJson('/customer/add-points', ['points' => 999])
            ->assertStatus(422)
            ->assertJsonPath('blocked', 'game_disabled');
    }

    // ══════════ ON: unchanged ══════════

    public function test_a_guest_spin_still_works_when_the_game_is_on(): void
    {
        $this->setGame('1');
        $this->forceSpinOutcome(5); // the server picks the prize since F3
        $order = $this->guestOrder();

        $this->postJson('/customer/add-points')
            ->assertOk()
            ->assertJson(['success' => true, 'total_points' => 5, 'spin_number' => 1]);

        $this->assertSame(1, GamePlayed::where('order_id', $order->id)->count());
        $this->assertSame(5, (int) session('guest_points'));
    }

    public function test_a_signed_in_customer_is_still_credited_when_the_game_is_on(): void
    {
        $this->setGame('1');
        $this->forceSpinOutcome(8); // the server picks the prize since F3
        $customer = $this->customerWithOrder();

        $this->actingAs($customer, 'customer')
            ->postJson('/customer/add-points')
            ->assertOk()
            ->assertJson(['success' => true, 'total_points' => 8]);

        $this->assertSame(8, (int) $customer->fresh()->points);
        $this->assertSame(1, GamePlayed::where('user_id', $customer->id)->count());
    }

    /** The existing refusals still speak for themselves when the game is on. */
    public function test_with_the_game_on_the_ordinary_no_order_refusal_is_unchanged(): void
    {
        $this->setGame('1');

        $this->postJson('/customer/add-points', ['points' => 5])
            ->assertStatus(422)
            ->assertJsonPath('blocked', 'no_active_order');
    }

    // ══════════ page and endpoint agree ══════════

    public static function storedShapes(): array
    {
        return [
            'global row on'           => ['1', null, true],
            'global row off'          => ['0', null, false],
            // Since 2026-09-27 the switch is the global row only. A branch-1
            // row on its own is not the switch, so the page and the endpoint
            // both read OFF. They still agree, which is what this pins.
            'legacy branch-1 row on'  => ['1', 1, false],
            'legacy branch-1 row off' => ['0', 1, false],
            'row missing'             => [null, null, false],
        ];
    }

    /**
     * @dataProvider storedShapes
     *
     * Whatever shape the row is in, the spin endpoint says yes exactly when the
     * page draws the wheel. Both read Setting::gameEnabled(), the global row.
     * (Before 2026-09-27 both took the first row unfiltered, which is why a
     * legacy branch-1 row once read ON here. See GameSwitchSingleRowTest.)
     */
    public function test_the_endpoint_agrees_with_whether_the_page_shows_the_wheel(?string $value, ?int $branchId, bool $expectOn): void
    {
        $this->setGame($value, $branchId);
        $this->guestOrder();

        $page = $this->get('/customer/game')->assertOk()->getContent();
        $pageShowsWheel = str_contains($page, 'id="spinBtn"');

        $spin = $this->postJson('/customer/add-points', ['points' => 3]);

        $this->assertSame($expectOn, $pageShowsWheel, 'the page did not render the expected state (setup check)');
        $this->assertSame(
            $pageShowsWheel,
            $spin->status() === 200,
            'the endpoint and the page disagree about whether Spin & Win is on'
        );
    }
}

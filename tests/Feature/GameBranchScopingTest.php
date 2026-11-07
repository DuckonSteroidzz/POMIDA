<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Order;
use App\Models\Voucher;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\ForcesSpinOutcome;
use Tests\TestCase;

/**
 * Branch parity audit Phase 3, Finding #5 — Sept 2026.
 *
 * THE GAP THIS CLOSES
 * -------------------
 * Voucher and Ad both carry a nullable branch_id, and
 * Voucher::branchErrorFor() already enforces "NULL = global, an integer =
 * that branch only" at REDEMPTION time (VoucherBranchRedemptionTest). This
 * pass closes the same gap on the two customer-facing reads that never
 * consulted branch_id at all:
 *
 *   AuthController::showGame()          — the wheel's prize legend
 *                                          ($vouchers) and the game-page ad
 *                                          carousel ($gameAds).
 *   AuthController::winnableVoucherFor() — the Spin & Win prize PICKER: what
 *                                          a spin can actually award.
 *
 * Before this fix a branch-3-only voucher's prize showed on every branch's
 * wheel legend and could be WON by a customer at branch 1 — a prize that
 * could be spun and awarded live, then refused by branchErrorFor() the
 * moment they tried to spend it at checkout. A branch-3-only ad played in
 * every branch's game-page carousel the same way.
 *
 * LIVE DATA (read-only, confirmed before this fix): both live vouchers
 * (#23 DISCOUNT, #9293 DISCOUNT-01) and the one live ad (#10, "20% Discount")
 * already have branch_id NULL — global. So this bug had not yet manifested
 * with real data; it was reachable the moment anyone created a
 * branch-scoped voucher or ad, which the admin/supervisor promotion screens
 * already allow. Neither of those rows nor any other pre-existing row is
 * ever written by this file.
 *
 * THE RULE — Voucher's and Ad's OWN existing convention, not a new one:
 *     branch_id NULL -> global. Winnable at every branch.
 *     branch_id N    -> winnable ONLY at branch N.
 * A visitor with no branch selected yet wins only global rows — mirrors
 * Voucher::branchErrorFor()'s own "no branch known" handling.
 *
 * Since QA, Nov 2 2026 the game page LISTS a prize from another branch instead
 * of hiding it, labelled "Not available at this branch". It still cannot be won
 * there (the picker tests below are unchanged). See WheelPrizeStatusTest.
 *
 * Every row this file creates carries the GBSCOPE prefix and runs inside
 * DatabaseTransactions, so nothing survives the run in pomida_db_testing.
 */
class GameBranchScopingTest extends TestCase
{
    use DatabaseTransactions;
    use ForcesSpinOutcome;

    private const PREFIX = 'GBSCOPE';
    private const HOME_BRANCH = 1;
    private const FAR_BRANCH = 2;

    // ══════════════════ fixtures ══════════════════

    /**
     * max_uses is a real positive number so every test in this file is about
     * branch scoping and nothing else.
     */
    private function voucher(?int $branchId, string $suffix, int $pointsRequired = 8): Voucher
    {
        return Voucher::create([
            'branch_id'       => $branchId,
            'code'            => self::PREFIX . '-' . $suffix,
            'description'     => self::PREFIX . ' voucher ' . $suffix,
            'discount_type'   => 'fixed',
            'discount_value'  => 25,
            'max_uses'        => 100,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'is_active'       => true,
            'points_required' => $pointsRequired,
        ]);
    }

    private function ad(?int $branchId, string $suffix): Ad
    {
        return Ad::create([
            'branch_id'  => $branchId,
            'title'      => self::PREFIX . ' Ad ' . $suffix,
            'placement'  => 'game',
            'is_active'  => true,
        ]);
    }

    /** An order this guest session placed today, which is what opens a spin window. */
    private function guestOrder(int $branchId): Order
    {
        $order = Order::create([
            'order_number'   => 'GBS-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => $branchId,
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

    // ══════════════════ showGame() — the wheel's prize legend ══════════════════

    public function test_the_game_page_shows_a_same_branch_voucher(): void
    {
        $voucher = $this->voucher(self::HOME_BRANCH, 'SAMEV');

        $html = $this->withSession(['branch_id' => self::HOME_BRANCH])
            ->get('/customer/game')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($voucher->description, $html);
    }

    public function test_the_game_page_says_a_different_branch_voucher_is_not_available_here(): void
    {
        $voucher = $this->voucher(self::FAR_BRANCH, 'FARV');

        $html = $this->withSession(['branch_id' => self::HOME_BRANCH])
            ->get('/customer/game')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($voucher->description, $html);
        $this->assertStringContainsString('Not available at this branch', $html);
        $this->assertStringNotContainsString('You can win this', $html);
    }

    public function test_the_game_page_shows_a_global_voucher_at_any_branch(): void
    {
        $voucher = $this->voucher(null, 'GLOBALV');

        foreach ([self::HOME_BRANCH, self::FAR_BRANCH] as $branchId) {
            $html = $this->withSession(['branch_id' => $branchId])
                ->get('/customer/game')
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString($voucher->description, $html);
        }
    }

    public function test_a_branchless_visitor_can_only_hope_for_global_vouchers(): void
    {
        $global = $this->voucher(null, 'NOBR-GLOBAL', 8);
        $scoped = $this->voucher(self::HOME_BRANCH, 'NOBR-SCOPED', 8);

        $html = $this->withSession(['branch_id' => null])->get('/customer/game')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/' . preg_quote($global->description, '/') . '.*?data-prize-status\s*>Need 8 more points</s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/' . preg_quote($scoped->description, '/') . '.*?data-prize-status\s*>Not available at this branch</s',
            $html
        );
    }

    // ══════════════════ showGame() — the game-page ad carousel ══════════════════

    public function test_the_game_page_ad_carousel_includes_a_same_branch_ad(): void
    {
        $ad = $this->ad(self::HOME_BRANCH, 'SAMEA');

        $data = $this->withSession(['branch_id' => self::HOME_BRANCH])
            ->get('/customer/game')
            ->assertOk()
            ->viewData('gameAds');

        $this->assertTrue($data->pluck('id')->contains($ad->id), 'a same-branch ad must appear in the carousel');
    }

    public function test_the_game_page_ad_carousel_excludes_a_different_branch_ad(): void
    {
        $ad = $this->ad(self::FAR_BRANCH, 'FARA');

        $data = $this->withSession(['branch_id' => self::HOME_BRANCH])
            ->get('/customer/game')
            ->assertOk()
            ->viewData('gameAds');

        $this->assertFalse($data->pluck('id')->contains($ad->id), 'a different-branch ad must never play in this carousel');
    }

    public function test_the_game_page_ad_carousel_includes_a_global_ad_at_any_branch(): void
    {
        $ad = $this->ad(null, 'GLOBALA');

        foreach ([self::HOME_BRANCH, self::FAR_BRANCH] as $branchId) {
            $data = $this->withSession(['branch_id' => $branchId])
                ->get('/customer/game')
                ->assertOk()
                ->viewData('gameAds');

            $this->assertTrue($data->pluck('id')->contains($ad->id), "a global ad must play at branch {$branchId}");
        }
    }

    public function test_a_branchless_visitor_only_gets_global_ads_in_the_carousel(): void
    {
        $global = $this->ad(null, 'NOBR-GLOBAL-AD');
        $scoped = $this->ad(self::HOME_BRANCH, 'NOBR-SCOPED-AD');

        $data = $this->get('/customer/game')->assertOk()->viewData('gameAds');

        $this->assertTrue($data->pluck('id')->contains($global->id));
        $this->assertFalse($data->pluck('id')->contains($scoped->id));
    }

    // ══════════════════ redeem — what a customer can actually get ══════════════════
    // (Nov 2026: a spin only earns points; the Redeem button issues the voucher,
    // checked by App\Services\PointsRedeem. These used to pin the spin picker.)

    /**
     * A guest at $branchId spins $points (from 0), then presses Redeem on
     * $voucher. The redeem's 'voucher' field, or null when it was refused.
     */
    private function spinFor(int $branchId, int $points, ?Voucher $voucher = null): ?array
    {
        $this->guestOrder($branchId);
        $this->forceSpinOutcome($points); // the server picks the outcome since F3

        $this->withSession(['branch_id' => $branchId, 'guest_points' => 0])
            ->postJson('/customer/add-points')
            ->assertOk()
            ->assertJsonMissingPath('voucher');

        $redeem = $this->postJson('/customer/points/redeem', ['voucher_id' => $voucher->id]);

        return $redeem->status() === 200 ? $redeem->json('voucher') : null;
    }

    public function test_a_spin_can_win_a_same_branch_voucher(): void
    {
        $voucher = $this->voucher(self::HOME_BRANCH, 'WINSAME', pointsRequired: 8);

        $won = $this->spinFor(self::HOME_BRANCH, 8, $voucher);

        $this->assertNotNull($won, 'a same-branch voucher must be winnable at its own branch');
        $this->assertSame($voucher->code, $won['code']);
    }

    public function test_a_spin_cannot_win_a_different_branchs_voucher(): void
    {
        $far = $this->voucher(self::FAR_BRANCH, 'NOWIN', pointsRequired: 8);

        $won = $this->spinFor(self::HOME_BRANCH, 8, $far);

        $this->assertNull(
            $won,
            'a voucher belonging to another branch must never be awarded here — it could be won but never spent'
        );
    }

    /**
     * A SEPARATE global voucher per branch, created ONE AT A TIME right
     * before its own spin — not both up front. Two simultaneously-eligible
     * global vouchers would let inRandomOrder() pick either one on the first
     * spin, which is a real (if rare) flake, not a branch-scoping failure.
     * Creating the second only after the first is already won also means
     * GuestVoucherClaims' "already won" exclusion (see
     * winnableVoucherFor()'s $excludeVoucherIds) never has more than one
     * eligible candidate to choose from at a time.
     */
    public function test_a_spin_can_win_a_global_voucher_at_any_branch(): void
    {
        $first = $this->voucher(null, 'WINGLOBAL1', pointsRequired: 8);
        $wonFirst = $this->spinFor(self::HOME_BRANCH, 8, $first);

        $this->assertNotNull($wonFirst, 'a global voucher must be winnable at branch ' . self::HOME_BRANCH);
        $this->assertSame($first->code, $wonFirst['code']);

        $second = $this->voucher(null, 'WINGLOBAL2', pointsRequired: 8);
        $wonSecond = $this->spinFor(self::FAR_BRANCH, 8, $second);

        $this->assertNotNull($wonSecond, 'a global voucher must be winnable at branch ' . self::FAR_BRANCH);
        $this->assertSame($second->code, $wonSecond['code']);
    }

    public function test_a_branchless_guest_can_only_win_a_global_voucher(): void
    {
        $global = $this->voucher(null, 'NOBR-WINGLOBAL', pointsRequired: 8);
        $scoped = $this->voucher(self::HOME_BRANCH, 'NOBR-NOWIN', pointsRequired: 8);

        $this->guestOrder(self::HOME_BRANCH);
        $this->forceSpinOutcome(8);

        $this->withSession(['guest_points' => 0])
            ->postJson('/customer/add-points')
            ->assertOk();

        // No branch picked: the branch-scoped one is refused, the global one is not.
        $this->postJson('/customer/points/redeem', ['voucher_id' => $scoped->id])
            ->assertStatus(422)
            ->assertJsonPath('status', \App\Support\WheelPrizes::OTHER_BRANCH);

        $won = $this->postJson('/customer/points/redeem', ['voucher_id' => $global->id])
            ->assertOk()
            ->json('voucher');

        $this->assertNotNull($won, 'a global voucher must still be winnable with no branch selected');
        $this->assertSame($global->code, $won['code']);
    }
}

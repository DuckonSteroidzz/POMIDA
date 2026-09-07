<?php

namespace Tests\Feature;

use App\Models\GamePlayed;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Services\PointsRewards;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Points-threshold voucher rewards (2026-09-02).
 *
 * Every PointsRewards::THRESHOLD lifetime points a customer earns entitles
 * them to one voucher reward, which staff hand over at the counter using the
 * same claim-code minting the walk-in flow already uses.
 *
 * THE TWO THINGS THAT WERE EASY TO GET WRONG
 * ------------------------------------------
 * 1. WHICH POINTS COUNT. `users.points` is a spendable balance — addPoints()
 *    subtracts points_required from it when the wheel awards a voucher. A
 *    threshold measured against it would be crossed again every time a
 *    customer spent points and re-earned them, paying out repeatedly for the
 *    same points. Rewards are therefore measured against the append-only
 *    `games_played` ledger, and test_spending_points_on_a_wheel_voucher_does_
 *    not_re_arm_the_reward pins exactly that.
 *
 * 2. FIRING ONCE. The notification must fire on the spin that crosses a
 *    multiple and stay silent on every point after it, until the next one.
 */
class PointsRewardThresholdTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Highest notification id that existed BEFORE this test started.
     *
     * WHY THIS EXISTS (2026-09-02). These five tests began failing
     * deterministically without a single line of the code they cover
     * changing. Proven, not assumed: the commit that introduced this feature
     * (7c7b722) was checked out in a throwaway worktree and run against the
     * same live database — the identical five failed there too, and
     * `git log 7c7b722..HEAD` shows no commit has touched
     * AuthController::addPoints(), PointsRewards, or Notification since. The
     * only variable that changed was the data.
     *
     * What changed in the data: the owner really used the wheel. User 11's
     * spin ledger crosses 60 lifetime points at 2026-09-02 02:52:02, and
     * notification id 4693 (points_reward_earned) carries exactly that
     * timestamp. The production code did precisely the right thing — fired
     * once, at the crossing. rewardNotifications() simply counted every
     * matching row that had EVER existed for the user, so that one real row
     * inflated every count by one ("1 is identical to 0", "2 is identical
     * to 1").
     *
     * A high-water mark fixes it at the root: assertions below count only
     * notifications this test itself caused. Deliberately an ID mark and not
     * a wall-clock filter — two rows can share a second, and a clock
     * comparison would still be a guess about which row belongs to whom.
     */
    private int $notificationMark = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notificationMark = (int) Notification::max('id');
    }

    /**
     * A customer created by and for this test.
     *
     * The previous version reached for the live pedro@gmail.com account and
     * then DELETED its games_played rows and reset its points — relying on
     * the transaction rollback to put a real customer's history back. Even
     * rolled back, that is someone's real data, and it is what left these
     * tests exposed to that account's real activity in the first place. A
     * fresh account starts with no spins, no points, no reward claims and no
     * notifications, so nobody else's rows can reach these assertions.
     */
    private function customer(): User
    {
        return User::create([
            'name'      => 'Points Reward Test Customer',
            'email'     => 'points-reward-test-' . uniqid() . '@example.test',
            'password'  => Hash::make('irrelevant-' . uniqid()),
            'role'      => 'customer',
            'points'    => 0,
            'is_active' => true,
        ]);
    }

    /** An order that opens a spin window for this customer. */
    private function order(User $user): Order
    {
        return Order::create([
            'order_number'   => 'PRT-' . substr(uniqid(), -8),
            'user_id'        => $user->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 500,
            'total'          => 500,
        ]);
    }

    private function spin(int $points = 8)
    {
        return $this->postJson('/customer/add-points', ['points' => $points]);
    }

    /**
     * Reward notifications THIS TEST caused for this customer.
     *
     * Both filters are load-bearing and neither is a weakening: the id mark
     * excludes rows that already existed when the test began, and user_id
     * excludes rows belonging to anyone else. The assertions they feed are
     * unchanged — every one still fails if the feature stops firing, fires
     * twice, or fires at the wrong moment (proven by sabotage).
     */
    private function rewardNotifications(User $user): int
    {
        return Notification::where('id', '>', $this->notificationMark)
            ->where('user_id', $user->id)
            ->where('type', 'points_reward_earned')
            ->count();
    }

    /**
     * Credit the ledger directly, for tests about the ARITHMETIC rather than
     * about the spin endpoint. Uses the same column the real spin writes.
     */
    private function grantLifetimePoints(User $user, int $points): void
    {
        GamePlayed::create([
            'user_id'        => $user->id,
            'order_id'       => $this->order($user)->id,
            'spin_number'    => 1,
            'points_awarded' => $points,
        ]);
    }

    // ══════════ the threshold itself ══════════

    public function test_the_threshold_is_a_named_constant(): void
    {
        // Pinned so the number cannot quietly drift, and so the worked example
        // in the docs stays true.
        $this->assertSame(30, PointsRewards::THRESHOLD);
    }

    // ══════════ 1. firing exactly once ══════════

    public function test_no_notification_before_the_threshold_is_reached(): void
    {
        $user = $this->customer();
        $this->order($user);
        $this->actingAs($user, 'customer');

        // 3 x 8 = 24, short of 30.
        for ($i = 0; $i < 3; $i++) {
            $this->spin(8)->assertOk();
        }

        $this->assertSame(24, PointsRewards::lifetimePointsFor($user->refresh()));
        $this->assertSame(0, $this->rewardNotifications($user));
    }

    public function test_the_notification_fires_on_the_spin_that_crosses_the_threshold(): void
    {
        $user = $this->customer();
        $this->order($user);
        $this->actingAs($user, 'customer');

        // 4 x 8 = 32, crossing 30 on the fourth.
        for ($i = 0; $i < 4; $i++) {
            $this->spin(8)->assertOk();
        }

        $this->assertSame(
            1,
            $this->rewardNotifications($user),
            'crossing the threshold did not tell the customer'
        );

        $notification = Notification::where('id', '>', $this->notificationMark)
            ->where('user_id', $user->id)
            ->where('type', 'points_reward_earned')
            ->firstOrFail();

        // Delivered through the same bell as order updates, to the customer.
        $this->assertSame(Notification::AUDIENCE_CUSTOMER, $notification->audience);
        $this->assertStringContainsString('30 points', $notification->message);
        $this->assertStringContainsString('staff', $notification->message);

        // Not an order notification — this is the first non-order row in the
        // table, which is what order_id being nullable was left for.
        $this->assertNull($notification->order_id);
    }

    public function test_it_does_not_fire_again_on_every_point_after_the_threshold(): void
    {
        $user = $this->customer();
        $this->order($user);
        $this->actingAs($user, 'customer');

        // Cross 30 (32), then keep spinning well short of 60.
        for ($i = 0; $i < 7; $i++) {
            $this->spin(8)->assertOk();
        }

        // 7 x 8 = 56: past 30, not yet 60.
        $this->assertSame(56, PointsRewards::lifetimePointsFor($user->refresh()));

        $this->assertSame(
            1,
            $this->rewardNotifications($user),
            'the customer was congratulated again for points that earned nothing new'
        );
    }

    public function test_a_zero_point_spin_never_notifies(): void
    {
        $user = $this->customer();
        $this->order($user);
        $this->actingAs($user, 'customer');

        // Land exactly on 30, then take a "Try Again". The before and after
        // totals are equal, so no new multiple can have been crossed.
        for ($i = 0; $i < 6; $i++) {
            $this->spin(5)->assertOk();
        }

        $this->assertSame(30, PointsRewards::lifetimePointsFor($user->refresh()));
        $this->assertSame(1, $this->rewardNotifications($user));

        $this->spin(0)->assertOk();

        $this->assertSame(
            1,
            $this->rewardNotifications($user),
            'a losing spin re-fired the reward notification'
        );
    }

    public function test_the_second_crossing_notifies_again_at_the_next_multiple(): void
    {
        $user = $this->customer();
        $this->order($user);
        $this->actingAs($user, 'customer');

        // One order caps at SPINS_PER_ORDER spins, so reaching 60 needs a
        // second order — which is also the realistic shape of a customer
        // earning a second reward on a later visit.
        for ($i = 0; $i < 7; $i++) {
            $this->spin(8)->assertOk();
        }

        $this->assertSame(1, $this->rewardNotifications($user), 'CONTROL: one so far');

        $this->order($user);

        // 56 -> 64, crossing 60.
        $this->spin(8)->assertOk();

        $this->assertSame(64, PointsRewards::lifetimePointsFor($user->refresh()));
        $this->assertSame(
            2,
            $this->rewardNotifications($user),
            'crossing the second threshold did not notify'
        );

        $messages = Notification::where('id', '>', $this->notificationMark)
            ->where('user_id', $user->id)
            ->where('type', 'points_reward_earned')
            ->orderBy('id')
            ->pluck('message')
            ->all();

        // Each names its own milestone, not the same one twice.
        $this->assertStringContainsString('30 points', $messages[0]);
        $this->assertStringContainsString('60 points', $messages[1]);
    }

    public function test_spending_points_on_a_wheel_voucher_does_not_re_arm_the_reward(): void
    {
        /*
         * The reason rewards are measured against the ledger and not
         * users.points. Simulate the wheel having spent the balance — which
         * addPoints() really does — and confirm the reward count holds.
         */
        $user = $this->customer();
        $this->grantLifetimePoints($user, 30);

        $this->assertSame(1, PointsRewards::earnedFor($user->refresh()));

        // The wheel deducts from the spendable balance.
        $user->points = 0;
        $user->save();

        $this->assertSame(
            1,
            PointsRewards::earnedFor($user->refresh()),
            'spending points erased a reward the customer had already earned'
        );
        $this->assertSame(30, PointsRewards::lifetimePointsFor($user->refresh()));
    }

    // ══════════ 2. the staff-side counts ══════════

    public function test_the_unclaimed_count_is_correct_after_zero_one_and_two_claims(): void
    {
        $user = $this->customer();

        // The worked example: 65 lifetime points = 2 rewards earned.
        $this->grantLifetimePoints($user, 65);
        $user->refresh();

        $this->assertSame(65, PointsRewards::lifetimePointsFor($user));
        $this->assertSame(2, PointsRewards::earnedFor($user));

        // 0 claimed -> 2 unclaimed
        $this->assertSame(2, PointsRewards::unclaimedFor($user));

        // 1 claimed -> 1 unclaimed
        $user->reward_claims = 1;
        $user->save();
        $this->assertSame(1, PointsRewards::unclaimedFor($user->refresh()));

        // 2 claimed -> 0 unclaimed
        $user->reward_claims = 2;
        $user->save();
        $this->assertSame(0, PointsRewards::unclaimedFor($user->refresh()));
    }

    public function test_points_are_never_deducted_when_a_reward_is_claimed(): void
    {
        $user = $this->customer();
        $this->grantLifetimePoints($user, 65);

        $voucher = $this->issuableVoucher();

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/vouchers/' . $voucher->id . '/issue-reward', [
                'customer_id' => $user->id,
            ]);

        $user->refresh();

        // The lifetime figure is a running history and must survive a claim.
        $this->assertSame(65, PointsRewards::lifetimePointsFor($user));
        $this->assertSame(2, PointsRewards::earnedFor($user));
        $this->assertSame(1, (int) $user->reward_claims);
        $this->assertSame(1, PointsRewards::unclaimedFor($user));
    }

    /**
     * The staff-facing "Points Rewards" lookup card was removed from
     * vouchers.blade.php in the 2026 voucher/UI overhaul (rewards are still
     * earned and still issued through the issue-reward endpoint, but the
     * counter no longer surfaces a name/email lookup panel). The server still
     * accepts the legacy ?customer= query parameter without error, which is
     * all this test now pins.
     */
    public function test_the_voucher_page_still_loads_with_the_legacy_customer_param(): void
    {
        $user = $this->customer();
        $this->grantLifetimePoints($user, 65);
        $user->reward_claims = 1;
        $user->save();

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/vouchers?customer=' . urlencode($user->email))
            ->assertOk();
    }

    // ══════════ 3. the issuance ceiling ══════════

    public function test_staff_cannot_issue_more_reward_codes_than_are_unclaimed(): void
    {
        $user = $this->customer();

        // Exactly one reward earned.
        $this->grantLifetimePoints($user, 30);

        $voucher = $this->issuableVoucher();
        $admin = $this->admin();

        // First issue: allowed.
        $this->actingAs($admin, 'admin')
            ->post('/admin/vouchers/' . $voucher->id . '/issue-reward', ['customer_id' => $user->id]);

        $this->assertSame(1, (int) $user->refresh()->reward_claims);

        $claimsAfterFirst = \App\Models\UserVoucher::where('issued_by', $admin->id)->count();

        // Second issue: refused, because nothing is left unclaimed.
        $this->actingAs($admin, 'admin')
            ->post('/admin/vouchers/' . $voucher->id . '/issue-reward', ['customer_id' => $user->id]);

        $this->assertSame(
            1,
            (int) $user->refresh()->reward_claims,
            'staff issued a second reward the customer had not earned'
        );

        $this->assertSame(
            $claimsAfterFirst,
            \App\Models\UserVoucher::where('issued_by', $admin->id)->count(),
            'a refused reward still minted a claim code'
        );
    }

    public function test_a_customer_with_no_points_gets_no_reward_code(): void
    {
        $user = $this->customer();   // zero lifetime points
        $voucher = $this->issuableVoucher();

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/vouchers/' . $voucher->id . '/issue-reward', ['customer_id' => $user->id]);

        $this->assertSame(0, (int) $user->refresh()->reward_claims);
    }

    // ══════════ 4. the existing walk-in flow is untouched ══════════

    public function test_the_no_points_walk_in_issuance_still_works_unchanged(): void
    {
        /*
         * The Pass 9 flow has no customer and no points requirement, and must
         * keep working for someone who never earned anything. It is a separate
         * endpoint precisely so the reward ceiling cannot leak into it.
         */
        $voucher = $this->issuableVoucher();
        $admin = $this->admin();

        $before = \App\Models\UserVoucher::where('voucher_id', $voucher->id)->count();

        $this->actingAs($admin, 'admin')
            ->post('/admin/vouchers/' . $voucher->id . '/issue-code')
            ->assertRedirect();

        $this->assertSame(
            $before + 1,
            \App\Models\UserVoucher::where('voucher_id', $voucher->id)->count(),
            'the walk-in issuance flow stopped minting codes'
        );
    }

    // ══════════ helpers ══════════

    private function admin(): User
    {
        return User::where('role', 'admin')->firstOrFail();
    }

    private function issuableVoucher(): Voucher
    {
        $voucher = Voucher::create([
            'code'            => 'RWD' . strtoupper(substr(uniqid(), -6)),
            'description'     => 'Points reward test voucher',
            'discount_type'   => 'fixed',
            'discount_value'  => 50,
            'max_uses'        => 100,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 0,
            'is_active'       => true,
        ]);

        $this->assertNull(
            $voucher->issuanceErrorFor(),
            'the test voucher must be issuable, or these tests prove nothing'
        );

        return $voucher;
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FreeDessertReward;
use App\Models\LoyaltyAward;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Voucher;
use App\Services\FreeDesserts;
use App\Services\StreakRewardSettings;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ConfiguresStreakReward;
use Tests\TestCase;

/**
 * QA, 3 Nov 2026: the owner set an "All Branches" stamp reward, but the Main
 * Branch stamp card still said "The stamp card isn't available at this branch
 * yet." QA expects the card at EVERY branch the customer ordered from.
 *
 * The card went missing whenever a branch had no reward it could give — no
 * reward chosen, an archived or deleted item, a reward that belongs to one
 * branch, or a branch with settings of its own that hid the All Branches
 * reward — and nothing told the owner. So:
 *
 *   PART 1 (admin) — All Branches only offers, and only saves, a reward that
 *     works at every branch; an EXISTING broken setting is named, with its real
 *     reason per branch, in a banner and in that branch's Branch Overview row.
 *   PART 2 (customer) — a branch whose card is ON always shows the real card
 *     with the customer's real stamps; with no usable reward it says "Reward
 *     coming soon" and issues nothing. Only a switched-off branch keeps the
 *     "isn't available" card; the owner's master switch still hides it all.
 *
 * Stamps are never counted differently and no setting or loyalty row is
 * changed by any of this. Every row is created inside DatabaseTransactions.
 */
class StampCardAllBranchesTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresStreakReward;

    // 12:00 Manila on 3 Nov 2026.
    private const NOW_UTC = '2026-11-03 04:00:00';
    private const PASSWORD = 'Aa1!aaaaaa';
    private const SOON = 'Reward coming soon';
    private const NOTE = 'Keep collecting. Your reward will be added once the cafe sets it up.';
    private const UNAVAILABLE = "The stamp card isn't available at this branch yet.";

    private Branch $main;
    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());

        $this->travelTo(CarbonImmutable::parse(self::NOW_UTC, 'UTC'));
        Cache::flush();

        config([
            'app.shop_timezone'                => 'Asia/Manila',
            'loyalty.order_bonus_points'       => 3,
            'loyalty.streak_milestones'        => [3 => 10, 7 => 25],
            'loyalty.free_dessert_visit_days'  => 7,
            'loyalty.free_dessert_counts_from' => '2026-09-01',
            'loyalty.free_dessert_valid_days'  => 30,
        ]);

        $this->clearStreakRewardSettings();
        DB::table('settings')->where('key', Setting::STREAK_CALENDAR_ENABLED)->delete();

        $this->main = $this->branch('AB Main');
        $this->other = $this->branch('AB Other');
    }

    // ══════════ fixtures ══════════

    private function branch(string $name): Branch
    {
        return Branch::create([
            'name'      => $name . ' ' . uniqid(),
            'code'      => 'AB' . strtoupper(substr(uniqid(), -7)),
            'address'   => 'AB address',
            'is_active' => true,
        ]);
    }

    private function voucher(?Branch $branch, array $attrs = []): Voucher
    {
        return Voucher::create(array_merge([
            'branch_id'       => $branch?->id,
            'code'            => 'AB' . strtoupper(Str::random(6)),
            'description'     => 'All-branches template',
            'discount_type'   => 'percent',
            'discount_value'  => 10,
            'max_uses'        => 5,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 0,
            'is_active'       => true,
        ], $attrs))->fresh();
    }

    private function item(?Branch $branch, string $name = ''): MenuItem
    {
        return $this->plainRewardItem(['branch_id' => $branch?->id, 'name' => $name ?: 'AB Cake ' . uniqid()]);
    }

    private function customer(): User
    {
        return User::create([
            'name'              => 'AB Customer',
            'email'             => 'ab-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => self::PASSWORD,
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ])->fresh();
    }

    private function portalUser(string $role, ?Branch $branch = null): User
    {
        return User::create([
            'name'              => 'AB ' . $role,
            'email'             => 'ab-' . $role . '-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => self::PASSWORD,
            'role'              => $role,
            'branch_id'         => $role === 'admin' ? null : $branch?->id,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function orderOn(User $user, string $manilaDay, Branch $branch, string $status = 'completed'): Order
    {
        $at = CarbonImmutable::parse($manilaDay . ' 12:00:00', 'Asia/Manila')->utc()->format('Y-m-d H:i:s');

        $order = Order::create([
            'order_number'   => 'AB-' . strtoupper(Str::random(8)),
            'user_id'        => $user->id,
            'branch_id'      => $branch->id,
            'type'           => 'pick_up',
            'status'         => $status,
            'payment_method' => 'cash',
            'payment_status' => $status === 'completed' ? 'paid' : 'pending',
            'subtotal'       => 100,
            'total'          => 100,
            'completed_at'   => $status === 'completed' ? $at : null,
        ]);

        // A stamp day is the day the order was PLACED (created_at, not fillable).
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $at]);

        return $order->fresh();
    }

    /** $count completed orders at $branch on $count different October days. */
    private function stampsAt(User $user, Branch $branch, int $count): void
    {
        for ($day = 1; $day <= $count; $day++) {
            $this->orderOn($user, sprintf('2026-10-%02d', $day), $branch);
        }
    }

    private function showCardOf(User $user, Branch $branch): void
    {
        DB::table('users')->where('id', $user->id)->update(['rewards_branch_id' => $branch->id]);
    }

    private function account(User $user): string
    {
        return $this->actingAs($user->fresh(), 'customer')
            ->withSession(['branch_id' => null, 'order_type' => null, 'table_number' => null])
            ->get(route('customer.account'))->assertOk()->getContent();
    }

    private function vouchers(User $user): string
    {
        return $this->actingAs($user->fresh(), 'customer')
            ->withSession(['branch_id' => null, 'order_type' => null, 'table_number' => null])
            ->get(route('customer.vouchers'))->assertOk()->getContent();
    }

    private function adminPage(User $viewer, array $query = []): string
    {
        return $this->actingAs($viewer, 'admin')->get(route('admin.streak-rewards', $query))->assertOk()->getContent();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function text(DOMXPath $x, string $query): ?string
    {
        $node = $x->query($query)->item(0);

        return $node ? trim(preg_replace('/\s+/', ' ', $node->textContent)) : null;
    }

    /** The banner line for $branch, or null. */
    private function bannerLine(string $html, Branch $branch): ?string
    {
        return $this->text($this->xpath($html), '//*[@data-testid="streak-rewards-broken-row"][@data-branch="' . $branch->id . '"]');
    }

    /** The Branch Overview row's warning for $branch, or null. */
    private function overviewLine(string $html, Branch $branch): ?string
    {
        return $this->text($this->xpath($html), '//*[@data-branch-row="' . $branch->id . '"]//*[@data-testid="streak-rewards-overview-warning"]');
    }

    /** What the customer's page shows for the card: 'card', 'unavailable' or 'none'; plus whether it reads "coming soon". */
    private function cardState(string $html): array
    {
        $x = $this->xpath($html);

        return [
            $x->query('//*[@data-testid="free-dessert-card"]')->length === 1 ? 'card'
                : ($x->query('//*[@data-testid="stamp-card-unavailable"]')->length === 1 ? 'unavailable' : 'none'),
            str_contains($html, self::SOON),
        ];
    }

    private function completeToday(User $user, Branch $branch): Order
    {
        $order = $this->orderOn($user, '2026-11-03', $branch, 'pending');

        $this->actingAs($this->portalUser('admin'), 'admin')
            ->put(route('admin.orders.complete', $order->id))
            ->assertRedirect();

        $this->assertSame('completed', $order->fresh()->status);

        return $order;
    }

    // ══════════ PART 1 — the admin cannot save a reward that will not work ══════════

    public function test_all_branches_offers_only_vouchers_and_items_that_work_at_every_branch(): void
    {
        $everywhere = $this->voucher(null, ['code' => 'ABEVERY' . strtoupper(Str::random(3))]);
        $mainOnly = $this->voucher($this->main, ['code' => 'ABMAIN' . strtoupper(Str::random(3))]);
        $branchItem = $this->item($this->main, 'AB Main Only Cake');

        $html = $this->adminPage($this->portalUser('admin'), ['streak_branch' => 'default']);

        $start = strpos($html, 'id="srVoucher"');
        $this->assertNotFalse($start);
        $select = substr($html, $start, strpos($html, '</select>', $start) - $start);
        $this->assertStringContainsString('<option value="' . $everywhere->id . '"', $select);
        $this->assertStringNotContainsString('<option value="' . $mainOnly->id . '"', $select, 'a one-branch voucher was offered as the All Branches reward');

        // No item is sold at every branch, so the picker is replaced by the "pick a branch" panel.
        $this->assertSame(0, MenuItem::whereNull('branch_id')->count());
        $this->assertStringContainsString('data-testid="streak-rewards-item-per-branch"', $html);
        $this->assertStringNotContainsString('<option value="' . $branchItem->id . '"', $html);

        // One sold at every branch IS offered.
        $global = $this->item(null, 'AB Everywhere Cake');
        $html = $this->adminPage($this->portalUser('admin'), ['streak_branch' => 'default', 'reward' => 'menu_item']);
        $this->assertStringContainsString('<option value="' . $global->id . '"', $html);
        $this->assertStringNotContainsString('<option value="' . $branchItem->id . '"', $html);
    }

    public function test_the_server_refuses_a_branch_only_or_archived_reward_as_the_all_branches_reward(): void
    {
        $admin = $this->portalUser('admin');
        $mainOnly = $this->voucher($this->main);
        $branchItem = $this->item($this->main, 'AB Branch Cake');
        $archived = $this->item(null, 'AB Archived Cake');
        DB::table('menu_items')->where('id', $archived->id)->update(['archived_at' => now()]);

        $payload = ['branch_id' => 'default', 'days' => 5, 'valid_days' => 10];
        $rowsBefore = Setting::whereNull('branch_id')->whereIn('key', StreakRewardSettings::KEYS)->count();

        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $payload + ['reward_type' => 'voucher', 'voucher_id' => $mainOnly->id])
            ->assertSessionHasErrors(['voucher_id' => 'That voucher is only for ' . $this->main->name . ', so it cannot be the All Branches reward. Choose a voucher for all branches.'], null, 'streakRewards');

        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $payload + ['reward_type' => 'menu_item', 'menu_item_id' => $branchItem->id])
            ->assertSessionHasErrors(['menu_item_id' => 'AB Branch Cake is only sold at ' . $this->main->name . ', so it cannot be the All Branches reward. Choose a voucher for all branches, or pick that branch to give it a menu item of its own.'], null, 'streakRewards');

        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $payload + ['reward_type' => 'menu_item', 'menu_item_id' => $archived->id])
            ->assertSessionHasErrors(['menu_item_id' => 'Choose a menu item that is still on the menu.'], null, 'streakRewards');

        $this->assertSame($rowsBefore, Setting::whereNull('branch_id')->whereIn('key', StreakRewardSettings::KEYS)->count(), 'a refused save wrote something');

        // A company-wide voucher saves, and every branch then works.
        $ok = $this->voucher(null);
        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $payload + ['reward_type' => 'voucher', 'voucher_id' => $ok->id])
            ->assertSessionHasNoErrors();
        $this->assertTrue(StreakRewardSettings::current($this->main->id)['configured']);
        $this->assertTrue(StreakRewardSettings::current($this->other->id)['configured']);
    }

    // ══════════ PART 1 — an existing broken setting is named, with its real reason ══════════

    public function test_a_working_setup_shows_no_warning_anywhere(): void
    {
        $this->chooseRewardVoucher($this->voucher(null));

        $html = $this->adminPage($this->portalUser('admin'));

        $this->assertStringNotContainsString('data-testid="streak-rewards-broken"', $html);
        $this->assertStringNotContainsString('data-testid="streak-rewards-overview-warning"', $html);
    }

    public function test_an_all_branches_reward_that_belongs_to_one_branch_warns_every_other_branch_with_the_real_reason(): void
    {
        $voucher = $this->voucher($this->main, ['code' => 'ABONLYMAIN']);
        $this->chooseRewardVoucher($voucher);

        $html = $this->adminPage($this->portalUser('admin'));

        $expected = $this->other->name . ': no reward can be given, so customers see "Reward coming soon" and a full stamp card earns nothing. '
            . 'Reason: the All Branches reward (voucher ABONLYMAIN) belongs only to ' . $this->main->name . '. Choose a company-wide reward.';

        // Banner at the top of the page AND the branch's own Branch Overview row.
        $this->assertSame($expected, $this->bannerLine($html, $this->other) === null ? null : preg_replace('/ Fix it$/', '', $this->bannerLine($html, $this->other)));
        $this->assertSame($expected, $this->overviewLine($html, $this->other));

        // The branch the voucher does belong to is fine: no line, no warning.
        $this->assertNull($this->bannerLine($html, $this->main));
        $this->assertNull($this->overviewLine($html, $this->main));

        // The "Fix it" link goes to the All Branches tab, where the reward is chosen.
        $this->assertStringContainsString(e(route('admin.streak-rewards', ['streak_branch' => 'default'])) . '#streak-rewards-card', $html);

        // The All Branches row says it too.
        $this->assertStringContainsString('All Branches cannot give a reward. Reason: the All Branches reward (voucher ABONLYMAIN) belongs only to', $html);
    }

    public function test_an_archived_or_deleted_all_branches_item_is_named(): void
    {
        $item = $this->item(null, 'AB Gone Cake');
        $this->chooseRewardItem($item);

        DB::table('menu_items')->where('id', $item->id)->update(['archived_at' => now()]);
        $html = $this->adminPage($this->portalUser('admin'));
        $this->assertStringContainsString('Reason: the All Branches reward (Free AB Gone Cake) is archived or deleted. Choose a company-wide reward.', $this->overviewLine($html, $this->other));

        // Permanently deleted: the name is gone, the reason still true.
        DB::table('menu_items')->where('id', $item->id)->delete();
        $html = $this->adminPage($this->portalUser('admin'));
        $this->assertStringContainsString('Reason: the All Branches reward (a menu item) is archived or deleted.', $this->overviewLine($html, $this->other));
    }

    public function test_a_branch_with_its_own_settings_but_no_reward_is_said_to_hide_the_all_branches_reward(): void
    {
        $this->chooseRewardVoucher($this->voucher(null));
        // The branch has days of its own, but no reward in its set.
        $this->putStreakRewardSetting(StreakRewardSettings::DAYS, '5', $this->other->id);

        $this->assertFalse(StreakRewardSettings::current($this->other->id)['configured']);
        $this->assertTrue(StreakRewardSettings::current($this->main->id)['configured']);

        $html = $this->adminPage($this->portalUser('admin'));

        $this->assertStringContainsString(
            'Reason: ' . $this->other->name . ' has its own settings but no reward chosen in them, and that hides the All Branches reward. Choose a reward for ' . $this->other->name . ', or switch it back to All Branches.',
            $this->overviewLine($html, $this->other)
        );
        $this->assertNull($this->overviewLine($html, $this->main));
        // The fix link goes to THAT branch's tab, not All Branches.
        $this->assertStringContainsString(e(route('admin.streak-rewards', ['streak_branch' => $this->other->id])) . '#streak-rewards-card', $html);
    }

    public function test_a_branch_reward_that_belongs_to_another_branch_names_both(): void
    {
        $this->chooseRewardVoucher($this->voucher(null));
        $cake = $this->item($this->main, 'AB Main Cake');
        $this->chooseBranchReward($this->other->id, $cake, 5);

        $html = $this->adminPage($this->portalUser('admin'));

        $this->assertStringContainsString(
            'Reason: ' . $this->other->name . "'s own reward (Free AB Main Cake) belongs only to " . $this->main->name . '. Choose a reward for ' . $this->other->name . ', or switch it back to All Branches.',
            $this->overviewLine($html, $this->other)
        );
    }

    public function test_no_reward_chosen_and_unreadable_voucher_terms_are_named(): void
    {
        $html = $this->adminPage($this->portalUser('admin'));
        $this->assertStringContainsString('Reason: no reward is chosen under All Branches. Choose a company-wide reward.', $this->overviewLine($html, $this->main));
        $this->assertStringContainsString('Reason: no reward is chosen under All Branches.', $this->overviewLine($html, $this->other));

        $this->putStreakRewardSetting(StreakRewardSettings::TYPE, StreakRewardSettings::TYPE_VOUCHER);
        $this->putStreakRewardSetting(StreakRewardSettings::VOUCHER, '{not json');
        $this->assertSame(StreakRewardSettings::PROBLEM_VOUCHER_UNREADABLE, StreakRewardSettings::current($this->main->id)['problem']);

        $html = $this->adminPage($this->portalUser('admin'));
        $this->assertStringContainsString("Reason: the All Branches reward has voucher terms that can't be read.", $this->overviewLine($html, $this->main));
    }

    public function test_a_branch_that_is_switched_off_still_warns_and_says_so(): void
    {
        $this->setBranchSwitch($this->other->id, false);

        $html = $this->adminPage($this->portalUser('admin'));

        $this->assertNotNull($this->bannerLine($html, $this->other));
        $this->assertStringContainsString('The stamp card is also switched off at ' . $this->other->name . '.', $this->bannerLine($html, $this->other));
    }

    public function test_a_supervisor_is_warned_about_their_own_branch_only(): void
    {
        $this->chooseRewardVoucher($this->voucher($this->main, ['code' => 'ABSUPMAIN']));

        // The branch the voucher does not belong to: one banner line, theirs.
        $html = $this->adminPage($this->portalUser('supervisor', $this->other));
        $x = $this->xpath($html);
        $this->assertSame(1, $x->query('//*[@data-testid="streak-rewards-broken-row"]')->length);
        $this->assertStringContainsString('Reason: the All Branches reward (voucher ABSUPMAIN) belongs only to ' . $this->main->name, $this->bannerLine($html, $this->other));
        $this->assertStringNotContainsString('Fix it', $this->bannerLine($html, $this->other), 'a supervisor cannot edit All Branches, so no link to it');
        $this->assertStringNotContainsString('data-testid="streak-rewards-overview"', $html);

        // The branch it does fit: nothing.
        $this->assertStringNotContainsString('data-testid="streak-rewards-broken"', $this->adminPage($this->portalUser('supervisor', $this->main)));
    }

    // ══════════ PART 2 — the customer always sees their real stamps ══════════

    public function test_a_valid_reward_shows_the_normal_card_with_the_reward_named(): void
    {
        $cake = $this->item(null, 'AB Valid Cake');
        $this->chooseRewardItem($cake);
        $user = $this->customer();
        $this->stampsAt($user, $this->main, 3);
        $this->showCardOf($user, $this->main);

        foreach ([$this->account($user), $this->vouchers($user)] as $html) {
            $this->assertSame(['card', false], $this->cardState($html));
            $this->assertStringContainsString('Free AB Valid Cake', $html);
            $this->assertStringContainsString('3 / 7 stamps', $html);
            $this->assertStringNotContainsString(self::NOTE, $html);
        }
    }

    public function test_no_reward_chosen_shows_the_real_stamps_and_reward_coming_soon(): void
    {
        $user = $this->customer();
        $this->stampsAt($user, $this->main, 3);
        $this->showCardOf($user, $this->main);

        foreach ([$this->account($user), $this->vouchers($user)] as $html) {
            $this->assertSame(['card', true], $this->cardState($html));
            $this->assertStringNotContainsString(self::UNAVAILABLE, $html);
            $this->assertStringContainsString(self::NOTE, $html);
            // Real stamps and the built-in days needed (config 7: nothing saved).
            $this->assertStringContainsString('3 / 7 stamps', $html);
            $this->assertSame(3, substr_count($html, 'data-stamp="filled"'));
            $this->assertSame(4, substr_count($html, 'data-stamp="empty"'));
            $this->assertStringContainsString($this->main->name, $html);
        }
    }

    public function test_a_branch_with_its_own_days_but_no_reward_shows_its_own_days_needed(): void
    {
        $this->chooseRewardVoucher($this->voucher(null));
        $this->putStreakRewardSetting(StreakRewardSettings::DAYS, '5', $this->other->id);

        $user = $this->customer();
        $this->stampsAt($user, $this->other, 2);
        $this->showCardOf($user, $this->other);

        foreach ([$this->account($user), $this->vouchers($user)] as $html) {
            $this->assertSame(['card', true], $this->cardState($html));
            $this->assertStringContainsString('2 / 5 stamps', $html, "the branch's own days needed");
            $this->assertSame(5, substr_count($html, 'data-stamp='));
        }
    }

    public function test_a_reward_that_belongs_to_another_branch_shows_coming_soon_there_and_the_reward_where_it_fits(): void
    {
        $this->chooseRewardVoucher($this->voucher($this->main));
        $user = $this->customer();
        $this->stampsAt($user, $this->other, 4);
        $this->stampsAt($user, $this->main, 2);

        $this->showCardOf($user, $this->other);
        foreach ([$this->account($user), $this->vouchers($user)] as $html) {
            $this->assertSame(['card', true], $this->cardState($html), 'the branch the reward does not belong to');
            $this->assertStringContainsString('4 / 7 stamps', $html);
        }

        $this->showCardOf($user, $this->main);
        foreach ([$this->account($user), $this->vouchers($user)] as $html) {
            $this->assertSame(['card', false], $this->cardState($html), 'the branch the reward belongs to');
            $this->assertStringContainsString('10% off one order', $html);
            $this->assertStringContainsString('2 / 7 stamps', $html);
        }
    }

    public function test_an_archived_or_deleted_reward_item_shows_coming_soon(): void
    {
        $item = $this->item(null, 'AB Soon Gone Cake');
        $this->chooseRewardItem($item);
        $user = $this->customer();
        $this->stampsAt($user, $this->main, 5);
        $this->showCardOf($user, $this->main);

        $this->assertSame(['card', false], $this->cardState($this->account($user)));

        DB::table('menu_items')->where('id', $item->id)->update(['archived_at' => now()]);
        foreach ([$this->account($user), $this->vouchers($user)] as $html) {
            $this->assertSame(['card', true], $this->cardState($html), 'archived');
            $this->assertStringContainsString('5 / 7 stamps', $html);
        }

        DB::table('menu_items')->where('id', $item->id)->delete();
        foreach ([$this->account($user), $this->vouchers($user)] as $html) {
            $this->assertSame(['card', true], $this->cardState($html), 'deleted');
            $this->assertStringContainsString('5 / 7 stamps', $html);
        }
    }

    public function test_only_a_switched_off_branch_keeps_the_unavailable_card_and_the_master_switch_hides_all(): void
    {
        $user = $this->customer();
        $this->stampsAt($user, $this->main, 3);
        $this->showCardOf($user, $this->main);

        // Switched off: the "isn't available" card, with the stamps saved.
        $this->setBranchSwitch($this->main->id, false);
        foreach ([$this->account($user), $this->vouchers($user)] as $html) {
            $this->assertSame(['unavailable', false], $this->cardState($html));
            $this->assertStringContainsString(self::UNAVAILABLE, $html);
            $this->assertMatchesRegularExpression('/Your 3 stamps at [^<]*are saved\./', $html);
        }

        // Switched off AND no reward: still the "isn't available" card (the switch decides).
        // Switched on, no reward: the card.
        $this->setBranchSwitch($this->main->id, true);
        $this->assertSame(['card', true], $this->cardState($this->account($user)));

        // Master off: nothing, as before.
        Setting::updateOrCreate(['key' => Setting::STREAK_CALENDAR_ENABLED, 'branch_id' => null], ['value' => '0', 'group' => 'loyalty', 'type' => 'text']);
        foreach ([$this->account($user), $this->vouchers($user)] as $html) {
            $this->assertSame(['none', false], $this->cardState($html));
        }
    }

    public function test_my_account_and_vouchers_and_rewards_agree_in_every_case(): void
    {
        $user = $this->customer();
        $this->stampsAt($user, $this->main, 3);
        $this->stampsAt($user, $this->other, 2);

        $cases = [
            'no reward'      => fn () => null,
            'valid reward'   => fn () => $this->chooseRewardVoucher($this->voucher(null)),
            'other branch'   => function () { $this->clearStreakRewardSettings(); $this->chooseRewardVoucher($this->voucher($this->main)); },
            'branch off'     => function () { $this->clearStreakRewardSettings(); $this->setBranchSwitch($this->other->id, false); },
        ];

        foreach ($cases as $label => $arrange) {
            $this->clearStreakRewardSettings();
            $arrange();

            foreach ([$this->main, $this->other] as $branch) {
                $this->showCardOf($user, $branch);
                $this->assertSame($this->cardState($this->account($user)), $this->cardState($this->vouchers($user)), "$label at {$branch->name}");
            }
        }
    }

    public function test_the_account_header_and_benefits_line_follow_the_card(): void
    {
        $user = $this->customer();
        $this->stampsAt($user, $this->main, 3);
        $this->showCardOf($user, $this->main);

        $html = $this->account($user);
        $x = $this->xpath($html);
        $this->assertSame('3/7', $this->text($x, '//*[@data-testid="chip-stamps"]'), 'the header shows the real stamps, not a dash');
        $this->assertStringContainsString('to receive a reward (coming soon).', $this->text($x, '//*[@data-testid="benefit-free-dessert"]'));

        $this->chooseRewardItem($this->item(null, 'AB Header Cake'));
        $x = $this->xpath($this->account($user));
        $this->assertStringContainsString('to receive a Free AB Header Cake.', $this->text($x, '//*[@data-testid="benefit-free-dessert"]'));
    }

    // ══════════ PART 2 — no reward is issued while it cannot be used; what happens next ══════════

    public function test_a_full_card_issues_nothing_while_the_reward_cannot_be_used_and_is_paid_once_a_valid_one_is_chosen(): void
    {
        $user = $this->customer();
        $this->stampsAt($user, $this->other, 15); // two full cards of 7 and one stamp over
        $this->showCardOf($user, $this->other);

        $rewards = fn () => FreeDessertReward::where('user_id', $user->id)->count();
        $ledger = fn () => LoyaltyAward::where('user_id', $user->id)->where('type', LoyaltyAward::TYPE_FREE_DESSERT)->count();

        // 1. No reward / a reward for another branch: nothing is issued, the stamps are still counted.
        foreach ([fn () => null, fn () => $this->chooseRewardVoucher($this->voucher($this->main))] as $arrange) {
            $this->clearStreakRewardSettings();
            $arrange();

            $this->assertSame([], FreeDesserts::grantDue($user, $this->other->id), 'a reward was issued with none that can be used');
            $this->assertSame(0, $rewards());
            $this->assertSame(0, $ledger(), 'a ledger number was used up with nothing to pay');
            $this->assertSame(15, FreeDesserts::countedDays($user, $this->other->id));

            $summary = FreeDesserts::summaryFor($user, $this->other->id);
            $this->assertTrue($summary['enabled']);
            $this->assertFalse($summary['set_up']);
            $this->assertSame(1, $summary['progress']);
            $this->assertSame(2, $summary['waiting'], 'two full cards are waiting for a reward');
            $this->assertNull($summary['reward_text']);
        }

        // 2. A valid reward is chosen. The stamps are untouched, and nothing is
        //    issued until the customer's NEXT completed order at that branch.
        $this->clearStreakRewardSettings();
        $this->chooseRewardVoucher($this->voucher(null));
        $this->assertSame(15, FreeDesserts::countedDays($user, $this->other->id), 'choosing a reward changed the stamps');
        $this->assertSame(0, $rewards());
        $this->assertTrue(FreeDesserts::summaryFor($user, $this->other->id)['set_up']);

        // 3. That next completion pays every card that is due — both — and counts one more stamp.
        $this->completeToday($user, $this->other);

        $this->assertSame(16, FreeDesserts::countedDays($user, $this->other->id));
        $this->assertSame(2, $rewards(), 'both waiting cards are paid at the next completed order');
        $this->assertEqualsCanonicalizing(
            [FreeDesserts::awardKey($this->other->id, 1), FreeDesserts::awardKey($this->other->id, 2)],
            LoyaltyAward::where('user_id', $user->id)->where('type', LoyaltyAward::TYPE_FREE_DESSERT)->pluck('award_key')->all()
        );
        $reward = FreeDessertReward::where('user_id', $user->id)->first();
        $this->assertSame($this->other->id, (int) $reward->branch_id, 'the reward is issued by (and usable at) the branch that earned it');
        $this->assertSame(StreakRewardSettings::TYPE_VOUCHER, $reward->reward_type);

        // A second run adds nothing: the ledger is the guard.
        $this->assertSame([], FreeDesserts::grantDue($user, $this->other->id));
        $this->assertSame(2, $rewards());
    }

    public function test_a_full_card_still_unpaid_after_a_valid_reward_is_chosen_says_when_it_will_be_added(): void
    {
        $user = $this->customer();
        $this->stampsAt($user, $this->other, 7);
        $this->stampsAt($user, $this->main, 3);
        $this->showCardOf($user, $this->other);

        $notice = 'Your card is full! Your reward will be added after your next completed order at ' . $this->other->name . '.';

        // No reward yet: the "coming soon" card (with its own waiting line), not this notice.
        foreach ([$this->account($user), $this->vouchers($user)] as $html) {
            $this->assertStringNotContainsString('data-testid="free-dessert-owed"', $html);
            $this->assertStringContainsString('data-testid="free-dessert-waiting"', $html);
        }

        // A valid reward is chosen: the full card is owed, and the card says when.
        $this->chooseRewardVoucher($this->voucher(null));
        $this->assertSame(1, FreeDesserts::summaryFor($user, $this->other->id)['owed']);
        foreach ([$this->account($user), $this->vouchers($user)] as $html) {
            $this->assertSame(['card', false], $this->cardState($html));
            $this->assertStringContainsString($notice, preg_replace('/\s+/', ' ', strip_tags($html)));
            $this->assertStringNotContainsString('data-testid="free-dessert-waiting"', $html);
        }

        // A card that is not full has nothing owed, so no notice (Main: 3 of 7).
        $this->showCardOf($user, $this->main);
        foreach ([$this->account($user), $this->vouchers($user)] as $html) {
            $this->assertStringNotContainsString('data-testid="free-dessert-owed"', $html);
        }

        // The next completed order at that branch pays it, and the notice goes.
        $this->completeToday($user, $this->other);
        $this->assertSame(0, FreeDesserts::summaryFor($user, $this->other->id)['owed']);
        $this->showCardOf($user, $this->other);
        foreach ([$this->account($user), $this->vouchers($user)] as $html) {
            $this->assertStringNotContainsString('data-testid="free-dessert-owed"', $html);
            $this->assertStringContainsString('1 reward ready to use', $html);
        }
    }

    public function test_no_notice_for_a_customer_who_cannot_earn_rewards_yet(): void
    {
        $this->chooseRewardVoucher($this->voucher(null));
        $user = $this->customer();
        DB::table('users')->where('id', $user->id)->update(['email_verified_at' => null]);
        $this->stampsAt($user, $this->other, 7);
        $this->showCardOf($user, $this->other);

        $this->assertSame(0, FreeDesserts::summaryFor($user->fresh(), $this->other->id)['owed'], 'an unverified customer is never granted, so nothing is "owed"');
        $this->assertStringNotContainsString('data-testid="free-dessert-owed"', $this->account($user));
    }

    public function test_no_stamp_setting_or_loyalty_row_is_written_by_looking_at_the_pages(): void
    {
        $user = $this->customer();
        $this->stampsAt($user, $this->main, 7);
        $this->showCardOf($user, $this->main);

        $snapshot = fn () => [
            DB::table('settings')->orderBy('id')->get()->toJson(),
            DB::table('loyalty_awards')->count(),
            DB::table('free_dessert_rewards')->count(),
            DB::table('orders')->where('user_id', $user->id)->count(),
        ];
        $before = $snapshot();

        $this->account($user);
        $this->vouchers($user);
        $this->adminPage($this->portalUser('admin'));

        $this->assertSame($before, $snapshot());
    }
}

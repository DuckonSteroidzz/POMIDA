<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\StreakRewardController;
use App\Models\Branch;
use App\Models\Category;
use App\Models\FreeDessertReward;
use App\Models\Inventory;
use App\Models\LoyaltyAward;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Voucher;
use App\Services\FreeDesserts;
use App\Services\StreakRewardSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ConfiguresStreakReward;
use Tests\TestCase;

/**
 * Streak Rewards PER BRANCH (Oct 2026).
 *
 * Every branch has its own stamp card: stamps are the Manila days with a
 * completed order AT THAT BRANCH, and each branch has its own days needed,
 * reward, validity and on/off (settings rows with branch_id = the branch),
 * falling back as a whole set to the company default (branch_id NULL) and
 * then to nothing. The owner's master switch turns every branch off. The
 * owner edits any branch and the default; a supervisor only their own
 * branch. A reward is used only at the branch that issued it, and the code
 * check shows a supervisor only their branch's codes. The customer pages
 * show the branch the customer is ordering from. The streak POINTS bonus
 * stays company-wide.
 *
 * Every row is created inside DatabaseTransactions and rolled back.
 */
class StreakRewardsPerBranchTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresStreakReward;

    // 12:00 Manila on 15 Oct 2026.
    private const NOW_UTC = '2026-10-15 04:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());

        $this->travelTo(CarbonImmutable::parse(self::NOW_UTC, 'UTC'));

        config([
            'app.shop_timezone'                => 'Asia/Manila',
            'loyalty.welcome_bonus_points'     => 10,
            'loyalty.order_bonus_points'       => 3,
            'loyalty.streak_milestones'        => [3 => 10, 7 => 0],
            'loyalty.free_dessert_visit_days'  => 7,
            'loyalty.free_dessert_counts_from' => '2026-09-01',
            'loyalty.free_dessert_valid_days'  => 30,
        ]);

        $this->clearStreakRewardSettings();
        DB::table('settings')->where('key', Setting::STREAK_CALENDAR_ENABLED)->delete();

        // The four branches these tests use exist and are named as expected.
        $this->assertSame(4, Branch::whereIn('id', [1, 2, 3, 4])->count());
    }

    // ══════════ fixtures ══════════

    private function customer(string $name = 'Branch Customer'): User
    {
        return User::create([
            'name'              => $name,
            'email'             => 'sr-branch-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ])->fresh();
    }

    private function portalUser(string $role, ?int $branchId = 1): User
    {
        return User::create([
            'name'              => 'SRB ' . $role,
            'email'             => 'sr-branch-' . $role . '-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $role === 'admin' ? null : $branchId,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return $this->portalUser('admin');
    }

    /** A menu item with a recipe and stock at $stockBranch; $branchId null = sold at all branches. */
    private function item(?int $branchId = 1, string $name = '', int $stockBranch = 0): MenuItem
    {
        $inv = Inventory::create([
            'branch_id'       => $stockBranch ?: ($branchId ?? 1),
            'item_name'       => 'SRB Ingredient ' . uniqid(),
            'item_code'       => 'SRB-' . strtoupper(substr(uniqid(), -8)) . random_int(10, 99),
            'quantity'        => 50,
            'unit'            => 'pc',
            'low_stock_alert' => 0,
            'unit_cost'       => 5,
            'is_active'       => true,
        ]);

        $item = MenuItem::create([
            'category_id'   => Category::value('id'),
            'branch_id'     => $branchId,
            'name'          => $name ?: 'SRB Item ' . uniqid(),
            'price'         => 100,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        return $item->fresh();
    }

    private function voucher(?int $branchId = null, array $attrs = []): Voucher
    {
        return Voucher::create(array_merge([
            'branch_id'       => $branchId,
            'code'            => 'SRB' . strtoupper(Str::random(6)),
            'description'     => 'Per-branch template',
            'discount_type'   => 'percent',
            'discount_value'  => 10,
            'max_uses'        => 5,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 0,
            'is_active'       => true,
        ], $attrs))->fresh();
    }

    private function manila(string $local): string
    {
        return CarbonImmutable::parse($local, 'Asia/Manila')->utc()->format('Y-m-d H:i:s');
    }

    private function completedOrderOn(User $user, string $manilaDay, int $branchId): Order
    {
        $order = Order::create([
            'order_number'   => 'SRB-' . strtoupper(Str::random(8)),
            'user_id'        => $user->id,
            'branch_id'      => $branchId,
            'type'           => 'pick_up',
            'status'         => 'completed',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'subtotal'       => 100,
            'total'          => 100,
            'completed_at'   => $this->manila($manilaDay . ' 12:00:00'),
        ]);

        // A stamp day is the day the order was PLACED (created_at, not fillable).
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $this->manila($manilaDay . ' 12:00:00')]);

        return $order->fresh();
    }

    /** Completed orders at $branchId on $count October days from $firstDay, $step days apart (every other day by default). */
    private function stampsAt(User $user, int $branchId, int $count, int $firstDay = 1, int $step = 2): void
    {
        for ($i = 0; $i < $count; $i++) {
            $day = $firstDay + $step * $i;
            $this->assertLessThan(15, $day, 'fixture day is not in the past');
            $this->completedOrderOn($user, sprintf('2026-10-%02d', $day), $branchId);
        }
    }

    /** A pending order at $branchId, completed now by the owner (what pays rewards). */
    private function completeNowAt(User $user, int $branchId): Order
    {
        $order = Order::create([
            'order_number'   => 'SRB-P-' . strtoupper(Str::random(8)),
            'user_id'        => $user->id,
            'branch_id'      => $branchId,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.orders.complete', $order->id))
            ->assertRedirect();
        $this->assertSame('completed', $order->fresh()->status);

        return $order;
    }

    private function cart(MenuItem $item): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => (float) $item->price,
            'quantity'     => 2,
            'image'        => null,
            'options'      => [],
        ]];
    }

    /** Checkout as $user at $branchId. Returns [response, new order or null]. */
    private function place(User $user, array $cart, array $extra, int $branchId): array
    {
        $before = (int) Order::max('id');

        $response = $this->actingAs($user, 'customer')
            ->withSession(['cart' => $cart, 'branch_id' => $branchId, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', array_merge([
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => array_values($cart)[0]['menu_item_id'], 'quantity' => 2]],
            ], $extra));

        return [$response, Order::where('id', '>', $before)->where('user_id', $user->id)->latest('id')->first()];
    }

    private function keys(User $user): array
    {
        return LoyaltyAward::where('user_id', $user->id)->where('type', LoyaltyAward::TYPE_FREE_DESSERT)
            ->orderBy('id')->pluck('award_key')->all();
    }

    /** Every streak reward settings row, as "branch|key" => value ("global" for the company default). */
    private function rows(): array
    {
        return DB::table('settings')
            ->whereIn('key', array_merge(StreakRewardSettings::KEYS, [StreakRewardSettings::BRANCH_ENABLED]))
            ->orderBy('branch_id')->orderBy('key')->get()
            ->mapWithKeys(fn ($r) => [($r->branch_id ?? 'global') . '|' . $r->key => $r->value])
            ->all();
    }

    private function settingsPayload(int|string $branch, MenuItem|Voucher $reward, array $overrides = []): array
    {
        $isItem = $reward instanceof MenuItem;

        return array_merge([
            'branch_id'    => (string) $branch,
            'days'         => 4,
            'reward_type'  => $isItem ? 'menu_item' : 'voucher',
            'menu_item_id' => $isItem ? $reward->id : null,
            'voucher_id'   => $isItem ? null : $reward->id,
            'valid_days'   => 10,
        ], $overrides);
    }

    private function masterOff(): void
    {
        Setting::updateOrCreate(
            ['key' => Setting::STREAK_CALENDAR_ENABLED, 'branch_id' => null],
            ['value' => '0', 'group' => 'business', 'type' => 'text']
        );
    }

    // ══════════ A. storage and fallback ══════════

    public function test_a_branch_without_settings_uses_the_company_default_and_with_neither_nothing_is_granted(): void
    {
        $user = $this->customer();

        // Neither: nothing can be given anywhere, but stamps keep counting.
        foreach ([1, 2, 3, 4] as $branch) {
            $current = StreakRewardSettings::current($branch);
            $this->assertSame(StreakRewardSettings::SOURCE_DEFAULT, $current['source']);
            $this->assertFalse($current['configured']);
            $this->assertSame(StreakRewardSettings::PROBLEM_NOT_CHOSEN, $current['problem']);
            $this->assertSame(7, $current['days'], 'days did not fall back to config');
        }

        $this->stampsAt($user, 1, 6);
        $this->completeNowAt($user, 1);
        $this->assertSame([], $this->keys($user), 'a ledger number was used up with no reward set');
        $this->assertSame(1, FreeDesserts::summaryFor($user, 1)['waiting']);

        // The company default only: every branch follows it.
        $cake = $this->item(null, 'SRB Default Cake');
        $this->chooseRewardItem($cake);
        foreach ([1, 2, 3, 4] as $branch) {
            $this->assertSame('Free SRB Default Cake', StreakRewardSettings::current($branch)['description']);
        }

        // The next completion at branch 1 catches up, issued BY branch 1.
        $this->completeNowAt($user, 1);
        $reward = FreeDessertReward::where('user_id', $user->id)->sole();
        $this->assertSame(1, $reward->branch_id);
        $this->assertSame(['visits:1:1'], $this->keys($user));

        // A branch's own set is used whole, never mixed with the default: a
        // branch row with days only and no reward of its own gives nothing.
        Setting::create(['key' => StreakRewardSettings::DAYS, 'branch_id' => 4, 'value' => '2', 'group' => 'loyalty', 'type' => 'number']);
        $four = StreakRewardSettings::current(4);
        $this->assertSame(StreakRewardSettings::SOURCE_BRANCH, $four['source']);
        $this->assertSame(2, $four['days']);
        $this->assertFalse($four['configured'], 'branch 4 borrowed the default reward into its own set');
        $this->assertTrue(StreakRewardSettings::current(3)['configured'], 'branch 3 lost the default');
    }

    public function test_nothing_changes_on_deploy_the_global_rows_still_decide_every_branch(): void
    {
        // Rows exactly as the previous release wrote them: global only.
        $this->chooseRewardVoucher($this->voucher(null, ['discount_value' => 15]));
        Setting::create(['key' => StreakRewardSettings::DAYS, 'branch_id' => null, 'value' => '5', 'group' => 'loyalty', 'type' => 'number']);

        foreach ([1, 2, 3, 4] as $branch) {
            $this->assertTrue(FreeDesserts::active($branch));
            $this->assertSame(5, FreeDesserts::visitDays($branch));
            $this->assertSame('15% off one order', StreakRewardSettings::current($branch)['description']);
        }
    }

    // ══════════ B. the master switch ══════════

    public function test_the_master_switch_off_turns_every_branch_off_whatever_their_own_switch_says(): void
    {
        $user = $this->customer();
        $this->chooseRewardItem($this->item(null));
        $this->setBranchSwitch(2, true);
        $this->stampsAt($user, 2, 6);

        $this->masterOff();

        foreach ([1, 2, 3, 4] as $branch) {
            $this->assertFalse(FreeDesserts::active($branch), "branch $branch stayed on with the master switch off");
            $this->assertSame(['enabled' => false], FreeDesserts::summaryFor($user, $branch));
        }

        $this->completeNowAt($user, 2);
        $this->assertSame([], $this->keys($user), 'a reward was earned with the master switch off');

        $this->actingAs($user, 'customer')->withSession(['branch_id' => 2])->get(route('customer.account'))->assertOk()
            ->assertDontSee('data-testid="streak-calendar"', false)
            ->assertDontSee('data-testid="free-dessert-card"', false);

        // A supervisor is told, without being shown the switch.
        $this->actingAs($this->portalUser('supervisor', 2), 'admin')->get(route('admin.streak-rewards'))->assertOk()
            ->assertSee('data-testid="streak-rewards-master-off"', false)
            ->assertSee('The owner turned the Streak Calendar off')
            ->assertDontSee(route('admin.streak.toggle'));

        // Back on: the next completion there catches up.
        DB::table('settings')->where('key', Setting::STREAK_CALENDAR_ENABLED)->delete();
        $this->completeNowAt($user, 2);
        $this->assertSame(['visits:2:1'], $this->keys($user));
    }

    // ══════════ C. who may change what ══════════

    public function test_the_owner_edits_any_branch_and_the_company_default(): void
    {
        $admin = $this->admin();
        $branchItem = $this->item(2, 'SRB Branch Two Cake');

        // A branch: rows with that branch_id; the others are untouched.
        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $this->settingsPayload(2, $branchItem))
            ->assertRedirect(route('admin.streak-rewards', ['streak_branch' => 2]) . '#streak-rewards-card')
            ->assertSessionHasNoErrors();
        $rows = $this->rows();
        $this->assertSame('4', $rows['2|' . StreakRewardSettings::DAYS]);
        $this->assertSame((string) $branchItem->id, $rows['2|' . StreakRewardSettings::MENU_ITEM_ID]);
        $this->assertSame('10', $rows['2|' . StreakRewardSettings::VALID_DAYS]);
        $this->assertArrayNotHasKey('global|' . StreakRewardSettings::TYPE, $rows);
        $this->assertTrue(StreakRewardSettings::current(2)['own']);
        $this->assertFalse(StreakRewardSettings::current(1)['configured'], 'branch 1 picked up branch 2\'s reward');

        // The page shows the selector, the overview and the chosen branch.
        $html = $this->actingAs($admin, 'admin')->get(route('admin.streak-rewards', ['streak_branch' => 2]))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="streak-rewards-selector"', $html);
        $this->assertStringContainsString('data-testid="streak-rewards-overview"', $html);
        $this->assertSame(4, substr_count($html, 'data-branch-row="'));
        $this->assertStringContainsString('data-scope="2"', $html);
        $this->assertStringContainsString('Free SRB Branch Two Cake', $html, 'the overview did not show branch 2\'s own reward');
        $this->assertStringContainsString(route('admin.streak.toggle'), $html, 'the owner lost the master switch');

        // "All branches", applied to every branch: branch 2's own set goes.
        $defaultItem = $this->item(null, 'SRB Everywhere Cake');
        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $this->settingsPayload('default', $defaultItem, ['days' => 6, 'apply_to_all' => '1']))
            ->assertRedirect(route('admin.streak-rewards', ['streak_branch' => 'default']) . '#streak-rewards-card')
            ->assertSessionHasNoErrors();
        $rows = $this->rows();
        $this->assertSame('6', $rows['global|' . StreakRewardSettings::DAYS]);
        $this->assertArrayNotHasKey('2|' . StreakRewardSettings::DAYS, $rows, 'apply to all kept branch 2\'s own set');
        $this->assertSame('Free SRB Everywhere Cake', StreakRewardSettings::current(2)['description']);

        // Any branch's own switch, and "use company default" for any branch.
        $this->actingAs($admin, 'admin')->post(route('admin.streak.branch.toggle'), ['branch_id' => 3])->assertRedirect();
        $this->assertFalse(StreakRewardSettings::branchEnabled(3));
        $this->assertTrue(StreakRewardSettings::branchEnabled(4));
        $this->actingAs($admin, 'admin')->post(route('admin.streak.branch.toggle'), ['branch_id' => 3])->assertRedirect();
        $this->assertTrue(StreakRewardSettings::branchEnabled(3));

        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $this->settingsPayload(4, $this->voucher(4)))->assertSessionHasNoErrors();
        $this->assertTrue(StreakRewardSettings::current(4)['own']);
        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.default'), ['branch_id' => 4])->assertRedirect();
        $this->assertFalse(StreakRewardSettings::current(4)['own']);
        $this->assertSame('Free SRB Everywhere Cake', StreakRewardSettings::current(4)['description']);

        // A branch that does not exist.
        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $this->settingsPayload(999999, $defaultItem))->assertNotFound();
        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $this->settingsPayload('', $defaultItem))->assertNotFound();
    }

    public function test_a_supervisor_edits_only_their_own_branch(): void
    {
        $supervisor = $this->portalUser('supervisor', 2);
        $this->chooseRewardItem($this->item(null, 'SRB Untouched Default'));
        $before = $this->rows();

        // Their page: their branch only — no selector, no overview, no master
        // switch, no Spin Wheel switch; their branch's switch and form.
        $html = $this->actingAs($supervisor, 'admin')->get(route('admin.streak-rewards', ['streak_branch' => 3]))->assertOk()->getContent();
        $this->assertStringContainsString('data-scope="2"', $html, 'a supervisor reached another branch through the query string');
        $this->assertStringNotContainsString('data-scope="3"', $html);
        $this->assertStringContainsString('data-testid="streak-branch-switch"', $html);
        $this->assertStringContainsString('data-testid="streak-rewards-form"', $html);
        $this->assertStringContainsString('<input type="hidden" name="branch_id" value="2">', $html);
        foreach (['data-testid="streak-rewards-selector"', 'data-testid="streak-rewards-overview"', route('admin.streak.toggle'), route('admin.game.toggle'), 'Spin Wheel Game', 'data-testid="streak-rewards-apply-all"'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }

        // Another branch, the company default, nothing at all: 403, nothing written.
        $item3 = $this->item(3);
        foreach ([
            [route('admin.streak.rewards.update'), $this->settingsPayload(3, $item3)],
            [route('admin.streak.rewards.update'), $this->settingsPayload('default', $this->item(null))],
            [route('admin.streak.rewards.update'), array_diff_key($this->settingsPayload(2, $this->item(2)), ['branch_id' => true])],
            [route('admin.streak.rewards.default'), ['branch_id' => 3]],
            [route('admin.streak.branch.toggle'), ['branch_id' => 3]],
            [route('admin.streak.branch.toggle'), ['branch_id' => 'default']],
        ] as [$url, $payload]) {
            $this->actingAs($supervisor, 'admin')->post($url, $payload)->assertForbidden();
        }
        $this->assertSame($before, $this->rows(), 'a refused supervisor request wrote something');

        // The master switch: the role gate refuses it.
        $this->actingAs($supervisor, 'admin')->post(route('admin.streak.toggle'))->assertRedirect(route('admin.home'));
        $this->assertTrue(Setting::streakCalendarEnabled());

        // Their own branch: allowed.
        $own = $this->item(2, 'SRB Supervisor Cake');
        $this->actingAs($supervisor, 'admin')->post(route('admin.streak.rewards.update'), $this->settingsPayload(2, $own))
            ->assertRedirect(route('admin.streak-rewards') . '#streak-rewards-card')
            ->assertSessionHasNoErrors();
        $this->assertSame('Free SRB Supervisor Cake', StreakRewardSettings::current(2)['description']);
        $this->assertSame('Free SRB Untouched Default', StreakRewardSettings::current(3)['description']);

        $this->actingAs($supervisor, 'admin')->post(route('admin.streak.branch.toggle'), ['branch_id' => 2])->assertRedirect();
        $this->assertFalse(StreakRewardSettings::branchEnabled(2));

        $this->actingAs($supervisor, 'admin')->post(route('admin.streak.rewards.default'), ['branch_id' => 2])->assertRedirect();
        $this->assertFalse(StreakRewardSettings::current(2)['own']);
        $this->assertFalse(StreakRewardSettings::branchEnabled(2), 'using the default flipped the branch switch');
    }

    public function test_a_supervisor_with_no_branch_can_change_nothing(): void
    {
        $supervisor = $this->portalUser('supervisor', null);

        $this->actingAs($supervisor, 'admin')->get(route('admin.streak-rewards'))->assertOk()
            ->assertSee('data-testid="streak-rewards-no-branch"', false)
            ->assertDontSee('data-testid="streak-rewards-form"', false);

        foreach ([1, 0, 'default'] as $branch) {
            $this->actingAs($supervisor, 'admin')->post(route('admin.streak.rewards.update'), $this->settingsPayload($branch, $this->item(null)))->assertForbidden();
            $this->actingAs($supervisor, 'admin')->post(route('admin.streak.branch.toggle'), ['branch_id' => $branch])->assertForbidden();
        }

        $this->assertSame([], $this->rows());
    }

    public function test_staff_are_refused_every_settings_route_and_see_neither_card(): void
    {
        $staff = $this->portalUser('staff', 2);

        foreach ([
            [route('admin.streak.rewards.update'), $this->settingsPayload(2, $this->item(2))],
            [route('admin.streak.rewards.default'), ['branch_id' => 2]],
            [route('admin.streak.branch.toggle'), ['branch_id' => 2]],
            [route('admin.streak.toggle'), []],
        ] as [$url, $payload]) {
            $this->actingAs($staff, 'admin')->post($url, $payload)->assertRedirect(route('admin.home'));
        }

        $this->assertSame([], $this->rows());
        $this->assertTrue(Setting::streakCalendarEnabled());

        $this->actingAs($staff, 'admin')->get(route('admin.streak-rewards'))
            ->assertRedirect(route('admin.home'));
        $this->actingAs($staff, 'admin')->get(route('admin.vouchers'))->assertOk()
            ->assertDontSee('data-testid="streak-rewards-card"', false)
            ->assertDontSee('data-testid="reward-code-check-card"', false);
    }

    // ══════════ D. what a branch may give ══════════

    public function test_the_voucher_and_item_must_be_valid_at_the_branch_they_are_saved_for(): void
    {
        $admin = $this->admin();

        // Branch 2: its own or company-wide only.
        $refused = [
            [$this->voucher(3), 'voucher_id', 'Choose a voucher for ' . Branch::find(2)->name . ' or for all branches.'],
            [$this->item(3), 'menu_item_id', 'Choose a menu item sold at ' . Branch::find(2)->name . ' or at all branches.'],
        ];
        foreach ($refused as [$reward, $field, $message]) {
            $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $this->settingsPayload(2, $reward))
                ->assertSessionHasErrors([$field => $message], null, 'streakRewards');
        }

        // "All branches": company-wide only.
        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $this->settingsPayload('default', $this->voucher(2)))
            ->assertSessionHasErrors(['voucher_id' => 'Choose a voucher for all branches.'], null, 'streakRewards');
        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $this->settingsPayload('default', $this->item(2)))
            ->assertSessionHasErrors(['menu_item_id' => StreakRewardController::ITEM_PER_BRANCH_MESSAGE], null, 'streakRewards');

        $this->assertSame([], $this->rows(), 'a refused save wrote something');

        foreach ([$this->voucher(2), $this->voucher(null), $this->item(2), $this->item(null)] as $ok) {
            $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $this->settingsPayload(2, $ok))->assertSessionHasNoErrors();
            $this->assertTrue(StreakRewardSettings::current(2)['configured']);
        }

        // The pickers agree: branch 2's page offers branch 2 + company-wide, never branch 3's.
        $mine = $this->voucher(2, ['code' => 'SRBMINE' . Str::upper(Str::random(3))]);
        $theirs = $this->voucher(3, ['code' => 'SRBTHEIRS' . Str::upper(Str::random(3))]);
        $html = $this->actingAs($admin, 'admin')->get(route('admin.streak-rewards', ['streak_branch' => 2]))->assertOk()->getContent();
        $start = strpos($html, 'id="srVoucher"');
        $this->assertNotFalse($start);
        $voucherSelect = substr($html, $start, strpos($html, '</select>', $start) - $start);
        $this->assertStringContainsString('<option value="' . $mine->id . '"', $voucherSelect);
        $this->assertStringNotContainsString('<option value="' . $theirs->id . '"', $voucherSelect);

        // A default saved before the rule (a one-branch voucher) gives nothing elsewhere, and says why.
        $this->clearStreakRewardSettings();
        $this->chooseRewardVoucher($this->voucher(3));
        $this->assertTrue(StreakRewardSettings::current(3)['configured']);
        $this->assertSame(StreakRewardSettings::PROBLEM_VOUCHER_OTHER_BRANCH, StreakRewardSettings::current(1)['problem']);
        $this->assertNull(StreakRewardSettings::snapshotForNewReward(1));
        $this->actingAs($admin, 'admin')->get(route('admin.streak-rewards', ['streak_branch' => 1]))->assertOk()
            ->assertSee('data-testid="streak-rewards-not-set"', false)
            ->assertSee('so they cannot be used at ' . Branch::find(1)->name);
    }

    // ══════════ E. earning per branch ══════════

    public function test_stamps_count_only_the_branchs_own_completed_orders(): void
    {
        $user = $this->customer();
        $this->chooseRewardItem($this->item(null));

        $this->stampsAt($user, 1, 4);               // Oct 1, 3, 5, 7
        $this->stampsAt($user, 2, 3, 2);            // Oct 2, 4, 6
        $this->completedOrderOn($user, '2026-10-01', 2); // same day as a branch-1 stamp: one stamp EACH

        $this->assertSame(['2026-10-01', '2026-10-03', '2026-10-05', '2026-10-07'], FreeDesserts::stampDays($user, 1));
        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-04', '2026-10-06'], FreeDesserts::stampDays($user, 2));
        $this->assertSame(0, FreeDesserts::countedDays($user, 3));

        // 5 + 4 = 9 days in all after today's completion, but neither branch has 7: nothing.
        $this->completeNowAt($user, 1); // branch 1: 5 (today)
        $this->assertSame([], $this->keys($user));

        $this->stampsAt($user, 1, 2, 9); // branch 1: Oct 9 and 11 -> 7 stamps now
        $this->completeNowAt($user, 3);  // ...but a completion at ANOTHER branch pays nothing at branch 1
        $this->assertSame([], $this->keys($user));

        $this->completeNowAt($user, 1);  // the next completion THERE pays (today is still one stamp)
        $this->assertSame(['visits:1:1'], $this->keys($user));
        $this->assertSame(1, FreeDessertReward::where('user_id', $user->id)->sole()->branch_id);
    }

    public function test_each_branch_uses_its_own_days_and_reward_and_the_snapshot_is_taken_when_earned(): void
    {
        $user = $this->customer();
        $cake = $this->item(1, 'SRB Branch One Cake');
        $this->chooseBranchReward(1, $cake, 3, 10);
        $this->chooseBranchReward(2, $this->voucher(2, ['discount_value' => 20]), 2, 5);

        $this->stampsAt($user, 1, 2);
        $this->completeNowAt($user, 1);
        $this->stampsAt($user, 2, 1, 2);
        $this->completeNowAt($user, 2);

        $one = FreeDessertReward::where('user_id', $user->id)->where('branch_id', 1)->sole();
        $two = FreeDessertReward::where('user_id', $user->id)->where('branch_id', 2)->sole();

        $this->assertSame('menu_item', $one->reward_type);
        $this->assertSame('Free SRB Branch One Cake', $one->description());
        $this->assertTrue($one->expires_at->equalTo(CarbonImmutable::parse('2026-10-25 23:59:59', 'Asia/Manila')), 'branch 1\'s validity was not used');

        $this->assertSame('voucher', $two->reward_type);
        $this->assertSame('20% off one order', $two->description());
        $this->assertTrue($two->expires_at->equalTo(CarbonImmutable::parse('2026-10-20 23:59:59', 'Asia/Manila')), 'branch 2\'s validity was not used');
        $this->assertSame(['visits:1:1', 'visits:2:1'], $this->keys($user));

        // Changing branch 1's settings afterwards never changes the earned reward.
        $this->chooseBranchReward(1, $this->voucher(null, ['discount_value' => 50]), 9, 1);
        $cake->update(['name' => 'SRB Renamed']);
        $this->assertSame('Free SRB Branch One Cake', $one->fresh()->description());
        $this->assertTrue($one->fresh()->expires_at->equalTo(CarbonImmutable::parse('2026-10-25 23:59:59', 'Asia/Manila')));
    }

    public function test_ledger_keys_per_branch_never_collide(): void
    {
        $user = $this->customer();
        $this->chooseRewardItem($this->item(null));

        // Number 1 at two branches: two rewards, two keys.
        $a = FreeDesserts::grant($user, 1, 1);
        $b = FreeDesserts::grant($user, 2, 1);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotSame($a->code, $b->code);
        $this->assertSame(['visits:1:1', 'visits:2:1'], $this->keys($user));

        // ...and each only once.
        $this->assertNull(FreeDesserts::grant($user, 1, 1));
        $this->assertNull(FreeDesserts::grant($user, 2, 1));

        // Branch 1's count is not fooled by a branch whose id starts with 1.
        $elevenId = collect(range(11, 19))->first(fn ($id) => ! Branch::whereKey($id)->exists());
        $this->assertNotNull($elevenId, 'no free branch id between 11 and 19 for this test');
        DB::table('branches')->insert([
            'id' => $elevenId, 'name' => 'SRB Branch ' . $elevenId, 'code' => 'SRB' . $elevenId . strtoupper(Str::random(3)),
            'address' => 'Test', 'is_active' => true, 'is_main_branch' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNotNull(FreeDesserts::grant($user, $elevenId, 2));

        $other = $this->customer();
        LoyaltyAward::create(['user_id' => $other->id, 'type' => LoyaltyAward::TYPE_FREE_DESSERT, 'award_key' => 'visits:' . $elevenId . ':1', 'points' => 0]);
        $this->clearStreakRewardSettings();
        $this->stampsAt($other, 1, 7);
        $this->assertSame(1, FreeDesserts::summaryFor($other, 1)['waiting'], 'branch ' . $elevenId . '\'s key counted as branch 1\'s');
    }

    public function test_a_branch_switched_off_earns_nothing_but_rewards_already_earned_still_work(): void
    {
        $user = $this->customer();
        $cake = $this->item(1, 'SRB Off Cake');
        $this->chooseBranchReward(1, $cake, 7);
        $earned = FreeDesserts::grant($user, 1, 1);

        $this->setBranchSwitch(1, false);
        $this->assertFalse(FreeDesserts::active(1));
        $this->assertTrue(FreeDesserts::active(2), 'switching branch 1 off turned branch 2 off');

        // 14 stamps at branch 1 now (Oct 1-13 and today): still only the reward from before.
        $this->stampsAt($user, 1, 13, 1, 1);
        $this->completeNowAt($user, 1);
        $this->assertSame(['visits:1:1'], $this->keys($user));

        // Its calendar and stamp card are hidden; the account page still works.
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get(route('customer.account'))->assertOk()
            ->assertDontSee('data-testid="streak-calendar"', false)
            ->assertDontSee('data-testid="free-dessert-card"', false)
            ->assertDontSee('data-testid="free-dessert-challenge"', false)
            ->assertSee('id="benefits"', false);

        // The reward is still listed, and spent at its branch.
        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get(route('customer.vouchers'))->assertOk()
            ->assertSee($earned->displayCode());
        [, $order] = $this->place($user, $this->cart($this->item(1)), ['reward_code' => $earned->displayCode()], 1);
        $this->assertNotNull($order);
        $this->assertSame('used', $earned->fresh()->status);

        // Back on: the catch-up pays what became due meanwhile.
        $this->setBranchSwitch(1, true);
        $this->completeNowAt($user, 1);
        $this->assertSame(['visits:1:1', 'visits:1:2'], $this->keys($user));
    }

    // ══════════ F. used only at the issuing branch ══════════

    public function test_a_reward_is_refused_at_another_branch_and_accepted_at_its_own(): void
    {
        $user = $this->customer();
        $branch2 = Branch::findOrFail(2);
        $message = 'This reward can only be used at ' . $branch2->name . '.';

        $this->chooseBranchReward(2, $this->item(2, 'SRB Two Cake'), 7);
        $itemReward = FreeDesserts::grant($user, 2, 1);
        $this->chooseBranchReward(2, $this->voucher(null, ['discount_type' => 'fixed', 'discount_value' => 30]), 7);
        $voucherReward = FreeDesserts::grant($user, 2, 2);
        $this->assertSame([2, 2], [$itemReward->branch_id, $voucherReward->branch_id]);

        // Branch 1 sells the same company-wide voucher's terms and has its own
        // stock: only the branch rule can refuse.
        $here = $this->item(1);

        // Checkout at branch 1.
        [$r1, $none1] = $this->place($user, $this->cart($here), ['reward_code' => $itemReward->displayCode()], 1);
        $this->assertNull($none1);
        $this->assertSame($message, session('errors')->first('reward_code'));

        [$r2, $none2] = $this->place($user, $this->cart($here), ['voucher_code_confirmed' => $voucherReward->displayCode()], 1);
        $this->assertNull($none2);
        $this->assertSame($message, session('errors')->first('voucher_code_confirmed'));

        // The cart preview and "Use now" say the same.
        $session = ['cart' => $this->cart($here), 'branch_id' => 1, 'order_type' => 'pick_up'];
        foreach ([$itemReward, $voucherReward] as $reward) {
            $this->actingAs($user, 'customer')->withSession($session)
                ->postJson(route('customer.apply-voucher'), ['code' => $reward->displayCode(), 'subtotal' => 200])
                ->assertOk()->assertExactJson(['success' => false, 'message' => $message]);

            $this->actingAs($user, 'customer')->withSession($session)
                ->post(route('customer.rewards.use', $reward->id))
                ->assertRedirect(route('customer.vouchers') . '#rewards')
                ->assertSessionHas('error', $message)
                ->assertSessionMissing('apply_reward_code');
        }

        $this->assertSame(['available', 'available'], [$itemReward->fresh()->status, $voucherReward->fresh()->status]);

        // At branch 2: both accepted (one per order).
        [, $o1] = $this->place($user, $this->cart($this->item(2)), ['reward_code' => $itemReward->displayCode()], 2);
        $this->assertNotNull($o1);
        $this->assertSame(2, $itemReward->fresh()->used_branch_id);
        [, $o2] = $this->place($user, $this->cart($this->item(2)), ['voucher_code_confirmed' => $voucherReward->displayCode()], 2);
        $this->assertNotNull($o2);
        $this->assertEquals(30.00, (float) $o2->discount_amount);
    }

    public function test_a_reward_from_before_per_branch_rewards_works_at_any_branch(): void
    {
        $user = $this->customer();
        $this->chooseRewardItem($this->item(null, '', 3)); // stocked at branch 3, where it is spent
        $legacy = FreeDesserts::grant($user, 1, 1);
        $legacy->forceFill(['branch_id' => null])->save();

        $this->assertNull($legacy->fresh()->branchErrorFor(3));
        $this->assertSame('Any branch', $legacy->fresh()->branchName());
        [, $order] = $this->place($user, $this->cart($this->item(3)), ['reward_code' => $legacy->displayCode()], 3);
        $this->assertNotNull($order);
    }

    // ══════════ G. the code check ══════════

    /**
     * The check's RESULT block only — the rest of the page (the settings card
     * for the supervisor's own branch) may name a reward legitimately.
     */
    private function check(User $actor, string $code): string
    {
        $html = $this->actingAs($actor, 'admin')->followingRedirects()
            ->post(route('admin.streak.rewards.check'), ['reward_code' => $code])
            ->assertOk()->getContent();

        $start = strpos($html, 'data-testid="reward-check-result"');
        $this->assertNotFalse($start, 'no check result on the page');
        // The result ends with the checker card; the Branch Overview after it
        // names rewards legitimately.
        $end = strpos($html, '</section>', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    public function test_the_code_check_shows_a_supervisor_only_their_branchs_codes(): void
    {
        $user = $this->customer('Gabriela Silang');
        $this->chooseRewardItem($this->item(null, 'SRB Checked Cake'));
        $ownCode = FreeDesserts::grant($user, 2, 1);
        $otherCode = FreeDesserts::grant($user, 3, 1);
        $legacy = FreeDesserts::grant($user, 4, 1);
        $legacy->forceFill(['branch_id' => null])->save();

        $supervisor = $this->portalUser('supervisor', 2);

        $html = $this->check($supervisor, $ownCode->displayCode());
        $this->assertStringContainsString('data-state="valid"', $html);
        $this->assertStringContainsString('Gabriela Silang', $html);
        $this->assertStringContainsString('Free SRB Checked Cake', $html);

        // Another branch's code (or one whose issuer is unknown) answers
        // exactly like a code that does not exist: a supervisor cannot even
        // learn that it is real.
        foreach ([$otherCode, $legacy] as $notTheirs) {
            $html = $this->check($supervisor, $notTheirs->displayCode());
            $this->assertStringContainsString('data-state="not_found"', $html);
            $this->assertStringContainsString('No reward has this code.', $html);
            $this->assertStringNotContainsString('another branch', $html);
            $this->assertStringNotContainsString('Gabriela Silang', $html);
            $this->assertStringNotContainsString('Free SRB Checked Cake', $html);
            $this->assertStringNotContainsString('Valid — can be used', $html);
        }

        // The owner: every code in full, with the issuing branch.
        $admin = $this->admin();
        foreach ([[$ownCode, Branch::find(2)->name], [$otherCode, Branch::find(3)->name], [$legacy, 'Any branch']] as [$reward, $issuer]) {
            $html = $this->check($admin, $reward->displayCode());
            $this->assertStringContainsString('Gabriela Silang', $html);
            $this->assertStringContainsString('<dd data-testid="reward-check-issued-by">' . e($issuer) . '</dd>', $html);
        }

        // A supervisor with no branch sees nobody's details.
        $html = $this->check($this->portalUser('supervisor', null), $ownCode->displayCode());
        $this->assertStringContainsString('data-state="not_found"', $html);
        $this->assertStringNotContainsString('Gabriela Silang', $html);
    }

    // ══════════ H. the customer pages ══════════

    public function test_the_account_page_shows_the_branch_the_customer_is_ordering_from(): void
    {
        $user = $this->customer();
        $this->chooseRewardItem($this->item(null));
        $this->chooseBranchReward(3, $this->item(3, 'SRB Dine In Cake'), 5);
        $this->stampsAt($user, 1, 4);    // Oct 1, 3, 5, 7
        $this->stampsAt($user, 2, 2, 2); // Oct 2, 4
        $this->stampsAt($user, 3, 1, 9); // Oct 9

        // Pickup at branch 2: branch 2's name, days and stamps only.
        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => 2, 'order_type' => 'pick_up'])
            ->get(route('customer.account'))->assertOk()->getContent();
        $name2 = Branch::find(2)->name;
        $this->assertStringContainsString('data-testid="streak-calendar" data-branch="2"', $html);
        $this->assertMatchesRegularExpression('/data-testid="streak-branch"><i class="bi bi-shop" aria-hidden="true"><\/i>' . preg_quote($name2, '/') . '<\/span>/', $html);
        $this->assertSame(2, substr_count($html, 'data-streak-day="'), 'the calendar showed other branches\' days');
        $this->assertStringContainsString('data-streak-day="2026-10-02"', $html);
        $this->assertStringNotContainsString('data-streak-day="2026-10-01"', $html);
        $this->assertStringContainsString('data-testid="free-dessert-card" data-branch="2"', $html);
        $this->assertStringContainsString('2 / 7 stamps', $html);
        $this->assertStringContainsString('Day 2 of 7', $html);
        $this->assertStringContainsString('1 stamp per day you completed an order at ' . $name2 . '.', $html);

        // Dine-In at a branch-3 table: branch 3's own card (5 days, its own reward).
        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => 3, 'order_type' => 'dine_in', 'table_number' => '4'])
            ->get(route('customer.account'))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="streak-calendar" data-branch="3"', $html);
        $this->assertStringContainsString('1 / 5 stamps', $html);
        $this->assertStringContainsString('Collect 5 stamps for a reward: Free SRB Dine In Cake.', $html);
        $this->assertSame(1, substr_count($html, 'data-streak-day="'));

        // No branch picked yet: a prompt to choose one, no calendar or card.
        // (The test session carries over between requests: clear it.)
        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => null, 'order_type' => null, 'table_number' => null])
            ->get(route('customer.account'))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="streak-choose-branch"', $html);
        $this->assertStringNotContainsString('data-testid="streak-calendar"', $html);
        $this->assertStringNotContainsString('data-testid="free-dessert-card"', $html);

        // A branch switched off: only its calendar and stamp card go.
        $this->setBranchSwitch(2, false);
        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => 2, 'order_type' => 'pick_up'])
            ->get(route('customer.account'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-testid="streak-calendar"', $html);
        $this->assertStringNotContainsString('data-testid="free-dessert-card"', $html);
        $this->assertStringNotContainsString('data-testid="streak-choose-branch"', $html);
        $this->assertStringContainsString('id="benefits"', $html);
    }

    public function test_vouchers_and_rewards_lists_every_branchs_rewards_each_labeled(): void
    {
        $user = $this->customer();
        $this->chooseRewardItem($this->item(null));
        $one = FreeDesserts::grant($user, 1, 1);
        $two = FreeDesserts::grant($user, 2, 1);

        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->get(route('customer.vouchers'))->assertOk()->getContent();

        $this->assertStringContainsString($one->displayCode(), $html);
        $this->assertStringContainsString($two->displayCode(), $html);
        $this->assertSame(2, substr_count($html, 'data-testid="loyalty-reward-branch"'));
        $this->assertStringContainsString('Only at ' . Branch::find(1)->name, $html);
        $this->assertStringContainsString('Only at ' . Branch::find(2)->name, $html);

        // "Use now" only for the reward of the branch they are ordering from.
        $this->assertSame(1, substr_count($html, 'data-testid="loyalty-reward-use"'));
        $this->assertStringContainsString(route('customer.rewards.use', $one->id), $html);
        $this->assertStringNotContainsString(route('customer.rewards.use', $two->id), $html);
        $this->assertStringContainsString('Order from ' . Branch::find(2)->name . ' to use it', $html);

        // The stamp card on this page is branch 1's, and counts only the
        // reward that can be used there.
        $this->assertStringContainsString('data-testid="free-dessert-card" data-branch="1"', $html);
        $this->assertMatchesRegularExpression('/1 reward ready to use\s+at ' . preg_quote(Branch::find(1)->name, '/') . '/', $html);
        $this->assertStringNotContainsString('2 rewards ready to use', $html);
        $this->assertCount(1, FreeDesserts::summaryFor($user, 2)['available']);
        $this->assertSame($two->id, FreeDesserts::summaryFor($user, 2)['available']->first()->id);
    }

    // ══════════ I. the points bonuses stay company-wide ══════════

    public function test_the_streak_points_bonus_still_counts_every_branch(): void
    {
        $user = $this->customer();
        $this->setBranchSwitch(3, false);

        // Three days in a row at three different branches, the last at a
        // branch whose stamp card is OFF: the 3-day bonus is paid all the same.
        $this->completedOrderOn($user, '2026-10-13', 1);
        $this->completedOrderOn($user, '2026-10-14', 2);
        $this->completeNowAt($user, 3);

        $streak = LoyaltyAward::where('user_id', $user->id)->where('type', LoyaltyAward::TYPE_STREAK)->sole();
        $this->assertSame('3:2026-10-13', $streak->award_key);
        $this->assertSame(10, $streak->points);
        $this->assertTrue(LoyaltyAward::where('user_id', $user->id)->where('type', LoyaltyAward::TYPE_ORDER_BONUS)->exists());

        // The calendar at branch 2 shows branch 2's days, and the bonus line
        // the run across every branch.
        $other = $this->customer();
        $this->completedOrderOn($other, '2026-10-14', 1);
        $this->completedOrderOn($other, '2026-10-15', 2);
        $html = $this->actingAs($other, 'customer')->withSession(['branch_id' => 2])->get(route('customer.account'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-testid="current-streak">\s*1 day\s*</', $html);
        $this->assertMatchesRegularExpression('/Streak bonus \(any branch\):\s*1 more day\s*in a row to the 3-day bonus \(\+10 points\)\./', $html);
    }

    // ══════════ the legacy ledger keys ══════════

    public function test_legacy_visits_keys_move_to_the_branch_that_earned_them_and_are_never_paid_again(): void
    {
        $user = $this->customer();
        $earnedAt2 = $this->completedOrderOn($user, '2026-10-01', 2);

        $keyed = LoyaltyAward::create(['user_id' => $user->id, 'type' => LoyaltyAward::TYPE_FREE_DESSERT, 'award_key' => 'visits:1', 'points' => 0, 'order_id' => $earnedAt2->id]);
        $unknown = LoyaltyAward::create(['user_id' => $user->id, 'type' => LoyaltyAward::TYPE_FREE_DESSERT, 'award_key' => 'visits:2', 'points' => 0]);

        $migration = require base_path('database/migrations/2026_10_13_000000_scope_stamp_card_ledger_keys_by_branch.php');
        $migration->up();
        $migration->up(); // idempotent

        $this->assertSame('visits:2:1', $keyed->fresh()->award_key);
        $this->assertSame('visits:2', $unknown->fresh()->award_key, 'a key with no known branch was moved');
        $this->assertSame('Stamp card reward #1', $keyed->fresh()->label());

        // Seven stamps at branch 2: number 1 there is already paid.
        $this->chooseRewardItem($this->item(null));
        $this->stampsAt($user, 2, 6, 3);
        $this->assertSame(7, FreeDesserts::countedDays($user, 2));
        $this->assertSame([], FreeDesserts::grantDue($user, 2), 'a reward paid before the change was paid again');

        // Rolling back would let the old code pay them again: refused.
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}

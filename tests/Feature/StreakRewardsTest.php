<?php

namespace Tests\Feature;

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
use App\Providers\RateLimitServiceProvider;
use App\Services\FreeDesserts;
use App\Services\StreakRewardSettings;
use App\Support\RewardCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ConfiguresStreakReward;
use Tests\TestCase;

/**
 * Streak Rewards (Oct 2026) — the owner chooses the stamp card reward (one
 * menu item, or a voucher's terms), how many days earn it and how long it
 * stays valid; every reward gets its own single-use code; a read-only code
 * check for the owner and (branch-scoped) supervisors; "Vouchers & Rewards".
 *
 * Pinned here: settings validation; permissions enforced on the server;
 * the check tool (owner full details, supervisor branch-scoped, staff
 * refused, rate limited); snapshots (changing settings never changes an
 * earned reward); unique codes; voucher-type rewards (branch, minimum order,
 * never stacking with a voucher or PWD/Senior); Use now; the code-guessing
 * limiter; the rename; the switch-off behaviour; guests.
 *
 * Menu-item rewards end to end (₱0 line, stock, cancel, tampering, expiry,
 * wrong owner) are in FreeDessertRewardTest; the pop-up in
 * FreeDessertPromptTest; the two-process races in FreeDessertRaceTest.
 *
 * Every row is created inside DatabaseTransactions and rolled back.
 */
class StreakRewardsTest extends TestCase
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
            'loyalty.streak_milestones'        => [3 => 10, 7 => 0],
            'loyalty.free_dessert_visit_days'  => 7,
            'loyalty.free_dessert_counts_from' => '2026-09-01',
            'loyalty.free_dessert_valid_days'  => 30,
        ]);

        $this->clearStreakRewardSettings();
        DB::table('settings')->where('key', Setting::STREAK_CALENDAR_ENABLED)->delete();
    }

    // ══════════ fixtures ══════════

    private function customer(bool $verified = true, string $name = 'Reward Customer'): User
    {
        return User::create([
            'name'              => $name,
            'email'             => 'streak-rewards-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => $verified ? now() : null,
        ])->fresh();
    }

    private function portalUser(string $role, ?int $branchId = 1): User
    {
        return User::create([
            'name'              => 'SR ' . $role,
            'email'             => 'streak-rewards-' . $role . '-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $role === 'admin' ? null : $branchId,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function inventory(float $quantity, int $branchId = 1): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => 'SR Ingredient ' . uniqid(),
            'item_code'       => 'SR-' . strtoupper(substr(uniqid(), -8)) . random_int(10, 99),
            'quantity'        => $quantity,
            'unit'            => 'pc',
            'low_stock_alert' => 0,
            'unit_cost'       => 5,
            'is_active'       => true,
        ]);
    }

    private function item(int $branchId = 1, float $price = 100, float $stock = 20, array $attrs = []): MenuItem
    {
        $inv = $this->inventory($stock, $branchId);
        $item = MenuItem::create(array_merge([
            'category_id'   => Category::value('id'),
            'branch_id'     => $branchId,
            'name'          => 'SR Item ' . uniqid(),
            'price'         => $price,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ], $attrs));

        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => 1]);

        return $item->fresh();
    }

    private function voucher(array $attrs = []): Voucher
    {
        return Voucher::create(array_merge([
            'branch_id'       => null,
            'code'            => 'SRV' . strtoupper(Str::random(6)),
            'description'     => 'Streak reward template',
            'discount_type'   => 'percent',
            'discount_value'  => 10,
            'max_uses'        => 5,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 0,
            'is_active'       => true,
        ], $attrs))->fresh();
    }

    private function cart(MenuItem $item, int $qty = 1): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => (float) $item->price,
            'quantity'     => $qty,
            'image'        => null,
            'options'      => [],
        ]];
    }

    /** Reward $number at branch 1, as $item (the company default reward). */
    private function grantItemReward(User $user, MenuItem $item, int $number = 1): FreeDessertReward
    {
        $this->chooseRewardItem($item);

        return tap(FreeDesserts::grant($user, 1, $number), fn ($r) => $this->assertNotNull($r));
    }

    /** Reward $number at branch 1, as $voucher's terms (the company default reward). */
    private function grantVoucherReward(User $user, Voucher $voucher, int $number = 1): FreeDessertReward
    {
        $this->chooseRewardVoucher($voucher);

        return tap(FreeDesserts::grant($user, 1, $number), fn ($r) => $this->assertNotNull($r));
    }

    /** Reward $number earned at $branchId, which gets $reward as its own setting. */
    private function grantAt(User $user, int $branchId, MenuItem|Voucher $reward, int $number = 1): FreeDessertReward
    {
        $this->chooseBranchReward($branchId, $reward, 7);

        return tap(FreeDesserts::grant($user, $branchId, $number), fn ($r) => $this->assertNotNull($r));
    }

    /** Checkout as $user. Returns [response, new order or null]. */
    private function place(User $user, array $cart, array $extra = [], int $branchId = 1): array
    {
        $before = (int) Order::max('id');

        $response = $this->actingAs($user, 'customer')
            ->withSession(['cart' => $cart, 'branch_id' => $branchId, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', array_merge([
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => array_values($cart)[0]['menu_item_id'], 'quantity' => 1]],
            ], $extra));

        return [$response, Order::where('id', '>', $before)->where('user_id', $user->id)->latest('id')->first()];
    }

    private function complete(Order $order): void
    {
        $this->actingAs($this->portalUser('admin'), 'admin')
            ->put(route('admin.orders.complete', $order->id))
            ->assertRedirect();
    }

    private function validSettings(array $overrides = []): array
    {
        // The company default by default, so the item is one sold at all branches.
        return array_merge([
            'branch_id'    => 'default',
            'days'         => 5,
            'reward_type'  => 'menu_item',
            'menu_item_id' => $this->item(1, 100, 20, ['branch_id' => null])->id,
            'voucher_id'   => null,
            'valid_days'   => 14,
        ], $overrides);
    }

    private function settingRows(): array
    {
        return DB::table('settings')->whereNull('branch_id')->where('key', 'like', 'streak_reward_%')
            ->orderBy('key')->pluck('value', 'key')->all();
    }

    // ══════════ settings: saved, validated, owner only ══════════

    public function test_with_nothing_saved_the_days_and_validity_fall_back_to_config_and_no_reward_is_given(): void
    {
        $current = StreakRewardSettings::current();

        $this->assertSame(7, $current['days']);
        $this->assertFalse($current['days_saved']);
        $this->assertSame(30, $current['valid_days']);
        $this->assertNull($current['type']);
        $this->assertFalse($current['configured']);
        $this->assertNull(StreakRewardSettings::snapshotForNewReward(1));
        $this->assertNull(FreeDesserts::grant($this->customer(), 1, 1), 'a reward was granted with no reward chosen');

        $this->actingAs($this->portalUser('admin'), 'admin')->get(route('admin.streak-rewards'))->assertOk()
            ->assertSee('data-testid="streak-rewards-not-set"', false)
            ->assertSee('No reward is chosen yet');
    }

    public function test_the_owner_saves_a_menu_item_reward_and_the_page_states_the_rule_in_plain_words(): void
    {
        $admin = $this->portalUser('admin');
        $item = $this->item(1, 120, 20, ['name' => 'SR Ube Cake', 'branch_id' => null]);

        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $this->validSettings([
            'days' => 5, 'menu_item_id' => $item->id, 'valid_days' => 14,
        ]))->assertRedirect(route('admin.streak-rewards', ['streak_branch' => 'default']) . '#streak-rewards-card')->assertSessionHasNoErrors();

        // Global rows (branch_id NULL) in the settings table.
        $rows = $this->settingRows();
        $this->assertSame('5', $rows[StreakRewardSettings::DAYS]);
        $this->assertSame('menu_item', $rows[StreakRewardSettings::TYPE]);
        $this->assertSame((string) $item->id, $rows[StreakRewardSettings::MENU_ITEM_ID]);
        $this->assertSame('14', $rows[StreakRewardSettings::VALID_DAYS]);
        $this->assertNull($rows[StreakRewardSettings::VOUCHER]);
        $this->assertSame(5, FreeDesserts::visitDays(), 'the saved days beat config');
        $this->assertSame(14, FreeDesserts::validDays());

        // The page states it in short fields now, not one long rule
        // paragraph: the branch overview shows what a branch with nothing
        // of its own (here, every branch) actually inherits.
        $this->actingAs($admin, 'admin')->get(route('admin.streak-rewards'))->assertOk()
            ->assertSee('Free SR Ube Cake')
            ->assertDontSee('data-testid="streak-rewards-not-set"', false);

        // A branch with nothing of its own follows it.
        $this->assertSame(5, FreeDesserts::visitDays(1));
        $this->assertSame('Free SR Ube Cake', StreakRewardSettings::current(3)['description']);
    }

    public function test_the_owner_saves_a_voucher_reward_and_its_terms_are_copied_at_save_time(): void
    {
        $admin = $this->portalUser('admin');
        $template = $this->voucher(['discount_type' => 'fixed', 'discount_value' => 50, 'minimum_order' => 200]);

        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $this->validSettings([
            'reward_type' => 'voucher', 'voucher_id' => $template->id, 'menu_item_id' => null,
        ]))->assertSessionHasNoErrors();

        $current = StreakRewardSettings::current();
        $this->assertTrue($current['configured']);
        $this->assertSame('voucher', $current['type']);
        $this->assertSame('₱50.00 off one order (min. order ₱200.00)', $current['description']);

        // Editing or switching off the template afterwards changes nothing
        // until the owner saves again.
        $template->update(['discount_value' => 5, 'is_active' => false]);
        $this->assertSame('₱50.00 off one order (min. order ₱200.00)', StreakRewardSettings::current()['description']);
        $this->assertEquals(50.0, (float) StreakRewardSettings::snapshotForNewReward(1)['discount_value']);
    }

    /** @return array<string, array{0: array, 1: string}> */
    public static function invalidSettings(): array
    {
        return [
            'days missing'          => [['days' => ''], 'days'],
            'days zero'             => [['days' => 0], 'days'],
            'days negative'         => [['days' => -3], 'days'],
            'days fraction'         => [['days' => 2.5], 'days'],
            'days too many'         => [['days' => 366], 'days'],
            'days text'             => [['days' => 'seven'], 'days'],
            'type missing'          => [['reward_type' => ''], 'reward_type'],
            'type unknown'          => [['reward_type' => 'points'], 'reward_type'],
            'item missing'          => [['menu_item_id' => ''], 'menu_item_id'],
            'item does not exist'   => [['menu_item_id' => 999999999], 'menu_item_id'],
            'voucher missing'       => [['reward_type' => 'voucher', 'voucher_id' => ''], 'voucher_id'],
            'voucher does not exist' => [['reward_type' => 'voucher', 'voucher_id' => 999999999], 'voucher_id'],
            'valid days missing'    => [['valid_days' => ''], 'valid_days'],
            'valid days zero'       => [['valid_days' => 0], 'valid_days'],
            'valid days too many'   => [['valid_days' => 400], 'valid_days'],
        ];
    }

    /** @dataProvider invalidSettings */
    public function test_every_field_is_validated_on_the_server(array $overrides, string $field): void
    {
        $this->actingAs($this->portalUser('admin'), 'admin')
            ->post(route('admin.streak.rewards.update'), $this->validSettings($overrides))
            ->assertSessionHasErrors([$field], null, 'streakRewards');

        $this->assertSame([], $this->settingRows(), 'a refused save wrote something');
    }

    public function test_an_inactive_or_expired_voucher_and_an_archived_item_are_refused(): void
    {
        $admin = $this->portalUser('admin');

        foreach ([
            $this->voucher(['is_active' => false]),
            $this->voucher(['expires_at' => now()->subDay()]),
        ] as $voucher) {
            $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $this->validSettings([
                'reward_type' => 'voucher', 'voucher_id' => $voucher->id,
            ]))->assertSessionHasErrors(['voucher_id'], null, 'streakRewards');
        }

        $archived = $this->item();
        $archived->archived_at = now();
        $archived->save();

        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.update'), $this->validSettings([
            'menu_item_id' => $archived->id,
        ]))->assertSessionHasErrors(['menu_item_id'], null, 'streakRewards');

        $this->assertSame([], $this->settingRows());
    }

    public function test_a_supervisor_cannot_change_the_company_default(): void
    {
        // Per-branch permissions in full: StreakRewardsPerBranchTest.
        $item = $this->chooseRewardItem($this->item(1, 100, 20, ['name' => 'SR Leche Flan', 'branch_id' => null]));
        $before = $this->settingRows();
        $supervisor = $this->portalUser('supervisor', 1);

        $html = $this->actingAs($supervisor, 'admin')->get(route('admin.streak-rewards'))->assertOk()->getContent();
        $this->assertStringContainsString('data-scope="1"', $html, 'the supervisor was not shown their own branch');
        $this->assertStringNotContainsString('data-scope="default"', $html);
        $this->assertStringNotContainsString(route('admin.streak.toggle'), $html);
        // ...and the check tool, which they may use.
        $this->assertStringContainsString('data-testid="reward-code-check-card"', $html);

        $this->actingAs($supervisor, 'admin')
            ->post(route('admin.streak.rewards.update'), $this->validSettings(['days' => 2]))
            ->assertForbidden();

        $this->assertSame($before, $this->settingRows(), 'a supervisor changed the company default');
        $this->assertSame($item->id, StreakRewardSettings::current()['menu_item_id']);
    }

    public function test_staff_customers_and_guests_cannot_change_the_settings_and_staff_see_no_reward_cards(): void
    {
        $this->chooseRewardItem($this->item());
        $before = $this->settingRows();
        $payload = $this->validSettings(['days' => 2]);

        $staff = $this->portalUser('staff', 1);
        $this->actingAs($staff, 'admin')->post(route('admin.streak.rewards.update'), $payload)
            ->assertRedirect(route('admin.home'));
        $this->actingAs($staff, 'admin')->get(route('admin.streak-rewards'))
            ->assertRedirect(route('admin.home'));
        $this->actingAs($staff, 'admin')->get(route('admin.vouchers'))->assertOk()
            ->assertDontSee('data-testid="streak-rewards-card"', false)
            ->assertDontSee('data-testid="reward-code-check-card"', false);

        // A customer has no admin session at all.
        $this->actingAs($this->customer(), 'customer')->post(route('admin.streak.rewards.update'), $payload)
            ->assertRedirect();

        auth()->guard('admin')->logout();
        auth()->guard('customer')->logout();
        $this->post(route('admin.streak.rewards.update'), $payload)->assertRedirect();

        $this->assertSame($before, $this->settingRows());
    }

    // ══════════ snapshots ══════════

    public function test_changing_the_settings_never_changes_a_reward_already_earned(): void
    {
        $user = $this->customer();
        $cake = $this->item(1, 150, 20, ['name' => 'SR Snapshot Cake']);
        $old = $this->grantItemReward($user, $cake, 1);
        $oldRow = $old->fresh()->only(['code', 'reward_type', 'menu_item_id', 'item_name', 'discount_type', 'discount_value', 'minimum_order', 'branch_id', 'expires_at']);

        // The owner switches to a voucher reward with a shorter validity.
        $this->actingAs($this->portalUser('admin'), 'admin')->post(route('admin.streak.rewards.update'), [
            'branch_id' => 'default',
            'days' => 3, 'reward_type' => 'voucher', 'voucher_id' => $this->voucher(['discount_value' => 15])->id, 'valid_days' => 3,
        ])->assertSessionHasNoErrors();

        // ...and even renames and archives the old item.
        $cake->update(['name' => 'SR Renamed Cake']);

        $this->assertEquals($oldRow, $old->fresh()->only(array_keys($oldRow)), 'an earned reward changed with the settings');
        $this->assertSame('Free SR Snapshot Cake', $old->fresh()->description());

        // A reward earned now is the new one.
        $new = FreeDesserts::grant($user, 1, 2);
        $this->assertSame('voucher', $new->reward_type);
        $this->assertSame('15% off one order', $new->description());
        $this->assertTrue($new->expires_at->equalTo(CarbonImmutable::parse('2026-10-18 23:59:59', 'Asia/Manila')));
        $this->assertTrue($old->fresh()->expires_at->equalTo(CarbonImmutable::parse('2026-11-14 23:59:59', 'Asia/Manila')));
    }

    public function test_a_reward_with_no_snapshot_takes_the_first_reward_the_owner_saves(): void
    {
        // A row from before rewards had snapshots (reward_type NULL): listed,
        // but not usable, until the owner chooses a reward.
        $user = $this->customer();
        $award = LoyaltyAward::create(['user_id' => $user->id, 'type' => LoyaltyAward::TYPE_FREE_DESSERT, 'award_key' => 'visits:1', 'points' => 0]);
        $legacy = FreeDessertReward::create([
            'user_id' => $user->id, 'loyalty_award_id' => $award->id, 'code' => FreeDesserts::uniqueCode(),
            'status' => 'available', 'expires_at' => now()->addDays(5),
        ]);

        $this->assertSame('not_set', $legacy->statusKey());
        $this->assertFalse($legacy->isUsable());
        $this->actingAs($user, 'customer')->get(route('customer.vouchers'))->assertOk()
            ->assertSee($legacy->displayCode())
            ->assertSee('We are still setting this reward up.');

        // Its earning branch is unknown (no order), so it takes the company default.
        $item = $this->item(1, 100, 20, ['name' => 'SR Adopted Cake', 'branch_id' => null]);
        $this->actingAs($this->portalUser('admin'), 'admin')->post(route('admin.streak.rewards.update'), $this->validSettings([
            'menu_item_id' => $item->id,
        ]))->assertSessionHasNoErrors();

        $legacy->refresh();
        $this->assertSame('menu_item', $legacy->reward_type);
        $this->assertSame($item->id, $legacy->menu_item_id);
        $this->assertTrue($legacy->isUsable());
        $this->assertTrue($legacy->expires_at->equalTo(now()->addDays(5)), 'adopting changed its own expiry');
    }

    // ══════════ codes ══════════

    public function test_codes_are_random_unique_and_in_the_table_code_alphabet(): void
    {
        $codes = [];
        for ($i = 0; $i < 300; $i++) {
            $code = RewardCode::generate();
            $this->assertMatchesRegularExpression('/^RWD[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{8}$/', $code);
            $codes[] = $code;
        }
        $this->assertCount(300, array_unique($codes));

        $this->assertSame('RWD-ABCD-EFGH', RewardCode::display('rwdabcdefgh'));
        $this->assertTrue(RewardCode::looksLike('rwd abcd efgh'));
        $this->assertFalse(RewardCode::looksLike('RWD-ABCD-EFG0'), '0 is not in the alphabet');
        $this->assertFalse(RewardCode::looksLike('PCH-ABCDE-FGHJK'));

        // Every earned reward gets its own.
        $this->chooseRewardItem($this->item());
        $rewards = collect(range(1, 5))->map(fn ($n) => FreeDesserts::grant($this->customer(), 1, $n));
        $this->assertCount(5, $rewards->pluck('code')->unique());
    }

    public function test_the_database_refuses_a_second_reward_with_the_same_code(): void
    {
        $user = $this->customer();
        $first = $this->grantItemReward($user, $this->item(), 1);
        $award = LoyaltyAward::create(['user_id' => $user->id, 'type' => LoyaltyAward::TYPE_FREE_DESSERT, 'award_key' => 'visits:1:2', 'points' => 0]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/free_dessert_rewards_code_unique|Duplicate entry/');

        FreeDessertReward::create([
            'user_id' => $user->id, 'loyalty_award_id' => $award->id, 'code' => $first->code,
            'reward_type' => 'menu_item', 'status' => 'available',
        ]);
    }

    public function test_a_code_an_order_ever_recorded_is_never_issued_again(): void
    {
        $taken = RewardCode::generate();
        Order::create([
            'order_number' => 'SRC-' . strtoupper(Str::random(8)), 'user_id' => null, 'branch_id' => 1, 'type' => 'pick_up',
            'status' => 'cancelled', 'payment_method' => 'cash', 'payment_status' => 'pending',
            'subtotal' => 0, 'total' => 0, 'reward_code' => $taken,
        ]);

        // The generator offers the taken code first: it is skipped.
        $offers = [$taken, 'RWD' . 'ABCDEFGH'];
        $fresh = FreeDesserts::uniqueCode(function () use (&$offers) {
            return array_shift($offers);
        });

        $this->assertSame('RWDABCDEFGH', $fresh);
        $this->assertSame([], $offers, 'the taken code was not even offered');

        // A code an existing reward holds is skipped the same way.
        $held = $this->grantItemReward($this->customer(), $this->item());
        // (A real closure: an arrow fn would capture $offers by value.)
        $offers = [$held->code, 'RWD' . 'HGFEDCBA'];
        $this->assertSame('RWDHGFEDCBA', FreeDesserts::uniqueCode(function () use (&$offers) {
            return array_shift($offers);
        }));
    }

    // ══════════ voucher-type rewards ══════════

    public function test_a_voucher_reward_discounts_the_order_like_a_voucher_and_is_spent_once(): void
    {
        $user = $this->customer();
        $template = $this->voucher(['discount_type' => 'percent', 'discount_value' => 10, 'max_uses' => 1]);
        $reward = $this->grantVoucherReward($user, $template);

        [$response, $order] = $this->place($user, $this->cart($this->item(1, 100), 3), [
            'voucher_code_confirmed' => $reward->displayCode(),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('reward', $order->discount_type);
        $this->assertEquals(30.00, (float) $order->discount_amount);
        $this->assertEquals(270.00, (float) $order->total);
        $this->assertNull($order->voucher_id, 'the template voucher was redeemed');
        $this->assertSame($reward->code, $order->reward_code);
        $this->assertSame('Reward ' . $reward->displayCode(), $order->discountLabel());
        $this->assertSame(0, $order->items()->where('is_free_dessert', true)->count(), 'a voucher reward added a line');
        $this->assertSame(0, $template->fresh()->used_count, 'the reward used up the template\'s own uses');

        $this->assertSame('used', $reward->fresh()->status);
        $this->assertSame($order->id, $reward->fresh()->order_id);
        $this->assertNull($reward->fresh()->order_item_id);

        // Staff see the code on the board and the receipt.
        $admin = $this->portalUser('admin');
        $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => 1])->get(route('admin.home'))
            ->assertOk()->assertSee($reward->displayCode());
        $this->actingAs($admin, 'admin')->get(route('admin.receipt', $order->id))
            ->assertOk()->assertSee($reward->displayCode());

        // Once.
        $this->complete($order);
        [$again, $second] = $this->place($user, $this->cart($this->item(1, 100), 3), [
            'voucher_code_confirmed' => $reward->displayCode(),
        ]);
        $this->assertNull($second);
        $again->assertSessionHasErrors('voucher_code_confirmed');
    }

    public function test_a_voucher_reward_is_used_only_at_the_branch_that_issued_it(): void
    {
        // Earned at branch 2 from branch 2's own voucher: usable at branch 2 only.
        $branch2 = Branch::findOrFail(2);
        $user = $this->customer();
        $reward = $this->grantAt($user, $branch2->id, $this->voucher(['branch_id' => $branch2->id]));
        $this->assertSame($branch2->id, $reward->branch_id);

        [$refused, $none] = $this->place($user, $this->cart($this->item(1, 100)), ['voucher_code_confirmed' => $reward->displayCode()], 1);
        $this->assertNull($none);
        $refused->assertSessionHasErrors('voucher_code_confirmed');
        $this->assertSame('This reward can only be used at ' . $branch2->name . '.', session('errors')->first('voucher_code_confirmed'));
        $this->assertSame('available', $reward->fresh()->status);

        // At its own branch: accepted.
        [$ok, $order] = $this->place($user, $this->cart($this->item(2, 100)), ['voucher_code_confirmed' => $reward->displayCode()], 2);
        $ok->assertSessionHasNoErrors();
        $this->assertNotNull($order);
        $this->assertSame('used', $reward->fresh()->status);
        $this->assertSame(2, $reward->fresh()->used_branch_id);
    }

    public function test_a_voucher_reward_keeps_its_templates_minimum_on_the_paid_subtotal(): void
    {
        $user = $this->customer();
        $reward = $this->grantVoucherReward($user, $this->voucher(['minimum_order' => 200]));

        [$refused, $none] = $this->place($user, $this->cart($this->item(1, 100)), ['voucher_code_confirmed' => $reward->displayCode()]);
        $this->assertNull($none);
        $this->assertSame('Minimum order of ₱200.00 required.', session('errors')->first('voucher_code_confirmed'));

        [, $order] = $this->place($user, $this->cart($this->item(1, 100), 2), ['voucher_code_confirmed' => $reward->displayCode()]);
        $this->assertNotNull($order);
    }

    public function test_a_voucher_reward_never_stacks_with_pwd_senior_and_the_loser_is_kept(): void
    {
        $pwd = [
            'discount_type'             => 'senior',
            'discount_applied'          => '1',
            'discount_beneficiary_name' => 'Juan Dela Cruz',
            'discount_beneficiary_id'   => 'SC-12345',
        ];

        // 10% (₱20) loses to 20% PWD/Senior (₱40): the reward is kept.
        $user = $this->customer();
        $small = $this->grantVoucherReward($user, $this->voucher(['discount_type' => 'percent', 'discount_value' => 10]));
        [, $order] = $this->place($user, $this->cart($this->item(1, 100), 2), $pwd + ['voucher_code_confirmed' => $small->displayCode()]);
        $this->assertSame('senior', $order->discount_type);
        $this->assertEquals(40.00, (float) $order->discount_amount);
        $this->assertNull($order->reward_code);
        $this->assertSame('available', $small->fresh()->status, 'the losing reward was spent');

        // ₱100 off beats ₱40: the reward wins, and no ID is recorded.
        $other = $this->customer();
        $big = $this->grantVoucherReward($other, $this->voucher(['discount_type' => 'fixed', 'discount_value' => 100]));
        [, $order2] = $this->place($other, $this->cart($this->item(1, 100), 2), $pwd + ['voucher_code_confirmed' => $big->displayCode()]);
        $this->assertSame('reward', $order2->discount_type);
        $this->assertEquals(100.00, (float) $order2->discount_amount);
        $this->assertSame('approved', $order2->discount_status);
        $this->assertNull($order2->discount_beneficiary_name);
        $this->assertSame(0, $order2->discountBeneficiaries()->count());
        $this->assertSame('used', $big->fresh()->status);
    }

    public function test_one_code_per_order_and_one_reward_per_order(): void
    {
        $user = $this->customer();
        $voucherReward = $this->grantVoucherReward($user, $this->voucher(), 1);
        $itemReward = $this->grantItemReward($user, $this->item(), 2);
        $plainVoucher = $this->voucher();

        // A voucher AND a voucher-type reward: two discount codes.
        [$a, $none] = $this->place($user, $this->cart($this->item(1, 300)), [
            'voucher_code_confirmed' => $plainVoucher->code,
            'reward_code'            => $voucherReward->displayCode(),
        ]);
        $this->assertNull($none);
        $a->assertSessionHasErrors('voucher_code_confirmed');

        // Two rewards (a discount reward and an item reward).
        [$b, $none] = $this->place($user, $this->cart($this->item(1, 300)), [
            'voucher_code_confirmed' => $voucherReward->displayCode(),
            'reward_code'            => $itemReward->displayCode(),
        ]);
        $this->assertNull($none);
        $b->assertSessionHasErrors('reward_code');
        $this->assertStringContainsString('Only one reward', session('errors')->first('reward_code'));

        $this->assertSame(['available', 'available'], [$voucherReward->fresh()->status, $itemReward->fresh()->status]);
        $this->assertSame(0, $plainVoucher->fresh()->used_count);
    }

    public function test_cancelling_gives_a_voucher_reward_back_with_the_same_code(): void
    {
        $user = $this->customer();
        $reward = $this->grantVoucherReward($user, $this->voucher());
        [, $order] = $this->place($user, $this->cart($this->item(1, 100)), ['voucher_code_confirmed' => $reward->displayCode()]);

        $this->actingAs($this->portalUser('admin'), 'admin')->put(route('admin.orders.cancel', $order->id))->assertRedirect();

        $this->assertSame('available', $reward->fresh()->status);
        $this->assertSame($reward->code, $reward->fresh()->code);
        $this->assertNull($reward->fresh()->used_branch_id);
    }

    // ══════════ the cart preview and Use now ══════════

    public function test_the_code_box_previews_both_kinds_for_the_owner_only(): void
    {
        $user = $this->customer();
        $item = $this->item(1, 150, 20, ['name' => 'SR Preview Cake']);
        $itemReward = $this->grantItemReward($user, $item, 1);
        $discountReward = $this->grantVoucherReward($user, $this->voucher(['discount_type' => 'fixed', 'discount_value' => 30]), 2);
        $session = ['cart' => $this->cart($this->item()), 'branch_id' => 1, 'order_type' => 'pick_up'];

        $this->actingAs($user, 'customer')->withSession($session)
            ->postJson(route('customer.apply-voucher'), ['code' => strtolower($itemReward->displayCode()), 'subtotal' => 100])
            ->assertOk()
            ->assertJson(['success' => true, 'kind' => 'reward_item', 'code' => $itemReward->displayCode(), 'item_name' => 'SR Preview Cake']);

        $this->actingAs($user, 'customer')->withSession($session)
            ->postJson(route('customer.apply-voucher'), ['code' => $discountReward->displayCode(), 'subtotal' => 100])
            ->assertOk()
            ->assertJson(['success' => true, 'kind' => 'reward_discount', 'discount' => 30, 'final_total' => 70]);

        // Someone else, and a guest: exactly like a code that does not exist.
        $this->actingAs($this->customer(), 'customer')->withSession($session)
            ->postJson(route('customer.apply-voucher'), ['code' => $itemReward->displayCode(), 'subtotal' => 100])
            ->assertExactJson(['success' => false, 'message' => 'Invalid voucher code.']);

        auth()->guard('customer')->logout();
        $this->withSession($session)
            ->postJson(route('customer.apply-voucher'), ['code' => $itemReward->displayCode(), 'subtotal' => 100])
            ->assertExactJson(['success' => false, 'message' => 'Invalid voucher code.']);

        // Previewing spends nothing.
        $this->assertSame(['available', 'available'], [$itemReward->fresh()->status, $discountReward->fresh()->status]);
    }

    public function test_use_now_hands_the_code_to_the_cart_and_spends_nothing(): void
    {
        $user = $this->customer();
        $reward = $this->grantItemReward($user, $this->item());
        $cart = $this->cart($this->item());

        $this->actingAs($user, 'customer')->withSession(['cart' => $cart, 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post(route('customer.rewards.use', $reward->id))
            ->assertRedirect(route('customer.cart'))
            ->assertSessionHas('apply_reward_code', $reward->displayCode());

        // The cart page hands it to its code box once, then forgets it.
        $html = $this->actingAs($user, 'customer')->withSession(['cart' => $cart, 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->get(route('customer.cart'))->assertOk()->getContent();
        $this->assertStringContainsString('"' . $reward->displayCode() . '"', $html);
        $this->assertNull(session('apply_reward_code'));

        $this->assertSame('available', $reward->fresh()->status);
    }

    public function test_use_now_with_an_empty_cart_goes_to_the_menu_first(): void
    {
        $user = $this->customer();
        $reward = $this->grantItemReward($user, $this->item());

        $this->actingAs($user, 'customer')->withSession(['branch_id' => 1, 'order_type' => 'pick_up'])
            ->post(route('customer.rewards.use', $reward->id))
            ->assertRedirect(route('customer.menu'))
            ->assertSessionHas('apply_reward_code', $reward->displayCode());
    }

    public function test_use_now_refuses_someone_elses_used_or_expired_reward_and_guests(): void
    {
        $owner = $this->customer();
        $reward = $this->grantItemReward($owner, $this->item());

        $this->actingAs($this->customer(), 'customer')->post(route('customer.rewards.use', $reward->id))
            ->assertRedirect(route('customer.vouchers'))
            ->assertSessionHas('error', 'That reward could not be found.')
            ->assertSessionMissing('apply_reward_code');

        $reward->forceFill(['status' => 'used', 'used_at' => now()])->save();
        $this->actingAs($owner, 'customer')->post(route('customer.rewards.use', $reward->id))
            ->assertSessionHas('error', 'This reward code has already been used.')
            ->assertSessionMissing('apply_reward_code');

        auth()->guard('customer')->logout();
        $this->post(route('customer.rewards.use', $reward->id))->assertRedirect(route('customer.login'));
    }

    public function test_guessing_reward_codes_is_capped_by_the_apply_voucher_limiter(): void
    {
        $user = $this->customer();
        $session = ['cart' => $this->cart($this->item()), 'branch_id' => 1, 'order_type' => 'pick_up'];

        for ($i = 0; $i < RateLimitServiceProvider::VOUCHER_PER_SESSION; $i++) {
            $this->actingAs($user, 'customer')->withSession($session)
                ->postJson(route('customer.apply-voucher'), ['code' => RewardCode::display(RewardCode::generate()), 'subtotal' => 100])
                ->assertOk();
        }

        $this->actingAs($user, 'customer')->withSession($session)
            ->postJson(route('customer.apply-voucher'), ['code' => RewardCode::display(RewardCode::generate()), 'subtotal' => 100])
            ->assertStatus(429);
    }

    // ══════════ the code check ══════════

    private function check(User $actor, string $code)
    {
        return $this->actingAs($actor, 'admin')->followingRedirects()
            ->post(route('admin.streak.rewards.check'), ['reward_code' => $code]);
    }

    public function test_the_owner_checks_any_code_and_sees_every_detail(): void
    {
        $admin = $this->portalUser('admin');
        $user = $this->customer(true, 'Maria Clara');
        $valid = $this->grantItemReward($user, $this->item(1, 100, 20, ['name' => 'SR Check Cake']), 1);

        $this->check($admin, strtolower($valid->displayCode()))->assertOk()
            ->assertSee('data-state="valid"', false)
            ->assertSee('Valid — can be used')
            ->assertSee('Maria Clara')
            ->assertSee('Free SR Check Cake')
            ->assertSee('Nov 14, 2026');

        // Issued and used at branch 2: order number, date and branch.
        $used = $this->grantAt($user, 2, $this->item(2, 100), 2);
        [, $order] = $this->place($user, $this->cart($this->item(2, 100)), ['reward_code' => $used->displayCode()], 2);
        $this->assertNotNull($order);

        $this->check($admin, $used->displayCode())->assertOk()
            ->assertSee('data-state="used"', false)
            ->assertSee('Already used')
            ->assertSee($order->order_number)
            ->assertSee('<dt>Issued by</dt><dd data-testid="reward-check-issued-by">' . e(Branch::find(2)->name) . '</dd>', false)
            ->assertSee('Oct 15, 2026')
            ->assertSee('That order is not finished yet.');

        // Expired.
        $expired = $this->grantItemReward($user, $this->item(), 4);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->check($admin, $expired->displayCode())->assertOk()->assertSee('data-state="expired"', false);

        // Not found, and malformed.
        $this->check($admin, 'RWD-2345-6789')->assertOk()->assertSee('data-state="not_found"', false);
        $this->check($admin, 'hello')->assertOk()->assertSee('data-state="not_found"', false);

        // Checking never spends or changes anything.
        $this->assertSame('available', $valid->fresh()->status);
        $this->assertSame('available', $expired->fresh()->status, 'the check swept a reward to expired');
    }

    public function test_a_supervisor_sees_details_only_for_codes_their_branch_issued(): void
    {
        $supervisor = $this->portalUser('supervisor', 1);
        $user = $this->customer(true, 'Juan Luna');

        // Issued by THEIR branch, valid: full details, so they can serve the customer.
        $valid = $this->grantAt($user, 1, $this->item(), 1);
        $this->check($supervisor, $valid->displayCode())->assertOk()
            ->assertSee('data-state="valid"', false)
            ->assertSee('Juan Luna');

        // Issued by and used at THEIR branch: full details.
        $ownBranch = $this->grantAt($user, 1, $this->item(1, 100), 2);
        [, $ownOrder] = $this->place($user, $this->cart($this->item(1, 100)), ['reward_code' => $ownBranch->displayCode()], 1);
        $this->complete($ownOrder);
        $this->check($supervisor, $ownBranch->displayCode())->assertOk()
            ->assertSee('data-state="used"', false)
            ->assertSee($ownOrder->order_number);

        // Issued by ANOTHER branch (used there): "belongs to another branch" and nothing else.
        $elsewhere = $this->grantAt($user, 2, $this->item(2, 100), 1);
        [, $otherOrder] = $this->place($user, $this->cart($this->item(2, 100)), ['reward_code' => $elsewhere->displayCode()], 2);
        $this->assertNotNull($otherOrder);

        $html = $this->check($supervisor, $elsewhere->displayCode())->assertOk()->getContent();
        $this->assertStringContainsString('data-state="other_branch"', $html);
        $this->assertStringContainsString('This code belongs to another branch.', $html);
        $this->assertStringNotContainsString('Already used', $html);
        $this->assertStringNotContainsString($otherOrder->order_number, $html);
        $this->assertStringNotContainsString('Juan Luna', $html);
        $this->assertStringNotContainsString('reward-check-issued-by', $html);

        // The owner sees that one in full.
        $this->check($this->portalUser('admin'), $elsewhere->displayCode())->assertOk()
            ->assertSee($otherOrder->order_number);
    }

    public function test_staff_customers_and_guests_cannot_check_codes(): void
    {
        $reward = $this->grantItemReward($this->customer(), $this->item());

        $this->actingAs($this->portalUser('staff', 1), 'admin')
            ->post(route('admin.streak.rewards.check'), ['reward_code' => $reward->displayCode()])
            ->assertRedirect(route('admin.home'))
            ->assertSessionMissing('reward_check');

        $this->actingAs($this->customer(), 'customer')
            ->post(route('admin.streak.rewards.check'), ['reward_code' => $reward->displayCode()])
            ->assertRedirect()
            ->assertSessionMissing('reward_check');

        auth()->guard('admin')->logout();
        auth()->guard('customer')->logout();
        $this->post(route('admin.streak.rewards.check'), ['reward_code' => $reward->displayCode()])
            ->assertRedirect()
            ->assertSessionMissing('reward_check');
    }

    public function test_the_check_is_rate_limited_per_user_and_per_ip(): void
    {
        $admin = $this->portalUser('admin');

        for ($i = 0; $i < RateLimitServiceProvider::ADMIN_REWARD_CODE_CHECK_PER_USER; $i++) {
            $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.check'), ['reward_code' => 'RWD-2345-6789'])
                ->assertRedirect();
        }
        $this->actingAs($admin, 'admin')->post(route('admin.streak.rewards.check'), ['reward_code' => 'RWD-2345-6789'])
            ->assertStatus(429);

        // Per IP: other accounts on the same address, each staying under
        // its own limit, run into the address ceiling. A request the
        // per-user limit refused never reached the address counter, so the
        // address allows exactly ADMIN_REWARD_CODE_CHECK_PER_IP checks.
        $accepted = RateLimitServiceProvider::ADMIN_REWARD_CODE_CHECK_PER_USER;
        $refused = false;

        for ($account = 0; $account < 10 && ! $refused; $account++) {
            $supervisor = $this->portalUser('supervisor', 1);

            for ($i = 0; $i < RateLimitServiceProvider::ADMIN_REWARD_CODE_CHECK_PER_USER - 1; $i++) {
                $status = $this->actingAs($supervisor, 'admin')
                    ->post(route('admin.streak.rewards.check'), ['reward_code' => 'RWD-2345-6789'])->getStatusCode();

                if ($status === 429) {
                    $refused = true;
                    break;
                }

                $accepted++;
            }
        }

        $this->assertTrue($refused, 'the per-IP ceiling never applied');
        $this->assertSame(RateLimitServiceProvider::ADMIN_REWARD_CODE_CHECK_PER_IP, $accepted);
    }

    // ══════════ the switch, the rename, guests ══════════

    public function test_switched_off_no_reward_is_earned_but_earned_ones_can_be_used_and_checked(): void
    {
        $user = $this->customer();
        $reward = $this->grantItemReward($user, $this->item());
        Setting::updateOrCreate(['key' => Setting::STREAK_CALENDAR_ENABLED, 'branch_id' => null], ['value' => '0', 'group' => 'business', 'type' => 'text']);

        $this->assertNull(FreeDesserts::grant($user, 1, 2));

        $html = $this->actingAs($user, 'customer')->withSession(['branch_id' => 1])->get(route('customer.vouchers'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-testid="free-dessert-card"', $html);
        $this->assertStringContainsString($reward->displayCode(), $html);

        $this->check($this->portalUser('admin'), $reward->displayCode())->assertOk()->assertSee('data-state="valid"', false);

        [, $order] = $this->place($user, $this->cart($this->item()), ['reward_code' => $reward->displayCode()]);
        $this->assertNotNull($order);
        $this->assertSame('used', $reward->fresh()->status);
    }

    public function test_vouchers_is_now_vouchers_and_rewards_everywhere_a_customer_sees_it(): void
    {
        $user = $this->customer();

        $more = $this->actingAs($user, 'customer')->get(route('customer.more'))->assertOk()->getContent();
        $this->assertStringContainsString('Vouchers &amp; Rewards', $more);
        $this->assertStringNotContainsString('Vouchers &amp; Points', $more);
        $this->assertStringContainsString('href="' . route('customer.vouchers') . '"', $more);

        $page = $this->actingAs($user, 'customer')->get(route('customer.vouchers'))->assertOk()->getContent();
        $this->assertStringContainsString('<title>Vouchers &amp; Rewards - Peachy</title>', $page);
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Vouchers &amp; Rewards\s*<\/h1>/', $page);
        $this->assertStringNotContainsString('My Vouchers', $page);
        // The bottom nav lights More, where Vouchers & Rewards lives.
        $this->assertMatchesRegularExpression('/href="' . preg_quote(route('customer.more'), '/') . '"[^>]*aria-current="page"/', $page);

        $help = $this->actingAs($user, 'customer')->get(route('customer.help'))->assertOk()->getContent();
        $this->assertStringContainsString('More &rarr; Vouchers &amp; Rewards', $help);
        $this->assertStringNotContainsString('Vouchers &amp; Points', $help);

        $this->actingAs($user, 'customer')->get(route('customer.account'))->assertOk()
            ->assertSee('Vouchers &amp; Rewards', false)
            ->assertDontSee('My Vouchers');
    }

    public function test_guests_get_nothing(): void
    {
        $this->chooseRewardItem($this->item());

        $this->get(route('customer.vouchers'))->assertRedirect(route('customer.login'));
        $this->post(route('customer.rewards.use', 1))->assertRedirect(route('customer.login'));

        // A guest order completing earns nothing and grants no code.
        $before = FreeDessertReward::count();
        $guestOrder = Order::create([
            'order_number' => 'SRG-' . strtoupper(Str::random(8)), 'user_id' => null, 'branch_id' => 1,
            'type' => 'dine_in', 'table_number' => '9', 'status' => 'pending',
            'payment_method' => 'cash', 'payment_status' => 'pending', 'subtotal' => 50, 'total' => 50,
        ]);
        $this->complete($guestOrder);
        $this->assertSame($before, FreeDessertReward::count());
    }
}

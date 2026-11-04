<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FreeDessertReward;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\FreeDesserts;
use App\Services\StreakRewardSettings;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ConfiguresStreakReward;
use Tests\TestCase;

/**
 * QA, 2 Nov 2026: "I only logged out and my streak was gone", "after forgot
 * password my streak was gone", "no 2nd stamp", "the page looks different at
 * each branch".
 *
 * Nothing was ever lost: stamps and streaks are read from completed orders. The
 * cards read the branch from the session, and logging out empties the session,
 * so a signed-in customer came back to a dash and a "choose a branch" card, and
 * a branch that was off simply lost its cards. So:
 *
 *   - the account remembers which branch's card to show (users.rewards_branch_id):
 *     the branch last chosen or last ordered from, whichever came last;
 *   - the customer can switch card without touching the branch they order from;
 *   - every branch has the same layout, with a clear card where it has no stamp
 *     card (off, or no reward chosen);
 *   - the page says what a streak is and that stamps are per branch.
 *
 * The stamp and streak RULES are not changed by any of this, and these tests pin
 * that as well. Every row is created inside DatabaseTransactions and rolled back.
 */
class StampCardBranchMemoryTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresStreakReward;

    // 12:00 Manila on 2 Nov 2026.
    private const NOW_UTC = '2026-11-02 04:00:00';
    private const PASSWORD = 'Aa1!aaaaaa';

    private const STREAK_LINE = 'A streak counts days in a row. Missing a day starts it again at 1; your best streak is kept.';
    private const UNAVAILABLE = "The stamp card isn't available at this branch yet.";

    private Branch $main;
    private Branch $angono;

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

        $this->main = $this->branch('QA Main');
        $this->angono = $this->branch('QA Angono');

        // Different cards on purpose: QA report item (e).
        $this->chooseBranchReward($this->main->id, $this->rewardItem($this->main), 7);
        $this->chooseBranchReward($this->angono->id, $this->rewardItem($this->angono), 5);
    }

    // ══════════ fixtures ══════════

    private function branch(string $name, bool $active = true): Branch
    {
        return Branch::create([
            'name'      => $name . ' ' . uniqid(),
            'code'      => 'QA' . strtoupper(substr(uniqid(), -7)),
            'address'   => 'QA address',
            'is_active' => $active,
        ]);
    }

    private function category(): Category
    {
        return Category::create(['name' => 'QA Category ' . uniqid(), 'is_active' => true]);
    }

    private function rewardItem(Branch $branch): MenuItem
    {
        return $this->plainRewardItem(['category_id' => $this->category()->id, 'branch_id' => $branch->id]);
    }

    private function customer(array $attrs = []): User
    {
        return User::create(array_merge([
            'name'              => 'QA Customer',
            'email'             => 'qa-memory-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => self::PASSWORD,
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ], $attrs))->fresh();
    }

    private function owner(): User
    {
        return User::create([
            'name'              => 'QA Owner',
            'email'             => 'qa-memory-owner-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => self::PASSWORD,
            'role'              => 'admin',
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function orderOn(User $user, string $manilaDay, Branch $branch, string $status = 'completed'): Order
    {
        $at = CarbonImmutable::parse($manilaDay . ' 12:00:00', 'Asia/Manila')->utc()->format('Y-m-d H:i:s');

        $order = Order::create([
            'order_number'   => 'QAM-' . strtoupper(Str::random(8)),
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

    private function account(User $user, array $session = []): string
    {
        return $this->actingAs($user, 'customer')
            ->withSession($session + ['branch_id' => null, 'order_type' => null, 'table_number' => null])
            ->get(route('customer.account'))->assertOk()->getContent();
    }

    private function vouchers(User $user, array $session = []): string
    {
        return $this->actingAs($user, 'customer')
            ->withSession($session + ['branch_id' => null, 'order_type' => null, 'table_number' => null])
            ->get(route('customer.vouchers'))->assertOk()->getContent();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function logIn(User $user, string $password = self::PASSWORD): void
    {
        $this->post(route('customer.login.post'), ['email' => $user->email, 'password' => $password])
            ->assertRedirect(route('customer.menu'));
    }

    /** A pending order placed today, completed by staff through the real endpoint. */
    private function completeToday(User $user, Branch $branch): Order
    {
        $order = $this->orderOn($user, '2026-11-02', $branch, 'pending');

        $this->actingAs($this->owner(), 'admin')
            ->put(route('admin.orders.complete', $order->id))
            ->assertRedirect();

        $this->assertSame('completed', $order->fresh()->status);

        return $order;
    }

    // ══════════ (a) "No 2nd stamp": the stamp rule itself was and is right ══════════

    public function test_oct_31_and_nov_2_at_one_branch_are_two_stamps_though_the_streak_is_one(): void
    {
        $user = $this->customer();
        $this->orderOn($user, '2026-10-31', $this->main);
        $this->completeToday($user, $this->main);

        $this->assertSame(2, FreeDesserts::countedDays($user, $this->main->id));

        $html = $this->account($user, ['branch_id' => $this->main->id]);

        $this->assertStringContainsString('2 / 7 stamps', $html);
        $this->assertMatchesRegularExpression('/data-testid="current-streak">\s*1 day\s*</', $html);
    }

    public function test_the_two_orders_at_two_branches_are_one_stamp_on_each_card(): void
    {
        $user = $this->customer();
        $this->orderOn($user, '2026-10-31', $this->main);
        $this->completeToday($user, $this->angono);

        $this->assertSame(1, FreeDesserts::countedDays($user, $this->main->id));
        $this->assertSame(1, FreeDesserts::countedDays($user, $this->angono->id));
    }

    public function test_an_order_not_completed_yet_gives_no_stamp_until_it_is(): void
    {
        $user = $this->customer();
        $this->orderOn($user, '2026-10-31', $this->main);
        $pending = $this->orderOn($user, '2026-11-02', $this->main, 'pending');

        $this->assertSame(1, FreeDesserts::countedDays($user, $this->main->id));

        $this->actingAs($this->owner(), 'admin')->put(route('admin.orders.complete', $pending->id))->assertRedirect();

        $this->assertSame(2, FreeDesserts::countedDays($user, $this->main->id));
    }

    // ══════════ (b)(c) logging out must never make the streak look gone ══════════

    public function test_logging_out_and_in_shows_the_same_streak_and_stamps(): void
    {
        $user = $this->customer();

        foreach (['2026-10-31', '2026-11-01', '2026-11-02'] as $day) {
            $this->orderOn($user, $day, $this->main);
        }

        $this->actingAs($user, 'customer')->post(route('customer.select-branch'), ['branch_id' => $this->main->id]);
        $before = $this->actingAs($user, 'customer')->get(route('customer.account'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-testid="chip-streak">\s*3 days\s*</', $before);
        $this->assertStringContainsString('3/7', $before);

        $this->post(route('customer.logout'))->assertRedirect();
        $this->assertNull(session('branch_id'), 'logging out is supposed to end the customer side of the session');

        $this->logIn($user);
        $after = $this->get(route('customer.account'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="streak-calendar" data-branch="' . $this->main->id . '"', $after);
        $this->assertStringContainsString('data-testid="free-dessert-card" data-branch="' . $this->main->id . '"', $after);
        $this->assertMatchesRegularExpression('/data-testid="chip-streak">\s*3 days\s*</', $after);
        $this->assertStringContainsString('3/7', $after);
        $this->assertStringNotContainsString('data-testid="streak-choose-branch"', $after);
    }

    public function test_a_forgotten_password_does_not_lose_the_card_either(): void
    {
        $user = $this->customer();
        $this->orderOn($user, '2026-11-02', $this->angono);
        $this->actingAs($user, 'customer')->post(route('customer.select-branch'), ['branch_id' => $this->angono->id]);

        // The whole reset, signed in at the time: it ends this login too.
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make('123456'), 'created_at' => now()]
        );
        $this->withSession(['customer_password_reset' => ['email' => $user->email, 'verified' => true, 'verified_at' => now()->timestamp]])
            ->post(route('customer.new-password.post'), ['password' => 'Bb2!bbbbbb', 'password_confirmation' => 'Bb2!bbbbbb'])
            ->assertRedirect(route('customer.login'));

        $this->assertTrue(Hash::check('Bb2!bbbbbb', $user->fresh()->password));
        $this->assertNull(session('branch_id'));

        $this->logIn($user, 'Bb2!bbbbbb');
        $html = $this->get(route('customer.account'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="free-dessert-card" data-branch="' . $this->angono->id . '"', $html);
        $this->assertStringContainsString('1 / 5 stamps', $html);
    }

    public function test_vouchers_and_rewards_keeps_the_card_after_logging_out_and_in(): void
    {
        $user = $this->customer();
        $this->orderOn($user, '2026-11-02', $this->main);
        $this->actingAs($user, 'customer')->post(route('customer.select-branch'), ['branch_id' => $this->main->id]);

        $this->post(route('customer.logout'));
        $this->logIn($user);

        $html = $this->get(route('customer.vouchers'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="free-dessert-card" data-branch="' . $this->main->id . '"', $html);
        $this->assertStringContainsString('1 / 7 stamps', $html);
    }

    public function test_an_account_that_never_chose_shows_the_branch_it_last_ordered_from(): void
    {
        $user = $this->customer();
        $this->orderOn($user, '2026-10-20', $this->main);
        $this->orderOn($user, '2026-10-25', $this->angono);

        $this->assertNull($user->rewards_branch_id, 'existing accounts start with nothing remembered');
        $this->assertStringContainsString('data-testid="free-dessert-card" data-branch="' . $this->angono->id . '"', $this->account($user));
    }

    public function test_an_account_with_no_branch_at_all_is_asked_to_choose_one(): void
    {
        $html = $this->account($this->customer());

        $this->assertStringContainsString('data-testid="streak-choose-branch"', $html);
        $this->assertStringContainsString('data-testid="card-branch-picker"', $html);
        $this->assertStringNotContainsString('data-testid="free-dessert-card"', $html);
    }

    // ══════════ what is remembered, and when ══════════

    public function test_choosing_a_pickup_branch_is_remembered_on_the_account(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'customer')->post(route('customer.select-branch'), ['branch_id' => $this->angono->id]);

        $this->assertSame($this->angono->id, (int) $user->fresh()->rewards_branch_id);
    }

    public function test_a_guest_choosing_a_branch_writes_to_no_account(): void
    {
        $user = $this->customer();

        $this->post(route('customer.select-branch'), ['branch_id' => $this->angono->id]);

        $this->assertNull($user->fresh()->rewards_branch_id);
    }

    public function test_the_branch_picked_before_signing_in_is_remembered_at_login(): void
    {
        $user = $this->customer();
        DB::table('users')->where('id', $user->id)->update(['rewards_branch_id' => $this->main->id]);

        // In this browser, as a guest, a moment ago.
        $this->post(route('customer.select-branch'), ['branch_id' => $this->angono->id]);
        $this->logIn($user);

        $this->assertSame($this->angono->id, (int) $user->fresh()->rewards_branch_id);
    }

    public function test_placing_an_order_remembers_its_branch(): void
    {
        $user = $this->customer();
        DB::table('users')->where('id', $user->id)->update(['rewards_branch_id' => $this->main->id]);

        $ingredient = Inventory::create([
            'branch_id'       => $this->angono->id,
            'item_name'       => 'QA Ingredient ' . uniqid(),
            'item_code'       => 'QA-' . strtoupper(substr(uniqid(), -8)) . random_int(10, 99),
            'quantity'        => 50,
            'unit'            => 'pc',
            'low_stock_alert' => 0,
            'unit_cost'       => 5,
            'is_active'       => true,
        ]);
        $item = $this->rewardItem($this->angono);
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $ingredient->id, 'quantity_used' => 1]);

        $this->actingAs($user->fresh(), 'customer')
            ->withSession([
                'branch_id'  => $this->angono->id,
                'order_type' => 'pick_up',
                'cart'       => [(string) $item->id => [
                    'menu_item_id' => (string) $item->id,
                    'name'         => $item->name,
                    'price'        => (float) $item->price,
                    'base_price'   => (float) $item->price,
                    'quantity'     => 1,
                    'image'        => null,
                    'options'      => [],
                ]],
            ])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => (string) $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Order::where('user_id', $user->id)->where('branch_id', $this->angono->id)->count());
        $this->assertSame($this->angono->id, (int) $user->fresh()->rewards_branch_id);
    }

    public function test_remembering_never_breaks_what_called_it(): void
    {
        $user = $this->customer();

        // A branch that does not exist: the foreign key refuses it, quietly.
        FreeDesserts::rememberBranch($user, 2147483000);

        $this->assertNull($user->fresh()->rewards_branch_id);
    }

    public function test_deleting_the_remembered_branch_only_forgets_it(): void
    {
        $user = $this->customer();
        $closing = $this->branch('QA Closing');
        FreeDesserts::rememberBranch($user, $closing->id);

        $closing->delete();

        $this->assertNull($user->fresh()->rewards_branch_id);
        $this->assertStringContainsString('data-testid="streak-choose-branch"', $this->account($user->fresh()));
    }

    // ══════════ switching which card is shown ══════════

    public function test_the_customer_can_switch_to_each_branchs_card(): void
    {
        $user = $this->customer();
        $this->orderOn($user, '2026-10-20', $this->main);
        $this->orderOn($user, '2026-10-22', $this->main);
        $this->orderOn($user, '2026-10-21', $this->angono);

        $this->actingAs($user, 'customer')
            ->post(route('customer.rewards-branch'), ['branch_id' => $this->main->id])
            ->assertRedirect(route('customer.account') . '#overview');
        $this->assertStringContainsString('2 / 7 stamps', $this->account($user->fresh()));

        $this->actingAs($user->fresh(), 'customer')
            ->post(route('customer.rewards-branch'), ['branch_id' => $this->angono->id, 'return_to' => 'vouchers'])
            ->assertRedirect(route('customer.vouchers') . '#stamp-card-branch');
        $this->assertStringContainsString('1 / 5 stamps', $this->vouchers($user->fresh()));
    }

    public function test_switching_the_card_does_not_move_the_branch_being_ordered_from(): void
    {
        $user = $this->customer();
        $cart = ['1' => ['menu_item_id' => '1', 'name' => 'Kept', 'price' => 1, 'quantity' => 1, 'options' => []]];

        $this->actingAs($user, 'customer')
            ->withSession(['branch_id' => $this->main->id, 'order_type' => 'pick_up', 'cart' => $cart])
            ->post(route('customer.rewards-branch'), ['branch_id' => $this->angono->id])
            ->assertRedirect();

        $this->assertSame($this->main->id, (int) session('branch_id'));
        $this->assertSame('pick_up', session('order_type'));
        $this->assertSame($cart, session('cart'), 'looking at another branch\'s card emptied the cart');
        $this->assertSame($this->angono->id, (int) $user->fresh()->rewards_branch_id);
    }

    public function test_use_now_still_follows_the_branch_being_ordered_from_not_the_card(): void
    {
        $user = $this->customer();
        $reward = FreeDesserts::grant($user, $this->main->id, 1);
        $this->assertInstanceOf(FreeDessertReward::class, $reward);

        DB::table('users')->where('id', $user->id)->update(['rewards_branch_id' => $this->angono->id]);

        $atMain = $this->vouchers($user->fresh(), ['branch_id' => $this->main->id, 'order_type' => 'pick_up']);
        $this->assertStringContainsString('data-testid="free-dessert-card" data-branch="' . $this->angono->id . '"', $atMain);
        $this->assertStringContainsString('data-testid="loyalty-reward-use"', $atMain);

        $atAngono = $this->vouchers($user->fresh(), ['branch_id' => $this->angono->id, 'order_type' => 'pick_up']);
        $this->assertStringNotContainsString('data-testid="loyalty-reward-use"', $atAngono);
        $this->assertStringContainsString('data-testid="loyalty-reward-elsewhere"', $atAngono);
    }

    public function test_the_switch_refuses_a_branch_it_does_not_offer(): void
    {
        $user = $this->customer();
        $closed = $this->branch('QA Closed', active: false);

        $this->actingAs($user, 'customer')->post(route('customer.rewards-branch'), ['branch_id' => $closed->id])->assertNotFound();
        $this->actingAs($user, 'customer')->post(route('customer.rewards-branch'), ['branch_id' => 2147483000])->assertNotFound();
        $this->actingAs($user, 'customer')->post(route('customer.rewards-branch'), ['branch_id' => 'abc'])->assertNotFound();
        $this->actingAs($user, 'customer')->post(route('customer.rewards-branch'), [])->assertNotFound();

        $this->assertNull($user->fresh()->rewards_branch_id);
    }

    public function test_a_closed_branch_the_customer_ordered_at_is_still_offered(): void
    {
        $user = $this->customer();
        $closed = $this->branch('QA Closed', active: false);
        $never = $this->branch('QA Never', active: false);
        $this->orderOn($user, '2026-10-20', $closed);

        $options = FreeDesserts::cardBranchOptions($user)->pluck('id')->all();

        $this->assertContains($closed->id, $options);
        $this->assertContains($this->main->id, $options);
        $this->assertNotContains($never->id, $options);

        $this->actingAs($user, 'customer')->post(route('customer.rewards-branch'), ['branch_id' => $closed->id])->assertRedirect();
        $this->assertSame($closed->id, (int) $user->fresh()->rewards_branch_id);
    }

    public function test_a_guest_cannot_switch(): void
    {
        $this->post(route('customer.rewards-branch'), ['branch_id' => $this->main->id])
            ->assertRedirect(route('customer.login'));
    }

    public function test_the_switch_is_on_its_own_limiter(): void
    {
        $route = app('router')->getRoutes()->getByName('customer.rewards-branch');

        $this->assertContains('throttle:customer-rewards-branch', $route->gatherMiddleware());
    }

    public function test_the_switch_flashes_nothing_that_would_open_the_profile_tab(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'customer')
            ->post(route('customer.rewards-branch'), ['branch_id' => $this->main->id])
            ->assertSessionMissing('success');
    }

    /**
     * The whole Account page sits inside one big form, and a form inside a form
     * is dropped by the HTML parser: its buttons would then submit the account
     * form. See account-settings.blade.php.
     */
    public function test_the_account_pages_switch_is_a_real_form_outside_the_account_form(): void
    {
        $x = $this->xpath($this->account($this->customer(), ['branch_id' => $this->main->id]));

        $picker = $x->query('//form[@data-testid="card-branch-picker"]');
        $this->assertSame(1, $picker->length);
        $this->assertSame(0, $x->query('//form[@id="account-form"]//*[@data-testid="card-branch-picker"]')->length);
        $this->assertStringEndsWith('/customer/rewards-branch', $picker->item(0)->getAttribute('action'));
        $this->assertSame(1, $x->query('//form[@data-testid="card-branch-picker"]//input[@name="_token"]')->length);
        $this->assertGreaterThanOrEqual(2, $x->query('//form[@data-testid="card-branch-picker"]//button[@name="branch_id"]')->length);
    }

    public function test_the_switch_marks_the_card_being_shown(): void
    {
        $x = $this->xpath($this->account($this->customer(), ['branch_id' => $this->angono->id]));

        $on = $x->query('//form[@data-testid="card-branch-picker"]//button[@aria-pressed="true"]');

        $this->assertSame(1, $on->length);
        $this->assertSame((string) $this->angono->id, $on->item(0)->getAttribute('value'));
    }

    // ══════════ (d) one layout at every branch ══════════

    /** The cards of the Overview tab, in page order: testid => present. */
    private function overviewShape(string $html): array
    {
        $x = $this->xpath($html);

        return [
            'picker'  => $x->query('//*[@data-testid="card-branch-picker"]')->length,
            'left'    => $x->query('//*[@id="p-overview"]//*[@data-testid="streak-calendar" or @data-testid="streak-unavailable"]')->length,
            'stamps'  => $x->query('//*[@id="p-overview"]//*[@data-testid="free-dessert-card" or @data-testid="stamp-card-unavailable"]')->length,
            'balance' => $x->query('//*[@id="p-overview"]//*[@data-testid="bonus-banner"]')->length,
            'split'   => $x->query('//*[@id="p-overview"]//div[contains(@class, "min-[761px]:col-span-5")]')->length,
        ];
    }

    public function test_every_branch_has_the_same_layout_whatever_its_stamp_card_is_doing(): void
    {
        $user = $this->customer();
        $this->orderOn($user, '2026-10-20', $this->main);

        $off = $this->branch('QA Off');
        $this->setBranchSwitch($off->id, false);

        $notSetUp = $this->branch('QA Not Set Up');

        $expected = ['picker' => 1, 'left' => 1, 'stamps' => 1, 'balance' => 1, 'split' => 1];

        foreach ([$this->main, $this->angono, $off, $notSetUp] as $branch) {
            DB::table('users')->where('id', $user->id)->update(['rewards_branch_id' => $branch->id]);

            $this->assertSame($expected, $this->overviewShape($this->account($user->fresh())), 'account page at ' . $branch->name);

            $html = $this->vouchers($user->fresh());
            $x = $this->xpath($html);
            $this->assertSame(1, $x->query('//*[@data-testid="loyalty-rewards"]')->length, 'vouchers page at ' . $branch->name);
            $this->assertSame(1, $x->query('//*[@data-testid="card-branch-picker"]')->length, 'vouchers page at ' . $branch->name);
            $this->assertSame(1, $x->query('//*[@data-testid="free-dessert-card" or @data-testid="stamp-card-unavailable"]')->length, 'vouchers page at ' . $branch->name);
        }
    }

    public function test_a_branch_switched_off_shows_the_clear_card_on_both_pages(): void
    {
        $user = $this->customer();
        $this->setBranchSwitch($this->main->id, false);
        DB::table('users')->where('id', $user->id)->update(['rewards_branch_id' => $this->main->id]);

        foreach ([$this->account($user->fresh()), $this->vouchers($user->fresh())] as $html) {
            $this->assertStringContainsString(self::UNAVAILABLE, $html);
            $this->assertStringContainsString($this->main->name, $html);
            $this->assertStringNotContainsString('data-testid="free-dessert-card"', $html);
        }

        $this->assertStringContainsString('data-testid="streak-unavailable"', $this->account($user->fresh()));
    }

    public function test_a_branch_with_no_reward_chosen_shows_the_real_card_as_reward_coming_soon_and_keeps_its_calendar(): void
    {
        $user = $this->customer();
        $bare = $this->branch('QA No Reward');
        DB::table('users')->where('id', $user->id)->update(['rewards_branch_id' => $bare->id]);

        $html = $this->account($user->fresh());

        // Its switch is ON, so it is the normal card (not the "isn't available" one).
        $this->assertStringNotContainsString(self::UNAVAILABLE, $html);
        $this->assertStringNotContainsString('data-testid="stamp-card-unavailable"', $html);
        $this->assertStringContainsString('data-testid="free-dessert-card"', $html);
        $this->assertStringContainsString('Reward coming soon', $html);
        $this->assertStringContainsString('Keep collecting. Your reward will be added once the cafe sets it up.', $html);
        $this->assertStringContainsString('data-testid="streak-calendar"', $html, 'its branch switch is on, so its calendar still shows');

        $vouchers = $this->vouchers($user->fresh());
        $this->assertStringNotContainsString(self::UNAVAILABLE, $vouchers);
        $this->assertStringContainsString('data-testid="free-dessert-card"', $vouchers);
        $this->assertStringContainsString('Reward coming soon', $vouchers);
    }

    public function test_stamps_at_a_branch_with_no_reward_show_on_the_card_and_a_full_card_still_says_so(): void
    {
        $user = $this->customer();
        $bare = $this->branch('QA No Reward');
        DB::table('users')->where('id', $user->id)->update(['rewards_branch_id' => $bare->id]);

        $this->orderOn($user, '2026-10-20', $bare);
        $this->orderOn($user, '2026-10-22', $bare);
        foreach ([$this->account($user->fresh()), $this->vouchers($user->fresh())] as $html) {
            // The customer's real stamps and the built-in days needed (config 7: none saved).
            $this->assertStringContainsString('2 / 7 stamps', $html);
            $this->assertSame(2, substr_count($html, 'data-stamp="filled"'));
            $this->assertSame(5, substr_count($html, 'data-stamp="empty"'));
        }

        foreach (['2026-10-10', '2026-10-12', '2026-10-14', '2026-10-16', '2026-10-18'] as $day) {
            $this->orderOn($user, $day, $bare);
        }
        $this->assertStringContainsString('data-testid="free-dessert-waiting"', $this->account($user->fresh()));

        // Switched off: the "isn't available" card, with the stamps still saved, never a made-up zero.
        $this->setBranchSwitch($bare->id, false);
        $this->assertMatchesRegularExpression('/Your 7 stamps at [^<]*are saved\./', $this->account($user->fresh()));
        $this->assertStringContainsString(self::UNAVAILABLE, $this->vouchers($user->fresh()));
    }

    public function test_the_no_card_state_changes_no_stamp_or_reward_rule(): void
    {
        $user = $this->customer();
        $bare = $this->branch('QA No Reward');

        foreach (['2026-10-10', '2026-10-12', '2026-10-14', '2026-10-16', '2026-10-18', '2026-10-20', '2026-10-22'] as $day) {
            $this->orderOn($user, $day, $bare);
        }

        $this->assertSame(7, FreeDesserts::countedDays($user, $bare->id));
        $this->assertSame([], FreeDesserts::grantDue($user, $bare->id), 'no reward chosen: nothing can be granted');

        $this->chooseBranchReward($bare->id, $this->rewardItem($bare), 7);

        $this->assertCount(1, FreeDesserts::grantDue($user, $bare->id), 'the full card is paid once a reward is chosen');
    }

    public function test_the_owners_master_switch_still_hides_all_of_it(): void
    {
        $user = $this->customer();
        DB::table('users')->where('id', $user->id)->update(['rewards_branch_id' => $this->main->id]);
        Setting::updateOrCreate(['key' => Setting::STREAK_CALENDAR_ENABLED, 'branch_id' => null], ['value' => '0', 'group' => 'loyalty', 'type' => 'text']);

        foreach ([$this->account($user->fresh()), $this->vouchers($user->fresh())] as $html) {
            $this->assertStringNotContainsString('data-testid="card-branch-picker"', $html);
            $this->assertStringNotContainsString('data-testid="free-dessert-card"', $html);
            $this->assertStringNotContainsString('data-testid="stamp-card-unavailable"', $html);
            $this->assertStringNotContainsString('data-testid="streak-unavailable"', $html);
            $this->assertStringNotContainsString('data-testid="streak-choose-branch"', $html);
        }
    }

    // ══════════ (b)(e) the page explains itself ══════════

    public function test_a_missed_day_restarts_the_streak_at_1_keeps_the_best_and_says_so(): void
    {
        $user = $this->customer();

        foreach (['2026-10-28', '2026-10-29', '2026-10-30', '2026-11-02'] as $day) {
            $this->orderOn($user, $day, $this->main);
        }

        $html = $this->account($user, ['branch_id' => $this->main->id]);

        $this->assertMatchesRegularExpression('/data-testid="current-streak">\s*1 day\s*</', $html);
        $this->assertMatchesRegularExpression('/data-testid="best-streak">\s*3 days\s*</', $html);
        $this->assertStringContainsString(self::STREAK_LINE, $html);
    }

    public function test_the_stamp_card_says_its_stamps_count_only_at_its_branch_on_both_pages(): void
    {
        $user = $this->customer();
        DB::table('users')->where('id', $user->id)->update(['rewards_branch_id' => $this->angono->id]);

        foreach ([$this->account($user->fresh()), $this->vouchers($user->fresh())] as $html) {
            $this->assertStringContainsString('Stamps here count only for orders at ' . $this->angono->name . '.', $html);
        }
    }

    public function test_stamps_stay_per_branch_and_each_card_keeps_its_own_settings(): void
    {
        $user = $this->customer();
        $this->orderOn($user, '2026-10-20', $this->main);
        $this->orderOn($user, '2026-10-22', $this->main);
        $this->orderOn($user, '2026-10-21', $this->angono);

        DB::table('users')->where('id', $user->id)->update(['rewards_branch_id' => $this->main->id]);
        $this->assertStringContainsString('2 / 7 stamps', $this->account($user->fresh()));

        DB::table('users')->where('id', $user->id)->update(['rewards_branch_id' => $this->angono->id]);
        $this->assertStringContainsString('1 / 5 stamps', $this->account($user->fresh()));
    }

    public function test_nothing_here_writes_an_award_or_a_reward(): void
    {
        $user = $this->customer();
        $this->orderOn($user, '2026-10-20', $this->main);

        $this->actingAs($user, 'customer')->post(route('customer.rewards-branch'), ['branch_id' => $this->angono->id]);
        $this->account($user->fresh());
        $this->vouchers($user->fresh());

        $this->assertSame(0, DB::table('loyalty_awards')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('free_dessert_rewards')->where('user_id', $user->id)->count());
        $this->assertSame(StreakRewardSettings::TYPE_MENU_ITEM, StreakRewardSettings::current($this->main->id)['type']);
    }
}

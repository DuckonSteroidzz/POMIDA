<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FreeDessertReward;
use App\Models\LoyaltyAward;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\FreeDesserts;
use App\Services\StreakRewardSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The free dessert under real concurrency — two processes, two database
 * connections (tests/Support/free-dessert-race-worker.php), because one
 * PHPUnit process cannot block on its own lock.
 *
 *   EARNING  two completions grant the same dessert number at once — both
 *            through grant() directly and through grantDue(), the stamp
 *            count completion actually runs
 *            -> UNIQUE(user_id, type, award_key) on loyalty_awards lets one
 *               through; the other creates no ledger row and no reward.
 *   SPENDING two checkouts spend the same reward CODE at once
 *            -> lockForSpend() takes the row FOR UPDATE; the second waits,
 *               re-checks, finds it used and spends nothing. Control: when
 *               the first rolls back, the waiting one does spend it.
 *
 * NOT DatabaseTransactions, deliberately: the workers only see committed
 * rows. Everything is created here and deleted in tearDown() — the worker's
 * orders (FDRACE-…), the menu item, then the account (its ledger rows and
 * rewards cascade) — with a final assertion that nothing is left. The
 * Streak Rewards settings rows the workers need (a reward must be chosen
 * before anything is granted) are committed too, and tearDown() puts back
 * exactly what was there before.
 */
class FreeDessertRaceTest extends TestCase
{
    private const HOLD_SECONDS = 3;

    /** A call faster than this did not wait on a lock, so nothing raced. */
    private const MIN_WAIT_MS = 300;

    /** Pinned here AND passed to the worker, so both processes judge the same card. */
    private const VISIT_DAYS = 7;
    private const COUNTS_FROM = '2026-09-01';

    private ?int $userId = null;
    private ?int $menuItemId = null;

    /** The Streak Rewards settings rows as they were before this test, restored in tearDown(). */
    private array $settingsBefore = [];

    private const REWARD_KEYS = [
        StreakRewardSettings::DAYS, StreakRewardSettings::VALID_DAYS, StreakRewardSettings::TYPE,
        StreakRewardSettings::MENU_ITEM_ID, StreakRewardSettings::VOUCHER,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName(), 'refusing to run anywhere but the testing database');

        config([
            'loyalty.free_dessert_visit_days'  => self::VISIT_DAYS,
            'loyalty.free_dessert_counts_from' => self::COUNTS_FROM,
            'loyalty.free_dessert_valid_days'  => 30,
        ]);

        $this->userId = User::create([
            'name'              => 'Dessert Race',
            'email'             => 'dessert-race-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ])->id;

        $this->menuItemId = MenuItem::create([
            'category_id'           => Category::value('id'),
            'branch_id'             => 1,
            'name'                  => 'FD Race Cake ' . uniqid(),
            'price'                 => 150,
            'cost'                  => 0,
            'is_available'          => true,
            'display_order'         => 0,
        ])->id;

        // The reward is this cake (the company default; branch 1, where every
        // race runs, has no settings of its own and its switch is on); days
        // and validity stay on config (pinned above). The workers only see
        // committed rows, so these are committed and put back in tearDown().
        $this->settingsBefore = $this->rewardSettingRows()->get()->map(fn ($row) => (array) $row)->all();
        $this->rewardSettingRows()->delete();
        foreach ([StreakRewardSettings::TYPE => StreakRewardSettings::TYPE_MENU_ITEM, StreakRewardSettings::MENU_ITEM_ID => (string) $this->menuItemId] as $key => $value) {
            DB::table('settings')->insert([
                'key' => $key, 'branch_id' => null, 'value' => $value, 'group' => 'loyalty', 'type' => 'text',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** The global reward set plus branch 1's own set and switch: what decides branch 1's reward. */
    private function rewardSettingRows()
    {
        return DB::table('settings')
            ->whereIn('key', array_merge(self::REWARD_KEYS, [StreakRewardSettings::BRANCH_ENABLED]))
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', 1));
    }

    protected function tearDown(): void
    {
        $orderIds = Order::where('order_number', 'like', 'FDRACE-%')->where('user_id', $this->userId)->pluck('id');
        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        Order::whereIn('id', $orderIds)->delete();

        // Back to exactly the settings rows that were there before.
        $this->rewardSettingRows()->delete();
        foreach ($this->settingsBefore as $row) {
            DB::table('settings')->insert($row);
        }

        if ($this->menuItemId) {
            DB::table('menu_items')->where('id', $this->menuItemId)->delete();
        }

        if ($this->userId) {
            User::whereKey($this->userId)->delete();

            $this->assertSame(0, User::whereKey($this->userId)->count(), 'race account was left behind');
            $this->assertSame(0, LoyaltyAward::where('user_id', $this->userId)->count(), 'race ledger rows were left behind');
            $this->assertSame(0, FreeDessertReward::where('user_id', $this->userId)->count(), 'race rewards were left behind');
            $this->assertSame(0, Order::where('user_id', $this->userId)->count(), 'race orders were left behind');
        }

        parent::tearDown();
    }

    private function worker(string ...$args): Process
    {
        $process = new Process(
            array_merge([PHP_BINARY, base_path('tests/Support/free-dessert-race-worker.php')], $args),
            base_path(),
            [
                'DB_DATABASE'    => 'pomida_db_testing',
                'APP_ENV'        => 'testing',
                'FD_VISIT_DAYS'  => (string) self::VISIT_DAYS,
                'FD_COUNTS_FROM' => self::COUNTS_FROM,
            ]
        );
        $process->setTimeout(60);

        return $process;
    }

    private function waitForHold(Process $process): void
    {
        $deadline = microtime(true) + 30;

        while (!str_contains($process->getOutput(), 'HOLDING') && $process->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }

        $this->assertStringContainsString('HOLDING', $process->getOutput(), 'the first process never got its turn: ' . $process->getErrorOutput());
    }

    /** @return array{ok: bool, ms: int} */
    private function resultOf(Process $process): array
    {
        $this->assertSame(1, preg_match('/^RESULT (\{.*\})$/m', $process->getOutput(), $m), 'no result from worker: ' . $process->getOutput() . $process->getErrorOutput());

        return json_decode($m[1], true);
    }

    private function race(string $holdCommand, string $racer, string $arg): array
    {
        $first = $this->worker($holdCommand, (string) $this->userId, $arg, (string) self::HOLD_SECONDS);
        $first->start();
        $this->waitForHold($first);

        $second = $this->worker($racer, (string) $this->userId, $arg);
        $second->run();
        $first->wait();

        return [$this->resultOf($first), $this->resultOf($second)];
    }

    public function test_two_simultaneous_completions_earn_one_dessert(): void
    {
        [$a, $b] = $this->race('grant-hold-commit', 'grant', '1');

        $this->assertTrue($a['ok'], 'the process that wrote first earned nothing');
        $this->assertFalse($b['ok'], 'VIOLATION: the same dessert number was granted twice');
        $this->assertGreaterThan(self::MIN_WAIT_MS, $b['ms'], 'the second grant did not wait on the first, so nothing raced');

        $this->assertSame(1, FreeDessertReward::where('user_id', $this->userId)->count());
        $this->assertSame(1, LoyaltyAward::where('user_id', $this->userId)->where('type', LoyaltyAward::TYPE_FREE_DESSERT)->count());
    }

    public function test_two_simultaneous_completions_counting_stamps_earn_one_dessert(): void
    {
        // Seven committed stamp days (the workers only see committed rows).
        // FDRACE- numbers, so tearDown() deletes them.
        foreach (range(1, self::VISIT_DAYS) as $day) {
            $stamp = Order::create([
                'order_number' => 'FDRACE-STAMP-' . strtoupper(Str::random(6)), 'user_id' => $this->userId, 'branch_id' => 1,
                'type' => 'pick_up', 'status' => 'completed', 'payment_method' => 'cash', 'payment_status' => 'paid',
                'subtotal' => 100, 'total' => 100,
                'completed_at' => sprintf('2026-10-%02d 04:00:00', $day),
            ]);
            // A stamp day is the day the order was PLACED (created_at, not fillable).
            \DB::table('orders')->where('id', $stamp->id)->update(['created_at' => sprintf('2026-10-%02d 04:00:00', $day)]);
        }
        $this->assertSame(self::VISIT_DAYS, FreeDesserts::countedDays(User::find($this->userId), 1));

        [$a, $b] = $this->race('grant-due-hold-commit', 'grant-due', '-');

        $this->assertTrue($a['ok'], 'the first completion earned nothing for a full card');
        $this->assertSame(1, $a['granted']);
        $this->assertFalse($b['ok'], 'VIOLATION: one full card earned two desserts');
        $this->assertGreaterThan(self::MIN_WAIT_MS, $b['ms'], 'the second completion did not wait on the first, so nothing raced');

        $this->assertSame(1, FreeDessertReward::where('user_id', $this->userId)->count());
        $this->assertSame(
            ['visits:1:1'],
            LoyaltyAward::where('user_id', $this->userId)->where('type', LoyaltyAward::TYPE_FREE_DESSERT)->pluck('award_key')->all()
        );
    }

    public function test_two_simultaneous_checkouts_spend_the_reward_once(): void
    {
        $reward = FreeDesserts::grant(User::find($this->userId), 1, 1);

        [$a, $b] = $this->race('spend-hold-commit', 'spend', (string) $reward->id);

        $this->assertTrue($a['ok'], 'the first checkout could not spend an available reward');
        $this->assertFalse($b['ok'], 'VIOLATION: one reward code bought two free items');
        $this->assertGreaterThan(self::MIN_WAIT_MS, $b['ms'], 'the second checkout did not wait on the reward lock, so nothing raced');

        $this->assertSame('used', $reward->fresh()->status);
        $this->assertSame(1, Order::where('user_id', $this->userId)->count());
        $this->assertSame(1, DB::table('order_items')->whereIn('order_id', Order::where('user_id', $this->userId)->pluck('id'))->where('is_free_dessert', true)->count());
    }

    public function test_when_the_first_checkout_rolls_back_the_waiting_one_spends_it(): void
    {
        $reward = FreeDesserts::grant(User::find($this->userId), 1, 1);

        [, $b] = $this->race('spend-hold-rollback', 'spend', (string) $reward->id);

        $this->assertTrue($b['ok'], 'the reward was lost when the other checkout rolled back');
        $this->assertGreaterThan(self::MIN_WAIT_MS, $b['ms'], 'the second checkout did not wait, so nothing raced');

        $this->assertSame('used', $reward->fresh()->status);
        $this->assertSame(1, Order::where('user_id', $this->userId)->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\LoyaltyAward;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Two processes paying the SAME bonus at the same moment — two real database
 * connections (tests/Support/loyalty-award-race-worker.php), because one
 * PHPUnit process cannot block on its own lock.
 *
 * This is the case a "check, then insert" guard gets wrong: both sides read
 * "not paid yet" and both pay. App\Services\Loyalty::award() has no such
 * read; the ledger's UNIQUE(user_id, type, award_key) decides.
 *
 *   first commits    -> the second waits on the key, then hits the duplicate
 *                       and pays nothing. One row, points credited once.
 *   first rolls back -> the second waits, then succeeds. Still one row, once.
 *
 * NOT DatabaseTransactions, deliberately: the workers can only see committed
 * rows. The one account used is created here and deleted in tearDown() by id
 * (its ledger rows cascade), with a final assertion that nothing is left.
 */
class LoyaltyAwardRaceTest extends TestCase
{
    private const HOLD_SECONDS = 3;

    /** A call faster than this did not wait on a lock, so nothing raced. */
    private const MIN_WAIT_MS = 300;

    private ?int $userId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName(), 'refusing to run anywhere but the testing database');

        $this->userId = User::create([
            'name'              => 'Loyalty Race',
            'email'             => 'loyalty-race-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ])->id;
    }

    protected function tearDown(): void
    {
        if ($this->userId) {
            User::whereKey($this->userId)->delete();

            $this->assertSame(0, User::whereKey($this->userId)->count(), 'race account was left behind');
            $this->assertSame(0, LoyaltyAward::where('user_id', $this->userId)->count(), 'race ledger rows were left behind');
        }

        parent::tearDown();
    }

    private function worker(string ...$args): Process
    {
        $process = new Process(
            array_merge([PHP_BINARY, base_path('tests/Support/loyalty-award-race-worker.php')], $args),
            base_path(),
            ['DB_DATABASE' => 'pomida_db_testing', 'APP_ENV' => 'testing']
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

        $this->assertStringContainsString('HOLDING', $process->getOutput(), 'the first process never wrote its row: ' . $process->getErrorOutput());
    }

    /** @return array{ok: bool, ms: int} */
    private function resultOf(Process $process): array
    {
        $this->assertSame(1, preg_match('/^RESULT (\{.*\})$/m', $process->getOutput(), $m), 'no result from worker: ' . $process->getOutput() . $process->getErrorOutput());

        return json_decode($m[1], true);
    }

    private function bonus(): int
    {
        return (int) DB::table('users')->where('id', $this->userId)->value('bonus_points');
    }

    public function test_two_processes_paying_the_same_bonus_pay_it_once(): void
    {
        $key = '3:2026-10-13';

        $first = $this->worker('hold-commit', (string) $this->userId, $key, (string) self::HOLD_SECONDS);
        $first->start();
        $this->waitForHold($first);

        $second = $this->worker('award', (string) $this->userId, $key);
        $second->run();
        $first->wait();

        $a = $this->resultOf($first);
        $b = $this->resultOf($second);

        $this->assertTrue($a['ok'], 'the process that wrote first did not pay');
        $this->assertFalse($b['ok'], 'VIOLATION: the same bonus was paid twice');
        $this->assertGreaterThan(self::MIN_WAIT_MS, $b['ms'], 'the second award did not wait on the first, so nothing raced');

        $this->assertSame(1, LoyaltyAward::where('user_id', $this->userId)->where('award_key', $key)->count());
        $this->assertSame(10, $this->bonus(), 'points were credited more than once');
    }

    public function test_when_the_first_payment_rolls_back_the_waiting_one_pays_exactly_once(): void
    {
        $key = '7:2026-10-09';

        $first = $this->worker('hold-rollback', (string) $this->userId, $key, (string) self::HOLD_SECONDS);
        $first->start();
        $this->waitForHold($first);

        $second = $this->worker('award', (string) $this->userId, $key);
        $second->run();
        $first->wait();

        $this->assertTrue($this->resultOf($second)['ok'], 'the bonus was lost when the other attempt rolled back');
        $this->assertGreaterThan(self::MIN_WAIT_MS, $this->resultOf($second)['ms'], 'the second award did not wait, so nothing raced');

        $this->assertSame(1, LoyaltyAward::where('user_id', $this->userId)->where('award_key', $key)->count());
        $this->assertSame(10, $this->bonus());
    }
}

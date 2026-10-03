<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A customer seating themselves at a table, racing a manager deleting it — two
 * real processes on two real database connections (tests/Support/
 * table-delete-race-worker.php), because one PHPUnit process cannot hold a
 * row lock against itself.
 *
 * Whichever transaction takes the registry row's lock first wins, and the
 * other is refused cleanly:
 *
 *   claim first  -> the delete waits for the lock, then sees the live session
 *                   and refuses (409): the table stays, the customer stays.
 *   delete first -> the claim waits for the lock, then finds no row and is
 *                   refused (ERR_TABLE_NOT_FOUND): no session at a deleted table.
 *
 * The forbidden outcome — a live session at a table that no longer exists — is
 * asserted against in both orders.
 *
 * NOT DatabaseTransactions, deliberately: the worker processes can only see
 * committed rows. Instead every row this class creates is created here, under a
 * branch created here, and deleted in tearDown() by those ids alone (the
 * bounded-cleanup pattern), with a final assertion that nothing is left.
 */
class TableDeleteRaceTest extends TestCase
{
    /** How long the first process holds its locks. Long enough to outlast the second process's boot. */
    private const HOLD_SECONDS = 3;

    /** A call that returned faster than this did not wait on a lock, so it was not a race. */
    private const MIN_WAIT_MS = 300;

    /** @var array<int, int> */
    private array $branchIds = [];

    private string $auditLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'pomida_db_testing',
            DB::connection()->getDatabaseName(),
            'refusing to run anywhere but the testing database'
        );

        $this->auditLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'table-delete-race-' . Str::random(10) . '.log';
    }

    protected function tearDown(): void
    {
        try {
            if ($this->branchIds) {
                $sessions = TableSession::whereIn('branch_id', $this->branchIds)->pluck('id');
                DB::table('table_session_devices')->whereIn('table_session_id', $sessions)->delete();
                TableSession::whereIn('id', $sessions)->delete();
                RestaurantTable::whereIn('branch_id', $this->branchIds)->delete();
                Branch::whereIn('id', $this->branchIds)->delete();

                $this->assertSame(0, Branch::whereIn('id', $this->branchIds)->count(), 'race fixtures were left behind');
                $this->assertSame(0, TableSession::whereIn('branch_id', $this->branchIds)->count(), 'race sessions were left behind');
            }
        } finally {
            if (is_file($this->auditLog)) {
                unlink($this->auditLog);
            }

            parent::tearDown();
        }
    }

    /** A committed branch and one committed, never-used table — what a typo looks like. */
    private function fixture(): RestaurantTable
    {
        $branch = Branch::create([
            'name'           => 'Delete Race ' . Str::random(6),
            'code'           => 'DR' . strtoupper(Str::random(6)),
            'address'        => 'Created by TableDeleteRaceTest',
            'is_active'      => true,
            'is_main_branch' => false,
        ]);
        $this->branchIds[] = $branch->id;

        return TableEntry::findOrRegister($branch->id, (string) random_int(1000000, 9999999));
    }

    private function worker(string ...$args): Process
    {
        $process = new Process(
            array_merge([PHP_BINARY, base_path('tests/Support/table-delete-race-worker.php')], $args),
            base_path(),
            ['DB_DATABASE' => 'pomida_db_testing', 'APP_ENV' => 'testing', 'RACE_AUDIT_LOG' => $this->auditLog]
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

        $this->assertStringContainsString('HOLDING', $process->getOutput(), 'the first process never took its locks: ' . $process->getErrorOutput());
    }

    /** @return array{ok: bool, status: ?int, error: ?string, ms: int} */
    private function resultOf(Process $process): array
    {
        $this->assertSame(1, preg_match('/^RESULT (\{.*\})$/m', $process->getOutput(), $m), 'no result from worker: ' . $process->getOutput() . $process->getErrorOutput());

        return json_decode($m[1], true);
    }

    private function liveSessionAt(RestaurantTable $table): ?TableSession
    {
        return TableOccupancy::activeFor($table->branch_id, $table->table_number);
    }

    public function test_claim_wins_then_the_delete_waits_and_is_refused(): void
    {
        $table = $this->fixture();

        $claim = $this->worker('hold-claim', (string) $table->branch_id, $table->table_number, (string) $table->id, (string) self::HOLD_SECONDS);
        $claim->start();
        $this->waitForHold($claim);

        $delete = $this->worker('delete', (string) $table->id, (string) $table->branch_id);
        $delete->run();
        $claim->wait();

        $claimed = $this->resultOf($claim);
        $deleted = $this->resultOf($delete);

        $this->assertTrue($claimed['ok'], 'the claim that held the lock was refused');
        $this->assertFalse($deleted['ok'], 'VIOLATION: the delete went through after the customer was seated');
        $this->assertSame(409, $deleted['status']);
        $this->assertSame("Table {$table->table_number} is occupied. Move or clear it first.", $deleted['error']);
        $this->assertGreaterThan(self::MIN_WAIT_MS, $deleted['ms'], 'the delete did not wait on the lock, so nothing raced');

        $this->assertNotNull(RestaurantTable::find($table->id), 'the table was deleted under a seated customer');
        $this->assertNotNull($this->liveSessionAt($table), 'the customer lost their seat');
        $this->assertFileDoesNotExist($this->auditLog, 'a refused delete was audited');
    }

    public function test_delete_wins_then_the_claim_waits_and_sees_not_found(): void
    {
        $table = $this->fixture();

        $delete = $this->worker('hold-delete', (string) $table->id, (string) $table->branch_id, (string) self::HOLD_SECONDS);
        $delete->start();
        $this->waitForHold($delete);

        $claim = $this->worker('claim', (string) $table->branch_id, $table->table_number, (string) $table->id);
        $claim->run();
        $delete->wait();

        $deleted = $this->resultOf($delete);
        $claimed = $this->resultOf($claim);

        $this->assertTrue($deleted['ok'], 'the delete that held the lock was refused');
        $this->assertFalse($claimed['ok'], 'VIOLATION: a customer was seated at a deleted table');
        $this->assertSame(TableEntry::ERR_TABLE_NOT_FOUND, $claimed['error']);
        $this->assertGreaterThan(self::MIN_WAIT_MS, $claimed['ms'], 'the claim did not wait on the lock, so nothing raced');

        $this->assertNull(RestaurantTable::find($table->id), 'the table is still there');
        $this->assertNull($this->liveSessionAt($table), 'a session was opened at a deleted table');
        $this->assertSame(0, TableSession::where('branch_id', $table->branch_id)->where('table_number', $table->table_number)->count());

        // The one audit line, from the process that deleted.
        $this->assertFileExists($this->auditLog);
        $lines = file($this->auditLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('"action":"table_deleted"', $lines[0]);
    }
}

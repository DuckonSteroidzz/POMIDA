<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Migration 2026_10_22_000000_add_missing_foreign_keys_for_branches_and_vouchers.
 *
 * The schema it leaves behind (read from information_schema), and its orphan
 * handling: users / free_dessert_rewards orphans make it refuse before any
 * change, orders orphans lose only their dead voucher_id.
 *
 * The suite never runs real DDL: the testing database is already migrated, so
 * up() finds every foreign key in place and only its orphan checks run, and a
 * beforeExecuting tripwire fails the test if any ALTER/DROP/CREATE reaches the
 * database. Orphans are planted with FOREIGN_KEY_CHECKS=0 (a session variable,
 * so DatabaseTransactions still rolls the rows back). The real up()/down()
 * round trip was proved by hand on pomida_db_testing (70 -> 67 -> 70 FKs).
 */
class MissingForeignKeysMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private const MIGRATION = '2026_10_22_000000_add_missing_foreign_keys_for_branches_and_vouchers.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/' . self::MIGRATION);
    }

    private function ddlTripwire(): void
    {
        DB::connection()->beforeExecuting(function (string $query) {
            if (preg_match('/^\s*(alter|drop|create|rename|truncate)\b/i', $query)) {
                throw new \LogicException('DDL reached the database: ' . $query);
            }
        });
    }

    private function missingBranchId(): int
    {
        return (int) Branch::max('id') + 1000000;
    }

    private function missingVoucherId(): int
    {
        return (int) Voucher::max('id') + 1000000;
    }

    /** Runs $insert with foreign key checks off, so an orphan can exist at all. */
    private function plant(callable $insert): mixed
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            return $insert();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function orphanUser(): User
    {
        $branchId = $this->missingBranchId();

        return $this->plant(fn () => User::create([
            'name' => 'FK orphan staff', 'email' => 'fk-orphan-' . uniqid() . '@example.test',
            'password' => 'Secret-123!', 'role' => 'staff', 'branch_id' => $branchId,
        ]));
    }

    private function orphanReward(): int
    {
        $branchId = $this->missingBranchId();

        return $this->plant(fn () => DB::table('free_dessert_rewards')->insertGetId([
            'user_id' => (int) User::max('id'), 'loyalty_award_id' => 2000000000 + random_int(1, 99999999),
            'code' => 'RWD' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), 'branch_id' => $branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    private function voucherOrder(?int $voucherId): Order
    {
        return $this->plant(fn () => Order::create([
            'order_number' => 'FKM-' . strtoupper(substr(uniqid(), -8)), 'branch_id' => 1, 'type' => 'pick_up',
            'status' => 'completed', 'subtotal' => 200, 'discount_amount' => 20, 'tax_amount' => 0, 'total' => 180,
            'discount_type' => 'voucher', 'voucher_id' => $voucherId,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]));
    }

    private function rule(string $constraint): ?object
    {
        return DB::selectOne(
            'SELECT REFERENCED_TABLE_NAME, DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
            [$constraint]
        );
    }

    // ── the schema ───────────────────────────────────────────────────────────

    public function test_each_foreign_key_exists_with_its_on_delete_rule_and_an_index(): void
    {
        $expected = [
            ['users', 'branch_id', 'users_branch_id_foreign', 'branches', 'RESTRICT', 'users_branch_id_index'],
            ['orders', 'voucher_id', 'orders_voucher_id_foreign', 'vouchers', 'SET NULL', 'orders_voucher_id_index'],
            ['free_dessert_rewards', 'branch_id', 'free_dessert_rewards_branch_id_foreign', 'branches', 'RESTRICT', 'free_dessert_rewards_branch_id_index'],
        ];

        foreach ($expected as [$table, $column, $constraint, $parent, $onDelete, $index]) {
            $rule = $this->rule($constraint);
            $this->assertNotNull($rule, $constraint . ' exists');
            $this->assertSame($parent, $rule->REFERENCED_TABLE_NAME, $constraint);
            $this->assertSame($onDelete, $rule->DELETE_RULE, $constraint);
            $this->assertSame('RESTRICT', $rule->UPDATE_RULE, $constraint . ' matches every other FK in the schema');

            $indexes = collect(DB::select(
                'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            ))->pluck('INDEX_NAME')->all();
            $this->assertSame([$index], $indexes, $table . '.' . $column . ' has exactly the one index the migration added');
        }
    }

    public function test_the_database_now_refuses_new_orphans(): void
    {
        $attempts = [
            'users' => fn () => User::create([
                'name' => 'FK new orphan', 'email' => 'fk-new-' . uniqid() . '@example.test',
                'password' => 'Secret-123!', 'role' => 'staff', 'branch_id' => $this->missingBranchId(),
            ]),
            'orders' => fn () => Order::create([
                'order_number' => 'FKN-' . strtoupper(substr(uniqid(), -8)), 'branch_id' => 1, 'type' => 'pick_up',
                'status' => 'pending', 'subtotal' => 10, 'discount_amount' => 0, 'tax_amount' => 0, 'total' => 10,
                'voucher_id' => $this->missingVoucherId(), 'payment_method' => 'cash', 'payment_status' => 'pending',
            ]),
            'free_dessert_rewards' => fn () => DB::table('free_dessert_rewards')
                ->where('id', $this->plant(fn () => DB::table('free_dessert_rewards')->insertGetId([
                    'user_id' => (int) User::max('id'), 'loyalty_award_id' => 2000000000 + random_int(1, 99999999),
                    'code' => 'RWD' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), 'branch_id' => null,
                ])))
                ->update(['branch_id' => $this->missingBranchId()]),
        ];

        foreach ($attempts as $table => $attempt) {
            try {
                $attempt();
                $this->fail($table . ': a row pointing at a missing parent was accepted');
            } catch (QueryException $e) {
                $this->assertSame(1452, (int) ($e->errorInfo[1] ?? 0), $table . ': refused by the foreign key');
            }
        }
    }

    // ── orphan handling ──────────────────────────────────────────────────────

    public function test_up_on_a_clean_migrated_database_passes_and_runs_no_ddl(): void
    {
        $migration = $this->migration();
        $this->ddlTripwire();

        $migration->up();

        $this->assertSame('RESTRICT', $this->rule('users_branch_id_foreign')->DELETE_RULE);
    }

    public function test_up_refuses_and_lists_ids_when_a_user_points_at_a_missing_branch(): void
    {
        $user = $this->orphanUser();
        $order = $this->voucherOrder($this->missingVoucherId());
        $migration = $this->migration();
        $this->ddlTripwire();

        try {
            $migration->up();
            $this->fail('up() must refuse while a user points at a missing branch');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to add the missing foreign keys', $e->getMessage());
            $this->assertStringContainsString('1 user(s) point at a branch that does not exist', $e->getMessage());
            $this->assertStringContainsString('user ' . $user->id . ' (staff, branch_id ' . $user->branch_id . ')', $e->getMessage());
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }

        $this->assertSame($user->branch_id, (int) DB::table('users')->where('id', $user->id)->value('branch_id'), 'the user is not guessed at');
        $this->assertNotNull(DB::table('orders')->where('id', $order->id)->value('voucher_id'), 'the refusal comes before the orders clean-up');
    }

    public function test_up_refuses_and_lists_ids_when_a_reward_points_at_a_missing_branch(): void
    {
        $rewardId = $this->orphanReward();
        $branchId = (int) DB::table('free_dessert_rewards')->where('id', $rewardId)->value('branch_id');
        $migration = $this->migration();
        $this->ddlTripwire();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/1 free_dessert_rewards row\(s\) point at a branch that does not exist: reward ' . $rewardId . ' \(user \d+, branch_id ' . $branchId . '\)/');

        try {
            $migration->up();
        } finally {
            $this->assertSame($branchId, (int) DB::table('free_dessert_rewards')->where('id', $rewardId)->value('branch_id'), 'not set to NULL (that would open it to every branch)');
        }
    }

    public function test_up_sets_a_missing_voucher_to_null_and_keeps_the_orders_discount(): void
    {
        Log::spy();
        $missing = $this->missingVoucherId();
        $orphan = $this->voucherOrder($missing);
        $voucher = Voucher::create([
            'code' => 'FKKEEP' . random_int(1000, 9999), 'discount_type' => 'fixed', 'discount_value' => 20,
            'max_uses' => 0, 'used_count' => 0, 'minimum_order' => 0, 'is_active' => true, 'points_required' => 0,
        ]);
        $healthy = $this->voucherOrder($voucher->id);
        $migration = $this->migration();
        $this->ddlTripwire();

        $migration->up();

        $row = DB::table('orders')->where('id', $orphan->id)->first();
        $this->assertNull($row->voucher_id);
        $this->assertSame('voucher', $row->discount_type);
        $this->assertSame(['200.00', '20.00', '180.00'], [$row->subtotal, $row->discount_amount, $row->total]);
        $this->assertSame('Voucher', Order::find($orphan->id)->discountLabel(), 'the receipt still says what discount it was');

        $this->assertSame($voucher->id, (int) DB::table('orders')->where('id', $healthy->id)->value('voucher_id'), 'a real link is untouched');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($msg, $ctx) => str_contains($msg, 'orders.voucher_id')
            && $ctx['orders'] === [['order_id' => $orphan->id, 'old_voucher_id' => $missing]]);
    }
}

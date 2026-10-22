<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `php artisan pomida:check-orphans` — the leader runs it on the LIVE database
 * before the foreign-key migration, so the one property that matters most is
 * that it never writes. Every statement it sends is recorded and must be a
 * SELECT or SHOW; the rows it reports are unchanged afterwards.
 *
 * It must also report exactly what the migration acts on — the last test
 * plants the same orphans for both and compares the ids.
 */
class CheckOrphansCommandTest extends TestCase
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
        $branchId = (int) Branch::max('id') + 1000000;

        return $this->plant(fn () => User::create([
            'name' => 'Orphan check staff', 'email' => 'orphan-check-' . uniqid() . '@example.test',
            'password' => 'Secret-123!', 'role' => 'staff', 'branch_id' => $branchId,
        ]));
    }

    private function orphanReward(): int
    {
        $branchId = (int) Branch::max('id') + 1000000;

        return $this->plant(fn () => DB::table('free_dessert_rewards')->insertGetId([
            'user_id' => (int) User::max('id'), 'loyalty_award_id' => 2000000000 + random_int(1, 99999999),
            'code' => 'RWD' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), 'branch_id' => $branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    private function orphanOrder(): Order
    {
        $voucherId = (int) Voucher::max('id') + 1000000;

        return $this->plant(fn () => Order::create([
            'order_number' => 'CHK-' . strtoupper(substr(uniqid(), -8)), 'branch_id' => 1, 'type' => 'pick_up',
            'status' => 'completed', 'subtotal' => 100, 'discount_amount' => 10, 'tax_amount' => 0, 'total' => 90,
            'discount_type' => 'voucher', 'voucher_id' => $voucherId,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]));
    }

    /** @return array{0:int,1:string,2:list<string>} exit code, output, every SQL statement sent */
    private function runCheck(): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $exit = Artisan::call('pomida:check-orphans');

        return [$exit, Artisan::output(), $queries];
    }

    public function test_a_clean_database_is_ready_and_shows_each_foreign_key(): void
    {
        [$exit, $out] = $this->runCheck();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('database "pomida_db_testing"', $out);
        $this->assertStringContainsString('users.branch_id -> branches: 0 orphan(s); foreign key present (ON DELETE RESTRICT)', $out);
        $this->assertStringContainsString('orders.voucher_id -> vouchers: 0 orphan(s); foreign key present (ON DELETE SET NULL)', $out);
        $this->assertStringContainsString('free_dessert_rewards.branch_id -> branches: 0 orphan(s); foreign key present (ON DELETE RESTRICT)', $out);
        $this->assertStringContainsString('READY: no orphans', $out);
    }

    public function test_user_and_reward_orphans_are_listed_and_block(): void
    {
        $user = $this->orphanUser();
        $rewardId = $this->orphanReward();
        $order = $this->orphanOrder();

        [$exit, $out] = $this->runCheck();

        $this->assertSame(1, $exit, 'non-zero so a script can stop before migrating');
        $this->assertStringContainsString('users.branch_id -> branches: 1 orphan(s)', $out);
        $this->assertStringContainsString('- id ' . $user->id . ', branch_id ' . $user->branch_id . ', role staff', $out);
        $this->assertStringContainsString('free_dessert_rewards.branch_id -> branches: 1 orphan(s)', $out);
        $this->assertStringContainsString('- id ' . $rewardId . ', branch_id ', $out);
        $this->assertStringContainsString('orders.voucher_id -> vouchers: 1 orphan(s)', $out);
        $this->assertStringContainsString('- id ' . $order->id . ', voucher_id ' . $order->voucher_id . ', order_number ' . $order->order_number, $out);
        $this->assertStringContainsString('NOT READY: 2 user/reward row(s)', $out);
        $this->assertStringNotContainsString('RWD', $out, 'a reward code is a bearer code and is never printed');
    }

    public function test_order_orphans_alone_are_ready_and_say_they_will_be_set_to_null(): void
    {
        $this->orphanOrder();

        [$exit, $out] = $this->runCheck();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('READY: the migration will set voucher_id to NULL on the 1 order(s) above', $out);
    }

    public function test_it_is_read_only(): void
    {
        $user = $this->orphanUser();
        $rewardId = $this->orphanReward();
        $order = $this->orphanOrder();
        $before = [
            DB::table('users')->count(), DB::table('orders')->count(), DB::table('free_dessert_rewards')->count(),
            DB::table('users')->where('id', $user->id)->value('branch_id'),
            DB::table('orders')->where('id', $order->id)->value('voucher_id'),
            DB::table('free_dessert_rewards')->where('id', $rewardId)->value('branch_id'),
        ];

        [, , $queries] = $this->runCheck();

        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|show)\b/i', $sql, 'only reads reach the database');
        }
        $this->assertSame($before, [
            DB::table('users')->count(), DB::table('orders')->count(), DB::table('free_dessert_rewards')->count(),
            DB::table('users')->where('id', $user->id)->value('branch_id'),
            DB::table('orders')->where('id', $order->id)->value('voucher_id'),
            DB::table('free_dessert_rewards')->where('id', $rewardId)->value('branch_id'),
        ]);

        // The write guard is released once the command has finished.
        DB::table('orders')->where('id', $order->id)->update(['notes' => 'after the check']);
        $this->assertSame('after the check', DB::table('orders')->where('id', $order->id)->value('notes'));
    }

    public function test_it_reports_the_same_ids_the_migration_refuses_on(): void
    {
        $user = $this->orphanUser();
        $rewardId = $this->orphanReward();

        [, $out] = $this->runCheck();
        $this->assertStringContainsString('- id ' . $user->id . ',', $out);
        $this->assertStringContainsString('- id ' . $rewardId . ',', $out);

        try {
            (require database_path('migrations/' . self::MIGRATION))->refuseUnresolvableOrphans();
            $this->fail('the migration must refuse the same rows');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('user ' . $user->id . ' (', $e->getMessage());
            $this->assertStringContainsString('reward ' . $rewardId . ' (', $e->getMessage());
        }
    }
}

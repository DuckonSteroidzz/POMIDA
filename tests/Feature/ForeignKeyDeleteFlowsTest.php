<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The delete and close flows that the 2026-10-22 foreign keys touch, run end
 * to end through the admin routes:
 *
 *   - deleting a voucher still works — including one an order used; the
 *     order keeps its discount and loses only the link (SET NULL);
 *   - closing a branch (the app's only way to retire one) still works with
 *     staff assigned, and leaves their branch_id alone;
 *   - a hard DELETE of a branch that still has staff or a reward is refused
 *     by the database (RESTRICT), while an unused branch can still be deleted.
 */
class ForeignKeyDeleteFlowsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function voucher(): Voucher
    {
        return Voucher::create([
            'code' => 'FKDEL' . random_int(10000, 99999), 'description' => 'FK delete flow',
            'discount_type' => 'fixed', 'discount_value' => 15, 'max_uses' => 0, 'used_count' => 1,
            'minimum_order' => 0, 'is_active' => true, 'points_required' => 0,
        ]);
    }

    private function branch(): Branch
    {
        return Branch::create([
            'name' => 'FK flow branch ' . uniqid(), 'code' => 'FK' . random_int(10000, 99999),
            'address' => 'Test address', 'is_active' => true, 'is_main_branch' => false,
        ]);
    }

    private function staffAt(Branch $branch): User
    {
        return User::create([
            'name' => 'FK flow staff', 'email' => 'fk-flow-' . uniqid() . '@example.test',
            'password' => 'Secret-123!', 'role' => 'staff', 'branch_id' => $branch->id,
        ]);
    }

    public function test_deleting_a_voucher_an_order_used_still_works_and_the_order_keeps_its_discount(): void
    {
        $voucher = $this->voucher();
        $order = Order::create([
            'order_number' => 'FKD-' . strtoupper(substr(uniqid(), -8)), 'branch_id' => 1, 'type' => 'pick_up',
            'status' => 'completed', 'subtotal' => 150, 'discount_amount' => 15, 'tax_amount' => 0, 'total' => 135,
            'discount_type' => 'voucher', 'voucher_id' => $voucher->id,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->delete('/admin/vouchers/' . $voucher->id)
            ->assertRedirect(route('admin.vouchers'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'deleted'));

        $this->assertNull(Voucher::find($voucher->id));

        $order->refresh();
        $this->assertNull($order->voucher_id, 'the link is cleared, not left dangling');
        $this->assertSame('voucher', $order->discount_type);
        $this->assertSame(['150.00', '15.00', '135.00'], [$order->subtotal, $order->discount_amount, $order->total]);
        $this->assertSame('Voucher', $order->discountLabel());
    }

    public function test_deleting_an_unused_voucher_still_works(): void
    {
        $voucher = $this->voucher();

        $this->actingAs($this->admin(), 'admin')
            ->delete('/admin/vouchers/' . $voucher->id)
            ->assertSessionHasNoErrors();

        $this->assertNull(Voucher::find($voucher->id));
    }

    public function test_closing_and_reopening_a_branch_with_staff_still_works(): void
    {
        $branch = $this->branch();
        $staff = $this->staffAt($branch);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->put('/admin/branches/' . $branch->id . '/toggle')
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Closed'));
        $this->assertFalse((bool) $branch->fresh()->is_active);

        $this->actingAs($admin, 'admin')->put('/admin/branches/' . $branch->id . '/toggle')
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Open'));
        $this->assertTrue((bool) $branch->fresh()->is_active);

        $this->assertSame($branch->id, (int) $staff->fresh()->branch_id, 'closing a branch never touches its staff');
    }

    public function test_hard_deleting_a_branch_with_assigned_staff_is_refused(): void
    {
        $branch = $this->branch();
        $staff = $this->staffAt($branch);

        try {
            DB::table('branches')->where('id', $branch->id)->delete();
            $this->fail('a branch with staff must not be deletable');
        } catch (QueryException $e) {
            $this->assertSame(1451, (int) ($e->errorInfo[1] ?? 0));
            $this->assertStringContainsString('users_branch_id_foreign', $e->getMessage());
        }

        $this->assertNotNull(Branch::find($branch->id));
        $this->assertSame($branch->id, (int) $staff->fresh()->branch_id);
    }

    public function test_hard_deleting_a_branch_that_issued_a_reward_is_refused(): void
    {
        $branch = $this->branch();

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $rewardId = DB::table('free_dessert_rewards')->insertGetId([
                'user_id' => (int) User::max('id'), 'loyalty_award_id' => 2000000000 + random_int(1, 99999999),
                'code' => 'RWD' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), 'branch_id' => $branch->id,
            ]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        try {
            DB::table('branches')->where('id', $branch->id)->delete();
            $this->fail('a branch that issued a reward must not be deletable');
        } catch (QueryException $e) {
            $this->assertSame(1451, (int) ($e->errorInfo[1] ?? 0));
            $this->assertStringContainsString('free_dessert_rewards_branch_id_foreign', $e->getMessage());
        }

        $this->assertSame($branch->id, (int) DB::table('free_dessert_rewards')->where('id', $rewardId)->value('branch_id'), 'never widened to NULL');
    }

    public function test_an_unused_branch_can_still_be_hard_deleted(): void
    {
        $branch = $this->branch();

        DB::table('branches')->where('id', $branch->id)->delete();

        $this->assertNull(Branch::find($branch->id));
    }
}

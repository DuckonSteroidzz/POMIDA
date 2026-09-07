<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * AUDIT (Sept 2026 readiness pass) — staff order endpoints ignored branch scope.
 * FIXED. These tests are kept as the record of the hole, and now assert that it
 * is closed.
 *
 * WHAT THIS ORIGINALLY PROVED
 * ---------------------------
 * The admin area has one documented rule for "which branch am I looking at?" —
 * ResolvesBranchScope::getSelectedBranch(), whose docblock states:
 *
 *     "Staff -> locked to their own branch. They never get a picker, so a
 *      Branch 1 staff member can never be handed a Branch 2 scope."
 *
 * That held for the LIST screens (Completed Orders, Inventory, Summary), which
 * consult it. It did NOT hold for the per-order endpoints. Every one of these
 * is `role:admin,staff` and resolved its order with a bare findOrFail($id):
 *
 *     AdminController::showOrderDetail()  GET  admin/order-detail/{id}
 *     AdminController::showReceipt()      GET  admin/receipt/{id}
 *     AdminController::completeOrder()    PUT  admin/orders/{id}/complete
 *     AdminController::cancelOrder()      PUT  admin/orders/{id}/cancel
 *
 * So the branch lock was a FILTER ON A LIST, not an authorisation boundary: a
 * staff member who typed another branch's order id into the URL read and
 * mutated it. Completing one also deducted THAT branch's stock.
 *
 * WHAT CHANGED
 * ------------
 * Every per-order endpoint now resolves its record through
 * App\Services\AdminOrderAccess — one rule, written once — which adds
 * `where('branch_id', <the staff member's branch>)` for staff and leaves admins
 * exactly as they were. A refusal is the same 404 a nonexistent order gets, so
 * it says nothing about whether the order exists in another branch.
 *
 * EACH TEST BELOW NAMES WHAT IT ASSERTED BEFORE AND WHAT IT ASSERTS NOW.
 * The wider coverage — every endpoint, the untouched status, the untouched
 * inventory row, the same-branch controls and the admin controls — lives in
 * tests/Feature/StaffBranchScopeOnOrderEndpointsTest.php.
 *
 * These tests create their own branch, staff account and order — all prefixed
 * AUDIT-BRANCHSCOPE — and run inside DatabaseTransactions so nothing is left
 * behind in pomida_db.
 */
class AuditStaffBranchScopeTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'AUDIT-BRANCHSCOPE';

    /** A staff account locked to $branchId. */
    private function staffAt(int $branchId): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Staff',
            'email'     => strtolower(self::PREFIX) . '-staff-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => 'staff',
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
    }

    /** A second branch, distinct from branch 1, with one order sitting in it. */
    private function foreignBranch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Far Branch ' . uniqid(),
            'code'      => 'AUD' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function orderIn(Branch $branch, string $status = 'pending'): Order
    {
        $order = Order::create([
            'order_number'   => 'AUD-' . strtoupper(substr(uniqid(), -8)),
            'branch_id'      => $branch->id,
            'type'           => 'pick_up',
            'status'         => $status,
            'subtotal'       => 250.00,
            'discount_amount' => 0,
            'total'          => 250.00,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            // order_items.menu_item_id is NOT NULL in this schema, so a line
            // must point at a real menu item even when the test only cares
            // about the snapshot name it renders.
            'menu_item_id' => \App\Models\MenuItem::query()->value('id'),
            'item_name'    => self::PREFIX . ' Secret Cake',
            'item_price'   => 250.00,
            'quantity'     => 1,
            'subtotal'     => 250.00,
        ]);

        return $order;
    }

    // ══════════ READ across branches ══════════

    /**
     * BEFORE: asserted that GET admin/order-detail/{id} threw
     *         "Call to undefined relationship [user] on model [App\Models\Order]"
     *         — i.e. branch-1 staff reached another branch's order id, and the
     *         endpoint then blew up on its own broken eager-load.
     *
     * NOW:    asserts branch-1 staff get a 404. The branch check runs when the
     *         record is resolved, so the request is refused before the broken
     *         relation is ever loaded.
     *
     * SEPARATE FINDING, STILL OPEN AND DELIBERATELY NOT FIXED IN THIS PASS.
     * The route remains dead code for a staff member's OWN branch:
     *
     *   - showOrderDetail() eager-loads the relation 'user', but
     *     App\Models\Order defines 'customer' (Order.php:175) and no 'user'.
     *   - It returns view('admin.order-detail'), and
     *     resources/views/admin/order-detail.blade.php does not exist either.
     *
     * Nothing in resources/views links to the route, so no user reaches it by
     * clicking. That is a separate bug from branch scope and is left alone here
     * rather than folded into an authorisation fix; hence there is no
     * same-branch control for this endpoint (a same-branch request still 500s).
     */
    public function test_staff_cannot_reach_another_branchs_order_detail(): void
    {
        $far   = $this->foreignBranch();
        $order = $this->orderIn($far);
        $staff = $this->staffAt(1);

        $this->actingAs($staff, 'admin')
            ->get('/admin/order-detail/' . $order->id)
            ->assertNotFound();
    }

    /**
     * BEFORE: asserted 200 and assertSee('AUDIT-BRANCHSCOPE Secret Cake') —
     *         branch-1 staff read another branch's receipt in full.
     *
     * NOW:    asserts 404, and that the basket line is nowhere in the body.
     */
    public function test_staff_cannot_open_another_branchs_receipt(): void
    {
        $far   = $this->foreignBranch();
        $order = $this->orderIn($far, 'completed');
        $staff = $this->staffAt(1);

        $response = $this->actingAs($staff, 'admin')
            ->get('/admin/receipt/' . $order->id);

        $response->assertNotFound();
        $response->assertDontSee(self::PREFIX . ' Secret Cake');
    }

    // ══════════ WRITE across branches — the severe half ══════════

    /**
     * BEFORE: asserted the order's status was 'completed' afterwards — branch-1
     *         staff completed a foreign order, which also deducted that
     *         branch's inventory.
     *
     * NOW:    asserts the request 404s and the order is still 'pending'.
     *         The inventory half of this is proved against a real inventory row
     *         in StaffBranchScopeOnOrderEndpointsTest.
     */
    public function test_staff_cannot_complete_another_branchs_order(): void
    {
        $far   = $this->foreignBranch();
        $order = $this->orderIn($far, 'pending');
        $staff = $this->staffAt(1);

        $this->actingAs($staff, 'admin')
            ->put('/admin/orders/' . $order->id . '/complete')
            ->assertNotFound();

        $this->assertSame(
            'pending',
            $order->fresh()->status,
            'branch-1 staff must not be able to complete a branch-' . $far->id . ' order.'
        );
    }

    /**
     * BEFORE: asserted the order's status was 'cancelled' afterwards.
     * NOW:    asserts the request 404s and the order is still 'pending'.
     */
    public function test_staff_cannot_cancel_another_branchs_order(): void
    {
        $far   = $this->foreignBranch();
        $order = $this->orderIn($far, 'pending');
        $staff = $this->staffAt(1);

        $this->actingAs($staff, 'admin')
            ->put('/admin/orders/' . $order->id . '/cancel', [
                'cancellation_reason' => self::PREFIX . ' cross-branch cancel',
            ])
            ->assertNotFound();

        $this->assertSame(
            'pending',
            $order->fresh()->status,
            'branch-1 staff must not be able to cancel a branch-' . $far->id . ' order.'
        );
    }

    // ══════════ control: the LIST screen genuinely is scoped ══════════

    /**
     * Unchanged by the fix, and kept exactly as it was: the list screens were
     * already correct, and this proves the fix did not disturb them.
     */
    public function test_the_completed_orders_list_does_honour_the_branch_lock(): void
    {
        $far   = $this->foreignBranch();
        $order = $this->orderIn($far, 'completed');
        $order->forceFill(['completed_at' => now()])->save();

        $staff = $this->staffAt(1);

        $response = $this->actingAs($staff, 'admin')->get('/admin/completed-orders');

        $response->assertOk();
        $response->assertDontSee(
            $order->order_number,
            false
        );
    }
}

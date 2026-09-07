<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Branch scope on the PER-ORDER admin endpoints.
 *
 * The hole this closes is recorded in AuditStaffBranchScopeTest: the list
 * screens honoured ResolvesBranchScope, but every endpoint that resolved ONE
 * order from the URL used a bare findOrFail($id) and never asked which branch
 * the order belonged to. A branch-1 staff member could read and mutate any
 * branch's order by typing its id, and completing one deducted THAT branch's
 * stock.
 *
 * All of them now resolve through App\Services\AdminOrderAccess. This file is
 * the coverage for that rule, and it is deliberately one test PER ENDPOINT
 * rather than one loop over a table: if the check is ever dropped from a single
 * endpoint, exactly one test here must go red and name it.
 *
 * THREE THINGS ARE ASSERTED FOR EVERY WRITE ENDPOINT
 *   1. the cross-branch request is refused (404 — the same answer a
 *      nonexistent id gets, so it cannot be used to probe another branch),
 *   2. the order is UNCHANGED afterwards — a refusal that still mutated would
 *      pass a response-only assertion,
 *   3. the same action on the staff member's OWN branch still works. Without
 *      that control, "refuse everything" would pass.
 *
 * Plus: the inventory row itself is checked, because moving another branch's
 * stock was the real damage; and an admin is checked on a foreign order, both
 * on "all branches" and with the picker set elsewhere, because the fix must not
 * lock admins out of anything they could do before.
 *
 * Every row this file creates carries the BRANCHSCOPE prefix and it runs inside
 * DatabaseTransactions, so nothing survives the run in pomida_db.
 */
class StaffBranchScopeOnOrderEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'BRANCHSCOPE';

    /** The branch the staff member under test is locked to. */
    private const HOME_BRANCH = 1;

    // ══════════════════ fixtures ══════════════════

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

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function otherBranch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Far Branch ' . uniqid(),
            'code'      => 'BSC' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    /**
     * An order in $branchId, with one line, plus whatever state the endpoint
     * under test needs.
     */
    private function orderIn(int $branchId, array $attrs = []): Order
    {
        $order = Order::create(array_merge([
            'order_number'    => 'BSC-' . strtoupper(substr(uniqid(), -8)),
            'branch_id'       => $branchId,
            'type'            => 'pick_up',
            'status'          => 'pending',
            'subtotal'        => 250.00,
            'discount_amount' => 0,
            'total'           => 250.00,
            'payment_method'  => 'cash',
            'payment_status'  => 'paid',
        ], $attrs));

        OrderItem::create([
            'order_id'     => $order->id,
            // order_items.menu_item_id is NOT NULL, so the line must point at a
            // real menu item even where the test only cares about the order row.
            'menu_item_id' => MenuItem::query()->value('id'),
            'item_name'    => self::PREFIX . ' Line',
            'item_price'   => 250.00,
            'quantity'     => 1,
            'subtotal'     => 250.00,
        ]);

        return $order;
    }

    /**
     * An order whose single line has a REAL recipe behind it, so completing it
     * actually deducts stock.
     *
     * Returns [$order, $inventory]. The inventory row and the menu item are
     * created in $branchId, which is what makes "completing a foreign order
     * moves a foreign branch's stock" checkable.
     */
    private function orderWithStockIn(int $branchId): array
    {
        $inventory = Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => self::PREFIX . ' Flour',
            'item_code'       => 'BSC-' . strtoupper(substr(uniqid(), -10)),
            'category'        => self::PREFIX,
            'quantity'        => 100,
            'unit'            => 'kg',
            'low_stock_alert' => 1,
            'unit_cost'       => 10,
            'is_active'       => true,
        ]);

        $menuItem = MenuItem::create([
            'category_id' => \App\Models\Category::query()->value('id'),
            'branch_id'   => $branchId,
            'name'        => self::PREFIX . ' Recipe Cake ' . uniqid(),
            'price'       => 250.00,
            'is_available' => true,
        ]);

        MenuItemIngredient::create([
            'menu_item_id'  => $menuItem->id,
            'inventory_id'  => $inventory->id,
            'quantity_used' => 2,
        ]);

        $order = Order::create([
            'order_number'    => 'BSC-' . strtoupper(substr(uniqid(), -8)),
            'branch_id'       => $branchId,
            'type'            => 'pick_up',
            'status'          => 'pending',
            'subtotal'        => 250.00,
            'discount_amount' => 0,
            'total'           => 250.00,
            'payment_method'  => 'cash',
            'payment_status'  => 'paid',
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'menu_item_id' => $menuItem->id,
            'item_name'    => $menuItem->name,
            'item_price'   => 250.00,
            'quantity'     => 1,
            'subtotal'     => 250.00,
        ]);

        return [$order, $inventory];
    }

    /** Assert the order row is exactly as it was left. */
    private function assertOrderUnchanged(Order $order, string $status, string $paymentStatus): void
    {
        $fresh = $order->fresh();

        $this->assertSame($status, $fresh->status, 'the refused request must not change the order status');
        $this->assertSame($paymentStatus, $fresh->payment_status, 'the refused request must not change the payment status');
    }

    // ══════════════════ READ endpoints, across branches ══════════════════

    public function test_staff_cannot_read_another_branchs_order_detail(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->get('/admin/order-detail/' . $order->id)
            ->assertNotFound();
    }

    public function test_staff_cannot_read_another_branchs_receipt(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, ['status' => 'completed']);

        $response = $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->get('/admin/receipt/' . $order->id);

        $response->assertNotFound();
        $response->assertDontSee($order->order_number, false);
    }

    public function test_staff_can_read_their_own_branchs_receipt(): void
    {
        $order = $this->orderIn(self::HOME_BRANCH, ['status' => 'completed']);

        $response = $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->get('/admin/receipt/' . $order->id);

        $response->assertOk();
        $response->assertSee($order->order_number, false);
    }

    /**
     * The refusal must not double as a probe.
     *
     * A 403 (or any answer that differs from the one a nonexistent id gets)
     * would confirm that the order EXISTS, just somewhere the viewer cannot
     * see — which is information about another branch's trade. This is the same
     * argument DiscountIdAccess makes for its own 404s.
     */
    public function test_the_refusal_does_not_reveal_that_the_order_exists_elsewhere(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, ['status' => 'completed']);
        $staff = $this->staffAt(self::HOME_BRANCH);

        $missingId = (int) Order::max('id') + 99999;

        $foreign = $this->actingAs($staff, 'admin')->get('/admin/receipt/' . $order->id);
        $missing = $this->actingAs($staff, 'admin')->get('/admin/receipt/' . $missingId);

        $this->assertSame(
            $missing->getStatusCode(),
            $foreign->getStatusCode(),
            'a foreign-branch order must be refused exactly the way a nonexistent one is'
        );

        $foreign->assertDontSee($order->order_number, false);
        $foreign->assertDontSee($far->name, false);
    }

    // ══════════════════ WRITE endpoints, across branches ══════════════════

    public function test_staff_cannot_prepare_another_branchs_order(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/prepare')
            ->assertNotFound();

        $this->assertOrderUnchanged($order, 'pending', 'paid');
    }

    public function test_staff_cannot_serve_another_branchs_order(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/serve')
            ->assertNotFound();

        $this->assertOrderUnchanged($order, 'pending', 'paid');
    }

    public function test_staff_cannot_complete_another_branchs_order(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/complete')
            ->assertNotFound();

        $this->assertOrderUnchanged($order, 'pending', 'paid');
        $this->assertNull($order->fresh()->receipt_number, 'a refused completion must not issue a receipt number');
    }

    public function test_staff_cannot_cancel_another_branchs_order(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/cancel')
            ->assertNotFound();

        $this->assertOrderUnchanged($order, 'pending', 'paid');
    }

    public function test_staff_cannot_approve_another_branchs_discount(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, [
            'discount_type'   => 'pwd',
            'discount_status' => 'pending',
        ]);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/discount/approve')
            ->assertNotFound();

        $this->assertSame('pending', $order->fresh()->discount_status);
    }

    public function test_staff_cannot_reject_another_branchs_discount(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, [
            'discount_type'   => 'pwd',
            'discount_status' => 'pending',
        ]);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/discount/reject')
            ->assertNotFound();

        $this->assertSame('pending', $order->fresh()->discount_status);
    }

    public function test_staff_cannot_approve_another_branchs_gcash_payment(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, [
            'payment_method' => 'gcash',
            'payment_status' => 'awaiting_verification',
        ]);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/payment/approve')
            ->assertNotFound();

        $this->assertOrderUnchanged($order, 'pending', 'awaiting_verification');
    }

    public function test_staff_cannot_reject_another_branchs_gcash_payment(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, [
            'payment_method' => 'gcash',
            'payment_status' => 'awaiting_verification',
        ]);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/payment/reject')
            ->assertNotFound();

        $this->assertOrderUnchanged($order, 'pending', 'awaiting_verification');
    }

    public function test_staff_cannot_mark_another_branchs_order_refunded(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, [
            'status'         => 'cancelled',
            'payment_status' => 'refund_pending',
        ]);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/payment/refunded')
            ->assertNotFound();

        $this->assertOrderUnchanged($order, 'cancelled', 'refund_pending');
    }

    /**
     * The discount ID document is attached to the order, so it follows the
     * order's branch. Verifying a PWD/Senior card is a counter job, and a
     * counter only verifies its own branch's customers.
     */
    public function test_staff_cannot_view_another_branchs_discount_id_document(): void
    {
        $path = 'discount_ids/' . self::PREFIX . '-' . uniqid() . '.png';

        Storage::disk('local')->put($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        try {
            $far   = $this->otherBranch();
            $order = $this->orderIn($far->id, [
                'discount_type'     => 'pwd',
                'discount_status'   => 'pending',
                'discount_id_image' => $path,
            ]);

            $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
                ->get('/discount-id/' . $order->id)
                ->assertNotFound();
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    public function test_staff_can_view_their_own_branchs_discount_id_document(): void
    {
        $path = 'discount_ids/' . self::PREFIX . '-' . uniqid() . '.png';

        Storage::disk('local')->put($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        try {
            $order = $this->orderIn(self::HOME_BRANCH, [
                'discount_type'     => 'pwd',
                'discount_status'   => 'pending',
                'discount_id_image' => $path,
            ]);

            $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
                ->get('/discount-id/' . $order->id)
                ->assertOk();
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    // ══════════════════ the inventory half ══════════════════

    /**
     * The damage this fix is really about: completing an order deducts the
     * stock behind its menu items, and those inventory rows belong to the
     * ORDER's branch. Before the fix, branch-1 staff could move branch-N stock.
     *
     * Asserted on the inventory row, not on the response.
     */
    public function test_a_refused_completion_deducts_no_inventory_from_the_other_branch(): void
    {
        $far = $this->otherBranch();
        [$order, $inventory] = $this->orderWithStockIn($far->id);

        $before = (float) $inventory->fresh()->quantity;

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/complete')
            ->assertNotFound();

        $this->assertSame(
            $before,
            (float) $inventory->fresh()->quantity,
            'branch-' . $far->id . ' stock must be untouched by a refused cross-branch completion'
        );
        $this->assertSame('pending', $order->fresh()->status);
    }

    /**
     * The control for the test above: on their OWN branch the deduction must
     * still happen, or the fix would be indistinguishable from "completion is
     * broken".
     */
    public function test_a_permitted_completion_still_deducts_the_staffs_own_branch_stock(): void
    {
        [$order, $inventory] = $this->orderWithStockIn(self::HOME_BRANCH);

        $before = (float) $inventory->fresh()->quantity;

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/complete');

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(
            $before - 2.0,
            (float) $inventory->fresh()->quantity,
            'the recipe deduction must still run for an order in the staff member\'s own branch'
        );
    }

    // ══════════════════ same-branch controls ══════════════════

    public function test_staff_can_prepare_their_own_branchs_order(): void
    {
        $order = $this->orderIn(self::HOME_BRANCH);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/prepare');

        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_staff_can_serve_their_own_branchs_order(): void
    {
        $order = $this->orderIn(self::HOME_BRANCH, ['status' => 'preparing']);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/serve');

        $this->assertSame('serving', $order->fresh()->status);
    }

    public function test_staff_can_complete_their_own_branchs_order(): void
    {
        $order = $this->orderIn(self::HOME_BRANCH);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/complete');

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_staff_can_cancel_their_own_branchs_order(): void
    {
        $order = $this->orderIn(self::HOME_BRANCH);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/cancel');

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_staff_can_approve_their_own_branchs_discount(): void
    {
        $order = $this->orderIn(self::HOME_BRANCH, [
            'discount_type'   => 'pwd',
            'discount_status' => 'pending',
        ]);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/discount/approve');

        $this->assertSame('approved', $order->fresh()->discount_status);
    }

    public function test_staff_can_reject_their_own_branchs_discount(): void
    {
        $order = $this->orderIn(self::HOME_BRANCH, [
            'discount_type'   => 'pwd',
            'discount_status' => 'pending',
        ]);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/discount/reject');

        $this->assertSame('rejected', $order->fresh()->discount_status);
    }

    public function test_staff_can_approve_their_own_branchs_gcash_payment(): void
    {
        $order = $this->orderIn(self::HOME_BRANCH, [
            'payment_method' => 'gcash',
            'payment_status' => 'awaiting_verification',
        ]);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/payment/approve');

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_staff_can_reject_their_own_branchs_gcash_payment(): void
    {
        $order = $this->orderIn(self::HOME_BRANCH, [
            'payment_method' => 'gcash',
            'payment_status' => 'awaiting_verification',
        ]);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/payment/reject');

        $this->assertSame('rejected', $order->fresh()->payment_status);
    }

    public function test_staff_can_mark_their_own_branchs_order_refunded(): void
    {
        $order = $this->orderIn(self::HOME_BRANCH, [
            'status'         => 'cancelled',
            'payment_status' => 'refund_pending',
        ]);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/payment/refunded');

        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    // ══════════════════ admins are not locked out ══════════════════

    /**
     * The default admin scope is 'all branches'. An admin must still be able to
     * open and act on any branch's order from there — that is existing
     * behaviour and this pass does not narrow it.
     */
    public function test_an_admin_on_all_branches_can_still_act_on_any_branchs_order(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->put('/admin/orders/' . $order->id . '/prepare');

        $this->assertSame('preparing', $order->fresh()->status);
    }

    /**
     * And with the picker pointed at some OTHER branch, too. The picker filters
     * the lists; it has never been an authorisation boundary for an admin, and
     * turning it into one here would be a new restriction rather than a fix.
     */
    public function test_an_admin_with_the_picker_elsewhere_can_still_act_on_a_branchs_order(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => self::HOME_BRANCH])
            ->put('/admin/orders/' . $order->id . '/cancel');

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_an_admin_can_still_read_any_branchs_receipt(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, ['status' => 'completed']);

        $response = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get('/admin/receipt/' . $order->id);

        $response->assertOk();
        $response->assertSee($order->order_number, false);
    }
}

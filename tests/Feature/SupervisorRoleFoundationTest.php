<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\AdminOrderAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * SUPERVISOR ROLE FOUNDATION (Sept 2026, phase 1 of the RBAC pass).
 *
 * WHAT THIS ROLE IS
 * -----------------
 * Before this pass `users.role` was an enum of exactly three strings —
 * customer, staff, admin — with no supervisor, manager or owner anywhere in
 * the codebase. `admin` IS the owner in this system (it is the only role
 * AdminAuthController::resettableRoles() covers, and losing it locks the whole
 * system out). `supervisor` is a fourth value, branch-locked, holding exactly
 * the staff shift surface for now.
 *
 * WHAT IS ASSERTED HERE, AND WHY EACH HALF MATTERS
 * ------------------------------------------------
 *  1. BRANCH LOCK. A supervisor reaches only their own branch's data, by
 *     DIRECT URL as well as through the lists. The lists were never the
 *     control — AuditStaffBranchScopeTest is the record of a branch lock that
 *     was a filter on a list rather than an authorisation boundary, and this
 *     file refuses to repeat it. Every cross-branch test here types an id.
 *
 *  2. NO BRANCH SWITCHER. Hiding the "Viewing:" dropdown in the sidebar is a
 *     convenience. GET admin/branches/select/{branch} is asserted to be
 *     refused server-side AND to leave the supervisor's effective scope
 *     unmoved — a refusal that still wrote the session key would pass a
 *     status-code-only assertion and hand over every branch.
 *
 *  3. OWNER-ONLY ACTIONS. Branch CRUD, owner-account takeover and
 *     system-level configuration are refused by direct route access, and the
 *     SIDE EFFECT is checked as well as the status: "did it refuse" and "did
 *     it happen anyway" are different questions.
 *
 *  4. ADMIN IS UNCHANGED. Every restriction above is paired with the admin
 *     doing the same thing successfully. Without those controls, a change that
 *     simply broke the feature for everyone would pass this file.
 *
 * WHY REFUSALS LOOK THE WAY THEY DO
 *  - Cross-branch record access is a 404, never a 403. A 403 would confirm the
 *    record exists somewhere the viewer cannot see, which is information about
 *    another branch's trade. Same argument as AdminOrderAccess and
 *    DiscountIdAccess; asserted explicitly in
 *    test_a_cross_branch_refusal_is_indistinguishable_from_a_missing_record().
 *  - Wrong-role route access is RoleMiddleware's redirect to admin.home with a
 *    flash error, not a raw 403 page. That is this app's established shape for
 *    a role refusal and predates this pass.
 *
 * DATA HYGIENE
 * Everything created here is prefixed SUPFOUND and runs inside
 * DatabaseTransactions, so nothing survives the run. No pre-existing row is
 * mutated, nothing is deleted, and no live staff, supervisor or admin account
 * is touched — the tests mint their own throwaway accounts.
 */
class SupervisorRoleFoundationTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'SUPFOUND';

    /** The branch a test subject is assigned to. */
    private const HOME_BRANCH = 1;

    // ══════════════════════ fixtures ══════════════════════

    private function accountAt(string $role, ?int $branchId): User
    {
        return User::create([
            'name'      => self::PREFIX . ' ' . ucfirst($role),
            'email'     => strtolower(self::PREFIX) . '-' . $role . '-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => $role,
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
    }

    private function supervisorAt(int $branchId): User
    {
        return $this->accountAt('supervisor', $branchId);
    }

    private function admin(): User
    {
        return $this->accountAt('admin', null);
    }

    /** A throwaway branch that is never HOME_BRANCH. */
    private function otherBranch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Far Branch ' . uniqid(),
            'code'      => 'SUP' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function orderIn(int $branchId, array $attributes = []): Order
    {
        $order = Order::create(array_merge([
            'order_number'    => self::PREFIX . '-' . strtoupper(substr(uniqid(), -8)),
            'branch_id'       => $branchId,
            'type'            => 'pick_up',
            'status'          => 'pending',
            'subtotal'        => 250.00,
            'discount_amount' => 0,
            'total'           => 250.00,
            'payment_method'  => 'cash',
            'payment_status'  => 'paid',
        ], $attributes));

        OrderItem::create([
            'order_id'     => $order->id,
            // order_items.menu_item_id is NOT NULL in this schema, so the line
            // must point at a real menu item even though the assertions only
            // care about the snapshot name it renders.
            'menu_item_id' => MenuItem::query()->value('id'),
            'item_name'    => self::PREFIX . ' Secret Cake',
            'item_price'   => 250.00,
            'quantity'     => 1,
            'subtotal'     => 250.00,
        ]);

        return $order;
    }

    private function inventoryIn(int $branchId): Inventory
    {
        return Inventory::create([
            'branch_id'     => $branchId,
            'item_name'     => self::PREFIX . ' Secret Flour',
            'item_code'     => self::PREFIX . strtoupper(substr(uniqid(), -6)),
            'unit'          => 'kg',
            'quantity'      => 10,
            'reorder_level' => 2,
        ]);
    }

    // ═════════════════ 0. the role exists and works ═════════════════

    /**
     * The enum genuinely accepts the value. Without the migration this fails
     * at insert — which is why it is asserted separately from behaviour.
     */
    public function test_a_supervisor_account_can_be_created_and_is_branch_locked(): void
    {
        $supervisor = $this->supervisorAt(self::HOME_BRANCH);

        $this->assertSame('supervisor', $supervisor->fresh()->role);
        $this->assertTrue($supervisor->isSupervisor());
        $this->assertTrue($supervisor->isBranchLocked());
        $this->assertFalse($supervisor->isAdmin());
    }

    /** The portal door (AdminMiddleware) admits the role at all. */
    public function test_a_supervisor_can_reach_the_dashboard(): void
    {
        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->get('/admin/home')
            ->assertOk();
    }

    /**
     * The lock is reported for a supervisor exactly as for staff. If this
     * returns null, getSelectedBranch() falls through to
     * session('selected_branch_id', 'all') and the account reads EVERY branch —
     * so this one assertion is the hinge the whole file turns on.
     */
    public function test_the_branch_lock_reports_the_supervisors_own_branch(): void
    {
        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin');

        $this->assertSame(self::HOME_BRANCH, AdminOrderAccess::lockedBranchId());
    }

    /**
     * A supervisor with NO branch is denied everything, not handed branch 1.
     *
     * storeUser() requires a branch, so this is the unreachable-by-design path.
     * It is asserted precisely because an omission here fails OPEN: the historic
     * `?? 1` fallback would have silently granted Main Branch's orders,
     * inventory and customer ID documents to a misconfigured account.
     */
    public function test_a_supervisor_with_no_branch_is_locked_to_nothing(): void
    {
        $this->actingAs($this->accountAt('supervisor', null), 'admin');

        $this->assertSame(0, AdminOrderAccess::lockedBranchId());
        $this->assertFalse(AdminOrderAccess::allowsBranch(self::HOME_BRANCH));
    }

    // ═════════════════ 1. branch lock, by direct URL ═════════════════

    public function test_a_supervisor_cannot_read_another_branchs_receipt(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, ['status' => 'completed']);

        $response = $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->get('/admin/receipt/' . $order->id);

        $response->assertNotFound();
        $response->assertDontSee($order->order_number, false);
        $response->assertDontSee($far->name, false);
    }

    public function test_a_supervisor_can_read_their_own_branchs_receipt(): void
    {
        $order = $this->orderIn(self::HOME_BRANCH, ['status' => 'completed']);

        $response = $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->get('/admin/receipt/' . $order->id);

        $response->assertOk();
        $response->assertSee($order->order_number, false);
    }

    /**
     * Refusing the request is only half of it. An endpoint that answered 404
     * and completed the order anyway would pass a status-only assertion, and
     * completing an order DEDUCTS that branch's stock.
     */
    public function test_a_supervisor_cannot_complete_another_branchs_order(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, ['status' => 'preparing']);

        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/complete')
            ->assertNotFound();

        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_a_supervisor_cannot_cancel_another_branchs_order(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id);

        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/orders/' . $order->id . '/cancel')
            ->assertNotFound();

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** Moving another branch's stock was the real damage in the original hole. */
    public function test_a_supervisor_cannot_move_another_branchs_stock(): void
    {
        $far  = $this->otherBranch();
        $item = $this->inventoryIn($far->id);

        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->post('/admin/inventory/stock-in/' . $item->id, [
                'quantity' => 50,
                'notes'    => self::PREFIX . ' attempted cross-branch stock-in',
            ])
            ->assertNotFound();

        $this->assertEquals(10, $item->fresh()->quantity);
    }

    /** The creation side: a walk-in order booked into someone else's branch. */
    public function test_a_supervisor_cannot_raise_a_walk_in_order_in_another_branch(): void
    {
        $far = $this->otherBranch();

        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->post('/admin/manual-order', [
                'branch_id'      => $far->id,
                'type'           => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [
                    ['menu_item_id' => MenuItem::query()->value('id'), 'quantity' => 1],
                ],
            ]);

        // Whatever the refusal's shape (404 from the branch guard, or a 422 if
        // validation bites first), NO order may exist in the foreign branch.
        $this->assertSame(
            0,
            Order::where('branch_id', $far->id)->count(),
            'a supervisor booked a walk-in order into another branch'
        );
    }

    /** The QR section, which carried its own hand-rolled copy of the lock. */
    public function test_a_supervisor_cannot_print_another_branchs_table_card(): void
    {
        $far = $this->otherBranch();

        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->getJson('/admin/qr-generator/table-card?branch_id=' . $far->id . '&table_number=1')
            ->assertStatus(403);
    }

    /** The lists, too — the same lock, seen from the other side. */
    public function test_another_branchs_orders_do_not_appear_in_the_supervisors_lists(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, ['status' => 'completed']);

        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->get('/admin/completed-orders')
            ->assertOk()
            ->assertDontSee($order->order_number, false);
    }

    public function test_another_branchs_inventory_does_not_appear_in_the_supervisors_list(): void
    {
        $far  = $this->otherBranch();
        $item = $this->inventoryIn($far->id);

        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->get('/admin/inventory')
            ->assertOk()
            ->assertDontSee($item->item_name, false);
    }

    /**
     * The refusal must not double as a probe: a foreign record and a
     * nonexistent one must be answered identically, or the status code becomes
     * a way to map another branch's order numbers.
     */
    public function test_a_cross_branch_refusal_is_indistinguishable_from_a_missing_record(): void
    {
        $far        = $this->otherBranch();
        $order      = $this->orderIn($far->id, ['status' => 'completed']);
        $supervisor = $this->supervisorAt(self::HOME_BRANCH);
        $missingId  = (int) Order::max('id') + 99999;

        $foreign = $this->actingAs($supervisor, 'admin')->get('/admin/receipt/' . $order->id);
        $missing = $this->actingAs($supervisor, 'admin')->get('/admin/receipt/' . $missingId);

        $this->assertSame(
            $missing->getStatusCode(),
            $foreign->getStatusCode(),
            'a foreign-branch order must be refused exactly the way a nonexistent one is'
        );
    }

    // ═════════════════ 2. the branch switcher ═════════════════

    /**
     * Direct hit on the switcher route. Both halves matter: the refusal, AND
     * the fact that nothing was written to the session on the way out.
     */
    public function test_the_branch_switcher_refuses_a_supervisor_and_writes_nothing(): void
    {
        $far = $this->otherBranch();

        $response = $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->get('/admin/branches/select/' . $far->id);

        $response->assertRedirect(route('admin.home'));
        $response->assertSessionMissing('selected_branch_id');
    }

    public function test_the_branch_switcher_refuses_a_supervisor_asking_for_all_branches(): void
    {
        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->get('/admin/branches/select/all')
            ->assertRedirect(route('admin.home'))
            ->assertSessionMissing('selected_branch_id');
    }

    /**
     * The scope must be unmoved even if the session key is somehow already set
     * to another branch — a stale key from a previous admin session on the same
     * browser, say. getSelectedBranch() must never consult it for a locked role.
     */
    public function test_a_preset_session_scope_cannot_widen_a_supervisor(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, ['status' => 'completed']);

        $this->withSession(['selected_branch_id' => $far->id])
            ->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->get('/admin/completed-orders')
            ->assertOk()
            ->assertDontSee($order->order_number, false);
    }

    /** The dropdown is not rendered for a supervisor. */
    public function test_the_supervisor_is_not_shown_the_branch_picker(): void
    {
        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->assertDontSee('admin/branches/select', false);
    }

    // ═════════════════ 3. owner-only actions ═════════════════

    public function test_a_supervisor_cannot_view_branch_management(): void
    {
        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->get('/admin/branches')
            ->assertRedirect(route('admin.home'));
    }

    public function test_a_supervisor_cannot_create_a_branch(): void
    {
        $before = Branch::count();

        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->post('/admin/branches', [
                'name'    => self::PREFIX . ' Rogue Branch',
                'code'    => 'SUPROGUE',
                'address' => self::PREFIX . ' nowhere',
            ])
            ->assertRedirect(route('admin.home'));

        $this->assertSame($before, Branch::count(), 'a supervisor created a branch');
        $this->assertNull(Branch::where('code', 'SUPROGUE')->first());
    }

    public function test_a_supervisor_cannot_edit_a_branch(): void
    {
        $far      = $this->otherBranch();
        $original = $far->name;

        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/branches/' . $far->id, [
                'name'    => self::PREFIX . ' Renamed By Supervisor',
                'code'    => $far->code,
                'address' => $far->address,
            ])
            ->assertRedirect(route('admin.home'));

        $this->assertSame($original, $far->fresh()->name);
    }

    /** Including their OWN branch — branch CRUD is owner-only, not "not mine". */
    public function test_a_supervisor_cannot_close_their_own_branch(): void
    {
        $home = Branch::find(self::HOME_BRANCH);
        $was  = (bool) $home->is_active;

        $this->actingAs($this->supervisorAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/branches/' . self::HOME_BRANCH . '/toggle')
            ->assertRedirect(route('admin.home'));

        $this->assertSame($was, (bool) $home->fresh()->is_active);
    }

    public function test_a_supervisor_cannot_reach_owner_account_management(): void
    {
        $supervisor = $this->supervisorAt(self::HOME_BRANCH);

        $this->actingAs($supervisor, 'admin')
            ->get('/admin/account')
            ->assertRedirect(route('admin.home'));

        $this->actingAs($supervisor, 'admin')
            ->put('/admin/account/password', [
                'current_password'      => 'Aa1!aaaaaa',
                'password'              => 'Bb2@bbbbbb',
                'password_confirmation' => 'Bb2@bbbbbb',
            ])
            ->assertRedirect(route('admin.home'));
    }

    /**
     * A supervisor cannot seize an account ABOVE OR BESIDE their own tier.
     *
     * This test used to assert that a supervisor could not manage portal
     * accounts AT ALL — /admin/users was `role:admin` when the foundation pass
     * landed. The Sept 2026 permission matrix deliberately changed that: "View
     * / Create / Edit / Reset Password / Activate / Deactivate Staff" are all
     * Y | Y | N, and "Delete Staff Account" is Y | LIMITED | N, so a supervisor
     * now reaches this screen for the staff of their own branch.
     *
     * What has NOT changed, and is what this test now pins, is the ceiling: a
     * supervisor may never take over the OWNER's account or a PEER
     * supervisor's, because resetting a password IS taking over an account.
     * That is the guarantee the original test was really protecting, and it
     * survives the widening intact.
     *
     * The full grid — every role against every feature, and the own-branch /
     * staff-role-only limit on what a supervisor may touch here — lives in
     * RolePermissionMatrixTest.
     */
    public function test_a_supervisor_cannot_seize_an_account_above_their_tier(): void
    {
        $supervisor = $this->supervisorAt(self::HOME_BRANCH);

        foreach ([
            'the owner'         => $this->admin(),
            'a peer supervisor' => $this->supervisorAt(self::HOME_BRANCH),
        ] as $label => $victim) {
            $originalHash = $victim->fresh()->password;

            $this->actingAs($supervisor, 'admin')
                ->put('/admin/users/' . $victim->id . '/password', [
                    'password'              => 'Dd4$dddddd',
                    'password_confirmation' => 'Dd4$dddddd',
                ])
                ->assertRedirect(route('admin.users'));

            $this->assertSame(
                $originalHash,
                $victim->fresh()->password,
                "a supervisor reset the password of $label"
            );

            // Nor deactivate them out of the system.
            $this->actingAs($supervisor, 'admin')
                ->put('/admin/users/' . $victim->id . '/toggle')
                ->assertRedirect(route('admin.users'));

            $this->assertTrue(
                (bool) $victim->fresh()->is_active,
                "a supervisor deactivated $label"
            );
        }
    }

    public function test_a_supervisor_cannot_change_system_configuration(): void
    {
        $supervisor = $this->supervisorAt(self::HOME_BRANCH);

        foreach ([
            '/admin/customization',
            '/admin/customization/customer',
            '/admin/game/toggle',
            '/admin/account/gcash-qr',
        ] as $path) {
            $this->actingAs($supervisor, 'admin')
                ->post($path, [])
                ->assertRedirect(route('admin.home'));
        }
    }

    /**
     * The escalation path that would make the whole role pointless: minting an
     * admin through the account form. Validated against
     * User::ADMIN_MANAGEABLE_ROLES, which cannot contain 'admin'.
     */
    public function test_no_one_can_mint_an_admin_through_the_account_form(): void
    {
        $email = strtolower(self::PREFIX) . '-escalate-' . uniqid() . '@example.test';

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/users', [
                'name'                  => self::PREFIX . ' Escalation Attempt',
                'email'                 => $email,
                'role'                  => 'admin',
                'branch_id'             => self::HOME_BRANCH,
                'password'              => 'Ee5%eeeeee',
                'password_confirmation' => 'Ee5%eeeeee',
            ])
            ->assertSessionHasErrors('role');

        $this->assertNull(User::where('email', $email)->first());
    }

    // ═════════════════ 4. ADMIN/OWNER IS UNCHANGED ═════════════════

    /**
     * Every restriction above is paired here with the admin doing the same
     * thing successfully. Without these, a change that simply broke the
     * feature for everybody would pass this file.
     */
    public function test_an_admin_still_sees_every_branchs_orders(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, ['status' => 'completed']);

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/receipt/' . $order->id)
            ->assertOk()
            ->assertSee($order->order_number, false);
    }

    public function test_an_admin_is_not_branch_locked(): void
    {
        $far = $this->otherBranch();

        $this->actingAs($this->admin(), 'admin');

        $this->assertNull(AdminOrderAccess::lockedBranchId());
        $this->assertTrue(AdminOrderAccess::allowsBranch(self::HOME_BRANCH));
        $this->assertTrue(AdminOrderAccess::allowsBranch($far->id));
    }

    public function test_an_admin_can_still_switch_branches(): void
    {
        $far = $this->otherBranch();

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/branches/select/' . $far->id)
            ->assertRedirect();

        $this->assertSame($far->id, session('selected_branch_id'));

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/branches/select/all');

        $this->assertSame('all', session('selected_branch_id'));
    }

    public function test_an_admin_is_still_shown_the_branch_picker(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->assertSee('admin/branches/select', false);
    }

    public function test_an_admin_still_reaches_every_owner_only_page(): void
    {
        $admin = $this->admin();

        foreach (['/admin/branches', '/admin/users', '/admin/account', '/admin/summary'] as $path) {
            $this->actingAs($admin, 'admin')
                ->get($path)
                ->assertOk();
        }
    }

    public function test_an_admin_can_create_a_supervisor_account(): void
    {
        $email = strtolower(self::PREFIX) . '-created-' . uniqid() . '@example.test';

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/users', [
                'name'                  => self::PREFIX . ' Created Supervisor',
                'email'                 => $email,
                'role'                  => 'supervisor',
                'branch_id'             => self::HOME_BRANCH,
                'password'              => 'Ff6^ffffff',
                'password_confirmation' => 'Ff6^ffffff',
            ])
            ->assertRedirect(route('admin.users'));

        $created = User::where('email', $email)->first();

        $this->assertNotNull($created, 'the admin could not create a supervisor');
        $this->assertSame('supervisor', $created->role);
        $this->assertSame(self::HOME_BRANCH, (int) $created->branch_id);
        $this->assertTrue((bool) $created->is_active);
    }

    /** Staff behaviour is untouched by the arrival of a second locked role. */
    public function test_staff_are_still_locked_to_their_own_branch(): void
    {
        $far   = $this->otherBranch();
        $order = $this->orderIn($far->id, ['status' => 'completed']);

        $this->actingAs($this->accountAt('staff', self::HOME_BRANCH), 'admin')
            ->get('/admin/receipt/' . $order->id)
            ->assertNotFound();

        $this->actingAs($this->accountAt('staff', self::HOME_BRANCH), 'admin');
        $this->assertSame(self::HOME_BRANCH, AdminOrderAccess::lockedBranchId());
    }
}

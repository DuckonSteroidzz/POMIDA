<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\HelpRequest;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Branch scope on the admin/staff endpoints that resolve or create a
 * branch-owned record that is NOT an order.
 *
 * The Sept 2026 order pass (AuditStaffBranchScopeTest,
 * StaffBranchScopeOnOrderEndpointsTest) closed this class of hole on Order and
 * on the discount-id route, then explicitly left the identical hole open
 * elsewhere as out of scope. This file is the coverage for closing the rest:
 *
 *   POST admin/inventory/stock-in/{id}     Inventory  — adds quantity directly
 *   POST admin/inventory/stock-out/{id}    Inventory  — subtracts it
 *   GET  admin/inventory/edit/{id}         Inventory  — leaks stock levels/costs
 *   PUT  admin/menu-items/toggle/{id}      MenuItem   — availability
 *   PUT  admin/help-requests/{id}/assist   HelpRequest
 *   PUT  admin/help-requests/{id}/resolve  HelpRequest
 *   POST admin/manual-order                creates an Order in a named branch
 *   POST admin/tables/clear                acts on a (branch_id, table) pair
 *
 * They all go through App\Services\AdminOrderAccess now — the SAME rule the
 * order endpoints use, not a second copy of it. A branch-locked refusal is the
 * app's standard 404, indistinguishable from a nonexistent id, so it leaks
 * nothing about another branch's trade. (tables/clear keeps its long-standing
 * 403 — see the test for why, and TableOccupancyTest for the behaviour it
 * pins.)
 *
 * WHICH ROLE EACH TEST ACTS AS (changed by the Sept 2026 permission matrix)
 * ------------------------------------------------------------------------
 * The first three endpoints above — stock-in, stock-out and the menu-item
 * availability toggle — are now `role:admin,supervisor`: "Update Stock",
 * "Stock Adjustments" and "Enable/Disable Menu Items" are Y | Y | N, so staff
 * no longer reach them at all. Those tests therefore act as a SUPERVISOR, the
 * lowest-privileged role that still gets there, and see managerAt() for why
 * leaving them on a staff account would have quietly turned them into tests of
 * RoleMiddleware. Supervisor is branch-locked identically, so the guarantee
 * asserted is unchanged.
 *
 * Help requests, manual orders and tables/clear are still shift work shared
 * with staff, and those tests still act as staff.
 *
 * stock-in / stock-out are the point of the pass: branch-1 staff adjusting
 * branch-2 stock is how missing goods get hidden, so those assert the far
 * branch's quantity is byte-identical afterwards, on the row, not the response.
 *
 * Every row this file creates carries the BRANCHREC prefix and it runs inside
 * DatabaseTransactions, so nothing survives the run in pomida_db.
 */
class StaffBranchScopeOnBranchRecordEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'BRANCHREC';
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

    /**
     * A branch-locked MANAGER (supervisor).
     *
     * The Sept 2026 permission matrix moved stock-in, stock-out and the
     * menu-item availability toggle out of the shared admin+staff group and
     * into `role:admin,supervisor` — "Update Stock", "Stock Adjustments" and
     * "Enable/Disable Menu Items" are all Y | Y | N. A staff member is now
     * stopped by RoleMiddleware before AdminOrderAccess is ever consulted, so
     * pointing those tests at a staff account would assert the ROLE gate while
     * claiming to assert the BRANCH gate — and would keep passing if the
     * branch check were deleted outright.
     *
     * Supervisor is in User::BRANCH_LOCKED_ROLES, so it carries exactly the
     * same lock staff did. Swapping the actor keeps this file testing the thing
     * it was written to test: that a branch-locked account cannot reach another
     * branch's record by typing its id.
     */
    private function managerAt(int $branchId): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Supervisor',
            'email'     => strtolower(self::PREFIX) . '-sup-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => 'supervisor',
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
            'code'      => 'BRC' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function inventoryIn(int $branchId, float $quantity = 100): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId,
            'item_name'       => self::PREFIX . ' Flour ' . uniqid(),
            'item_code'       => 'BRC-' . strtoupper(substr(uniqid(), -10)),
            'category'        => self::PREFIX,
            'quantity'        => $quantity,
            'unit'            => 'kg',
            'low_stock_alert' => 1,
            'unit_cost'       => 10,
            'is_active'       => true,
        ]);
    }

    /** A menu item in $branchId with a real recipe behind it, so it is orderable. */
    private function orderableItemIn(int $branchId): MenuItem
    {
        $inventory = $this->inventoryIn($branchId, 500);

        $menuItem = MenuItem::create([
            'category_id'  => Category::query()->value('id'),
            'branch_id'    => $branchId,
            'name'         => self::PREFIX . ' Cake ' . uniqid(),
            'price'        => 120.00,
            'is_available' => true,
        ]);

        MenuItemIngredient::create([
            'menu_item_id'  => $menuItem->id,
            'inventory_id'  => $inventory->id,
            'quantity_used' => 1,
        ]);

        return $menuItem;
    }

    private function helpRequestIn(int $branchId): HelpRequest
    {
        return HelpRequest::create([
            'branch_id'    => $branchId,
            'table_number' => '7',
            'status'       => 'pending',
            'message'      => self::PREFIX . ' please assist',
            'requested_at' => now(),
        ]);
    }

    // ══════════════════ stock-in ══════════════════

    public function test_a_manager_cannot_stock_in_another_branchs_inventory(): void
    {
        $far  = $this->otherBranch();
        $item = $this->inventoryIn($far->id, 100);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->post('/admin/inventory/stock-in/' . $item->id, ['amount' => 25])
            ->assertNotFound();

        $this->assertSame(100.0, (float) $item->fresh()->quantity,
            'the far branch quantity must be identical after a refused stock-in');
        $this->assertSame(0, DB::table('stock_movements')->where('inventory_id', $item->id)->count(),
            'a refused stock-in must not write a stock movement');
    }

    public function test_a_manager_can_stock_in_their_own_branchs_inventory(): void
    {
        $item = $this->inventoryIn(self::HOME_BRANCH, 100);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->post('/admin/inventory/stock-in/' . $item->id, ['amount' => 25])
            ->assertRedirect();

        $this->assertSame(125.0, (float) $item->fresh()->quantity);
    }

    public function test_admin_can_stock_in_any_branchs_inventory(): void
    {
        $far  = $this->otherBranch();
        $item = $this->inventoryIn($far->id, 100);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->post('/admin/inventory/stock-in/' . $item->id, ['amount' => 25])
            ->assertRedirect();

        $this->assertSame(125.0, (float) $item->fresh()->quantity);
    }

    // ══════════════════ stock-out — the point of the pass ══════════════════

    public function test_a_manager_cannot_stock_out_another_branchs_inventory(): void
    {
        $far  = $this->otherBranch();
        $item = $this->inventoryIn($far->id, 100);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->post('/admin/inventory/stock-out/' . $item->id, ['amount' => 40])
            ->assertNotFound();

        $this->assertSame(100.0, (float) $item->fresh()->quantity,
            'the far branch quantity must be identical after a refused stock-out — '
            . 'writing down another branch\'s stock is how missing goods get hidden');
        $this->assertSame(0, DB::table('stock_movements')->where('inventory_id', $item->id)->count());
    }

    public function test_a_manager_can_stock_out_their_own_branchs_inventory(): void
    {
        $item = $this->inventoryIn(self::HOME_BRANCH, 100);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->post('/admin/inventory/stock-out/' . $item->id, ['amount' => 40])
            ->assertRedirect();

        $this->assertSame(60.0, (float) $item->fresh()->quantity);
    }

    public function test_admin_can_stock_out_any_branchs_inventory(): void
    {
        $far  = $this->otherBranch();
        $item = $this->inventoryIn($far->id, 100);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->post('/admin/inventory/stock-out/' . $item->id, ['amount' => 40])
            ->assertRedirect();

        $this->assertSame(60.0, (float) $item->fresh()->quantity);
    }

    public function test_a_refused_stock_movement_is_indistinguishable_from_a_missing_id(): void
    {
        $far   = $this->otherBranch();
        $item  = $this->inventoryIn($far->id, 100);
        $staff = $this->managerAt(self::HOME_BRANCH);

        $missingId = (int) Inventory::max('id') + 99999;

        $foreign = $this->actingAs($staff, 'admin')
            ->post('/admin/inventory/stock-out/' . $item->id, ['amount' => 40]);
        $missing = $this->actingAs($staff, 'admin')
            ->post('/admin/inventory/stock-out/' . $missingId, ['amount' => 40]);

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode(),
            'a foreign-branch inventory id must be refused exactly the way a nonexistent one is');
        $foreign->assertDontSee($item->item_name, false);
        $foreign->assertDontSee($far->name, false);
    }

    // ══════════════════ inventory edit (JSON feed) ══════════════════
    //
    // Route is role:admin today, so a staff member is already stopped by
    // RoleMiddleware — this asserts the scoped resolve does not regress the
    // admin, who must still read any branch's row.

    public function test_admin_can_read_any_branchs_inventory_edit_payload(): void
    {
        $far  = $this->otherBranch();
        $item = $this->inventoryIn($far->id, 77);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->getJson('/admin/inventory/edit/' . $item->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $item->id]);
    }

    // ══════════════════ menu-item availability toggle ══════════════════

    public function test_a_manager_cannot_toggle_another_branchs_menu_item(): void
    {
        $far  = $this->otherBranch();
        $item = MenuItem::create([
            'category_id'  => Category::query()->value('id'),
            'branch_id'    => $far->id,
            'name'         => self::PREFIX . ' Far Item ' . uniqid(),
            'price'        => 90.00,
            'is_available' => true,
        ]);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/menu-items/toggle/' . $item->id)
            ->assertNotFound();

        $this->assertTrue((bool) $item->fresh()->is_available,
            'a refused toggle must leave the far branch item\'s availability unchanged');
    }

    public function test_a_manager_can_toggle_their_own_branchs_menu_item(): void
    {
        $item = MenuItem::create([
            'category_id'  => Category::query()->value('id'),
            'branch_id'    => self::HOME_BRANCH,
            'name'         => self::PREFIX . ' Home Item ' . uniqid(),
            'price'        => 90.00,
            'is_available' => true,
        ]);

        $this->actingAs($this->managerAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/menu-items/toggle/' . $item->id)
            ->assertRedirect();

        $this->assertFalse((bool) $item->fresh()->is_available);
    }

    public function test_admin_can_toggle_any_branchs_menu_item(): void
    {
        $far  = $this->otherBranch();
        $item = MenuItem::create([
            'category_id'  => Category::query()->value('id'),
            'branch_id'    => $far->id,
            'name'         => self::PREFIX . ' Far Item ' . uniqid(),
            'price'        => 90.00,
            'is_available' => true,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->put('/admin/menu-items/toggle/' . $item->id)
            ->assertRedirect();

        $this->assertFalse((bool) $item->fresh()->is_available);
    }

    // ══════════════════ help requests ══════════════════

    public function test_staff_cannot_assist_another_branchs_help_request(): void
    {
        $far  = $this->otherBranch();
        $help = $this->helpRequestIn($far->id);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/help-requests/' . $help->id . '/assist')
            ->assertNotFound();

        $this->assertSame('pending', $help->fresh()->status);
        $this->assertNull($help->fresh()->assisting_at);
    }

    public function test_staff_cannot_resolve_another_branchs_help_request(): void
    {
        $far  = $this->otherBranch();
        $help = $this->helpRequestIn($far->id);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/help-requests/' . $help->id . '/resolve')
            ->assertNotFound();

        $this->assertSame('pending', $help->fresh()->status);
        $this->assertNull($help->fresh()->resolved_at);
    }

    public function test_the_help_request_refusal_is_indistinguishable_from_a_missing_id(): void
    {
        $far   = $this->otherBranch();
        $help  = $this->helpRequestIn($far->id);
        $staff = $this->staffAt(self::HOME_BRANCH);

        $missingId = (int) HelpRequest::max('id') + 99999;

        $foreign = $this->actingAs($staff, 'admin')->put('/admin/help-requests/' . $help->id . '/resolve');
        $missing = $this->actingAs($staff, 'admin')->put('/admin/help-requests/' . $missingId . '/resolve');

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
    }

    public function test_staff_can_assist_their_own_branchs_help_request(): void
    {
        $help = $this->helpRequestIn(self::HOME_BRANCH);

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->put('/admin/help-requests/' . $help->id . '/assist')
            ->assertRedirect();

        $this->assertSame('assisting', $help->fresh()->status);
    }

    public function test_admin_can_resolve_any_branchs_help_request(): void
    {
        $far  = $this->otherBranch();
        $help = $this->helpRequestIn($far->id);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->put('/admin/help-requests/' . $help->id . '/resolve')
            ->assertRedirect();

        $this->assertSame('resolved', $help->fresh()->status);
    }

    // ══════════════════ manual (walk-in) order — creation is scoped too ══════════════════

    private function manualPayload(int $branchId, int $menuItemId): array
    {
        return [
            'branch_id'      => $branchId,
            'order_type'     => 'pick_up',
            'table_number'   => '',
            'payment_method' => 'cash',
            'amount_paid'    => '1000',
            'items'          => [
                (string) $menuItemId => [
                    'menu_item_id' => (string) $menuItemId,
                    'quantity'     => '1',
                ],
            ],
        ];
    }

    public function test_staff_cannot_create_a_manual_order_in_another_branch(): void
    {
        $far  = $this->otherBranch();
        $item = $this->orderableItemIn($far->id);

        $before = (int) DB::table('orders')->max('id');

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', $this->manualPayload($far->id, $item->id))
            ->assertNotFound();

        $this->assertSame(0, DB::table('orders')->where('id', '>', $before)->count(),
            'a staff member must not be able to raise a walk-in order in a branch that is not theirs');
    }

    public function test_staff_can_create_a_manual_order_in_their_own_branch(): void
    {
        $item = $this->orderableItemIn(self::HOME_BRANCH);

        $before = (int) DB::table('orders')->max('id');

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', $this->manualPayload(self::HOME_BRANCH, $item->id));

        $row = DB::table('orders')->where('id', '>', $before)->orderByDesc('id')->first();

        $this->assertNotNull($row, 'the own-branch control must actually create the order');
        $this->assertSame(self::HOME_BRANCH, (int) $row->branch_id);
    }

    public function test_admin_can_create_a_manual_order_in_another_branch(): void
    {
        $far  = $this->otherBranch();
        $item = $this->orderableItemIn($far->id);

        $before = (int) DB::table('orders')->max('id');

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->from('/admin/home')
            ->post('/admin/manual-order', $this->manualPayload($far->id, $item->id));

        $row = DB::table('orders')->where('id', '>', $before)->orderByDesc('id')->first();

        $this->assertNotNull($row, 'an admin keeps the freedom to book a walk-in order into any branch');
        $this->assertSame($far->id, (int) $row->branch_id);
    }

    // ══════════════════ tables/clear — folded into the same rule, 403 kept ══════════════════
    //
    // clearTableOccupancy() used to hand-roll "staff && branch_id !== theirs".
    // It now calls AdminOrderAccess::allowsBranch() — the one rule — but keeps
    // its 403 rather than switching to a 404: TableOccupancyTest pins both the
    // 403 (wrong branch) and the 404 (free table), the panel is a live staff
    // tool where "not your table" is the useful message, and a table session is
    // not the id-enumeration surface the 404 convention exists for.

    public function test_staff_clearing_another_branchs_table_still_gets_a_403(): void
    {
        $far = $this->otherBranch();

        $this->actingAs($this->staffAt(self::HOME_BRANCH), 'admin')
            ->postJson('/admin/tables/clear', ['branch_id' => $far->id, 'table_number' => '5'])
            ->assertStatus(403);
    }
}

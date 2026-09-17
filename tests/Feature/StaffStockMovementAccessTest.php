<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * STAFF ACCESS TO INVENTORY IN/OUT (Sept 2026).
 *
 * "Update Stock" and "Stock Adjustments" are Y | Y | Y: all three portal roles
 * may record a stock movement. This reverses the earlier placement that had
 * moved stock-in/stock-out into the manager group — recording what is actually
 * on the shelf is counter shift work, and making a staff member wait for a
 * supervisor is how the recorded quantity drifts away from reality for a whole
 * shift.
 *
 * WHAT THIS FILE OWNS
 * -------------------
 * The SHAPE of the grant — that it is movement-only, and that the page drawn
 * for a staff member matches it. Specifically:
 *
 *   - staff may move a quantity, and may not touch the item's DEFINITION
 *     (name, unit cost, low-stock threshold) or delete it;
 *   - the business rules on a movement apply to staff exactly as they do to a
 *     manager — no overdraw, and every movement attributed;
 *   - the inventory table renders the right COLUMNS for each of the three
 *     roles, counted from the same flags the cells are drawn from.
 *
 * WHAT IT DELIBERATELY DOES NOT OWN
 * ---------------------------------
 *   - the route-level grid (RolePermissionMatrixTest) — which role may call
 *     which endpoint, and that denied controls are absent from the page;
 *   - the branch lock (StaffBranchScopeOnBranchRecordEndpointsTest) — that a
 *     branch-locked account cannot reach another branch's item by id.
 *
 * Both are asserted there in detail, and duplicating them would mean two files
 * to update when either changes. This file builds on top of them.
 *
 * DATA HYGIENE
 * Everything is prefixed SSMA and runs inside DatabaseTransactions, so nothing
 * survives the run. Every account and inventory row acted on is one this file
 * minted — no pre-existing row is selected for mutation, so the live catalogue
 * (and inventory id 500, Pizza Sauce) is untouched by construction.
 */
class StaffStockMovementAccessTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'SSMA';

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

    private function staff(): User
    {
        return $this->accountAt('staff', self::HOME_BRANCH);
    }

    private function manager(): User
    {
        return $this->accountAt('supervisor', self::HOME_BRANCH);
    }

    private function owner(): User
    {
        return $this->accountAt('admin', null);
    }

    private function item(float $quantity = 100): Inventory
    {
        return Inventory::create([
            'branch_id'       => self::HOME_BRANCH,
            'item_name'       => self::PREFIX . ' Syrup ' . uniqid(),
            'item_code'       => self::PREFIX . strtoupper(substr(uniqid(), -6)),
            'unit'            => 'bottle',
            'quantity'        => $quantity,
            'unit_cost'       => 45,
            'low_stock_alert' => 5,
            'is_active'       => true,
        ]);
    }

    // ══════════════ 1. THE GRANT — staff may move stock ══════════════

    public function test_staff_can_record_a_stock_in_and_it_is_attributed_to_them(): void
    {
        $item  = $this->item(100);
        $staff = $this->staff();

        $this->actingAs($staff, 'admin')
            ->post(route('admin.inventory.stock-in', $item->id), [
                'amount' => 12,
                'note'   => self::PREFIX . ' morning delivery',
            ])
            ->assertRedirect(route('admin.inventory'));

        $this->assertEquals(112, $item->fresh()->quantity);

        /*
         * Attribution is what makes this grant auditable rather than
         * anonymous, and it is the reason widening the role set was safe: the
         * page shows the last 20 movements with the author's name, so a staff
         * correction is as traceable as a manager's always was.
         */
        $this->assertDatabaseHas('stock_movements', [
            'inventory_id'   => $item->id,
            'movement_type'  => 'in',
            'amount'         => 12,
            'quantity_after' => 112,
            'source'         => 'manual',
            'user_id'        => $staff->id,
        ]);
    }

    public function test_staff_can_record_a_stock_out_and_it_is_attributed_to_them(): void
    {
        $item  = $this->item(100);
        $staff = $this->staff();

        $this->actingAs($staff, 'admin')
            ->post(route('admin.inventory.stock-out', $item->id), [
                'amount' => 30,
                'note'   => self::PREFIX . ' spillage',
            ])
            ->assertRedirect(route('admin.inventory'));

        $this->assertEquals(70, $item->fresh()->quantity);

        $this->assertDatabaseHas('stock_movements', [
            'inventory_id'   => $item->id,
            'movement_type'  => 'out',
            'quantity_after' => 70,
            'user_id'        => $staff->id,
        ]);
    }

    // ══════════ 2. THE LIMITS — the business rules still apply ══════════

    /**
     * The overdraw guard is a property of the endpoint, not of the caller's
     * role. Widening who may call it must not have widened what they may do.
     */
    public function test_staff_cannot_stock_out_more_than_is_on_the_shelf(): void
    {
        $item = $this->item(10);

        $this->actingAs($this->staff(), 'admin')
            ->from(route('admin.inventory'))
            ->post(route('admin.inventory.stock-out', $item->id), ['amount' => 25])
            ->assertSessionHasErrors('amount');

        $this->assertEquals(10, $item->fresh()->quantity, 'an overdrawn stock-out still moved stock');
        $this->assertDatabaseMissing('stock_movements', ['inventory_id' => $item->id]);
    }

    public function test_staff_cannot_move_a_zero_or_negative_amount(): void
    {
        $item = $this->item(50);

        foreach ([0, -5] as $bad) {
            $this->actingAs($this->staff(), 'admin')
                ->from(route('admin.inventory'))
                ->post(route('admin.inventory.stock-in', $item->id), ['amount' => $bad])
                ->assertSessionHasErrors('amount');
        }

        $this->assertEquals(50, $item->fresh()->quantity);
    }

    // ══════════ 3. MOVEMENT, NEVER DEFINITION ══════════

    /**
     * The whole shape of the narrow grant, asserted as one statement: the same
     * staff account that just moved a quantity cannot change what the item is,
     * what it costs, when the shop is warned about it, or whether it exists.
     *
     * Each of these is a route-group refusal (RoleMiddleware's redirect to
     * admin.home), asserted here for its SIDE EFFECT rather than its status —
     * a gate that redirects after doing the work would pass a status check.
     */
    public function test_staff_cannot_change_an_items_definition_or_delete_it(): void
    {
        $item  = $this->item(100);
        $staff = $this->staff();

        // Edit the definition.
        $this->actingAs($staff, 'admin')->put(route('admin.inventory.update', $item->id), [
            'item_name'       => self::PREFIX . ' Renamed',
            'item_code'       => self::PREFIX . 'RENAMED',
            'unit'            => 'bottle',
            'quantity'        => 100,
            'unit_cost'       => 1,
            'low_stock_alert' => 999,
        ])->assertRedirect(route('admin.home'));

        // Create a new one.
        $this->actingAs($staff, 'admin')->post(route('admin.inventory.store'), [
            'item_name' => self::PREFIX . ' Smuggled',
            'item_code' => self::PREFIX . 'SMUG',
            'unit'      => 'kg',
            'quantity'  => 1,
            'unit_cost' => 1,
            'branch_id' => self::HOME_BRANCH,
        ])->assertRedirect(route('admin.home'));

        // Delete this one.
        $this->actingAs($staff, 'admin')
            ->delete(route('admin.inventory.delete', $item->id))
            ->assertRedirect(route('admin.home'));

        $fresh = $item->fresh();
        $this->assertNotNull($fresh, 'a staff account deleted an inventory item');
        $this->assertStringNotContainsString('Renamed', $fresh->item_name);
        $this->assertEquals(45, $fresh->unit_cost, 'a staff account rewrote the unit cost');
        $this->assertEquals(5, $fresh->low_stock_alert, 'a staff account rewrote the low-stock threshold');
        $this->assertDatabaseMissing('inventory', ['item_code' => self::PREFIX . 'SMUG']);
    }

    // ══════════ 4. THE PAGE MATCHES THE GRANT ══════════

    /**
     * The table's column count is computed per viewer ($ivColumnCount) and has
     * to track the columns actually rendered, or the "no items" and "no
     * matching items" rows span the wrong width. It drifted once before, when
     * the roles split removed columns and left the count hard-coded at 11.
     *
     * Asserted through the always-rendered (hidden) no-results row, so it does
     * not depend on the branch's inventory being empty:
     *
     *   staff       7 base + In/Out          = 9
     *   supervisor  7 base + In/Out + Edit   = 10
     *   admin       7 base + In/Out + Edit + Delete = 11
     */
    public function test_the_inventory_table_spans_the_columns_each_role_actually_sees(): void
    {
        foreach ([[$this->staff(), 9], [$this->manager(), 10], [$this->owner(), 11]] as [$actor, $expected]) {
            $html = $this->actingAs($actor, 'admin')
                ->get(route('admin.inventory'))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString(
                'colspan="' . $expected . '"',
                $html,
                "the inventory table should span $expected columns for a {$actor->role}"
            );
        }
    }

    /**
     * The In/Out controls are drawn for staff, and the definition controls are
     * not — the visible half of the same split section 3 asserts server-side.
     *
     * The stock modal is the other half of a working button: gated on its own
     * flag now, because it used to sit inside the manager-only block with the
     * Add/Edit form. A visible button whose modal was not rendered would be a
     * dead control that this assertion catches.
     */
    public function test_staff_get_the_stock_controls_and_their_modal_but_not_the_definition_controls(): void
    {
        $html = $this->actingAs($this->staff(), 'admin')
            ->get(route('admin.inventory'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Stock In</th>', $html);
        $this->assertStringContainsString('>Stock Out</th>', $html);
        $this->assertStringContainsString('id="stockModal"', $html, 'the In/Out buttons have no modal to open');
        $this->assertStringContainsString('function openStockModal', $html, 'the In/Out buttons have no handler');

        $this->assertStringNotContainsString('>Edit</th>', $html);
        $this->assertStringNotContainsString('>Delete</th>', $html);
        $this->assertStringNotContainsString('id="itemModal"', $html, 'staff were shipped the Add/Edit form');
        $this->assertStringNotContainsString('id="deleteModal"', $html);
    }

    // ══════════ 5. THE OTHER TWO ROLES ARE UNAFFECTED ══════════

    /**
     * The point of the change was to ADD a role, not to move the boundary for
     * the two that already had it.
     */
    public function test_a_manager_and_the_owner_can_still_move_stock(): void
    {
        foreach ([$this->manager(), $this->owner()] as $actor) {
            $item = $this->item(100);

            $this->actingAs($actor, 'admin')
                ->post(route('admin.inventory.stock-in', $item->id), ['amount' => 7])
                ->assertRedirect(route('admin.inventory'));

            $this->assertEquals(107, $item->fresh()->quantity, "a {$actor->role} could no longer stock in");
        }
    }

    public function test_a_manager_still_gets_the_definition_controls_staff_do_not(): void
    {
        $html = $this->actingAs($this->manager(), 'admin')
            ->get(route('admin.inventory'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Stock In</th>', $html);
        $this->assertStringContainsString('>Edit</th>', $html);
        $this->assertStringContainsString('id="itemModal"', $html);
        $this->assertStringContainsString('id="stockModal"', $html);

        // Delete stays owner-only even for a manager.
        $this->assertStringNotContainsString('>Delete</th>', $html);
    }
}

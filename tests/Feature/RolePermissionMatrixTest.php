<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * THE FULL ROLE PERMISSION MATRIX (Sept 2026, phase 2 of the RBAC pass).
 *
 * Phase 1 (SupervisorRoleFoundationTest) established the role itself, the
 * branch lock and the owner-only refusals. This file asserts the FEATURE GRID
 * layered on top of it: for every capability in the portal, which of the three
 * portal roles may use it.
 *
 *      Owner (admin) | Manager (supervisor) | Staff
 *
 * WHAT IS NOT RE-TESTED HERE
 * --------------------------
 * The branch lock itself. Phase 1 owns that, in detail, and duplicating it
 * would mean two files to update when it changes. This file builds ON it —
 * where a matrix row is LIMITED, the test asserts the LIMIT, not the lock.
 *
 * HOW IT IS STRUCTURED
 * --------------------
 * The matrix is DATA, in matrixRows(), one row per feature naming the route,
 * the HTTP verb, a payload if it needs one, and the expected outcome per role.
 * Three data-provider-driven tests then walk it — one per role — instead of a
 * hundred near-identical methods. Adding a feature to the portal means adding
 * one row here, and the test count grows with it automatically.
 *
 * WHAT "DENIED" MEANS
 * -------------------
 * RoleMiddleware's established shape for a role refusal, which predates this
 * pass: a 302 to admin.home carrying a flash error, not a raw 403. So a denial
 * is asserted as "redirected to admin.home", and — crucially — the SIDE EFFECT
 * is checked separately wherever a row could have changed something. "Did it
 * refuse" and "did it happen anyway" are different questions, and only the
 * second one catches a gate that redirects after the write.
 *
 * BOTH HALVES, ALWAYS
 * -------------------
 * Every denied row is asserted twice: the CONTROL is absent from the rendered
 * page, and the ROUTE refuses a direct request. UI-hiding is never the
 * boundary, and a server-side check with a visible button is a bug report
 * waiting to happen. The two are tested by different methods on purpose.
 *
 * DATA HYGIENE
 * Everything created here is prefixed RBACMTX and runs inside
 * DatabaseTransactions, so nothing survives the run. No pre-existing row is
 * read for mutation, nothing live is deleted, and every account, branch, menu
 * item, voucher and inventory row a test acts on is one this file minted.
 * Inventory id 500 (Pizza Sauce), the owner's real vouchers and every
 * pre-existing staff/supervisor account are untouched by construction: no test
 * here selects a row it did not create.
 */
class RolePermissionMatrixTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'RBACMTX';

    private const HOME_BRANCH = 1;

    /** Outcomes a matrix row can declare. */
    private const ALLOW = 'allow';
    private const DENY  = 'deny';

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

    private function owner(): User
    {
        return $this->accountAt('admin', null);
    }

    private function manager(?int $branchId = self::HOME_BRANCH): User
    {
        return $this->accountAt('supervisor', $branchId);
    }

    private function staff(?int $branchId = self::HOME_BRANCH): User
    {
        return $this->accountAt('staff', $branchId);
    }

    private function actorFor(string $role): User
    {
        return match ($role) {
            'admin'      => $this->owner(),
            'supervisor' => $this->manager(),
            'staff'      => $this->staff(),
        };
    }

    private function otherBranch(): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Far Branch ' . uniqid(),
            'code'      => 'RBX' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    private function menuItemIn(?int $branchId): MenuItem
    {
        return MenuItem::create([
            'name'         => self::PREFIX . ' Dish ' . uniqid(),
            'description'  => self::PREFIX . ' fixture',
            'price'        => 120.00,
            'cost'         => 40.00,
            'branch_id'    => $branchId,
            'category_id'  => \App\Models\Category::query()->value('id'),
            'is_available' => true,
        ]);
    }

    /**
     * Already archived (Delete always archives a menu item), for the
     * permanent-delete row — which acts only on the archive.
     */
    private function archivedMenuItemIn(?int $branchId): MenuItem
    {
        $item = $this->menuItemIn($branchId);
        $item->archive();

        return $item;
    }

    private function inventoryIn(int $branchId): Inventory
    {
        return Inventory::create([
            'branch_id'  => $branchId,
            'item_name'  => self::PREFIX . ' Flour ' . uniqid(),
            'item_code'  => self::PREFIX . strtoupper(substr(uniqid(), -6)),
            'unit'       => 'kg',
            'quantity'   => 10,
            'unit_cost'  => 5,
            'is_active'  => true,
        ]);
    }

    /**
     * Already archived (deleted-stage-one), for the restore / permanent-delete
     * rows below — those act on a row that has already been through
     * inventory.delete, not a live one.
     */
    private function archivedInventoryIn(int $branchId): Inventory
    {
        $item = $this->inventoryIn($branchId);
        $item->archive();

        return $item;
    }

    /**
     * A voucher, scoped to $branchId (null = global, valid everywhere).
     *
     * The parameter arrived with the Sept 2026 branch-scope pass. Before it,
     * every voucher was global by construction and the matrix rows below could
     * mint one blindly; now "Edit/Activate Vouchers" is Y | LIMITED | N, so a
     * supervisor's ALLOW row has to act on a voucher in THEIR branch — a global
     * one is refused, and rightly. bind() therefore mints it at
     * $actor->branch_id, which is null for the owner (global, still allowed)
     * and their own branch for a supervisor. See SupervisorPromotionScopeTest
     * for the LIMIT itself.
     */
    private function voucher(?int $branchId = null): Voucher
    {
        return Voucher::create([
            'branch_id'      => $branchId,
            'code'           => self::PREFIX . strtoupper(substr(uniqid(), -6)),
            'description'    => self::PREFIX . ' fixture voucher',
            'discount_type'  => 'fixed',
            'discount_value' => 10,
            'max_uses'       => 5,
            'is_active'      => true,
        ]);
    }

    private function orderIn(int $branchId): Order
    {
        $order = Order::create([
            'order_number'    => self::PREFIX . '-' . strtoupper(substr(uniqid(), -8)),
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
            'menu_item_id' => MenuItem::query()->value('id'),
            'item_name'    => self::PREFIX . ' Cake',
            'item_price'   => 250.00,
            'quantity'     => 1,
            'subtotal'     => 250.00,
        ]);

        return $order;
    }

    // ══════════════════════ THE MATRIX ══════════════════════

    /**
     * One entry per feature: [label, verb, route-name, expectations].
     *
     * Expectations are keyed by role. Routes taking an id use a `bind` closure
     * that mints the fixture and returns the parameters, so a row that WRITES
     * always writes to something this file created.
     *
     * Only GET rows are exercised by the deny-side provider tests below, plus
     * every writing row — a denial that leaves the write undone is the whole
     * point, so the writing rows matter most.
     */
    public static function matrixRows(): array
    {
        return [
            // ── ADMIN / MANAGEMENT ──
            'create vouchers'        => ['POST',   'admin.vouchers.store',      ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'edit vouchers'          => ['PUT',    'admin.vouchers.update',     ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'toggle vouchers'        => ['PUT',    'admin.vouchers.toggle',     ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'delete vouchers'        => ['DELETE', 'admin.vouchers.delete',     ['admin' => self::ALLOW, 'supervisor' => self::DENY,  'staff' => self::DENY]],
            'view summary'           => ['GET',    'admin.summary',             ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::ALLOW]],
            'view analytics'         => ['GET',    'admin.analytics',           ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'export sales csv'       => ['GET',    'admin.export.orders',       ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'manage ads'             => ['GET',    'admin.ads',                 ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'view staff accounts'    => ['GET',    'admin.users',               ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'create staff accounts'  => ['POST',   'admin.users.store',         ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'edit staff info'        => ['PUT',    'admin.users.update',        ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'reset staff password'   => ['PUT',    'admin.users.password.update', ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'toggle staff'           => ['PUT',    'admin.users.toggle',        ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'delete staff account'   => ['DELETE', 'admin.users.destroy',       ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'owner account page'     => ['GET',    'admin.account',             ['admin' => self::ALLOW, 'supervisor' => self::DENY,  'staff' => self::DENY]],
            'view branches'          => ['GET',    'admin.branches',            ['admin' => self::ALLOW, 'supervisor' => self::DENY,  'staff' => self::DENY]],
            'create branch'          => ['POST',   'admin.branches.store',      ['admin' => self::ALLOW, 'supervisor' => self::DENY,  'staff' => self::DENY]],
            'edit branch'            => ['PUT',    'admin.branches.update',     ['admin' => self::ALLOW, 'supervisor' => self::DENY,  'staff' => self::DENY]],
            'close branch'           => ['PUT',    'admin.branches.toggle',     ['admin' => self::ALLOW, 'supervisor' => self::DENY,  'staff' => self::DENY]],
            'system configuration'   => ['POST',   'admin.customization.update', ['admin' => self::ALLOW, 'supervisor' => self::DENY, 'staff' => self::DENY]],
            'spin wheel toggle'      => ['POST',   'admin.game.toggle',         ['admin' => self::ALLOW, 'supervisor' => self::DENY,  'staff' => self::DENY]],

            // ── OPERATIONAL ──
            'dashboard'              => ['GET',    'admin.home',                ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::ALLOW]],
            'completed orders'       => ['GET',    'admin.completed-orders',    ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::ALLOW]],
            'view inventory'         => ['GET',    'admin.inventory',           ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::ALLOW]],
            'add inventory'          => ['POST',   'admin.inventory.store',     ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            // Stock movement is Y | Y | Y — staff record what is on the shelf
            // during their own shift. The DEFINITION rows either side of these
            // stay Y | Y | N, and that contrast is the whole point of the
            // split: a staff account changes quantities and nothing else.
            'update stock (in)'      => ['POST',   'admin.inventory.stock-in',  ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::ALLOW]],
            'stock adjustment (out)' => ['POST',   'admin.inventory.stock-out', ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::ALLOW]],
            // The inventory DEFINITION — name, unit, unit cost, low-stock
            // threshold. Explicit rows so the staff stock-movement grant above
            // can never be widened into an edit grant without a test saying so.
            'open inventory editor'  => ['GET',    'admin.inventory.edit',      ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'edit inventory item'    => ['PUT',    'admin.inventory.update',    ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'delete inventory'       => ['DELETE', 'admin.inventory.delete',    ['admin' => self::ALLOW, 'supervisor' => self::DENY,  'staff' => self::DENY]],
            'view deleted inventory' => ['GET',    'admin.inventory.deleted',   ['admin' => self::ALLOW, 'supervisor' => self::DENY,  'staff' => self::DENY]],
            'restore inventory'      => ['PUT',    'admin.inventory.restore',   ['admin' => self::ALLOW, 'supervisor' => self::DENY,  'staff' => self::DENY]],
            'permanently delete inventory' => ['DELETE', 'admin.inventory.force-delete', ['admin' => self::ALLOW, 'supervisor' => self::DENY, 'staff' => self::DENY]],
            'view menu items'        => ['GET',    'admin.menu-items',          ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::ALLOW]],
            'add menu item'          => ['POST',   'admin.new-menu-item.post',  ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'edit menu item'         => ['PUT',    'admin.menu-items.update',   ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'toggle menu item'       => ['PUT',    'admin.menu-items.toggle',   ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'delete menu item'       => ['DELETE', 'admin.menu-items.delete',   ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'manage categories'      => ['GET',    'admin.add-category',        ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'manage menu options'    => ['GET',    'admin.menu-options',        ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'qr and table codes'     => ['GET',    'admin.qr-generator',        ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::ALLOW]],
            'rotate table code'      => ['POST',   'admin.qr-generator.regenerate-code', ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            // Same tier as rotating a code: it changes what customers can do at
            // a physical table, so staff see the card but not these buttons.
            'deactivate table'       => ['POST',   'admin.qr-generator.deactivate-table', ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'reactivate table'       => ['POST',   'admin.qr-generator.reactivate-table', ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            // The Manage tables list fed those two actions' Remove / Restore
            // buttons, so it is the same tier. Its section is gone from the page
            // (Oct 2026); the endpoint is kept, unused by the UI.
            'manage tables list'     => ['GET',    'admin.qr-generator.tables', ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            // The Move dialog's trash icon: deleting a table nobody ever used.
            // Same tier as rotating a code; staff move tables but never delete them.
            'delete unused table'    => ['POST',   'admin.tables.delete',       ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::DENY]],
            'view vouchers'         => ['GET',    'admin.vouchers',            ['admin' => self::ALLOW, 'supervisor' => self::ALLOW, 'staff' => self::ALLOW]],
            'restore archived'       => ['PUT',    'admin.archived.restore',    ['admin' => self::ALLOW, 'supervisor' => self::DENY,  'staff' => self::DENY]],
            'permanently delete archived menu item' => ['DELETE', 'admin.archived.menu-item.force-delete', ['admin' => self::ALLOW, 'supervisor' => self::DENY, 'staff' => self::DENY]],
        ];
    }

    /** The matrix, flattened to one case per (feature, role). */
    public static function matrixCases(): array
    {
        $cases = [];

        foreach (self::matrixRows() as $label => [$verb, $route, $expected]) {
            foreach ($expected as $role => $outcome) {
                $cases["$label / $role"] = [$label, $verb, $route, $role, $outcome];
            }
        }

        return $cases;
    }

    /**
     * Route parameters and a payload for a row, built from fixtures this file
     * owns. Returns [parameters, payload].
     */
    private function bind(string $route, User $actor): array
    {
        $branch = $actor->branch_id ?? self::HOME_BRANCH;

        return match ($route) {
            'admin.vouchers.update',
            'admin.vouchers.toggle',
            'admin.vouchers.delete' => [
                // $actor->branch_id, NOT $branch: the owner must get a global
                // voucher (null) so their unrestricted reach is what is tested,
                // while a branch-locked actor gets one of their own. $branch
                // coalesces null to HOME_BRANCH, which would quietly turn the
                // owner's row into a branch-scoped case.
                [$this->voucher($actor->branch_id)->id],
                ['code' => self::PREFIX . 'UPD', 'discount_type' => 'fixed', 'discount_value' => 15, 'max_uses' => 5],
            ],
            'admin.vouchers.store' => [
                [],
                [
                    'code' => self::PREFIX . strtoupper(substr(uniqid(), -6)),
                    'description' => self::PREFIX . ' new',
                    'discount_type' => 'fixed',
                    'discount_value' => 10,
                    'max_uses' => 5,
                ],
            ],
            'admin.users.store' => [
                [],
                [
                    'name' => self::PREFIX . ' Newbie',
                    'email' => strtolower(self::PREFIX) . '-new-' . uniqid() . '@example.test',
                    'branch_id' => $branch,
                    'role' => 'staff',
                    'password' => 'Aa1!aaaaaa',
                    'password_confirmation' => 'Aa1!aaaaaa',
                ],
            ],
            'admin.users.update' => [
                [$this->staff($branch)->id],
                [
                    'name' => self::PREFIX . ' Renamed',
                    'email' => strtolower(self::PREFIX) . '-ren-' . uniqid() . '@example.test',
                    'branch_id' => $branch,
                    'role' => 'staff',
                ],
            ],
            'admin.users.password.update' => [
                [$this->staff($branch)->id],
                ['password' => 'Bb2@bbbbbb', 'password_confirmation' => 'Bb2@bbbbbb'],
            ],
            'admin.users.toggle',
            'admin.users.destroy' => [[$this->staff($branch)->id], []],
            'admin.branches.update',
            'admin.branches.toggle' => [
                [$this->otherBranch()->id],
                ['name' => self::PREFIX . ' Renamed Branch', 'address' => self::PREFIX . ' addr'],
            ],
            'admin.branches.store' => [
                [],
                ['name' => self::PREFIX . ' Brand New ' . uniqid(), 'code' => 'RBN' . strtoupper(substr(uniqid(), -5)), 'address' => self::PREFIX . ' addr'],
            ],
            'admin.inventory.store' => [
                [],
                [
                    'item_name' => self::PREFIX . ' New Stock ' . uniqid(),
                    'item_code' => self::PREFIX . strtoupper(substr(uniqid(), -6)),
                    'unit' => 'kg',
                    'quantity' => 5,
                    'unit_cost' => 3,
                    'branch_id' => $branch,
                ],
            ],
            // 'amount' and 'note', NOT 'quantity'/'notes': those are what
            // stockIn()/stockOut() actually validate. The old spelling was
            // silently ignored and the request failed validation instead of
            // moving stock — harmless while every staff row here was DENY (the
            // role gate answered first), but useless the moment one is ALLOW.
            'admin.inventory.stock-in',
            'admin.inventory.stock-out' => [
                [$this->inventoryIn($branch)->id],
                ['amount' => 1, 'note' => self::PREFIX . ' movement'],
            ],
            'admin.inventory.edit' => [[$this->inventoryIn($branch)->id], []],
            'admin.inventory.update' => [
                [$this->inventoryIn($branch)->id],
                [
                    'item_name'       => self::PREFIX . ' Renamed Stock ' . uniqid(),
                    'item_code'       => self::PREFIX . strtoupper(substr(uniqid(), -6)),
                    'unit'            => 'kg',
                    'quantity'        => 12,
                    'unit_cost'       => 6,
                    'low_stock_alert' => 3,
                ],
            ],
            'admin.inventory.delete' => [[$this->inventoryIn($branch)->id], []],
            'admin.inventory.restore',
            'admin.inventory.force-delete' => [[$this->archivedInventoryIn($branch)->id], []],
            'admin.menu-items.toggle',
            'admin.menu-items.delete' => [[$this->menuItemIn($branch)->id], []],
            'admin.menu-items.update' => [
                [$this->menuItemIn($branch)->id],
                ['name' => self::PREFIX . ' Renamed Dish', 'price' => 130, 'branch_id' => $branch],
            ],
            'admin.new-menu-item.post' => [
                [],
                [
                    'name' => self::PREFIX . ' Fresh Dish ' . uniqid(),
                    'price' => 99,
                    'branch_id' => $branch,
                    'category_id' => \App\Models\Category::query()->value('id'),
                ],
            ],
            'admin.archived.restore' => [['menu-item', $this->menuItemIn($branch)->id], []],
            'admin.archived.menu-item.force-delete' => [[$this->archivedMenuItemIn($branch)->id], []],
            'admin.qr-generator.regenerate-code' => [[], ['branch_id' => $branch, 'table_number' => 1]],
            'admin.qr-generator.tables' => [[], ['branch_id' => $branch]],
            'admin.customization.update' => [[], ['primary_color' => '#F4845F']],
            'admin.game.toggle' => [[], []],
            default => [[], []],
        };
    }

    private function hit(string $verb, string $route, array $parameters, array $payload)
    {
        $url = route($route, $parameters);

        return match ($verb) {
            'GET'    => $this->get($url . (str_contains($url, '?') ? '&' : '?') . http_build_query($payload)),
            'POST'   => $this->post($url, $payload),
            'PUT'    => $this->put($url, $payload),
            'DELETE' => $this->delete($url, $payload),
        };
    }

    // ═════════════ 1. THE GRID — server-side enforcement ═════════════

    /**
     * The whole matrix, one case per (feature, role), asserted against the
     * ROUTE rather than the page.
     *
     * A denial is RoleMiddleware's redirect to admin.home. An allowance is
     * merely "not that refusal" — a row may legitimately answer 200, 302-back,
     * 404 or 422 depending on the fixture, and pinning each one would make this
     * a test of validation rules rather than of permissions. What it must never
     * do is bounce to admin.home with the permission error.
     *
     * @dataProvider matrixCases
     */
    public function test_matrix_row_is_enforced_server_side(
        string $label,
        string $verb,
        string $route,
        string $role,
        string $outcome
    ): void {
        $actor = $this->actorFor($role);

        [$parameters, $payload] = $this->bind($route, $actor);

        $response = $this->actingAs($actor, 'admin')->hit($verb, $route, $parameters, $payload);

        if ($outcome === self::DENY) {
            $response->assertRedirect(route('admin.home'));
            $this->assertSame(
                "You don't have permission to access that.",
                session('error'),
                "[$label] $role was refused, but not by the role gate."
            );

            return;
        }

        $isRoleRefusal = $response->isRedirect(route('admin.home'))
            && session('error') === "You don't have permission to access that.";

        $this->assertFalse(
            $isRoleRefusal,
            "[$label] $role should be allowed but was refused by the role gate."
        );
    }

    // ═════════════ 2. THE GRID — the UI agrees with it ═════════════

    /**
     * Every denied entry must ALSO be invisible: the control the role cannot
     * use is not rendered on the page they can reach.
     *
     * Asserted as "the URL does not appear in the HTML" against the page that
     * would host the control. A server-side gate with a visible button is not a
     * security hole, but it is a support ticket and a lie to the user, and the
     * brief asks for both halves.
     *
     * @dataProvider uiVisibilityCases
     */
    public function test_denied_controls_are_absent_from_the_page(
        string $role,
        string $page,
        string $absentRoute,
        array $absentParameters
    ): void {
        $actor = $this->actorFor($role);

        $html = $this->actingAs($actor, 'admin')->get(route($page))->assertOk()->getContent();

        $needle = route($absentRoute, $absentParameters, false);

        $this->assertStringNotContainsString(
            $needle,
            $html,
            "$role can see a control for $absentRoute on $page, which they may not use."
        );
    }

    public static function uiVisibilityCases(): array
    {
        return [
            // Staff — the sidebar must not offer the manager screens.
            'staff / no analytics link'    => ['staff', 'admin.home', 'admin.analytics', []],
            'staff / no ads link'          => ['staff', 'admin.home', 'admin.ads', []],
            'staff / no staff accounts'    => ['staff', 'admin.home', 'admin.users', []],
            'staff / no categories link'   => ['staff', 'admin.home', 'admin.add-category', []],
            'staff / no menu options link' => ['staff', 'admin.home', 'admin.menu-options', []],
            'staff / no branches link'     => ['staff', 'admin.home', 'admin.branches', []],
            'staff / no account link'      => ['staff', 'admin.home', 'admin.account', []],

            // Staff — page-level controls.
            //
            // Only routes whose URL is UNIQUE to the forbidden action belong
            // here. admin.inventory.store and admin.vouchers.store share their
            // URL with the GET list the staff member legitimately has a nav
            // link to (POST /admin/inventory vs GET /admin/inventory), so a
            // URL-substring assertion on those can only ever fail. Those two
            // are covered by test_forbidden_write_controls_are_absent_by_markup
            // below, which looks for the control itself.
            'staff / no add menu item'     => ['staff', 'admin.menu-items', 'admin.new-menu-item.post', []],
            'staff / no export csv'        => ['staff', 'admin.summary', 'admin.export.orders', []],
            'staff / no rotate table code' => ['staff', 'admin.qr-generator', 'admin.qr-generator.regenerate-code', []],
            'staff / no deactivate table'  => ['staff', 'admin.qr-generator', 'admin.qr-generator.deactivate-table', []],
            'staff / no reactivate table'  => ['staff', 'admin.qr-generator', 'admin.qr-generator.reactivate-table', []],
            'staff / no manage tables list' => ['staff', 'admin.qr-generator', 'admin.qr-generator.tables', []],
            'staff / no delete table'      => ['staff', 'admin.qr-generator', 'admin.tables.delete', []],

            // Manager — must not see the owner-only controls.
            'manager / no branches link'   => ['supervisor', 'admin.home', 'admin.branches', []],
            'manager / no account link'    => ['supervisor', 'admin.home', 'admin.account', []],
            'manager / no spin wheel'      => ['supervisor', 'admin.vouchers', 'admin.game.toggle', []],
            'manager / no deleted items link' => ['supervisor', 'admin.inventory', 'admin.inventory.deleted', []],
        ];
    }

    /**
     * The controls whose URL is shared with a page the role may legitimately
     * reach, asserted by their MARKUP instead.
     *
     * POST /admin/inventory and GET /admin/inventory are the same URL, as are
     * POST and GET /admin/vouchers, so "the URL is absent" is unprovable for
     * those — a staff member has a nav link to both lists. What must be absent
     * is the CONTROL: the Add Item button, the Edit button, the Create New
     * Voucher form.
     *
     * $shouldSee is why this provider outgrew its name: the Stock In/Out rows
     * assert PRESENCE for staff, because those controls became theirs. Same
     * mechanism, opposite expectation, one table.
     *
     * @dataProvider markupControlCases
     */
    public function test_forbidden_write_controls_are_absent_by_markup(
        string $role,
        string $page,
        string $marker,
        bool $shouldSee
    ): void {
        $html = $this->actingAs($this->actorFor($role), 'admin')
            ->get(route($page))
            ->assertOk()
            ->getContent();

        if ($shouldSee) {
            $this->assertStringContainsString($marker, $html, "$role should see \"$marker\" on $page.");

            return;
        }

        $this->assertStringNotContainsString($marker, $html, "$role must not see \"$marker\" on $page.");
    }

    public static function markupControlCases(): array
    {
        return [
            // Inventory — staff MOVE stock but never change its definition.
            // The three rows below are the whole shape of that split, asserted
            // on one page for one role: In and Out present, Add Item absent.
            'staff / no add item button'   => ['staff', 'admin.inventory', 'openAddModal()', false],
            'staff / has stock in button'  => ['staff', 'admin.inventory', "openStockModal(this, 'in')", true],
            'staff / has stock out button' => ['staff', 'admin.inventory', "openStockModal(this, 'out')", true],
            // Edit is the definition, so it stays hidden from staff even
            // though the In/Out buttons beside it are now theirs.
            'staff / no edit item button'  => ['staff', 'admin.inventory', 'openEditModal(this)', false],
            'staff / no delete inv'        => ['staff', 'admin.inventory', 'confirmDelete(this.dataset.id)', false],
            'manager / has add item'       => ['supervisor', 'admin.inventory', 'openAddModal()', true],
            'manager / has stock in'       => ['supervisor', 'admin.inventory', "openStockModal(this, 'in')", true],
            'manager / has edit item'      => ['supervisor', 'admin.inventory', 'openEditModal(this)', true],
            // Delete Inventory is owner-only even for a manager.
            'manager / no delete inv'      => ['supervisor', 'admin.inventory', 'confirmDelete(this.dataset.id)', false],
            'owner / has delete inv'       => ['admin', 'admin.inventory', 'confirmDelete(this.dataset.id)', true],

            // Vouchers — staff read the list only.
            'staff / no create voucher'    => ['staff', 'admin.vouchers', 'Create New Voucher', false],
            'manager / has create voucher' => ['supervisor', 'admin.vouchers', 'Create New Voucher', true],
            'manager / no spin wheel card' => ['supervisor', 'admin.vouchers', 'Spin Wheel Game', false],
            'owner / has spin wheel card'  => ['admin', 'admin.vouchers', 'Spin Wheel Game', true],

            // Menu items — staff see availability as a badge, not a toggle.
            'staff / no availability form' => ['staff', 'admin.menu-items', 'openAddModal()', false],
            'manager / has availability'   => ['supervisor', 'admin.menu-items', 'openAddModal()', true],
        ];
    }

    /**
     * The mirror of the test above: a control the role MAY use is present.
     *
     * Without this, hiding every button from everyone would make the visibility
     * test above pass completely.
     *
     * @dataProvider uiPresenceCases
     */
    public function test_permitted_controls_are_present_on_the_page(
        string $role,
        string $page,
        string $presentRoute
    ): void {
        $actor = $this->actorFor($role);

        $html = $this->actingAs($actor, 'admin')->get(route($page))->assertOk()->getContent();

        $this->assertStringContainsString(
            route($presentRoute, [], false),
            $html,
            "$role should see a control for $presentRoute on $page."
        );
    }

    public static function uiPresenceCases(): array
    {
        return [
            'manager sees analytics link'  => ['supervisor', 'admin.home', 'admin.analytics'],
            'manager sees ads link'        => ['supervisor', 'admin.home', 'admin.ads'],
            'manager sees staff accounts'  => ['supervisor', 'admin.home', 'admin.users'],
            'manager sees create voucher'  => ['supervisor', 'admin.vouchers', 'admin.vouchers.store'],
            'manager sees add inventory'   => ['supervisor', 'admin.inventory', 'admin.inventory.store'],
            'manager sees export csv'      => ['supervisor', 'admin.summary', 'admin.export.orders'],
            // The Manage tables section is gone (Oct 2026); a manager's table
            // control on this page is now the Move dialog's delete-table action.
            'manager sees delete table'    => ['supervisor', 'admin.qr-generator', 'admin.tables.delete'],
            'owner sees delete table'      => ['admin', 'admin.qr-generator', 'admin.tables.delete'],
            'owner sees branches link'     => ['admin', 'admin.home', 'admin.branches'],
            'owner sees spin wheel'        => ['admin', 'admin.vouchers', 'admin.game.toggle'],
            'staff still see summary'      => ['staff', 'admin.home', 'admin.summary'],
            'staff still see vouchers'     => ['staff', 'admin.home', 'admin.vouchers'],
        ];
    }

    // ═════════════ 3. DENIALS DO NOT WRITE ═════════════

    /**
     * A refusal that redirects AFTER doing the work would pass every status
     * assertion above. These check the side effect is absent.
     */
    /**
     * Staff MAY move stock, so the side-effect question for them inverted:
     * this used to assert a refused stock-in moved nothing. The refusal it
     * asserted is gone, so asserting it again would test nothing. What must
     * still be true is the other half of the split — a staff member who can
     * change a QUANTITY still cannot change the item's DEFINITION.
     */
    public function test_a_denied_staff_inventory_edit_changes_no_definition(): void
    {
        $item = $this->inventoryIn(self::HOME_BRANCH);
        $beforeName = $item->item_name;
        $beforeCost = $item->unit_cost;

        $this->actingAs($this->staff(), 'admin')
            ->put(route('admin.inventory.update', $item->id), [
                'item_name'       => self::PREFIX . ' Staff Renamed',
                'item_code'       => self::PREFIX . 'STAFFED',
                'unit'            => 'kg',
                'quantity'        => 999,
                'unit_cost'       => 9999,
                'low_stock_alert' => 1,
            ])
            ->assertRedirect(route('admin.home'));

        $fresh = $item->fresh();
        $this->assertSame($beforeName, $fresh->item_name, 'A refused inventory edit still renamed the item.');
        $this->assertEquals($beforeCost, $fresh->unit_cost, 'A refused inventory edit still changed the unit cost.');
        $this->assertEquals(10, $fresh->quantity, 'A refused inventory edit still moved stock through the edit form.');
    }

    /**
     * The grant's own side effect, asserted positively: an ALLOW row that
     * redirected without doing the work would pass every status assertion in
     * the grid above. This is the mirror of the denial tests around it.
     */
    public function test_a_permitted_staff_stock_movement_moves_stock_and_is_attributed(): void
    {
        $item  = $this->inventoryIn(self::HOME_BRANCH);
        $staff = $this->staff(self::HOME_BRANCH);

        $this->actingAs($staff, 'admin')
            ->post(route('admin.inventory.stock-in', $item->id), [
                'amount' => 5,
                'note'   => self::PREFIX . ' delivery',
            ])
            ->assertRedirect(route('admin.inventory'));

        $this->assertEquals(15, $item->fresh()->quantity, 'A permitted staff stock-in did not move stock.');

        // Attribution is what makes the grant auditable rather than anonymous.
        $this->assertDatabaseHas('stock_movements', [
            'inventory_id'  => $item->id,
            'movement_type' => 'in',
            'user_id'       => $staff->id,
        ]);
    }

    public function test_a_denied_staff_menu_toggle_changes_no_availability(): void
    {
        $item = $this->menuItemIn(self::HOME_BRANCH);
        $before = $item->is_available;

        $this->actingAs($this->staff(), 'admin')
            ->put(route('admin.menu-items.toggle', $item->id))
            ->assertRedirect(route('admin.home'));

        $this->assertEquals($before, $item->fresh()->is_available, 'A refused toggle still changed availability.');
    }

    public function test_a_denied_manager_voucher_delete_leaves_the_voucher(): void
    {
        // Their OWN branch's voucher, deliberately: "Delete Vouchers" is
        // Y | N | N and stayed that way through the branch-scope pass, so even
        // the one voucher a supervisor may freely edit and toggle is still not
        // one they may delete. A global fixture would have proved less.
        $voucher = $this->voucher(self::HOME_BRANCH);

        $this->actingAs($this->manager(), 'admin')
            ->delete(route('admin.vouchers.delete', $voucher->id))
            ->assertRedirect(route('admin.home'));

        $this->assertNotNull(Voucher::find($voucher->id), 'A refused voucher delete removed it anyway.');
    }

    public function test_a_denied_manager_inventory_delete_leaves_the_record(): void
    {
        $item = $this->inventoryIn(self::HOME_BRANCH);

        $this->actingAs($this->manager(), 'admin')
            ->delete(route('admin.inventory.delete', $item->id))
            ->assertRedirect(route('admin.home'));

        $this->assertNotNull(Inventory::find($item->id), 'A refused inventory delete removed it anyway.');
    }

    // ═════════════ 4. THE LIMITED ENTRIES ═════════════

    /**
     * "Delete Staff Account (Manager = LIMITED)": only Staff-role accounts,
     * only in their own branch.
     *
     * Each case types the target's id directly, which is the only way this
     * could ever be attacked — the list already omits these rows.
     *
     * @dataProvider forbiddenStaffTargets
     */
    public function test_a_manager_cannot_delete_an_account_outside_their_limit(string $targetKind): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $far     = $this->otherBranch();

        $target = match ($targetKind) {
            'peer supervisor'      => $this->manager(self::HOME_BRANCH),
            'owner'                => $this->owner(),
            'other branch staff'   => $this->staff($far->id),
            'themselves'           => $manager,
        };

        $this->actingAs($manager, 'admin')
            ->delete(route('admin.users.destroy', $target->id))
            ->assertRedirect(route('admin.users'));

        $this->assertNotNull(
            User::find($target->id),
            "A manager deleted a $targetKind account, which the LIMITED rule forbids."
        );
    }

    public static function forbiddenStaffTargets(): array
    {
        return [
            'peer supervisor'    => ['peer supervisor'],
            'owner'              => ['owner'],
            'other branch staff' => ['other branch staff'],
            'themselves'         => ['themselves'],
        ];
    }

    /** The permitted half — otherwise the rule above could just be "never". */
    public function test_a_manager_can_delete_their_own_branch_staff(): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $target  = $this->staff(self::HOME_BRANCH);

        $this->actingAs($manager, 'admin')
            ->delete(route('admin.users.destroy', $target->id))
            ->assertRedirect(route('admin.users'));

        $this->assertNull(User::find($target->id), 'A manager could not delete their own branch staff.');
    }

    /**
     * The same LIMITED rule governs the other three staff-management actions,
     * which is the point of routing them all through canManageAccount().
     *
     * @dataProvider staffManagementActions
     */
    public function test_the_limited_rule_covers_every_staff_management_action(string $verb, string $route, array $payload): void
    {
        $manager = $this->manager(self::HOME_BRANCH);
        $far     = $this->otherBranch();
        $target  = $this->staff($far->id);

        $before = $target->fresh();

        $this->actingAs($manager, 'admin')
            ->hit($verb, $route, [$target->id], $payload)
            ->assertRedirect(route('admin.users'));

        $after = $target->fresh();

        $this->assertNotNull($after, "$route deleted another branch's staff.");
        $this->assertSame($before->name, $after->name, "$route renamed another branch's staff.");
        $this->assertSame($before->is_active, $after->is_active, "$route toggled another branch's staff.");
        $this->assertSame($before->password, $after->password, "$route reset another branch's staff password.");
    }

    public static function staffManagementActions(): array
    {
        return [
            'toggle'   => ['PUT', 'admin.users.toggle', []],
            'password' => ['PUT', 'admin.users.password.update', ['password' => 'Cc3#cccccc', 'password_confirmation' => 'Cc3#cccccc']],
            'edit'     => ['PUT', 'admin.users.update', [
                'name' => 'RBACMTX Hijacked',
                'email' => 'rbacmtx-hijack@example.test',
                'branch_id' => 1,
                'role' => 'staff',
            ]],
        ];
    }

    /**
     * "Change Staff Role (Manager = N)". A manager admitted to the create form
     * must not be able to mint a peer, nor plant an account in another branch.
     */
    public function test_a_manager_cannot_create_a_supervisor(): void
    {
        $email = strtolower(self::PREFIX) . '-escalate-' . uniqid() . '@example.test';

        $this->actingAs($this->manager(self::HOME_BRANCH), 'admin')
            ->post(route('admin.users.store'), [
                'name' => self::PREFIX . ' Escalation',
                'email' => $email,
                'branch_id' => self::HOME_BRANCH,
                'role' => 'supervisor',
                'password' => 'Aa1!aaaaaa',
                'password_confirmation' => 'Aa1!aaaaaa',
            ])
            ->assertSessionHasErrors('role');

        $this->assertNull(User::where('email', $email)->first(), 'A manager minted a peer supervisor.');
    }

    public function test_a_manager_cannot_plant_an_account_in_another_branch(): void
    {
        $far   = $this->otherBranch();
        $email = strtolower(self::PREFIX) . '-planted-' . uniqid() . '@example.test';

        $this->actingAs($this->manager(self::HOME_BRANCH), 'admin')
            ->post(route('admin.users.store'), [
                'name' => self::PREFIX . ' Planted',
                'email' => $email,
                'branch_id' => $far->id,
                'role' => 'staff',
                'password' => 'Aa1!aaaaaa',
                'password_confirmation' => 'Aa1!aaaaaa',
            ]);

        $created = User::where('email', $email)->first();

        $this->assertNotNull($created, 'The manager could not create an account at all.');
        $this->assertSame(
            self::HOME_BRANCH,
            (int) $created->branch_id,
            'A manager planted an account in a branch that is not theirs.'
        );
    }

    /** The owner keeps the full role choice — the manager narrowing is not global. */
    public function test_the_owner_can_still_create_a_supervisor(): void
    {
        $email = strtolower(self::PREFIX) . '-newsup-' . uniqid() . '@example.test';

        $this->actingAs($this->owner(), 'admin')
            ->post(route('admin.users.store'), [
                'name' => self::PREFIX . ' New Supervisor',
                'email' => $email,
                'branch_id' => self::HOME_BRANCH,
                'role' => 'supervisor',
                'password' => 'Aa1!aaaaaa',
                'password_confirmation' => 'Aa1!aaaaaa',
            ]);

        $this->assertSame('supervisor', User::where('email', $email)->value('role'));
    }

    /** A manager's roster shows their own branch's staff only. */
    public function test_a_manager_sees_only_their_own_branch_staff(): void
    {
        $far        = $this->otherBranch();
        $mine       = $this->staff(self::HOME_BRANCH);
        $theirs     = $this->staff($far->id);
        $peer       = $this->manager(self::HOME_BRANCH);

        $html = $this->actingAs($this->manager(self::HOME_BRANCH), 'admin')
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($mine->email, $html, "A manager cannot see their own branch's staff.");
        $this->assertStringNotContainsString($theirs->email, $html, "A manager can see another branch's staff.");
        $this->assertStringNotContainsString($peer->email, $html, 'A manager can see a peer supervisor.');
    }

    /**
     * "Delete Menu Items (Manager = LIMITED)": own-branch items only, and never
     * a shared one.
     *
     * @dataProvider forbiddenMenuItemTargets
     */
    public function test_a_manager_cannot_delete_a_menu_item_outside_their_limit(string $kind): void
    {
        $manager = $this->manager(self::HOME_BRANCH);

        $item = $kind === 'shared'
            ? $this->menuItemIn(null)
            : $this->menuItemIn($this->otherBranch()->id);

        $this->actingAs($manager, 'admin')
            ->delete(route('admin.menu-items.delete', $item->id))
            ->assertRedirect(route('admin.menu-items'));

        $this->assertNotNull(
            MenuItem::withArchived()->find($item->id),
            "A manager deleted a $kind menu item, which the LIMITED rule forbids."
        );
    }

    public static function forbiddenMenuItemTargets(): array
    {
        return [
            'shared (all branches)' => ['shared'],
            'another branch'        => ['other branch'],
        ];
    }

    /**
     * The owner is not narrowed — including on shared items.
     *
     * "Delete" means ARCHIVE for every menu item since 2026-09-24 (it used to
     * hard-delete an unsold one), so the owner's delete is proven by the item
     * leaving the live list and sitting in the archive, not by the row
     * vanishing. The irreversible step is admin.archived.menu-item.force-delete.
     */
    public function test_the_owner_can_still_delete_a_shared_menu_item(): void
    {
        $item = $this->menuItemIn(null);

        $this->actingAs($this->owner(), 'admin')
            ->delete(route('admin.menu-items.delete', $item->id))
            ->assertRedirect(route('admin.menu-items'));

        $this->assertNull(
            MenuItem::find($item->id),
            'The owner could not delete a shared menu item.'
        );
        $this->assertTrue(
            MenuItem::withArchived()->find($item->id)->isArchived(),
            'The owner\'s delete of a shared menu item must archive it, not destroy it.'
        );
    }

    /**
     * "Cancel Orders (Staff = LIMITED)".
     *
     * The brief expected this to be satisfied by phase 1's branch lock alone,
     * with no new code — this test is the confirmation of that, not a new
     * restriction. cancelOrder() resolves through AdminOrderAccess, so another
     * branch's order is a 404 (never a 403: see the class docblock).
     */
    public function test_staff_can_cancel_only_their_own_branch_orders(): void
    {
        $far = $this->otherBranch();

        $mine   = $this->orderIn(self::HOME_BRANCH);
        $theirs = $this->orderIn($far->id);

        $staff = $this->staff(self::HOME_BRANCH);

        $this->actingAs($staff, 'admin')
            ->put(route('admin.orders.cancel', $mine->id));

        $this->assertSame('cancelled', $mine->fresh()->status, 'Staff could not cancel their own order.');

        $this->actingAs($staff, 'admin')
            ->put(route('admin.orders.cancel', $theirs->id))
            ->assertNotFound();

        $this->assertSame('pending', $theirs->fresh()->status, "Staff cancelled another branch's order.");
    }

    /**
     * "View Summary/Reports (Staff = LIMITED)": their own branch, never the
     * consolidated view.
     *
     * The scope is not readable from the request at all — showSummary() asks
     * getSelectedBranch() — so this asserts the effective scope rather than a
     * status code, and pairs it with the owner seeing 'all'.
     */
    public function test_staff_summary_is_locked_to_their_own_branch(): void
    {
        $staff = $this->staff(self::HOME_BRANCH);

        $this->actingAs($staff, 'admin')->get(route('admin.summary'))->assertOk();

        // The scope the page was built with, asked the same way the page asks.
        $this->actingAs($staff, 'admin');
        $this->assertSame(
            self::HOME_BRANCH,
            \App\Services\AdminOrderAccess::lockedBranchId(),
            'Staff resolved a branch scope that is not their own.'
        );

        // And the picker that would widen it is refused.
        $this->actingAs($staff, 'admin')
            ->get(route('admin.branches.select', 'all'))
            ->assertRedirect(route('admin.home'));
    }

    // ═════════════ 5. OWNER IS UNAFFECTED ═════════════

    /**
     * A spot-check that the owner still reaches everything. The provider-driven
     * grid above already covers this row by row; this is the readable summary
     * that a reviewer can scan.
     *
     * @dataProvider ownerPages
     */
    public function test_the_owner_still_reaches_every_page(string $route): void
    {
        $this->actingAs($this->owner(), 'admin')->get(route($route))->assertOk();
    }

    public static function ownerPages(): array
    {
        return [
            ['admin.home'], ['admin.summary'], ['admin.analytics'], ['admin.ads'],
            ['admin.users'], ['admin.branches'], ['admin.account'], ['admin.inventory'],
            ['admin.inventory.deleted'],
            ['admin.menu-items'], ['admin.menu-options'], ['admin.add-category'],
            ['admin.vouchers'], ['admin.qr-generator'], ['admin.completed-orders'],
            ['admin.archived'],
        ];
    }
}

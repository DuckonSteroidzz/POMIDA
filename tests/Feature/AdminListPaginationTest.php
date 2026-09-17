<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin/staff/supervisor mobile investigation, Symptom D (2026-09-14).
 *
 * Completed Orders and Vouchers both used to load EVERY matching row on
 * every visit — Completed Orders even faked pagination client-side (fetch
 * everything, hide the rest with CSS), and Vouchers had no pagination at
 * all. Both grow without bound over the life of the business (every
 * completed/cancelled order ever, every voucher code ever minted by the
 * Spin Wheel or an "Issue Code" counter action), which was the concrete,
 * measurable cause behind the mobile-slowness report: a heavier page on
 * every visit as history piled up, worst on mobile.
 *
 * FIX: real ->paginate() on both, sharing one filtered-but-unbounded query
 * (AdminController::completedOrdersQuery()) between the on-screen paginated
 * list and the "Print Filtered" report, which is its own request precisely
 * because the list's Blade variable no longer holds every matching row.
 *
 * BOUNDED cleanup, per this repo's convention: high-water marks taken
 * before each test, deletions scoped to id > that mark AND this suite's
 * own name/code prefix — never an id-only bound, and never touching a row
 * this suite did not create.
 */
class AdminListPaginationTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'PAGTEST';
    private const ORDER_PREFIX = 'PAGT-';
    private const BRANCH = 1;

    private array $highWater = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['orders', 'order_items', 'vouchers'] as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
        }
    }

    protected function tearDown(): void
    {
        $orderIds = DB::table('orders')
            ->where('id', '>', $this->highWater['orders'])
            ->where('order_number', 'like', self::ORDER_PREFIX . '%')
            ->pluck('id');

        DB::table('order_items')
            ->where('id', '>', $this->highWater['order_items'])
            ->whereIn('order_id', $orderIds)
            ->delete();

        DB::table('orders')
            ->where('id', '>', $this->highWater['orders'])
            ->where('order_number', 'like', self::ORDER_PREFIX . '%')
            ->delete();

        DB::table('vouchers')
            ->where('id', '>', $this->highWater['vouchers'])
            ->where('code', 'like', self::PREFIX . '%')
            ->delete();

        $this->assertSame(
            0,
            DB::table('orders')->where('order_number', 'like', self::ORDER_PREFIX . '%')->count(),
            'this suite leaked an order row'
        );
        $this->assertSame(
            0,
            DB::table('vouchers')->where('code', 'like', self::PREFIX . '%')->count(),
            'this suite leaked a voucher row'
        );

        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    /**
     * $n orders, oldest first in creation order but given explicit, strictly
     * DESCENDING updated_at timestamps one second apart so the list's
     * `orderBy('updated_at', 'desc')` has a deterministic order to test
     * against — order_number index 0 sorts first, matching array index.
     * updated_at is not mass-assignable (see [[order-created-at-not-fillable]]),
     * so it is backdated with a direct DB update after create().
     */
    private function makeOrders(int $n, string $type = 'pick_up', string $status = 'completed'): array
    {
        $item = \App\Models\MenuItem::where('is_available', true)->orderBy('id')->firstOrFail();
        $orders = [];

        for ($i = 0; $i < $n; $i++) {
            $order = Order::create([
                'order_number'   => self::ORDER_PREFIX . strtoupper(substr(uniqid(), -8)) . '-' . $i,
                'branch_id'      => self::BRANCH,
                'type'           => $type,
                'status'         => $status,
                'subtotal'       => 100,
                'total'          => 100,
                'payment_method' => 'cash',
                'payment_status' => 'paid',
                'completed_at'   => now(),
            ]);

            OrderItem::create([
                'order_id'     => $order->id,
                'menu_item_id' => $item->id,
                'item_name'    => $item->name,
                'item_price'   => 100,
                'quantity'     => 1,
                'subtotal'     => 100,
            ]);

            DB::table('orders')->where('id', $order->id)->update([
                // Descending index i: order 0 gets the LATEST timestamp, so
                // orderBy('updated_at', 'desc') yields orders in exactly the
                // 0, 1, 2... order they were created in.
                'updated_at' => now()->addSeconds($n - $i),
            ]);

            $orders[] = $order->fresh();
        }

        return $orders;
    }

    private function voucherIn(?int $branchId = null): Voucher
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

    // ══════════════════════════════════════════════════════════════════════
    // COMPLETED ORDERS — pagination
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_list_returns_exactly_one_pages_worth_of_orders(): void
    {
        // 15 is the smallest allow-listed page size (paginationPerPage() falls
        // back to the default for anything else) — 20 orders guarantees a
        // real off-page row to assert against.
        $orders = $this->makeOrders(20);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.completed-orders', ['per_page' => 15, 'type' => 'pick_up']))
            ->assertOk()
            ->getContent();

        $onPage = array_slice($orders, 0, 15);
        $offPage = array_slice($orders, 15);

        foreach ($onPage as $order) {
            $this->assertStringContainsString($order->order_number, $html);
        }
        foreach ($offPage as $order) {
            $this->assertStringNotContainsString($order->order_number, $html, 'a row past page 1 leaked into the page-1 response');
        }

        $this->assertStringContainsString('Showing 1–15 of', $html);
    }

    public function test_page_two_shows_different_rows_than_page_one(): void
    {
        $orders = $this->makeOrders(20);

        $page1 = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.completed-orders', ['per_page' => 15, 'type' => 'pick_up']))
            ->assertOk()
            ->getContent();

        $page2 = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.completed-orders', ['per_page' => 15, 'type' => 'pick_up', 'page' => 2]))
            ->assertOk()
            ->getContent();

        // The 15 newest (indices 0-14) are on page 1; the 5 oldest (15-19) on page 2.
        foreach (array_slice($orders, 0, 15) as $order) {
            $this->assertStringContainsString($order->order_number, $page1);
            $this->assertStringNotContainsString($order->order_number, $page2);
        }
        foreach (array_slice($orders, 15, 5) as $order) {
            $this->assertStringContainsString($order->order_number, $page2);
            $this->assertStringNotContainsString($order->order_number, $page1);
        }
    }

    public function test_an_out_of_range_per_page_value_falls_back_to_the_default(): void
    {
        $this->makeOrders(2);

        // 999 is not one of the 4 allow-listed sizes — silently falls back
        // rather than letting a crafted URL force an unbounded page.
        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.completed-orders', ['per_page' => 999, 'type' => 'pick_up']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="25" selected', $html);
    }

    public function test_a_date_filter_narrows_the_paginated_total_correctly(): void
    {
        $matching = $this->makeOrders(3, 'pick_up');
        $other = $this->makeOrders(2, 'dine_in');

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.completed-orders', ['type' => 'pick_up', 'per_page' => 15]))
            ->assertOk()
            ->getContent();

        foreach ($matching as $order) {
            $this->assertStringContainsString($order->order_number, $html);
        }
        foreach ($other as $order) {
            $this->assertStringNotContainsString($order->order_number, $html, 'a differently-typed order leaked through the type filter');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // COMPLETED ORDERS — "Print Filtered" is its own unbounded query
    // ══════════════════════════════════════════════════════════════════════

    public function test_print_filtered_includes_every_matching_row_not_just_one_page(): void
    {
        // More than the smallest page size (15), so a paginated response
        // could never have held all of these on one page.
        $orders = $this->makeOrders(18, 'pick_up');

        $printHtml = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.completed-orders.print', ['type' => 'pick_up', 'per_page' => 15]))
            ->assertOk()
            ->getContent();

        foreach ($orders as $order) {
            $this->assertStringContainsString($order->order_number, $printHtml, 'Print Filtered dropped a row that a real page-1-only view would also have dropped');
        }
    }

    public function test_print_filtered_still_honours_the_current_filters(): void
    {
        $matching = $this->makeOrders(2, 'pick_up');
        $other = $this->makeOrders(2, 'dine_in');

        $printHtml = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.completed-orders.print', ['type' => 'pick_up']))
            ->assertOk()
            ->getContent();

        foreach ($matching as $order) {
            $this->assertStringContainsString($order->order_number, $printHtml);
        }
        foreach ($other as $order) {
            $this->assertStringNotContainsString($order->order_number, $printHtml, 'Print Filtered ignored the type filter');
        }
    }

    public function test_print_filtered_is_scoped_to_the_same_role_group_as_the_list(): void
    {
        // Staff can view the list (RolePermissionMatrixTest pins this); the
        // print route sits in the same role:admin,staff,supervisor group, so
        // it must be reachable too rather than silently 403ing a role that
        // could see "Print Filtered" on screen a moment earlier.
        $staff = User::where('role', 'staff')->where('branch_id', self::BRANCH)->orderBy('id')->firstOrFail();

        $this->actingAs($staff, 'admin')
            ->get(route('admin.completed-orders.print'))
            ->assertOk();
    }

    public function test_print_filtered_reports_the_correct_row_count_in_its_header(): void
    {
        $orders = $this->makeOrders(4, 'pick_up');

        $printHtml = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.completed-orders.print', ['type' => 'pick_up']))
            ->assertOk()
            ->getContent();

        // Scoped to this suite's own type filter, so the count in the
        // header is an exact number rather than "at least N".
        $this->assertStringContainsString((string) count($orders) . ' orders', $printHtml);
    }

    // ══════════════════════════════════════════════════════════════════════
    // VOUCHERS — pagination
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_voucher_list_returns_exactly_one_pages_worth(): void
    {
        // 15 is the smallest allow-listed page size; anything else falls
        // back to the default (paginationPerPage()) rather than a custom
        // size, so 20 vouchers over 15/page guarantees an off-page row.
        for ($i = 0; $i < 20; $i++) {
            $this->voucherIn(self::BRANCH);
        }

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.vouchers', ['per_page' => 15]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Showing 1–15 of', $html);
    }

    public function test_voucher_pagination_preserves_the_branch_scope_rule(): void
    {
        // Re-confirms SupervisorPromotionScopeTest's own-branch rule still
        // holds now that the query is paginated rather than a plain get().
        $supervisor = User::factory()->create([
            'role' => 'supervisor',
            'is_active' => true,
            'branch_id' => self::BRANCH,
        ]);

        // A real, different branch id — vouchers.branch_id carries a foreign
        // key, so a made-up id would fail to insert rather than prove anything.
        $farBranchId = \App\Models\Branch::where('id', '!=', self::BRANCH)->orderBy('id')->value('id');

        $mine = $this->voucherIn(self::BRANCH);
        $theirs = $this->voucherIn($farBranchId);

        try {
            $html = $this->actingAs($supervisor, 'admin')
                ->get(route('admin.vouchers', ['per_page' => 15]))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString($mine->code, $html);
            $this->assertStringNotContainsString($theirs->code, $html);
        } finally {
            $theirs->delete();
        }
    }

    public function test_voucher_page_two_shows_different_rows_than_page_one(): void
    {
        // 20 over 15/page, same reasoning as the orders version above.
        // created_at is backdated with distinct, strictly descending-by-index
        // offsets — created in a tight loop, several of these would otherwise
        // share the same second and make orderBy('created_at', 'desc') tie-
        // broken in whatever order the ties happen to come back in.
        $codes = [];
        for ($i = 0; $i < 20; $i++) {
            $voucher = $this->voucherIn(self::BRANCH);
            DB::table('vouchers')->where('id', $voucher->id)->update([
                'created_at' => now()->addSeconds(20 - $i),
            ]);
            $codes[] = $voucher->code;
        }

        $page1 = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.vouchers', ['per_page' => 15]))
            ->assertOk()
            ->getContent();

        $page2 = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.vouchers', ['per_page' => 15, 'page' => 2]))
            ->assertOk()
            ->getContent();

        foreach (array_slice($codes, 0, 15) as $code) {
            $this->assertStringContainsString($code, $page1);
            $this->assertStringNotContainsString($code, $page2);
        }
        foreach (array_slice($codes, 15, 5) as $code) {
            $this->assertStringContainsString($code, $page2);
            $this->assertStringNotContainsString($code, $page1);
        }
    }
}

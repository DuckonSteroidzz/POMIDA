<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Analytics page — branch scoping + the Sept 2026 redesign to a simplified
 * dashboard (Finding #7).
 *
 * BEFORE this pass: the page had no branch selector of any kind. A
 * branch-locked supervisor's KPIs/best-sellers WERE already scoped by
 * AnalyticsService($selectedBranch) via ResolvesBranchScope::getSelectedBranch(),
 * but the "Branch Performance" table was deliberately NOT branch-scoped and
 * showed every branch's sales to whoever was looking, including a locked
 * supervisor — and the admin had no dropdown to narrow "All Branches" because
 * admin.analytics was missing from admin.layout's $showBranchDropdown
 * whitelist that every other branch-scoped admin page already used.
 *
 * This suite proves the fix: the branch bar (with its admin-only dropdown)
 * now appears on Analytics, every remaining widget is computed from
 * AnalyticsService($selectedBranch) — which was already branch-safe — and the
 * cross-branch table that was the actual leak is gone along with the rest of
 * the removed widgets.
 */
class AnalyticsBranchScopeAndRedesignTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'ANALYTICSSCOPE';
    private const ORDER_PREFIX = 'ANLS-';

    private array $highWater = [];

    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['branches', 'menu_items', 'orders', 'order_items', 'users'] as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
        }

        $this->branchA = Branch::create([
            'name'      => self::PREFIX . ' Branch A ' . uniqid(),
            'code'      => 'ANA' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address A',
            'is_active' => true,
        ]);

        $this->branchB = Branch::create([
            'name'      => self::PREFIX . ' Branch B ' . uniqid(),
            'code'      => 'ANB' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address B',
            'is_active' => true,
        ]);
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

        DB::table('menu_items')
            ->where('id', '>', $this->highWater['menu_items'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        DB::table('users')
            ->where('id', '>', $this->highWater['users'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        DB::table('branches')
            ->where('id', '>', $this->highWater['branches'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        $this->assertSame(0, DB::table('orders')->where('order_number', 'like', self::ORDER_PREFIX . '%')->count());
        $this->assertSame(0, DB::table('menu_items')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('users')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('branches')->where('name', 'like', self::PREFIX . '%')->count());

        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function supervisor(Branch $branch): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Supervisor ' . uniqid(),
            'email'     => 'analyticsscope-' . uniqid() . '@example.test',
            'password'  => bcrypt('Sup3rStr0ng!Pass'),
            'role'      => 'supervisor',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    private function branchlessSupervisor(): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Branchless Supervisor ' . uniqid(),
            'email'     => 'analyticsscope-' . uniqid() . '@example.test',
            'password'  => bcrypt('Sup3rStr0ng!Pass'),
            'role'      => 'supervisor',
            'branch_id' => null,
            'is_active' => true,
        ]);
    }

    private function menuItem(Branch $branch, string $label, float $price): MenuItem
    {
        return MenuItem::create([
            'category_id'   => Category::value('id'),
            'branch_id'     => $branch->id,
            'name'          => self::PREFIX . ' ' . $label,
            'price'         => $price,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    private function completedOrder(Branch $branch, MenuItem $item, int $qty, float $price, string $completedAt): Order
    {
        $total = $price * $qty;

        $order = Order::create([
            'order_number' => self::ORDER_PREFIX . strtoupper(substr(uniqid(), -8)),
            'branch_id'    => $branch->id,
            'type'         => 'walk_in',
            'status'       => 'completed',
            'subtotal'     => $total,
            'total'        => $total,
            'completed_at' => $completedAt,
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'menu_item_id' => $item->id,
            'item_name'    => $item->name,
            'item_price'   => $price,
            'quantity'     => $qty,
            'subtotal'     => $total,
        ]);

        return $order;
    }

    private function peso(float $amount): string
    {
        return '₱' . number_format($amount, 2);
    }

    // ══════════════════════════════════════════════════════════════════════
    // "Always pair refusal with acceptance": a locked supervisor's own branch
    // data is correctly SHOWN, another branch's data is correctly EXCLUDED
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_branch_locked_supervisor_sees_only_their_own_branch_sales(): void
    {
        $itemA = $this->menuItem($this->branchA, 'Cake A', 500.0);
        $itemB = $this->menuItem($this->branchB, 'Cake B', 300.0);

        $this->completedOrder($this->branchA, $itemA, 2, 500.0, now()->subDay()->toDateTimeString());
        $this->completedOrder($this->branchB, $itemB, 3, 300.0, now()->subDay()->toDateTimeString());

        $supervisor = $this->supervisor($this->branchA);

        $html = $this->actingAs($supervisor, 'admin')
            ->get(route('admin.analytics', ['period' => 'last30']))
            ->assertOk()
            ->getContent();

        // Branch A's sale (₱1000.00) is shown; Branch B's (₱900.00) is not.
        $this->assertStringContainsString($this->peso(1000.00), $html);
        $this->assertStringNotContainsString($this->peso(900.00), $html);

        // No dropdown for a locked role — they never get to ask for another branch.
        $this->assertStringNotContainsString('name="branch_id"', $html);
    }

    public function test_a_branch_locked_supervisor_never_sees_the_admin_branch_dropdown(): void
    {
        $supervisor = $this->supervisor($this->branchA);

        $html = $this->actingAs($supervisor, 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Viewing:', $html);
        $this->assertStringNotContainsString('<select name="branch_id"', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Admin: "All Branches" aggregates correctly, selecting one branch scopes
    // correctly — the dropdown itself is the fix for the missing selector
    // ══════════════════════════════════════════════════════════════════════

    public function test_admin_sees_the_new_branch_selector_dropdown(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<select name="branch_id"', $html);
        $this->assertStringContainsString('All Branches', $html);
        $this->assertStringContainsString('value="' . $this->branchA->id . '"', $html);
        $this->assertStringContainsString('value="' . $this->branchB->id . '"', $html);
    }

    /** The "Total Sales" KPI tile's peso value, exactly as rendered. */
    private function totalSalesFromHtml(string $html): float
    {
        $this->assertMatchesRegularExpression(
            '/Total Sales<\/p>\s*<p[^>]*>₱([\d,]+\.\d{2})/',
            $html,
            'could not find the Total Sales KPI tile'
        );
        preg_match('/Total Sales<\/p>\s*<p[^>]*>₱([\d,]+\.\d{2})/', $html, $m);

        return (float) str_replace(',', '', $m[1]);
    }

    public function test_admin_all_branches_aggregates_every_branch(): void
    {
        $itemA = $this->menuItem($this->branchA, 'Cake A', 500.0);
        $itemB = $this->menuItem($this->branchB, 'Cake B', 300.0);

        $this->completedOrder($this->branchA, $itemA, 2, 500.0, now()->subDay()->toDateTimeString());
        $this->completedOrder($this->branchB, $itemB, 3, 300.0, now()->subDay()->toDateTimeString());

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get(route('admin.analytics', ['period' => 'last30']))
            ->assertOk()
            ->getContent();

        // "All Branches" must equal the RAW sum of every completed order in the
        // window, across every branch in the database — not hardcoded to our
        // two fixture branches, since a live database already carries other
        // branches' sales in the same last-30-days window and a literal-total
        // assertion would be a false negative against real data.
        $expected = (float) DB::table('orders')
            ->where('status', 'completed')
            ->whereBetween('completed_at', [now()->subDays(29)->startOfDay(), now()->endOfDay()])
            ->sum('total');

        $this->assertSame(round($expected, 2), $this->totalSalesFromHtml($html));

        // And our two fixture branches' ₱1900 are genuinely INSIDE that total,
        // not coincidentally matching it — proving this run's aggregate is not
        // scoped to just one of them.
        $this->assertGreaterThanOrEqual(1900.00, $expected);
    }

    public function test_admin_selecting_one_branch_scopes_every_metric_to_it(): void
    {
        $itemA = $this->menuItem($this->branchA, 'Cake A', 500.0);
        $itemB = $this->menuItem($this->branchB, 'Cake B', 300.0);

        $this->completedOrder($this->branchA, $itemA, 2, 500.0, now()->subDay()->toDateTimeString());
        $this->completedOrder($this->branchB, $itemB, 3, 300.0, now()->subDay()->toDateTimeString());

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics', ['period' => 'last30']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->peso(1000.00), $html);
        $this->assertStringNotContainsString($this->peso(900.00), $html);
        $this->assertStringNotContainsString($this->peso(1900.00), $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Branchless-supervisor sentinel — branch_id NULL resolves to the
    // unreachable-by-design branch id 0, never Main Branch (id 1) by omission
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_branchless_supervisor_sees_an_empty_portal_not_branch_one(): void
    {
        $mainBranch = Branch::orderBy('id')->firstOrFail();
        $item = $this->menuItem($mainBranch, 'Main Branch Cake', 500.0);
        $this->completedOrder($mainBranch, $item, 5, 500.0, now()->subDay()->toDateTimeString());

        $supervisor = $this->branchlessSupervisor();

        $html = $this->actingAs($supervisor, 'admin')
            ->get(route('admin.analytics', ['period' => 'last30']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->peso(0.00), $html);
        $this->assertStringNotContainsString($this->peso(2500.00), $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Date-range presets compute the correct window
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_today_preset_excludes_a_sale_from_yesterday(): void
    {
        $item = $this->menuItem($this->branchA, 'Cake A', 500.0);
        $this->completedOrder($this->branchA, $item, 1, 500.0, now()->subDay()->toDateTimeString());

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics', ['period' => 'today']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->peso(0.00), $html);
        $this->assertStringNotContainsString($this->peso(500.00), $html);
    }

    public function test_the_last_7_days_preset_excludes_a_sale_from_ten_days_ago(): void
    {
        $item = $this->menuItem($this->branchA, 'Cake A', 500.0);
        $this->completedOrder($this->branchA, $item, 1, 500.0, now()->subDays(10)->toDateTimeString());

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics', ['period' => 'last7']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->peso(0.00), $html);
        $this->assertStringNotContainsString($this->peso(500.00), $html);
    }

    public function test_the_custom_range_preset_uses_the_exact_requested_bounds(): void
    {
        $item = $this->menuItem($this->branchA, 'Cake A', 500.0);
        $this->completedOrder($this->branchA, $item, 1, 500.0, '2026-08-05 10:00:00');
        // Outside the custom window below.
        $this->completedOrder($this->branchA, $item, 1, 500.0, '2026-08-10 10:00:00');

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics', [
                'period'    => 'custom',
                'date_from' => '2026-08-04',
                'date_to'   => '2026-08-06',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->peso(500.00), $html);
        $this->assertStringNotContainsString($this->peso(1000.00), $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Print / Export mirrors the screen — same route pattern as
    // admin.completed-orders.print, same figures as the screen it printed from
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_print_route_reports_the_same_total_as_the_screen(): void
    {
        $item = $this->menuItem($this->branchA, 'Cake A', 500.0);
        $this->completedOrder($this->branchA, $item, 2, 500.0, now()->subDay()->toDateTimeString());

        $params = ['period' => 'last30'];

        $screen = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics', $params))
            ->assertOk()
            ->getContent();

        $print = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics.print', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->peso(1000.00), $screen);
        $this->assertStringContainsString($this->peso(1000.00), $print);
    }

    public function test_the_print_route_is_branch_locked_for_a_supervisor_exactly_like_the_screen(): void
    {
        $itemA = $this->menuItem($this->branchA, 'Cake A', 500.0);
        $itemB = $this->menuItem($this->branchB, 'Cake B', 300.0);
        $this->completedOrder($this->branchA, $itemA, 1, 500.0, now()->subDay()->toDateTimeString());
        $this->completedOrder($this->branchB, $itemB, 1, 300.0, now()->subDay()->toDateTimeString());

        $supervisor = $this->supervisor($this->branchA);

        $print = $this->actingAs($supervisor, 'admin')
            ->get(route('admin.analytics.print', ['period' => 'last30']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->peso(500.00), $print);
        $this->assertStringNotContainsString($this->peso(300.00), $print);
    }

    // ══════════════════════════════════════════════════════════════════════
    // The removed widgets are actually gone
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_removed_widgets_no_longer_render(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->getContent();

        // WHAT STAYS REMOVED, and why each one is still here.
        //
        // 'Branch Performance' and 'Sales per Branch' are the load-bearing two:
        // they are the cross-branch comparison the Phase 3 audit removed as a
        // disclosure risk, and nothing added since — Phase 2d's insights
        // included — may bring either back. The rest are the Sept 2026
        // redesign's own removals.
        //
        // 'Inventory Risk' LEFT this list in Phase 2d, deliberately. What was
        // removed in the redesign was a widget of that name; what exists now is
        // a KPI tile counting the Out of Stock + Critical rows of
        // AnalyticsIntelligenceService, computed from the branch-scoped result
        // the rest of the page renders. The name came back because the phase
        // specified it; the old implementation did not, and 'Branch
        // Performance' / 'Sales per Branch' above are what actually guard
        // against the leak the original removal was about.
        //
        // 'Analytics-Based Recommendations' stays forbidden and still passes:
        // Phase 2d's card is "Analytics Insights & Recommendations", a
        // different section built on different data.
        foreach ([
            'Analytics-Based Recommendations',
            'Sales Forecast',
            'Sales by Category',
            'Branch Performance',
            'Least Sellers',
            'Sales per Branch',
        ] as $removed) {
            $this->assertStringNotContainsString($removed, $html, "\"{$removed}\" should have been removed from the redesigned page.");
        }

        // What replaced them is present.
        $this->assertStringContainsString('Total Sales', $html);
        $this->assertStringContainsString('Total Orders', $html);
        $this->assertStringContainsString('Average Order Value', $html);
        $this->assertStringContainsString('Average Rating', $html);
        // Phase 2c renamed this card: the "Sales per Day" bar chart became the
        // "Sales Trend & Forecast" line chart, actual history solid and the
        // moving-average projection dashed. Still exactly ONE main graph — the
        // property this block guards — so the assertion moves to the new title
        // rather than being dropped.
        //
        // Note this does NOT relax the 'Sales Forecast' removal asserted above:
        // that was the old Simple Linear Regression card, which remains gone.
        $this->assertStringContainsString('Sales Trend &amp; Forecast', $html);
        $this->assertStringNotContainsString('salesPerDayChart', $html);
        $this->assertSame(1, substr_count($html, '<canvas'), 'Analytics must have exactly one graph.');
        // Phase 2d replaced the "Top 5 Products (by quantity sold)" card with
        // "Menu Performance". The old card aggregated its own revenue out of
        // order_items.subtotal while Summary reported revenue net of discounts
        // — the Phase 1 "two screens, two revenues" finding. Every money column
        // in its replacement comes from ProfitCalculationService instead. One
        // table, not two: a second menu table beside it would be exactly the
        // overcrowding the redesign exists to prevent.
        $this->assertStringContainsString('Menu Performance', $html);
        $this->assertStringNotContainsString('Top 5 Products', $html);
        $this->assertStringContainsString('Print', $html);
    }
}

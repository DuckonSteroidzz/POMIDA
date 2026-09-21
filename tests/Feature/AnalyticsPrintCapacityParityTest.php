<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\User;
use App\Services\ProductionCapacityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PHASE 2b.1 — Analytics print parity for Menu Production Capacity.
 *
 * Phase 2b (aa0b74a) added the live Menu Production Capacity card to
 * admin.analytics but never carried it onto admin.analytics.print, so the
 * printed sheet a supervisor hands to the owner was missing the one section
 * that answers "what can we still make right now". This suite proves the
 * follow-up: the print view calls the SAME ProductionCapacityService the
 * screen calls (no duplicated arithmetic), labels the section as a CURRENT
 * snapshot distinct from the historical report period, and inherits the
 * same branch-scope guarantees as the rest of the print route.
 *
 * Every row created here carries the ANPCP prefix, the file runs in
 * DatabaseTransactions, and tearDown() sweeps anything that outlived the
 * transaction by high-water mark, mirroring ProductionCapacityTest and
 * AnalyticsBranchScopeAndRedesignTest.
 */
class AnalyticsPrintCapacityParityTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'ANPCP';

    private array $highWater = [];

    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['branches', 'inventory', 'menu_items', 'menu_item_ingredients', 'users'] as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
        }

        $this->branchA = $this->branch('A');
        $this->branchB = $this->branch('B');
    }

    protected function tearDown(): void
    {
        $menuItemIds = DB::table('menu_items')
            ->where('id', '>', $this->highWater['menu_items'])
            ->where('name', 'like', self::PREFIX . '%')
            ->pluck('id');

        DB::table('menu_item_ingredients')->whereIn('menu_item_id', $menuItemIds)->delete();
        DB::table('menu_items')->whereIn('id', $menuItemIds)->delete();

        DB::table('inventory')
            ->where('id', '>', $this->highWater['inventory'])
            ->where('item_name', 'like', self::PREFIX . '%')
            ->delete();

        DB::table('users')
            ->where('id', '>', $this->highWater['users'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        DB::table('branches')
            ->where('id', '>', $this->highWater['branches'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        $this->assertSame(0, DB::table('menu_items')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('inventory')->where('item_name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('branches')->where('name', 'like', self::PREFIX . '%')->count());

        parent::tearDown();
    }

    // ══════════════════════════ fixtures ══════════════════════════

    private function branch(string $label): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Branch ' . $label . ' ' . uniqid(),
            'code'      => 'APC' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address ' . $label,
            'is_active' => true,
        ]);
    }

    private function inventory(Branch $branch, string $name, float $qty, string $unit = 'g'): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branch->id,
            'item_name'       => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'item_code'       => 'APC-' . strtoupper(substr(uniqid(), -9)),
            'category'        => self::PREFIX,
            'quantity'        => $qty,
            'unit'            => $unit,
            'low_stock_alert' => 1,
            'unit_cost'       => 2,
            'is_active'       => true,
        ]);
    }

    /** @param array<int, array{0: Inventory, 1: float}> $recipe */
    private function menuItem(Branch $branch, string $name, array $recipe = [], float $price = 100): MenuItem
    {
        $item = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $branch->id,
            'name'          => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'price'         => $price,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        foreach ($recipe as [$inv, $qty]) {
            MenuItemIngredient::create([
                'menu_item_id'  => $item->id,
                'inventory_id'  => $inv->id,
                'quantity_used' => $qty,
            ]);
        }

        return $item;
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function supervisor(Branch $branch): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Supervisor ' . uniqid(),
            'email'     => 'anpcp-' . uniqid() . '@example.test',
            'password'  => bcrypt('Sup3rStr0ng!Pass'),
            'role'      => 'supervisor',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    /** The number rendered in the live screen's Menu Production Capacity td for one item. */
    private function screenCapacityFor(string $html, string $itemName): ?string
    {
        $pattern = '/<span>' . preg_quote($itemName, '/') . '<\/span>.*?color:#F4845F;">\s*([\d,]+|—|&mdash;)\s*<\/td>/s';

        return preg_match($pattern, $html, $m) ? trim($m[1]) : null;
    }

    /** The number rendered in the print sheet's Current Production Capacity td for one item. */
    private function printCapacityFor(string $html, string $itemName): ?string
    {
        $pattern = '/<td>\s*' . preg_quote($itemName, '/') . '.*?an-cap-num">\s*([\d,]+|—)\s*<\/td>/s';

        return preg_match($pattern, $html, $m) ? trim($m[1]) : null;
    }

    /** The bottleneck ingredient name in the print sheet's row for one item. */
    private function printBottleneckFor(string $html, string $itemName): ?string
    {
        $pattern = '/<td>\s*' . preg_quote($itemName, '/') . '.*?an-cap-bottleneck">([^<]*)<\/td>/s';

        return preg_match($pattern, $html, $m) ? trim($m[1]) : null;
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 1 — the print route includes the capacity section at all
    // ══════════════════════════════════════════════════════════════════════

    public function test_analytics_print_includes_production_capacity_section(): void
    {
        $flour = $this->inventory($this->branchA, 'Flour', 3000);
        $cake = $this->menuItem($this->branchA, 'Snapshot Cake', [[$flour, 150]]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics.print', ['period' => 'last30']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Current Production Capacity', $html);
        $this->assertStringContainsString('Snapshot as of', $html);
        $this->assertStringContainsString($cake->name, $html);
        $this->assertNotNull($this->printCapacityFor($html, $cake->name));
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 2 + 3 — printed capacity and bottleneck match the live screen,
    // both sourced from the same ProductionCapacityService call
    // ══════════════════════════════════════════════════════════════════════

    public function test_printed_capacity_and_bottleneck_match_the_live_screen(): void
    {
        // Flour supports 3000/150 = 20; Sugar supports 500/100 = 5 — sugar is
        // the deliberately tighter ingredient, so it must be reported as the
        // bottleneck on both pages.
        $flour = $this->inventory($this->branchA, 'Flour', 3000);
        $sugar = $this->inventory($this->branchA, 'Sugar', 500);
        $cake = $this->menuItem($this->branchA, 'Parity Cake', [[$flour, 150], [$sugar, 100]]);

        $admin = $this->admin();
        $params = ['period' => 'last30'];

        $screen = $this->actingAs($admin, 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics', $params))
            ->assertOk()
            ->getContent();

        $print = $this->actingAs($admin, 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics.print', $params))
            ->assertOk()
            ->getContent();

        $expected = app(ProductionCapacityService::class)->forMenuItem($cake->fresh(), $this->branchA->id);

        $this->assertSame('5', (string) $expected['capacity']);
        $this->assertStringContainsString('Sugar', $expected['bottleneck_name']);

        $screenCapacity = $this->screenCapacityFor($screen, $cake->name);
        $printCapacity = $this->printCapacityFor($print, $cake->name);

        $this->assertNotNull($screenCapacity, 'could not find the capacity cell on the live screen');
        $this->assertNotNull($printCapacity, 'could not find the capacity cell on the print sheet');
        $this->assertSame('5', $screenCapacity);
        $this->assertSame($screenCapacity, $printCapacity);

        $printBottleneck = $this->printBottleneckFor($print, $cake->name);
        $this->assertNotNull($printBottleneck);
        $this->assertStringContainsString('Sugar', $printBottleneck);
        $this->assertStringContainsString($expected['bottleneck_name'], $printBottleneck);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 4 — an item with no recipe stays "No Recipe Set" on the print sheet
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_menu_item_with_no_recipe_is_printed_as_no_recipe_set(): void
    {
        $bare = $this->menuItem($this->branchA, 'Bare Item', []);

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics.print', ['period' => 'last30']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<td>\s*' . preg_quote($bare->name, '/') . '.*?an-cap-badge-none">No Recipe Set<\/span>/s',
            $html
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 5 + 6 — branch scope: a locked supervisor sees only their own
    // branch's capacity rows, and cannot widen scope via the querystring
    // ══════════════════════════════════════════════════════════════════════

    public function test_print_capacity_is_scoped_to_the_selected_branch(): void
    {
        $flourA = $this->inventory($this->branchA, 'Flour', 3000);
        $flourB = $this->inventory($this->branchB, 'Flour', 3000);
        $itemA = $this->menuItem($this->branchA, 'Branch A Cake', [[$flourA, 150]]);
        $itemB = $this->menuItem($this->branchB, 'Branch B Cake', [[$flourB, 150]]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics.print', ['period' => 'last30']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($itemA->name, $html);
        $this->assertStringNotContainsString($itemB->name, $html);
    }

    public function test_a_branch_locked_supervisor_cannot_widen_print_capacity_scope_via_querystring(): void
    {
        $flourA = $this->inventory($this->branchA, 'Flour', 3000);
        $flourB = $this->inventory($this->branchB, 'Flour', 3000);
        $itemA = $this->menuItem($this->branchA, 'Locked Branch Cake', [[$flourA, 150]]);
        $itemB = $this->menuItem($this->branchB, 'Other Branch Cake', [[$flourB, 150]]);

        $supervisor = $this->supervisor($this->branchA);

        // Attempt to widen scope: neither a forged branch_id querystring nor
        // 'all' should escape the session-locked branch. getSelectedBranch()
        // never reads either from an untrusted request parameter.
        $html = $this->actingAs($supervisor, 'admin')
            ->get(route('admin.analytics.print', [
                'period'    => 'last30',
                'branch_id' => $this->branchB->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($itemA->name, $html);
        $this->assertStringNotContainsString($itemB->name, $html);

        $htmlAll = $this->actingAs($supervisor, 'admin')
            ->get(route('admin.analytics.print', [
                'period'     => 'last30',
                'branch_id'  => 'all',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($itemA->name, $htmlAll);
        $this->assertStringNotContainsString($itemB->name, $htmlAll);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 7 — the historical date-range picker never moves the live
    // capacity snapshot on the print sheet
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_historical_period_does_not_change_the_printed_capacity_snapshot(): void
    {
        $flour = $this->inventory($this->branchA, 'Flour', 1500);
        $cake = $this->menuItem($this->branchA, 'Time Invariant Cake', [[$flour, 150]]);

        $todayHtml = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics.print', ['period' => 'today']))
            ->assertOk()
            ->getContent();

        $last30Html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics.print', ['period' => 'last30']))
            ->assertOk()
            ->getContent();

        $monthHtml = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics.print', ['period' => 'month']))
            ->assertOk()
            ->getContent();

        $today = $this->printCapacityFor($todayHtml, $cake->name);
        $last30 = $this->printCapacityFor($last30Html, $cake->name);
        $month = $this->printCapacityFor($monthHtml, $cake->name);

        $this->assertSame('10', $today);
        $this->assertSame($today, $last30);
        $this->assertSame($today, $month);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 8 — the print sheet stays functional when there is no capacity
    // data to show (a branch with no available menu items at all)
    // ══════════════════════════════════════════════════════════════════════

    public function test_print_remains_functional_with_no_capacity_data(): void
    {
        // branchB has no menu items in this suite's fixtures at all here —
        // an empty forBranchScope() result, exactly like a brand-new branch.
        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchB->id])
            ->get(route('admin.analytics.print', ['period' => 'last30']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Current Production Capacity', $html);
        $this->assertStringContainsString('No available menu items in this branch yet.', $html);
    }
}

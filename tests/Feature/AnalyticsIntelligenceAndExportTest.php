<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\AnalyticsIntelligenceService;
use App\Services\DemandForecastService;
use App\Services\ProductionCapacityService;
use App\Services\ProfitCalculationService;
use App\Support\Csv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PHASE 2d — forecast-vs-capacity risk, inventory intelligence, automated
 * insights and the Analytics CSV export.
 *
 * What this suite is actually guarding, beyond "the feature works":
 *
 *  1. INSUFFICIENT IS NEVER ZERO. The single most damaging thing this page
 *     could do is turn "we don't know" into "there is no risk". Several tests
 *     below exist only to prove a fabricated 0 does not appear — in the
 *     shortage column, in the coverage column, or in the CSV.
 *
 *  2. THE RISK LADDER IS DETERMINISTIC. Six states, evaluated top to bottom,
 *     first match wins. The precedence tests prove that an out-of-stock item
 *     in a branch with no sales history reports OUT OF STOCK rather than being
 *     masked as INSUFFICIENT DATA — which is the live shape of Branches 1-3.
 *
 *  3. ONE CALCULATION, THREE RENDERINGS. Screen, print and CSV all come from
 *     AdminController::analyticsContext(). The parity tests compare the
 *     exported figures against the services directly, so a second calculation
 *     creeping into any of the three would fail here.
 *
 *  4. NO CROSS-BRANCH LEAK, including through the new surfaces. The export and
 *     the insight sentences are both new places a branch name or stock level
 *     could escape, and both are tested against a forged querystring.
 *
 * Fixtures carry the AN2D prefix, run in DatabaseTransactions, and tearDown()
 * sweeps by high-water mark — the same pattern as ProductionCapacityTest and
 * AnalyticsPrintCapacityParityTest, for the same reason: this suite runs
 * against pomida_db_testing, which is a persistent database with pre-existing
 * rows, not a freshly migrated one.
 */
class AnalyticsIntelligenceAndExportTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'AN2D';
    private const ORDER_PREFIX = 'AN2D-';

    private array $highWater = [];

    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['branches', 'inventory', 'menu_items', 'menu_item_ingredients', 'users', 'orders', 'order_items'] as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
        }

        $this->branchA = $this->branch('A');
        $this->branchB = $this->branch('B');
    }

    protected function tearDown(): void
    {
        $orderIds = DB::table('orders')
            ->where('id', '>', $this->highWater['orders'])
            ->where('order_number', 'like', self::ORDER_PREFIX . '%')
            ->pluck('id');

        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('orders')->whereIn('id', $orderIds)->delete();

        $menuItemIds = DB::table('menu_items')
            ->where('id', '>', $this->highWater['menu_items'])
            ->where('name', 'like', '%' . self::PREFIX . '%')
            ->pluck('id');

        DB::table('menu_item_ingredients')->whereIn('menu_item_id', $menuItemIds)->delete();
        DB::table('menu_items')->whereIn('id', $menuItemIds)->delete();

        DB::table('inventory')
            ->where('id', '>', $this->highWater['inventory'])
            ->where('item_name', 'like', '%' . self::PREFIX . '%')
            ->delete();

        DB::table('users')
            ->where('id', '>', $this->highWater['users'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        DB::table('branches')
            ->where('id', '>', $this->highWater['branches'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        // Verified deletion, not assumed: a leaked fixture would corrupt every
        // later run of this suite against the same persistent database.
        $this->assertSame(0, DB::table('menu_items')->where('name', 'like', '%' . self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('inventory')->where('item_name', 'like', '%' . self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('branches')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('orders')->where('order_number', 'like', self::ORDER_PREFIX . '%')->count());

        parent::tearDown();
    }

    // ══════════════════════════ fixtures ══════════════════════════

    private function branch(string $label): Branch
    {
        return Branch::create([
            'name'      => self::PREFIX . ' Branch ' . $label . ' ' . uniqid(),
            'code'      => 'A2D' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address ' . $label,
            'is_active' => true,
        ]);
    }

    private function inventory(Branch $branch, string $name, float $qty, string $unit = 'g'): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branch->id,
            'item_name'       => self::PREFIX . ' ' . $name . ' ' . uniqid(),
            'item_code'       => 'A2D-' . strtoupper(substr(uniqid(), -9)),
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

    /**
     * One completed sale, on a given calendar day.
     *
     * ingredient_cost is written explicitly so COGS comes from the historical
     * snapshot the way a real completed order's does, rather than falling
     * through to today's prices — the fallback is a different code path and
     * these tests are not measuring it.
     */
    private function sale(Branch $branch, MenuItem $item, int $qty, string $day, float $unitCost = 10.0): Order
    {
        $total = (float) $item->price * $qty;

        $order = Order::create([
            'order_number' => self::ORDER_PREFIX . strtoupper(substr(uniqid(), -8)),
            'branch_id'    => $branch->id,
            'type'         => 'walk_in',
            'status'       => 'completed',
            'subtotal'     => $total,
            'total'        => $total,
            'completed_at' => $day . ' 12:00:00',
        ]);

        OrderItem::create([
            'order_id'        => $order->id,
            'menu_item_id'    => $item->id,
            'item_name'       => $item->name,
            'item_price'      => $item->price,
            'quantity'        => $qty,
            'subtotal'        => $total,
            'ingredient_cost' => $unitCost,
        ]);

        return $order;
    }

    /**
     * Sales on N consecutive days ending on $lastDay — enough history for the
     * moving average to be willing to forecast.
     */
    private function salesRun(Branch $branch, MenuItem $item, int $qtyPerDay, int $days, string $lastDay): void
    {
        $cursor = \Carbon\Carbon::parse($lastDay)->subDays($days - 1);

        for ($i = 0; $i < $days; $i++) {
            $this->sale($branch, $item, $qtyPerDay, $cursor->toDateString());
            $cursor->addDay();
        }
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function supervisor(Branch $branch): User
    {
        return User::create([
            'name'      => self::PREFIX . ' Supervisor ' . uniqid(),
            'email'     => 'an2d-' . uniqid() . '@example.test',
            'password'  => bcrypt('Sup3rStr0ng!Pass'),
            'role'      => 'supervisor',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    /** The window the fixtures sell in, and the range every request asks for. */
    private const LAST_DAY = '2026-09-20';
    private const FIRST_DAY = '2026-08-22';

    private function rangeParams(): array
    {
        return ['period' => 'custom', 'date_from' => self::FIRST_DAY, 'date_to' => self::LAST_DAY];
    }

    /** The intelligence result for one branch, built exactly as the controller builds it. */
    private function intelFor($branchScope): array
    {
        $start = \Carbon\Carbon::parse(self::FIRST_DAY)->startOfDay();
        $end   = \Carbon\Carbon::parse(self::LAST_DAY)->endOfDay();

        $capacity = app(ProductionCapacityService::class)->forBranchScope($branchScope);
        $forecast = app(DemandForecastService::class)
            ->forMenuItems($branchScope, $start, $end, PHP_INT_MAX);
        $profit = app(ProfitCalculationService::class)->forRange($start, $end, $branchScope);

        return app(AnalyticsIntelligenceService::class)
            ->build($branchScope, $capacity, $forecast, $profit);
    }

    /** One menu row out of an intelligence result. */
    private function rowFor(array $intel, MenuItem $item): ?array
    {
        foreach ($intel['rows'] as $row) {
            if ($row['menu_item_id'] === $item->id) {
                return $row;
            }
        }

        return null;
    }

    /** One ingredient row out of an intelligence result. */
    private function ingredientFor(array $intel, Inventory $inv): ?array
    {
        foreach ($intel['ingredients'] as $ing) {
            if ($ing['inventory_id'] === $inv->id) {
                return $ing;
            }
        }

        return null;
    }

    /** The CSV body as a string, as a browser would receive it. */
    private function csvFor(User $actor, array $session = [], array $params = []): string
    {
        $response = $this->actingAs($actor, 'admin')
            ->withSession($session)
            ->get(route('admin.analytics.export', $params ?: $this->rangeParams()));

        $response->assertOk();

        return $response->streamedContent();
    }

    /** The data row of the menu grid for one item, split into cells. */
    private function csvRowFor(string $csv, string $itemName): ?array
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        while (($cells = fgetcsv($handle)) !== false) {
            if (isset($cells[0]) && trim((string) $cells[0]) === $itemName) {
                fclose($handle);

                return $cells;
            }
        }

        fclose($handle);

        return null;
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 1 + 3 — forecast above capacity produces a potential shortage,
    //              and the shortage is exactly forecast - capacity
    // ══════════════════════════════════════════════════════════════════════

    public function test_forecast_above_capacity_produces_a_potential_shortage(): void
    {
        // 2 units/day sold for a fortnight -> a 7-day projection of 14 units.
        // The pantry supports 40 / 10 = 4 more orders, well under that.
        $flour = $this->inventory($this->branchA, 'Flour', 40);
        $item = $this->menuItem($this->branchA, 'Shortage Cake', [[$flour, 10]]);
        $this->salesRun($this->branchA, $item, 2, 14, self::LAST_DAY);

        $row = $this->rowFor($this->intelFor($this->branchA->id), $item);

        $this->assertNotNull($row);
        $this->assertSame(4, $row['capacity']);
        $this->assertTrue($row['forecast_sufficient'], 'fourteen selling days must be enough to forecast');
        $this->assertTrue($row['has_shortage']);

        // TEST 3 — the formula itself, not merely its sign.
        $this->assertEqualsWithDelta(
            $row['forecast_qty_next_7_days'] - $row['capacity'],
            $row['potential_shortage'],
            0.01,
            'potential shortage must be exactly forecast demand minus current capacity'
        );
        $this->assertEqualsWithDelta(14.0, $row['potential_shortage'] + 4, 0.01);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 2 — capacity at or above the forecast is NOT a shortage of zero
    // ══════════════════════════════════════════════════════════════════════

    public function test_capacity_above_forecast_produces_no_shortage_warning(): void
    {
        // 1 unit/day -> a 7-day projection of 7. Capacity is 100.
        $flour = $this->inventory($this->branchA, 'Flour', 1000);
        $item = $this->menuItem($this->branchA, 'Comfortable Cake', [[$flour, 10]]);
        $this->salesRun($this->branchA, $item, 1, 14, self::LAST_DAY);

        $row = $this->rowFor($this->intelFor($this->branchA->id), $item);

        $this->assertNotNull($row);
        $this->assertSame(100, $row['capacity']);
        $this->assertFalse($row['has_shortage']);

        // The absence of a shortage is reported as an absence — NOT as a
        // shortage of 0 orders, which would read as "exactly break even".
        $this->assertNull($row['potential_shortage']);
        $this->assertSame(AnalyticsIntelligenceService::RISK_GOOD, $row['risk']);

        $csv = $this->csvFor($this->admin(), ['selected_branch_id' => $this->branchA->id]);
        $cells = $this->csvRowFor($csv, $item->name);
        $this->assertNotNull($cells);
        $this->assertContains('None', $cells, 'the CSV must say None, not 0, when there is no shortage');
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 4 — days of coverage is capacity / average daily demand
    // ══════════════════════════════════════════════════════════════════════

    public function test_days_of_coverage_is_capacity_over_average_daily_demand(): void
    {
        // 2/day, capacity 10 -> 5.0 days of coverage.
        $flour = $this->inventory($this->branchA, 'Flour', 100);
        $item = $this->menuItem($this->branchA, 'Coverage Cake', [[$flour, 10]]);
        $this->salesRun($this->branchA, $item, 2, 14, self::LAST_DAY);

        $row = $this->rowFor($this->intelFor($this->branchA->id), $item);

        $this->assertNotNull($row);
        $this->assertSame(10, $row['capacity']);
        $this->assertEqualsWithDelta(2.0, $row['forecast_qty_per_day'], 0.01);
        $this->assertEqualsWithDelta(5.0, $row['coverage_days'], 0.05);
        $this->assertEqualsWithDelta(
            $row['capacity'] / $row['forecast_qty_per_day'],
            $row['coverage_days'],
            0.05
        );

        // Coverage below the 7-day horizon is exactly "a shortage exists", so
        // the two columns cannot contradict each other.
        $this->assertTrue($row['has_shortage']);
        $this->assertSame(AnalyticsIntelligenceService::RISK_LOW, $row['risk']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 5 — zero demand never divides by zero
    // ══════════════════════════════════════════════════════════════════════

    public function test_zero_demand_never_divides_by_zero(): void
    {
        // Stock and a recipe, but not one sale: demand is UNKNOWN, not zero.
        $flour = $this->inventory($this->branchA, 'Flour', 500);
        $item = $this->menuItem($this->branchA, 'Never Sold Cake', [[$flour, 10]]);

        $row = $this->rowFor($this->intelFor($this->branchA->id), $item);

        $this->assertNotNull($row, 'an item that never sold must still appear, with demand unavailable');
        $this->assertSame(50, $row['capacity']);

        // No division happened, and no invented figure took its place.
        $this->assertNull($row['coverage_days']);
        $this->assertNull($row['potential_shortage']);
        $this->assertFalse($row['has_shortage']);
        $this->assertSame(AnalyticsIntelligenceService::COVERAGE_INSUFFICIENT, $row['coverage_reason']);

        // And it is NOT presented as a proven zero-demand item.
        $this->assertSame(AnalyticsIntelligenceService::RISK_INSUFFICIENT_DATA, $row['risk']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 6 — an out-of-stock ingredient is identified, and OUT OF STOCK
    //          outranks INSUFFICIENT DATA in the ladder
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_out_of_stock_ingredient_is_identified_even_with_no_sales_history(): void
    {
        $empty = $this->inventory($this->branchA, 'Empty Sauce', 0);
        $item = $this->menuItem($this->branchA, 'Blocked Cake', [[$empty, 10]]);

        $intel = $this->intelFor($this->branchA->id);
        $row = $this->rowFor($intel, $item);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['capacity']);

        // THE PRECEDENCE THAT MATTERS. This item has no sales history at all,
        // so a ladder that consulted the forecast first would report
        // INSUFFICIENT DATA and hide a shelf that is genuinely empty — the
        // live shape of Branches 1-3.
        $this->assertSame(AnalyticsIntelligenceService::RISK_OUT_OF_STOCK, $row['risk']);
        $this->assertStringContainsString($empty->item_name, $row['risk_reason']);

        $ingredient = $this->ingredientFor($intel, $empty);
        $this->assertNotNull($ingredient);
        $this->assertTrue($ingredient['is_out_of_stock']);
        $this->assertSame(1, $ingredient['menu_items_affected']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 7 — one ingredient, several dependent menu items
    // ══════════════════════════════════════════════════════════════════════

    public function test_one_ingredient_lists_every_menu_item_that_depends_on_it(): void
    {
        $sauce = $this->inventory($this->branchA, 'Shared Sauce', 60);
        $pizza = $this->menuItem($this->branchA, 'Pizza', [[$sauce, 10]]);
        $pasta = $this->menuItem($this->branchA, 'Pasta', [[$sauce, 20]]);
        // A third item that does NOT use it, to prove the reverse relationship
        // is the real recipe link rather than "everything in the branch".
        $flour = $this->inventory($this->branchA, 'Flour', 900);
        $bread = $this->menuItem($this->branchA, 'Bread', [[$flour, 10]]);

        $intel = $this->intelFor($this->branchA->id);
        $ingredient = $this->ingredientFor($intel, $sauce);

        $this->assertNotNull($ingredient);
        $this->assertSame(2, $ingredient['menu_items_affected']);

        $names = array_column($ingredient['affected_menu_items'], 'menu_item_name');
        $this->assertContains($pizza->name, $names);
        $this->assertContains($pasta->name, $names);
        $this->assertNotContains($bread->name, $names);

        // Each affected item carries its OWN recipe requirement, not a shared one.
        $byName = array_column($ingredient['affected_menu_items'], null, 'menu_item_name');
        $this->assertEqualsWithDelta(10.0, (float) $byName[$pizza->name]['required_per_unit'], 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $byName[$pasta->name]['required_per_unit'], 0.001);

        // And it is limiting BOTH of them, so the bottleneck insight can say so.
        $this->assertSame(2, $ingredient['bottleneck_for']);
    }

    public function test_ingredient_daily_usage_sums_each_dependent_items_own_demand(): void
    {
        // Pizza: 3/day x 10g = 30g/day. Pasta: 1/day x 20g = 20g/day.
        // Combined 50g/day — each item counted once, never one item three times.
        $sauce = $this->inventory($this->branchA, 'Usage Sauce', 500);
        $pizza = $this->menuItem($this->branchA, 'Usage Pizza', [[$sauce, 10]]);
        $pasta = $this->menuItem($this->branchA, 'Usage Pasta', [[$sauce, 20]]);

        $this->salesRun($this->branchA, $pizza, 3, 14, self::LAST_DAY);
        $this->salesRun($this->branchA, $pasta, 1, 14, self::LAST_DAY);

        $ingredient = $this->ingredientFor($this->intelFor($this->branchA->id), $sauce);

        $this->assertNotNull($ingredient);
        $this->assertSame(2, $ingredient['menu_items_forecastable']);
        $this->assertFalse($ingredient['usage_is_partial']);
        $this->assertEqualsWithDelta(50.0, $ingredient['average_daily_usage'], 0.01);
        $this->assertEqualsWithDelta(10.0, $ingredient['days_of_stock'], 0.1);
    }

    public function test_partial_ingredient_usage_is_flagged_rather_than_presented_as_complete(): void
    {
        // Only one of the two items that use this ingredient has any sales, so
        // the usage figure is a FLOOR. It must say so rather than read as the
        // whole consumption rate.
        $sauce = $this->inventory($this->branchA, 'Partial Sauce', 500);
        $sold = $this->menuItem($this->branchA, 'Partial Sold', [[$sauce, 10]]);
        $this->menuItem($this->branchA, 'Partial Unsold', [[$sauce, 10]]);

        $this->salesRun($this->branchA, $sold, 2, 14, self::LAST_DAY);

        $ingredient = $this->ingredientFor($this->intelFor($this->branchA->id), $sauce);

        $this->assertNotNull($ingredient);
        $this->assertSame(2, $ingredient['menu_items_affected']);
        $this->assertSame(1, $ingredient['menu_items_forecastable']);
        $this->assertTrue($ingredient['usage_is_partial']);
        $this->assertEqualsWithDelta(20.0, $ingredient['average_daily_usage'], 0.01);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 8 — the bottleneck is the SAME one ProductionCapacityService names
    // ══════════════════════════════════════════════════════════════════════

    public function test_bottleneck_parity_with_production_capacity_service(): void
    {
        // Flour supports 3000/150 = 20; Sugar supports 500/100 = 5.
        $flour = $this->inventory($this->branchA, 'Flour', 3000);
        $sugar = $this->inventory($this->branchA, 'Sugar', 500);
        $item = $this->menuItem($this->branchA, 'Parity Cake', [[$flour, 150], [$sugar, 100]]);

        $expected = app(ProductionCapacityService::class)
            ->forMenuItem($item->fresh(), $this->branchA->id);

        $this->assertSame(5, $expected['capacity']);
        $this->assertSame($sugar->item_name, $expected['bottleneck_name']);

        // Joined result — same bottleneck, not a second ARGMIN.
        $row = $this->rowFor($this->intelFor($this->branchA->id), $item);
        $this->assertSame($expected['bottleneck_name'], $row['bottleneck_name']);
        $this->assertSame($expected['bottleneck_inventory_id'], $row['bottleneck_inventory_id']);
        $this->assertSame($expected['capacity'], $row['capacity']);

        $admin = $this->admin();
        $session = ['selected_branch_id' => $this->branchA->id];

        // Screen, print and CSV — all three must name the same ingredient.
        $screen = $this->actingAs($admin, 'admin')->withSession($session)
            ->get(route('admin.analytics', $this->rangeParams()))->assertOk()->getContent();
        $print = $this->actingAs($admin, 'admin')->withSession($session)
            ->get(route('admin.analytics.print', $this->rangeParams()))->assertOk()->getContent();
        $csv = $this->csvFor($admin, $session);

        $this->assertStringContainsString($expected['bottleneck_name'], $screen);
        $this->assertStringContainsString($expected['bottleneck_name'], $print);

        $cells = $this->csvRowFor($csv, $item->name);
        $this->assertNotNull($cells);
        $this->assertContains($expected['bottleneck_name'], $cells);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 9 — an insufficient forecast produces no fake shortage or coverage
    // ══════════════════════════════════════════════════════════════════════

    public function test_insufficient_forecast_produces_no_fake_shortage_or_coverage(): void
    {
        // Two selling days is below DemandForecastService::MIN_DAYS_WITH_SALES,
        // so the item sold but cannot be forecast.
        $flour = $this->inventory($this->branchA, 'Flour', 500);
        $item = $this->menuItem($this->branchA, 'Thin History Cake', [[$flour, 10]]);
        $this->salesRun($this->branchA, $item, 3, 2, self::LAST_DAY);

        $row = $this->rowFor($this->intelFor($this->branchA->id), $item);

        $this->assertNotNull($row);
        $this->assertTrue($row['has_sales'], 'the item did sell, it just did not sell often enough');
        $this->assertFalse($row['forecast_sufficient']);

        $this->assertNull($row['potential_shortage']);
        $this->assertNull($row['coverage_days']);
        $this->assertFalse($row['has_shortage']);
        $this->assertSame(AnalyticsIntelligenceService::RISK_INSUFFICIENT_DATA, $row['risk']);

        // And the same honesty survives into the export: three columns that
        // could each have been written as a confident 0.
        $cells = $this->csvRowFor(
            $this->csvFor($this->admin(), ['selected_branch_id' => $this->branchA->id]),
            $item->name
        );

        $this->assertNotNull($cells);
        $this->assertNotContains('0', $cells, 'no column may be written as a bare zero for an unforecastable item');
        $this->assertContains('Insufficient Data', $cells);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 10 + 11 — strong performer and below-average demand insights
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_highest_selling_item_produces_a_strong_performer_insight(): void
    {
        $flour = $this->inventory($this->branchA, 'Flour', 5000);
        $star = $this->menuItem($this->branchA, 'Star Cake', [[$flour, 10]]);
        $quiet = $this->menuItem($this->branchA, 'Quiet Cake', [[$flour, 10]]);

        $this->salesRun($this->branchA, $star, 12, 14, self::LAST_DAY);
        $this->salesRun($this->branchA, $quiet, 1, 14, self::LAST_DAY);

        $insights = $this->intelFor($this->branchA->id)['insights'];
        $performance = array_values(array_filter($insights, fn ($i) => $i['type'] === 'performance'));

        $this->assertNotEmpty($performance, 'a clear top seller must produce a strong-performer insight');
        $this->assertStringContainsString($star->name, $performance[0]['message']);
        $this->assertStringContainsString('highest quantity sold during the selected period', $performance[0]['message']);

        // Decision support, not an instruction.
        $this->assertStringContainsString('Consider', $performance[0]['message']);
    }

    public function test_a_below_average_seller_produces_a_monitoring_insight(): void
    {
        $flour = $this->inventory($this->branchA, 'Flour', 5000);
        $star = $this->menuItem($this->branchA, 'Busy Cake', [[$flour, 10]]);
        $quiet = $this->menuItem($this->branchA, 'Slow Cake', [[$flour, 10]]);

        $this->salesRun($this->branchA, $star, 12, 14, self::LAST_DAY);
        $this->salesRun($this->branchA, $quiet, 1, 14, self::LAST_DAY);

        $insights = $this->intelFor($this->branchA->id)['insights'];
        $lowDemand = array_values(array_filter($insights, fn ($i) => $i['type'] === 'low_demand'));

        $this->assertNotEmpty($lowDemand);
        $message = $lowDemand[0]['message'];

        $this->assertStringContainsString($quiet->name, $message);
        $this->assertStringContainsString('below the selected-period average', $message);
        $this->assertStringContainsString('Monitor', $message);

        // The rule must NEVER tell the owner to withdraw a product — that is a
        // business decision, not a report's to make.
        foreach ($insights as $insight) {
            $this->assertStringNotContainsStringIgnoringCase('remove this', $insight['message']);
            $this->assertStringNotContainsStringIgnoringCase('discontinue', $insight['message']);
            $this->assertStringNotContainsStringIgnoringCase('buy exactly', $insight['message']);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 12 — the profitability insight quotes ProfitCalculationService
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_profitability_insight_uses_profit_calculation_service_figures(): void
    {
        $flour = $this->inventory($this->branchA, 'Flour', 9000);

        // Sells well, costs 90 of its 100 price -> 10% margin.
        $thin = $this->menuItem($this->branchA, 'Thin Margin Cake', [[$flour, 10]], 100);
        // Sells equally well, costs 10 -> 90% margin, lifting the period average.
        $fat = $this->menuItem($this->branchA, 'Fat Margin Cake', [[$flour, 10]], 100);

        for ($i = 0; $i < 14; $i++) {
            $day = \Carbon\Carbon::parse(self::LAST_DAY)->subDays($i)->toDateString();
            $this->sale($this->branchA, $thin, 5, $day, 90.0);
            $this->sale($this->branchA, $fat, 5, $day, 10.0);
        }

        $start = \Carbon\Carbon::parse(self::FIRST_DAY)->startOfDay();
        $end   = \Carbon\Carbon::parse(self::LAST_DAY)->endOfDay();
        $profit = app(ProfitCalculationService::class)->forRange($start, $end, $this->branchA->id);

        $profitByName = array_column($profit['items'], null, 'name');
        $this->assertArrayHasKey($thin->name, $profitByName);

        $intel = $this->intelFor($this->branchA->id);
        $row = $this->rowFor($intel, $thin);

        // The row's money is the service's money, to the centavo — not a
        // second aggregation that happens to be close.
        $this->assertSame($profitByName[$thin->name]['revenue'], $row['revenue']);
        $this->assertSame($profitByName[$thin->name]['cost'], $row['cogs']);
        $this->assertSame($profitByName[$thin->name]['profit'], $row['gross_profit']);
        $this->assertSame($profitByName[$thin->name]['margin_percent'], $row['margin_percent']);

        $profitability = array_values(array_filter(
            $intel['insights'],
            fn ($i) => $i['type'] === 'profitability'
        ));

        $this->assertNotEmpty($profitability, 'a strong seller below the period margin must be surfaced');
        $this->assertStringContainsString($thin->name, $profitability[0]['message']);
        $this->assertStringContainsString(
            number_format($profitByName[$thin->name]['margin_percent'], 1),
            $profitability[0]['message']
        );
        $this->assertStringContainsString('Consider evaluating', $profitability[0]['message']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 13 — the CSV matches the screen
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_csv_reports_the_same_figures_as_the_analytics_screen(): void
    {
        $flour = $this->inventory($this->branchA, 'Flour', 40);
        $item = $this->menuItem($this->branchA, 'Export Cake', [[$flour, 10]], 100);
        $this->salesRun($this->branchA, $item, 2, 14, self::LAST_DAY);

        $intel = $this->intelFor($this->branchA->id);
        $row = $this->rowFor($intel, $item);
        $this->assertNotNull($row);

        $csv = $this->csvFor($this->admin(), ['selected_branch_id' => $this->branchA->id]);
        $cells = $this->csvRowFor($csv, $item->name);
        $this->assertNotNull($cells, 'the exported grid must carry a row for this item');

        // Financial, capacity and forecast fields, each compared against the
        // service result the screen renders from.
        $this->assertContains((string) $row['quantity_sold'], $cells);
        $this->assertContains(number_format($row['revenue'], 2, '.', ''), $cells);
        $this->assertContains(number_format($row['cogs'], 2, '.', ''), $cells);
        $this->assertContains(number_format($row['gross_profit'], 2, '.', ''), $cells);
        $this->assertContains((string) $row['capacity'], $cells);
        $this->assertContains(number_format($row['forecast_qty_next_7_days'], 2, '.', ''), $cells);
        $this->assertContains(number_format($row['potential_shortage'], 2, '.', ''), $cells);
        $this->assertContains(number_format($row['coverage_days'], 1, '.', ''), $cells);
        $this->assertContains($row['risk_label'], $cells);

        // The period summary block quotes the service's own totals.
        $this->assertStringContainsString(
            '"Net Revenue",' . number_format($intel['financials']['net_revenue'], 2, '.', ''),
            $csv
        );
        $this->assertStringContainsString(
            '"Gross Profit",' . number_format($intel['financials']['gross_profit'], 2, '.', ''),
            $csv
        );

        // The conventions the project's other exports already follow.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'the file must carry a UTF-8 BOM');
        $this->assertStringContainsString('Analytics Report', $csv);
        $this->assertStringContainsString($this->branchA->name, $csv);
        $this->assertStringContainsString('Generated by', $csv);
        $this->assertStringContainsString('SUMMARY', $csv);
    }

    public function test_the_csv_filename_and_headers_describe_the_requested_period(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics.export', $this->rangeParams()));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment;', $disposition);
        $this->assertStringContainsString('.csv', $disposition);
        $this->assertStringContainsString(self::FIRST_DAY, $disposition);
        $this->assertStringContainsString(self::LAST_DAY, $disposition);

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Aug 22, 2026 - Sep 20, 2026', $csv);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 14 — an empty result is still a valid report
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_csv_of_an_empty_branch_is_a_valid_report_not_an_error(): void
    {
        // Branch B has no menu items, no inventory and no sales at all.
        $csv = $this->csvFor($this->admin(), ['selected_branch_id' => $this->branchB->id]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Analytics Report', $csv);
        $this->assertStringContainsString($this->branchB->name, $csv);
        $this->assertStringContainsString('SUMMARY', $csv);
        // fputcsv quotes any label carrying a space, so these assertions are
        // written as the file's own bytes rather than as the bare label.
        $this->assertStringContainsString('"Menu Items Reported",0', $csv);
        $this->assertStringContainsString('"Items Requiring Attention (Out of Stock + Critical)",0', $csv);

        // "No shortage was calculable" is written as None, not as 0.00.
        $this->assertStringContainsString('"Total Potential Shortage (orders)",None', $csv);
        $this->assertStringContainsString('No recipe ingredients are tracked for this branch.', $csv);

        // The header block is still complete, so an emailed empty file still
        // says what it is a report of.
        $this->assertStringContainsString('Period,', $csv);
        $this->assertStringContainsString('Generated by', $csv);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 15 + 18 — CSV and insight branch authorisation
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_branch_locked_supervisor_cannot_export_another_branchs_data(): void
    {
        $aFlour = $this->inventory($this->branchA, 'A Flour', 500);
        $aCake = $this->menuItem($this->branchA, 'A Cake', [[$aFlour, 10]]);
        $this->salesRun($this->branchA, $aCake, 2, 14, self::LAST_DAY);

        $bFlour = $this->inventory($this->branchB, 'B Flour', 500);
        $bCake = $this->menuItem($this->branchB, 'B Cake', [[$bFlour, 10]]);
        $this->salesRun($this->branchB, $bCake, 9, 14, self::LAST_DAY);

        $supervisorB = $this->supervisor($this->branchB);

        // Every shape of forgery the querystring and the session allow, tried
        // at once: none of them may widen the scope by a single row.
        $csv = $this->csvFor($supervisorB, [
            'selected_branch_id' => $this->branchA->id,
        ], $this->rangeParams() + [
            'branch_id'          => $this->branchA->id,
            'branch'             => $this->branchA->id,
            'scope'              => 'all',
            'selected_branch_id' => $this->branchA->id,
        ]);

        // Paired refusal and acceptance: B's own data IS present…
        $this->assertStringContainsString($bCake->name, $csv);
        // …and nothing of A's is, by any name.
        $this->assertStringNotContainsString($aCake->name, $csv);
        $this->assertStringNotContainsString($aFlour->item_name, $csv);
        $this->assertStringNotContainsString($this->branchA->name, $csv);
        $this->assertStringContainsString($this->branchB->name, $csv);
    }

    public function test_a_branch_locked_supervisor_never_receives_another_branchs_insight(): void
    {
        // A dramatic, insight-triggering condition in branch A: an ingredient
        // with nothing left, blocking a menu item.
        $aEmpty = $this->inventory($this->branchA, 'A Empty Sauce', 0);
        $aCake = $this->menuItem($this->branchA, 'A Blocked Cake', [[$aEmpty, 10]]);

        // Branch B is comfortable and should produce no alarm about A.
        $bFlour = $this->inventory($this->branchB, 'B Flour', 5000);
        $bCake = $this->menuItem($this->branchB, 'B Fine Cake', [[$bFlour, 10]]);
        $this->salesRun($this->branchB, $bCake, 1, 14, self::LAST_DAY);

        $html = $this->actingAs($this->supervisor($this->branchB), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics', $this->rangeParams()))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Analytics Insights &amp; Recommendations', $html);
        $this->assertStringContainsString($bCake->name, $html);

        $this->assertStringNotContainsString($aCake->name, $html);
        $this->assertStringNotContainsString($aEmpty->item_name, $html);
        $this->assertStringNotContainsString($this->branchA->name, $html);
    }

    public function test_staff_cannot_reach_the_analytics_export_at_all(): void
    {
        $staff = User::create([
            'name'      => self::PREFIX . ' Staff ' . uniqid(),
            'email'     => 'an2d-staff-' . uniqid() . '@example.test',
            'password'  => bcrypt('Sup3rStr0ng!Pass'),
            'role'      => 'staff',
            'branch_id' => $this->branchA->id,
            'is_active' => true,
        ]);

        // The export is gated by the same middleware as the page it exports —
        // it must not be a side door into figures the page itself refuses. The
        // role middleware turns a refusal into a redirect rather than a 403
        // (AdminMiddleware's existing convention), so what is asserted is that
        // BOTH routes refuse, and refuse identically.
        $export = $this->actingAs($staff, 'admin')
            ->get(route('admin.analytics.export', $this->rangeParams()));
        $page = $this->actingAs($staff, 'admin')
            ->get(route('admin.analytics', $this->rangeParams()));

        $export->assertRedirect();
        $page->assertRedirect();
        $this->assertSame($page->headers->get('Location'), $export->headers->get('Location'));

        // And nothing of the report leaked into the refusal itself.
        $this->assertStringNotContainsString('Analytics Report', $export->getContent());
        $this->assertNull($export->headers->get('Content-Disposition'));
    }

    public function test_a_guest_cannot_reach_the_analytics_export(): void
    {
        $this->get(route('admin.analytics.export', $this->rangeParams()))
            ->assertRedirect();
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 16 + 17 — All Branches stays consolidated, with branch distinction
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_branches_consolidates_every_branch_without_comparing_them(): void
    {
        $aFlour = $this->inventory($this->branchA, 'A Flour', 500);
        $aCake = $this->menuItem($this->branchA, 'A Only Cake', [[$aFlour, 10]]);
        $bFlour = $this->inventory($this->branchB, 'B Flour', 500);
        $bCake = $this->menuItem($this->branchB, 'B Only Cake', [[$bFlour, 10]]);

        $csv = $this->csvFor($this->admin(), ['selected_branch_id' => 'all']);

        // Consolidated: both branches' items are present…
        $this->assertStringContainsString($aCake->name, $csv);
        $this->assertStringContainsString($bCake->name, $csv);
        $this->assertStringContainsString('All Branches', $csv);

        // …but the per-branch ranking the Phase 3 audit removed must not have
        // come back with the export.
        $this->assertStringNotContainsString('Branch Performance', $csv);
        $this->assertStringNotContainsString('Sales per Branch', $csv);
        $this->assertStringNotContainsString('below it', $csv);

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get(route('admin.analytics', $this->rangeParams()))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Branch Performance', $html);
        $this->assertStringNotContainsString('Sales per Branch', $html);
    }

    public function test_same_named_menu_items_stay_distinguishable_under_all_branches(): void
    {
        // The same NAME in two branches — the case a consolidated view makes
        // ambiguous unless the branch is carried alongside it.
        $shared = 'Cheesecake ' . uniqid();

        $aFlour = $this->inventory($this->branchA, 'A Flour', 500);
        $bFlour = $this->inventory($this->branchB, 'B Flour', 900);

        $aItem = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $this->branchA->id,
            'name'          => self::PREFIX . ' ' . $shared,
            'price'         => 100,
            'is_available'  => true,
            'display_order' => 0,
        ]);
        MenuItemIngredient::create(['menu_item_id' => $aItem->id, 'inventory_id' => $aFlour->id, 'quantity_used' => 10]);

        $bItem = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $this->branchB->id,
            'name'          => self::PREFIX . ' ' . $shared,
            'price'         => 100,
            'is_available'  => true,
            'display_order' => 0,
        ]);
        MenuItemIngredient::create(['menu_item_id' => $bItem->id, 'inventory_id' => $bFlour->id, 'quantity_used' => 10]);

        $intel = $this->intelFor('all');
        $rowA = $this->rowFor($intel, $aItem);
        $rowB = $this->rowFor($intel, $bItem);

        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $this->assertSame($this->branchA->name, $rowA['branch_name']);
        $this->assertSame($this->branchB->name, $rowB['branch_name']);

        // Two rows with the same name but different capacities: without the
        // branch column the reader cannot tell which shelf either describes.
        $this->assertSame(50, $rowA['capacity']);
        $this->assertSame(90, $rowB['capacity']);

        $csv = $this->csvFor($this->admin(), ['selected_branch_id' => 'all']);
        $this->assertStringContainsString('Branch', $csv);
        $this->assertStringContainsString($this->branchA->name, $csv);
        $this->assertStringContainsString($this->branchB->name, $csv);

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get(route('admin.analytics', $this->rangeParams()))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->branchA->name, $html);
        $this->assertStringContainsString($this->branchB->name, $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 19 — print still works, and carries the new sections
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_print_sheet_still_renders_and_carries_the_new_sections(): void
    {
        $flour = $this->inventory($this->branchA, 'Flour', 40);
        $item = $this->menuItem($this->branchA, 'Printed Cake', [[$flour, 10]]);
        $this->salesRun($this->branchA, $item, 2, 14, self::LAST_DAY);

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics.print', $this->rangeParams()))
            ->assertOk()
            ->getContent();

        // Phase 2b.1's section is preserved exactly, snapshot wording included.
        $this->assertStringContainsString('Current Production Capacity', $html);
        $this->assertStringContainsString('Snapshot as of', $html);

        // Phase 2d's additions are present.
        $this->assertStringContainsString('Forecast Demand', $html);
        $this->assertStringContainsString('Potential Shortage', $html);
        $this->assertStringContainsString('Automated Insights', $html);
        $this->assertStringContainsString('Menu Performance', $html);

        // The three time bases stay distinguishable on paper.
        $this->assertStringContainsString('not the', $html);
        $this->assertStringContainsString('projection for the next', $html);

        // And it is still one document, not a broken render.
        $this->assertStringContainsString('</html>', $html);
        $this->assertStringContainsString('Printed by', $html);
    }

    public function test_the_print_sheet_never_calls_its_output_artificial_intelligence(): void
    {
        $screen = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', $this->rangeParams()))->assertOk()->getContent();
        $print = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics.print', $this->rangeParams()))->assertOk()->getContent();
        $csv = $this->csvFor($this->admin());

        // The application contains no AI or ML model, so none of these three
        // surfaces may claim one. Checked as whole words so "Automated"
        // and the "ai-" CSS class prefix do not trip it.
        foreach (['screen' => $screen, 'print' => $print, 'csv' => $csv] as $label => $content) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bArtificial Intelligence\b/i',
                $content,
                "the {$label} must not describe its output as Artificial Intelligence"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\bAI (Prediction|Recommendation|Insight)/i',
                $content,
                "the {$label} must not brand its output as AI"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST 21 — CSV formula injection
    // ══════════════════════════════════════════════════════════════════════

    /**
     * @dataProvider formulaPayloads
     */
    public function test_a_formula_prefixed_name_is_neutralised_in_the_export(string $payload): void
    {
        // A menu item and an ingredient whose names both BEGIN with a
        // spreadsheet formula trigger — the payload has to come before the
        // fixture prefix, or the name merely contains the character and the
        // guard is never exercised. (tearDown() matches the prefix anywhere in
        // the name for exactly this case.)
        $sauce = Inventory::create([
            'branch_id'       => $this->branchA->id,
            'item_name'       => $payload . self::PREFIX . ' Sauce ' . uniqid(),
            'item_code'       => 'A2D-' . strtoupper(substr(uniqid(), -9)),
            'category'        => self::PREFIX,
            'quantity'        => 500,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 2,
            'is_active'       => true,
        ]);

        $item = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $this->branchA->id,
            'name'          => $payload . self::PREFIX . ' Cake ' . uniqid(),
            'price'         => 100,
            'is_available'  => true,
            'display_order' => 0,
        ]);
        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $sauce->id,
            'quantity_used' => 10,
        ]);

        $csv = $this->csvFor($this->admin(), ['selected_branch_id' => $this->branchA->id]);

        // The names are still there — nothing is silently dropped from the
        // export — but neutralised with a leading single quote, so no cell
        // starts with the trigger character.
        $this->assertStringContainsString("'" . $item->name, $csv);
        $this->assertStringContainsString("'" . $sauce->item_name, $csv);

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $sawItem = false;
        while (($cells = fgetcsv($handle)) !== false) {
            foreach ($cells as $cell) {
                if (str_contains((string) $cell, $item->name) || str_contains((string) $cell, $sauce->item_name)) {
                    $sawItem = true;
                    $this->assertSame(
                        "'",
                        substr((string) $cell, 0, 1),
                        "a cell carrying a user-entered name beginning with {$payload} must be neutralised"
                    );
                }
            }
        }
        fclose($handle);

        $this->assertTrue($sawItem, 'the payload names must actually appear in the export');
    }

    public static function formulaPayloads(): array
    {
        return [
            'equals'  => ['='],
            'plus'    => ['+'],
            'minus'   => ['-'],
            'at'      => ['@'],
        ];
    }

    public function test_the_csv_guard_leaves_ordinary_values_untouched(): void
    {
        // The guard must not corrupt every other cell in the file on its way
        // to catching the rare one.
        $this->assertSame('Pizza Sauce', Csv::cell('Pizza Sauce'));
        $this->assertSame('', Csv::cell(''));
        $this->assertSame(42, Csv::cell(42));
        $this->assertSame(1.5, Csv::cell(1.5));
        $this->assertNull(Csv::cell(null));

        // …and it must catch the tab and carriage-return variants some readers
        // strip before deciding whether a cell is a formula.
        $this->assertSame("'\t=1+1", Csv::cell("\t=1+1"));
        $this->assertSame("'\r=1+1", Csv::cell("\r=1+1"));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Risk ladder — the precedence itself, rung by rung
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_item_with_no_recipe_is_unavailable_not_out_of_stock(): void
    {
        // Rung 1. "We cannot measure this" is a different fact from "there is
        // none of it", and the data-entry job it implies is a different job.
        $item = $this->menuItem($this->branchA, 'Recipeless Cake', []);

        $row = $this->rowFor($this->intelFor($this->branchA->id), $item);

        $this->assertNotNull($row);
        $this->assertFalse($row['is_measurable']);
        $this->assertSame(AnalyticsIntelligenceService::RISK_UNAVAILABLE, $row['risk']);
        $this->assertNull($row['capacity']);
        $this->assertNull($row['coverage_days']);
        $this->assertSame(AnalyticsIntelligenceService::COVERAGE_UNAVAILABLE, $row['coverage_reason']);
    }

    public function test_coverage_below_one_day_is_critical(): void
    {
        // Rung 4. 10/day sold, capacity 5 -> 0.5 days of coverage.
        $flour = $this->inventory($this->branchA, 'Flour', 50);
        $item = $this->menuItem($this->branchA, 'Critical Cake', [[$flour, 10]]);
        $this->salesRun($this->branchA, $item, 10, 14, self::LAST_DAY);

        $row = $this->rowFor($this->intelFor($this->branchA->id), $item);

        $this->assertNotNull($row);
        $this->assertSame(5, $row['capacity']);
        $this->assertEqualsWithDelta(0.5, $row['coverage_days'], 0.05);
        $this->assertSame(AnalyticsIntelligenceService::RISK_CRITICAL, $row['risk']);
        $this->assertTrue($row['has_shortage']);
    }

    public function test_the_existing_low_stock_threshold_prevents_a_contradictory_good(): void
    {
        // The reason the low-capacity floor exists. This item sells so slowly
        // that 2 units of capacity covers 20 days — comfortably past the
        // horizon — yet the Menu Production Capacity table has always badged
        // capacity <= 3 as "Low Stock". Without the floor, one row would read
        // "Low Stock" and "Good" side by side.
        $threshold = (int) config('inventory.low_stock_threshold', 3);

        $flour = $this->inventory($this->branchA, 'Flour', 20);
        $item = $this->menuItem($this->branchA, 'Slow Cake', [[$flour, 10]]);

        // The two bars DemandForecastService applies are deliberately measured
        // over different spans, and this fixture sits between them: at least
        // MIN_DAYS_WITH_SALES selling days anywhere in the RANGE, but a
        // moving average taken over only the last 7 days of it.
        //
        // Five sales early in the period clear the "enough history" bar; one
        // more inside the window gives a 7-day average of 1/7 of a unit a day.
        // Against a capacity of 2 that is 14 days of coverage — twice the
        // horizon, so no shortage exists.
        for ($i = 0; $i < 5; $i++) {
            $this->sale(
                $this->branchA,
                $item,
                1,
                \Carbon\Carbon::parse(self::FIRST_DAY)->addDays($i)->toDateString()
            );
        }
        $this->sale($this->branchA, $item, 1, self::LAST_DAY);

        $row = $this->rowFor($this->intelFor($this->branchA->id), $item);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['capacity']);
        $this->assertLessThanOrEqual($threshold, $row['capacity']);
        $this->assertTrue($row['forecast_sufficient']);

        // Coverage covers the horizon, so there is no shortage…
        $this->assertGreaterThanOrEqual(
            AnalyticsIntelligenceService::COVERAGE_LOW_DAYS,
            $row['coverage_days']
        );
        $this->assertFalse($row['has_shortage']);

        // …but the existing inventory rule still lifts it off GOOD.
        $this->assertSame(AnalyticsIntelligenceService::RISK_LOW, $row['risk']);
        $this->assertStringContainsString('low-stock threshold', $row['risk_reason']);
    }

    public function test_every_row_carries_exactly_one_of_the_six_risk_states(): void
    {
        // A deliberately mixed branch: one of every situation the ladder has a
        // rung for, all present at once, so a row that satisfies two conditions
        // still resolves to exactly one state.
        $empty = $this->inventory($this->branchA, 'Empty', 0);
        $plenty = $this->inventory($this->branchA, 'Plenty', 9000);

        $this->menuItem($this->branchA, 'Ladder No Recipe', []);
        $this->menuItem($this->branchA, 'Ladder Blocked', [[$empty, 10]]);
        $this->menuItem($this->branchA, 'Ladder Unsold', [[$plenty, 10]]);
        $busy = $this->menuItem($this->branchA, 'Ladder Busy', [[$plenty, 10]]);
        $this->salesRun($this->branchA, $busy, 3, 14, self::LAST_DAY);

        $intel = $this->intelFor($this->branchA->id);
        $valid = array_keys(AnalyticsIntelligenceService::RISK_SEVERITY);

        $this->assertNotEmpty($intel['rows']);

        foreach ($intel['rows'] as $row) {
            $this->assertContains($row['risk'], $valid, 'no risk state outside the documented six');
            $this->assertSame(AnalyticsIntelligenceService::RISK_LABELS[$row['risk']], $row['risk_label']);
            $this->assertNotNull($row['risk_reason'], 'every verdict must be able to explain itself');

            // The invariants the ladder guarantees, checked on every row at once.
            if ($row['risk'] === AnalyticsIntelligenceService::RISK_UNAVAILABLE) {
                $this->assertNull($row['capacity'] === null ? null : $row['coverage_days']);
            }
            if (in_array($row['risk'], [
                AnalyticsIntelligenceService::RISK_UNAVAILABLE,
                AnalyticsIntelligenceService::RISK_INSUFFICIENT_DATA,
            ], true)) {
                $this->assertNull($row['potential_shortage'], 'no shortage may be invented without both figures');
                $this->assertNull($row['coverage_days'], 'no coverage may be invented without both figures');
            }
            if ($row['risk'] === AnalyticsIntelligenceService::RISK_GOOD) {
                $this->assertFalse($row['has_shortage'], 'GOOD and a shortage cannot both be true');
            }
        }

        // The KPI counts only the two states that describe a problem NOW.
        $counts = $intel['summary']['risk_counts'];
        $this->assertSame(
            $counts[AnalyticsIntelligenceService::RISK_OUT_OF_STOCK]
                + $counts[AnalyticsIntelligenceService::RISK_CRITICAL],
            $intel['summary']['items_requiring_attention']
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // Insight wording — shortage, inventory, bottleneck, capacity
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_shortage_and_bottleneck_insights_quote_real_figures(): void
    {
        $sauce = $this->inventory($this->branchA, 'Pizza Sauce', 40);
        $pizza = $this->menuItem($this->branchA, 'Insight Pizza', [[$sauce, 10]]);
        $calzone = $this->menuItem($this->branchA, 'Insight Calzone', [[$sauce, 10]]);
        $this->salesRun($this->branchA, $pizza, 5, 14, self::LAST_DAY);

        $intel = $this->intelFor($this->branchA->id);
        $byType = [];
        foreach ($intel['insights'] as $insight) {
            $byType[$insight['type']][] = $insight;
        }

        // SHORTAGE — the number quoted is the row's own potential_shortage.
        $this->assertArrayHasKey('shortage', $byType);
        $row = $this->rowFor($intel, $pizza);
        $this->assertStringContainsString($pizza->name, $byType['shortage'][0]['message']);
        $this->assertStringContainsString(
            rtrim(rtrim(number_format($row['potential_shortage'], 2, '.', ''), '0'), '.'),
            $byType['shortage'][0]['message']
        );
        $this->assertStringContainsString('Consider preparing', $byType['shortage'][0]['message']);

        // BOTTLENECK — the affected count is the real recipe relationship.
        $this->assertArrayHasKey('bottleneck', $byType);
        $this->assertStringContainsString($sauce->item_name, $byType['bottleneck'][0]['message']);
        $this->assertStringContainsString('limiting production for 2 menu items', $byType['bottleneck'][0]['message']);
        $this->assertNotNull($this->rowFor($intel, $calzone));
    }

    public function test_an_ingredient_under_one_day_of_cover_produces_an_inventory_alert(): void
    {
        // 10/day sold, 10g each -> 100g/day. 50g on the shelf is half a day.
        $sauce = $this->inventory($this->branchA, 'Thin Sauce', 50);
        $item = $this->menuItem($this->branchA, 'Thin Cover Cake', [[$sauce, 10]]);
        $this->salesRun($this->branchA, $item, 10, 14, self::LAST_DAY);

        $intel = $this->intelFor($this->branchA->id);
        $ingredient = $this->ingredientFor($intel, $sauce);

        $this->assertNotNull($ingredient);
        $this->assertEqualsWithDelta(100.0, $ingredient['average_daily_usage'], 0.01);
        $this->assertEqualsWithDelta(0.5, $ingredient['days_of_stock'], 0.05);

        $inventoryAlerts = array_values(array_filter($intel['insights'], fn ($i) => $i['type'] === 'inventory'));
        $this->assertNotEmpty($inventoryAlerts);
        $this->assertStringContainsString($sauce->item_name, $inventoryAlerts[0]['message']);
        $this->assertStringContainsString('less than 1 day of projected demand', $inventoryAlerts[0]['message']);
        $this->assertStringContainsString('Consider replenishing', $inventoryAlerts[0]['message']);
    }

    public function test_a_low_capacity_item_produces_a_capacity_insight_with_its_real_number(): void
    {
        $flour = $this->inventory($this->branchA, 'Flour', 20);
        $item = $this->menuItem($this->branchA, 'Nearly Out Cake', [[$flour, 10]]);

        $intel = $this->intelFor($this->branchA->id);
        $capacityAlerts = array_values(array_filter($intel['insights'], fn ($i) => $i['type'] === 'capacity'));

        $this->assertNotEmpty($capacityAlerts);
        $this->assertStringContainsString('approximately 2 additional orders', $capacityAlerts[0]['message']);
        $this->assertStringContainsString($item->name, $capacityAlerts[0]['message']);
        $this->assertStringContainsString('Monitor', $capacityAlerts[0]['message']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // The screen itself
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_analytics_page_renders_the_new_sections_and_still_has_one_graph(): void
    {
        $flour = $this->inventory($this->branchA, 'Flour', 40);
        $item = $this->menuItem($this->branchA, 'Screen Cake', [[$flour, 10]]);
        $this->salesRun($this->branchA, $item, 2, 14, self::LAST_DAY);

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics', $this->rangeParams()))
            ->assertOk()
            ->getContent();

        foreach ([
            'Inventory Risk',
            'Gross Profit',
            'Menu Performance',
            'Inventory Intelligence',
            'Menu Production Capacity',
            'Analytics Insights &amp; Recommendations',
            'Export CSV',
            'Forecasted Demand',
            'Potential Shortage',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, "\"{$expected}\" must be on the page");
        }

        // The Phase 2c rule that survives Phase 2d unchanged: ONE main graph.
        $this->assertSame(1, substr_count($html, '<canvas'), 'Analytics must still have exactly one graph.');
    }

    public function test_the_export_link_carries_the_same_filters_the_page_was_rendered_with(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.analytics', $this->rangeParams()))
            ->assertOk()
            ->getContent();

        // The export must describe the period on screen, so the link is built
        // from the resolved period rather than from the raw querystring.
        $this->assertStringContainsString('date_from=' . self::FIRST_DAY, $html);
        $this->assertStringContainsString('date_to=' . self::LAST_DAY, $html);
        $this->assertStringContainsString('analytics/export', $html);
    }

    /**
     * A rubbish custom range falls back on the export exactly as it does on the
     * screen, because both resolve it through the same
     * AdminController::analyticsContext(). A file that fell back still names
     * the window it actually reported on in its own header block.
     */
    public function test_a_rejected_custom_range_falls_back_identically_on_screen_and_in_the_export(): void
    {
        $bad = ['period' => 'custom', 'date_from' => 'banana', 'date_to' => 'also-banana'];

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', $bad))
            ->assertOk()
            ->getContent();

        $csv = $this->csvFor($this->admin(), [], $bad);

        // Both landed on the default preset — last 30 days.
        $fallbackStart = now()->subDays(29)->startOfDay();
        $fallbackEnd = now()->endOfDay();

        $this->assertStringContainsString($fallbackStart->format('M d, Y'), $csv);
        $this->assertStringContainsString($fallbackEnd->format('M d, Y'), $csv);
        $this->assertStringContainsString($fallbackStart->format('M d'), $html);
    }
}

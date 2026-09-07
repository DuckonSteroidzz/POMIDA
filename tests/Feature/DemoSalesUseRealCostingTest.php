<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\DemoSalesTopUp;
use App\Services\MenuItemCosting;
use App\Services\ProfitCalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fabricated sales must travel the REAL costing path.
 *
 * DemoSalesTopUp exists so the capstone demo cannot rot back to "insufficient
 * data" as the calendar moves. It derives its money honestly — line subtotal =
 * price x qty — but it used to write no cost at all. Every fabricated line
 * therefore reached ProfitCalculationService with a NULL
 * order_items.ingredient_cost and fell down that class's legacy-approximation
 * branch, which exists for rows that predate the column.
 *
 * The consequence was that the COGS, Gross Profit and Margin figures being
 * demonstrated on the Summary screen and the printed report were produced by a
 * code path no real sale ever takes. Demo data is only worth showing if it
 * exercises the logic it is standing in for.
 *
 * So the generator now snapshots each line's cost through MenuItemCosting, the
 * same service that answers "what does this cost to make" everywhere else in
 * the app, and which delegates the recipe walk to the very walker a real
 * deduction uses. Reading a cost moves no stock, so the class's standing
 * promise — fabricated sales never touch real inventory — is untouched, and
 * this suite asserts that too.
 */
class DemoSalesUseRealCostingTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'DEMOCOST';
    private const ORDER_PREFIX = 'DMC-';

    /** MAX(id) per table BEFORE this test ran — the high-water marks. */
    private array $highWater = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'menu_items', 'orders', 'order_items', 'inventory',
            'menu_item_ingredients', 'stock_movements',
        ] as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
        }
    }

    /**
     * BOUNDED cleanup: above this run's high-water mark AND carrying this
     * suite's own marker. The generated demo rows are matched by id and by
     * DemoSalesTopUp::MARKER, so a demo order that already existed before this
     * test ran can never be swept up.
     */
    protected function tearDown(): void
    {
        $generatedIds = DB::table('orders')
            ->where('id', '>', $this->highWater['orders'])
            ->where(function ($q) {
                $q->where('notes', DemoSalesTopUp::MARKER)
                    ->orWhere('order_number', 'like', self::ORDER_PREFIX . '%');
            })
            ->pluck('id');

        $menuItemIds = DB::table('menu_items')
            ->where('id', '>', $this->highWater['menu_items'])
            ->where('name', 'like', self::PREFIX . '%')
            ->pluck('id');

        DB::table('order_items')
            ->where('id', '>', $this->highWater['order_items'])
            ->whereIn('order_id', $generatedIds)
            ->delete();

        DB::table('orders')->whereIn('id', $generatedIds)->delete();

        DB::table('menu_item_ingredients')
            ->where('id', '>', $this->highWater['menu_item_ingredients'])
            ->whereIn('menu_item_id', $menuItemIds)
            ->delete();

        DB::table('menu_items')
            ->where('id', '>', $this->highWater['menu_items'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        DB::table('inventory')
            ->where('id', '>', $this->highWater['inventory'])
            ->where('item_name', 'like', self::PREFIX . '%')
            ->delete();

        $this->assertSame(0, DB::table('orders')->where('order_number', 'like', self::ORDER_PREFIX . '%')->count());
        $this->assertSame(0, DB::table('menu_items')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('inventory')->where('item_name', 'like', self::PREFIX . '%')->count());

        parent::tearDown();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function topUp(): DemoSalesTopUp
    {
        return app(DemoSalesTopUp::class);
    }

    private function costing(): MenuItemCosting
    {
        return app(MenuItemCosting::class);
    }

    /** The orders this test run fabricated, and nothing that came before it. */
    private function generatedOrders()
    {
        return Order::where('id', '>', $this->highWater['orders'])
            ->where('notes', DemoSalesTopUp::MARKER)
            ->with('items.menuItem')
            ->get();
    }

    /** A menu item with a real recipe, priced off real inventory. */
    private function recipeItem(float $price, float $unitCost, float $qtyUsed): MenuItem
    {
        $item = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => 1,
            'name'          => self::PREFIX . ' Item ' . uniqid(),
            'price'         => $price,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        $inventory = Inventory::create([
            'branch_id'       => 1,
            'item_name'       => self::PREFIX . ' Ingredient ' . uniqid(),
            'item_code'       => 'DMC' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => 1000,
            'unit'            => 'pc',
            'low_stock_alert' => 1,
            'unit_cost'       => $unitCost,
            'is_active'       => true,
        ]);

        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $inventory->id,
            'quantity_used' => $qtyUsed,
        ]);

        return $item;
    }

    // ── generation ──────────────────────────────────────────────────────────

    public function test_every_fabricated_line_carries_the_costing_services_own_figure(): void
    {
        $this->topUp()->generateDays([3, 4], 20260906);

        $orders = $this->generatedOrders();

        $this->assertGreaterThan(0, $orders->count(), 'The generator wrote nothing to inspect.');

        $lines = 0;

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $lines++;

                $this->assertNotNull(
                    $item->ingredient_cost,
                    'A fabricated line has no cost snapshot, so it will be costed by the legacy fallback.'
                );

                $this->assertSame(
                    $this->costing()->costFor($item->menuItem),
                    (float) $item->ingredient_cost,
                    'The fabricated cost does not match what MenuItemCosting says the item costs.'
                );

                // Branch 1's catalogue is fully costed, so a zero here would
                // mean the snapshot was written but never actually computed.
                $this->assertGreaterThan(0.0, (float) $item->ingredient_cost);
            }
        }

        $this->assertGreaterThan(0, $lines, 'The generated orders have no lines.');
    }

    /**
     * The headline consequence: the profit figures being demonstrated are now
     * produced by the same branch of the same code a real sale takes.
     */
    public function test_the_report_costs_fabricated_sales_without_falling_back(): void
    {
        $this->topUp()->generateDays([3], 20260906);

        $report = app(ProfitCalculationService::class)
            ->forRange(now()->subDays(3)->startOfDay(), now()->subDays(3)->endOfDay(), 1);

        $this->assertGreaterThan(0.0, $report['gross_revenue'], 'The generated day reports no revenue.');
        $this->assertGreaterThan(0.0, $report['cogs'], 'The generated day reports no cost of goods.');

        $this->assertSame(
            0,
            $report['legacy_fallback_count'],
            'A fabricated sale was costed by the legacy fallback instead of its own snapshot.'
        );
    }

    /**
     * Costing reads inventory; it must never move it. This is the standing
     * promise of the whole class and the reason it may run on a demo machine
     * at all.
     */
    public function test_generation_still_touches_no_real_stock(): void
    {
        $before = DB::table('inventory')->orderBy('id')->pluck('quantity', 'id');

        $this->topUp()->generateDays([3], 20260906);

        $this->assertEquals(
            $before,
            DB::table('inventory')->orderBy('id')->pluck('quantity', 'id'),
            'Fabricated sales moved real inventory.'
        );

        $this->assertSame(
            0,
            DB::table('stock_movements')->where('id', '>', $this->highWater['stock_movements'])->count(),
            'Fabricated sales wrote stock movements.'
        );
    }

    // ── healing the rows written before the snapshot existed ────────────────

    public function test_backfill_gives_an_older_demo_line_a_real_cost_snapshot(): void
    {
        $item = $this->recipeItem(300.0, 40.0, 2.0);
        $line = $this->demoOrderLine($item, DemoSalesTopUp::MARKER);

        $updated = $this->topUp()->backfillCostSnapshots();

        $this->assertGreaterThanOrEqual(1, $updated);

        $this->assertSame(
            $this->costing()->costFor($item),
            (float) $line->fresh()->ingredient_cost
        );
    }

    /**
     * The safety bound. The backfill is a bulk write over order_items; the
     * ONLY thing keeping it away from the café's real sales is the parent
     * order carrying the demo marker.
     */
    public function test_backfill_never_touches_a_real_order(): void
    {
        $item = $this->recipeItem(300.0, 40.0, 2.0);
        $real = $this->demoOrderLine($item, null);

        $this->topUp()->backfillCostSnapshots();

        $this->assertNull(
            $real->fresh()->ingredient_cost,
            'The demo backfill wrote a cost onto a real order.'
        );
    }

    /**
     * One completed order line with NO cost snapshot. $notes decides whether
     * it is a demo row or a real one.
     */
    private function demoOrderLine(MenuItem $item, ?string $notes): OrderItem
    {
        $order = Order::create([
            'order_number' => self::ORDER_PREFIX . strtoupper(substr(uniqid(), -8)),
            'branch_id'    => 1,
            'type'         => 'walk_in',
            'status'       => 'completed',
            'subtotal'     => $item->price,
            'total'        => $item->price,
            'notes'        => $notes,
            'completed_at' => now()->subDays(2),
        ]);

        return OrderItem::create([
            'order_id'        => $order->id,
            'menu_item_id'    => $item->id,
            'item_name'       => $item->name,
            'item_price'      => $item->price,
            'quantity'        => 1,
            'subtotal'        => $item->price,
            'ingredient_cost' => null,
        ]);
    }
}

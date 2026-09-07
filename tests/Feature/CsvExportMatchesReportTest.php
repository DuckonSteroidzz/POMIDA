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
use App\Services\ProfitCalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Export CSV vs the printed report — they must be the SAME document.
 *
 * The Export CSV button sits directly beside Print Report on the Summary page,
 * over the same period selector. An owner who exports and an owner who prints
 * are asking the same question, and the two answers used to differ:
 *
 *   1. The export filtered on orders.created_at while the report scoped on
 *      orders.completed_at. Any order that crossed midnight between being
 *      placed and being paid landed in a different period in each — present in
 *      one document and missing from the other.
 *   2. The export offered Subtotal / Discount / Total and no cost of any kind.
 *      Summing its Total column against the report's Total Revenue came up
 *      short by exactly the discounts given, with nothing in the file to
 *      explain the gap, and nothing in it able to corroborate COGS, Gross
 *      Profit or Margin at all.
 *
 * Both now read through ProfitCalculationService — the export's rows through
 * ordersForRange(), its TOTALS line through the same forRange() the KPI cards
 * and the printed report are built from, both funnelled through that class's
 * one lineItemsQuery(). So the agreement is structural. These tests exist
 * because "structural" is worth nothing unless something fails loudly the day
 * somebody pulls the two apart.
 *
 * Every assertion compares a CSV cell against what the service reports for
 * that same period. None of them hardcodes a peso figure.
 */
class CsvExportMatchesReportTest extends TestCase
{
    use DatabaseTransactions;

    /** Every row this suite creates carries this prefix, for bounded cleanup. */
    private const PREFIX = 'CSVRPT';
    private const ORDER_PREFIX = 'CSV-';

    /** MAX(id) per table BEFORE this test ran — the high-water marks. */
    private array $highWater = [];

    private Branch $branch;

    /** The period under report — fixed, in the past, away from any live "today". */
    private string $from = '2026-07-06';
    private string $to = '2026-07-08';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['branches', 'menu_items', 'orders', 'order_items', 'inventory', 'menu_item_ingredients'] as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
        }

        $this->branch = Branch::create([
            'name'      => self::PREFIX . ' Branch ' . uniqid(),
            'code'      => 'CSV' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address',
            'is_active' => true,
        ]);
    }

    /**
     * BOUNDED cleanup: rows ABOVE this run's high-water mark AND carrying this
     * suite's own name prefix. Never an id-only bound — an id-only delete has
     * already destroyed a live row in this project once.
     */
    protected function tearDown(): void
    {
        $orderIds = DB::table('orders')
            ->where('id', '>', $this->highWater['orders'])
            ->where('order_number', 'like', self::ORDER_PREFIX . '%')
            ->pluck('id');

        $menuItemIds = DB::table('menu_items')
            ->where('id', '>', $this->highWater['menu_items'])
            ->where('name', 'like', self::PREFIX . '%')
            ->pluck('id');

        DB::table('order_items')
            ->where('id', '>', $this->highWater['order_items'])
            ->whereIn('order_id', $orderIds)
            ->delete();

        DB::table('orders')
            ->where('id', '>', $this->highWater['orders'])
            ->where('order_number', 'like', self::ORDER_PREFIX . '%')
            ->delete();

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

        DB::table('branches')
            ->where('id', '>', $this->highWater['branches'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        // Verify zero of this suite's own rows survive the bounded delete.
        $this->assertSame(0, DB::table('orders')->where('order_number', 'like', self::ORDER_PREFIX . '%')->count());
        $this->assertSame(0, DB::table('menu_items')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('inventory')->where('item_name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('branches')->where('name', 'like', self::PREFIX . '%')->count());

        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function menuItem(string $label, float $price): MenuItem
    {
        return MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $this->branch->id,
            'name'          => self::PREFIX . ' ' . $label,
            'price'         => $price,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ]);
    }

    /**
     * A menu item with a REAL recipe, so MenuItemCosting has something to walk.
     * Used for the line deliberately left without a cost snapshot.
     */
    private function menuItemWithRecipe(string $label, float $price, float $unitCost, float $qtyUsed): MenuItem
    {
        $item = $this->menuItem($label, $price);

        $inventory = Inventory::create([
            'branch_id'       => $this->branch->id,
            'item_name'       => self::PREFIX . ' ' . $label . ' ingredient',
            'item_code'       => 'CSV' . strtoupper(substr(uniqid(), -8)),
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

    /**
     * One completed order.
     *
     * created_at and completed_at are set INDEPENDENTLY on purpose: the whole
     * first class of bug here lives in the gap between them.
     *
     * @param  array<int, array{item: MenuItem, qty: int, unit_cost: float|null}>  $lines
     */
    private function completedOrder(
        array $lines,
        string $createdAt,
        string $completedAt,
        float $discount = 0.0
    ): Order {
        $subtotal = collect($lines)->sum(fn ($l) => $l['item']->price * $l['qty']);

        $order = Order::create([
            'order_number'    => self::ORDER_PREFIX . strtoupper(substr(uniqid(), -8)),
            'branch_id'       => $this->branch->id,
            'type'            => 'walk_in',
            'status'          => 'completed',
            'subtotal'        => $subtotal,
            'discount_amount' => $discount,
            'total'           => $subtotal - $discount,
            'payment_method'  => 'cash',
            'completed_at'    => $completedAt,
        ]);

        // created_at is NOT in Order::$fillable, so passing it to create()
        // above would be silently dropped and every fixture order would carry
        // today's date — which would make the two midnight-straddle tests pass
        // for the wrong reason. Write it directly.
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $createdAt]);

        foreach ($lines as $line) {
            OrderItem::create([
                'order_id'        => $order->id,
                'menu_item_id'    => $line['item']->id,
                'item_name'       => $line['item']->name,
                'item_price'      => $line['item']->price,
                'quantity'        => $line['qty'],
                'subtotal'        => $line['item']->price * $line['qty'],
                // NULL here means "no snapshot" — the read-time fallback path.
                'ingredient_cost' => $line['unit_cost'],
            ]);
        }

        return $order;
    }

    /**
     * A trading period with every awkward case in it:
     *
     *  - plain     : an ordinary order, wholly inside the period
     *  - discounted: a PWD/Senior discount, so revenue and money-taken differ
     *  - straddler : PLACED before the period, PAID inside it. The report
     *                counts it; the old export did not.
     *  - spillover : PLACED inside the period, PAID after it. The old export
     *                counted it; the report does not.
     *  - noSnapshot: a line with no cost snapshot, costed at read time from a
     *                real recipe — the one path where report and export could
     *                still diverge if they stopped sharing a cost rule.
     *
     * @return array<string, Order>
     */
    private function seedPeriod(): array
    {
        $cake = $this->menuItem('Cake', 500.0);
        $tart = $this->menuItem('Tart', 300.0);
        $brew = $this->menuItem('Brew', 120.0);
        $recipeItem = $this->menuItemWithRecipe('Recipe Slice', 250.0, 25.0, 2.0);

        return [
            'plain' => $this->completedOrder(
                [
                    ['item' => $cake, 'qty' => 2, 'unit_cost' => 120.0],
                    ['item' => $brew, 'qty' => 3, 'unit_cost' => 30.0],
                ],
                $this->from . ' 10:00:00',
                $this->from . ' 10:20:00'
            ),

            'discounted' => $this->completedOrder(
                [['item' => $tart, 'qty' => 4, 'unit_cost' => 90.0]],
                $this->from . ' 14:00:00',
                $this->from . ' 14:25:00',
                240.0
            ),

            'straddler' => $this->completedOrder(
                [['item' => $cake, 'qty' => 1, 'unit_cost' => 120.0]],
                '2026-07-05 23:50:00',
                $this->from . ' 00:10:00'
            ),

            'spillover' => $this->completedOrder(
                [['item' => $cake, 'qty' => 5, 'unit_cost' => 120.0]],
                $this->to . ' 23:50:00',
                '2026-07-09 00:20:00'
            ),

            'noSnapshot' => $this->completedOrder(
                [['item' => $recipeItem, 'qty' => 3, 'unit_cost' => null]],
                $this->to . ' 09:00:00',
                $this->to . ' 09:30:00'
            ),
        ];
    }

    // ── fetching and parsing the export ─────────────────────────────────────

    /** @return array<int, array<int, string>> every CSV record, BOM stripped */
    private function csv(): array
    {
        $content = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branch->id])
            ->get(route('admin.export.orders', [
                'period'    => 'custom',
                'date_from' => $this->from,
                'date_to'   => $this->to,
            ]))
            ->assertOk()
            ->streamedContent();

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $records = [];
        foreach (preg_split('/\r\n|\n/', trim($content)) as $line) {
            $records[] = str_getcsv($line, ',', '"', '\\');
        }

        return $records;
    }

    /** The header row of the order table, wherever the preamble ends. */
    private function headerIndex(array $records): int
    {
        foreach ($records as $i => $row) {
            if (($row[0] ?? null) === 'Order #') {
                return $i;
            }
        }

        $this->fail('The export has no "Order #" header row.');
    }

    /** @return array<int, array<string, string>> data rows keyed by column name */
    private function orderRows(array $records): array
    {
        $headerAt = $this->headerIndex($records);
        $columns = $records[$headerAt];
        $rows = [];

        foreach (array_slice($records, $headerAt + 1) as $row) {
            $first = $row[0] ?? '';

            // The TOTALS line ends the order table. Everything after it is
            // trailer — today the cost note, tomorrow whatever else the file
            // has to disclose — and none of it is an order.
            if ($first === 'TOTALS') {
                break;
            }

            if ($first === '' || $first === null) {
                continue;
            }

            $rows[] = array_combine($columns, array_pad($row, count($columns), ''));
        }

        return $rows;
    }

    /** @return array<string, string> the TOTALS line, keyed by column name */
    private function totalsRow(array $records): array
    {
        $columns = $records[$this->headerIndex($records)];

        foreach ($records as $row) {
            if (($row[0] ?? null) === 'TOTALS') {
                return array_combine($columns, array_pad($row, count($columns), ''));
            }
        }

        $this->fail('The export has no TOTALS row.');
    }

    /** What the report — the one and only calculation — says about this period. */
    private function report(): array
    {
        return app(ProfitCalculationService::class)->custom($this->from, $this->to, $this->branch->id);
    }

    private function money(?string $cell): float
    {
        return (float) str_replace(',', '', (string) $cell);
    }

    // ── the agreement ───────────────────────────────────────────────────────

    public function test_totals_line_carries_the_reports_own_figures(): void
    {
        $this->seedPeriod();

        $totals = $this->totalsRow($this->csv());
        $report = $this->report();

        $this->assertSame($report['gross_revenue'], $this->money($totals['Gross Revenue']));
        $this->assertSame($report['discounts'], $this->money($totals['Discount']));
        $this->assertSame($report['net_revenue'], $this->money($totals['Net Revenue']));
        $this->assertSame($report['cogs'], $this->money($totals['COGS']));
        $this->assertSame($report['gross_profit'], $this->money($totals['Gross Profit']));
        $this->assertSame($report['margin_percent'], $this->money($totals['Margin % (of Net)']));
    }

    public function test_the_order_rows_add_up_to_the_totals_line(): void
    {
        $this->seedPeriod();

        $rows = $this->orderRows($this->csv());
        $report = $this->report();

        $this->assertSame(
            $report['gross_revenue'],
            round(collect($rows)->sum(fn ($r) => $this->money($r['Gross Revenue'])), 2),
            'The Gross Revenue column does not sum to the report Gross Revenue.'
        );

        $this->assertSame(
            $report['discounts'],
            round(collect($rows)->sum(fn ($r) => $this->money($r['Discount'])), 2),
            'The Discount column does not sum to the report Discounts.'
        );

        $this->assertSame(
            $report['net_revenue'],
            round(collect($rows)->sum(fn ($r) => $this->money($r['Net Revenue'])), 2),
            'The Net Revenue column does not sum to the report Net Revenue.'
        );

        $this->assertSame(
            $report['cogs'],
            round(collect($rows)->sum(fn ($r) => $this->money($r['COGS'])), 2),
            'The COGS column does not sum to the report COGS.'
        );

        $this->assertSame(
            $report['gross_profit'],
            round(collect($rows)->sum(fn ($r) => $this->money($r['Gross Profit'])), 2),
            'The Gross Profit column does not sum to the report Gross Profit.'
        );

        $this->assertCount(
            $report['order_count'],
            $rows,
            'The export lists a different number of orders than the report counted.'
        );
    }

    /**
     * The midnight-straddle bug, in the direction that used to LOSE a sale
     * from the export.
     */
    public function test_an_order_paid_inside_the_period_is_exported_even_if_placed_before_it(): void
    {
        $orders = $this->seedPeriod();

        $numbers = collect($this->orderRows($this->csv()))->pluck('Order #')->all();

        $this->assertContains(
            $orders['straddler']->order_number,
            $numbers,
            'An order completed inside the period is missing from the export.'
        );
    }

    /**
     * ...and in the direction that used to ADD one the report never counted.
     */
    public function test_an_order_paid_after_the_period_is_not_exported(): void
    {
        $orders = $this->seedPeriod();

        $numbers = collect($this->orderRows($this->csv()))->pluck('Order #')->all();

        $this->assertNotContains(
            $orders['spillover']->order_number,
            $numbers,
            'An order completed after the period leaked into the export.'
        );
    }

    /**
     * Revenue and money-taken are different quantities and must be reported
     * under different names. Conflating them is what made the old Total column
     * come up short against the report by exactly the discounts given.
     */
    public function test_a_discount_separates_revenue_from_the_amount_charged(): void
    {
        $orders = $this->seedPeriod();
        $discounted = $orders['discounted'];

        $row = collect($this->orderRows($this->csv()))
            ->firstWhere('Order #', $discounted->order_number);

        $this->assertNotNull($row, 'The discounted order is missing from the export.');

        $this->assertSame(
            (float) $discounted->subtotal,
            $this->money($row['Gross Revenue']),
            'Gross Revenue must be the pre-discount figure.'
        );

        $this->assertSame(
            (float) $discounted->total,
            $this->money($row['Amount Charged']),
            'Amount Charged must be what the customer actually paid.'
        );

        $this->assertSame((float) $discounted->discount_amount, $this->money($row['Discount']));

        // Net Revenue is the derivation; Amount Charged is the till's own
        // record of the same sale. They must land on the same peso.
        $this->assertSame(
            $this->money($row['Amount Charged']),
            $this->money($row['Net Revenue']),
            'Net Revenue and Amount Charged describe the same money and must agree.'
        );
    }

    /**
     * The one remaining place report and export could diverge: a line with no
     * cost snapshot, costed at read time. Both must take the same branch of
     * the same rule.
     */
    public function test_a_line_without_a_cost_snapshot_is_costed_identically_in_both(): void
    {
        $orders = $this->seedPeriod();

        $row = collect($this->orderRows($this->csv()))
            ->firstWhere('Order #', $orders['noSnapshot']->order_number);

        $this->assertNotNull($row);

        // 3 units x (2.0 of an ingredient priced 25.00) — a real recipe walk,
        // not a zero. Proves the fallback actually costed something.
        $this->assertGreaterThan(0.0, $this->money($row['COGS']));

        $reportRow = collect($this->report()['items'])
            ->firstWhere('name', self::PREFIX . ' Recipe Slice');

        $this->assertNotNull($reportRow, 'The report is missing the un-snapshotted item.');
        $this->assertSame($reportRow['cost'], $this->money($row['COGS']));
    }

    /**
     * A file that has been emailed onward must still say what it is a report
     * of — including which date it counts a sale on, since that is precisely
     * what used to differ.
     */
    public function test_the_export_states_its_branch_period_and_basis(): void
    {
        $this->seedPeriod();

        $records = $this->csv();

        $preamble = collect(array_slice($records, 0, $this->headerIndex($records)))
            ->map(fn ($row) => implode(' ', array_map(fn ($c) => (string) $c, $row)))
            ->implode("\n");

        $this->assertStringContainsString($this->branch->name, $preamble);
        $this->assertStringContainsString('Jul 06, 2026', $preamble);
        $this->assertStringContainsString('Jul 08, 2026', $preamble);
        $this->assertStringContainsString('completion date', $preamble);
    }
}

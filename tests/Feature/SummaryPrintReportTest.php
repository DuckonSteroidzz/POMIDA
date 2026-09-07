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
 * Print Report — the printed document must be COMPLETE, and must AGREE with
 * the screen.
 *
 * The Summary screen stays minimal (KPI cards, Top/Least Selling 5). The
 * printed document is the full report: letterhead, KPI summary, period
 * comparison in pesos, EVERY item sold in the period with cost and profit,
 * the out-of-stock items by name, and a printed-by footer.
 *
 * The rule these tests exist to defend: a number on the paper must never
 * disagree with the same number on the screen. That is guaranteed
 * structurally — ProfitCalculationService::summarize() builds the per-item
 * breakdown in the SAME pass that produces the period totals, and the view
 * prints the very variables the KPI cards render — but "guaranteed
 * structurally" is worth nothing unless something fails loudly when the two
 * are pulled apart. Every assertion below compares the printed cell against
 * what the service reports for that same item and period, never against a
 * hardcoded figure.
 *
 * Assertions are isolated to the element under test via data-testid: a bare
 * assertSee() of a peso amount would pass on a coincidental match anywhere
 * else on a page that is full of peso amounts.
 */
class SummaryPrintReportTest extends TestCase
{
    use DatabaseTransactions;

    /** Every row this suite creates carries this prefix, for bounded cleanup. */
    private const PREFIX = 'PRINTRPT';
    private const ORDER_PREFIX = 'PRT-';

    /** MAX(id) per table BEFORE this test ran — the high-water marks. */
    private array $highWater = [];

    private Branch $branch;

    /** The custom period under report — fixed, in the past, and away from any live "today". */
    private string $from = '2026-08-04';
    private string $to = '2026-08-06';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['branches', 'menu_items', 'orders', 'order_items', 'inventory', 'menu_item_ingredients'] as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
        }

        $this->branch = Branch::create([
            'name'      => self::PREFIX . ' Branch ' . uniqid(),
            'code'      => 'PRT' . strtoupper(substr(uniqid(), -6)),
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
        $branchIds = DB::table('branches')
            ->where('id', '>', $this->highWater['branches'])
            ->where('name', 'like', self::PREFIX . '%')
            ->pluck('id');

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

        DB::table('menu_item_ingredients')
            ->where('id', '>', $this->highWater['menu_item_ingredients'])
            ->whereIn('menu_item_id', DB::table('menu_items')
                ->where('id', '>', $this->highWater['menu_items'])
                ->where('name', 'like', self::PREFIX . '%')
                ->pluck('id'))
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
        $this->assertSame(0, DB::table('menu_items')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('inventory')->where('item_name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('orders')->where('order_number', 'like', self::ORDER_PREFIX . '%')->count());
        $this->assertSame(0, DB::table('branches')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, $branchIds->count() - $branchIds->count());

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
     * One completed order inside the reported period.
     *
     * @param  array<int, array{item: MenuItem, qty: int, unit_cost: float}>  $lines
     */
    private function completedOrder(array $lines, string $completedAt): Order
    {
        $total = collect($lines)->sum(fn ($l) => $l['item']->price * $l['qty']);

        $order = Order::create([
            'order_number' => self::ORDER_PREFIX . strtoupper(substr(uniqid(), -8)),
            'branch_id'    => $this->branch->id,
            'type'         => 'walk_in',
            'status'       => 'completed',
            'subtotal'     => $total,
            'total'        => $total,
            'completed_at' => $completedAt,
        ]);

        foreach ($lines as $line) {
            OrderItem::create([
                'order_id'        => $order->id,
                'menu_item_id'    => $line['item']->id,
                'item_name'       => $line['item']->name,
                'item_price'      => $line['item']->price,
                'quantity'        => $line['qty'],
                'subtotal'        => $line['item']->price * $line['qty'],
                'ingredient_cost' => $line['unit_cost'],
            ]);
        }

        return $order;
    }

    /**
     * Seven distinct items sold — deliberately more than the five the SCREEN
     * shows, so "the report fell back to the top 5" is a detectable failure
     * rather than an invisible one.
     *
     * @return array<int, MenuItem>
     */
    private function seedSales(): array
    {
        $items = [];
        foreach ([
            ['Cake A', 500.0, 3, 120.0],
            ['Cake B', 400.0, 4, 100.0],
            ['Cake C', 300.0, 5, 90.0],
            ['Cake D', 250.0, 2, 80.0],
            ['Cake E', 200.0, 6, 70.0],
            ['Cake F', 150.0, 1, 60.0],
            ['Cake G', 120.0, 2, 50.0],
        ] as [$label, $price, $qty, $unitCost]) {
            $item = $this->menuItem($label, $price);
            $items[] = $item;
            $this->completedOrder(
                [['item' => $item, 'qty' => $qty, 'unit_cost' => $unitCost]],
                $this->from . ' 10:00:00'
            );
        }

        // A second order for one item, on another day of the same period, so a
        // correct report must AGGREGATE across orders rather than list lines.
        $this->completedOrder(
            [['item' => $items[0], 'qty' => 2, 'unit_cost' => 120.0]],
            $this->to . ' 15:00:00'
        );

        return $items;
    }

    // ── fetching the report ─────────────────────────────────────────────────

    private function reportHtml(?string $from = null, ?string $to = null): string
    {
        return $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branch->id])
            ->get(route('admin.summary', [
                'period'    => 'custom',
                'date_from' => $from ?? $this->from,
                'date_to'   => $to ?? $this->to,
            ]))
            ->assertOk()
            ->getContent();
    }

    /** What the service — the one and only calculation — says about this period. */
    private function service(?string $from = null, ?string $to = null): array
    {
        return app(ProfitCalculationService::class)->custom(
            $from ?? $this->from,
            $to ?? $this->to,
            $this->branch->id
        );
    }

    // ── HTML extraction, so every assertion is isolated to one element ──────

    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    /** @return array<int, \DOMElement> */
    private function nodes(string $html, string $testId): array
    {
        $found = $this->xpath($html)->query("//*[@data-testid='{$testId}']");

        return $found === false ? [] : iterator_to_array($found);
    }

    private function text(string $html, string $testId): string
    {
        $nodes = $this->nodes($html, $testId);
        $this->assertNotEmpty($nodes, "No element with data-testid=\"{$testId}\" in the report.");

        return $this->normalise($nodes[0]->textContent);
    }

    private function normalise(string $raw): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($raw, ENT_QUOTES, 'UTF-8')));
    }

    /**
     * The printed item table, keyed by item name, each row's cells extracted
     * individually. Nothing here matches on a bare number.
     *
     * @return array<string, array<string, string>>
     */
    private function printedItemRows(string $html): array
    {
        $xp = $this->xpath($html);
        $rows = [];

        // One DOMXPath for the whole table: nodes from a second parse of the
        // same HTML belong to a different document and cannot be used as a
        // context node here.
        $trs = $xp->query("//*[@data-testid='print-item-row']");

        foreach ($trs as $tr) {
            $cells = [];
            foreach (['name', 'qty', 'revenue', 'cost', 'profit', 'margin'] as $field) {
                $cell = $xp->query(".//*[@data-testid='print-item-{$field}']", $tr);
                $this->assertGreaterThan(0, $cell->length, "Row is missing its {$field} cell.");
                $cells[$field] = $this->normalise($cell->item(0)->textContent);
            }
            $rows[$cells['name']] = $cells;
        }

        return $rows;
    }

    private function peso(float $amount): string
    {
        return '₱' . number_format($amount, 2);
    }

    // ══════════════════════════════════════════════════════════════════════
    // EVERY item sold, not the top five
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_printed_item_table_lists_every_item_sold_in_the_period(): void
    {
        $this->seedSales();

        // The expected row count is derived from the underlying order data, not
        // hardcoded: count the distinct menu items on completed orders whose
        // completed_at falls in the reported window for this branch.
        $expected = (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.branch_id', $this->branch->id)
            ->where('orders.status', 'completed')
            ->whereBetween('orders.completed_at', [$this->from . ' 00:00:00', $this->to . ' 23:59:59'])
            ->distinct()
            ->count('order_items.menu_item_id');

        $rows = $this->printedItemRows($this->reportHtml());

        $this->assertSame($expected, count($rows), 'The printed table must list every item sold in the period.');
        $this->assertGreaterThan(5, $expected, 'Fixture must exceed the five items the screen shows, or this proves nothing.');
    }

    public function test_the_item_rows_are_sorted_by_revenue_descending(): void
    {
        $this->seedSales();

        $printedOrder = array_keys($this->printedItemRows($this->reportHtml()));
        $serviceOrder = array_column($this->service()['items'], 'name');

        $this->assertSame($serviceOrder, $printedOrder);

        $revenues = array_column($this->service()['items'], 'revenue');
        $sorted = $revenues;
        rsort($sorted);
        $this->assertSame($sorted, $revenues, 'Rows must read as Top Selling extended to everything.');
    }

    public function test_an_item_sold_across_two_orders_is_aggregated_into_one_row(): void
    {
        $items = $this->seedSales();

        // Cake A was sold 3 on the first day and 2 on the last.
        $rows = $this->printedItemRows($this->reportHtml());
        $this->assertArrayHasKey($items[0]->name, $rows);
        $this->assertSame('5', $rows[$items[0]->name]['qty']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Paper AGREES with the service — the failure mode this pass exists for
    // ══════════════════════════════════════════════════════════════════════

    public function test_every_rows_cost_and_profit_agree_with_the_service(): void
    {
        $this->seedSales();

        $html = $this->reportHtml();
        $rows = $this->printedItemRows($html);
        $service = $this->service();

        $this->assertNotEmpty($service['items']);

        foreach ($service['items'] as $expected) {
            $name = $expected['name'];
            $this->assertArrayHasKey($name, $rows, "\"{$name}\" is missing from the printed table.");

            $this->assertSame(
                number_format($expected['quantity']),
                $rows[$name]['qty'],
                "Printed Qty for \"{$name}\" disagrees with the service."
            );
            $this->assertSame(
                $this->peso($expected['revenue']),
                $rows[$name]['revenue'],
                "Printed Revenue for \"{$name}\" disagrees with the service."
            );
            $this->assertSame(
                $this->peso($expected['cost']),
                $rows[$name]['cost'],
                "Printed Cost for \"{$name}\" disagrees with the service — the paper and the screen would show different costs."
            );
            $this->assertSame(
                $this->peso($expected['profit']),
                $rows[$name]['profit'],
                "Printed Profit for \"{$name}\" disagrees with the service."
            );
            $this->assertSame(
                is_null($expected['margin_percent']) ? '—' : number_format($expected['margin_percent'], 1) . '%',
                $rows[$name]['margin'],
                "Printed Margin for \"{$name}\" disagrees with the service."
            );
        }
    }

    public function test_the_per_item_cost_is_the_stored_cogs_snapshot_not_a_live_recalculation(): void
    {
        // One item whose recipe cost TODAY is wildly different from the cost
        // snapshotted onto the order line when it was completed. The report
        // must print the snapshot, because that is what the screen's COGS and
        // Gross Profit are built from.
        $item = $this->menuItem('Snapshot Cake', 500.0);

        $inventory = Inventory::create([
            'branch_id'       => $this->branch->id,
            'item_name'       => self::PREFIX . ' Ingredient ' . uniqid(),
            'item_code'       => 'PRT-' . strtoupper(substr(uniqid(), -8)),
            'quantity'        => 1000,
            'unit'            => 'g',
            'low_stock_alert' => 1,
            'unit_cost'       => 99,   // today's price, deliberately absurd
            'is_active'       => true,
        ]);
        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $inventory->id,
            'quantity_used' => 10,
        ]);

        $this->completedOrder(
            [['item' => $item, 'qty' => 2, 'unit_cost' => 111.0]],  // the snapshot
            $this->from . ' 09:00:00'
        );

        $rows = $this->printedItemRows($this->reportHtml());

        $this->assertSame($this->peso(222.00), $rows[$item->name]['cost'], 'Cost must be 2 × the 111.00 snapshot.');
        $this->assertSame($this->peso(778.00), $rows[$item->name]['profit']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // The totals row equals the KPI figures on the same report
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_totals_row_equals_the_kpi_figures_on_the_same_report(): void
    {
        $this->seedSales();

        $html = $this->reportHtml();
        $service = $this->service();

        $this->assertSame($this->peso($service['gross_revenue']), $this->text($html, 'print-total-revenue'));
        $this->assertSame($this->peso($service['net_revenue']), $this->text($html, 'print-total-net-revenue'));
        $this->assertSame($this->peso($service['cogs']), $this->text($html, 'print-total-cost'));
        $this->assertSame($this->peso($service['gross_profit']), $this->text($html, 'print-total-profit'));
        $this->assertSame(
            number_format($service['margin_percent'], 1) . '%',
            $this->text($html, 'print-total-margin')
        );

        // …and those same figures are what the KPI cards on this page state.
        $kpi = $this->kpiValues($html);
        $this->assertContains($this->text($html, 'print-total-revenue'), $kpi, 'Totals gross revenue is not on any KPI card.');
        $this->assertContains($this->text($html, 'print-total-net-revenue'), $kpi, 'Totals net revenue is not on any KPI card.');
        $this->assertContains($this->text($html, 'print-total-cost'), $kpi, 'Totals cost is not on any KPI card.');
        $this->assertContains($this->text($html, 'print-total-profit'), $kpi, 'Totals profit is not on any KPI card.');
        $this->assertContains($this->text($html, 'print-total-margin'), $kpi, 'Totals margin is not on any KPI card.');
    }

    /**
     * The printed columns must FOOT to the printed totals row.
     *
     * This is the assertion that catches a per-item figure sourced from a
     * different calculation than the screen's. The totals row prints the KPI
     * figures verbatim, so if any row's cost or profit came from anywhere
     * other than the same pass that produced those KPIs, the column stops
     * adding up to the line beneath it — visibly, on the paper, in front of
     * the panel. Tolerance is one centavo per row, which is what per-row
     * rounding can legitimately cost and nothing more.
     */
    public function test_the_printed_columns_add_up_to_the_printed_totals_row(): void
    {
        $this->seedSales();

        $html = $this->reportHtml();
        $rows = $this->printedItemRows($html);
        $this->assertNotEmpty($rows);

        $unpeso = fn (string $cell) => (float) str_replace([',', '₱'], '', $cell);
        $tolerance = 0.01 * count($rows);

        // The item rows are per-MENU-ITEM and therefore pre-discount: a
        // discount is given on an order, not on a cake. They foot to the
        // "TOTAL (before discounts)" row. The NET TOTAL row beneath it is
        // checked against the service by the test above.
        $footerFor = [
            'revenue' => 'print-total-revenue',
            'cost'    => 'print-total-cost',
            'profit'  => 'print-total-line-profit',
        ];

        foreach (['revenue', 'cost', 'profit'] as $column) {
            $summed = array_sum(array_map($unpeso, array_column($rows, $column)));
            $printedTotal = $unpeso($this->text($html, $footerFor[$column]));

            $this->assertEqualsWithDelta(
                $printedTotal,
                $summed,
                $tolerance,
                "The printed {$column} column does not add up to the printed TOTAL row — "
                . "a per-item figure is coming from a different calculation than the KPI figures."
            );
        }

        // And the printed quantity column foots exactly; quantities never round.
        $qty = array_sum(array_map(
            fn (string $c) => (int) str_replace(',', '', $c),
            array_column($rows, 'qty')
        ));
        $totalsRow = $this->normalise($this->nodes($html, 'print-item-totals')[0]->textContent);
        $this->assertStringContainsString(number_format($qty), $totalsRow);
    }

    /** @return array<int, string> the .kpi-value texts, and nothing else on the page */
    private function kpiValues(string $html): array
    {
        $found = $this->xpath($html)->query("//p[contains(concat(' ', normalize-space(@class), ' '), ' kpi-value ')]");
        $out = [];
        foreach ($found as $node) {
            $out[] = $this->normalise($node->textContent);
        }

        return $out;
    }

    public function test_the_period_comparison_shows_both_periods_as_peso_values(): void
    {
        $this->seedSales();

        // A sale in the immediately preceding window of the same length.
        $prior = $this->menuItem('Prior Cake', 1000.0);
        $this->completedOrder([['item' => $prior, 'qty' => 1, 'unit_cost' => 300.0]], '2026-08-02 10:00:00');

        $html = $this->reportHtml();
        $current = $this->service();
        $previous = $this->service('2026-08-01', '2026-08-03');

        $row = $this->normalise($this->nodes($html, 'print-compare-revenue')[0]->textContent);

        $this->assertStringContainsString($this->peso($current['gross_revenue']), $row);
        $this->assertStringContainsString($this->peso($previous['gross_revenue']), $row);
        $this->assertGreaterThan(0, $previous['gross_revenue'], 'Previous period must be non-empty for this to mean anything.');

        $netRow = $this->normalise($this->nodes($html, 'print-compare-net-revenue')[0]->textContent);
        $this->assertStringContainsString($this->peso($current['net_revenue']), $netRow);
        $this->assertStringContainsString($this->peso($previous['net_revenue']), $netRow);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Letterhead
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_letterhead_states_the_real_branch_and_the_requested_range(): void
    {
        $this->seedSales();
        $html = $this->reportHtml();

        $this->assertSame($this->branch->name, $this->text($html, 'print-branch'));

        $period = $this->text($html, 'print-period');
        $this->assertStringContainsString('Custom Range', $period);
        $this->assertStringContainsString(\Carbon\Carbon::parse($this->from)->format('M d, Y'), $period);
        $this->assertStringContainsString(\Carbon\Carbon::parse($this->to)->format('M d, Y'), $period);

        // Generated stamp is the real clock, not a placeholder.
        $this->assertStringContainsString(now()->format('M d, Y'), $this->text($html, 'print-generated'));

        $this->assertStringContainsString('Sales &amp; Profit Report', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Out of stock, by name
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_out_of_stock_section_lists_the_names_behind_the_kpi_count(): void
    {
        $this->seedSales();

        // Two items the kitchen cannot make: recipe needs more than is on hand.
        $expected = [];
        foreach (['Empty Cake', 'Short Cake'] as $label) {
            $item = $this->menuItem($label, 300.0);
            $inv = Inventory::create([
                'branch_id'       => $this->branch->id,
                'item_name'       => self::PREFIX . ' Ing ' . uniqid(),
                'item_code'       => 'PRT-' . strtoupper(substr(uniqid(), -8)),
                'quantity'        => 1,
                'unit'            => 'g',
                'low_stock_alert' => 1,
                'unit_cost'       => 5,
                'is_active'       => true,
            ]);
            MenuItemIngredient::create([
                'menu_item_id'  => $item->id,
                'inventory_id'  => $inv->id,
                'quantity_used' => 50,   // needs 50, only 1 on hand
            ]);
            $expected[] = $item->name;
        }

        $html = $this->reportHtml();

        $printed = array_map(
            fn (\DOMElement $li) => $this->normalise($li->textContent),
            $this->nodes($html, 'print-oos-item')
        );

        sort($expected);
        $printedSorted = $printed;
        sort($printedSorted);
        $this->assertSame($expected, $printedSorted, 'The printed list must be the real out-of-stock item names.');

        // The KPI count is the size of that same list — count and names agree.
        $countCard = $this->xpath($html)->query(
            "//p[contains(concat(' ', normalize-space(@class), ' '), ' kpi-label ')][contains(text(),'Out-of-Stock')]/following-sibling::p[1]"
        );
        $this->assertGreaterThan(0, $countCard->length);
        $this->assertSame((string) count($printed), $this->normalise($countCard->item(0)->textContent));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Footer
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_footer_names_the_acting_admin_and_the_real_time(): void
    {
        $this->seedSales();

        $footer = $this->text($this->reportHtml(), 'print-footer');

        $this->assertStringContainsString('Printed by ' . $this->admin()->name, $footer);
        $this->assertStringContainsString(now()->format('M d, Y'), $footer);
    }

    public function test_the_footer_names_whoever_actually_asked_for_the_report(): void
    {
        $this->seedSales();

        $other = User::create([
            'name'      => self::PREFIX . ' Acting Admin',
            'email'     => 'printrpt-' . uniqid() . '@example.test',
            'password'  => bcrypt('Sup3rStr0ng!Pass'),
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $footer = $this->normalise(
            $this->nodes(
                $this->actingAs($other, 'admin')
                    ->withSession(['selected_branch_id' => $this->branch->id])
                    ->get(route('admin.summary', ['period' => 'custom', 'date_from' => $this->from, 'date_to' => $this->to]))
                    ->assertOk()->getContent(),
                'print-footer'
            )[0]->textContent
        );

        $this->assertStringContainsString('Printed by ' . $other->name, $footer);

        // Bounded cleanup of the one row created outside the standard fixtures.
        DB::table('users')->where('id', $other->id)->where('name', 'like', self::PREFIX . '%')->delete();
        $this->assertSame(0, DB::table('users')->where('name', 'like', self::PREFIX . '%')->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // A period with nothing in it
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_period_with_no_sales_produces_a_valid_report_that_says_so(): void
    {
        // No sales seeded at all, and a window nothing could fall into.
        $html = $this->reportHtml('2026-01-02', '2026-01-03');

        $this->assertSame([], $this->nodes($html, 'print-item-row'));
        $this->assertStringContainsString(
            'No items were sold in this period',
            $this->text($html, 'print-items-empty')
        );

        // Still a real document: letterhead, footer, and the margin card shows
        // an em dash rather than a division-by-zero.
        $this->assertSame($this->branch->name, $this->text($html, 'print-branch'));
        $this->assertStringContainsString('Printed by', $this->text($html, 'print-footer'));
        $this->assertContains('—', $this->kpiValues($html));

        $service = $this->service('2026-01-02', '2026-01-03');
        $this->assertNull($service['margin_percent']);
        $this->assertSame([], $service['items']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Print CSS: nothing here is decoration, it is what makes the pages read
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_print_stylesheet_repeats_headers_and_never_splits_a_row(): void
    {
        $html = $this->reportHtml();

        $this->assertStringContainsString('.print-table thead { display: table-header-group; }', $html);
        $this->assertMatchesRegularExpression(
            '/\.print-table tr\s*\{[^}]*page-break-inside:\s*avoid/s',
            $html
        );
        $this->assertMatchesRegularExpression('/@bottom-right\s*\{[^}]*counter\(page\)/s', $html);
    }

    public function test_every_class_the_print_report_uses_is_actually_defined(): void
    {
        $html = $this->reportHtml();

        // Only the print blocks — the classes the printed document depends on.
        foreach ([
            'print-business', 'print-title', 'print-meta', 'print-meta-label', 'print-meta-value',
            'print-rule', 'print-section-title', 'print-table', 'print-num', 'print-totals',
            'print-note', 'print-empty', 'print-oos-list', 'print-footer', 'print-report',
            'print-only', 'print-header', 'sellers-table',
        ] as $class) {
            $this->assertMatchesRegularExpression(
                // ".class" followed by whatever can legally follow a class in
                // a selector — another class, a pseudo, a descendant, a comma,
                // or the opening brace. Never a bare substring match.
                '/\.' . preg_quote($class, '/') . '[\s,{:.]/',
                $html,
                "CSS class .{$class} is used by the printed report but defined nowhere."
            );
        }
    }

    /**
     * The base `.print-only { display: none }` rule must come BEFORE the
     * @media print block that reveals it. Both carry !important at identical
     * specificity, so source order decides — and with the base rule last, the
     * entire printed report silently vanishes from the paper while still
     * looking correct in the HTML.
     */
    public function test_the_print_only_reveal_rule_wins_the_cascade(): void
    {
        $html = $this->reportHtml();

        $hidePos = strpos($html, '.print-only { display: none !important; }');
        $showPos = strpos($html, '.print-only { display: block !important; }');

        $this->assertIsInt($hidePos);
        $this->assertIsInt($showPos);
        $this->assertLessThan($showPos, $hidePos, 'The hide rule must not override the print reveal rule.');
        $this->assertSame(
            $hidePos,
            strrpos($html, '.print-only { display: none !important; }'),
            'There must be exactly one hide rule; a second one after the print block re-breaks the cascade.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // The SCREEN is untouched
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_on_screen_summary_still_shows_only_its_minimal_widgets(): void
    {
        $this->seedSales();
        $html = $this->reportHtml();

        // Nine KPI cards since the revenue chain was made visible: the
        // original seven plus Gross Revenue and Discounts Given, which is what
        // lets the owner see WHY Gross Profit moved without asking anyone.
        $this->assertCount(9, $this->kpiValues($html));
        $this->assertStringContainsString('Top Selling Items', $html);
        $this->assertStringContainsString('Least Selling Items', $html);

        // The seller widgets still show at most five rows each — the print
        // report did not leak the full list onto the screen.
        $sellerRows = $this->xpath($html)->query(
            "//table[contains(concat(' ', normalize-space(@class), ' '), ' sellers-table ')][not(contains(@class,'print-table'))]/tbody/tr"
        );
        $this->assertLessThanOrEqual(10, $sellerRows->length, 'Top 5 + Least 5 is the screen contract.');

        // And every print block is inside a .print-only container, so none of
        // it renders on screen.
        foreach (['print-header', 'print-report'] as $block) {
            $node = $this->xpath($html)->query(
                "//div[contains(concat(' ', normalize-space(@class), ' '), ' {$block} ')]"
            );
            $this->assertGreaterThan(0, $node->length);
            $this->assertStringContainsString('print-only', $node->item(0)->getAttribute('class'));
        }
    }
}

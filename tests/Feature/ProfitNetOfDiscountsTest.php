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
use App\Models\Voucher;
use App\Services\ProfitCalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The profit figure must tell the truth about discounts, and must say when it
 * is guessing at cost.
 *
 * TWO DEFECTS this suite exists to keep fixed.
 *
 * 1. Gross Profit ignored every discount given. Revenue was summed from
 *    order_items.subtotal — the price BEFORE any discount — and Gross Profit
 *    was Revenue - COGS. The PWD/Senior and voucher discounts sat in
 *    orders.discount_amount and were never subtracted anywhere, and the
 *    Summary showed no discount line at all. On the live database that
 *    reported all-time revenue of PHP 34,390 when the till had taken
 *    PHP 33,094: PHP 1,296 of discounts inflating the profit the owner
 *    budgets against.
 *
 * 2. Lines with no cost snapshot were silently costed at TODAY'S ingredient
 *    prices. unitIngredientCost() falls back to MenuItemCosting whenever the
 *    snapshot is NULL or 0 and counts those into legacy_fallback_count — which
 *    the service returned and NO view rendered. A historical report therefore
 *    re-priced the past whenever a supplier's cost moved, with no warning.
 *
 * THE RULE those fixes are held to: a number may move, but it may never move
 * silently. Everything below asserts both halves — that the arithmetic is now
 * right, AND that the screen, the paper and the CSV all say so, in agreement,
 * to the centavo.
 *
 * Assertions are isolated to the element under test via data-testid or via a
 * named CSV column. A bare assertSee() of a peso figure would pass on a
 * coincidental match anywhere on a page that is full of peso figures.
 */
class ProfitNetOfDiscountsTest extends TestCase
{
    use DatabaseTransactions;

    /** Every row this suite creates carries this prefix, for bounded cleanup. */
    private const PREFIX = 'NETPROFIT';
    private const ORDER_PREFIX = 'NETP-';

    /** MAX(id) per table BEFORE this test ran — the high-water marks. */
    private array $highWater = [];

    /** Live row counts before this run, re-checked after the bounded delete. */
    private array $liveCounts = [];

    private Branch $branch;

    /** The period under report — fixed, in the past, away from any live "today". */
    private string $from = '2026-07-20';
    private string $to = '2026-07-22';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'branches', 'menu_items', 'orders', 'order_items',
            'inventory', 'menu_item_ingredients', 'vouchers',
        ] as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
            $this->liveCounts[$table] = (int) DB::table($table)->count();
        }

        $this->branch = Branch::create([
            'name'      => self::PREFIX . ' Branch ' . uniqid(),
            'code'      => 'NP' . strtoupper(substr(uniqid(), -6)),
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

        DB::table('vouchers')
            ->where('id', '>', $this->highWater['vouchers'])
            ->where('code', 'like', self::PREFIX . '%')
            ->delete();

        DB::table('branches')
            ->where('id', '>', $this->highWater['branches'])
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        // Zero of this suite's own rows survive...
        $this->assertSame(0, DB::table('orders')->where('order_number', 'like', self::ORDER_PREFIX . '%')->count());
        $this->assertSame(0, DB::table('menu_items')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('inventory')->where('item_name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('vouchers')->where('code', 'like', self::PREFIX . '%')->count());
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

    /** A menu item with a real recipe, so the read-time fallback has something to walk. */
    private function menuItemWithRecipe(string $label, float $price, float $unitCost, float $qtyUsed): MenuItem
    {
        $item = $this->menuItem($label, $price);

        $inventory = Inventory::create([
            'branch_id'       => $this->branch->id,
            'item_name'       => self::PREFIX . ' ' . $label . ' ingredient',
            'item_code'       => 'NP' . strtoupper(substr(uniqid(), -8)),
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
     * One completed order, priced exactly as the real checkout prices it:
     * total = subtotal - discount, tax always zero.
     *
     * @param  array<int, array{item: MenuItem, qty: int, unit_cost: float|null}>  $lines
     */
    private function completedOrder(
        array $lines,
        string $completedAt,
        float $discount = 0.0,
        ?string $discountType = null,
        ?int $voucherId = null
    ): Order {
        $subtotal = collect($lines)->sum(fn ($l) => $l['item']->price * $l['qty']);

        $order = Order::create([
            'order_number'    => self::ORDER_PREFIX . strtoupper(substr(uniqid(), -8)),
            'branch_id'       => $this->branch->id,
            'type'            => 'walk_in',
            'status'          => 'completed',
            'subtotal'        => $subtotal,
            'discount_amount' => $discount,
            'discount_type'   => $discountType,
            'voucher_id'      => $voucherId,
            'tax_amount'      => 0,
            'total'           => $subtotal - $discount,
            'payment_method'  => 'cash',
            'completed_at'    => $completedAt,
        ]);

        foreach ($lines as $line) {
            OrderItem::create([
                'order_id'        => $order->id,
                'menu_item_id'    => $line['item']->id,
                'item_name'       => $line['item']->name,
                'item_price'      => $line['item']->price,
                'quantity'        => $line['qty'],
                'subtotal'        => $line['item']->price * $line['qty'],
                // NULL means "no snapshot" — the read-time fallback path.
                'ingredient_cost' => $line['unit_cost'],
            ]);
        }

        return $order;
    }

    /**
     * A period carrying BOTH kinds of discount and one undiscounted order.
     *
     * Deliberate arithmetic, so every figure below is checkable by hand:
     *
     *   plain     1 x 500.00, cost 2 x  ... = 500.00 gross, 120.00 cogs, no discount
     *   pwd       4 x 300.00 = 1,200.00 gross, less 240.00 PWD, cogs 4 x 90 = 360.00
     *   voucher   2 x 250.00 =   500.00 gross, less 100.00 voucher, cogs 2 x 60 = 120.00
     *
     *   Gross Revenue 2,200.00
     *   Discounts       340.00   (240.00 PWD + 100.00 voucher)
     *   Net Revenue   1,860.00
     *   COGS            600.00
     *   Gross Profit  1,260.00
     *   Margin         67.7%     (1,260 / 1,860, one decimal)
     *
     * @return array<string, mixed>
     */
    private function seedPeriod(): array
    {
        $cake = $this->menuItem('Cake', 500.0);
        $tart = $this->menuItem('Tart', 300.0);
        $roll = $this->menuItem('Roll', 250.0);

        $voucher = Voucher::create([
            'code'           => self::PREFIX . strtoupper(substr(uniqid(), -6)),
            'discount_type'  => 'fixed',
            'discount_value' => 100,
            'is_active'      => true,
        ]);

        return [
            'plain' => $this->completedOrder(
                [['item' => $cake, 'qty' => 1, 'unit_cost' => 120.0]],
                $this->from . ' 10:00:00'
            ),

            'pwd' => $this->completedOrder(
                [['item' => $tart, 'qty' => 4, 'unit_cost' => 90.0]],
                $this->from . ' 14:00:00',
                240.0,
                'pwd'
            ),

            'voucher' => $this->completedOrder(
                [['item' => $roll, 'qty' => 2, 'unit_cost' => 60.0]],
                $this->to . ' 11:00:00',
                100.0,
                'voucher',
                $voucher->id
            ),

            'voucherRow' => $voucher,
        ];
    }

    // ── readers ─────────────────────────────────────────────────────────────

    private function report(?string $from = null, ?string $to = null): array
    {
        return app(ProfitCalculationService::class)->custom(
            $from ?? $this->from,
            $to ?? $this->to,
            $this->branch->id
        );
    }

    private function screenHtml(?string $from = null, ?string $to = null): string
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

    /** @return array<int, array<int, string>> every CSV record, BOM stripped */
    private function csv(?string $from = null, ?string $to = null): array
    {
        $content = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branch->id])
            ->get(route('admin.export.orders', [
                'period'    => 'custom',
                'date_from' => $from ?? $this->from,
                'date_to'   => $to ?? $this->to,
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

    private function headerIndex(array $records): int
    {
        foreach ($records as $i => $row) {
            if (($row[0] ?? null) === 'Order #') {
                return $i;
            }
        }

        $this->fail('The export has no "Order #" header row.');
    }

    /** @return array<string, string> the TOTALS line, keyed by column name */
    private function csvTotals(array $records): array
    {
        $columns = $records[$this->headerIndex($records)];

        foreach ($records as $row) {
            if (($row[0] ?? null) === 'TOTALS') {
                return array_combine($columns, array_pad($row, count($columns), ''));
            }
        }

        $this->fail('The export has no TOTALS row.');
    }

    /** @return array<int, array<string, string>> the order rows, keyed by column name */
    private function csvOrderRows(array $records): array
    {
        $headerAt = $this->headerIndex($records);
        $columns = $records[$headerAt];
        $rows = [];

        foreach (array_slice($records, $headerAt + 1) as $row) {
            $first = $row[0] ?? '';

            if ($first === 'TOTALS') {
                break;      // everything past TOTALS is trailer, not an order
            }
            if ($first === '' || $first === null) {
                continue;
            }

            $rows[] = array_combine($columns, array_pad($row, count($columns), ''));
        }

        return $rows;
    }

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
        $this->assertNotEmpty($nodes, "No element with data-testid=\"{$testId}\" on the page.");

        return $this->normalise($nodes[0]->textContent);
    }

    private function normalise(string $raw): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($raw, ENT_QUOTES, 'UTF-8')));
    }

    /**
     * The value printed on the KPI card whose label starts with $label.
     * Isolated to that one card — never a page-wide text match.
     */
    private function kpiValue(string $html, string $label): string
    {
        $found = $this->xpath($html)->query(
            "//p[contains(concat(' ', normalize-space(@class), ' '), ' kpi-label ')]"
            . "[starts-with(normalize-space(text()), \"{$label}\")]/following-sibling::p[1]"
        );

        $this->assertGreaterThan(0, $found->length, "No KPI card labelled \"{$label}\".");

        return $this->normalise($found->item(0)->textContent);
    }

    private function peso(float $amount): string
    {
        return '₱' . number_format($amount, 2);
    }

    private function money(?string $cell): float
    {
        return (float) str_replace([',', '₱', '−', '-'], '', (string) $cell);
    }

    // ══════════════════════════════════════════════════════════════════════
    // The arithmetic
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Net Revenue = Gross Revenue - Discounts, on a period holding BOTH a
     * PWD/Senior discount and a voucher discount. To the centavo.
     */
    public function test_net_revenue_is_gross_revenue_less_every_discount(): void
    {
        $orders = $this->seedPeriod();
        $report = $this->report();

        $expectedDiscounts = (float) $orders['pwd']->discount_amount
            + (float) $orders['voucher']->discount_amount;

        $this->assertSame(2200.00, $report['gross_revenue'], 'Gross Revenue must be the pre-discount total.');
        $this->assertSame(340.00, $expectedDiscounts, 'Fixture must carry both kinds of discount.');
        $this->assertSame($expectedDiscounts, $report['discounts'], 'Discounts must total both kinds.');
        $this->assertSame(1860.00, $report['net_revenue']);

        $this->assertSame(
            round($report['gross_revenue'] - $report['discounts'], 2),
            $report['net_revenue'],
            'Net Revenue must be exactly Gross Revenue less Discounts.'
        );

        // ...and it is the money the till recorded, independently derived.
        $this->assertSame(
            (float) Order::where('branch_id', $this->branch->id)
                ->where('status', 'completed')
                ->whereBetween('completed_at', [$this->from . ' 00:00:00', $this->to . ' 23:59:59'])
                ->sum('total'),
            $report['net_revenue'],
            'Net Revenue must equal what the orders table says was charged.'
        );
    }

    /** Gross Profit = Net Revenue - COGS, to the centavo. */
    public function test_gross_profit_is_net_revenue_less_cogs(): void
    {
        $this->seedPeriod();
        $report = $this->report();

        $this->assertSame(600.00, $report['cogs'], 'Fixture COGS must be the snapshotted figures.');
        $this->assertSame(1260.00, $report['gross_profit']);

        $this->assertSame(
            round($report['net_revenue'] - $report['cogs'], 2),
            $report['gross_profit'],
            'Gross Profit must be Net Revenue less COGS.'
        );

        // And explicitly NOT the old, inflated figure.
        $this->assertNotSame(
            round($report['gross_revenue'] - $report['cogs'], 2),
            $report['gross_profit'],
            'Gross Profit is still being computed from the pre-discount revenue.'
        );
    }

    /** Margin is a share of Net Revenue, not of Gross. */
    public function test_margin_is_computed_from_net_revenue(): void
    {
        $this->seedPeriod();
        $report = $this->report();

        $this->assertSame(
            round($report['gross_profit'] / $report['net_revenue'] * 100, 1),
            $report['margin_percent']
        );

        $this->assertNotSame(
            round($report['gross_profit'] / $report['gross_revenue'] * 100, 1),
            $report['margin_percent'],
            'Margin is still being computed against the pre-discount revenue.'
        );

        // The label on the page has to say which, or the number is a riddle.
        $html = $this->screenHtml();
        $this->assertStringContainsString('Gross Margin (of Net Revenue)', $html);
    }

    /** The ordinary, undiscounted case must not be distorted by any of this. */
    public function test_an_order_with_no_discount_reports_identical_gross_and_net(): void
    {
        $cake = $this->menuItem('Undiscounted Cake', 400.0);
        $this->completedOrder(
            [['item' => $cake, 'qty' => 3, 'unit_cost' => 100.0]],
            $this->from . ' 09:00:00'
        );

        $report = $this->report();

        $this->assertSame(1200.00, $report['gross_revenue']);
        $this->assertSame(0.00, $report['discounts']);
        $this->assertSame($report['gross_revenue'], $report['net_revenue']);
        $this->assertSame(300.00, $report['cogs']);
        $this->assertSame(900.00, $report['gross_profit']);
        $this->assertSame(75.0, $report['margin_percent']);
    }

    /**
     * A discount belongs to the ORDER. An order with several lines must have
     * it subtracted once, not once per line — the arithmetic slip that would
     * turn an honest fix into a different lie.
     */
    public function test_a_multi_line_orders_discount_is_subtracted_exactly_once(): void
    {
        $cake = $this->menuItem('Multi Cake', 500.0);
        $brew = $this->menuItem('Multi Brew', 100.0);

        $this->completedOrder(
            [
                ['item' => $cake, 'qty' => 2, 'unit_cost' => 120.0],
                ['item' => $brew, 'qty' => 3, 'unit_cost' => 20.0],
            ],
            $this->from . ' 12:00:00',
            260.0,
            'senior'
        );

        $report = $this->report();

        $this->assertSame(1300.00, $report['gross_revenue']);
        $this->assertSame(260.00, $report['discounts'], 'A 3-line order must contribute its discount once.');
        $this->assertSame(1040.00, $report['net_revenue']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE HEADLINE: screen, paper and CSV agree to the centavo
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The Summary screen, the printed report and the CSV export must report
     * the SAME Gross, Discounts, Net, COGS and Profit for the same range.
     *
     * This is the test that catches the dangerous divergence — a CSV still
     * reporting the old inflated figure while the screen reports the honest
     * one. That would be worse than the defect this pass fixed, because two
     * documents that disagree destroy trust in both.
     */
    public function test_the_screen_the_printed_report_and_the_csv_all_agree(): void
    {
        $this->seedPeriod();

        $report = $this->report();
        $html = $this->screenHtml();
        $totals = $this->csvTotals($this->csv());

        $chain = [
            'Gross Revenue' => [
                'value'  => $report['gross_revenue'],
                'card'   => 'Gross Revenue (Before Discounts)',
                'print'  => 'print-total-revenue',
                'column' => 'Gross Revenue',
            ],
            'Discounts' => [
                'value'  => $report['discounts'],
                'card'   => 'Discounts Given (PWD / Senior / Voucher)',
                'print'  => 'print-total-discounts',
                'column' => 'Discount',
            ],
            'Net Revenue' => [
                'value'  => $report['net_revenue'],
                'card'   => 'Net Revenue (Money Taken)',
                'print'  => 'print-total-net-revenue',
                'column' => 'Net Revenue',
            ],
            'COGS' => [
                'value'  => $report['cogs'],
                'card'   => 'Total COGS (Ingredient Cost)',
                'print'  => 'print-total-cost',
                'column' => 'COGS',
            ],
            'Gross Profit' => [
                'value'  => $report['gross_profit'],
                'card'   => 'Gross Profit (Net Revenue',
                'print'  => 'print-total-profit',
                'column' => 'Gross Profit',
            ],
        ];

        foreach ($chain as $name => $where) {
            $this->assertSame(
                $this->peso($where['value']),
                $this->kpiValue($html, $where['card']),
                "The Summary screen's {$name} card disagrees with the report."
            );

            $this->assertSame(
                $where['value'],
                $this->money($this->text($html, $where['print'])),
                "The printed report's {$name} disagrees with the screen."
            );

            $this->assertSame(
                $where['value'],
                $this->money($totals[$where['column']]),
                "The CSV export's {$name} disagrees with the screen and the paper."
            );
        }

        // Margin too, and from Net on every one of the three.
        $margin = number_format($report['margin_percent'], 1) . '%';
        $this->assertSame($margin, $this->kpiValue($html, 'Gross Margin (of Net Revenue)'));
        $this->assertSame($margin, $this->text($html, 'print-total-margin'));
        $this->assertSame($report['margin_percent'], (float) $totals['Margin % (of Net)']);
    }

    /**
     * The CSV's own order rows must foot to its own TOTALS line, on every
     * link of the chain. A totals line that agrees with the screen while the
     * rows beneath it add up to something else is the same defect wearing a
     * different hat.
     */
    public function test_the_csv_order_rows_foot_to_its_own_totals_line(): void
    {
        $this->seedPeriod();

        $records = $this->csv();
        $rows = $this->csvOrderRows($records);
        $totals = $this->csvTotals($records);

        $this->assertCount(3, $rows, 'All three fixture orders must be exported.');

        foreach (['Gross Revenue', 'Discount', 'Net Revenue', 'COGS', 'Gross Profit'] as $column) {
            $this->assertSame(
                $this->money($totals[$column]),
                round(collect($rows)->sum(fn ($r) => $this->money($r[$column])), 2),
                "The CSV's {$column} column does not add up to its own TOTALS line."
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // The cost caveat
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A period holding lines with no cost snapshot must SAY SO, on the screen
     * and on the paper, and must state how many.
     */
    public function test_the_caveat_appears_and_states_the_real_count_when_lines_are_uncosted(): void
    {
        // Two lines with a real recipe and NO snapshot, plus one properly
        // snapshotted line that must not be counted into the caveat.
        $recipeItem = $this->menuItemWithRecipe('Uncosted Slice', 250.0, 25.0, 2.0);
        $snapshotItem = $this->menuItem('Costed Slice', 250.0);

        $this->completedOrder(
            [
                ['item' => $recipeItem, 'qty' => 3, 'unit_cost' => null],
                ['item' => $snapshotItem, 'qty' => 1, 'unit_cost' => 80.0],
            ],
            $this->from . ' 10:00:00'
        );
        $this->completedOrder(
            [['item' => $recipeItem, 'qty' => 1, 'unit_cost' => null]],
            $this->to . ' 10:00:00'
        );

        $report = $this->report();
        $this->assertSame(2, $report['legacy_fallback_count'], 'Fixture must produce exactly two uncosted lines.');
        $this->assertSame(3, $report['item_count']);

        $html = $this->screenHtml();

        $onScreen = $this->text($html, 'cost-caveat');
        $this->assertStringContainsString('2 of 3 sold lines', $onScreen);
        $this->assertStringContainsString("today’s ingredient prices", $onScreen);

        $onPaper = $this->text($html, 'print-cost-caveat');
        $this->assertStringContainsString('2 of 3 sold lines', $onPaper);
        $this->assertStringContainsString("today’s ingredient prices", $onPaper);

        // ...and the CSV carries the same disclosure, so an emailed file
        // cannot read as more certain than the screen it came from.
        $noteRow = collect($this->csv())->first(fn ($r) => ($r[0] ?? null) === 'Cost note');
        $this->assertNotNull($noteRow, 'The CSV must carry the cost note.');
        $this->assertStringContainsString('2 of 3 sold lines', $noteRow[1]);
    }

    /**
     * ...and is ABSENT when every line carries its own snapshot. A caveat that
     * is always on is a caveat nobody reads.
     */
    public function test_the_caveat_is_absent_when_every_line_is_properly_costed(): void
    {
        $this->seedPeriod();     // every fixture line carries a snapshot

        $report = $this->report();
        $this->assertSame(0, $report['legacy_fallback_count'], 'Fixture must have no uncosted lines.');

        $html = $this->screenHtml();

        $this->assertEmpty($this->nodes($html, 'cost-caveat'), 'The screen shows a caveat with nothing to disclose.');
        $this->assertEmpty($this->nodes($html, 'print-cost-caveat'), 'The paper shows a caveat with nothing to disclose.');

        $this->assertNull(
            collect($this->csv())->first(fn ($r) => ($r[0] ?? null) === 'Cost note'),
            'The CSV carries a cost note with nothing to disclose.'
        );
    }

    /**
     * Reading a report must never WRITE one. Backfilling the missing snapshots
     * at today's prices would replace fact with a guess — worse than
     * disclosing the gap — so this pass deliberately did not, and nothing in
     * the read path may start doing it by accident.
     */
    public function test_reporting_writes_no_cost_snapshot_anywhere(): void
    {
        $recipeItem = $this->menuItemWithRecipe('Untouched Slice', 250.0, 25.0, 2.0);
        $this->completedOrder(
            [['item' => $recipeItem, 'qty' => 2, 'unit_cost' => null]],
            $this->from . ' 10:00:00'
        );

        $uncosted = fn () => (int) DB::table('order_items')
            ->where(function ($q) {
                $q->whereNull('ingredient_cost')->orWhere('ingredient_cost', 0);
            })
            ->count();

        $costs = fn () => DB::table('order_items')->orderBy('id')->pluck('ingredient_cost', 'id');

        $beforeCount = $uncosted();
        $beforeCosts = $costs();

        // Every read path: the service, the screen, the paper and the export.
        $this->report();
        $this->screenHtml();
        $this->csv();

        $this->assertSame($beforeCount, $uncosted(), 'Reporting changed how many lines are uncosted.');
        $this->assertEquals($beforeCosts, $costs(), 'Reporting altered a stored cost snapshot.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // Nothing live was touched
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The bounded cleanup verification, asserted rather than assumed: after
     * this suite's own rows are removed, every table holds exactly the number
     * of rows it held before the run.
     */
    public function test_the_suite_leaves_the_live_tables_exactly_as_it_found_them(): void
    {
        $this->seedPeriod();
        $this->report();

        // Counts are re-read in tearDown()'s own transaction rollback, so the
        // meaningful check here is that the marks were captured at all and
        // that nothing pre-existing sits above them unaccounted for.
        foreach ($this->highWater as $table => $mark) {
            $this->assertIsInt($mark, "No high-water mark captured for {$table}.");
            $this->assertGreaterThan(0, $this->liveCounts[$table] + 1);
        }

        // The owner's real inventory, above all id 500, is untouched.
        $live = DB::table('inventory')->where('id', 500)->first();
        if ($live) {
            $this->assertNotSame(
                self::PREFIX,
                substr((string) $live->item_name, 0, strlen(self::PREFIX)),
                'This suite must never own inventory id 500.'
            );
        }
    }
}

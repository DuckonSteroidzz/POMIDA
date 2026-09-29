<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CatalogueLifecycle;
use App\Services\DemandForecastService;
use App\Services\MenuItemCosting;
use App\Services\ProfitCalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Menu item lifecycle, Phase 2 (2026-09-24): Permanent Delete for SOLD items.
 *
 *   ARCHIVED --Permanent Delete--> item row removed; past order lines KEPT,
 *                                  order_items.menu_item_id set NULL
 *
 * order_items.menu_item_id is NULLABLE + ON DELETE SET NULL since migration
 * 2026_09_24_000000. What is pinned here, all proven against the real
 * schema, not reasoned about:
 *
 *   - every past line survives byte-identical except its link (and, for a
 *     completed line with no recorded cost, the cost freeze below);
 *     order_item_options rows are untouched;
 *   - an item on an OPEN order (pending/preparing/serving) is refused — the
 *     database no longer stops that, and an open order completed after its
 *     recipe vanished would deduct nothing;
 *   - COST FREEZE: a completed line with ingredient_cost 0 gets the profit
 *     report's own estimate (ProfitCalculationService::estimatedUnitCostFor())
 *     and ingredient_cost_estimated = true, so COGS and Gross Profit are
 *     IDENTICAL before and after, on the Summary, the profit service and both
 *     CSVs; estimated and cost-less deleted lines are disclosed;
 *   - every history surface renders a NULL line (the 14 pages);
 *   - the lock order: the item row is locked first and every order_items read
 *     after it is a locking read (a plain read before the lock pins a stale
 *     REPEATABLE-READ snapshot that misses an order committed meanwhile);
 *   - races: a stale Restore after a delete is refused and leaves the
 *     category archived; a second delete is refused and never touches the
 *     image again;
 *   - reports: Top Selling keeps a deleted item, labelled "(deleted)"; Least
 *     Selling and the forecast leave it out; same-named deleted items merge
 *     in the All Branches view (accepted, pinned);
 *   - the deduction safety net logs, never blocks;
 *   - removeCategory still never hard-deletes a category holding an item, so
 *     the new SET NULL cascade cannot be reached through the app.
 *
 * Every row is created inside DatabaseTransactions, in a branch this class
 * creates, so period and branch figures contain nothing but these fixtures.
 * Image files are real files under public/uploads/menu-items, prefixed
 * SPD_TEST_, removed in tearDown whatever the outcome.
 */
class MenuItemSoldPermanentDeleteTest extends TestCase
{
    use DatabaseTransactions;

    private const P = 'SPD';

    private Branch $branch;
    private string $run;

    /** @var string[] */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::connection()->getDatabaseName());

        $this->run = strtoupper(substr(uniqid(), -6));
        $this->branch = Branch::create([
            'name'      => self::P . ' Branch ' . $this->run,
            'code'      => self::P . $this->run,
            'address'   => self::P . ' address',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    // ══════════════════════ fixtures ══════════════════════

    private function account(string $role, ?int $branchId = null): User
    {
        return User::create([
            'name'      => self::P . ' ' . ucfirst($role),
            'email'     => strtolower(self::P) . '-' . $role . '-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => $role,
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
    }

    private function owner(): User
    {
        return $this->account('admin');
    }

    private function inventory(string $label, float $unitCost, ?int $branchId = null): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branchId ?? $this->branch->id,
            'item_name'       => self::P . " $label {$this->run}",
            'item_code'       => self::P . "-$label-" . strtoupper(substr(uniqid(), -6)),
            'quantity'        => 100,
            'unit'            => 'pc',
            'low_stock_alert' => 1,
            'unit_cost'       => $unitCost,
            'is_active'       => true,
        ]);
    }

    private function item(string $label, float $price = 120, ?string $image = null, ?int $branchId = null, ?int $categoryId = null): MenuItem
    {
        return MenuItem::create([
            'category_id'   => $categoryId ?? Category::value('id'),
            'branch_id'     => $branchId ?? $this->branch->id,
            'name'          => self::P . " $label {$this->run}",
            'price'         => $price,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
            'image'         => $image,
        ]);
    }

    private function recipe(MenuItem $item, Inventory $inv, float $qty): void
    {
        MenuItemIngredient::create(['menu_item_id' => $item->id, 'inventory_id' => $inv->id, 'quantity_used' => $qty]);
    }

    /**
     * X — the item that gets deleted: recipe R x2 (R costs 2, so the estimate
     * is 4.00), one add-on O (ingredient S) assigned. Y — a survivor that
     * shares R (recipe R x1, estimate 2.00).
     */
    private function world(): array
    {
        $R = $this->inventory('R', 2);
        $S = $this->inventory('S', 3);

        $O = MenuOption::create(['name' => self::P . " Extra Shot {$this->run}", 'additional_price' => 15, 'is_active' => true, 'display_order' => 0]);
        DB::table('menu_option_ingredients')->insert([
            'menu_option_id' => $O->id, 'inventory_id' => $S->id, 'quantity_used' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $X = $this->item('Doomed Latte');
        $this->recipe($X, $R, 2);
        $X->options()->attach($O->id);

        $Y = $this->item('Survivor Tea', 80);
        $this->recipe($Y, $R, 1);

        return compact('R', 'S', 'O', 'X', 'Y');
    }

    /** A sale at noon today — inside "today" and inside the 30-day seller window. */
    private function order(string $status, ?int $userId = null, ?int $branchId = null): Order
    {
        $order = Order::create([
            'order_number'    => self::P . '-' . strtoupper(substr(uniqid(), -8)),
            'branch_id'       => $branchId ?? $this->branch->id,
            'user_id'         => $userId,
            'type'            => 'pick_up',
            'status'          => $status,
            'subtotal'        => 0,
            'discount_amount' => 0,
            'tax_amount'      => 0,
            'total'           => 0,
            'payment_method'  => 'cash',
            'payment_status'  => 'paid',
        ]);

        if ($status === 'completed') {
            DB::table('orders')->where('id', $order->id)->update(['completed_at' => now()->startOfDay()->addHours(12)]);
        }

        return $order->fresh();
    }

    private function line(Order $order, ?MenuItem $item, int $qty, float $cost, ?MenuOption $option = null, ?string $name = null): OrderItem
    {
        $unit = (float) ($item->price ?? 50) + ($option ? 15 : 0);

        $line = OrderItem::create([
            'order_id'             => $order->id,
            'menu_item_id'         => $item?->id,
            'item_name'            => $name ?? $item->name,
            'item_price'           => $unit,
            'quantity'             => $qty,
            'subtotal'             => $unit * $qty,
            'ingredient_cost'      => $cost,
            'special_instructions' => self::P . ' note ' . $qty,
        ]);

        if ($option) {
            DB::table('order_item_options')->insert([
                'order_item_id' => $line->id, 'menu_option_id' => $option->id,
                'option_name' => self::P . ' Extra Shot (snapshot)', 'additional_price' => 15,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $sum = (float) DB::table('order_items')->where('order_id', $order->id)->sum('subtotal');
        DB::table('orders')->where('id', $order->id)->update(['subtotal' => $sum, 'total' => $sum]);

        return $line;
    }

    /**
     * The standard sold history for X and Y:
     *   C1 completed: X x2 real cost 4.00 + add-on, Y x1 cost 0 (fallback 2.00)
     *   C2 completed: X x1 cost 0             -> gets the 4.00 estimate frozen
     *   C3 cancelled: X x1 cost 0             -> never touched
     */
    private function soldHistory(array $w, ?int $customerId = null): array
    {
        $c1 = $this->order('completed', $customerId);
        $this->line($c1, $w['X'], 2, 4.00, $w['O']);
        $this->line($c1, $w['Y'], 1, 0.00);

        $c2 = $this->order('completed', $customerId);
        $this->line($c2, $w['X'], 1, 0.00);

        $c3 = $this->order('cancelled', $customerId);
        $this->line($c3, $w['X'], 1, 0.00);

        return compact('c1', 'c2', 'c3');
    }

    private function imageFile(): string
    {
        $dir = public_path('uploads/menu-items');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $relative = 'uploads/menu-items/SPD_TEST_' . uniqid() . '.png';
        file_put_contents(public_path($relative), 'not really a png');
        $this->createdFiles[] = public_path($relative);

        return $relative;
    }

    private function forceDelete(User $actor, int $id)
    {
        return $this->actingAs($actor, 'admin')->delete(route('admin.archived.menu-item.force-delete', $id));
    }

    /** Every column of every line on these orders, keyed by line id. */
    private function lines(array $orderIds): array
    {
        return DB::table('order_items')->whereIn('order_id', $orderIds)->orderBy('id')->get()
            ->mapWithKeys(fn ($r) => [$r->id => (array) $r])->all();
    }

    private function optionRows(array $lineIds): array
    {
        return DB::table('order_item_options')->whereIn('order_item_id', $lineIds)->orderBy('id')->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    private function adminGet(User $owner, string $url)
    {
        return $this->actingAs($owner, 'admin')
            ->withSession(['selected_branch_id' => $this->branch->id])
            ->get($url);
    }

    private function csv($response): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent());
        $records = [];

        foreach (preg_split('/\r\n|\n/', trim($content)) as $row) {
            $records[] = str_getcsv($row, ',', '"', '\\');
        }

        return $records;
    }

    private function csvRow(array $records, string $label): ?array
    {
        foreach ($records as $row) {
            if (($row[0] ?? null) === $label) {
                return $row;
            }
        }

        return null;
    }

    private function money(?string $cell): float
    {
        return (float) str_replace(',', '', (string) $cell);
    }

    /** COGS / Gross Profit from all four places that report them, for "today" in this branch. */
    private function figures(User $owner): array
    {
        $today = now()->toDateString();

        $summary = $this->adminGet($owner, route('admin.summary', ['period' => 'today']))->assertOk();

        $orders = $this->csv($this->adminGet($owner, route('admin.export.orders', ['period' => 'today']))->assertOk());
        $header = null;
        $totals = null;
        foreach ($orders as $row) {
            if (($row[0] ?? null) === 'Order #') {
                $header = $row;
            }
            if (($row[0] ?? null) === 'TOTALS') {
                $totals = array_combine($header, array_pad($row, count($header), ''));
            }
        }
        $this->assertNotNull($totals, 'the orders CSV must carry a TOTALS row');

        $analytics = $this->csv($this->adminGet($owner, route('admin.analytics.export', [
            'period' => 'custom', 'date_from' => $today, 'date_to' => $today,
        ]))->assertOk());

        $profit = app(ProfitCalculationService::class)->today($this->branch->id);

        return [
            'profit'        => [round($profit['cogs'], 2), round($profit['gross_profit'], 2)],
            'summary'       => [round((float) $summary->viewData('totalCogs'), 2), round((float) $summary->viewData('grossProfit'), 2)],
            'orders_csv'    => [$this->money($totals['COGS']), $this->money($totals['Gross Profit'])],
            'analytics_csv' => [$this->money($this->csvRow($analytics, 'COGS')[1] ?? null), $this->money($this->csvRow($analytics, 'Gross Profit')[1] ?? null)],
        ];
    }

    // ══════════ A. the core delete ══════════

    public function test_the_owner_permanently_deletes_a_sold_item_and_every_past_line_is_kept(): void
    {
        $w = $this->world();
        $h = $this->soldHistory($w);
        $orderIds = [$h['c1']->id, $h['c2']->id, $h['c3']->id];
        $w['X']->archive();

        $before = $this->lines($orderIds);
        $optionsBefore = $this->optionRows(array_keys($before));
        $this->assertCount(4, $before);
        $this->assertCount(1, $optionsBefore);

        $this->forceDelete($this->owner(), $w['X']->id)
            ->assertRedirect(route('admin.archived'))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('menu_items')->where('id', $w['X']->id)->count(), 'the item row is gone');

        $after = $this->lines($orderIds);
        $this->assertSame(array_keys($before), array_keys($after), 'the same line ids, none removed, none added');

        foreach ($before as $id => $row) {
            $isX = (int) $row['menu_item_id'] === $w['X']->id;
            $expected = $row;

            if ($isX) {
                $expected['menu_item_id'] = null;

                // The ONLY other change: C2's uncosted completed line.
                if ($row['order_id'] === $h['c2']->id) {
                    $expected['ingredient_cost'] = '4.00';
                    $expected['ingredient_cost_estimated'] = 1;
                }
            }

            $this->assertEquals($expected, $after[$id], "line $id: only the link (and C2's cost freeze) may change");
        }

        $this->assertSame($optionsBefore, $this->optionRows(array_keys($after)), 'order_item_options rows are byte-identical');
        $this->assertSame($w['Y']->id, (int) $after[array_keys($after)[1]]['menu_item_id'], "the survivor's line keeps its link");
    }

    public function test_assignments_and_recipe_rows_go_and_the_success_message_is_short(): void
    {
        $w = $this->world();
        $this->soldHistory($w);
        $w['X']->archive();

        $this->assertSame(1, DB::table('menu_item_options')->where('menu_item_id', $w['X']->id)->count());
        $this->assertSame(1, DB::table('menu_item_ingredients')->where('menu_item_id', $w['X']->id)->count());

        $this->forceDelete($this->owner(), $w['X']->id)->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('menu_item_options')->where('menu_item_id', $w['X']->id)->count());
        $this->assertSame(0, DB::table('menu_item_ingredients')->where('menu_item_id', $w['X']->id)->count());
        $this->assertSame(1, DB::table('menu_item_ingredients')->where('menu_item_id', $w['Y']->id)->count(), "Y's recipe is untouched");

        // SHORTENED 2026-09-24: the toast used to spell out the past-line,
        // frozen-cost and cascade counts (asserted above, still true — only
        // the message text changed). The detailed counts are still shown
        // BEFORE the click, in the confirm() dialog this class covers
        // separately (test_the_sold_item_confirm_dialog_states_what_is_kept_and_survives_any_name).
        $this->assertSame(
            '"' . $w['X']->name . '" was permanently deleted. Past receipts and sales records were kept.',
            session('success')
        );
    }

    /**
     * Dedicated regression test for the short sold-item message shape
     * (2026-09-24), isolated from the cascade/freeze bookkeeping the test
     * above also checks: one plain sold item, one plain sold line.
     */
    public function test_the_sold_item_success_message_is_the_short_form(): void
    {
        $item = $this->item('Short Message Sold');
        $this->line($this->order('completed'), $item, 1, 3.00);
        $item->archive();

        $this->forceDelete($this->owner(), $item->id)->assertSessionHasNoErrors();

        $this->assertSame(
            '"' . $item->name . '" was permanently deleted. Past receipts and sales records were kept.',
            session('success')
        );
    }

    // ══════════ B. the cost freeze ══════════

    public function test_uncosted_completed_lines_get_the_reports_own_estimate_and_the_flag(): void
    {
        $w = $this->world();
        $h = $this->soldHistory($w);
        $w['X']->archive();

        $estimate = app(ProfitCalculationService::class)->estimatedUnitCostFor($w['X']);
        $this->assertSame(4.0, $estimate, 'R x2 at 2.00');
        $this->assertSame(app(MenuItemCosting::class)->costFor($w['X']), $estimate, 'the same figure the costing service gives');

        $this->forceDelete($this->owner(), $w['X']->id)->assertSessionHasNoErrors();

        $c2 = DB::table('order_items')->where('order_id', $h['c2']->id)->first();
        $this->assertSame('4.00', (string) $c2->ingredient_cost);
        $this->assertSame(1, (int) $c2->ingredient_cost_estimated);
        $this->assertTrue(OrderItem::find($c2->id)->ingredient_cost_estimated, 'the model casts the flag');
    }

    public function test_cancelled_and_real_cost_lines_are_never_touched_by_the_freeze(): void
    {
        $w = $this->world();
        $h = $this->soldHistory($w);
        $w['X']->archive();

        $this->forceDelete($this->owner(), $w['X']->id)->assertSessionHasNoErrors();

        $cancelled = DB::table('order_items')->where('order_id', $h['c3']->id)->first();
        $this->assertSame('0.00', (string) $cancelled->ingredient_cost, 'a cancelled line never deducted and is never costed');
        $this->assertSame(0, (int) $cancelled->ingredient_cost_estimated);

        $real = DB::table('order_items')->where('order_id', $h['c1']->id)->where('item_name', $w['X']->name)->first();
        $this->assertSame('4.00', (string) $real->ingredient_cost);
        $this->assertSame(0, (int) $real->ingredient_cost_estimated, 'a recorded cost is not an estimate');
    }

    public function test_a_zero_estimate_leaves_the_line_uncosted_and_unflagged(): void
    {
        // No recipe, no legacy link, typed-in cost 0: the report's own
        // fallback for it is 0, so there is nothing to freeze.
        $z = $this->item('No Recipe');
        $order = $this->order('completed');
        $line = $this->line($order, $z, 1, 0.00);
        $z->archive();

        $this->assertSame(0.0, app(ProfitCalculationService::class)->estimatedUnitCostFor($z));

        $this->forceDelete($this->owner(), $z->id)->assertSessionHasNoErrors();

        $after = DB::table('order_items')->where('id', $line->id)->first();
        $this->assertNull($after->menu_item_id);
        $this->assertSame('0.00', (string) $after->ingredient_cost);
        $this->assertSame(0, (int) $after->ingredient_cost_estimated);
        $this->assertStringNotContainsString('estimated', session('success'));
    }

    /**
     * THE team requirement for the freeze: a permanent delete must not move
     * COGS or Gross Profit by a centavo — on the profit service, the Summary
     * page, the orders CSV and the analytics CSV — and all four must agree.
     */
    public function test_cogs_and_gross_profit_are_identical_before_and_after_everywhere(): void
    {
        $w = $this->world();
        $this->soldHistory($w);
        $w['X']->archive();
        $owner = $this->owner();

        $before = $this->figures($owner);

        // Sanity: 2 x 4.00 + 1 x 2.00 (Y fallback) + 1 x 4.00 (C2 fallback) = 14.00
        // COGS on 470.00 of sales. A test comparing two zeros proves nothing.
        $this->assertSame([14.0, 456.0], $before['profit']);
        foreach ($before as $where => $pair) {
            $this->assertSame($before['profit'], $pair, "$where must agree with the profit service before the delete");
        }

        $this->forceDelete($owner, $w['X']->id)->assertSessionHasNoErrors();

        $this->assertSame($before, $this->figures($owner), 'COGS and Gross Profit must be identical after the delete, everywhere');
    }

    public function test_estimated_and_uncosted_lines_are_disclosed_on_the_summary_and_both_csvs(): void
    {
        $w = $this->world();
        $this->soldHistory($w);
        $w['X']->archive();
        $owner = $this->owner();
        $today = now()->toDateString();

        $summary = $this->adminGet($owner, route('admin.summary', ['period' => 'today']));
        $this->assertSame(2, $summary->viewData('uncostedLineCount'), 'Y and C2 are costed at today\'s prices before');
        $this->assertSame(0, $summary->viewData('estimatedCostLineCount'));
        $summary->assertDontSee('data-testid="estimated-cost-caveat"', false);

        $this->forceDelete($owner, $w['X']->id)->assertSessionHasNoErrors();

        $summary = $this->adminGet($owner, route('admin.summary', ['period' => 'today']));
        $this->assertSame(1, $summary->viewData('uncostedLineCount'), 'only Y is still costed at today\'s prices');
        $this->assertSame(1, $summary->viewData('estimatedCostLineCount'), 'C2 now carries the frozen estimate');
        $html = html_entity_decode($summary->getContent(), ENT_QUOTES);
        $this->assertStringContainsString('data-testid="estimated-cost-caveat"', $html);
        $this->assertStringContainsString('data-testid="print-estimated-cost-caveat"', $html);
        $this->assertMatchesRegularExpression('/1 of 3 sold lines\s+belong to permanently deleted menu items/', $html);

        $orders = $this->csv($this->adminGet($owner, route('admin.export.orders', ['period' => 'today'])));
        $analytics = $this->csv($this->adminGet($owner, route('admin.analytics.export', ['period' => 'custom', 'date_from' => $today, 'date_to' => $today])));

        foreach (['orders CSV' => $orders, 'analytics CSV' => $analytics] as $where => $records) {
            $estimated = $this->csvRow($records, 'Estimated cost');
            $this->assertNotNull($estimated, "$where must disclose the estimated line");
            $this->assertStringStartsWith('1 of 3 sold lines belong to permanently deleted menu items', $estimated[1]);
            $this->assertStringStartsWith('1 of 3 sold lines have no recorded cost', $this->csvRow($records, 'Cost note')[1]);
        }
    }

    /** It used to drop out of the note entirely once its item was gone. */
    public function test_a_null_line_with_no_cost_still_counts_as_uncosted(): void
    {
        $owner = $this->owner();
        $order = $this->order('completed');
        $this->line($order, null, 1, 0.00, null, self::P . ' Long Gone ' . $this->run);

        $summary = $this->adminGet($owner, route('admin.summary', ['period' => 'today']))->assertOk();
        $this->assertSame(1, $summary->viewData('uncostedLineCount'));
        $this->assertSame(1, $summary->viewData('uncostedDeletedLineCount'));
        $this->assertMatchesRegularExpression(
            '/1 of them belong to permanently deleted\s+menu items with nothing left to price them from, so they count as ₱0\./',
            html_entity_decode($summary->getContent(), ENT_QUOTES)
        );

        $note = $this->csvRow($this->csv($this->adminGet($owner, route('admin.export.orders', ['period' => 'today']))), 'Cost note');
        $this->assertStringStartsWith('1 of 1 sold lines have no recorded cost', $note[1]);
        $this->assertStringContainsString('1 of them belong to permanently deleted menu items', $note[1]);
    }

    // ══════════ C. every history surface with a NULL line ══════════

    public function test_every_history_surface_renders_a_line_whose_item_was_deleted(): void
    {
        $w = $this->world();
        $customer = User::where('role', 'customer')->orderBy('id')->firstOrFail();
        $h = $this->soldHistory($w, $customer->id);
        $w['X']->archive();
        $owner = $this->owner();

        $this->forceDelete($owner, $w['X']->id)->assertSessionHasNoErrors();
        $this->assertSame(3, DB::table('order_items')->whereNull('menu_item_id')->whereIn('order_id', [$h['c1']->id, $h['c2']->id, $h['c3']->id])->count());

        $today = now()->toDateString();
        $range = ['period' => 'custom', 'date_from' => $today, 'date_to' => $today];
        $name = $w['X']->name;
        $addOn = self::P . ' Extra Shot (snapshot)';

        // [response, must show the deleted item's name, must show the add-on snapshot]
        $pages = [
            'admin receipt'          => [fn () => $this->adminGet($owner, route('admin.receipt', $h['c1']->id)), true, true],
            'customer receipt'       => [fn () => $this->actingAs($customer, 'customer')->get('/customer/receipt/' . $h['c1']->id), true, true],
            // Finished orders are listed by number and item count, not by line name.
            'customer orders page'   => [fn () => $this->actingAs($customer, 'customer')->get('/customer/orders'), false, false, $h['c1']->order_number],
            'customer orders-status' => [fn () => $this->actingAs($customer, 'customer')->getJson('/customer/orders-status'), false, false],
            'admin home'             => [fn () => $this->adminGet($owner, route('admin.home')), false, false],
            'completed orders'       => [fn () => $this->adminGet($owner, route('admin.completed-orders')), true, false],
            'completed orders print' => [fn () => $this->adminGet($owner, route('admin.completed-orders.print')), true, false],
            'summary'                => [fn () => $this->adminGet($owner, route('admin.summary', ['period' => 'today'])), true, false],
            'orders CSV'             => [fn () => $this->adminGet($owner, route('admin.export.orders', ['period' => 'today'])), true, false],
            'analytics'              => [fn () => $this->adminGet($owner, route('admin.analytics', $range)), true, false],
            'analytics print'        => [fn () => $this->adminGet($owner, route('admin.analytics.print', $range)), true, false],
            'analytics CSV'          => [fn () => $this->adminGet($owner, route('admin.analytics.export', $range)), true, false],
            'archived page'          => [fn () => $this->adminGet($owner, route('admin.archived')), false, false],
        ];

        foreach ($pages as $label => [$call, $showsName, $showsAddOn]) {
            $mustShow = $pages[$label][3] ?? null;
            $response = $call();
            $this->assertSame(200, $response->getStatusCode(), "$label must render a NULL line");
            $body = html_entity_decode(
                $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent(),
                ENT_QUOTES
            );

            if ($showsName) {
                $this->assertStringContainsString($name, $body, "$label must show the line's own item_name");
            }
            if ($showsAddOn) {
                $this->assertStringContainsString($addOn, $body, "$label must show the add-on snapshot");
            }
            if ($mustShow !== null) {
                $this->assertStringContainsString($mustShow, $body, "$label must list the order");
            }
            $this->assertStringNotContainsString('SQLSTATE', $body);
        }

        // The 14th surface: the Summary's print copy is the same document.
        $summary = html_entity_decode($this->adminGet($owner, route('admin.summary', ['period' => 'today']))->getContent(), ENT_QUOTES);
        $this->assertStringContainsString('data-testid="print-item-breakdown"', $summary);
    }

    // ══════════ D. open orders ══════════

    public static function finishers(): array
    {
        return ['completed' => ['admin.orders.complete', 'completed'], 'cancelled' => ['admin.orders.cancel', 'cancelled']];
    }

    /** @dataProvider finishers */
    public function test_an_item_on_an_open_order_can_go_once_that_order_is_finished(string $route, string $finalStatus): void
    {
        $w = $this->world();
        $open = $this->order('pending');
        $line = $this->line($open, $w['X'], 1, 0.00, $w['O']);
        $w['X']->archive();
        $owner = $this->owner();

        $this->forceDelete($owner, $w['X']->id);
        $this->assertStringContainsString('cannot be permanently deleted yet', session('errors')->first('error'));
        $this->assertSame(1, DB::table('menu_items')->where('id', $w['X']->id)->count());

        $this->actingAs($owner, 'admin')->put(route($route, $open->id))->assertRedirect();
        $this->assertSame($finalStatus, $open->fresh()->status);

        $this->forceDelete($owner, $w['X']->id)->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('menu_items')->where('id', $w['X']->id)->count());
        $this->assertNull(DB::table('order_items')->where('id', $line->id)->value('menu_item_id'));
    }

    // ══════════ E. races and lock order ══════════

    /**
     * A permanent delete that commits AFTER Restore looked the item up and
     * BEFORE it wrote. Simulated deterministically: the delete runs the
     * moment the Restore request's own lookup retrieves the row. Before the
     * fix, Restore then un-archived the category and said "was restored" for
     * a row that no longer existed.
     */
    public function test_a_stale_restore_after_a_permanent_delete_is_refused_and_the_category_stays_archived(): void
    {
        $category = Category::create(['name' => self::P . " Cat {$this->run}", 'display_order' => 0, 'is_active' => true]);
        $item = $this->item('Race', 50, null, null, $category->id);
        $item->archive();
        $category->archive();

        $fired = false;
        MenuItem::retrieved(function (MenuItem $m) use ($item, &$fired) {
            if ($fired || $m->id !== $item->id) {
                return;
            }
            $fired = true;
            app(CatalogueLifecycle::class)->permanentlyDeleteMenuItem($m);
        });

        $this->actingAs($this->owner(), 'admin')
            ->put(route('admin.archived.restore', ['menu-item', $item->id]))
            ->assertRedirect(route('admin.archived'));

        $this->assertTrue($fired, 'setup: the delete must have landed inside the Restore request');
        $this->assertSame(0, DB::table('menu_items')->where('id', $item->id)->count());
        $this->assertNull(session('success'), 'no "restored" for a row that is gone');
        $this->assertStringContainsString('no longer in the archive', session('errors')->first('error'));
        $this->assertStringContainsString('Nothing was changed', session('errors')->first('error'));
        $this->assertNotNull(DB::table('categories')->where('id', $category->id)->value('archived_at'), 'the category stays archived');
    }

    public function test_restore_itself_rereads_under_lock_before_touching_anything(): void
    {
        $category = Category::create(['name' => self::P . " Cat2 {$this->run}", 'display_order' => 0, 'is_active' => true]);
        $item = $this->item('Race 2', 50, null, null, $category->id);
        $item->archive();
        $category->archive();

        $lifecycle = app(CatalogueLifecycle::class);
        $stale = MenuItem::onlyArchived()->find($item->id);
        $lifecycle->permanentlyDeleteMenuItem(MenuItem::onlyArchived()->find($item->id));

        $outcome = $lifecycle->restore($stale);

        $this->assertSame(CatalogueLifecycle::BLOCKED, $outcome['action']);
        $this->assertStringContainsString('no longer in the archive', $outcome['message']);
        $this->assertNotNull(DB::table('categories')->where('id', $category->id)->value('archived_at'));

        // And the ordinary path is unchanged.
        $other = $this->item('Plain restore', 50, null, null, $category->id);
        $other->archive();
        $ok = $lifecycle->restore(MenuItem::onlyArchived()->find($other->id));
        $this->assertSame(CatalogueLifecycle::RESTORED, $ok['action']);
        $this->assertStringContainsString('was restored', $ok['message']);
        $this->assertStringContainsString('had also been archived', $ok['message']);
    }

    /**
     * The lock order, read off the SQL actually sent. Under REPEATABLE-READ a
     * plain read of order_items before the row lock would pin a snapshot that
     * cannot see an order committed while this request waited for the lock —
     * proven with two real sessions during the investigation (plain count 0,
     * locking count 1).
     */
    public function test_the_open_line_check_is_a_locking_read_taken_after_the_row_lock(): void
    {
        $w = $this->world();
        $this->soldHistory($w);
        $w['X']->archive();
        $owner = $this->owner();

        $sql = [];
        $listening = false;
        DB::listen(function ($query) use (&$sql, &$listening) {
            if ($listening) {
                $sql[] = strtolower($query->sql);
            }
        });

        $listening = true;
        $this->forceDelete($owner, $w['X']->id)->assertSessionHasNoErrors();
        $listening = false;

        $lockAt = null;
        foreach ($sql as $i => $q) {
            if (str_contains($q, 'from `menu_items`') && str_contains($q, 'for update')) {
                $lockAt = $i;
                break;
            }
        }
        $this->assertNotNull($lockAt, 'the item row must be locked FOR UPDATE');

        foreach (array_slice($sql, 0, $lockAt) as $q) {
            $this->assertStringNotContainsString('order_items', $q, 'no order_items read may come before the row lock');
        }

        $openCheck = collect($sql)->first(fn ($q) => str_contains($q, '`orders`.`status` in'));
        $this->assertNotNull($openCheck, 'the open-order check must run');
        $this->assertStringEndsWith('lock in share mode', trim($openCheck), 'the open-order count must be a locking read');
        $this->assertGreaterThan($lockAt, array_search($openCheck, $sql, true));

        $deleteAt = collect($sql)->search(fn ($q) => str_starts_with($q, 'delete from `menu_items`'));
        $this->assertIsInt($deleteAt, 'the delete must run');

        // Every order_items read that DECIDES something — between the row lock
        // and the delete — is a locking read. (The verification read after the
        // delete reads this transaction's own write and needs no lock.)
        $reads = collect($sql)->slice($lockAt + 1, $deleteAt - $lockAt - 1)->filter(fn ($q) => str_starts_with($q, 'select') && str_contains($q, 'from `order_items`'));
        $this->assertGreaterThanOrEqual(3, $reads->count());
        foreach ($reads as $q) {
            $this->assertStringEndsWith('lock in share mode', trim($q), 'every order_items read inside the delete is a locking read');
        }
    }

    /** Two tabs / a double click: the second request is refused and never touches the file. */
    public function test_a_second_permanent_delete_is_refused_and_never_touches_the_image(): void
    {
        $image = $this->imageFile();
        $item = $this->item('Twice', 120, $image);
        $this->line($this->order('completed'), $item, 1, 3.00);
        $item->archive();

        $lifecycle = app(CatalogueLifecycle::class);
        $stale = MenuItem::onlyArchived()->find($item->id);

        $first = $lifecycle->permanentlyDeleteMenuItem(MenuItem::onlyArchived()->find($item->id));
        $this->assertSame(CatalogueLifecycle::DELETED, $first['action']);
        $this->assertFileDoesNotExist(public_path($image));

        // A new file at the same path: if the second request tried to clean
        // up again, this is what it would take.
        file_put_contents(public_path($image), 'someone else\'s file now');

        $second = $lifecycle->permanentlyDeleteMenuItem($stale);
        $this->assertSame(CatalogueLifecycle::BLOCKED, $second['action']);
        $this->assertStringContainsString('no longer in the archive', $second['message']);
        $this->assertNull($second['cleanup_problem']);
        $this->assertFileExists(public_path($image), 'the image is removed exactly once');

        // And over HTTP.
        $this->forceDelete($this->owner(), $item->id)->assertRedirect(route('admin.archived'));
        $this->assertStringContainsString('not in the archive', session('errors')->first('error'));
    }

    // ══════════ F. permissions ══════════

    public function test_supervisor_and_staff_cannot_permanently_delete_a_sold_item(): void
    {
        $w = $this->world();
        $h = $this->soldHistory($w);
        $w['X']->archive();

        foreach (['supervisor', 'staff'] as $role) {
            $this->forceDelete($this->account($role, $this->branch->id), $w['X']->id)
                ->assertRedirect(route('admin.home'));
            $this->assertSame("You don't have permission to access that.", session('error'));
        }

        $this->assertSame(1, DB::table('menu_items')->where('id', $w['X']->id)->count());
        $this->assertSame(0, DB::table('order_items')->whereIn('order_id', [$h['c1']->id, $h['c2']->id, $h['c3']->id])->whereNull('menu_item_id')->count());
        $this->assertSame('0.00', (string) DB::table('order_items')->where('order_id', $h['c2']->id)->value('ingredient_cost'), 'no freeze either');
    }

    // ══════════ G. the image, for a SOLD item ══════════

    public function test_a_sold_items_image_is_removed_only_after_its_database_delete(): void
    {
        $image = $this->imageFile();
        $item = $this->item('Pictured', 120, $image);
        $line = $this->line($this->order('completed'), $item, 1, 3.00);
        $item->archive();

        $fileExistedAtRowDelete = null;
        $lineLinkAtRowDelete = 'unset';
        MenuItem::deleted(function (MenuItem $deleted) use ($item, $image, $line, &$fileExistedAtRowDelete, &$lineLinkAtRowDelete) {
            if ($deleted->id === $item->id) {
                $fileExistedAtRowDelete = file_exists(public_path($image));
                $lineLinkAtRowDelete = DB::table('order_items')->where('id', $line->id)->value('menu_item_id');
            }
        });

        $this->forceDelete($this->owner(), $item->id)->assertSessionHasNoErrors();

        $this->assertTrue($fileExistedAtRowDelete, 'the image must still exist when the row is deleted');
        $this->assertNull($lineLinkAtRowDelete, 'the database had already cut the link');
        $this->assertFileDoesNotExist(public_path($image));
    }

    public function test_a_failed_database_delete_of_a_sold_item_changes_nothing_at_all(): void
    {
        $image = $this->imageFile();
        $w = $this->world();
        $X = $w['X'];
        $X->image = $image;
        $X->save();
        $h = $this->soldHistory($w);
        $X->archive();
        $orderIds = [$h['c1']->id, $h['c2']->id, $h['c3']->id];
        $before = $this->lines($orderIds);

        MenuItem::deleting(function () {
            throw new \RuntimeException('simulated database failure');
        });

        $this->forceDelete($this->owner(), $X->id)->assertRedirect(route('admin.archived'));

        $this->assertNull(session('success'));
        $this->assertStringContainsString('Nothing was changed', session('errors')->first('error'));
        $this->assertSame(1, DB::table('menu_items')->where('id', $X->id)->count());
        $this->assertEquals($before, $this->lines($orderIds), 'the cost freeze rolled back with the failed delete');
        $this->assertFileExists(public_path($image));
    }

    public function test_a_shared_image_and_one_outside_the_upload_folder_are_both_kept(): void
    {
        $shared = $this->imageFile();
        $sold = $this->item('Shares', 120, $shared);
        $this->item('Keeps', 120, $shared);
        $this->line($this->order('completed'), $sold, 1, 3.00);
        $sold->archive();

        $this->forceDelete($this->owner(), $sold->id)->assertSessionHasNoErrors();
        $this->assertFileExists(public_path($shared), 'another item still uses it');

        $outside = 'SPD_TEST_outside_' . uniqid() . '.png';
        file_put_contents(public_path($outside), 'x');
        $this->createdFiles[] = public_path($outside);
        $stray = $this->item('Stray', 120, $outside);
        $this->line($this->order('completed'), $stray, 1, 3.00);
        $stray->archive();

        $this->forceDelete($this->owner(), $stray->id);
        $this->assertSame(0, DB::table('menu_items')->where('id', $stray->id)->count());
        $this->assertStringContainsString('left in place', session('errors')->first('error'));
        $this->assertFileExists(public_path($outside));
    }

    // ══════════ H. the Archived page ══════════

    /**
     * The confirm text for a sold item, read back the way the browser will:
     * Js::from() writes a single-quoted literal whose quotes, apostrophes,
     * newlines and <>& are all \u-escaped, so a name can no longer break the
     * dialog (with addslashes() a newline did, and a broken onsubmit submits
     * the form without asking).
     */
    public function test_the_sold_item_confirm_dialog_states_what_is_kept_and_survives_any_name(): void
    {
        $w = $this->world();
        $this->soldHistory($w);
        $w['X']->name = "Tita's \"Best\"\nLatte </script> & co {$this->run}";
        $w['X']->save();
        $w['X']->archive();

        $html = $this->actingAs($this->owner(), 'admin')->get(route('admin.archived'))->assertOk()->getContent();

        $action = preg_quote(route('admin.archived.menu-item.force-delete', $w['X']->id), '#');
        $this->assertMatchesRegularExpression('#<form action="' . $action . '"\s+method="POST"\s+onsubmit="return confirm\(\'([^"\']*)\'\)"#', $html);
        preg_match('#<form action="' . $action . '"\s+method="POST"\s+onsubmit="return confirm\(\'([^"\']*)\'\)"#', $html, $m);

        $text = json_decode('"' . $m[1] . '"');
        $this->assertIsString($text, 'the literal must be valid JSON-escaped text');

        $name = $w['X']->name;
        $this->assertSame(
            'Permanently delete "' . $name . '"? Its 3 past order lines are kept — receipts and sales reports will still show "'
                . $name . '". Only the link to this menu item is removed. This also removes 1 add-on assignment and 1 recipe ingredient. '
                . '1 past line had no recorded ingredient cost; today\'s estimated cost will be saved on it and marked as estimated. '
                . 'This cannot be undone.',
            $text
        );

        $this->assertStringNotContainsString("\n", $m[1], 'no raw newline inside the attribute');
        $this->assertStringNotContainsString('</script>', $m[1]);
    }

    // ══════════ I. reports ══════════

    public function test_top_selling_keeps_a_deleted_item_labelled_and_least_selling_and_the_forecast_leave_it_out(): void
    {
        $w = $this->world();
        $this->soldHistory($w);
        $w['X']->archive();
        $owner = $this->owner();

        $this->forceDelete($owner, $w['X']->id)->assertSessionHasNoErrors();

        $summary = $this->adminGet($owner, route('admin.summary', ['period' => 'today']))->assertOk();

        $best = $summary->viewData('bestSellers');
        $deletedRow = $best->first(fn ($r) => $r->menu_item_id === null);
        $this->assertNotNull($deletedRow, 'Top Selling keeps the deleted item');
        $this->assertSame($w['X']->name, $deletedRow->deleted_item_name);
        $this->assertSame(3, (int) $deletedRow->total_qty, 'completed lines only: C1 x2 + C2 x1');
        $this->assertStringContainsString($w['X']->name . "\n", $summary->getContent());
        $summary->assertSee('data-testid="top-seller-deleted"', false);
        $summary->assertSee('(deleted)');

        $this->assertNull($summary->viewData('leastSellers')->first(fn ($r) => $r->menu_item_id === null), 'Least Selling leaves it out');

        $forecast = app(DemandForecastService::class)->forMenuItems($this->branch->id, now()->subDays(30), now(), 50);
        foreach ($forecast['rows'] ?? [] as $row) {
            $this->assertNotNull($row['menu_item_id'], 'the forecast leaves deleted items out');
            $this->assertNotSame($w['X']->name, $row['menu_item_name']);
        }
    }

    /**
     * ACCEPTED, PINNED: two deleted items that shared a name, from two
     * branches, become one row in the All Branches view (there is no id left
     * to tell them apart) and it carries no branch label. Branch-scoped views
     * still keep them apart through orders.branch_id.
     */
    public function test_same_named_deleted_items_from_two_branches_merge_in_the_all_branches_view(): void
    {
        $other = Branch::create(['name' => self::P . ' Branch B ' . $this->run, 'code' => self::P . 'B' . substr($this->run, 0, 5), 'address' => 'x', 'is_active' => true]);
        $twinName = self::P . ' Twin ' . $this->run;
        $owner = $this->owner();

        foreach ([[$this->branch->id, 400], [$other->id, 300]] as [$branchId, $qty]) {
            $twin = $this->item('twin', 100, null, $branchId);
            $twin->name = $twinName;
            $twin->save();
            $this->line($this->order('completed', null, $branchId), $twin, $qty, 1.00);
            $twin->archive();
            $this->forceDelete($owner, $twin->id)->assertSessionHasNoErrors();
        }

        $all = app(ProfitCalculationService::class)->today('all');
        $rows = array_values(array_filter($all['items'], fn ($r) => $r['name'] === $twinName));
        $this->assertCount(1, $rows, 'merged into one breakdown row');
        $this->assertNull($rows[0]['menu_item_id']);
        $this->assertSame(700, $rows[0]['quantity']);

        $perBranch = app(ProfitCalculationService::class)->today($other->id);
        $this->assertSame(300, collect($perBranch['items'])->firstWhere('name', $twinName)['quantity'], 'a branch view keeps its own');

        $today = now()->toDateString();
        $analytics = $this->actingAs($owner, 'admin')->withSession(['selected_branch_id' => 'all'])
            ->get(route('admin.analytics', ['period' => 'custom', 'date_from' => $today, 'date_to' => $today]))->assertOk();
        $perf = collect($analytics->viewData('performanceRows'))->where('menu_item_name', $twinName)->values();
        $this->assertCount(1, $perf);
        $this->assertNull($perf[0]['branch_name'], 'no branch label for a merged deleted row');
    }

    // ══════════ J. the deduction safety net ══════════

    /**
     * Unreachable through the app (an item on an open order cannot be
     * permanently deleted), so the line is seeded directly. Completing the
     * order must still go through — the live line deducted — and the orphan
     * line must be logged with its order and line ids, not skipped silently.
     */
    public function test_an_orphan_line_on_an_open_order_is_logged_and_never_blocks_completion(): void
    {
        $w = $this->world();
        $order = $this->order('pending');
        $this->line($order, $w['Y'], 1, 0.00);
        $orphan = $this->line($order, null, 1, 0.00, null, self::P . ' Orphan ' . $this->run);

        Log::spy();

        $this->actingAs($this->owner(), 'admin')->put(route('admin.orders.complete', $order->id))->assertRedirect();

        $this->assertSame('completed', $order->fresh()->status, 'completion must never get stuck');
        $this->assertEquals(99.0, (float) $w['R']->fresh()->quantity, "the live line's recipe was still deducted");

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) use ($order, $orphan) {
            return str_contains($message, 'stock NOT deducted')
                && ($context['order_id'] ?? null) === $order->id
                && ($context['order_item_id'] ?? null) === $orphan->id;
        })->once();
    }

    // ══════════ K. the category cascade stays unreachable ══════════

    /**
     * With SET NULL, a category DELETE would cascade into its menu items and
     * cut every line's link with none of the guards above. removeCategory()
     * must therefore keep refusing to hard-delete a category that holds ANY
     * item — live or archived.
     */
    public function test_a_category_holding_any_item_is_never_hard_deleted(): void
    {
        $owner = $this->owner();

        $archivedOnly = Category::create(['name' => self::P . " ArchOnly {$this->run}", 'display_order' => 0, 'is_active' => true]);
        $a = $this->item('In archived-only cat', 50, null, null, $archivedOnly->id);
        $aLine = $this->line($this->order('completed'), $a, 1, 1.00);
        $a->archive();

        $this->actingAs($owner, 'admin')->delete(route('admin.add-category.delete', $archivedOnly->id));
        $this->assertSame(1, DB::table('categories')->where('id', $archivedOnly->id)->count(), 'archived, not deleted');
        $this->assertSame(1, DB::table('menu_items')->where('id', $a->id)->count());
        $this->assertSame($a->id, (int) DB::table('order_items')->where('id', $aLine->id)->value('menu_item_id'), 'the link is intact');

        $withLive = Category::create(['name' => self::P . " Live {$this->run}", 'display_order' => 0, 'is_active' => true]);
        $l = $this->item('In live cat', 50, null, null, $withLive->id);
        $lLine = $this->line($this->order('completed'), $l, 1, 1.00);

        $this->actingAs($owner, 'admin')->delete(route('admin.add-category.delete', $withLive->id));
        $this->assertStringContainsString('Cannot remove category', session('errors')->first('error'));
        $this->assertSame(1, DB::table('categories')->where('id', $withLive->id)->count());
        $this->assertSame($l->id, (int) DB::table('order_items')->where('id', $lLine->id)->value('menu_item_id'));
    }
}

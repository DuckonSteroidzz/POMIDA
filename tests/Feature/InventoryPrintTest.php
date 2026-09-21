<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Inventory Print — the printable stock report (Phase 2a, item 5).
 *
 * The brief's constraints, and where each is proved below:
 *
 *  - REUSE THE PRINT ARCHITECTURE. admin.inventory.print is the same
 *    printInFrame() shape as admin.analytics.print: a standalone print-only
 *    Blade, fetched into a hidden iframe by the page's Print button. There is
 *    no second reporting mechanism — see test_the_print_button_uses_the_shared
 *    _print_in_frame_helper and test_the_print_view_is_a_standalone_document.
 *
 *  - THE INVENTORY PAGE'S OWN notArchived() ROW SCOPE. Not a re-implementation:
 *    the screen, the CSV and this report now share one
 *    AdminController::inventoryRowsForScope(). Proved by archiving an item and
 *    asserting it leaves all three at once.
 *
 *  - THE INVENTORY PAGE'S OWN STATUS RULE. quantity <= 0 is Out of Stock;
 *    quantity > 0 AND quantity <= low_stock_alert is Low. The boundary cases
 *    are what distinguish this rule from a plausible second one, so they are
 *    tested individually: exactly 0, exactly at the threshold, and one above it.
 *
 *  - BRANCH, BUSINESS NAME, GENERATED DATE/TIME on the sheet.
 *
 *  - NAVIGATION/ACTION CONTROLS HIDDEN WHEN PRINTING.
 *
 *  - NO CHANGE TO INVENTORY BUSINESS LOGIC. The Inventory page's own rendering
 *    is re-asserted here unchanged.
 */
class InventoryPrintTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'INVPRINT';

    private array $highWater = [];
    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['branches', 'inventory', 'users'] as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
        }

        $this->branchA = Branch::create([
            'name'      => self::PREFIX . ' Branch A ' . uniqid(),
            'code'      => 'IPA' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address A',
            'is_active' => true,
        ]);

        $this->branchB = Branch::create([
            'name'      => self::PREFIX . ' Branch B ' . uniqid(),
            'code'      => 'IPB' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' address B',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
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

        $this->assertSame(0, DB::table('inventory')->where('item_name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('users')->where('name', 'like', self::PREFIX . '%')->count());
        $this->assertSame(0, DB::table('branches')->where('name', 'like', self::PREFIX . '%')->count());

        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function userWithRole(string $role, ?Branch $branch): User
    {
        return User::create([
            'name'      => self::PREFIX . ' ' . ucfirst($role) . ' ' . uniqid(),
            'email'     => 'invprint-' . uniqid() . '@example.test',
            'password'  => bcrypt('Sup3rStr0ng!Pass'),
            'role'      => $role,
            'branch_id' => $branch?->id,
            'is_active' => true,
        ]);
    }

    private function item(Branch $branch, string $label, float $qty, float $alert, float $unitCost = 10.0): Inventory
    {
        return Inventory::create([
            'branch_id'       => $branch->id,
            'item_name'       => self::PREFIX . ' ' . $label,
            'item_code'       => 'IP' . strtoupper(substr(uniqid(), -10)),
            'quantity'        => $qty,
            'unit'            => 'pcs',
            'unit_cost'       => $unitCost,
            'low_stock_alert' => $alert,
            'is_active'       => true,
        ]);
    }

    /** Render the print view as the admin, with the branch picker on $branch. */
    private function printAs(User $user, ?Branch $branch = null): string
    {
        $request = $this->actingAs($user, 'admin');

        if ($branch !== null) {
            $request = $request->withSession(['selected_branch_id' => $branch->id]);
        }

        return $request->get(route('admin.inventory.print'))->assertOk()->getContent();
    }

    /** The status pill rendered for one item name, or null when absent. */
    private function statusFor(string $html, string $label): ?string
    {
        $name = preg_quote(self::PREFIX . ' ' . $label, '/');

        // The row is <td>name</td> ... <td><span class="iv-badge ...">STATUS</span></td>
        if (preg_match('/' . $name . '<\/td>.*?iv-badge[^>]*>([^<]+)<\/span>/s', $html, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE STATUS RULE — the page's rule, boundaries included
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_status_rule_matches_the_inventory_pages_own_rule(): void
    {
        // The four cases that separate this rule from any plausible second one.
        $this->item($this->branchA, 'Zero Qty', 0, 10);        // quantity <= 0  -> OUT
        $this->item($this->branchA, 'Negative Qty', -5, 10);   // quantity <= 0  -> OUT
        $this->item($this->branchA, 'At Threshold', 10, 10);   // 0 < q <= alert -> LOW
        $this->item($this->branchA, 'Above Threshold', 11, 10); // q > alert     -> IN

        $html = $this->printAs($this->admin(), $this->branchA);

        $this->assertSame('Out of Stock', $this->statusFor($html, 'Zero Qty'));
        $this->assertSame('Out of Stock', $this->statusFor($html, 'Negative Qty'));
        $this->assertSame('Low Stock', $this->statusFor($html, 'At Threshold'));
        $this->assertSame('In Stock', $this->statusFor($html, 'Above Threshold'));
    }

    public function test_out_of_stock_wins_over_low_when_a_row_satisfies_both(): void
    {
        // quantity 0 with alert 10 matches BOTH written conditions. The page
        // evaluates out-before-low so it can only land in one bucket; the
        // report must land it in the same one, or the two counts disagree.
        $this->item($this->branchA, 'Both Conditions', 0, 10);

        $html = $this->printAs($this->admin(), $this->branchA);

        $this->assertSame('Out of Stock', $this->statusFor($html, 'Both Conditions'));
        $this->assertStringNotContainsString('Low Stock</span>', $html);
    }

    public function test_the_summary_counts_agree_with_the_rows_above_them(): void
    {
        $this->item($this->branchA, 'A Out', 0, 5);
        $this->item($this->branchA, 'B Low', 3, 5);
        $this->item($this->branchA, 'C Low', 5, 5);
        $this->item($this->branchA, 'D In', 50, 5);

        $html = $this->printAs($this->admin(), $this->branchA);

        // 4 items: 1 out, 2 low, 1 in.
        $this->assertSame(1, substr_count($html, '>Out of Stock</span>'));
        $this->assertSame(2, substr_count($html, '>Low Stock</span>'));
        $this->assertSame(1, substr_count($html, '>In Stock</span>'));

        // And the KPI tiles say the same, in the page's own order.
        $this->assertMatchesRegularExpression('/Total Items<\/p>\s*<p class="iv-kpi-value">4</', $html);
        $this->assertMatchesRegularExpression('/In Stock<\/p>\s*<p class="iv-kpi-value">1</', $html);
        $this->assertMatchesRegularExpression('/Low Stock<\/p>\s*<p class="iv-kpi-value">2</', $html);
        $this->assertMatchesRegularExpression('/Out of Stock<\/p>\s*<p class="iv-kpi-value">1</', $html);
    }

    public function test_the_stock_value_total_matches_quantity_times_unit_cost(): void
    {
        $this->item($this->branchA, 'Valued One', 4, 1, 25.00);   // 100.00
        $this->item($this->branchA, 'Valued Two', 2.5, 1, 10.00); // 25.00

        $html = $this->printAs($this->admin(), $this->branchA);

        $this->assertStringContainsString('₱125.00', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ROW SCOPE — notArchived(), shared with the page and the CSV
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_archived_item_leaves_the_print_report_the_page_and_the_csv_together(): void
    {
        $kept = $this->item($this->branchA, 'Still Listed', 20, 5);
        $gone = $this->item($this->branchA, 'To Be Archived', 20, 5);

        $admin = $this->admin();

        // Present on all three before archiving.
        $this->assertStringContainsString($gone->item_name, $this->printAs($admin, $this->branchA));
        $this->assertStringContainsString(
            $gone->item_name,
            $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => $this->branchA->id])
                ->get(route('admin.inventory'))->assertOk()->getContent()
        );
        $this->assertStringContainsString(
            $gone->item_name,
            $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => $this->branchA->id])
                ->get(route('admin.inventory.export'))->assertOk()->streamedContent()
        );

        $gone->archive();

        // Gone from all three after.
        $printHtml = $this->printAs($admin, $this->branchA);
        $pageHtml = $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.inventory'))->assertOk()->getContent();
        $csv = $this->actingAs($admin, 'admin')->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.inventory.export'))->assertOk()->streamedContent();

        $this->assertStringNotContainsString($gone->item_name, $printHtml);
        $this->assertStringNotContainsString($gone->item_name, $pageHtml);
        $this->assertStringNotContainsString($gone->item_name, $csv);

        // The refusal did not take the rest of the list with it.
        $this->assertStringContainsString($kept->item_name, $printHtml);
        $this->assertStringContainsString($kept->item_name, $pageHtml);
        $this->assertStringContainsString($kept->item_name, $csv);
    }

    public function test_the_archived_item_is_still_in_the_database_not_deleted(): void
    {
        // notArchived() is a LIST filter, not a delete. Pins that the report
        // reuses the filter rather than anything destructive.
        $item = $this->item($this->branchA, 'Archived Not Deleted', 20, 5);
        $item->archive();

        $this->assertNotNull(Inventory::find($item->id));
        $this->assertTrue(Inventory::find($item->id)->isArchived());
    }

    // ══════════════════════════════════════════════════════════════════════
    // BRANCH SCOPING
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_branch_locked_supervisor_prints_only_their_own_branch(): void
    {
        $mine = $this->item($this->branchA, 'Mine', 20, 5);
        $theirs = $this->item($this->branchB, 'Theirs', 20, 5);

        $html = $this->printAs($this->userWithRole('supervisor', $this->branchA));

        $this->assertStringContainsString($mine->item_name, $html);
        $this->assertStringNotContainsString($theirs->item_name, $html);
        $this->assertStringContainsString($this->branchA->name, $html);
    }

    public function test_a_locked_supervisor_cannot_widen_the_scope_from_the_querystring(): void
    {
        // The scope is read from getSelectedBranch(), never from the request.
        $theirs = $this->item($this->branchB, 'Theirs', 20, 5);

        $html = $this->actingAs($this->userWithRole('supervisor', $this->branchA), 'admin')
            ->withSession(['selected_branch_id' => $this->branchB->id])
            ->get(route('admin.inventory.print', [
                'branch_id' => $this->branchB->id,
                'branch'    => $this->branchB->id,
                'scope'     => 'all',
            ]))->assertOk()->getContent();

        $this->assertStringNotContainsString($theirs->item_name, $html);
        $this->assertStringNotContainsString('All Branches', $html);
        $this->assertStringContainsString($this->branchA->name, $html);
    }

    public function test_the_admin_can_print_a_single_branch_or_all_branches(): void
    {
        $a = $this->item($this->branchA, 'In A', 20, 5);
        $b = $this->item($this->branchB, 'In B', 20, 5);

        $admin = $this->admin();

        $onlyA = $this->printAs($admin, $this->branchA);
        $this->assertStringContainsString($a->item_name, $onlyA);
        $this->assertStringNotContainsString($b->item_name, $onlyA);

        $all = $this->actingAs($admin, 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->get(route('admin.inventory.print'))->assertOk()->getContent();

        $this->assertStringContainsString('All Branches', $all);
        $this->assertStringContainsString($a->item_name, $all);
        $this->assertStringContainsString($b->item_name, $all);
    }

    public function test_staff_may_print_the_inventory_they_may_already_read(): void
    {
        // "Always pair refusal with acceptance": staff are refused Analytics
        // (see ReportDateRangeValidationTest) but Inventory is Y | Y | Y, and
        // the printed sheet discloses nothing the table does not — so the
        // accepted path has to actually work for them.
        $mine = $this->item($this->branchA, 'Staff Visible', 20, 5);
        $theirs = $this->item($this->branchB, 'Staff Invisible', 20, 5);

        $html = $this->printAs($this->userWithRole('staff', $this->branchA));

        $this->assertStringContainsString($mine->item_name, $html);
        $this->assertStringNotContainsString($theirs->item_name, $html);
    }

    public function test_a_logged_out_visitor_cannot_print_the_inventory(): void
    {
        $this->item($this->branchA, 'Secret Stock', 20, 5);

        $response = $this->get(route('admin.inventory.print'));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRINT-SAFE RENDERING
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_sheet_states_branch_business_name_and_generated_time(): void
    {
        $this->item($this->branchA, 'Anything', 20, 5);

        $html = $this->printAs($this->admin(), $this->branchA);

        $this->assertStringContainsString('Peachy Cakes &amp; Deli Cafe', $html);
        $this->assertStringContainsString('Inventory Report', $html);
        $this->assertStringContainsString('data-testid="print-branch"', $html);
        $this->assertStringContainsString($this->branchA->name, $html);
        $this->assertStringContainsString('data-testid="print-generated"', $html);
        $this->assertStringContainsString(now()->format('M d, Y'), $html);
        $this->assertStringContainsString('Printed by', $html);
    }

    public function test_navigation_and_action_controls_are_hidden_when_printing(): void
    {
        $this->item($this->branchA, 'Anything', 20, 5);

        $html = $this->printAs($this->admin(), $this->branchA);

        // The @media print block hides the document's own controls.
        $this->assertMatchesRegularExpression(
            '/@media\s+print\s*\{[^}]*\.iv-actions-bar[^}]*display:\s*none/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/@media\s+print\s*\{[^}]*\.no-print[^}]*display:\s*none/s',
            $html
        );

        // None of the admin chrome is in this document to begin with.
        $this->assertStringNotContainsString('admin-sidebar', $html);
        $this->assertStringNotContainsString('pc-branchbar', $html);
        $this->assertStringNotContainsString('Stock In', $html);
        $this->assertStringNotContainsString('Add Item', $html);
        $this->assertStringNotContainsString('Deleted Items', $html);
    }

    public function test_the_print_view_is_a_standalone_document(): void
    {
        // Same shape as analytics-print: its own <html>, not the admin layout.
        $html = $this->printAs($this->admin(), $this->branchA);

        $this->assertStringStartsWith('<!DOCTYPE html>', trim($html));
        $this->assertStringContainsString('<title>Inventory Report', $html);
        $this->assertStringContainsString('window.print()', $html);
    }

    public function test_the_print_button_uses_the_shared_print_in_frame_helper(): void
    {
        // Reuse, not a second architecture: the button calls the same
        // printInFrame() that Analytics and Completed Orders print through.
        $this->item($this->branchA, 'Anything', 20, 5);

        $page = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.inventory'))->assertOk()->getContent();

        $this->assertStringContainsString('printInventory()', $page);
        $this->assertStringContainsString("printInFrame('" . route('admin.inventory.print') . "')", $page);
    }

    public function test_an_empty_branch_prints_a_sheet_rather_than_a_blank_page(): void
    {
        // Nothing seeded for this branch. The report still has to be a report.
        $emptyBranch = Branch::create([
            'name'      => self::PREFIX . ' Empty ' . uniqid(),
            'code'      => 'IPE' . strtoupper(substr(uniqid(), -6)),
            'address'   => self::PREFIX . ' empty address',
            'is_active' => true,
        ]);

        $html = $this->printAs($this->admin(), $emptyBranch);

        $this->assertStringContainsString('No inventory items for this branch.', $html);
        $this->assertStringContainsString($emptyBranch->name, $html);
        $this->assertStringContainsString('Printed by', $html);
    }

    public function test_fractional_quantities_are_not_rounded_away_on_paper(): void
    {
        // inventory.quantity is decimal(12,3) and recipes deduct fractions;
        // printing at 2 decimals would round stock off the stocktake sheet.
        $this->item($this->branchA, 'Fractional', 2.125, 1);

        $html = $this->printAs($this->admin(), $this->branchA);

        $this->assertStringContainsString('2.125', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // NO CHANGE TO INVENTORY'S OWN BEHAVIOUR
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_inventory_page_itself_is_unchanged(): void
    {
        $this->item($this->branchA, 'Zero Qty', 0, 10);
        $this->item($this->branchA, 'At Threshold', 10, 10);
        $this->item($this->branchA, 'Above Threshold', 11, 10);

        $html = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => $this->branchA->id])
            ->get(route('admin.inventory'))->assertOk()->getContent();

        // Still the page it was: its own stat strip, its own export, and now
        // the print button beside it.
        $this->assertStringContainsString('Export CSV', $html);
        $this->assertStringContainsString('Track stock levels', $html);
        $this->assertStringContainsString('Print', $html);

        foreach (['Zero Qty', 'At Threshold', 'Above Threshold'] as $label) {
            $this->assertStringContainsString(self::PREFIX . ' ' . $label, $html);
        }
    }
}

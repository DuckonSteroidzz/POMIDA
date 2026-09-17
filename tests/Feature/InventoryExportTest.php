<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Inventory → Export CSV (2026-09-15).
 *
 * The owner asked for a plain CSV/Excel-style download of the Inventory
 * table, not a browser print view. No spreadsheet library is installed in
 * this project, so exportInventory() streams a CSV built with fputcsv() —
 * same shape as the existing Summary "Export CSV" (exportOrders()).
 *
 * What this asserts:
 *  - The response is a downloadable CSV with the expected filename shape.
 *  - Its rows match what showInventory() puts on screen: same items, same
 *    Quantity / Unit / Low Stock Alert / Stock Value / Status columns.
 *  - Branch scoping is the SAME rule the page itself uses (ResolvesBranchScope)
 *    — a staff member's export never contains another branch's stock.
 *  - The SUMMARY block at the bottom carries the same five figures as the
 *    page's stat strip (Total Items / In Stock / Low Stock / Out of Stock /
 *    Total Stock Value), computed the same way — out first, then low, then
 *    the remainder in stock, plus a running quantity x unit_cost total.
 */
class InventoryExportTest extends TestCase
{
    use DatabaseTransactions;

    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function makeItem(array $attrs = []): Inventory
    {
        $staff = $this->staff();

        return Inventory::create(array_merge([
            'branch_id'       => (int) $staff->branch_id,
            'item_name'       => 'IVX Test Item ' . uniqid(),
            'item_code'       => 'IVX-' . strtoupper(substr(uniqid(), -8)),
            'category'        => null,
            'quantity'        => 10,
            'unit'            => 'pc',
            'low_stock_alert' => 5,
            'unit_cost'       => 12.50,
            'supplier'        => null,
            'is_active'       => true,
        ], $attrs));
    }

    public function test_export_button_links_to_the_export_route(): void
    {
        $page = $this->actingAs($this->staff(), 'admin')->get('/admin/inventory');

        $page->assertOk();
        $page->assertSee(route('admin.inventory.export'), false);
    }

    public function test_export_streams_a_csv_with_a_sensible_filename(): void
    {
        $response = $this->actingAs($this->staff(), 'admin')->get('/admin/inventory/export');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('inventory_', $disposition);
        $this->assertStringContainsString('.csv', $disposition);
    }

    public function test_export_rows_match_the_on_screen_columns(): void
    {
        // 7.25 x 12.50 = 90.625, rounds to 90.63 — chosen so a truncation bug
        // would fail this instead of accidentally landing on the right figure.
        $item = $this->makeItem(['quantity' => 7.25, 'unit_cost' => 12.50, 'low_stock_alert' => 5]);

        $response = $this->actingAs($this->staff(), 'admin')->get('/admin/inventory/export');
        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString($item->item_name, $csv);
        $this->assertStringContainsString('7.25', $csv);
        $this->assertStringContainsString('90.63', $csv);
        $this->assertStringContainsString('In Stock', $csv);
    }

    public function test_export_marks_out_of_stock_and_low_stock_status_correctly(): void
    {
        $out = $this->makeItem(['quantity' => 0, 'low_stock_alert' => 5]);
        $low = $this->makeItem(['quantity' => 3, 'low_stock_alert' => 5]);

        $response = $this->actingAs($this->staff(), 'admin')->get('/admin/inventory/export');
        $csv = $response->streamedContent();

        $lines = array_filter(explode("\n", $csv));
        $outLine = collect($lines)->first(fn($l) => str_contains($l, $out->item_name));
        $lowLine = collect($lines)->first(fn($l) => str_contains($l, $low->item_name));

        $this->assertNotNull($outLine);
        $this->assertNotNull($lowLine);
        $this->assertStringContainsString('Out of Stock', $outLine);
        $this->assertStringContainsString('Low Stock', $lowLine);
    }

    public function test_export_summary_matches_the_pages_stat_tiles(): void
    {
        $staff = $this->staff();

        // Whatever this branch already carries from other tests/fixtures,
        // counted BEFORE this test adds its own three rows — the expected
        // figures below are "existing + these three", not "only these three",
        // so nothing needs deleting.
        $before = Inventory::where('branch_id', $staff->branch_id)->get();
        $baseOut = $before->filter(fn($i) => $i->quantity <= 0)->count();
        $baseLow = $before->filter(fn($i) => $i->quantity > 0 && $i->quantity <= $i->low_stock_alert)->count();
        $baseOk = $before->count() - $baseOut - $baseLow;
        $baseValue = $before->sum(fn($i) => (float) $i->quantity * (float) $i->unit_cost);

        $this->makeItem(['quantity' => 0, 'unit_cost' => 10, 'low_stock_alert' => 5]);   // out
        $this->makeItem(['quantity' => 3, 'unit_cost' => 4, 'low_stock_alert' => 5]);    // low
        $this->makeItem(['quantity' => 20, 'unit_cost' => 2.5, 'low_stock_alert' => 5]); // in stock

        // Same three-way split and running total the Inventory page's own
        // @php block computes — independently re-derived here, not copied
        // from the controller, so this test would fail if either drifted.
        $expectedOut = $baseOut + 1;
        $expectedLow = $baseLow + 1;
        $expectedOk = $baseOk + 1;
        $expectedTotal = $before->count() + 3;
        $expectedValue = $baseValue + (0 * 10) + (3 * 4) + (20 * 2.5);

        $page = $this->actingAs($staff, 'admin')->get('/admin/inventory');
        $page->assertOk();
        $page->assertSee('₱' . number_format($expectedValue, 2), false);

        $response = $this->actingAs($staff, 'admin')->get('/admin/inventory/export');
        $csv = $response->streamedContent();

        // fputcsv quotes any field containing a space, so the labels below
        // arrive quoted (e.g. "Total Items",10) — matched as printed, not
        // guessed at.
        $this->assertStringContainsString('SUMMARY', $csv);
        $this->assertStringContainsString('"Total Items",' . $expectedTotal, $csv);
        $this->assertStringContainsString('"In Stock",' . $expectedOk, $csv);
        $this->assertStringContainsString('"Low Stock",' . $expectedLow, $csv);
        $this->assertStringContainsString('"Out of Stock",' . $expectedOut, $csv);
        $this->assertStringContainsString('"Total Stock Value",' . number_format($expectedValue, 2, '.', ''), $csv);
    }

    public function test_staff_export_never_contains_another_branchs_item(): void
    {
        $staff = $this->staff();
        $ownItem = $this->makeItem();

        $otherBranchId = \App\Models\Branch::where('id', '!=', $staff->branch_id)->value('id');
        $foreignItem = Inventory::create([
            'branch_id'       => $otherBranchId,
            'item_name'       => 'IVX Foreign Item ' . uniqid(),
            'item_code'       => 'IVX-' . strtoupper(substr(uniqid(), -8)),
            'category'        => null,
            'quantity'        => 10,
            'unit'            => 'pc',
            'low_stock_alert' => 5,
            'unit_cost'       => 5,
            'supplier'        => null,
            'is_active'       => true,
        ]);

        $response = $this->actingAs($staff, 'admin')->get('/admin/inventory/export');
        $csv = $response->streamedContent();

        $this->assertStringContainsString($ownItem->item_name, $csv);
        $this->assertStringNotContainsString($foreignItem->item_name, $csv);
    }
}

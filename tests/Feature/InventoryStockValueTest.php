<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Inventory page shows what the stock is worth (2026-09-03).
 *
 * WHAT THIS FOLLOWS
 * ------------------
 * showInventory() already scopes $inventory to the selected branch — 'all'
 * for an admin with no branch chosen, a specific branch_id for an admin who
 * picked one, and ALWAYS the staff member's own branch_id for staff (see
 * ResolvesBranchScope). The four existing KPI tiles (Total Items / In Stock /
 * Low Stock / Out of Stock) are computed in the view's own @php block from
 * that same $inventory collection — not in the controller, not stored. Total
 * Stock Value follows exactly that pattern: computed in the same @php block,
 * from the same unfiltered collection, so it is automatically branch-scoped
 * the same way and never drifts from the other tiles.
 *
 * THE FILTER GUARANTEE
 * ---------------------
 * The search box and status dropdown are pure client-side JS
 * (ivMatches()/ivRender()) that hide and show already-rendered <tr> rows —
 * every row for the branch is in the DOM from the first response, and the
 * PHP-computed tiles (including this one) never change value no matter what
 * is typed or selected. That is asserted here at the HTTP level with real
 * query-string parameters a filtered request could plausibly carry, rather
 * than by reading the JS and assuming.
 */
class InventoryStockValueTest extends TestCase
{
    use DatabaseTransactions;

    private function staff(): User
    {
        // Locked to its own branch_id by ResolvesBranchScope — no picker,
        // no ambiguity about which branch this test is scoped to.
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function makeItem(array $attrs = []): Inventory
    {
        $staff = $this->staff();

        return Inventory::create(array_merge([
            'branch_id'       => (int) $staff->branch_id,
            'item_name'       => 'ISV Test Item ' . uniqid(),
            'item_code'       => 'ISV-' . strtoupper(substr(uniqid(), -8)),
            'category'        => null,
            'quantity'        => 10,
            'unit'            => 'pc',
            'low_stock_alert' => 5,
            'unit_cost'       => 12.50,
            'supplier'        => null,
            'is_active'       => true,
        ], $attrs));
    }

    /*
    |--------------------------------------------------------------------------
    | PER-ROW STOCK VALUE
    |--------------------------------------------------------------------------
    */

    public function test_a_row_shows_quantity_times_unit_cost_as_pesos(): void
    {
        // 7.25 x 12.50 = 90.625, which rounds to 90.63 — chosen precisely so a
        // truncation bug (e.g. casting to int) would fail this test instead of
        // accidentally landing on the right figure.
        $item = $this->makeItem(['quantity' => 7.25, 'unit_cost' => 12.50]);

        $page = $this->actingAs($this->staff(), 'admin')->get('/admin/inventory');

        $page->assertOk();
        $page->assertSee($item->item_name);
        $page->assertSee('₱90.63', false);
    }

    public function test_a_zero_unit_cost_renders_as_php_0_00_and_the_page_still_loads(): void
    {
        $item = $this->makeItem(['quantity' => 50, 'unit_cost' => 0]);

        $page = $this->actingAs($this->staff(), 'admin')->get('/admin/inventory');

        $page->assertOk();
        $page->assertSee($item->item_name);

        // The row's own cell, not merely "a peso sign somewhere on the page" —
        // narrowed to the row containing this item's name.
        $body = $page->getContent();
        $rowStart = strpos($body, $item->item_name);
        $this->assertNotFalse($rowStart, 'the item row was not found in the response');

        $rowEnd = strpos($body, '</tr>', $rowStart);
        $row = substr($body, $rowStart, $rowEnd - $rowStart);

        $this->assertStringContainsString('₱0.00', $row);
    }

    /*
    |--------------------------------------------------------------------------
    | HEADER TOTAL
    |--------------------------------------------------------------------------
    */

    public function test_the_header_total_equals_the_sum_across_the_branch(): void
    {
        $staff = $this->staff();

        $a = $this->makeItem(['quantity' => 4, 'unit_cost' => 15.00]);   // 60.00
        $b = $this->makeItem(['quantity' => 3, 'unit_cost' => 8.75]);    // 26.25

        $expected = (float) Inventory::where('branch_id', $staff->branch_id)
            ->get()
            ->sum(fn ($row) => (float) $row->quantity * (float) $row->unit_cost);

        $page = $this->actingAs($staff, 'admin')->get('/admin/inventory');

        $page->assertOk();
        $page->assertSee('Total Stock Value');
        $page->assertSee('₱' . number_format($expected, 2), false);
    }

    public function test_the_header_total_is_unchanged_by_a_search_term(): void
    {
        $staff = $this->staff();
        $needle = $this->makeItem(['quantity' => 2, 'unit_cost' => 100.00]);
        $other  = $this->makeItem(['quantity' => 9, 'unit_cost' => 3.00]);

        $unfiltered = $this->actingAs($staff, 'admin')->get('/admin/inventory');
        // The search box is client-side JS with no server round trip — a
        // request carrying a stray query string is exactly what a bookmarked
        // or reloaded filtered URL would send, and the header total must
        // still answer for the WHOLE branch.
        $filtered = $this->actingAs($staff, 'admin')->get('/admin/inventory?search=' . urlencode($needle->item_name));

        $unfiltered->assertOk();
        $filtered->assertOk();

        preg_match('/Total Stock Value.*?₱[\d,]+\.\d{2}/s', $unfiltered->getContent(), $m1);
        preg_match('/Total Stock Value.*?₱[\d,]+\.\d{2}/s', $filtered->getContent(), $m2);

        $this->assertNotEmpty($m1, 'could not find the Total Stock Value tile in the unfiltered response');
        $this->assertNotEmpty($m2, 'could not find the Total Stock Value tile in the filtered response');
        $this->assertSame($m1[0], $m2[0], 'the header total changed when a search query string was present');
    }

    public function test_the_header_total_is_unchanged_by_a_status_filter(): void
    {
        $staff = $this->staff();
        $this->makeItem(['quantity' => 0, 'unit_cost' => 50.00]);   // out of stock
        $this->makeItem(['quantity' => 20, 'unit_cost' => 4.00]);   // in stock

        $all = $this->actingAs($staff, 'admin')->get('/admin/inventory');
        $out = $this->actingAs($staff, 'admin')->get('/admin/inventory?status=out');

        $all->assertOk();
        $out->assertOk();

        preg_match('/Total Stock Value.*?₱[\d,]+\.\d{2}/s', $all->getContent(), $m1);
        preg_match('/Total Stock Value.*?₱[\d,]+\.\d{2}/s', $out->getContent(), $m2);

        $this->assertNotEmpty($m1);
        $this->assertNotEmpty($m2);
        $this->assertSame($m1[0], $m2[0], 'the header total changed when a status filter query string was present');
    }

    /*
    |--------------------------------------------------------------------------
    | CLEANUP HELPER (high-water-mark verification lives in the test runner
    | script, not here — this file only creates rows inside a rolled-back
    | DatabaseTransactions wrapper).
    |--------------------------------------------------------------------------
    */
}

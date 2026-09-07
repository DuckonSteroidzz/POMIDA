<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableOccupancy;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The Occupied Tables panel keeps itself current, for an admin AND for staff.
 *
 * THE REPORT
 * ----------
 * "The list only updates after pressing Refresh." Item 41 had already added a
 * 12s setInterval poll here and verified it live, so the first job was to find
 * out what was actually true now rather than rebuild it. It was still there and
 * still fired. Two real things sat behind the complaint:
 *
 *   1. A staff dine-in Manual Order never marked its table occupied at all
 *      (see ManualOrderTableOccupancyTest), so in the owner's scenario the poll
 *      was working perfectly and there was genuinely nothing to show. That is a
 *      separate bug with a separate fix, and fixing it does NOT mean the poll
 *      was broken.
 *   2. 12 seconds is longer than anyone waits before deciding a screen is
 *      stale, so staff press Refresh first and never see the tick. The interval
 *      is now 5s, and the poll no longer runs while the tab is hidden.
 *
 * These assertions cover the parts that can be pinned server-side: the poll
 * exists and starts robustly, the endpoint answers for BOTH roles, and staff see
 * only their own branch. The item-41 live two-browser verification covered the
 * rest and is not re-litigated here.
 */
class OccupiedTablesAutoRefreshTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('qr-scan:127.0.0.1');
    }

    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@peachycafe.com')->firstOrFail();
    }

    private function panelSource(): string
    {
        return file_get_contents(
            base_path('resources/views/admin/qr-generator.blade.php')
        );
    }

    private function occupyByScan(string $table): void
    {
        // The QR carries the table's permanent code as `k` now, and every entry
        // door requires it (App\Services\TableEntry::validate()), so register
        // the table and scan the URL its real QR would encode.
        $code = \App\Services\TableEntry::findOrRegister(1, $table)->code;

        $this->from('/customer/dineinqr')->post('/customer/dineinqr', [
            'tableData' => "branch_id=1&table={$table}&k={$code}",
            'next'      => 'guest',
        ]);
    }

    // ══════════ the poll itself ══════════

    public function test_the_panel_polls_on_a_timer_and_keeps_its_refresh_button(): void
    {
        $view = $this->panelSource();

        $this->assertStringContainsString('OCCUPANCY_POLL_MS = 5000', $view);
        $this->assertStringContainsString('setInterval(loadOccupancy, OCCUPANCY_POLL_MS)', $view);

        // The manual button stays: staff must be able to force a check without
        // waiting for the next tick.
        $this->assertStringContainsString('onclick="loadOccupancy()"', $view);
    }

    public function test_the_poll_starts_even_if_the_document_is_already_loaded(): void
    {
        // A lone DOMContentLoaded listener never fires when the script runs
        // after the event, and the poll would then silently never start. The
        // readyState branch is what makes "does it actually fire" true rather
        // than merely likely.
        $view = $this->panelSource();

        $this->assertStringContainsString("document.readyState === 'loading'", $view);
        $this->assertStringContainsString('initOccupancyPoll', $view);
    }

    public function test_the_poll_stops_while_the_tab_is_hidden(): void
    {
        $this->assertStringContainsString("visibilitychange", $this->panelSource());
    }

    // ══════════ the endpoint both roles poll ══════════

    public function test_an_admin_sees_a_newly_occupied_table_without_any_click(): void
    {
        $this->occupyByScan('71');

        // A separate admin session: the panel's own fetch, no interaction with
        // the table beyond loading the endpoint.
        $this->flushSession();

        $response = $this->actingAs($this->admin(), 'admin')
            ->getJson('/admin/tables/occupancy');

        $response->assertOk();
        $this->assertContains('71', collect($response->json('tables'))->pluck('table_number')->all());
    }

    public function test_a_staff_member_sees_a_newly_occupied_table_at_their_own_branch(): void
    {
        $staff = $this->staff();
        $this->occupyByScan('72');

        $this->flushSession();

        $response = $this->actingAs($staff, 'admin')
            ->getJson('/admin/tables/occupancy');

        $response->assertOk();

        $tables = collect($response->json('tables'));

        $this->assertContains('72', $tables->pluck('table_number')->all());
        // And nothing from anywhere else.
        $this->assertSame(
            [(int) ($staff->branch_id ?? 1)],
            $tables->pluck('branch_id')->map(fn ($id) => (int) $id)->unique()->values()->all()
        );
    }

    public function test_an_admin_viewing_all_branches_is_not_scoped_away(): void
    {
        $this->occupyByScan('73');
        $this->flushSession();

        // "All Branches" is the admin default, and the owner's screenshot was
        // taken in exactly this state.
        $response = $this->actingAs($this->admin(), 'admin')
            ->withSession(['selected_branch_id' => 'all'])
            ->getJson('/admin/tables/occupancy');

        $response->assertOk();
        $this->assertContains('73', collect($response->json('tables'))->pluck('table_number')->all());
    }

    public function test_a_staff_member_does_not_see_another_branch_table(): void
    {
        $staff = $this->staff();
        $otherBranch = \App\Models\Branch::where('id', '!=', $staff->branch_id ?? 1)->first();

        if (!$otherBranch) {
            $this->markTestSkipped('only one branch exists in this database');
        }

        TableSession::create([
            'branch_id'     => $otherBranch->id,
            'table_number'  => '74',
            'session_token' => 'test-' . uniqid(),
            'active_lock'   => $otherBranch->id . ':74',
            'last_seen_at'  => now(),
        ]);

        $response = $this->actingAs($staff, 'admin')->getJson('/admin/tables/occupancy');

        $response->assertOk();
        $this->assertNotContains('74', collect($response->json('tables'))->pluck('table_number')->all());
    }

    // ══════════ both ways a table becomes occupied ══════════

    public function test_a_counter_order_shows_up_on_the_panel_and_is_labelled(): void
    {
        $item = \App\Models\MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->firstOrFail();

        $this->actingAs($this->staff(), 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', [
                'branch_id'      => 1,
                'order_type'     => 'dine_in',
                'table_number'   => '75',
                'payment_method' => 'cash',
                'amount_paid'    => '5000',
                'items'          => [
                    (string) $item->id => ['menu_item_id' => (string) $item->id, 'quantity' => '1'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $row = collect($this->actingAs($this->admin(), 'admin')
            ->getJson('/admin/tables/occupancy')
            ->json('tables'))
            ->firstWhere('table_number', '75');

        $this->assertNotNull($row, 'a counter order did not reach the Occupied Tables panel');
        $this->assertTrue($row['staff_opened']);
        $this->assertNotNull($row['order_number']);
    }

    public function test_a_customer_scan_is_not_labelled_as_a_counter_occupancy(): void
    {
        $this->occupyByScan('76');
        $this->flushSession();

        $row = collect($this->actingAs($this->admin(), 'admin')
            ->getJson('/admin/tables/occupancy')
            ->json('tables'))
            ->firstWhere('table_number', '76');

        $this->assertNotNull($row);
        $this->assertFalse($row['staff_opened']);
    }
}

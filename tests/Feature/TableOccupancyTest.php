<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Table occupancy: one SHARED dine-in session per physical table.
 *
 * The interesting half of this feature was never "does it block" — it is that a
 * table of four all scan the same standee, and every one of them has to get in.
 * Occupancy is therefore shared: the second, third and fourth scan JOIN the
 * session the first opened, and there is no path here that refuses a
 * well-formed scan because a table is busy.
 *
 * What is still exclusive, and what most of these cases actually pin down, is
 * that a table has exactly ONE session row — the thing the staff panel, the
 * order link and every release path depend on.
 */
class TableOccupancyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
        parent::tearDown();
    }

    // ── entry points ──

    /*
     * The QR now carries the table's permanent code as `k`, and every door
     * — this POST scanner path included — requires it to match
     * restaurant_tables.code (App\Services\TableEntry::validate()). So the
     * helper registers the table the way the admin card generator would and
     * scans the URL its QR would actually encode. A bare branch+table with no
     * code is exactly the hole that change closes; see permanentCode() for the
     * typed-door equivalent.
     */
    private function scan(int $branchId, string $table)
    {
        $code = TableEntry::findOrRegister($branchId, $table)->code;

        return $this->from('/customer/dineinqr')->post('/customer/dineinqr', [
            'tableData' => "branch_id={$branchId}&table={$table}&k={$code}",
            'next'      => 'guest',
        ]);
    }

    private function typeCode(string $code)
    {
        return $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', ['table_code' => $code, 'next' => 'guest']);
    }

    private function landOnUrl(int $branchId, string $table)
    {
        $code = TableEntry::findOrRegister($branchId, $table)->code;

        return $this->get("/customer/menu?branch_id={$branchId}&table={$table}&k={$code}");
    }

    /** Register (or fetch) a table's permanent code, the way the admin dashboard would. */
    private function permanentCode(int $branchId, string $table): string
    {
        return TableEntry::findOrRegister($branchId, $table)->code;
    }

    private function makeOrder(int $branchId, string $table, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TO-' . substr(uniqid(), -8),
            'user_id'      => null,
            'branch_id'    => $branchId,
            'type'         => 'dine_in',
            'table_number' => $table,
            'status'       => 'pending',
            'subtotal'     => 100,
            'total'        => 100,
        ], $attrs));
    }

    // ══════════ Occupying ══════════

    public function test_a_scan_occupies_the_table(): void
    {
        $this->scan(1, '41')->assertRedirect(route('customer.menu'));

        $session = TableOccupancy::activeFor(1, '41');

        $this->assertNotNull($session);
        $this->assertSame('41', $session->table_number);
        $this->assertSame(1, $session->branch_id);
        $this->assertNull($session->order_id);
        $this->assertSame('1:41', $session->active_lock);
        $this->assertSame($session->session_token, session(TableOccupancy::SESSION_KEY));
    }

    public function test_a_typed_code_occupies_the_table(): void
    {
        $code = $this->permanentCode(1, '42');

        $this->typeCode($code)->assertRedirect(route('customer.menu'));

        $this->assertNotNull(TableOccupancy::activeFor(1, '42'));
    }

    public function test_the_url_landing_occupies_the_table(): void
    {
        $this->landOnUrl(1, '43')->assertOk();

        $this->assertNotNull(TableOccupancy::activeFor(1, '43'));
    }

    // ══════════ Sharing a table ══════════

    /**
     * THE CASE THAT DRIVES THE WHOLE DESIGN.
     *
     * Four people sit at Table 44. All four scan the standee, because a QR on a
     * table invites everyone to. The second, third and fourth must JOIN the
     * session the first one opened — never be told the table is busy.
     */
    public function test_a_second_and_third_scan_of_the_same_table_join_the_session(): void
    {
        $this->scan(1, '44')->assertRedirect(route('customer.menu'));

        $first = TableOccupancy::activeFor(1, '44');
        $this->assertNotNull($first);

        foreach (['second', 'third'] as $who) {
            // A different person at the table = a different session cookie.
            $this->flushSession();
            RateLimiter::clear('qr-scan:127.0.0.1');

            $this->scan(1, '44')->assertRedirect(route('customer.menu'), "the $who scan was refused");

            $this->assertNull(session('error'), "the $who scan produced an error");
            $this->assertSame('44', session('table_number'), "the $who scan got no table");
            $this->assertSame('dine_in', session('order_type'));
        }

        // One shared session, not three competing ones.
        $this->assertSame(1, TableSession::where('active_lock', '1:44')->count());
        $this->assertSame($first->id, TableOccupancy::activeFor(1, '44')->id);
    }

    public function test_a_typed_code_joins_rather_than_being_refused_on_a_busy_table(): void
    {
        $this->scan(1, '45');
        $opened = TableOccupancy::activeFor(1, '45');

        $this->flushSession();
        RateLimiter::clear('table-code:127.0.0.1');

        // Someone else at the same table reads the permanent code off the card
        // because their camera would not focus. They belong at that table.
        $code = $this->permanentCode(1, '45');

        $this->typeCode($code)->assertRedirect(route('customer.menu'));

        $this->assertNull(session('error'));
        $this->assertSame('45', session('table_number'));
        $this->assertSame($opened->id, TableOccupancy::activeFor(1, '45')->id);
        $this->assertSame(1, TableSession::where('active_lock', '1:45')->count());
    }

    public function test_the_url_landing_joins_a_table_that_already_has_a_session(): void
    {
        $this->scan(1, '46');
        $opened = TableOccupancy::activeFor(1, '46');

        $this->flushSession();

        $this->landOnUrl(1, '46')->assertOk();

        $this->assertNull(session('error'));
        $this->assertSame('46', session('table_number'));
        $this->assertSame($opened->id, TableOccupancy::activeFor(1, '46')->id);
        $this->assertSame(1, TableSession::where('active_lock', '1:46')->count());
    }

    public function test_other_tables_and_branches_are_unaffected(): void
    {
        $this->scan(1, '47');

        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');
        $this->scan(1, '48')->assertRedirect(route('customer.menu'));

        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');
        // Same table NUMBER, different branch — a different physical table.
        $this->scan(2, '47')->assertRedirect(route('customer.menu'));

        $this->assertNotNull(TableOccupancy::activeFor(1, '47'));
        $this->assertNotNull(TableOccupancy::activeFor(1, '48'));
        $this->assertNotNull(TableOccupancy::activeFor(2, '47'));
    }

    // ══════════ The same customer continuing ══════════

    public function test_the_same_visitor_may_re_scan_refresh_and_navigate_freely(): void
    {
        $this->scan(1, '49')->assertRedirect(route('customer.menu'));
        $token = session(TableOccupancy::SESSION_KEY);

        // Re-scanning their own table (they pointed the camera at it again).
        $this->scan(1, '49')->assertRedirect(route('customer.menu'));

        // Refreshing the QR URL, repeatedly.
        $this->landOnUrl(1, '49')->assertOk();
        $this->landOnUrl(1, '49')->assertOk();

        // Wandering off to the plain menu and coming back.
        $this->get('/customer/menu')->assertOk();
        $this->landOnUrl(1, '49')->assertOk();

        $this->assertSame('49', session('table_number'));
        $this->assertSame($token, session(TableOccupancy::SESSION_KEY), 'token must be stable');
        $this->assertSame(1, TableSession::where('active_lock', '1:49')->count(), 'no duplicate occupancy rows');
        $this->assertNull(session('error'));
    }

    public function test_retyping_the_same_permanent_code_for_the_same_visitor_still_works(): void
    {
        $this->scan(1, '50');

        // Customer types the table's own permanent code again for the table
        // they are already at (e.g. they were bounced to login and came
        // back). Same browser, so it must be treated as continuing, not as a
        // second party.
        $code = $this->permanentCode(1, '50');

        $this->typeCode($code)->assertRedirect(route('customer.menu'));
        $this->assertSame(1, TableSession::where('active_lock', '1:50')->count());
    }

    public function test_a_customer_who_lost_their_cookie_is_recovered_through_their_order(): void
    {
        $this->scan(1, '51');
        $order = $this->makeOrder(1, '51');
        TableOccupancy::attachOrder($order);

        $this->assertSame($order->id, TableOccupancy::activeFor(1, '51')->order_id);

        // Cookie gone (cleared/expired), but they still hold the guest order.
        $this->flushSession();
        $this->session(['guest_order_id' => $order->id]);

        $this->landOnUrl(1, '51')->assertOk();

        $this->assertSame('51', session('table_number'));
        $this->assertNotNull(session(TableOccupancy::SESSION_KEY), 'token must be re-issued');
        $this->assertSame(1, TableSession::where('active_lock', '1:51')->count());
    }

    public function test_a_logged_in_customer_is_recovered_through_their_own_order(): void
    {
        $customer = User::where('role', 'customer')->firstOrFail();

        $this->scan(1, '52');
        $order = $this->makeOrder(1, '52', ['user_id' => $customer->id]);
        TableOccupancy::attachOrder($order);

        // Cookie lost mid-meal, but they are signed in and the order is theirs.
        $this->flushSession();
        $this->actingAs($customer, 'customer');

        $this->landOnUrl(1, '52')->assertOk();

        $this->assertSame('52', session('table_number'));
        $this->assertSame($order->id, TableOccupancy::activeFor(1, '52')->order_id);
        $this->assertSame(1, TableSession::where('active_lock', '1:52')->count());
    }

    /**
     * A second logged-in customer at the same table joins, and — this is the
     * part that still matters — does NOT inherit the first customer's order.
     * Sharing a table is not sharing an order.
     */
    public function test_a_second_logged_in_customer_joins_without_taking_over_the_order(): void
    {
        $owner = User::where('role', 'customer')->firstOrFail();
        $other = User::where('role', 'customer')->where('id', '!=', $owner->id)->first();

        if (!$other) {
            $this->markTestSkipped('need two customer accounts for this case');
        }

        $this->scan(1, '70');
        $order = $this->makeOrder(1, '70', ['user_id' => $owner->id]);
        TableOccupancy::attachOrder($order);

        $opened = TableOccupancy::activeFor(1, '70');

        $this->flushSession();
        $this->actingAs($other, 'customer');

        $this->landOnUrl(1, '70')->assertOk();

        $this->assertNull(session('error'));
        $this->assertSame('70', session('table_number'));

        $joined = TableOccupancy::activeFor(1, '70');
        $this->assertSame($opened->id, $joined->id, 'should have joined, not opened a rival session');

        // The order on the table is still the first customer's.
        $this->assertSame($order->id, $joined->order_id);
        $this->assertSame((int) $owner->id, (int) $order->fresh()->user_id);
    }

    /**
     * THE ACCEPTED COST, STATED AS A TEST.
     *
     * Somebody with no claim to this table — a photographed QR, a code read off
     * a standee months ago — CAN open a session on it. That is the price of a
     * permanent code and it is not defended against here, because occupancy is
     * shared and cannot tell them apart from the fourth person at the table.
     *
     * What IS asserted is the shape of the damage: they join the one session
     * staff are already watching rather than creating a second invisible one,
     * and they do not acquire the order sitting on that table.
     */
    public function test_a_stranger_joins_the_visible_session_and_gains_no_order(): void
    {
        $this->scan(1, '53');
        $order = $this->makeOrder(1, '53');
        TableOccupancy::attachOrder($order);

        $opened = TableOccupancy::activeFor(1, '53');

        // No cookie AND no claim to the order.
        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');

        $this->scan(1, '53')->assertRedirect(route('customer.menu'));

        $joined = TableOccupancy::activeFor(1, '53');

        $this->assertSame($opened->id, $joined->id, 'a stranger must not open a second, invisible session');
        $this->assertSame(1, TableSession::where('active_lock', '1:53')->count());
        $this->assertSame($order->id, $joined->order_id, 'the existing order must stay linked');
    }

    // ══════════ Releasing ══════════

    public function test_completing_the_order_releases_the_table(): void
    {
        $this->scan(1, '54');
        $order = $this->makeOrder(1, '54');
        TableOccupancy::attachOrder($order);

        $this->assertNotNull(TableOccupancy::activeFor(1, '54'));

        $order->status = 'completed';
        $order->save();

        $this->assertNull(TableOccupancy::activeFor(1, '54'), 'table must be free after completion');

        $row = TableSession::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('order_completed', $row->release_reason);
        $this->assertNotNull($row->released_at);
    }

    public function test_cancelling_the_order_releases_the_table(): void
    {
        $this->scan(1, '55');
        $order = $this->makeOrder(1, '55');
        TableOccupancy::attachOrder($order);

        $order->status = 'cancelled';
        $order->save();

        $this->assertNull(TableOccupancy::activeFor(1, '55'));
        $this->assertSame('order_cancelled', TableSession::where('order_id', $order->id)->value('release_reason'));
    }

    public function test_a_released_table_can_be_taken_by_the_next_customer(): void
    {
        $this->scan(1, '56');
        $order = $this->makeOrder(1, '56');
        TableOccupancy::attachOrder($order);
        $order->status = 'completed';
        $order->save();

        // Next party sits down.
        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');

        $this->scan(1, '56')->assertRedirect(route('customer.menu'));
        $this->assertNotNull(TableOccupancy::activeFor(1, '56'));

        // One released row plus one live row — history is kept.
        $this->assertSame(2, TableSession::where('branch_id', 1)->where('table_number', '56')->count());
    }

    public function test_intermediate_statuses_do_not_release_the_table(): void
    {
        $this->scan(1, '57');
        $order = $this->makeOrder(1, '57');
        TableOccupancy::attachOrder($order);

        foreach (['preparing', 'serving'] as $status) {
            $order->status = $status;
            $order->save();

            $this->assertNotNull(TableOccupancy::activeFor(1, '57'), "released too early on $status");
        }
    }

    public function test_an_order_with_a_linked_table_never_goes_stale(): void
    {
        $this->scan(1, '58');
        $order = $this->makeOrder(1, '58');
        TableOccupancy::attachOrder($order);

        // A very long meal. The inactivity sweep must not touch it: there is an
        // unfinished order on this table.
        DB::table('table_sessions')->where('active_lock', '1:58')
            ->update(['last_seen_at' => now()->subHours(6), 'created_at' => now()->subHours(6)]);

        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');

        $this->assertSame(0, TableOccupancy::sweepIdle(), 'a live order must never be swept');

        $session = TableOccupancy::activeFor(1, '58');
        $this->assertNotNull($session, 'a table with an unfinished order must stay held');
        $this->assertSame($order->id, $session->order_id);
    }

    /**
     * A SESSION ENDS BY ITSELF. Without this, the first customer who walks out
     * while staff are busy leaves that table showing "occupied" forever.
     */
    public function test_an_idle_browsing_session_releases_itself(): void
    {
        $this->scan(1, '59');
        $opened = TableOccupancy::activeFor(1, '59');
        $this->assertNotNull($opened);

        // Scanned, never ordered, walked away.
        DB::table('table_sessions')->where('id', $opened->id)->update([
            'last_seen_at' => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES + 1),
            'created_at'   => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES + 1),
        ]);

        $this->assertGreaterThanOrEqual(1, TableOccupancy::sweepIdle());

        $this->assertNull(TableOccupancy::activeFor(1, '59'), 'the idle session should have released itself');

        // Asserted on THIS row by id, so no other table's history can satisfy it.
        $released = TableSession::find($opened->id);
        $this->assertNull($released->active_lock);
        $this->assertSame('abandoned', $released->release_reason);
        $this->assertNotNull($released->released_at);
    }

    /** And it must not fire early on a party who are simply reading the menu. */
    public function test_a_briefly_idle_browsing_session_is_left_alone(): void
    {
        $this->scan(1, '60');
        $opened = TableOccupancy::activeFor(1, '60');

        DB::table('table_sessions')->where('id', $opened->id)->update([
            'last_seen_at' => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES - 5),
        ]);

        TableOccupancy::sweepIdle();

        $this->assertNotNull(TableOccupancy::activeFor(1, '60'));
        $this->assertNull(TableSession::find($opened->id)->released_at);
    }

    // ══════════ Staff manual clear ══════════

    public function test_admin_can_clear_a_table(): void
    {
        $this->scan(1, '61');
        $this->assertNotNull(TableOccupancy::activeFor(1, '61'));

        $admin = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/tables/clear', ['branch_id' => 1, 'table_number' => '61'])
            ->assertOk()
            ->assertJsonFragment(['table_number' => '61']);

        $this->assertNull(TableOccupancy::activeFor(1, '61'));

        $row = TableSession::where('branch_id', 1)->where('table_number', '61')->firstOrFail();
        $this->assertSame('staff_cleared', $row->release_reason);
        $this->assertSame($admin->id, (int) $row->released_by);
    }

    public function test_a_cleared_table_is_immediately_reusable(): void
    {
        $this->scan(1, '62');

        $this->actingAs(User::where('role', 'admin')->firstOrFail(), 'admin')
            ->postJson('/admin/tables/clear', ['branch_id' => 1, 'table_number' => '62'])
            ->assertOk();

        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');

        $this->scan(1, '62')->assertRedirect(route('customer.menu'));
    }

    public function test_staff_can_only_clear_their_own_branch(): void
    {
        $staff = User::where('role', 'staff')->whereNotNull('branch_id')->first();

        if (!$staff) {
            $this->markTestSkipped('no branch-scoped staff account in this database');
        }

        $other = Branch::where('id', '!=', $staff->branch_id)->where('is_active', true)->firstOrFail();

        $this->scan((int) $staff->branch_id, '63');
        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');
        $this->scan($other->id, '64');

        // Another branch's table: refused, and left occupied.
        $this->actingAs($staff, 'admin')
            ->postJson('/admin/tables/clear', ['branch_id' => $other->id, 'table_number' => '64'])
            ->assertStatus(403);

        $this->assertNotNull(TableOccupancy::activeFor($other->id, '64'));

        // Their own branch: allowed.
        $this->actingAs($staff, 'admin')
            ->postJson('/admin/tables/clear', ['branch_id' => $staff->branch_id, 'table_number' => '63'])
            ->assertOk();

        $this->assertNull(TableOccupancy::activeFor((int) $staff->branch_id, '63'));
    }

    public function test_clearing_a_free_table_is_a_404_not_a_500(): void
    {
        $this->actingAs(User::where('role', 'admin')->firstOrFail(), 'admin')
            ->postJson('/admin/tables/clear', ['branch_id' => 1, 'table_number' => '65'])
            ->assertStatus(404);
    }

    public function test_clear_validates_input(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/tables/clear', ['branch_id' => 999999, 'table_number' => '1'])
            ->assertStatus(422);

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/tables/clear', ['branch_id' => 1, 'table_number' => '<script>'])
            ->assertStatus(422);
    }

    public function test_clear_requires_a_staff_session(): void
    {
        $this->scan(1, '66');

        $this->post('/admin/tables/clear', ['branch_id' => 1, 'table_number' => '66'])
            ->assertRedirect(route('admin.login'));

        $this->assertNotNull(TableOccupancy::activeFor(1, '66'));
    }

    public function test_occupancy_listing_is_branch_scoped_for_staff(): void
    {
        $staff = User::where('role', 'staff')->whereNotNull('branch_id')->first();

        if (!$staff) {
            $this->markTestSkipped('no branch-scoped staff account in this database');
        }

        $other = Branch::where('id', '!=', $staff->branch_id)->where('is_active', true)->firstOrFail();

        $this->scan((int) $staff->branch_id, '67');
        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');
        $this->scan($other->id, '68');

        $tables = $this->actingAs($staff, 'admin')
            ->getJson('/admin/tables/occupancy')->assertOk()->json('tables');

        $branchIds = array_unique(array_column($tables, 'branch_id'));

        $this->assertSame([(int) $staff->branch_id], array_values($branchIds));
        $this->assertContains('67', array_column($tables, 'table_number'));
        $this->assertNotContains('68', array_column($tables, 'table_number'));
    }

    // ══════════ Non-dine-in must be untouched ══════════

    public function test_pickup_orders_never_occupy_a_table(): void
    {
        $order = $this->makeOrder(1, '69', ['type' => 'pick_up', 'table_number' => null]);

        TableOccupancy::attachOrder($order);

        $this->assertSame(0, TableSession::where('order_id', $order->id)->count());
    }

    public function test_the_plain_menu_is_unaffected(): void
    {
        // Measure the delta, not a global count: this suite runs against the
        // real database, which may legitimately already hold live occupancies.
        $before = TableSession::whereNotNull('active_lock')->count();

        $this->get('/customer/menu')->assertOk();

        $this->assertSame(
            $before,
            TableSession::whereNotNull('active_lock')->count(),
            'browsing the menu without QR params must not occupy any table'
        );
        $this->assertNull(session(TableOccupancy::SESSION_KEY));
    }
}

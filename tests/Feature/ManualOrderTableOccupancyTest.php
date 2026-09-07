<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * A staff-created dine-in Manual Order must mark its table occupied.
 *
 * THE BUG THIS EXISTS FOR
 * -----------------------
 * Reported live: staff created a Manual Order for dine-in Table 1 from the
 * admin side, and the Occupied Tables panel still said "No tables are occupied
 * right now" — so a table with a real party sitting at it stayed on offer to
 * the next QR scan.
 *
 * The cause was that storeManualOrder() never claimed a table at all, and the
 * only claiming path there was, TableOccupancy::attachOrder(), links an order to
 * an occupancy the CURRENT VISITOR already holds. A counter order comes from a
 * staff admin session, which holds no table token and owns no guest order, so
 * that path could never have worked for it. TableOccupancy::attachStaffOrder()
 * is the counter's own path.
 */
class ManualOrderTableOccupancyTest extends TestCase
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

    /** A live, orderable item plus one of its add-ons, resolved at runtime. */
    private function line(): array
    {
        $item = \App\Models\MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->firstOrFail();

        return [
            (string) $item->id => [
                'menu_item_id' => (string) $item->id,
                'quantity'     => '1',
            ],
        ];
    }

    /**
     * A customer arriving via their phone's camera on the printed QR. The QR
     * carries the table's permanent code as `k` now, and every entry door
     * requires it (App\Services\TableEntry::validate()), so this registers the
     * table and scans the URL its real QR would encode.
     */
    private function scanIn(string $table, int $branchId = 1)
    {
        $code = TableEntry::findOrRegister($branchId, $table)->code;

        return $this->from('/customer/dineinqr')->post('/customer/dineinqr', [
            'tableData' => "branch_id={$branchId}&table={$table}&k={$code}",
            'next'      => 'guest',
        ]);
    }

    private function keyInDineInOrder(User $actor, string $table)
    {
        return $this->actingAs($actor, 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', [
                'branch_id'      => 1,
                'order_type'     => 'dine_in',
                'table_number'   => $table,
                'payment_method' => 'cash',
                'amount_paid'    => '5000',
                'items'          => $this->line(),
            ]);
    }

    private function newestOrder(): Order
    {
        return Order::orderByDesc('id')->firstOrFail();
    }

    // ══════════ the reported bug ══════════

    public function test_a_staff_dine_in_manual_order_occupies_the_table(): void
    {
        $this->assertNull(TableOccupancy::activeFor(1, '61'));

        $response = $this->keyInDineInOrder($this->staff(), '61');
        $response->assertSessionHasNoErrors();

        $held = TableOccupancy::activeFor(1, '61');

        $this->assertNotNull($held, 'the counter order did not mark the table occupied');
        $this->assertSame($this->newestOrder()->id, (int) $held->order_id);
    }

    public function test_the_occupancy_is_attributed_to_the_staff_member_not_a_customer(): void
    {
        $staff = $this->staff();

        $this->keyInDineInOrder($staff, '62');

        $held = TableOccupancy::activeFor(1, '62');

        $this->assertNotNull($held);
        $this->assertSame((int) $staff->id, (int) $held->opened_by);
        $this->assertTrue($held->isStaffOpened());

        // The item-40 "same customer continuing" signals must NOT recognise the
        // counter session as the party at the table: the staff token is stored
        // in no session at all, and the linked order belongs to no customer.
        $this->assertFalse(TableOccupancy::heldByCurrentVisitor($held));
        $this->assertNotSame($held->session_token, session(TableOccupancy::SESSION_KEY));
    }

    public function test_a_pick_up_manual_order_claims_no_table(): void
    {
        $before = TableSession::count();

        $this->actingAs($this->staff(), 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', [
                'branch_id'      => 1,
                'order_type'     => 'pick_up',
                'table_number'   => '',
                'payment_method' => 'cash',
                'amount_paid'    => '5000',
                'items'          => $this->line(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($before, TableSession::count());
    }

    // ══════════ the table is already occupied by a customer ══════════

    public function test_a_manual_order_links_to_the_customers_existing_occupancy(): void
    {
        // A customer scans in and orders at table 63.
        $this->scanIn('63');

        $customerOrder = Order::create([
            'order_number'   => 'MO-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'dine_in',
            'table_number'   => '63',
            'status'         => 'preparing',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ]);

        GuestOrders::remember($customerOrder->id);
        TableOccupancy::attachOrder($customerOrder);

        $original = TableOccupancy::activeFor(1, '63');
        $this->assertNotNull($original);

        // Staff now key a manual order for the SAME table.
        $this->keyInDineInOrder($this->staff(), '63')->assertSessionHasNoErrors();

        $after = TableSession::where('active_lock', '1:63')->get();

        $this->assertCount(1, $after, 'a second, competing occupancy was opened for the same table');
        $this->assertSame($original->id, (int) $after->first()->id);

        // The customer's own order stays the one that proves who is sitting
        // there, so losing their cookie does not lock them out of their meal.
        $this->assertSame($customerOrder->id, (int) $after->first()->order_id);
        $this->assertNull($after->first()->opened_by);
    }

    public function test_an_occupancy_with_no_order_yet_adopts_the_counter_order(): void
    {
        // Customer scanned in but has not ordered; staff key their order for
        // them at the counter. The occupancy must stop being abandonable.
        $this->scanIn('64');

        $this->assertNull(TableOccupancy::activeFor(1, '64')->order_id);

        $this->keyInDineInOrder($this->staff(), '64')->assertSessionHasNoErrors();

        $held = TableOccupancy::activeFor(1, '64');

        $this->assertSame(1, TableSession::where('active_lock', '1:64')->count());
        $this->assertSame($this->newestOrder()->id, (int) $held->order_id);
    }

    /**
     * A customer scanning a table staff already opened at the counter JOINS
     * that occupancy — they are the party staff just keyed the order in for.
     * Opening a rival session, or refusing them, would both be wrong.
     */
    public function test_a_customer_scan_joins_a_table_held_by_a_counter_order(): void
    {
        $this->keyInDineInOrder($this->admin(), '65')->assertSessionHasNoErrors();

        $opened = TableOccupancy::activeFor(1, '65');
        $this->assertNotNull($opened);
        $counterOrderId = $opened->order_id;

        // A customer's phone: its own session, no claim on the counter order.
        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');

        $this->scanIn('65');

        $this->assertNull(session('error'));
        $this->assertSame('65', session('table_number'));

        $joined = TableOccupancy::activeFor(1, '65');
        $this->assertSame($opened->id, $joined->id, 'must join the counter occupancy, not replace it');
        $this->assertSame(1, TableSession::where('active_lock', '1:65')->count());

        // The counter order stays the one that holds the table.
        $this->assertSame($counterOrderId, $joined->order_id);
    }

    // ══════════ release ══════════

    public static function finishingStatuses(): array
    {
        return ['completed' => ['completed'], 'cancelled' => ['cancelled']];
    }

    /**
     * @dataProvider finishingStatuses
     */
    public function test_finishing_a_counter_order_frees_its_table(string $status): void
    {
        $this->keyInDineInOrder($this->staff(), '66')->assertSessionHasNoErrors();
        $this->assertNotNull(TableOccupancy::activeFor(1, '66'));

        $order = $this->newestOrder();
        $order->status = $status;
        $order->save();

        $this->assertNull(
            TableOccupancy::activeFor(1, '66'),
            "a {$status} counter order left its table held"
        );
    }

    public function test_the_table_stays_held_while_another_order_on_it_is_unfinished(): void
    {
        // Two counter orders on the same table — a second round keyed in at the
        // counter. The item-43 hand-off must keep the table held until both are
        // done, exactly as it does for a customer's own two orders.
        $this->keyInDineInOrder($this->staff(), '67')->assertSessionHasNoErrors();
        $first = $this->newestOrder();

        $this->keyInDineInOrder($this->staff(), '67')->assertSessionHasNoErrors();
        $second = $this->newestOrder();

        $this->assertNotSame($first->id, $second->id);

        $first->status = 'completed';
        $first->save();

        $held = TableOccupancy::activeFor(1, '67');
        $this->assertNotNull($held, 'the table was freed while a second counter order was still open');
        $this->assertSame($second->id, (int) $held->order_id);

        $second->status = 'completed';
        $second->save();

        $this->assertNull(TableOccupancy::activeFor(1, '67'));
    }

    public function test_staff_can_still_clear_a_counter_held_table_by_hand(): void
    {
        $this->keyInDineInOrder($this->staff(), '68')->assertSessionHasNoErrors();

        $this->actingAs($this->staff(), 'admin')
            ->postJson('/admin/tables/clear', ['branch_id' => 1, 'table_number' => '68'])
            ->assertOk();

        $this->assertNull(TableOccupancy::activeFor(1, '68'));
    }
}

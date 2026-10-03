<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\TableSessionDevice;
use App\Models\User;
use App\Providers\RateLimitServiceProvider;
use App\Services\TableChange;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Table N · Change table" — a seated Dine-In device moving to another table
 * at the same branch. See App\Services\TableChange.
 *
 * Branch-agnostic on purpose: nothing here names a branch id, a table id or a
 * table number. The matrix runs over every active branch the database holds
 * plus one created inside the test with no tables, sessions or orders at all,
 * and every table is registered here with a random number.
 *
 * Devices: the test client keeps ONE session store for the whole test, so
 * on($device) parks the current device's session and restores the next one's
 * — a second phone at the table is a second, independent session.
 */
class DineInChangeTableTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string, array> */
    private array $jar = [];

    private ?string $device = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLimits();
    }

    protected function tearDown(): void
    {
        $this->clearLimits();
        parent::tearDown();
    }

    private function clearLimits(): void
    {
        RateLimiter::clear('table-code:127.0.0.1');
        RateLimiter::clear('qr-scan:127.0.0.1');
        app('cache')->store()->flush();
    }

    // ══════════ fixtures ══════════

    private function freshBranch(string $tag = 'CT'): Branch
    {
        return Branch::create([
            'name'           => 'Change Table ' . $tag . ' ' . Str::random(5),
            'code'           => 'CT' . strtoupper(Str::random(6)),
            'address'        => 'Created by DineInChangeTableTest',
            'is_active'      => true,
            'is_main_branch' => false,
        ]);
    }

    /**
     * Every active branch in the database, plus one brand-new branch with no
     * dine-in history. Resolved at run time — no ids, no names.
     *
     * @return \Illuminate\Support\Collection<int, Branch>
     */
    private function branchesUnderTest()
    {
        return Branch::where('is_active', true)->orderBy('id')->get()
            ->push($this->freshBranch('NEW'));
    }

    /** Register a table the way the admin card generator does. */
    private function table(Branch $branch): RestaurantTable
    {
        return TableEntry::findOrRegister($branch->id, 'T' . strtoupper(Str::random(6)));
    }

    /** Switch to another phone. Each name keeps its own session. */
    private function on(string $device): void
    {
        if ($this->device !== null) {
            $this->jar[$this->device] = session()->all();
        }

        $this->flushSession();

        foreach ($this->jar[$device] ?? [] as $key => $value) {
            session()->put($key, $value);
        }

        $this->app['auth']->forgetGuards();
        $this->device = $device;
    }

    /** First entry through the phone-camera door. */
    private function scanQr(RestaurantTable $table)
    {
        return $this->get('/customer/menu?branch_id=' . $table->branch_id
            . '&table=' . $table->table_number . '&k=' . $table->code);
    }

    /** First entry by typing the code on the code page. */
    private function typeEntryCode(RestaurantTable $table)
    {
        return $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', ['table_code' => $table->code, 'next' => 'guest']);
    }

    /** The Change table control. */
    private function changeTo(string $code, array $extra = [])
    {
        return $this->from('/customer/menu')
            ->post('/customer/table/change', array_merge(['table_code' => $code], $extra));
    }

    private function confirm()
    {
        return $this->from('/customer/menu')->post('/customer/table/change/confirm');
    }

    private function live(RestaurantTable $table): ?TableSession
    {
        return TableOccupancy::activeFor($table->branch_id, $table->table_number);
    }

    private function devices(RestaurantTable $table): int
    {
        $session = $this->live($table);

        return $session ? TableOccupancy::activeDeviceCount($session) : 0;
    }

    private function openOrderAt(RestaurantTable $table, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'CT-' . strtoupper(Str::random(8)),
            'user_id'      => null,
            'branch_id'    => $table->branch_id,
            'type'         => 'dine_in',
            'table_number' => $table->table_number,
            'status'       => 'preparing',
            'subtotal'     => 100,
            'total'        => 100,
        ], $attrs));
    }

    /** A dine-in item this branch can actually sell: its own recipe and stock. */
    private function orderableItem(Branch $branch): MenuItem
    {
        $inventory = Inventory::create([
            'branch_id'       => $branch->id,
            'item_name'       => 'CT Ingredient ' . Str::random(6),
            'item_code'       => 'CT-' . strtoupper(Str::random(8)),
            'quantity'        => 50,
            'unit'            => 'pc',
            'low_stock_alert' => 1,
            'unit_cost'       => 1,
            'is_active'       => true,
        ]);

        $item = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $branch->id,
            'name'          => 'CT Item ' . Str::random(6),
            'price'         => 75,
            'cost'          => 0,
            'is_available'  => true,
            'display_order' => 0,
        ]);

        MenuItemIngredient::create([
            'menu_item_id'  => $item->id,
            'inventory_id'  => $inventory->id,
            'quantity_used' => 1,
        ]);

        return $item;
    }

    private function cartLine(MenuItem $item, int $qty = 1): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => (float) $item->price,
            'quantity'     => $qty,
            'image'        => null,
            'options'      => [],
        ]];
    }

    // ══════════ THE MATRIX, over every branch ══════════

    /**
     * For each branch: QR entry and code entry both work; a free table moves
     * the device and frees the old one; an occupied table needs a tap and then
     * joins; an open order holds the customer in place; and the order placed
     * afterwards carries the new table and that branch.
     */
    public function test_the_full_change_table_flow_works_on_every_branch(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id} ({$branch->name})";
            [$t1, $t2, $t3, $t4] = [$this->table($branch), $this->table($branch), $this->table($branch), $this->table($branch)];

            // QR entry resolves to the right table.
            $this->on("qr-{$branch->id}");
            $this->scanQr($t1)->assertOk();
            $this->assertSame($t1->table_number, session('table_number'), $label);
            $this->assertSame((int) $branch->id, (int) session('branch_id'), $label);
            $this->assertNotNull($this->live($t1), $label);

            // Free table: moves at once, the old table is freed, cart kept.
            $item = $this->orderableItem($branch);
            session()->put('cart', $this->cartLine($item, 2));

            $this->changeTo($t2->code)->assertRedirect(route('customer.menu'));

            $this->assertSame($t2->table_number, session('table_number'), $label);
            $this->assertSame((int) $branch->id, (int) session('branch_id'), $label);
            $this->assertSame($this->live($t2)->session_token, session(TableOccupancy::SESSION_KEY), $label);
            $this->assertNull($this->live($t1), "$label: the empty old table must be released");
            $this->assertSame(
                TableOccupancy::RELEASE_TABLE_CHANGED,
                TableSession::where('branch_id', $branch->id)->where('table_number', $t1->table_number)->latest('id')->value('release_reason'),
                $label
            );
            $this->assertSame(1, $this->devices($t2), $label);
            $this->assertCount(1, session('cart'), "$label: the cart must survive a same-branch move");
            $this->assertStringContainsString('Your cart is still here', (string) session('success'), $label);

            // The order placed afterwards: NEW table, this branch — even when
            // the cart page that posts it was rendered before the move.
            $before = (int) Order::max('id');
            $this->post('/customer/place-order', [
                'order_type'     => 'dine_in',
                'table_number'   => $t1->table_number,
                'branch_id'      => 999999,
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 2]],
            ]);
            $order = Order::where('id', '>', $before)->latest('id')->first();
            $this->assertNotNull($order, "$label: the order was not placed");
            $this->assertSame($t2->table_number, $order->table_number, $label);
            $this->assertSame((int) $branch->id, (int) $order->branch_id, $label);
            $this->assertSame($order->id, $this->live($t2)->order_id, "$label: the new table holds the order");

            // Open order: the customer stays put and is told why.
            $this->changeTo($t3->code)->assertRedirect(route('customer.menu'));
            $this->assertSame(
                TableChange::activeOrderMessage($t2->table_number),
                session('table_change_error'),
                $label
            );
            $this->assertSame($t2->table_number, session('table_number'), $label);
            $this->assertNull($this->live($t3), "$label: a refused move must not occupy the target");

            // Code entry resolves to the right table.
            $this->on("code-{$branch->id}");
            $this->typeEntryCode($t3)->assertRedirect(route('customer.menu'));
            $this->assertSame($t3->table_number, session('table_number'), $label);

            // Occupied table: someone else is at T4.
            $this->on("other-{$branch->id}");
            $this->scanQr($t4)->assertOk();
            $t4Session = $this->live($t4);

            $this->on("code-{$branch->id}");
            $this->changeTo($t4->code)->assertRedirect(route('customer.menu'));
            $this->assertSame($t3->table_number, session('table_number'), "$label: an occupied table needs a tap first");
            $this->assertTrue((bool) TableChange::pending()['occupied'], $label);
            $this->get('/customer/menu')->assertOk()->assertSee('is already in use. Join it?', false);

            $this->confirm()->assertRedirect(route('customer.menu'));
            $this->assertSame($t4->table_number, session('table_number'), $label);
            $this->assertSame($t4Session->session_token, session(TableOccupancy::SESSION_KEY), "$label: joined, not a rival session");
            $this->assertSame(1, TableSession::where('active_lock', $t4Session->active_lock)->count(), $label);
            $this->assertSame(2, $this->devices($t4), $label);
            $this->assertNull($this->live($t3), "$label: T3 had only this device");
        }
    }

    // ══════════ cross-branch ══════════

    /**
     * Every ordered pair of branches, existing and fresh: a seat at one and a
     * code for the other is refused server-side, and neither branch changes.
     */
    public function test_a_code_from_another_branch_is_refused_in_every_direction(): void
    {
        $branches = $this->branchesUnderTest()->push($this->freshBranch('NEW2'))->values();

        foreach ($branches as $from) {
            foreach ($branches as $to) {
                if ($from->id === $to->id) {
                    continue;
                }

                $seat = $this->table($from);
                $foreign = $this->table($to);

                $this->on("x-{$from->id}-{$to->id}");
                $this->scanQr($seat)->assertOk();
                $token = session(TableOccupancy::SESSION_KEY);
                $toSessionsBefore = TableSession::where('branch_id', $to->id)->count();

                $this->changeTo($foreign->code)->assertRedirect(route('customer.menu'));

                $pair = "#{$from->id} -> #{$to->id}";
                $this->assertSame(TableChange::ERR_OTHER_BRANCH, session('table_change_error'), $pair);
                $this->assertSame((int) $from->id, (int) session('branch_id'), $pair);
                $this->assertSame($seat->table_number, session('table_number'), $pair);
                $this->assertSame($token, session(TableOccupancy::SESSION_KEY), $pair);
                $this->assertNotNull($this->live($seat), $pair);
                $this->assertNull($this->live($foreign), $pair);
                $this->assertSame($toSessionsBefore, TableSession::where('branch_id', $to->id)->count(), $pair);
            }
        }
    }

    /**
     * Branch A Table 1 -> Table 2, then Branch B Table 1 -> Table 2. A's move
     * occupies, releases and modifies nothing at B.
     */
    public function test_changing_table_in_one_branch_never_touches_another(): void
    {
        $a = $this->freshBranch('A');
        $b = $this->freshBranch('B');
        [$a1, $a2] = [$this->table($a), $this->table($a)];
        [$b1, $b2] = [$this->table($b), $this->table($b)];

        $this->on('b-phone');
        $this->scanQr($b1)->assertOk();

        $snapshot = fn () => [
            TableSession::where('branch_id', $b->id)->orderBy('id')->get()->map->only([
                'id', 'table_number', 'active_lock', 'order_id', 'last_seen_at', 'last_activity_at', 'released_at', 'release_reason',
            ])->all(),
            TableSessionDevice::whereIn('table_session_id', TableSession::where('branch_id', $b->id)->pluck('id'))
                ->orderBy('id')->get()->map->only(['id', 'device_token', 'last_activity_at'])->all(),
        ];
        $bBefore = $snapshot();

        $this->on('a-phone');
        $this->scanQr($a1)->assertOk();
        $this->changeTo($a2->code)->assertRedirect(route('customer.menu'));

        $this->assertSame($a2->table_number, session('table_number'));
        $this->assertNull($this->live($a1));
        $this->assertNotNull($this->live($a2));
        $this->assertEquals($bBefore, $snapshot(), "Branch A's change touched Branch B");

        $this->on('b-phone');
        $this->changeTo($b2->code)->assertRedirect(route('customer.menu'));

        $this->assertSame($b2->table_number, session('table_number'));
        $this->assertNull($this->live($b1));
        $this->assertNotNull($this->live($b2));
        $this->assertNotNull($this->live($a2), "Branch B's change touched Branch A");
    }

    // ══════════ the credential ══════════

    public function test_a_bare_table_number_is_refused_and_nothing_moves(): void
    {
        $branch = $this->freshBranch();
        [$t1, $t2] = [$this->table($branch), $this->table($branch)];

        $this->on('phone');
        $this->scanQr($t1)->assertOk();
        $token = session(TableOccupancy::SESSION_KEY);

        $attempts = [
            ['table_code' => $t2->table_number],
            ['table_code' => '', 'table_number' => $t2->table_number, 'branch_id' => $branch->id],
            ['table_code' => '', 'table_id' => $t2->id],
            ['table_code' => 'ZZZZZZZZ'],
            ['table_code' => $t2->code . 'X'],
        ];

        foreach ($attempts as $i => $payload) {
            $this->from('/customer/menu')->post('/customer/table/change', $payload)
                ->assertRedirect(route('customer.menu'));

            $this->assertNotNull(session('table_change_error'), "attempt $i was not refused");
            $this->assertSame($t1->table_number, session('table_number'), "attempt $i moved the customer");
            $this->assertSame($token, session(TableOccupancy::SESSION_KEY), "attempt $i");
            $this->assertNull($this->live($t2), "attempt $i occupied the target");
        }
    }

    /** The table comes from the code. Posted ids and numbers are never read. */
    public function test_posted_branch_and_table_fields_are_ignored(): void
    {
        $branch = $this->freshBranch();
        $elsewhere = $this->freshBranch('ELSE');
        [$t1, $t2] = [$this->table($branch), $this->table($branch)];
        $decoy = $this->table($elsewhere);

        $this->on('phone');
        $this->scanQr($t1)->assertOk();

        $this->changeTo($t2->code, [
            'branch_id'    => $elsewhere->id,
            'table_id'     => $decoy->id,
            'table_number' => $decoy->table_number,
        ])->assertRedirect(route('customer.menu'));

        $this->assertSame($t2->table_number, session('table_number'));
        $this->assertSame((int) $branch->id, (int) session('branch_id'));
        $this->assertNotNull($this->live($t2));
        $this->assertNull($this->live($decoy));
    }

    /** An admin regenerating the code while the join prompt is up voids it. */
    public function test_confirming_after_the_code_was_regenerated_is_refused(): void
    {
        $branch = $this->freshBranch();
        [$t1, $t2] = [$this->table($branch), $this->table($branch)];

        $this->on('other');
        $this->scanQr($t2)->assertOk();

        $this->on('phone');
        $this->scanQr($t1)->assertOk();
        $this->changeTo($t2->code);
        $this->assertNotNull(TableChange::pending());

        TableEntry::rotateCode($t2);

        $this->confirm()->assertRedirect(route('customer.menu'));
        $this->assertSame(TableEntry::ERR_QR_STALE, session('table_change_error'));
        $this->assertSame($t1->table_number, session('table_number'));
        $this->assertSame(1, $this->devices($t2));
    }

    public function test_a_join_prompt_expires(): void
    {
        $branch = $this->freshBranch();
        [$t1, $t2] = [$this->table($branch), $this->table($branch)];

        $this->on('other');
        $this->scanQr($t2)->assertOk();

        $this->on('phone');
        $this->scanQr($t1)->assertOk();
        $this->changeTo($t2->code);

        $this->travel(TableChange::PENDING_TTL_MINUTES + 1)->minutes();

        $this->confirm()->assertRedirect(route('customer.menu'));
        $this->assertSame(TableChange::ERR_PENDING_EXPIRED, session('table_change_error'));
        $this->assertSame($t1->table_number, session('table_number'));
    }

    public function test_declining_the_join_prompt_keeps_the_customer_where_they_are(): void
    {
        $branch = $this->freshBranch();
        [$t1, $t2] = [$this->table($branch), $this->table($branch)];

        $this->on('other');
        $this->scanQr($t2)->assertOk();

        $this->on('phone');
        $this->scanQr($t1)->assertOk();
        $token = session(TableOccupancy::SESSION_KEY);
        $this->changeTo($t2->code);

        $this->post('/customer/table/change/cancel', ['return_to' => 'cart'])
            ->assertRedirect(route('customer.cart'));

        $this->assertNull(session(TableChange::PENDING_KEY));
        $this->assertSame($t1->table_number, session('table_number'));
        $this->assertSame($token, session(TableOccupancy::SESSION_KEY));
        $this->assertSame(1, $this->devices($t2));
        $this->assertNotNull($this->live($t1));
    }

    // ══════════ the phone-camera door ══════════

    /**
     * A seated customer's camera opening another table's QR — or the Back
     * button replaying one — asks before moving them anywhere.
     */
    public function test_the_qr_door_asks_before_moving_a_seated_customer(): void
    {
        $branch = $this->freshBranch();
        [$t1, $t2] = [$this->table($branch), $this->table($branch)];

        $this->on('phone');
        $this->scanQr($t1)->assertOk();
        $token = session(TableOccupancy::SESSION_KEY);

        $this->scanQr($t2)->assertRedirect(route('customer.menu'));

        $this->assertSame($t1->table_number, session('table_number'), 'a URL load must not move a seated customer');
        $this->assertSame($token, session(TableOccupancy::SESSION_KEY));
        $this->assertNull($this->live($t2));
        $this->assertFalse((bool) TableChange::pending()['occupied']);
        $this->get('/customer/menu')->assertOk()->assertSee('Move to Table ' . $t2->table_number . '?', false);

        $this->confirm()->assertRedirect(route('customer.menu'));
        $this->assertSame($t2->table_number, session('table_number'));
        $this->assertNull($this->live($t1));

        // The Back button: T1's QR URL again. Asked, not moved.
        $this->scanQr($t1)->assertRedirect(route('customer.menu'));
        $this->assertSame($t2->table_number, session('table_number'));
        $this->assertNull($this->live($t1));
    }

    public function test_the_qr_door_refuses_a_seated_customer_with_an_open_order(): void
    {
        $branch = $this->freshBranch();
        [$t1, $t2] = [$this->table($branch), $this->table($branch)];

        $this->on('phone');
        $this->scanQr($t1)->assertOk();
        $order = $this->openOrderAt($t1, ['status' => 'pending']);
        GuestOrders::remember($order->id);
        TableOccupancy::attachOrder($order);

        $this->scanQr($t2)->assertRedirect(route('customer.menu'));

        $this->assertSame(TableChange::activeOrderMessage($t1->table_number), session('table_change_error'));
        $this->assertSame($t1->table_number, session('table_number'));
        $this->assertNull($this->live($t2));
        $this->assertNull(session(TableChange::PENDING_KEY));
        $this->assertSame([$order->id], GuestOrders::ids(), 'the guest keeps their order');
        $this->assertSame($t1->table_number, $order->fresh()->table_number, 'the order is never moved');
    }

    /** The typed-code door on /customer/dineinqr follows the same rules. */
    public function test_the_code_page_moves_a_seated_customer_without_forgetting_them(): void
    {
        $branch = $this->freshBranch();
        [$t1, $t2] = [$this->table($branch), $this->table($branch)];

        $this->on('phone');
        $this->scanQr($t1)->assertOk();
        $done = $this->openOrderAt($t1, ['status' => 'completed']);
        GuestOrders::remember($done->id);

        $this->typeEntryCode($t2)->assertRedirect(route('customer.menu'));

        $this->assertSame($t2->table_number, session('table_number'));
        $this->assertNull($this->live($t1));
        $this->assertSame([$done->id], GuestOrders::ids(), 'a table change is not a new party');
        $this->assertNull(session('welcome_customer'));
    }

    // ══════════ the active order ══════════

    /**
     * A friend's order on THEIR phone at the same table holds the table too:
     * the session is shared, and staff will carry that food here.
     */
    public function test_an_open_order_anyone_at_the_table_placed_blocks_the_move(): void
    {
        $branch = $this->freshBranch();
        [$t1, $t2] = [$this->table($branch), $this->table($branch)];

        $this->on('friend');
        $this->scanQr($t1)->assertOk();

        $this->on('phone');
        $this->scanQr($t1)->assertOk();
        $this->openOrderAt($t1);

        $this->changeTo($t2->code);

        $this->assertSame(TableChange::activeOrderMessage($t1->table_number), session('table_change_error'));
        $this->assertSame($t1->table_number, session('table_number'));
    }

    /** Once the order is completed or cancelled, the party may move. */
    public function test_a_finished_order_does_not_block_the_move(): void
    {
        foreach (['completed', 'cancelled'] as $status) {
            $branch = $this->freshBranch();
            [$t1, $t2] = [$this->table($branch), $this->table($branch)];

            $this->on("phone-$status");
            $this->scanQr($t1)->assertOk();
            $order = $this->openOrderAt($t1, ['status' => $status]);
            GuestOrders::remember($order->id);

            $this->changeTo($t2->code);

            $this->assertNull(session('table_change_error'), $status);
            $this->assertSame($t2->table_number, session('table_number'), $status);
        }
    }

    /**
     * An older party's order left cooking after a staff Clear does not belong
     * to the party sitting there now.
     */
    public function test_a_previous_partys_order_does_not_hold_the_new_party(): void
    {
        $branch = $this->freshBranch();
        [$t1, $t2] = [$this->table($branch), $this->table($branch)];

        $old = $this->openOrderAt($t1);
        Order::whereKey($old->id)->update(['created_at' => now()->subHour()]);

        $this->on('phone');
        $this->scanQr($t1)->assertOk();
        $this->changeTo($t2->code);

        $this->assertNull(session('table_change_error'));
        $this->assertSame($t2->table_number, session('table_number'));
    }

    // ══════════ the old table ══════════

    /** People still seated at the old table keep it, and their clocks. */
    public function test_the_old_table_stays_with_the_people_still_at_it(): void
    {
        $branch = $this->freshBranch();
        [$t1, $t2] = [$this->table($branch), $this->table($branch)];

        $this->on('friend');
        $this->scanQr($t1)->assertOk();
        $friendToken = session(TableOccupancy::SESSION_KEY);

        $this->on('phone');
        $this->scanQr($t1)->assertOk();
        $this->assertSame(2, $this->devices($t1));

        // Backdate the clocks, or a same-second write would look unchanged.
        $seenAt = now()->subMinutes(5)->startOfSecond();
        TableSession::whereKey($this->live($t1)->id)->update(['last_seen_at' => $seenAt, 'last_activity_at' => $seenAt]);

        $this->changeTo($t2->code)->assertRedirect(route('customer.menu'));

        $old = $this->live($t1);
        $this->assertNotNull($old, 'the friend is still at T1');
        $this->assertSame(1, TableOccupancy::activeDeviceCount($old), 'Devices drops by exactly one');
        $this->assertTrue($seenAt->equalTo($old->last_seen_at), 'the 90-minute clock was extended');
        $this->assertTrue($seenAt->equalTo($old->last_activity_at), 'the 15-minute clock was extended');
        $this->assertSame(1, $this->devices($t2));

        // Asked of the service in the friend's own session, not through a
        // status poll: a visitor holding NO table also gets `valid`, so a poll
        // alone would pass even if the friend had been cut loose.
        $this->on('friend');
        $this->assertSame($friendToken, session(TableOccupancy::SESSION_KEY));
        $this->assertSame($old->id, TableOccupancy::currentGuestSession()?->id);
        $this->assertSame(['valid' => true], TableOccupancy::inspectGuestSession());
    }

    /** The staff panel shows exactly what happened, through its own endpoint. */
    public function test_the_occupied_tables_panel_stays_consistent(): void
    {
        $branch = $this->freshBranch();
        [$t1, $t2, $t3] = [$this->table($branch), $this->table($branch), $this->table($branch)];

        $this->on('friend');
        $this->scanQr($t3)->assertOk();

        $this->on('phone');
        $this->scanQr($t1)->assertOk();
        $this->changeTo($t2->code);              // free: T1 -> T2
        $this->changeTo($t3->code);              // occupied: T2 -> T3 (confirm)
        $this->confirm();

        $this->on('staff');
        $rows = collect($this->actingAs(User::where('role', 'admin')->firstOrFail(), 'admin')
            ->getJson('/admin/tables/occupancy')
            ->assertOk()
            ->json('tables'))
            ->where('branch_id', $branch->id)
            ->keyBy('table_number');

        $this->assertSame([$t3->table_number], $rows->keys()->all(), 'only T3 is occupied');
        $this->assertSame(2, $rows[$t3->table_number]['device_count']);
    }

    // ══════════ who it is for ══════════

    public function test_pick_up_is_unaffected(): void
    {
        $branch = $this->freshBranch();
        $t1 = $this->table($branch);

        $this->on('pickup');
        $this->post('/customer/select-branch', ['branch_id' => $branch->id]);
        $this->get('/customer/menu')->assertOk()->assertDontSee('data-table-change-open', false)
            ->assertDontSee('tableChangeModal', false);

        $this->changeTo($t1->code)->assertRedirect(route('customer.menu'));

        $this->assertSame(TableChange::ERR_NOT_DINE_IN, session('error'));
        $this->assertSame('pick_up', session('order_type'));
        $this->assertNull(session('table_number'));
        $this->assertNull($this->live($t1));
    }

    /** A Dine-In session with no branch is no seat — never "Main". */
    public function test_no_branch_means_no_seat_and_no_fallback(): void
    {
        $branch = $this->freshBranch();
        $t1 = $this->table($branch);
        $sessionsBefore = TableSession::count();

        $this->on('phone');
        session()->put(['order_type' => 'dine_in', 'table_number' => '1']);

        $this->changeTo($t1->code)->assertRedirect(route('customer.menu'));

        $this->assertSame(TableChange::ERR_NOT_DINE_IN, session('error'));
        $this->assertSame($sessionsBefore, TableSession::count());
    }

    /**
     * The typed-code "Change table" control is gone from the customer UI —
     * staff move tables now (TableMoveByStaffTest) — while the QR door's
     * "Move to Table X?" confirm still renders, on the cart too.
     */
    public function test_the_typed_code_control_is_gone_but_the_qr_confirm_stays(): void
    {
        $branch = $this->freshBranch();
        [$t1, $t2] = [$this->table($branch), $this->table($branch)];
        $item = $this->orderableItem($branch);

        $this->on('phone');
        $this->scanQr($t1)->assertOk()
            ->assertDontSee('data-table-change-open', false)
            ->assertDontSee('id="tableChangeModal"', false)
            ->assertDontSee('action="' . route('customer.table-change') . '"', false);

        session()->put('cart', $this->cartLine($item));

        $this->get('/customer/cart')->assertOk()
            ->assertDontSee('data-table-change-open', false)
            ->assertDontSee('id="tableChangeModal"', false);

        $this->scanQr($t2)->assertRedirect(route('customer.menu'));

        $this->get('/customer/cart')->assertOk()
            ->assertSee('id="tableChangeConfirm"', false)
            ->assertSee('Move to Table ' . $t2->table_number . '?', false)
            ->assertSee('name="return_to" value="cart"', false)
            ->assertSee('action="' . route('customer.table-change.confirm') . '"', false);
    }

    // ══════════ the bare-number back doors ══════════

    /** The cart's quantity endpoint used to copy any posted table_number. */
    public function test_the_cart_update_endpoint_no_longer_changes_the_table(): void
    {
        $branch = $this->freshBranch();
        $t1 = $this->table($branch);
        $item = $this->orderableItem($branch);

        $this->on('phone');
        $this->scanQr($t1)->assertOk();
        session()->put('cart', $this->cartLine($item));

        $this->put('/customer/cart/update/' . $item->id, ['quantity' => 2, 'table_number' => '99']);

        $this->assertSame($t1->table_number, session('table_number'));
        $this->assertSame(2, session('cart')[(string) $item->id]['quantity']);
    }

    // ══════════ rate limits ══════════

    /** Guessing codes through Change table spends the SAME budget as the code page. */
    public function test_code_guessing_shares_one_budget_with_the_code_page(): void
    {
        $branch = $this->freshBranch();
        [$t1, $t2] = [$this->table($branch), $this->table($branch)];

        $this->on('phone');
        $this->scanQr($t1)->assertOk();

        for ($i = 0; $i < TableEntry::MAX_ATTEMPTS; $i++) {
            $this->from('/customer/dineinqr')->post('/customer/dineinqr', ['table_code' => 'WRONG' . $i . 'XYZ']);
        }

        $this->changeTo($t2->code);

        $this->assertSame('Too many attempts. Please wait a minute and try again.', session('table_change_error'));
        $this->assertSame($t1->table_number, session('table_number'));
    }

    /**
     * The named `table-change` limiter. The per-party half cannot accumulate
     * in this test client (a new session id per request), so the per-address
     * ceiling is the one asserted — the limit a script actually meets.
     */
    public function test_the_table_change_limiter_caps_the_endpoints(): void
    {
        // Every one of these must get through, or the final refusal below could
        // be the per-party limit tripping early rather than the ceiling.
        for ($i = 0; $i < RateLimitServiceProvider::TABLE_CHANGE_PER_IP; $i++) {
            $this->post('/customer/table/change/cancel')
                ->assertRedirect()
                ->assertHeaderMissing('X-RateLimit-Rejected');
        }

        $this->from('/customer/menu')->post('/customer/table/change', ['table_code' => 'X'])
            ->assertRedirect()
            ->assertHeader('X-RateLimit-Rejected', '1');

        $this->from('/customer/menu')->post('/customer/table/change/confirm')
            ->assertHeader('X-RateLimit-Rejected', '1');
    }

    /**
     * Feature tests skip CSRF verification by design, so "CSRF stays on" is
     * asserted structurally: the web group's token check runs on these routes
     * and none of their paths is on its exception list.
     */
    public function test_the_routes_carry_the_named_limiter_and_csrf(): void
    {
        // Building the HTTP kernel is what hands the router its `web` group.
        app(\Illuminate\Contracts\Http\Kernel::class);

        $router = app('router');
        $csrf = app(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $inExcept = new \ReflectionMethod($csrf, 'inExceptArray');

        foreach (['customer.table-change', 'customer.table-change.confirm', 'customer.table-change.cancel'] as $name) {
            $route = $router->getRoutes()->getByName($name);
            $resolved = $router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());

            $this->assertSame(['POST'], $route->methods(), $name);
            $this->assertContains('throttle:table-change', $route->middleware(), $name);
            $this->assertContains(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $resolved, $name);
            $this->assertFalse(
                $inExcept->invoke($csrf, \Illuminate\Http\Request::create('/' . $route->uri(), 'POST')),
                "$name is exempt from CSRF"
            );
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\HelpRequest;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use App\Providers\RateLimitServiceProvider;
use App\Services\TableChange;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Deactivate table" / "Reactivate table" on the QR & Table Codes card — an
 * admin or supervisor takes a mistaken table out of service, and brings it
 * back. See TableOccupancy::setInService() and AdminController::
 * changeTableService().
 *
 * Tables are never deleted. The flag these actions flip (restaurant_tables.
 * is_active) was already honoured by the QR door, the typed-code door and
 * "Move table"; the control was what was missing, so most of this file asserts
 * that those existing doors really do answer to it.
 *
 * Branch-agnostic on purpose: no branch id, table id or table number is named
 * anywhere. The matrices run over every active branch in the database plus one
 * created inside the test, and every table is registered here with a random
 * seven-digit number (fixed length, so one can never be a prefix of another in
 * an assertSee()).
 *
 * Devices: the test client keeps ONE session store, so on($device) parks the
 * current device's session and restores the next one's — the same helper as
 * TableMoveByStaffTest. Admin calls run as their own "device" too.
 */
class TableServiceByAdminTest extends TestCase
{
    use DatabaseTransactions;

    private const DEACTIVATE = '/admin/qr-generator/deactivate-table';
    private const REACTIVATE = '/admin/qr-generator/reactivate-table';

    /** @var array<string, array> */
    private array $jar = [];

    private ?string $device = null;

    private string $auditLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'pomida_db_testing',
            DB::connection()->getDatabaseName(),
            'refusing to run anywhere but the testing database'
        );

        app('cache')->store()->flush();

        // Every action writes an audit line. Keep the test runs' out of
        // storage/logs; the real path is asserted in its own test.
        $this->auditLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'table-service-test-' . Str::random(10) . '.log';
        config(['logging.channels.table_moves.path' => $this->auditLog]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->auditLog)) {
            unlink($this->auditLog);
        }

        app('cache')->store()->flush();
        parent::tearDown();
    }

    // ══════════ fixtures ══════════

    private function freshBranch(): Branch
    {
        return Branch::create([
            'name'           => 'Table Service ' . Str::random(6),
            'code'           => 'TS' . strtoupper(Str::random(6)),
            'address'        => 'Created by TableServiceByAdminTest',
            'is_active'      => true,
            'is_main_branch' => false,
        ]);
    }

    /** Every active branch, plus one created now with no history at all. */
    private function branchesUnderTest()
    {
        return Branch::where('is_active', true)->orderBy('id')->get()->push($this->freshBranch());
    }

    /** A registered table with a random number nothing at that branch has used. */
    private function table(Branch $branch): RestaurantTable
    {
        do {
            $number = (string) random_int(1000000, 9999999);
        } while (
            RestaurantTable::where('branch_id', $branch->id)->where('table_number', $number)->exists()
            || TableSession::where('branch_id', $branch->id)->where('table_number', $number)->exists()
            || Order::where('branch_id', $branch->id)->where('table_number', $number)->exists()
        );

        return TableEntry::findOrRegister($branch->id, $number);
    }

    private function portalUser(string $role, ?int $branchId): User
    {
        return User::create([
            'name'              => 'TableService ' . ucfirst($role),
            'email'             => 'tableservice-' . $role . '-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => $role,
            'branch_id'         => $branchId,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function owner(): User
    {
        return User::where('role', 'admin')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    /** Switch to another phone (or admin screen). Each name keeps its own session. */
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

    private function scanQr(RestaurantTable $table)
    {
        return $this->get('/customer/menu?branch_id=' . $table->branch_id
            . '&table=' . $table->table_number . '&k=' . $table->code);
    }

    /** Seat a phone through the real QR door; returns the table's live session. */
    private function seat(string $device, RestaurantTable $table): TableSession
    {
        $this->on($device);
        app('cache')->store()->flush();
        $this->scanQr($table)->assertOk();

        $session = $this->live($table);
        $this->assertNotNull($session, "{$device} was not seated at Table {$table->table_number}");

        return $session;
    }

    private function live(RestaurantTable $table): ?TableSession
    {
        return TableOccupancy::activeFor($table->branch_id, $table->table_number);
    }

    /**
     * The matrices make more admin calls than one minute's per-address budget
     * allows; the budget itself is asserted on its own (see the limiter test).
     */
    private function asAdmin(User $actor)
    {
        app('cache')->store()->flush();
        $this->on('admin-' . $actor->id);

        return $this->actingAs($actor, 'admin');
    }

    private function deactivate(User $actor, $tableId, array $extra = [])
    {
        return $this->asAdmin($actor)->postJson(self::DEACTIVATE, array_merge(['table_id' => $tableId], $extra));
    }

    private function reactivate(User $actor, $tableId, array $extra = [])
    {
        return $this->asAdmin($actor)->postJson(self::REACTIVATE, array_merge(['table_id' => $tableId], $extra));
    }

    private function move(User $actor, int $sessionId, $tableId)
    {
        return $this->asAdmin($actor)->postJson('/admin/tables/move', [
            'session_id' => $sessionId,
            'table_id'   => $tableId,
        ]);
    }

    private function targets(User $actor, int $sessionId)
    {
        return $this->asAdmin($actor)->getJson('/admin/tables/move-targets?session_id=' . $sessionId);
    }

    private function orderAt(RestaurantTable $table, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TS-' . strtoupper(Str::random(8)),
            'user_id'      => null,
            'branch_id'    => $table->branch_id,
            'type'         => 'dine_in',
            'table_number' => $table->table_number,
            'status'       => 'preparing',
            'subtotal'     => 100,
            'total'        => 100,
        ], $attrs));
    }

    private function helpRequestAt(RestaurantTable $table, string $status): HelpRequest
    {
        return HelpRequest::create([
            'branch_id'    => $table->branch_id,
            'table_number' => $table->table_number,
            'status'       => $status,
            'message'      => 'TableServiceByAdminTest',
            'requested_at' => now(),
        ]);
    }

    /** @return array<int, array> decoded context of every audit line written so far */
    private function auditRecords(): array
    {
        if (!is_file($this->auditLog)) {
            return [];
        }

        $records = [];

        foreach (file($this->auditLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $this->assertSame(1, preg_match('/\.INFO: (Table [a-z]+) (\{.*\})/', $line, $m), 'unreadable audit line: ' . $line);
            $record = json_decode($m[2], true);
            $this->assertIsArray($record);
            $records[] = ['message' => $m[1]] + $record;
        }

        return $records;
    }

    // ══════════ deactivate ══════════

    /** The row is flagged, and nothing is deleted or rewritten. */
    public function test_deactivating_takes_a_table_out_of_service_and_deletes_nothing(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $table = $this->table($branch)->fresh();
            $neighbour = $this->table($branch);
            $rows = RestaurantTable::count();

            $this->deactivate($this->owner(), $table->id)
                ->assertOk()
                ->assertExactJson([
                    'message'      => "Table {$table->table_number} at {$branch->name} is now out of service. Existing order history is kept.",
                    'changed'      => true,
                    'table_id'     => $table->id,
                    'branch_id'    => (int) $branch->id,
                    'table_number' => $table->table_number,
                    'is_active'    => false,
                ]);

            $after = RestaurantTable::find($table->id);

            $this->assertNotNull($after, "{$label}: the row was deleted");
            $this->assertFalse($after->is_active, $label);
            $this->assertSame($table->code, $after->code, "{$label}: the code changed");
            $this->assertSame($table->previous_code, $after->previous_code, $label);
            $this->assertSame($table->table_number, $after->table_number, $label);
            $this->assertSame((int) $table->branch_id, (int) $after->branch_id, $label);
            $this->assertTrue($table->created_at->equalTo($after->created_at), $label);
            $this->assertSame($rows, RestaurantTable::count(), "{$label}: the table count changed");
            $this->assertTrue($neighbour->fresh()->is_active, "{$label}: another table was touched");
        }
    }

    /** Deactivated twice is the same as deactivated once; it is not an error and not a second audit line. */
    public function test_deactivating_twice_changes_nothing_the_second_time(): void
    {
        $branch = $this->freshBranch();
        $table = $this->table($branch);
        $owner = $this->owner();

        $this->deactivate($owner, $table->id)->assertOk()->assertJson(['changed' => true]);
        $this->deactivate($owner, $table->id)->assertOk()->assertJson([
            'changed'   => false,
            'is_active' => false,
            'message'   => "Table {$table->table_number} at {$branch->name} is already out of service.",
        ]);

        // Reactivating one that is already in service is the same story.
        $other = $this->table($branch);
        $this->reactivate($owner, $other->id)->assertOk()->assertJson([
            'changed'   => false,
            'is_active' => true,
            'message'   => "Table {$other->table_number} at {$branch->name} is already in service.",
        ]);

        $this->assertCount(1, $this->auditRecords(), 'a request that changed nothing was audited');
    }

    // ══════════ the doors ══════════

    /** The phone-camera QR, the in-page scanner and the typed code all say "not in service". */
    public function test_a_table_out_of_service_is_refused_at_the_qr_scan_and_the_typed_code_door(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $table = $this->table($branch);
            $this->deactivate($this->owner(), $table->id)->assertOk();

            // 1. The phone's own camera app opening the QR's URL.
            $this->on('camera-app');
            app('cache')->store()->flush();
            $this->scanQr($table)->assertRedirect(route('customer.dineinqr'));
            $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, session('error'), "{$label}: QR landing");
            $this->assertNull(session('table_number'), $label);

            // 2. Our in-page camera scanner posting what it decoded.
            $this->on('scanner');
            app('cache')->store()->flush();
            $this->from('/customer/dineinqr')->post('/customer/dineinqr', [
                'tableData' => $table->qrUrl(),
                'next'      => 'guest',
            ])->assertRedirect('/customer/dineinqr');
            $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, session('error'), "{$label}: scanner");

            // 3. The code typed off the standee.
            $this->on('typed');
            app('cache')->store()->flush();
            $this->from('/customer/dineinqr')->post('/customer/dineinqr', [
                'table_code' => $table->code,
                'next'       => 'guest',
            ])->assertRedirect('/customer/dineinqr');
            $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, session('error'), "{$label}: typed code");
            $this->assertNull(session('table_number'), $label);

            $this->assertSame(
                'That table is not in service right now. Please ask our staff to seat you.',
                TableEntry::ERR_TABLE_INACTIVE
            );
            $this->assertNull($this->live($table), "{$label}: a refused door still seated somebody");
        }
    }

    /** It leaves the Move list, and a hand-posted id for it is refused. */
    public function test_it_disappears_from_the_move_list_and_a_posted_id_is_refused(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            [$seat, $free, $gone] = [$this->table($branch), $this->table($branch), $this->table($branch)];
            $owner = $this->owner();

            $session = $this->seat('phone', $seat);

            $before = collect($this->targets($owner, $session->id)->assertOk()->json('tables'))->pluck('id')->all();
            $this->assertContains($gone->id, $before, "{$label}: a table in service was not offered");

            $this->deactivate($owner, $gone->id)->assertOk();

            $after = collect($this->targets($owner, $session->id)->assertOk()->json('tables'))->pluck('id')->all();
            $this->assertContains($free->id, $after, "{$label}: a table still in service was dropped");
            $this->assertNotContains($gone->id, $after, "{$label}: the deactivated table is still offered");

            // The dialog never offers it, but nothing stops a hand-built POST.
            $this->move($owner, $session->id, $gone->id)
                ->assertStatus(422)
                ->assertJson(['message' => "Table {$gone->table_number} is not in service. Please pick another table."]);

            $this->assertSame($session->id, $this->live($seat)?->id, "{$label}: the party moved anyway");
            $this->assertNull($this->live($gone), $label);
        }
    }

    // ══════════ occupied ══════════

    /** A table with a live session is refused, and nothing about it changes. */
    public function test_an_occupied_table_cannot_be_deactivated(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            [$byCustomer, $byCounter] = [$this->table($branch), $this->table($branch)];
            $owner = $this->owner();

            // 1. A customer seated through the QR door.
            $session = $this->seat('phone', $byCustomer);

            $this->deactivate($owner, $byCustomer->id)
                ->assertStatus(409)
                ->assertExactJson(['message' => "Table {$byCustomer->table_number} is occupied. Move or clear it first."]);

            $this->assertTrue($byCustomer->fresh()->is_active, "{$label}: an occupied table was deactivated");
            $this->assertSame($session->id, $this->live($byCustomer)?->id, "{$label}: the session was disturbed");

            // 2. A counter order seats the table too — no phone involved.
            $order = $this->orderAt($byCounter);
            TableOccupancy::attachStaffOrder($order, $owner->id);
            $this->assertNotNull($this->live($byCounter), $label);

            $this->deactivate($owner, $byCounter->id)->assertStatus(409);
            $this->assertTrue($byCounter->fresh()->is_active, $label);

            // The message says what to do, and doing it works.
            TableOccupancy::releaseTable($branch->id, $byCustomer->table_number, $owner->id);
            $this->deactivate($owner, $byCustomer->id)->assertOk();
            $this->assertFalse($byCustomer->fresh()->is_active, $label);

            $this->assertSame('deactivate', $this->auditRecords()[0]['action'], "{$label}: the refusals were audited");
            $this->assertCount(1, $this->auditRecords(), $label);
            @unlink($this->auditLog);
        }
    }

    /** A ghost session nobody has touched for the idle window does not block a deactivation. */
    public function test_a_session_that_went_silent_does_not_keep_a_table_in_service(): void
    {
        $branch = $this->freshBranch();
        $table = $this->table($branch);

        $session = $this->seat('walked-off', $table);

        TableSession::whereKey($session->id)->update([
            'last_seen_at' => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES + 5),
        ]);

        $this->deactivate($this->owner(), $table->id)->assertOk();

        $this->assertFalse($table->fresh()->is_active);
        $this->assertNull($this->live($table));
    }

    // ══════════ reactivate ══════════

    /** Reactivating puts back every door, the Move list and the same code. */
    public function test_reactivating_restores_everything(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            [$byQr, $moveTarget, $party] = [$this->table($branch), $this->table($branch), $this->table($branch)];
            $owner = $this->owner();
            $codes = [$byQr->code, $moveTarget->code];

            $this->deactivate($owner, $byQr->id)->assertOk();
            $this->deactivate($owner, $moveTarget->id)->assertOk();

            $this->reactivate($owner, $byQr->id)
                ->assertOk()
                ->assertExactJson([
                    'message'      => "Table {$byQr->table_number} at {$branch->name} is back in service. Customers can open it again.",
                    'changed'      => true,
                    'table_id'     => $byQr->id,
                    'branch_id'    => (int) $branch->id,
                    'table_number' => $byQr->table_number,
                    'is_active'    => true,
                ]);
            $this->reactivate($owner, $moveTarget->id)->assertOk();

            $this->assertSame($codes, [$byQr->fresh()->code, $moveTarget->fresh()->code], "{$label}: a code changed");
            $this->assertNull($byQr->fresh()->previous_code, $label);

            // The QR door seats a customer again.
            $this->on('customer');
            app('cache')->store()->flush();
            $this->scanQr($byQr)->assertOk();
            $this->assertNotNull($this->live($byQr), "{$label}: the QR door still refuses");

            // The typed-code door too (a different phone, so it is a fresh visit).
            $other = $this->table($branch);
            $this->deactivate($owner, $other->id)->assertOk();
            $this->reactivate($owner, $other->id)->assertOk();
            $this->on('typist');
            app('cache')->store()->flush();
            $this->from('/customer/dineinqr')->post('/customer/dineinqr', ['table_code' => $other->code, 'next' => 'guest']);
            $this->assertNotNull($this->live($other), "{$label}: the typed code still refuses");

            // Move table offers it and moves a party into it.
            $session = $this->seat('mover', $party);
            $offered = collect($this->targets($owner, $session->id)->assertOk()->json('tables'))->pluck('id')->all();
            $this->assertContains($moveTarget->id, $offered, "{$label}: Move does not offer it back");

            $this->move($owner, $session->id, $moveTarget->id)->assertOk();
            $this->assertSame($session->id, $this->live($moveTarget)?->id, $label);

            $actions = array_column($this->auditRecords(), 'action');
            $this->assertSame(
                ['deactivate', 'deactivate', 'reactivate', 'reactivate', 'deactivate', 'reactivate', 'move'],
                $actions,
                "{$label}: the trail"
            );
            @unlink($this->auditLog);
        }
    }

    // ══════════ history is untouched ══════════

    /** Orders and help requests are never rewritten; they keep the table they were made at. */
    public function test_orders_and_help_requests_keep_their_table_number(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $table = $this->table($branch);
            $owner = $this->owner();

            $orders = [
                $this->orderAt($table, ['status' => 'completed']),
                $this->orderAt($table, ['status' => 'cancelled']),
                $this->orderAt($table, ['status' => 'preparing']),
            ];
            $help = [
                $this->helpRequestAt($table, 'pending'),
                $this->helpRequestAt($table, 'resolved'),
            ];

            $snapshot = fn () => [
                'orders' => Order::whereIn('id', array_column($orders, 'id'))->orderBy('id')->get()->map->getAttributes()->all(),
                'help'   => HelpRequest::whereIn('id', array_column($help, 'id'))->orderBy('id')->get()->map->getAttributes()->all(),
            ];

            $before = $snapshot();
            $this->assertCount(3, $before['orders']);
            $this->assertCount(2, $before['help']);

            $this->deactivate($owner, $table->id)->assertOk();
            $this->assertSame($before, $snapshot(), "{$label}: history changed on deactivate");

            $this->reactivate($owner, $table->id)->assertOk();
            $this->assertSame($before, $snapshot(), "{$label}: history changed on reactivate");

            foreach ($before['orders'] as $row) {
                $this->assertSame($table->table_number, $row['table_number'], $label);
            }
        }
    }

    /** Nothing is ever deleted, however many times it is toggled. */
    public function test_the_row_is_never_deleted(): void
    {
        $branch = $this->freshBranch();
        $table = $this->table($branch)->fresh();
        $owner = $this->owner();
        $rows = RestaurantTable::count();

        foreach (range(1, 3) as $cycle) {
            $this->deactivate($owner, $table->id)->assertOk();
            $this->assertNotNull(RestaurantTable::find($table->id), "cycle {$cycle}: deleted by deactivate");

            $this->reactivate($owner, $table->id)->assertOk();
            $this->assertNotNull(RestaurantTable::find($table->id), "cycle {$cycle}: deleted by reactivate");
        }

        $kept = RestaurantTable::find($table->id);
        $this->assertSame($rows, RestaurantTable::count());
        $this->assertTrue($kept->is_active);
        $this->assertSame($table->code, $kept->code);
        $this->assertTrue($table->created_at->equalTo($kept->created_at));
    }

    // ══════════ who may, and where ══════════

    /** A manager of another branch — or of no branch — gets the same 404 as for an id that does not exist. */
    public function test_an_out_of_scope_admin_gets_a_404_and_nothing_changes(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $table = $this->table($branch);
            $owner = $this->owner();
            $rows = RestaurantTable::count();

            $elsewhere = $this->portalUser('supervisor', $this->freshBranch()->id);
            $branchless = $this->portalUser('supervisor', null);
            $missing = (int) RestaurantTable::max('id') + 1000000;

            foreach ([$elsewhere, $branchless] as $who) {
                // Deactivate it...
                $this->deactivate($who, $table->id)->assertNotFound();
                $this->assertTrue($table->fresh()->is_active, "{$label}: {$who->name} deactivated another branch's table");

                // ...and reactivate one that really is out of service.
                $this->deactivate($owner, $table->id)->assertOk();
                $this->reactivate($who, $table->id)->assertNotFound();
                $this->assertFalse($table->fresh()->is_active, "{$label}: reactivated across branches");
                $this->reactivate($owner, $table->id)->assertOk();
            }

            // Out of scope reads exactly like "no such table": the same status,
            // and nothing in the body about the branch or the table's number.
            $this->deactivate($elsewhere, $missing)->assertNotFound();
            $refused = $this->deactivate($elsewhere, $table->id)->assertNotFound()->getContent();
            $this->assertStringNotContainsString($table->table_number, $refused, $label);
            $this->assertStringNotContainsString($branch->name, $refused, $label);
            $this->reactivate($elsewhere, $missing)->assertNotFound();

            // The right branch's supervisor is allowed, both ways.
            $own = $this->portalUser('supervisor', $branch->id);
            $this->deactivate($own, $table->id)->assertOk();
            $this->assertFalse($table->fresh()->is_active, $label);
            $this->reactivate($own, $table->id)->assertOk();
            $this->assertTrue($table->fresh()->is_active, $label);

            $this->assertSame($rows, RestaurantTable::count(), $label);
        }
    }

    /** Same tier as regenerate-code: staff are bounced by the role gate and nothing changes. */
    public function test_staff_cannot_use_either_endpoint(): void
    {
        $branch = $this->freshBranch();
        $table = $this->table($branch);
        $staff = $this->portalUser('staff', $branch->id);

        $this->deactivate($staff, $table->id)->assertRedirect(route('admin.home'));
        $this->assertSame("You don't have permission to access that.", session('error'));
        $this->assertTrue($table->fresh()->is_active);

        $this->deactivate($this->owner(), $table->id)->assertOk();
        $this->reactivate($staff, $table->id)->assertRedirect(route('admin.home'));
        $this->assertFalse($table->fresh()->is_active);
    }

    /** Only the registry id is read: the branch is the row's, whatever else is posted. */
    public function test_a_forged_branch_id_is_ignored(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $other = $this->freshBranch();
            $number = (string) random_int(1000000, 9999999);
            [$mine, $theirs] = [
                TableEntry::findOrRegister($branch->id, $number),
                TableEntry::findOrRegister($other->id, $number),
            ];
            $forged = ['branch_id' => $other->id, 'branch' => $other->id, 'table_number' => $number, 'code' => $theirs->code];

            // An owner naming the OTHER branch (and its identical table number)
            // still acts on the row whose id was sent.
            $this->deactivate($this->owner(), $mine->id, $forged)
                ->assertOk()
                ->assertJson(['table_id' => $mine->id, 'branch_id' => (int) $branch->id]);
            $this->assertFalse($mine->fresh()->is_active, $label);
            $this->assertTrue($theirs->fresh()->is_active, "{$label}: the forged branch's table was touched");

            $this->reactivate($this->owner(), $mine->id, $forged)->assertOk();
            $this->assertTrue($mine->fresh()->is_active, $label);
            $this->assertTrue($theirs->fresh()->is_active, $label);

            // A supervisor cannot widen their reach by claiming their own
            // branch while naming another branch's table.
            $supervisor = $this->portalUser('supervisor', $branch->id);
            $this->deactivate($supervisor, $theirs->id, ['branch_id' => $branch->id])->assertNotFound();
            $this->assertTrue($theirs->fresh()->is_active, "{$label}: a forged branch widened a supervisor's scope");
        }
    }

    /** The id must be an integer; anything else is a validation error, never a lookup. */
    public function test_a_malformed_table_id_is_a_validation_error(): void
    {
        $owner = $this->owner();

        foreach ([self::DEACTIVATE, self::REACTIVATE] as $url) {
            foreach ([[], ['table_id' => 'abc'], ['table_id' => ['1']], ['table_id' => '1 OR 1=1']] as $payload) {
                $this->asAdmin($owner)->postJson($url, $payload)->assertStatus(422);
            }
        }
    }

    // ══════════ CSRF and the rate limit ══════════

    public function test_the_routes_are_role_gated_throttled_and_csrf_protected(): void
    {
        app(\Illuminate\Contracts\Http\Kernel::class);

        $router = app('router');
        $csrf = app(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $inExcept = new \ReflectionMethod($csrf, 'inExceptArray');
        $regenerate = $router->getRoutes()->getByName('admin.qr-generator.regenerate-code');

        foreach (['admin.qr-generator.deactivate-table', 'admin.qr-generator.reactivate-table'] as $name) {
            $route = $router->getRoutes()->getByName($name);

            $this->assertSame(['POST'], $route->methods(), $name);
            $this->assertContains('throttle:admin-qr-table-service', $route->gatherMiddleware(), $name);

            // The same role gate as regenerate-code, not a spelling of its own.
            $this->assertContains('role:admin,supervisor', $route->gatherMiddleware(), $name);
            $this->assertContains('role:admin,supervisor', $regenerate->gatherMiddleware());

            $this->assertContains(
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
                $router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware()),
                $name
            );
            $this->assertFalse($inExcept->invoke($csrf, \Illuminate\Http\Request::create('/' . $route->uri(), 'POST')), $name);
        }
    }

    /** CSRF for real: no token is a 419 and nothing changes; this session's own token gets through. */
    public function test_a_request_without_a_csrf_token_is_refused(): void
    {
        $branch = $this->freshBranch();
        [$active, $inactive] = [$this->table($branch), $this->table($branch)];
        $owner = $this->owner();

        $inactive->forceFill(['is_active' => false])->save();

        // ValidateCsrfToken skips itself under runningUnitTests(); flipping the
        // resolved env is what turns it back on (see CsrfJsonRequestTest).
        $this->app['env'] = 'production';
        $this->assertFalse($this->app->runningUnitTests());

        // Both endpoints, without a token. (Done before any request carries
        // one: withHeaders() persists for the rest of the test.)
        $this->asAdmin($owner)->postJson(self::DEACTIVATE, ['table_id' => $active->id])->assertStatus(419);
        $this->asAdmin($owner)->postJson(self::REACTIVATE, ['table_id' => $inactive->id])->assertStatus(419);
        $this->assertTrue($active->fresh()->is_active, 'a 419 still deactivated the table');
        $this->assertFalse($inactive->fresh()->is_active, 'a 419 still reactivated the table');

        // With this session's own token both go through.
        $this->asAdmin($owner)->get('/admin/qr-generator')->assertOk();
        $this->actingAs($owner, 'admin')->withHeaders(['X-CSRF-TOKEN' => csrf_token()])
            ->postJson(self::DEACTIVATE, ['table_id' => $active->id])
            ->assertOk();
        $this->actingAs($owner, 'admin')->withHeaders(['X-CSRF-TOKEN' => csrf_token()])
            ->postJson(self::REACTIVATE, ['table_id' => $inactive->id])
            ->assertOk();
        $this->assertFalse($active->fresh()->is_active);
        $this->assertTrue($inactive->fresh()->is_active);
    }

    /** One per-address counter for the pair, separate from regenerate-code's. */
    public function test_the_endpoints_are_rate_limited_per_address(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'admin');

        $ip = ['REMOTE_ADDR' => '203.0.113.' . random_int(10, 250)];

        // A junk body costs nothing but still passes the throttle, which runs
        // before the controller.
        for ($i = 0; $i < RateLimitServiceProvider::ADMIN_QR_TABLE_SERVICE_PER_IP; $i++) {
            $this->withServerVariables($ip)->postJson(self::DEACTIVATE, [])->assertStatus(422);
        }

        // A plain 429, like regenerate-code and Move table: the admin routes
        // use the raw named throttle, not the customer-facing friendly wrapper.
        $this->withServerVariables($ip)->postJson(self::DEACTIVATE, [])->assertStatus(429);

        // Reactivate is the same feature, so it shares the spent counter...
        $this->withServerVariables($ip)->postJson(self::REACTIVATE, [])->assertStatus(429);

        // ...but regenerate-code and the panel's own endpoints have their own.
        $this->withServerVariables($ip)->postJson('/admin/qr-generator/regenerate-code', [])->assertStatus(422);
        $this->withServerVariables($ip)->getJson('/admin/tables/occupancy')->assertOk();

        // And another address is unaffected.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.' . random_int(10, 250)])
            ->postJson(self::DEACTIVATE, [])->assertStatus(422);
    }

    // ══════════ the card ══════════

    /** Generate QR on an out-of-service table still answers with the card, flagged, and changes nothing. */
    public function test_generate_qr_on_an_inactive_table_still_returns_the_card_in_the_inactive_state(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $table = $this->table($branch)->fresh();
            $owner = $this->owner();
            $url = '/admin/qr-generator/table-card?branch_id=' . $branch->id . '&table_number=' . $table->table_number;

            $this->asAdmin($owner)->getJson($url)->assertOk()->assertJson(['table_id' => $table->id, 'is_active' => true]);

            $this->deactivate($owner, $table->id)->assertOk();
            $rows = RestaurantTable::count();

            foreach ([$owner, $this->portalUser('supervisor', $branch->id), $this->portalUser('staff', $branch->id)] as $who) {
                $this->asAdmin($who)->getJson($url)
                    ->assertOk()
                    ->assertJson([
                        'table_id'     => $table->id,
                        'table_number' => $table->table_number,
                        'code'         => $table->code,
                        'url'          => $table->qrUrl(),
                        'branch_id'    => (int) $branch->id,
                        'is_active'    => false,
                    ]);
            }

            $this->assertSame($rows, RestaurantTable::count(), "{$label}: Generate QR registered a second row");
            $this->assertFalse($table->fresh()->is_active, "{$label}: Generate QR reactivated the table");
            $this->assertSame($table->code, $table->fresh()->code, $label);
        }
    }

    /** The redrawn card after a code rotation keeps reporting the table's state. */
    public function test_regenerating_a_code_reports_the_table_state_too(): void
    {
        $table = $this->table($this->freshBranch());
        $owner = $this->owner();

        $this->asAdmin($owner)->postJson('/admin/qr-generator/regenerate-code', ['table_id' => $table->id])
            ->assertOk()->assertJson(['is_active' => true]);

        $this->deactivate($owner, $table->id)->assertOk();

        $this->asAdmin($owner)->postJson('/admin/qr-generator/regenerate-code', ['table_id' => $table->id])
            ->assertOk()->assertJson(['is_active' => false]);
    }

    /**
     * The card preview has no Deactivate / Reactivate button for any role — only
     * an information note for a table that is not in service. Since Oct 2026 no
     * role's page carries the endpoints at all (the Manage tables list that
     * posted to them is gone; the endpoints are kept, unused by the UI).
     */
    public function test_the_card_has_no_deactivate_or_reactivate_button_only_a_note(): void
    {
        $branch = $this->freshBranch();

        foreach ([$this->owner(), $this->portalUser('supervisor', $branch->id), $this->portalUser('staff', $branch->id)] as $user) {
            $who = $user->role;
            $html = $this->asAdmin($user)->get('/admin/qr-generator')->assertOk()->getContent();

            // The button, its icon/label ids, its handlers and its confirm dialog are all gone.
            foreach ([
                'id="serviceCardBtn"', 'id="serviceCardLabel"', 'id="serviceCardIcon"', 'onServiceButton',
                'id="tableServiceDialog"', 'id="serviceConfirmBtn"', 'function confirmDeactivate', 'function reactivateCurrentTable',
                'Deactivate table', 'Reactivate table', 'Deactivate Table',
            ] as $gone) {
                $this->assertStringNotContainsString($gone, $html, "{$who}: left on the card: {$gone}");
            }

            // No <button> inside the generated-card preview other than Print / Download / Regenerate Code.
            $this->assertSame(1, preg_match('/<div id="qrResult".*?<p id="regenMsg"/s', $html, $m), "{$who}: card preview not found");
            preg_match_all('/<button\b[^>]*\bid="([^"]+)"/', $m[0], $ids);
            $expected = $user->role === 'staff' ? ['printBtn', 'downloadBtn'] : ['printBtn', 'downloadBtn', 'regenCardBtn'];
            $this->assertSame($expected, $ids[1], "{$who}: buttons in the card preview");

            // The not-in-service note stays, for every role, with no button in it.
            $this->assertSame(1, preg_match('/<p id="serviceNotice" role="status">(.*?)<\/p>/s', $html, $note), $who);
            $this->assertStringContainsString('Not in service.', $note[1]);
            $this->assertStringNotContainsString('<button', $note[1]);
            $this->assertStringNotContainsString('onclick', $note[1]);

            // A freshly generated card is still drawn in its inactive state.
            $this->assertMatchesRegularExpression('/currentCard = data;\s+renderServiceState\(\);/', $html, $who);
        }

        // Oct 2026: the Manage tables list is gone, so the note points nobody at a
        // restore — every role reads the same sentence — and no role's page carries
        // the two endpoints (kept on purpose, unused by the UI).
        foreach ([$this->owner(), $this->portalUser('supervisor', $branch->id), $this->portalUser('staff', $branch->id)] as $user) {
            $this->asAdmin($user)->get('/admin/qr-generator')->assertOk()
                ->assertSee('Customers cannot open this table by QR or code.', false)
                ->assertDontSee('Restore it in Manage tables.', false)
                ->assertDontSee('Ask a manager to restore it.', false)
                ->assertDontSee(route('admin.qr-generator.deactivate-table', [], false), false)
                ->assertDontSee(route('admin.qr-generator.reactivate-table', [], false), false);
        }
    }

    /** The page's intro no longer tells a manager to use a Deactivate button on the card. */
    public function test_the_intro_no_longer_mentions_a_deactivate_button_on_the_card(): void
    {
        $branch = $this->freshBranch();

        foreach ([$this->owner(), $this->portalUser('supervisor', $branch->id)] as $manager) {
            $html = $this->asAdmin($manager)->get('/admin/qr-generator')->assertOk()->getContent();

            $this->assertSame(1, preg_match('/<div class="content-card">\s*<p[^>]*>(.*?)<\/p>/s', $html, $intro), $manager->role);
            $this->assertStringNotContainsString('Deactivate', $intro[1]);
            $this->assertStringNotContainsString('Reactivate', $intro[1]);
            $this->assertStringContainsString('<strong>Regenerate Code</strong>', $intro[1]);
            // Oct 2026: a mistaken table is deleted from the Move table list, not Manage tables.
            $this->assertStringNotContainsString('Manage tables', $intro[1]);
            $this->assertStringContainsString('<strong>Move table</strong>', $intro[1]);
        }
    }

    // ══════════ the audit trail ══════════

    /** One line per action in the existing table-moves log, each with an `action`; none for a refusal or a no-op. */
    public function test_each_action_writes_one_audit_line_with_an_action_field(): void
    {
        $branch = $this->freshBranch();
        [$table, $busy, $from, $to] = [$this->table($branch), $this->table($branch), $this->table($branch), $this->table($branch)];
        $supervisor = $this->portalUser('supervisor', $branch->id);
        $owner = $this->owner();

        $this->seat('phone', $busy);
        $this->deactivate($supervisor, $busy->id)->assertStatus(409);
        $this->assertFileDoesNotExist($this->auditLog, 'a refused deactivation was audited');

        $this->deactivate($supervisor, $table->id)->assertOk();
        $this->deactivate($supervisor, $table->id)->assertOk();     // no-op
        $this->reactivate($owner, $table->id)->assertOk();

        $session = $this->seat('mover', $from);
        $this->move($owner, $session->id, $to->id)->assertOk();

        $records = $this->auditRecords();
        $this->assertSame(['deactivate', 'reactivate', 'move'], array_column($records, 'action'));
        $this->assertSame(['Table deactivated', 'Table reactivated', 'Table moved'], array_column($records, 'message'));

        [$deactivated, $reactivated, $moved] = $records;

        $this->assertSame($supervisor->id, $deactivated['acted_by_id']);
        $this->assertSame($supervisor->name, $deactivated['acted_by_name']);
        $this->assertSame('supervisor', $deactivated['acted_by_role']);
        $this->assertSame((int) $branch->id, $deactivated['branch_id']);
        $this->assertSame($table->id, $deactivated['table_id']);
        $this->assertSame($table->table_number, $deactivated['table_number']);
        $this->assertLessThan(60, abs(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($deactivated['acted_at']))));

        $this->assertSame($owner->id, $reactivated['acted_by_id']);
        $this->assertSame('admin', $reactivated['acted_by_role']);

        // The existing Move line is unchanged apart from the new field.
        $this->assertSame($owner->id, $moved['moved_by_id']);
        $this->assertSame($from->table_number, $moved['from_table']);
        $this->assertSame($to->table_number, $moved['to_table']);

        // The SAME channel — no new log channel and no new file.
        $channels = (require config_path('logging.php'))['channels'];
        $this->assertSame(storage_path('logs/table-moves.log'), $channels['table_moves']['path']);
        $this->assertSame('info', $channels['table_moves']['level']);
        $this->assertSame([], array_values(array_filter(
            array_keys($channels),
            fn ($name) => $name !== 'table_moves' && preg_match('/table|service|active/i', $name)
        )), 'a new table log channel was added');
    }

    /** The log is written after the commit; if it cannot be written the change still happened and is reported as done. */
    public function test_an_unwritable_audit_log_does_not_misreport_a_completed_change(): void
    {
        config(['logging.default' => 'null']);
        config(['logging.channels.table_moves.path' => sys_get_temp_dir()]);   // a directory is not a file

        $table = $this->table($this->freshBranch());
        $owner = $this->owner();

        $this->deactivate($owner, $table->id)->assertOk()->assertJson(['is_active' => false]);
        $this->assertFalse($table->fresh()->is_active);

        $this->reactivate($owner, $table->id)->assertOk()->assertJson(['is_active' => true]);
        $this->assertTrue($table->fresh()->is_active);
    }

    // ══════════ the race with a customer claiming the table ══════════

    /**
     * The window the registry lock exists for: a customer passed validation
     * (a plain read), the admin deactivated, and only then did the claim run.
     * claim() re-reads the flag under the registry row's lock and refuses —
     * otherwise the party would be seated at a table the admin was told was
     * empty and has just taken out of service.
     */
    public function test_a_claim_that_passed_validation_before_the_deactivation_is_refused(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $table = $this->table($branch);

            $this->assertTrue(TableEntry::validate($branch->id, $table->table_number, $table->code)['ok'], $label);

            $this->deactivate($this->owner(), $table->id)->assertOk();

            $this->on('late-customer');
            $claim = TableOccupancy::claim($branch, $table->table_number, '127.0.0.1');

            $this->assertFalse($claim['ok'], "{$label}: the claim seated a customer at a table out of service");
            $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, $claim['error'], $label);
            $this->assertNull($this->live($table), "{$label}: a session row was written");

            // An unregistered table is still not a refusal: the registry adds a
            // code and a card, it does not become a gate.
            $unregistered = (string) random_int(1000000, 9999999);
            $this->assertTrue(TableOccupancy::claim($branch, $unregistered, '127.0.0.1')['ok'], $label);
        }
    }

    /**
     * The same window, through the whole stack. A hook flips the flag at the
     * instant claim() takes the registry row's lock — after TableEntry::
     * validate() has passed and before the claim reads it — which a single
     * process can only do from inside the query.
     */
    public function test_the_doors_refuse_cleanly_when_the_table_is_deactivated_between_validation_and_claim(): void
    {
        $branch = $this->freshBranch();
        $url = $this->table($branch);

        $this->flipOnClaimLock($url->id);

        $this->on('qr-landing');
        app('cache')->store()->flush();
        $this->scanQr($url)->assertRedirect(route('customer.dineinqr'));

        $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, session('error'));
        $this->assertNull(session('table_number'));
        $this->assertFalse($url->fresh()->is_active, 'the hook did not run');
        $this->assertNull($this->live($url));

        // The typed-code door (startDineIn).
        $typed = $this->table($branch);
        $this->flipOnClaimLock($typed->id);

        $this->on('typed-door');
        app('cache')->store()->flush();
        $this->from('/customer/dineinqr')->post('/customer/dineinqr', ['table_code' => $typed->code, 'next' => 'guest'])
            ->assertRedirect('/customer/dineinqr');
        $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, session('error'));
        $this->assertNull($this->live($typed));
    }

    /** A seated customer confirming a move to a table that went out of service in that instant keeps their own seat. */
    public function test_a_confirmed_table_change_into_a_table_that_just_went_out_of_service_keeps_the_seat(): void
    {
        $branch = $this->freshBranch();
        [$here, $there] = [$this->table($branch), $this->table($branch)];

        $this->on('phone');
        app('cache')->store()->flush();
        $this->scanQr($here)->assertOk();

        // The QR door at a seated device asks before moving.
        $this->scanQr($there)->assertRedirect(route('customer.menu'));
        $this->assertNotNull(TableChange::pending(), 'the move was not staged');

        $this->flipOnClaimLock($there->id);

        $this->from('/customer/menu')->post('/customer/table/change/confirm')
            ->assertRedirect(route('customer.menu'));

        $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, session('table_change_error'));
        $this->assertSame($here->table_number, session('table_number'), 'the customer lost their table');
        $this->assertNotNull($this->live($here), 'the old seat was released');
        $this->assertNull($this->live($there));
    }

    /** TableChange::move() hands a refused claim straight back instead of reading a session that is not there. */
    public function test_a_refused_claim_is_returned_by_table_change_move_not_thrown(): void
    {
        $branch = $this->freshBranch();
        [$here, $there] = [$this->table($branch), $this->table($branch)];

        $this->on('phone');
        app('cache')->store()->flush();
        $this->scanQr($here)->assertOk();
        $this->deactivate($this->owner(), $there->id)->assertOk();

        $this->on('phone');
        $result = TableChange::move($branch, $there->table_number, '127.0.0.1');

        $this->assertFalse($result['ok']);
        $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, $result['error']);
        $this->assertArrayNotHasKey('session', $result);
        $this->assertNotNull($this->live($here), 'the old seat was released');
    }

    /**
     * Deactivate the table the instant claim() locks its registry row — i.e.
     * after validation, before the claim reads the flag. Fires once.
     */
    private function flipOnClaimLock(int $tableId): void
    {
        $done = false;

        DB::connection()->beforeExecuting(function ($query) use ($tableId, &$done) {
            if ($done || !preg_match('/from `restaurant_tables`.*for update/is', $query)) {
                return;
            }

            $done = true;
            DB::table('restaurant_tables')->where('id', $tableId)->update(['is_active' => 0]);
        });
    }
}

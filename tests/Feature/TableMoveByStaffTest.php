<?php

namespace Tests\Feature;

use App\Http\Middleware\FollowStaffTableMove;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use App\Providers\RateLimitServiceProvider;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Move table" on the staff Occupied Tables panel — a seated party moved to a
 * free table at the same branch. See TableOccupancy::moveSession() and
 * TableOccupancy::followSessionTable().
 *
 * Branch-agnostic on purpose: no branch id, table id or table number is named
 * anywhere. The matrices run over every active branch in the database plus one
 * created inside the test, and every table is registered here with a random
 * seven-digit number (fixed length, so one can never be a prefix of another in
 * an assertSee()).
 *
 * Devices: the test client keeps ONE session store, so on($device) parks the
 * current device's session and restores the next one's — the same helper as
 * DineInChangeTableTest. Staff calls run as their own "device" too.
 */
class TableMoveByStaffTest extends TestCase
{
    use DatabaseTransactions;

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

        // Every successful move writes an audit line. Keep the test runs' out
        // of storage/logs; the real path is asserted in its own test.
        $this->auditLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'table-moves-test-' . Str::random(10) . '.log';
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
            'name'           => 'Move Table ' . Str::random(6),
            'code'           => 'MT' . strtoupper(Str::random(6)),
            'address'        => 'Created by TableMoveByStaffTest',
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
            'name'              => 'MoveTable ' . ucfirst($role),
            'email'             => 'movetable-' . $role . '-' . Str::lower(Str::random(12)) . '@example.test',
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

    /** @return array<string, User> one actor per portal role, each able to see $branch */
    private function actorsFor(Branch $branch): array
    {
        return [
            'owner'      => $this->owner(),
            'supervisor' => $this->portalUser('supervisor', $branch->id),
            'staff'      => $this->portalUser('staff', $branch->id),
        ];
    }

    /** Switch to another phone (or staff screen). Each name keeps its own session. */
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
        $this->scanQr($table)->assertOk();

        $session = $this->live($table);
        $this->assertNotNull($session, "{$device} was not seated at Table {$table->table_number}");

        return $session;
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

    /**
     * The matrices make more staff calls than one minute's per-address budget
     * allows; the budget itself is asserted on its own (see the limiter test).
     */
    private function asStaff(User $actor)
    {
        app('cache')->store()->flush();
        $this->on('staff-' . $actor->id);

        return $this->actingAs($actor, 'admin');
    }

    private function move(User $actor, int $sessionId, $tableId, array $extra = [])
    {
        return $this->asStaff($actor)->postJson('/admin/tables/move', array_merge([
            'session_id' => $sessionId,
            'table_id'   => $tableId,
        ], $extra));
    }

    private function targets(User $actor, int $sessionId)
    {
        return $this->asStaff($actor)->getJson('/admin/tables/move-targets?session_id=' . $sessionId);
    }

    private function openOrderAt(RestaurantTable $table, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'MT-' . strtoupper(Str::random(8)),
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
            'item_name'       => 'MT Ingredient ' . Str::random(6),
            'item_code'       => 'MT-' . strtoupper(Str::random(8)),
            'quantity'        => 50,
            'unit'            => 'pc',
            'low_stock_alert' => 1,
            'unit_cost'       => 1,
            'is_active'       => true,
        ]);

        $item = MenuItem::create([
            'category_id'   => Category::where('is_active', true)->value('id') ?? Category::value('id'),
            'branch_id'     => $branch->id,
            'name'          => 'MT Item ' . Str::random(6),
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

    private function pill(RestaurantTable $table): string
    {
        return 'Dine-in • Table ' . $table->table_number . ' •';
    }

    // ══════════ the move itself, every branch, every role ══════════

    /**
     * Owner, supervisor and staff each move a party on every branch: the same
     * session row (same token) is now at the new table, the old table is free,
     * and the phone shows the new table on its next page with its cart intact.
     */
    public function test_every_role_moves_a_party_to_a_free_table_on_every_branch(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            foreach ($this->actorsFor($branch) as $role => $actor) {
                $label = "{$role} on branch #{$branch->id}";
                [$from, $to] = [$this->table($branch), $this->table($branch)];
                $phone = "phone-{$role}-{$branch->id}";

                $session = $this->seat($phone, $from);
                $item = $this->orderableItem($branch);
                session()->put('cart', $this->cartLine($item, 2));

                $this->move($actor, $session->id, $to->id)
                    ->assertOk()
                    ->assertJson([
                        'message'    => "Moved Table {$from->table_number} to Table {$to->table_number}.",
                        'session_id' => $session->id,
                        'from'       => $from->table_number,
                        'to'         => $to->table_number,
                    ]);

                $this->assertNull($this->live($from), "{$label}: the old table must be free");

                $moved = $this->live($to);
                $this->assertNotNull($moved, "{$label}: the new table must be occupied");
                $this->assertSame($session->id, $moved->id, "{$label}: the same occupancy moved, not a new one");
                $this->assertSame($session->session_token, $moved->session_token, $label);
                $this->assertSame((int) $branch->id, (int) $moved->branch_id, $label);
                $this->assertNull($moved->released_at, $label);

                $this->on($phone);
                $this->get('/customer/menu')->assertOk()
                    ->assertSee($this->pill($to), false)
                    ->assertDontSee($this->pill($from), false);

                $this->assertSame($to->table_number, session('table_number'), $label);
                $this->assertSame((int) $branch->id, (int) session('branch_id'), $label);
                $this->assertSame($moved->session_token, session(TableOccupancy::SESSION_KEY), $label);
                $this->assertSame(2, (int) collect(session('cart'))->sum('quantity'), "{$label}: the cart stays");
            }
        }
    }

    /**
     * Open orders of the visit move; completed and cancelled ones keep the
     * table they were served at; a previous party's order and an order at
     * another table are not touched.
     */
    public function test_open_orders_move_and_finished_orders_keep_their_table(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            [$from, $to, $elsewhere] = [$this->table($branch), $this->table($branch), $this->table($branch)];

            $session = $this->seat("phone-{$branch->id}", $from);

            $preparing = $this->openOrderAt($from, ['status' => 'preparing']);
            TableOccupancy::attachOrder($preparing);
            $pending = $this->openOrderAt($from, ['status' => 'pending']);
            $completed = $this->openOrderAt($from, ['status' => 'completed']);
            $cancelled = $this->openOrderAt($from, ['status' => 'cancelled']);

            $previousParty = $this->openOrderAt($from, ['status' => 'preparing']);
            Order::whereKey($previousParty->id)->update(['created_at' => now()->subHours(3)]);

            $otherTable = $this->openOrderAt($elsewhere, ['status' => 'preparing']);

            $response = $this->move($this->owner(), $session->id, $to->id)->assertOk();

            $this->assertEqualsCanonicalizing([$preparing->id, $pending->id], $response->json('order_ids'), $label);
            $this->assertStringContainsString("Its 2 open orders now show Table {$to->table_number}.", $response->json('message'), $label);

            $this->assertSame($to->table_number, $preparing->fresh()->table_number, "{$label}: open order moves");
            $this->assertSame($to->table_number, $pending->fresh()->table_number, "{$label}: open order moves");
            $this->assertSame($from->table_number, $completed->fresh()->table_number, "{$label}: completed keeps its table");
            $this->assertSame($from->table_number, $cancelled->fresh()->table_number, "{$label}: cancelled keeps its table");
            $this->assertSame($from->table_number, $previousParty->fresh()->table_number, "{$label}: not this visit's order");
            $this->assertSame($elsewhere->table_number, $otherTable->fresh()->table_number, $label);

            $this->assertSame('preparing', $preparing->fresh()->status, "{$label}: status is never touched");
            $this->assertSame($preparing->id, $this->live($to)->order_id, "{$label}: the link to the order moves with the row");
        }
    }

    // ══════════ refusals ══════════

    /**
     * An occupied destination is refused up front — before any write is even
     * attempted — and nothing changes. Tables are never merged.
     */
    public function test_an_occupied_destination_is_refused_and_nothing_is_written(): void
    {
        $watch = null;
        $writes = 0;

        // beforeExecuting, not DB::listen: a write the unique index rejects
        // throws before any QueryExecuted event, so a listener would miss
        // exactly the attempt this test exists to rule out.
        DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$watch, &$writes) {
            if ($watch !== null
                && str_starts_with($sql, 'update `table_sessions`')
                && in_array($watch, $bindings, true)) {
                $writes++;
            }
        });

        foreach ($this->branchesUnderTest() as $branch) {
            [$from, $busy] = [$this->table($branch), $this->table($branch)];

            $session = $this->seat("phone-{$branch->id}", $from);
            $other = $this->seat("other-{$branch->id}", $busy);
            $order = $this->openOrderAt($from);

            foreach ($this->actorsFor($branch) as $role => $actor) {
                $label = "{$role} on branch #{$branch->id}";
                $watch = $branch->id . ':' . $busy->table_number;
                $writes = 0;

                $this->move($actor, $session->id, $busy->id)
                    ->assertStatus(409)
                    ->assertJson(['message' => "Table {$busy->table_number} was just taken. Please pick another table."]);

                $this->assertSame(0, $writes, "{$label}: a write was attempted against an occupied table");
                $this->assertSame($session->id, $this->live($from)?->id, $label);
                $this->assertSame($other->id, $this->live($busy)?->id, "{$label}: never merged");
                $this->assertSame($from->table_number, $order->fresh()->table_number, $label);
            }

            $watch = null;
        }
    }

    /**
     * Every ordered pair of branches, existing and fresh, every role that can
     * see the source: a destination at the other branch is refused — the
     * Owner included — and posting that branch's id changes nothing.
     */
    public function test_another_branchs_table_is_refused_in_every_direction(): void
    {
        $branches = $this->branchesUnderTest();

        foreach ($branches as $a) {
            foreach ($branches as $b) {
                if ($a->id === $b->id) {
                    continue;
                }

                $pair = "#{$a->id} -> #{$b->id}";
                [$from, $foreign] = [$this->table($a), $this->table($b)];
                $session = $this->seat("phone-{$a->id}-{$b->id}", $from);
                $order = $this->openOrderAt($from);

                foreach ($this->actorsFor($a) as $role => $actor) {
                    $this->move($actor, $session->id, $foreign->id)
                        ->assertNotFound()
                        ->assertJson(['message' => TableOccupancy::ERR_MOVE_NO_TABLE]);

                    $this->move($actor, $session->id, $foreign->id, ['branch_id' => $b->id])->assertNotFound();

                    $offered = $this->targets($actor, $session->id)->assertOk()->json('tables.*.id');
                    $this->assertNotContains($foreign->id, $offered, "{$role} {$pair}: offered another branch's table");
                }

                $this->assertSame($session->id, $this->live($from)?->id, $pair);
                $this->assertNull($this->live($foreign), $pair);
                $this->assertSame($from->table_number, $order->fresh()->table_number, $pair);
            }
        }
    }

    /**
     * Staff and supervisors reach only their own branch's tables: another
     * branch's occupancy is a 404, exactly like a missing id, for both the
     * list and the move. A branchless account reaches nothing — no fallback
     * to Main. The Owner, who can see every branch, moves it within its own.
     */
    public function test_each_role_is_limited_to_its_branch_and_refused_with_404_outside_it(): void
    {
        $branches = $this->branchesUnderTest();

        foreach ($branches as $home) {
            foreach ($branches as $there) {
                if ($home->id === $there->id) {
                    continue;
                }

                $pair = "home #{$home->id}, table at #{$there->id}";
                [$from, $to] = [$this->table($there), $this->table($there)];
                $session = $this->seat("phone-{$home->id}-{$there->id}", $from);

                foreach (['staff', 'supervisor'] as $role) {
                    $outsider = $this->portalUser($role, $home->id);

                    $this->move($outsider, $session->id, $to->id)->assertNotFound();
                    $this->targets($outsider, $session->id)->assertNotFound();
                }

                $this->assertSame($session->id, $this->live($from)?->id, "{$pair}: an outsider moved it");
                $this->assertNull($this->live($to), $pair);
            }
        }

        $branch = $branches->last();
        [$from, $to] = [$this->table($branch), $this->table($branch)];
        $session = $this->seat('phone-branchless', $from);

        foreach (['staff', 'supervisor'] as $role) {
            $branchless = $this->portalUser($role, null);
            $this->move($branchless, $session->id, $to->id)->assertNotFound();
            $this->targets($branchless, $session->id)->assertNotFound();
        }

        $this->assertSame($session->id, $this->live($from)?->id);

        $this->move($this->owner(), $session->id, $to->id)->assertOk();
        $this->assertSame($session->id, $this->live($to)?->id);
    }

    /** A bare, missing, forged or otherwise wrong destination is refused, and nothing moves. */
    public function test_bare_forged_and_invalid_destinations_are_refused(): void
    {
        $branch = $this->freshBranch();
        $elsewhere = $this->freshBranch();
        [$from, $to, $inactive] = [$this->table($branch), $this->table($branch), $this->table($branch)];
        $foreign = $this->table($elsewhere);
        $inactive->update(['is_active' => false]);

        $session = $this->seat('phone', $from);
        $owner = $this->owner();

        // A forged id: a real table, another branch's.
        $this->move($owner, $session->id, $foreign->id)->assertNotFound()
            ->assertJson(['message' => TableOccupancy::ERR_MOVE_NO_TABLE]);

        // An id that does not exist.
        $this->move($owner, $session->id, (int) RestaurantTable::max('id') + 1000)->assertNotFound();

        // A bare table number instead of an id; a non-integer id; nothing at all.
        $this->asStaff($owner)->postJson('/admin/tables/move', [
            'session_id'   => $session->id,
            'table_number' => $to->table_number,
            'branch_id'    => $branch->id,
        ])->assertStatus(422)->assertJsonValidationErrors('table_id');

        $this->move($owner, $session->id, 'abc')->assertStatus(422)->assertJsonValidationErrors('table_id');
        $this->asStaff($owner)->postJson('/admin/tables/move', [])->assertStatus(422)
            ->assertJsonValidationErrors(['session_id', 'table_id']);

        // The table it is already at; a table taken out of service.
        $this->move($owner, $session->id, $from->id)->assertStatus(409)
            ->assertJson(['message' => "This customer is already at Table {$from->table_number}."]);

        $this->move($owner, $session->id, $inactive->id)->assertStatus(422)
            ->assertJson(['message' => "Table {$inactive->table_number} is not in service. Please pick another table."]);

        $this->assertSame($session->id, $this->live($from)?->id);
        $this->assertNull($this->live($to));
        $this->assertNull($this->live($inactive));
        $this->assertNull($this->live($foreign));

        // A session that has already ended cannot be moved.
        TableOccupancy::releaseTable($branch->id, $from->table_number, $owner->id);

        $this->move($owner, $session->id, $to->id)->assertStatus(409)
            ->assertJson(['message' => "Table {$from->table_number} no longer has an active session. Please check the list."]);
        $this->targets($owner, $session->id)->assertStatus(409);
        $this->assertNull($this->live($to));
    }

    // ══════════ the dialog's list ══════════

    /**
     * The dialog offers exactly the free, in-service tables of the party's own
     * branch — for every role, the Owner included.
     */
    public function test_the_dialog_lists_only_free_in_service_tables_of_the_same_branch(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $elsewhere = $this->freshBranch();
            [$from, $free1, $free2, $busy, $inactive] = [
                $this->table($branch), $this->table($branch), $this->table($branch),
                $this->table($branch), $this->table($branch),
            ];
            $foreign = $this->table($elsewhere);
            $inactive->update(['is_active' => false]);

            $session = $this->seat("phone-{$branch->id}", $from);
            $this->seat("other-{$branch->id}", $busy);

            foreach ($this->actorsFor($branch) as $role => $actor) {
                $label = "{$role} on branch #{$branch->id}";
                $response = $this->targets($actor, $session->id)->assertOk()
                    ->assertJson(['session_id' => $session->id, 'table_number' => $from->table_number]);

                $offered = $response->json('tables.*.id');

                $this->assertContains($free1->id, $offered, $label);
                $this->assertContains($free2->id, $offered, $label);
                $this->assertNotContains($from->id, $offered, "{$label}: offered its own table");
                $this->assertNotContains($busy->id, $offered, "{$label}: offered an occupied table");
                $this->assertNotContains($inactive->id, $offered, "{$label}: offered a table out of service");
                $this->assertNotContains($foreign->id, $offered, "{$label}: offered another branch's table");

                foreach (RestaurantTable::whereIn('id', $offered)->get() as $t) {
                    $this->assertSame((int) $branch->id, (int) $t->branch_id, $label);
                    $this->assertTrue($t->is_active, $label);
                    $this->assertNull($this->live($t), $label);
                }
            }
        }
    }

    // ══════════ the customer side ══════════

    /**
     * Three phones share the table; all three are at the new table after one
     * move — same token, same device count — and each sees it on its next page.
     */
    public function test_every_phone_at_the_table_moves_together(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            [$from, $to] = [$this->table($branch), $this->table($branch)];
            $phones = ["a-{$branch->id}", "b-{$branch->id}", "c-{$branch->id}"];

            $session = $this->seat($phones[0], $from);
            $this->seat($phones[1], $from);
            $this->seat($phones[2], $from);
            $this->assertSame(3, $this->devices($from), $label);

            $this->move($this->portalUser('staff', $branch->id), $session->id, $to->id)->assertOk();

            $this->assertSame(0, $this->devices($from), $label);
            $this->assertSame(3, $this->devices($to), "{$label}: the devices moved with the session");

            foreach ($phones as $phone) {
                $this->on($phone);
                $this->get('/customer/menu')->assertOk()->assertSee($this->pill($to), false);

                $this->assertSame($to->table_number, session('table_number'), "{$label}: {$phone}");
                $this->assertSame($session->session_token, session(TableOccupancy::SESSION_KEY), "{$label}: {$phone}");
                $this->assertSame($session->id, TableOccupancy::currentGuestSession()?->id, "{$label}: {$phone}");
                $this->assertSame(['valid' => true], TableOccupancy::inspectGuestSession(), "{$label}: {$phone}");
            }
        }
    }

    /**
     * The phone's next page shows the new table — menu and cart — with a
     * one-time note saying staff moved it.
     */
    public function test_the_next_page_shows_the_new_table_with_a_one_time_note(): void
    {
        $branch = $this->freshBranch();
        [$from, $to] = [$this->table($branch), $this->table($branch)];
        $item = $this->orderableItem($branch);

        $session = $this->seat('phone', $from);
        session()->put('cart', $this->cartLine($item, 3));

        $this->move($this->owner(), $session->id, $to->id)->assertOk();

        $this->on('phone');
        $this->get('/customer/menu')->assertOk()
            ->assertSee($this->pill($to), false)
            ->assertSee('id="tableChangeNotice"', false)
            ->assertSee("Our staff moved your party to Table {$to->table_number}.", false)
            ->assertSee('Your cart is still here', false);

        $this->get('/customer/menu')->assertOk()
            ->assertSee($this->pill($to), false)
            ->assertDontSee('id="tableChangeNotice"', false);

        $this->get('/customer/cart')->assertOk()
            ->assertSee('• Table ' . $to->table_number, false)
            ->assertSee('name="table_number" value="' . $to->table_number . '"', false)
            ->assertDontSee('• Table ' . $from->table_number, false);
    }

    /**
     * A tab rendered before the move still posts the OLD table number. The
     * order is placed at the NEW table, at the same branch, and linked to the
     * moved occupancy — on every branch.
     */
    public function test_a_stale_tab_posting_the_old_table_still_orders_at_the_new_one(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            [$from, $to] = [$this->table($branch), $this->table($branch)];
            $item = $this->orderableItem($branch);

            $session = $this->seat("phone-{$branch->id}", $from);
            session()->put('cart', $this->cartLine($item, 2));

            $this->move($this->owner(), $session->id, $to->id)->assertOk();

            // No page load in between: the stale tab posts straight away.
            $this->on("phone-{$branch->id}");
            $before = (int) Order::max('id');

            $this->post('/customer/place-order', [
                'order_type'     => 'dine_in',
                'table_number'   => $from->table_number,
                'branch_id'      => $branch->id,
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 2]],
            ]);

            $order = Order::where('id', '>', $before)->latest('id')->first();

            $this->assertNotNull($order, "{$label}: the order was not placed");
            $this->assertSame($to->table_number, $order->table_number, "{$label}: ordered at the OLD table");
            $this->assertSame((int) $branch->id, (int) $order->branch_id, $label);
            $this->assertSame($order->id, $this->live($to)?->order_id, "{$label}: the new table holds the order");
            $this->assertNull($this->live($from), "{$label}: the old table was re-occupied");
        }
    }

    /** Pick-Up has no table, and a phone that switched to Pick-Up is not pulled back. */
    public function test_pick_up_is_unaffected(): void
    {
        $branch = $this->freshBranch();
        [$from, $to] = [$this->table($branch), $this->table($branch)];

        $session = $this->seat('dine-in', $from);

        // Seated at the same table, then switched to Pick-Up — the old table
        // token is still in its session.
        $this->seat('switched', $from);
        $this->post('/customer/select-branch', ['branch_id' => $branch->id]);
        $this->assertSame('pick_up', session('order_type'));

        $this->on('pickup');
        $this->post('/customer/select-branch', ['branch_id' => $branch->id]);

        $this->move($this->owner(), $session->id, $to->id)->assertOk();

        foreach (['switched', 'pickup'] as $device) {
            $this->on($device);
            $this->get('/customer/menu')->assertOk()
                ->assertDontSee('Dine-in • Table', false)
                ->assertDontSee('id="tableChangeNotice"', false);

            $this->assertSame('pick_up', session('order_type'), $device);
            $this->assertNull(session('table_number'), $device);
            $this->assertNull(session(FollowStaffTableMove::NOTICE_KEY), $device);
            $this->assertNull(TableOccupancy::followSessionTable(), $device);
        }
    }

    /** The menu and cart no longer offer the customer a "Change table" control, on any branch. */
    public function test_the_menu_and_cart_no_longer_render_a_change_table_trigger(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $from = $this->table($branch);
            $item = $this->orderableItem($branch);

            $this->seat("phone-{$branch->id}", $from);
            session()->put('cart', $this->cartLine($item));

            foreach (['/customer/menu', '/customer/cart'] as $page) {
                $this->get($page)->assertOk()
                    ->assertSee('Table ' . $from->table_number, false)
                    ->assertDontSee('Change table', false)
                    ->assertDontSee('data-table-change-open', false)
                    ->assertDontSee('id="tableChangeModal"', false)
                    ->assertDontSee('name="table_code"', false)
                    ->assertDontSee('action="' . route('customer.table-change') . '"', false);
            }
        }
    }

    // ══════════ double submits and races ══════════

    /**
     * Pressing Move twice moves once; the second is told where the party
     * already is. A second staff member's stale dialog is re-checked from
     * where the party is NOW, and the answer names that table.
     */
    public function test_a_double_submit_moves_once_and_leaves_a_consistent_state(): void
    {
        $branch = $this->freshBranch();
        [$from, $to, $later] = [$this->table($branch), $this->table($branch), $this->table($branch)];

        $session = $this->seat('phone', $from);
        $order = $this->openOrderAt($from);
        $owner = $this->owner();

        $this->move($owner, $session->id, $to->id)->assertOk();
        $this->move($owner, $session->id, $to->id)->assertStatus(409)
            ->assertJson(['message' => "This customer is already at Table {$to->table_number}."]);

        $this->assertSame(1, TableSession::where('active_lock', $branch->id . ':' . $to->table_number)->count());
        $this->assertSame(0, TableSession::where('active_lock', $branch->id . ':' . $from->table_number)->count());
        $this->assertSame($to->table_number, $order->fresh()->table_number);

        $this->move($this->portalUser('staff', $branch->id), $session->id, $later->id)->assertOk()
            ->assertJson(['message' => "Moved Table {$to->table_number} to Table {$later->table_number}. Its open order now shows Table {$later->table_number}."]);

        $this->assertNull($this->live($from));
        $this->assertNull($this->live($to));
        $this->assertSame($session->id, $this->live($later)?->id);
        $this->assertSame($later->table_number, $order->fresh()->table_number);
        $this->assertSame(1, TableSession::where('branch_id', $branch->id)->whereNotNull('active_lock')->count());
    }

    /**
     * A customer scans the destination in the instant between the free check
     * and the write. The unique index refuses the write; the move is reported
     * as "just taken" and nothing of it is kept.
     */
    public function test_a_destination_taken_mid_move_is_refused_and_nothing_changes(): void
    {
        $branch = $this->freshBranch();
        [$from, $to] = [$this->table($branch), $this->table($branch)];

        $session = $this->seat('phone', $from);
        $order = $this->openOrderAt($from);
        $key = $branch->id . ':' . $to->table_number;
        $fired = false;

        DB::listen(function ($query) use (&$fired, $key, $branch, $to) {
            if ($fired
                || !str_contains($query->sql, 'from `table_sessions` where `active_lock` = ?')
                || ($query->bindings[0] ?? null) !== $key) {
                return;
            }

            $fired = true;

            TableSession::create([
                'branch_id'        => $branch->id,
                'table_number'     => $to->table_number,
                'session_token'    => (string) Str::uuid() . Str::random(24),
                'active_lock'      => $key,
                'last_seen_at'     => now(),
                'last_activity_at' => now(),
            ]);
        });

        $this->move($this->owner(), $session->id, $to->id)
            ->assertStatus(409)
            ->assertJson(['message' => "Table {$to->table_number} was just taken. Please pick another table."]);

        $this->assertTrue($fired, 'the race was never staged — the test proves nothing');
        $this->assertSame($session->id, $this->live($from)?->id, 'the party left its table');
        $this->assertNotSame($session->id, $this->live($to)?->id, 'the tables were merged');
        $this->assertSame($from->table_number, $order->fresh()->table_number);
        $this->assertFileDoesNotExist($this->auditLog);
    }

    /**
     * Another staff member moved the same party between this move choosing its
     * orders and locking the occupancy. Re-checked under the lock: refused,
     * and this move writes nothing.
     */
    public function test_a_party_moved_by_someone_else_mid_move_is_refused(): void
    {
        $branch = $this->freshBranch();
        [$from, $to, $meanwhile] = [$this->table($branch), $this->table($branch), $this->table($branch)];

        $session = $this->seat('phone', $from);
        $order = $this->openOrderAt($from);
        $fired = false;

        DB::listen(function ($query) use (&$fired, $session, $branch, $meanwhile) {
            if ($fired || !str_contains($query->sql, 'from `orders`') || !str_contains($query->sql, 'for update')) {
                return;
            }

            $fired = true;

            TableSession::whereKey($session->id)->update([
                'table_number' => $meanwhile->table_number,
                'active_lock'  => $branch->id . ':' . $meanwhile->table_number,
            ]);
        });

        $this->move($this->owner(), $session->id, $to->id)
            ->assertStatus(409)
            ->assertJson(['message' => "Table {$from->table_number} was just changed by someone else. Please check the list and try again."]);

        $this->assertTrue($fired, 'the race was never staged — the test proves nothing');
        $this->assertNull($this->live($to));
        $this->assertSame($session->id, $this->live($meanwhile)?->id);
        $this->assertSame($from->table_number, $order->fresh()->table_number, 'this move must write nothing');
    }

    /**
     * A failure after the occupancy row was written: the whole move rolls
     * back, the answer is a plain sentence (no SQL), and no audit line claims
     * a move that did not happen.
     */
    public function test_a_failure_part_way_rolls_the_whole_move_back(): void
    {
        config(['logging.default' => 'null']);

        $branch = $this->freshBranch();
        [$from, $to] = [$this->table($branch), $this->table($branch)];

        $session = $this->seat('phone', $from);
        $order = $this->openOrderAt($from);

        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'update `orders` set `table_number`')) {
                throw new \RuntimeException('TableMoveByStaffTest: injected failure after the occupancy row was written');
            }
        });

        $response = $this->move($this->owner(), $session->id, $to->id)
            ->assertStatus(500)
            ->assertExactJson(['message' => TableOccupancy::ERR_MOVE_FAILED]);

        $this->assertStringNotContainsString('injected', $response->getContent());
        $this->assertStringNotContainsString('update `', $response->getContent());

        $this->assertSame($session->id, $this->live($from)?->id, 'the occupancy move was not rolled back');
        $this->assertNull($this->live($to));
        $this->assertSame($from->table_number, $order->fresh()->table_number);
        $this->assertFileDoesNotExist($this->auditLog);
    }

    // ══════════ the panel and the audit trail ══════════

    /** After a move the panel shows the party at the new table, with its order and phones. */
    public function test_the_occupied_tables_panel_is_consistent_after_a_move(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            [$from, $to] = [$this->table($branch), $this->table($branch)];

            $session = $this->seat("a-{$branch->id}", $from);
            $order = $this->openOrderAt($from);
            TableOccupancy::attachOrder($order);
            $this->seat("b-{$branch->id}", $from);

            $staff = $this->portalUser('staff', $branch->id);
            $this->move($staff, $session->id, $to->id)->assertOk();

            $rows = collect($this->asStaff($staff)->getJson('/admin/tables/occupancy')->assertOk()->json('tables'))
                ->whereIn('table_number', [$from->table_number, $to->table_number])
                ->keyBy('table_number');

            // Numeric table numbers become integer keys under keyBy().
            $this->assertSame([$to->table_number], $rows->keys()->map(fn ($k) => (string) $k)->all(), "{$label}: only the new table is occupied");
            $this->assertSame($session->id, $rows[$to->table_number]['session_id'], $label);
            $this->assertSame((int) $branch->id, (int) $rows[$to->table_number]['branch_id'], $label);
            $this->assertSame($order->order_number, $rows[$to->table_number]['order_number'], $label);
            $this->assertSame(2, $rows[$to->table_number]['device_count'], $label);
        }
    }

    /** Who moved which party from where to where, and when — one line per move, none for a refusal. */
    public function test_each_move_writes_one_audit_record(): void
    {
        $branch = $this->freshBranch();
        [$from, $to, $busy] = [$this->table($branch), $this->table($branch), $this->table($branch)];
        $staff = $this->portalUser('staff', $branch->id);

        $session = $this->seat('phone', $from);
        $this->seat('other', $busy);
        $order = $this->openOrderAt($from);

        $this->move($staff, $session->id, $busy->id)->assertStatus(409);
        $this->assertFileDoesNotExist($this->auditLog, 'a refused move was audited');

        $this->move($staff, $session->id, $to->id)->assertOk();

        $lines = file($this->auditLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);
        $this->assertMatchesRegularExpression('/\.INFO: Table moved (\{.*\})\s*(\[\])?\s*$/', $lines[0]);
        preg_match('/Table moved (\{.*\})/', $lines[0], $m);

        $record = json_decode($m[1], true);
        $this->assertIsArray($record, 'the audit line carries no readable record');

        $this->assertSame($staff->id, $record['moved_by_id']);
        $this->assertSame($staff->name, $record['moved_by_name']);
        $this->assertSame('staff', $record['moved_by_role']);
        $this->assertSame((int) $branch->id, $record['branch_id']);
        $this->assertSame($session->id, $record['session_id']);
        $this->assertSame($from->table_number, $record['from_table']);
        $this->assertSame($to->table_number, $record['to_table']);
        $this->assertSame([$order->id], $record['order_ids']);
        $this->assertLessThan(60, abs(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($record['moved_at']))));

        // Outside the tests the channel writes storage/logs/table-moves.log at
        // info, whatever LOG_LEVEL production runs with.
        $channel = (require config_path('logging.php'))['channels']['table_moves'];
        $this->assertSame(storage_path('logs/table-moves.log'), $channel['path']);
        $this->assertSame('info', $channel['level']);
    }

    /**
     * The audit line is written after the commit. If it cannot be written,
     * the move still happened — the answer must say so, never "Nothing was
     * changed".
     */
    public function test_an_unwritable_audit_log_does_not_misreport_a_completed_move(): void
    {
        config(['logging.default' => 'null']);

        // A directory is not a file the log can open.
        config(['logging.channels.table_moves.path' => sys_get_temp_dir()]);

        $branch = $this->freshBranch();
        [$from, $to] = [$this->table($branch), $this->table($branch)];
        $session = $this->seat('phone', $from);

        $this->move($this->owner(), $session->id, $to->id)->assertOk()
            ->assertJson(['message' => "Moved Table {$from->table_number} to Table {$to->table_number}."]);

        $this->assertSame($session->id, $this->live($to)?->id);
        $this->assertNull($this->live($from));
    }

    // ══════════ routes, limits, CSRF ══════════

    public function test_the_routes_are_role_gated_throttled_and_csrf_protected(): void
    {
        app(\Illuminate\Contracts\Http\Kernel::class);

        $router = app('router');
        $csrf = app(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $inExcept = new \ReflectionMethod($csrf, 'inExceptArray');

        $move = $router->getRoutes()->getByName('admin.tables.move');
        $this->assertSame(['POST'], $move->methods());
        $this->assertContains('throttle:admin-tables-move', $move->gatherMiddleware());
        $this->assertContains('role:admin,staff,supervisor', $move->gatherMiddleware());
        $this->assertContains(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            $router->resolveMiddleware($move->gatherMiddleware(), $move->excludedMiddleware())
        );
        $this->assertFalse($inExcept->invoke($csrf, \Illuminate\Http\Request::create('/' . $move->uri(), 'POST')));

        $targets = $router->getRoutes()->getByName('admin.tables.move-targets');
        $this->assertSame(['GET', 'HEAD'], $targets->methods());
        $this->assertContains('throttle:admin-tables-occupancy', $targets->gatherMiddleware());
        $this->assertContains('role:admin,staff,supervisor', $targets->gatherMiddleware());

        foreach (['customer.menu', 'customer.cart', 'customer.place-order', 'customer.table-session-status'] as $name) {
            $this->assertContains(
                FollowStaffTableMove::class,
                $router->getRoutes()->getByName($name)->gatherMiddleware(),
                "{$name} does not follow a staff move"
            );
        }
    }

    /** CSRF for real: no token is a 419 and nothing moves; this session's own token gets through. */
    public function test_a_move_without_a_csrf_token_is_refused(): void
    {
        $branch = $this->freshBranch();
        [$from, $to] = [$this->table($branch), $this->table($branch)];
        $session = $this->seat('phone', $from);
        $owner = $this->owner();

        // ValidateCsrfToken skips itself under runningUnitTests(); flipping the
        // resolved env is what turns it back on (see CsrfJsonRequestTest).
        $this->app['env'] = 'production';
        $this->assertFalse($this->app->runningUnitTests());

        $this->asStaff($owner)->postJson('/admin/tables/move', ['session_id' => $session->id, 'table_id' => $to->id])
            ->assertStatus(419);
        $this->assertSame($session->id, $this->live($from)?->id);

        $this->asStaff($owner)->get('/admin/qr-generator')->assertOk();
        $this->actingAs($owner, 'admin')->withHeaders(['X-CSRF-TOKEN' => csrf_token()])
            ->postJson('/admin/tables/move', ['session_id' => $session->id, 'table_id' => $to->id])
            ->assertOk();
        $this->assertSame($session->id, $this->live($to)?->id);
    }

    /** The per-address ceiling, on the move's own counter. */
    public function test_the_move_endpoint_is_rate_limited_per_address(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'admin');

        $ip = ['REMOTE_ADDR' => '203.0.113.' . random_int(10, 250)];

        for ($i = 0; $i < RateLimitServiceProvider::ADMIN_TABLES_MOVE_PER_IP; $i++) {
            $this->withServerVariables($ip)->postJson('/admin/tables/move', [])->assertStatus(422);
        }

        $this->withServerVariables($ip)->postJson('/admin/tables/move', [])->assertStatus(429);

        // Its own counter: the panel's poll and Clear are untouched.
        $this->withServerVariables($ip)->getJson('/admin/tables/occupancy')->assertOk();
        $this->withServerVariables($ip)->postJson('/admin/tables/clear', [])->assertStatus(422);
    }

    /** The panel renders the Move table control and its two-step dialog for every role. */
    public function test_the_panel_offers_move_table_to_every_role(): void
    {
        $branch = $this->freshBranch();

        foreach ($this->actorsFor($branch) as $actor) {
            $this->asStaff($actor)->get('/admin/qr-generator')->assertOk()
                ->assertSee('id="moveTableDialog"', false)
                ->assertSee('open-move-btn', false)
                ->assertSee(route('admin.tables.move'), false)
                ->assertSee(route('admin.tables.move-targets'), false)
                ->assertSee('? Their cart and any open order move with them.', false);
        }
    }
}

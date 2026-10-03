<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\HelpRequest;
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
 * Deleting an unused table from the Move table dialog (Oct 2026).
 *
 * The QR & Table Codes page is now the generator and Occupied Tables only. A
 * table registered by mistake (100 typed for 10) is deleted with the trash icon
 * beside it in the Move table dialog's list: POST admin.tables.delete with the
 * dialog's occupancy id and the table id. The branch is read off those rows;
 * a table at any other branch is a 404 for every role, Owner included. Only a
 * table with no history at all is deleted — see
 * TableOccupancy::deleteUnusedTable(). The real two-process claim-vs-delete
 * race is TableDeleteRaceTest.
 *
 * Branch-agnostic: no branch id, table id or table number is named. The
 * per-branch matrices run over every active branch plus one created here, and
 * every table gets a random seven-digit number no branch has used.
 */
class TableDeleteFromMoveDialogTest extends TestCase
{
    use DatabaseTransactions;

    private const DELETE = '/admin/tables/delete';

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

        // Every delete writes an audit line; keep this run's out of storage/logs.
        $this->auditLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'table-delete-test-' . Str::random(10) . '.log';
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
            'name'           => 'Table Delete ' . Str::random(6),
            'code'           => 'TD' . strtoupper(Str::random(6)),
            'address'        => 'Created by TableDeleteFromMoveDialogTest',
            'is_active'      => true,
            'is_main_branch' => false,
        ]);
    }

    /** Every active branch, plus one created now with no history at all. */
    private function branchesUnderTest()
    {
        return Branch::where('is_active', true)->orderBy('id')->get()->push($this->freshBranch());
    }

    /** A number no branch has ever used anywhere, so no stray history can attach to it. */
    private function unusedNumber(): string
    {
        do {
            $number = (string) random_int(1000000, 9999999);
        } while (
            RestaurantTable::where('table_number', $number)->exists()
            || TableSession::where('table_number', $number)->exists()
            || Order::where('table_number', $number)->exists()
            || DB::table('help_requests')->where('table_number', $number)->exists()
            || DB::table('table_access_codes')->where('table_number', $number)->exists()
        );

        return $number;
    }

    /** A registered table exactly as pressing Generate makes one: fresh code, no history. */
    private function table(Branch $branch): RestaurantTable
    {
        return TableEntry::findOrRegister($branch->id, $this->unusedNumber());
    }

    private function portalUser(string $role, ?int $branchId): User
    {
        return User::create([
            'name'              => 'TableDelete ' . ucfirst($role),
            'email'             => 'tabledelete-' . $role . '-' . Str::lower(Str::random(12)) . '@example.test',
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

    private function asAdmin(User $actor)
    {
        app('cache')->store()->flush();
        $this->on('admin-' . $actor->id);

        return $this->actingAs($actor, 'admin');
    }

    private function orderAt(RestaurantTable $table, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TD-' . strtoupper(Str::random(8)),
            'user_id'      => null,
            'branch_id'    => $table->branch_id,
            'type'         => 'dine_in',
            'table_number' => $table->table_number,
            'status'       => 'preparing',
            'subtotal'     => 100,
            'total'        => 100,
        ], $attrs));
    }

    /** A party seated from the counter: the occupancy the Move dialog is opened from. */
    private function occupancy(Branch $branch, ?User $by = null): TableSession
    {
        $table = $this->table($branch);
        TableOccupancy::attachStaffOrder($this->orderAt($table), ($by ?? $this->owner())->id);

        $session = TableOccupancy::activeFor($branch->id, $table->table_number);
        $this->assertNotNull($session, 'the source occupancy was not opened');

        return $session;
    }

    /** What the trash icon's Delete posts. */
    private function deleteTable(User $actor, int $sessionId, int $tableId, array $extra = [])
    {
        return $this->asAdmin($actor)->postJson(self::DELETE, ['session_id' => $sessionId, 'table_id' => $tableId] + $extra);
    }

    private function moveTargetIds(User $actor, int $sessionId): array
    {
        return collect($this->asAdmin($actor)->getJson('/admin/tables/move-targets?session_id=' . $sessionId)->assertOk()->json('tables'))
            ->pluck('id')->all();
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

    private function page(User $actor): string
    {
        return $this->asAdmin($actor)->get('/admin/qr-generator')->assertOk()->getContent();
    }

    /** The source of one named function in the page's script (they close at four-space indent). */
    private function scriptFunction(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('/    (?:async )?function ' . $name . '\(.*?\n    \}\n/s', $html, $m), "function {$name} not found");

        return $m[0];
    }

    /** The page's own markup and script, without the shared admin layout around them. */
    private function pageOwn(string $html): string
    {
        $start = strpos($html, '<p class="page-title">QR Code Generator</p>');
        $this->assertNotFalse($start);
        $end = strpos($html, '</dialog>', (int) strpos($html, '<dialog id="moveTableDialog"'));
        $this->assertNotFalse($end);

        $scriptStart = strpos($html, '<script src="/vendor/qrcode.min.js"></script>');
        $this->assertNotFalse($scriptStart);
        $inline = strpos($html, '<script>', $scriptStart);
        $scriptEnd = strpos($html, '</script>', $inline);

        return substr($html, $start, $end - $start) . substr($html, $inline, $scriptEnd - $inline);
    }

    // ══════════ who may delete ══════════

    /** The Owner deletes a free, never-used table at every branch: the row is gone, and one audit line names who, where and what. */
    public function test_the_owner_can_delete_a_free_unused_table(): void
    {
        $owner = $this->owner();
        $branches = $this->branchesUnderTest();

        foreach ($branches as $branch) {
            $label = "branch #{$branch->id}";
            $source = $this->occupancy($branch);
            $typo = $this->table($branch);

            $this->deleteTable($owner, $source->id, $typo->id)
                ->assertOk()
                ->assertJson([
                    'table_id'     => $typo->id,
                    'table_number' => $typo->table_number,
                    'message'      => "Table {$typo->table_number} at {$branch->name} was deleted.",
                ]);

            $this->assertNull(RestaurantTable::find($typo->id), "{$label}: the row is still there");
            $this->assertNotNull(TableOccupancy::activeFor($branch->id, $source->table_number), "{$label}: the party was disturbed");
        }

        $records = $this->auditRecords();
        $this->assertCount($branches->count(), $records);

        foreach ($records as $record) {
            $this->assertSame('Table deleted', $record['message']);
            $this->assertSame('table_deleted', $record['action']);
            $this->assertSame($owner->id, $record['acted_by_id']);
            $this->assertSame($owner->name, $record['acted_by_name']);
            $this->assertSame('admin', $record['acted_by_role']);
            $this->assertArrayHasKey('branch_id', $record);
            $this->assertArrayHasKey('table_number', $record);
            $this->assertArrayNotHasKey('code', $record, 'the deleted code must not be logged');
        }
    }

    /** A supervisor deletes a free, never-used table at their own branch, and the audit line names them as supervisor. */
    public function test_a_supervisor_can_delete_a_free_unused_table_at_their_branch(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $supervisor = $this->portalUser('supervisor', $branch->id);
            $source = $this->occupancy($branch);
            $typo = $this->table($branch);

            $this->deleteTable($supervisor, $source->id, $typo->id)->assertOk()->assertJson(['table_id' => $typo->id]);
            $this->assertNull(RestaurantTable::find($typo->id), $label);

            $last = collect($this->auditRecords())->last();
            $this->assertSame('table_deleted', $last['action'], $label);
            $this->assertSame($supervisor->id, $last['acted_by_id'], $label);
            $this->assertSame('supervisor', $last['acted_by_role'], $label);
            $this->assertSame($branch->id, $last['branch_id'], $label);
            $this->assertSame($typo->table_number, $last['table_number'], $label);
        }
    }

    /** Staff are bounced by the role gate (the regenerate-code pattern), nothing is deleted, and their page has no trash icon at all. */
    public function test_staff_are_refused_and_see_no_trash_icons(): void
    {
        $branch = $this->freshBranch();
        $staff = $this->portalUser('staff', $branch->id);
        $source = $this->occupancy($branch);
        $typo = $this->table($branch);

        $this->deleteTable($staff, $source->id, $typo->id)->assertRedirect(route('admin.home'));
        $this->assertSame("You don't have permission to access that.", session('error'));
        $this->assertNotNull(RestaurantTable::find($typo->id), 'staff deleted a table');
        $this->assertSame([], $this->auditRecords());

        $html = $this->page($staff);

        foreach ([
            // The shared list builder names the helper only behind a typeof guard; its DEFINITION is managers-only.
            route('admin.tables.delete', [], false), 'DELETE_TABLE_ENDPOINT', 'function moveTargetWithDelete', 'move-target-delete',
            'id="moveStepDelete"', 'id="deleteConfirmBtn"', 'id="moveNotice"', 'with-delete', 'bi bi-trash', 'Delete Table',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "staff can see: {$needle}");
        }

        // ...while the Move dialog itself is unchanged for them, and its list still answers.
        $this->assertStringContainsString('<dialog id="moveTableDialog"', $html);
        $this->assertStringContainsString('<div id="moveTargets" class="move-targets"></div>', $html);
        $this->assertStringContainsString(route('admin.tables.move'), $html);
        $this->assertContains($typo->id, $this->moveTargetIds($staff, $source->id));

        // Managers DO carry the same markers, so the absence above is the gate, not a typo.
        $managerHtml = $this->page($this->portalUser('supervisor', $branch->id));
        $this->assertStringContainsString(route('admin.tables.delete', [], false), $managerHtml);
        $this->assertStringContainsString('id="moveStepDelete"', $managerHtml);
        $this->assertStringContainsString('<div id="moveTargets" class="move-targets with-delete"></div>', $managerHtml);
    }

    // ══════════ branch scope ══════════

    /** The Owner cannot reach another branch's table through a Move dialog opened at a different branch: 404, nothing deleted. */
    public function test_an_owner_cross_branch_delete_is_a_404(): void
    {
        $owner = $this->owner();
        $branches = $this->branchesUnderTest();

        foreach ($branches as $i => $branch) {
            $other = $branches[($i + 1) % $branches->count()];
            $source = $this->occupancy($branch);
            $foreign = $this->table($other);

            $this->deleteTable($owner, $source->id, $foreign->id)->assertNotFound();
            $this->assertNotNull(RestaurantTable::find($foreign->id), "branch #{$other->id}: deleted across branches");
        }

        $this->assertSame([], $this->auditRecords());
    }

    /** A supervisor is refused another branch's table (their own dialog) AND another branch's dialog: both 404. */
    public function test_a_supervisor_cross_branch_delete_is_a_404(): void
    {
        $mine = $this->freshBranch();
        $theirs = $this->freshBranch();
        $supervisor = $this->portalUser('supervisor', $mine->id);

        $ownSource = $this->occupancy($mine);
        $foreignSource = $this->occupancy($theirs);
        $foreign = $this->table($theirs);

        $this->deleteTable($supervisor, $ownSource->id, $foreign->id)->assertNotFound();
        $this->deleteTable($supervisor, $foreignSource->id, $foreign->id)->assertNotFound();

        $this->assertNotNull(RestaurantTable::find($foreign->id));
        $this->assertSame([], $this->auditRecords());

        // Control: the same table, through its own branch's Owner, is deletable — the 404s were scope, not state.
        $this->deleteTable($this->owner(), $foreignSource->id, $foreign->id)->assertOk();
    }

    /** A posted branch_id is never read: it neither redirects the delete nor widens it. */
    public function test_a_branch_in_the_request_body_is_ignored(): void
    {
        $owner = $this->owner();
        $mine = $this->freshBranch();
        $other = $this->freshBranch();
        $source = $this->occupancy($mine);

        // A forged branch does not stop the right table being deleted...
        $typo = $this->table($mine);
        $this->deleteTable($owner, $source->id, $typo->id, ['branch_id' => $other->id])->assertOk();
        $this->assertNull(RestaurantTable::find($typo->id));

        // ...and naming the foreign table's own branch does not open it up.
        $foreign = $this->table($other);
        $this->deleteTable($owner, $source->id, $foreign->id, ['branch_id' => $other->id])->assertNotFound();
        $this->assertNotNull(RestaurantTable::find($foreign->id));

        // Same for a supervisor of the first branch.
        $supervisor = $this->portalUser('supervisor', $mine->id);
        $this->deleteTable($supervisor, $source->id, $foreign->id, ['branch_id' => $other->id])->assertNotFound();
        $this->assertNotNull(RestaurantTable::find($foreign->id));
    }

    /** A table id or occupancy id that does not exist is a 404, and nothing is written. */
    public function test_a_nonexistent_id_is_a_404(): void
    {
        $owner = $this->owner();
        $branch = $this->freshBranch();
        $source = $this->occupancy($branch);
        $rows = RestaurantTable::count();

        $missingTable = (int) RestaurantTable::max('id') + 1000;
        $missingSession = (int) TableSession::max('id') + 1000;

        $this->deleteTable($owner, $source->id, $missingTable)->assertNotFound();
        $this->deleteTable($owner, $missingSession, $this->table($branch)->id)->assertNotFound();

        $this->asAdmin($owner)->postJson(self::DELETE, [])->assertStatus(422);
        $this->asAdmin($owner)->postJson(self::DELETE, ['session_id' => 'x', 'table_id' => [1]])->assertStatus(422);

        $this->assertSame($rows + 1, RestaurantTable::count());
        $this->assertSame([], $this->auditRecords());
    }

    // ══════════ what is refused ══════════

    /**
     * @param  callable(RestaurantTable): void  $leaveHistory
     */
    private function assertRefusedEverywhere(callable $leaveHistory, int $status, string $message, string $why): void
    {
        $owner = $this->owner();

        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id} ({$why})";
            $source = $this->occupancy($branch);
            $table = $this->table($branch);
            $leaveHistory($table);
            $before = $table->fresh()->toArray();

            $this->deleteTable($owner, $source->id, $table->id)
                ->assertStatus($status)
                ->assertExactJson(['message' => str_replace(':number', $table->table_number, $message)]);

            $this->assertSame($before, RestaurantTable::find($table->id)?->toArray(), "{$label}: the row changed or went");
        }

        $this->assertSame([], $this->auditRecords(), "{$why}: a refusal was audited");
    }

    public function test_an_occupied_table_is_refused(): void
    {
        $this->assertRefusedEverywhere(function (RestaurantTable $t) {
            TableOccupancy::attachStaffOrder($this->orderAt($t), $this->owner()->id);
        }, 409, 'Table :number is occupied. Move or clear it first.', 'live session');
    }

    public function test_a_table_with_an_open_order_is_refused(): void
    {
        $this->assertRefusedEverywhere(function (RestaurantTable $t) {
            $this->orderAt($t, ['status' => 'pending']);
        }, 409, 'Table :number has an open order and cannot be deleted.', 'open order');
    }

    public function test_a_table_with_any_finished_order_is_refused_as_history(): void
    {
        foreach (TableOccupancy::FINISHED_STATUSES as $status) {
            $this->assertRefusedEverywhere(function (RestaurantTable $t) use ($status) {
                $this->orderAt($t, ['status' => $status]);
            }, 409, TableOccupancy::ERR_TABLE_HAS_HISTORY, "{$status} order");
        }
    }

    public function test_a_table_with_a_released_session_is_refused(): void
    {
        $this->assertRefusedEverywhere(function (RestaurantTable $t) {
            TableSession::create([
                'branch_id'        => $t->branch_id,
                'table_number'     => $t->table_number,
                'session_token'    => (string) Str::uuid() . Str::random(24),
                'active_lock'      => null,
                'last_seen_at'     => now()->subDay(),
                'last_activity_at' => now()->subDay(),
            ])->forceFill(['released_at' => now()->subDay(), 'release_reason' => 'abandoned'])->save();
        }, 409, TableOccupancy::ERR_TABLE_HAS_HISTORY, 'released session');
    }

    public function test_a_table_with_a_help_request_is_refused(): void
    {
        $this->assertRefusedEverywhere(function (RestaurantTable $t) {
            HelpRequest::create([
                'branch_id'    => $t->branch_id,
                'table_number' => $t->table_number,
                'status'       => 'resolved',
                'message'      => 'Created by TableDeleteFromMoveDialogTest',
                'requested_at' => now()->subDay(),
                'resolved_at'  => now()->subDay(),
            ]);
        }, 409, TableOccupancy::ERR_TABLE_HAS_HISTORY, 'help request');
    }

    public function test_a_table_with_a_table_access_code_row_is_refused(): void
    {
        $this->assertRefusedEverywhere(function (RestaurantTable $t) {
            DB::table('table_access_codes')->insert([
                'code'         => strtoupper(Str::random(8)),
                'branch_id'    => $t->branch_id,
                'table_number' => $t->table_number,
                'expires_at'   => now()->subDay(),
                'created_at'   => now()->subDay(),
                'updated_at'   => now()->subDay(),
            ]);
        }, 409, TableOccupancy::ERR_TABLE_HAS_HISTORY, 'access code');
    }

    public function test_a_table_whose_code_was_ever_rotated_is_refused(): void
    {
        $this->assertRefusedEverywhere(function (RestaurantTable $t) {
            TableEntry::rotateCode($t, $this->owner()->id);
            $this->assertNotNull($t->fresh()->previous_code);
        }, 409, TableOccupancy::ERR_TABLE_HAS_HISTORY, 'rotated code');

        // previous_code alone (a row rotated before code_rotated_at existed) is history too.
        $this->assertRefusedEverywhere(function (RestaurantTable $t) {
            $t->forceFill(['previous_code' => strtoupper(Str::random(8)), 'code_rotated_at' => null])->save();
        }, 409, TableOccupancy::ERR_TABLE_HAS_HISTORY, 'previous_code only');
    }

    /** History at ANOTHER branch under the same table number is not this table's history. */
    public function test_history_is_matched_on_branch_and_table_number_together(): void
    {
        $owner = $this->owner();
        $mine = $this->freshBranch();
        $other = $this->freshBranch();
        $source = $this->occupancy($mine);
        $typo = $this->table($mine);

        $this->orderAt(TableEntry::findOrRegister($other->id, $typo->table_number), ['status' => 'completed']);

        $this->deleteTable($owner, $source->id, $typo->id)->assertOk();
        $this->assertNull(RestaurantTable::find($typo->id));
    }

    // ══════════ what a delete leaves behind ══════════

    /** Exactly one row goes; every other table — its code, previous code and state — is byte-for-byte untouched, and no code is reused. */
    public function test_the_row_is_really_gone_and_other_tables_codes_are_untouched(): void
    {
        $owner = $this->owner();
        $branch = $this->freshBranch();
        $source = $this->occupancy($branch);
        $typo = $this->table($branch);
        $neighbour = $this->table($branch);
        $deletedCode = $typo->code;

        $snapshot = fn () => RestaurantTable::orderBy('id')->get(['id', 'branch_id', 'table_number', 'code', 'previous_code', 'is_active'])
            ->keyBy('id')->map->toArray()->all();

        $before = $snapshot();

        $this->deleteTable($owner, $source->id, $typo->id)->assertOk();

        $after = $snapshot();
        unset($before[$typo->id]);

        $this->assertSame($before, $after, 'another table changed');
        $this->assertSame(0, RestaurantTable::whereKey($typo->id)->count());
        $this->assertSame(0, RestaurantTable::where('code', $deletedCode)->count(), 'the deleted code is still held');

        // Registering the same number again is a NEW table with a NEW code.
        $again = TableEntry::findOrRegister($branch->id, $typo->table_number);
        $this->assertNotSame($typo->id, $again->id);
        $this->assertNotSame($deletedCode, $again->code);
        $this->assertSame($neighbour->code, $neighbour->fresh()->code);
    }

    /** The Move list no longer offers a deleted table, and it is not offered for any other party either. */
    public function test_the_move_list_no_longer_shows_the_deleted_table(): void
    {
        $owner = $this->owner();

        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $source = $this->occupancy($branch);
            $second = $this->occupancy($branch);
            $typo = $this->table($branch);

            $this->assertContains($typo->id, $this->moveTargetIds($owner, $source->id), "{$label}: not offered before");

            $this->deleteTable($owner, $source->id, $typo->id)->assertOk();

            $this->assertNotContains($typo->id, $this->moveTargetIds($owner, $source->id), "{$label}: still offered");
            $this->assertNotContains($typo->id, $this->moveTargetIds($owner, $second->id), "{$label}: still offered to another party");
        }
    }

    /** A double submit deletes once: the second request is a clean 404, one DELETE statement, one audit line. */
    public function test_a_double_submit_deletes_once(): void
    {
        $owner = $this->owner();
        $branch = $this->freshBranch();
        $source = $this->occupancy($branch);
        $typo = $this->table($branch);
        $deletes = [];

        DB::connection()->beforeExecuting(function ($sql) use (&$deletes) {
            if (preg_match('/^\s*delete\b/i', $sql) && stripos($sql, 'restaurant_tables') !== false) {
                $deletes[] = $sql;
            }
        });

        $this->deleteTable($owner, $source->id, $typo->id)->assertOk();
        $this->deleteTable($owner, $source->id, $typo->id)->assertNotFound();

        $this->assertCount(1, $deletes);
        $this->assertCount(1, $this->auditRecords());
    }

    // ══════════ the page ══════════

    /** The page is only QR Code Generator + Occupied Tables, for every role, with no Manage tables / Deactivate / Reactivate / Restore anywhere. */
    public function test_the_page_has_no_manage_tables_markup_or_service_wording(): void
    {
        $branch = $this->freshBranch();

        foreach ([$this->owner(), $this->portalUser('supervisor', $branch->id), $this->portalUser('staff', $branch->id)] as $user) {
            $who = $user->role;
            $html = $this->page($user);
            $own = $this->pageOwn($html);

            $this->assertDoesNotMatchRegularExpression('/manage\s*tables|deactivat|reactivat|restore/i', $own, "{$who}: wording left on the page");

            foreach (['manageTablesCard', 'manageTablesToggle', 'manageInactiveToggle', 'mt-grid', 'mt-card', 'removeTableDialog', 'qr-generator/tables', 'qr-generator/deactivate-table', 'qr-generator/reactivate-table'] as $gone) {
                $this->assertStringNotContainsString($gone, $html, "{$who}: {$gone}");
            }

            // Two cards: the generator, then Occupied Tables.
            $this->assertSame(2, substr_count($own, '<div class="content-card"'), "{$who}: cards on the page");
            $this->assertLessThan(strpos($html, 'id="tablesBody"'), strpos($html, 'id="generateBtn"'), $who);
            $this->assertStringContainsString('<i class="bi bi-table"></i> Occupied Tables', $html);
        }
    }

    /** The trash is its own button beside the table's, its click is stopped before the picker, and the dialog's delete step behaves as asked. */
    public function test_the_trash_icon_never_selects_the_destination_and_the_confirm_step_is_guarded(): void
    {
        $html = $this->page($this->portalUser('supervisor', $this->freshBranch()->id));

        // Built beside the table's own button, never inside it.
        $wrap = $this->scriptFunction($html, 'moveTargetWithDelete');
        $this->assertStringContainsString("trash.type = 'button'", $wrap);
        $this->assertStringContainsString("trash.className = 'move-target-delete'", $wrap);
        $this->assertStringContainsString('row.append(button, trash)', $wrap);
        $this->assertStringNotContainsString('button.append', $wrap);
        $this->assertStringNotContainsString('button.appendChild', $wrap);
        $this->assertStringNotContainsString("'move-target'", $wrap, 'the trash must not be a .move-target itself');
        $this->assertStringContainsString("'Delete Table ' + t.table_number", $wrap);
        $this->assertDoesNotMatchRegularExpression('/innerHTML[^;\n]*t\.(table_number|id)/', $wrap, 'a table number reached innerHTML');

        // The list's own listener stops the click before the document-level picker sees it, and never picks.
        $this->assertMatchesRegularExpression(
            "/getElementById\('moveTargets'\)\.addEventListener\('click', function \(event\) \{\s*const trash = event\.target\.closest\('\.move-target-delete'\);\s*if \(!trash\) return;\s*(\/\/[^\n]*\n\s*)?event\.stopPropagation\(\);\s*askDeleteTable\(trash\);/",
            $html
        );
        $ask = $this->scriptFunction($html, 'askDeleteTable');
        $this->assertStringNotContainsString('pickMoveTarget', $ask);
        $this->assertStringContainsString("showMoveStep('delete')", $ask);

        // "Delete Table N?" with Cancel / Delete.
        $this->assertStringContainsString('<h3 id="deleteTableTitle">Delete Table <span id="deleteTableLabel"></span>?</h3>', $html);
        $this->assertStringContainsString('id="deleteCancelBtn">Cancel</button>', $html);
        $this->assertStringContainsString('id="deleteConfirmBtn" style="margin-right:0;">Delete</button>', $html);

        // Disabled while pending; success removes the table without closing the dialog.
        $confirm = $this->scriptFunction($html, 'confirmDeleteTable');
        $this->assertMatchesRegularExpression('/if \(!moveState \|\| !deleteTarget \|\| btn\.disabled\) return;\s*btn\.disabled = true;/', $confirm);
        $this->assertLessThan(strpos($confirm, 'await fetch('), strpos($confirm, 'btn.disabled = true;'));
        $this->assertStringContainsString('JSON.stringify({ session_id: moveState.sessionId, table_id: target.id })', $confirm);
        $this->assertStringContainsString('removeMoveTarget(target.id)', $confirm);
        $this->assertStringNotContainsString('moveDialog.close()', $confirm);

        // The step switcher hides the delete step whenever any other step shows.
        $this->assertStringContainsString("if (del) del.hidden = step !== 'delete';", $this->scriptFunction($html, 'showMoveStep'));
    }

    /** Moving a party works exactly as before with the trash in the list: same list shape, same move, same audit line. */
    public function test_move_core_behaviour_is_unchanged(): void
    {
        $owner = $this->owner();

        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $source = $this->occupancy($branch);
            $to = $this->table($branch);

            $list = $this->asAdmin($owner)->getJson('/admin/tables/move-targets?session_id=' . $source->id)->assertOk();
            $this->assertSame(['id', 'table_number'], array_keys($list->json('tables.0')), "{$label}: list shape");
            $this->assertSame(['session_id', 'branch_name', 'table_number', 'tables'], array_keys($list->json()), "{$label}: response shape");

            $moved = $this->asAdmin($owner)->postJson('/admin/tables/move', ['session_id' => $source->id, 'table_id' => $to->id])
                ->assertOk()
                ->assertJson(['session_id' => $source->id, 'from' => $source->table_number, 'to' => $to->table_number]);

            $this->assertSame($to->table_number, $source->fresh()->table_number, $label);
            $this->assertSame($source->session_token, $source->fresh()->session_token, "{$label}: same row, same token");
            foreach ($moved->json('order_ids') as $orderId) {
                $this->assertSame($to->table_number, Order::find($orderId)->table_number, $label);
            }
            $this->assertSame('move', collect($this->auditRecords())->last()['action'], $label);

            // The table the party just sat at now has history (a session and an order): the trash refuses it.
            $back = $this->occupancy($branch);
            $this->deleteTable($owner, $back->id, $to->id)->assertStatus(409);
            $this->assertNotNull(RestaurantTable::find($to->id), $label);
        }
    }

    // ══════════ route and limiter ══════════

    public function test_the_route_is_post_only_manager_gated_and_on_its_own_limiter(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.tables.delete');

        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('role:admin,supervisor', $route->gatherMiddleware());
        $this->assertContains('throttle:admin-tables-delete', $route->gatherMiddleware());

        // The same role gate as regenerate-code.
        $this->assertContains('role:admin,supervisor', app('router')->getRoutes()->getByName('admin.qr-generator.regenerate-code')->gatherMiddleware());
    }

    /** Spending the delete budget blocks only deletes; spending Move's or the table-service budget leaves deletes alone. */
    public function test_the_rate_limiter_is_isolated(): void
    {
        $owner = $this->owner();
        $branch = $this->freshBranch();
        $source = $this->occupancy($branch);
        $missing = (int) RestaurantTable::max('id') + 1000;
        $post = fn () => $this->actingAs($owner, 'admin')->postJson(self::DELETE, ['session_id' => $source->id, 'table_id' => $missing]);

        app('cache')->store()->flush();

        for ($i = 0; $i < RateLimitServiceProvider::ADMIN_TABLES_DELETE_PER_IP; $i++) {
            $post()->assertNotFound();
        }

        $post()->assertStatus(429);

        // Not shared: Move, its list and the table-service actions still answer.
        $this->actingAs($owner, 'admin')->getJson('/admin/tables/move-targets?session_id=' . $source->id)->assertOk();
        $this->actingAs($owner, 'admin')->postJson('/admin/tables/move', ['session_id' => $source->id, 'table_id' => $missing])->assertNotFound();
        $this->actingAs($owner, 'admin')->postJson('/admin/qr-generator/deactivate-table', ['table_id' => $missing])->assertNotFound();

        // ...and the other way round.
        app('cache')->store()->flush();

        for ($i = 0; $i < RateLimitServiceProvider::ADMIN_QR_TABLE_SERVICE_PER_IP; $i++) {
            $this->actingAs($owner, 'admin')->postJson('/admin/qr-generator/deactivate-table', ['table_id' => $missing]);
        }
        for ($i = 0; $i < RateLimitServiceProvider::ADMIN_TABLES_MOVE_PER_IP; $i++) {
            $this->actingAs($owner, 'admin')->postJson('/admin/tables/move', ['session_id' => $source->id, 'table_id' => $missing]);
        }

        $this->actingAs($owner, 'admin')->postJson('/admin/qr-generator/deactivate-table', ['table_id' => $missing])->assertStatus(429);
        $this->actingAs($owner, 'admin')->postJson('/admin/tables/move', ['session_id' => $source->id, 'table_id' => $missing])->assertStatus(429);
        $post()->assertNotFound();
    }

    // ══════════ a customer who validated a table that is then deleted ══════════

    /**
     * The customer's QR passed validate(), and the table is deleted in the instant before claim() takes the
     * registry lock: the claim finds no row and refuses ("not found") — no session at a table that is gone.
     * (The real two-process race, both orders, is TableDeleteRaceTest.)
     */
    public function test_a_claim_for_a_table_deleted_after_validation_sees_not_found(): void
    {
        $branch = $this->freshBranch();
        $typo = $this->table($branch);
        $done = false;

        DB::connection()->beforeExecuting(function ($sql) use ($typo, $branch, &$done) {
            if (!$done && stripos($sql, 'from `restaurant_tables`') !== false && stripos($sql, 'for update') !== false) {
                $done = true;
                $this->assertTrue(TableOccupancy::deleteUnusedTable($typo, $branch->id)['ok'], 'the competing delete was refused');
            }
        });

        $this->on('customer');
        $this->get('/customer/menu?branch_id=' . $branch->id . '&table=' . $typo->table_number . '&k=' . $typo->code)
            ->assertRedirect(route('customer.dineinqr'));

        $this->assertTrue($done, 'the claim never took the registry lock');
        $this->assertSame(TableEntry::ERR_TABLE_NOT_FOUND, session('error'));
        $this->assertNull(RestaurantTable::find($typo->id));
        $this->assertSame(0, TableSession::where('branch_id', $branch->id)->where('table_number', $typo->table_number)->count(), 'a session was opened at a deleted table');
    }

    /** Same, through the typed-code door. */
    public function test_a_typed_code_for_a_table_deleted_after_validation_sees_not_found(): void
    {
        $branch = $this->freshBranch();
        $typo = $this->table($branch);
        $done = false;

        DB::connection()->beforeExecuting(function ($sql) use ($typo, $branch, &$done) {
            if (!$done && stripos($sql, 'from `restaurant_tables`') !== false && stripos($sql, 'for update') !== false) {
                $done = true;
                TableOccupancy::deleteUnusedTable($typo, $branch->id);
            }
        });

        $this->on('customer-typed');
        $this->from('/customer/dineinqr')->post('/customer/dineinqr', ['table_code' => $typo->code, 'next' => 'guest'])
            ->assertRedirect('/customer/dineinqr');

        $this->assertTrue($done);
        $this->assertSame(TableEntry::ERR_TABLE_NOT_FOUND, session('error'));
        $this->assertSame(0, TableSession::where('branch_id', $branch->id)->where('table_number', $typo->table_number)->count());
    }

    /** The is_active guard in claim() is untouched: an out-of-service table is still refused as such. */
    public function test_the_claim_is_active_guard_is_kept(): void
    {
        $branch = $this->freshBranch();
        $table = $this->table($branch);
        $table->forceFill(['is_active' => false])->save();

        $claim = TableOccupancy::claim($branch, $table->table_number, '127.0.0.1', $table->id);

        $this->assertFalse($claim['ok']);
        $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, $claim['error']);
        $this->assertNull(TableOccupancy::activeFor($branch->id, $table->table_number));
    }
}

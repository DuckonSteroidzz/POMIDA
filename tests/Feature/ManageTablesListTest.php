<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\HelpRequest;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The "Manage tables" list on QR & Table Codes — one branch's registered tables
 * with a Remove / Restore button each, so a mistaken table (Table 100 typed
 * instead of Table 10) can go without printing a card for it first.
 *
 * "Remove" is the existing soft deactivate (restaurant_tables.is_active = 0)
 * and "Restore" the existing reactivate: this file proves the NEW read endpoint
 * (admin.qr-generator.tables) and that the list's buttons, which post to those
 * unchanged endpoints, behave as the list promises. TableServiceByAdminTest
 * already covers the endpoints themselves.
 *
 * Branch-agnostic on purpose: no branch id, table id or table number is named
 * anywhere. Every matrix runs over every active branch in the database plus one
 * created inside the test (which has no history at all), and every table is
 * registered here with a random seven-digit number.
 *
 * Oct 2026: the page no longer draws this list for anyone. A mistaken table is
 * now deleted from the Move table dialog (TableDeleteFromMoveDialogTest). The
 * endpoint and the two actions are kept on purpose, unused by the UI, so every
 * backend assertion here stands; the markup assertions now prove the section,
 * its CSS and its script are gone.
 *
 * Devices: the test client keeps ONE session store, so on($device) parks the
 * current device's session and restores the next one's — the same helper as
 * TableServiceByAdminTest and TableMoveByStaffTest.
 */
class ManageTablesListTest extends TestCase
{
    use DatabaseTransactions;

    private const LIST = '/admin/qr-generator/tables';
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

        // Every Remove / Restore writes an audit line; keep this run's out of
        // storage/logs.
        $this->auditLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manage-tables-test-' . Str::random(10) . '.log';
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

    private function freshBranch(bool $active = true): Branch
    {
        return Branch::create([
            'name'           => 'Manage Tables ' . Str::random(6),
            'code'           => 'MT' . strtoupper(Str::random(6)),
            'address'        => 'Created by ManageTablesListTest',
            'is_active'      => $active,
            'is_main_branch' => false,
        ]);
    }

    /** Every active branch, plus one created now with no history at all. */
    private function branchesUnderTest()
    {
        return Branch::where('is_active', true)->orderBy('id')->get()->push($this->freshBranch());
    }

    /** A registered table with a random number NO branch has used (so a number can never point at another branch's row). */
    private function table(Branch $branch): RestaurantTable
    {
        do {
            $number = (string) random_int(1000000, 9999999);
        } while (
            RestaurantTable::where('table_number', $number)->exists()
            || TableSession::where('table_number', $number)->exists()
            || Order::where('table_number', $number)->exists()
        );

        return TableEntry::findOrRegister($branch->id, $number);
    }

    private function portalUser(string $role, ?int $branchId): User
    {
        return User::create([
            'name'              => 'ManageTables ' . ucfirst($role),
            'email'             => 'managetables-' . $role . '-' . Str::lower(Str::random(12)) . '@example.test',
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

        $session = TableOccupancy::activeFor($table->branch_id, $table->table_number);
        $this->assertNotNull($session, "{$device} was not seated at Table {$table->table_number}");

        return $session;
    }

    /** A counter (staff-opened) occupancy: no phone involved. */
    private function seatAtCounter(RestaurantTable $table, User $by): Order
    {
        $order = $this->orderAt($table);
        TableOccupancy::attachStaffOrder($order, $by->id);
        $this->assertNotNull(TableOccupancy::activeFor($table->branch_id, $table->table_number));

        return $order;
    }

    private function orderAt(RestaurantTable $table, array $attrs = []): Order
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

    /** Each admin call is its own "device", and the matrices outrun one minute's per-address budget. */
    private function asAdmin(User $actor)
    {
        app('cache')->store()->flush();
        $this->on('admin-' . $actor->id);

        return $this->actingAs($actor, 'admin');
    }

    /** The list, exactly as the page's script asks for it. */
    private function list(User $actor, $branchId)
    {
        return $this->asAdmin($actor)->getJson(self::LIST . '?branch_id=' . $branchId);
    }

    /** What the Remove button posts. */
    private function remove(User $actor, int $tableId)
    {
        return $this->asAdmin($actor)->postJson(self::DEACTIVATE, ['table_id' => $tableId]);
    }

    /** What the Restore button posts. */
    private function restore(User $actor, int $tableId)
    {
        return $this->asAdmin($actor)->postJson(self::REACTIVATE, ['table_id' => $tableId]);
    }

    private function moveTargets(User $actor, int $sessionId)
    {
        return $this->asAdmin($actor)->getJson('/admin/tables/move-targets?session_id=' . $sessionId);
    }

    /** @return array<int, string> table id => status, from a list response */
    private function statuses($response): array
    {
        return collect($response->json('tables'))->pluck('status', 'id')->all();
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

    // ══════════ the list: scope and content ══════════

    /** Only the selected branch's tables, each in the state the server says, and the response names the branch. */
    public function test_the_list_shows_only_the_selected_branchs_tables_with_their_states(): void
    {
        $owner = $this->owner();
        $branches = $this->branchesUnderTest();

        // Every branch gets a table of every kind, so a leak in ANY direction
        // (one branch's rows under another's name) shows up.
        $made = [];

        foreach ($branches as $branch) {
            $free = $this->table($branch);
            $busy = $this->table($branch);
            $gone = $this->table($branch);

            $this->seatAtCounter($busy, $owner);
            $this->remove($owner, $gone->id)->assertOk();

            $made[$branch->id] = compact('free', 'busy', 'gone');
        }

        foreach ($branches as $branch) {
            $label = "branch #{$branch->id}";
            $response = $this->list($owner, $branch->id)->assertOk();
            $body = $response->json();

            $this->assertSame((int) $branch->id, $body['branch_id'], $label);
            $this->assertSame($branch->name, $body['branch_name'], $label);

            // Exactly this branch's registry rows: nothing missing, nothing extra.
            $expected = RestaurantTable::where('branch_id', $branch->id)->pluck('id')->sort()->values()->all();
            $shown = collect($body['tables'])->pluck('id')->sort()->values()->all();
            $this->assertSame($expected, $shown, "{$label}: the list is not this branch's tables");

            $statuses = $this->statuses($response);
            $mine = $made[$branch->id];

            $this->assertSame('in_service', $statuses[$mine['free']->id], "{$label}: a free table");
            $this->assertSame('occupied', $statuses[$mine['busy']->id], "{$label}: a table with a customer");
            $this->assertSame('not_in_service', $statuses[$mine['gone']->id], "{$label}: a removed table");

            // Nothing from any other branch: not its id, not its number.
            $content = $response->getContent();

            foreach ($made as $otherId => $tables) {
                if ($otherId === $branch->id) {
                    continue;
                }

                foreach ($tables as $kind => $other) {
                    $this->assertArrayNotHasKey($other->id, $statuses, "{$label}: branch #{$otherId}'s {$kind} table is listed");
                    $this->assertStringNotContainsString($other->table_number, $content, "{$label}: branch #{$otherId}'s {$kind} number leaked");
                }
            }

            // The occupied row says why its button is off; no other row has a reason.
            foreach ($body['tables'] as $row) {
                $this->assertSame(
                    $row['id'] === $mine['busy']->id
                        ? "Table {$mine['busy']->table_number} is occupied. Move or clear the customer first."
                        : null,
                    $row['blocked_reason'],
                    "{$label}: reason on Table {$row['table_number']}"
                );
            }
        }
    }

    /** Numbers read in table order — shorter first, then alphabetical ("2" before "10") — the same order as the Move list. */
    public function test_tables_are_listed_in_table_order(): void
    {
        $branch = $this->freshBranch();

        // Random numbers of three different lengths, registered in a shuffled
        // order, so neither the values nor the insertion order is what sorts them.
        $numbers = [];

        foreach ([[1, 9], [10, 99], [100, 999], [1000, 9999]] as [$low, $high]) {
            do {
                $n = (string) random_int($low, $high);
            } while (
                in_array($n, $numbers, true)
                || RestaurantTable::where('table_number', $n)->exists()
                || TableSession::where('table_number', $n)->exists()
            );

            $numbers[] = $n;
        }

        $registered = $numbers;
        shuffle($registered);

        foreach ($registered as $number) {
            TableEntry::findOrRegister($branch->id, $number);
        }

        usort($numbers, fn ($a, $b) => [strlen($a), $a] <=> [strlen($b), $b]);

        $listed = collect($this->list($this->owner(), $branch->id)->assertOk()->json('tables'))->pluck('table_number')->all();

        $this->assertSame($numbers, $listed);
    }

    /** A branch with no tables at all (one created during the defense) answers an empty list, not an error. */
    public function test_a_branch_with_no_tables_answers_an_empty_list(): void
    {
        $branch = $this->freshBranch();

        $this->list($this->owner(), $branch->id)
            ->assertOk()
            ->assertExactJson(['branch_id' => (int) $branch->id, 'branch_name' => $branch->name, 'tables' => []]);
    }

    /** The shape is fixed, and no table code (current or previous) can be in it. */
    public function test_table_codes_are_never_in_the_response(): void
    {
        $owner = $this->owner();

        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $rotated = $this->table($branch);
            $this->table($branch);
            $this->table($branch)->forceFill(['is_active' => false])->save();

            // A table whose code has been rotated carries a previous_code too.
            TableEntry::rotateCode($rotated, $owner->id);

            $response = $this->list($owner, $branch->id)->assertOk();
            $content = $response->getContent();

            $this->assertSame(['branch_id', 'branch_name', 'tables'], array_keys($response->json()), $label);

            foreach ($response->json('tables') as $row) {
                $this->assertSame(['id', 'table_number', 'status', 'blocked_reason'], array_keys($row), $label);
            }

            // Every table of the branch, real ones included.
            foreach (RestaurantTable::where('branch_id', $branch->id)->get() as $t) {
                $this->assertStringNotContainsString($t->code, $content, "{$label}: Table {$t->table_number}'s code is in the response");

                if ($t->previous_code) {
                    $this->assertStringNotContainsString($t->previous_code, $content, "{$label}: a previous code is in the response");
                }
            }

            $this->assertNotNull(RestaurantTable::find($rotated->id)->previous_code, 'the fixture never rotated');

            foreach (['"code"', 'previous_code', '"url"', 'k=', 'qrUrl'] as $needle) {
                $this->assertStringNotContainsString($needle, $content, "{$label}: {$needle}");
            }
        }
    }

    // ══════════ the list: who may ask, and for which branch ══════════

    /** Another branch's id — like an unknown one or a closed one — is a plain 404 that tells nothing. */
    public function test_an_out_of_scope_branch_is_a_404_that_reads_like_a_missing_one(): void
    {
        $owner = $this->owner();
        $missing = (int) Branch::max('id') + 1000000;
        $closed = $this->freshBranch(false);
        $this->table($closed);

        $unknown = $this->list($owner, $missing)->assertNotFound()->getContent();

        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $table = $this->table($branch);

            $elsewhere = $this->portalUser('supervisor', $this->freshBranch()->id);
            $branchless = $this->portalUser('supervisor', null);

            foreach ([$elsewhere, $branchless] as $who) {
                $refused = $this->list($who, $branch->id)->assertNotFound()->getContent();

                $this->assertSame($unknown, $refused, "{$label}: out of scope does not read like a missing branch");
                $this->assertStringNotContainsString($table->table_number, $refused, $label);
                $this->assertStringNotContainsString($branch->name, $refused, $label);
            }

            // The branch's own supervisor, and the owner, are let in.
            $this->list($this->portalUser('supervisor', $branch->id), $branch->id)->assertOk();
            $this->list($owner, $branch->id)->assertOk();
        }

        // A closed branch is not on the page's dropdown, so it is a 404 for the owner too.
        $this->list($owner, $closed->id)->assertNotFound();
        $this->list($this->portalUser('supervisor', $closed->id), $closed->id)->assertNotFound();
    }

    /** The branch is only a request to look at one the page itself offers; nothing posted can widen a supervisor's reach. */
    public function test_a_forged_branch_id_cannot_widen_a_supervisors_reach(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $theirs = $this->table($branch);
            $other = $this->freshBranch();
            $own = $this->table($other);
            $supervisor = $this->portalUser('supervisor', $other->id);

            // Their own branch: only their own tables, whatever else is on the URL.
            $response = $this->asAdmin($supervisor)
                ->getJson(self::LIST . '?branch_id=' . $other->id . '&branch=' . $branch->id . '&table_id=' . $theirs->id . '&branch_id[]=' . $branch->id);

            // A second branch_id in array form is malformed, never a second lookup.
            $response->assertStatus(422);

            $response = $this->asAdmin($supervisor)
                ->getJson(self::LIST . '?branch_id=' . $other->id . '&branch=' . $branch->id . '&table_id=' . $theirs->id)
                ->assertOk();

            $this->assertSame([$own->id], collect($response->json('tables'))->pluck('id')->all(), "{$label}: a forged parameter changed what was listed");
            $this->assertStringNotContainsString($theirs->table_number, $response->getContent(), $label);

            // The other branch itself is still a 404.
            $this->asAdmin($supervisor)->getJson(self::LIST . '?branch_id=' . $branch->id)->assertNotFound();
        }
    }

    /**
     * "Validated like the page itself": the endpoint answers for exactly the
     * branches the page's own Branch dropdown offers that user, and for no other.
     */
    public function test_the_endpoint_accepts_exactly_the_branches_the_pages_dropdown_offers(): void
    {
        $closed = $this->freshBranch(false);
        $active = $this->branchesUnderTest();
        $everyBranch = Branch::pluck('id')->push((int) Branch::max('id') + 1000000)->all();

        $users = [
            'owner'                  => $this->owner(),
            'supervisor of a branch' => $this->portalUser('supervisor', $active->last()->id),
            'supervisor of closed'   => $this->portalUser('supervisor', $closed->id),
            'branchless supervisor'  => $this->portalUser('supervisor', null),
        ];

        foreach ($users as $who => $user) {
            $html = $this->asAdmin($user)->get('/admin/qr-generator')->assertOk()->getContent();

            preg_match('/<select id="branchSelect".*?<\/select>/s', $html, $select);
            preg_match_all('/<option value="(\d+)"/', $select[0] ?? '', $found);
            $offered = array_map('intval', $found[1]);

            foreach ($everyBranch as $id) {
                $status = $this->asAdmin($user)->getJson(self::LIST . '?branch_id=' . $id)->getStatusCode();

                $this->assertSame(
                    in_array((int) $id, $offered, true) ? 200 : 404,
                    $status,
                    "{$who}: branch #{$id} is " . (in_array((int) $id, $offered, true) ? '' : 'not ') . 'in the dropdown'
                );
            }
        }

        // The cases above are not vacuous: the owner is offered every active branch and no closed one.
        $this->assertGreaterThan(1, count($active));
    }

    /** The id must be an integer: anything else is a validation error, never a lookup. */
    public function test_a_malformed_branch_id_is_a_validation_error(): void
    {
        $owner = $this->owner();

        foreach (['', '?branch_id=', '?branch_id=abc', '?branch_id[]=1', '?branch_id=1%20OR%201%3D1', '?branch_id=1.5'] as $query) {
            $this->asAdmin($owner)->getJson(self::LIST . $query)->assertStatus(422);
        }
    }

    /** Staff are refused by the role gate, and the page does not draw the section or hand them the URL. */
    public function test_staff_are_refused_by_the_endpoint_and_do_not_see_the_section(): void
    {
        $branch = $this->freshBranch();
        $table = $this->table($branch);
        $staff = $this->portalUser('staff', $branch->id);

        $this->list($staff, $branch->id)->assertRedirect(route('admin.home'));
        $this->assertSame("You don't have permission to access that.", session('error'));

        // Their own branch's list, for every shape of request.
        $this->asAdmin($staff)->get(self::LIST . '?branch_id=' . $branch->id)->assertRedirect(route('admin.home'));

        $html = $this->asAdmin($staff)->get('/admin/qr-generator')->assertOk()->getContent();

        // The section's markup, its dialog, the list URL and the manager-only
        // script — not the bare words, which a CSS comment may legitimately carry.
        foreach ([
            self::LIST,
            'id="manageTablesCard"',
            'id="manageTablesToggle"',
            'id="manageTablesPanel"',
            'id="manageTablesToggleText"',
            'id="removeTableDialog"',
            'MANAGE_TABLES_ENDPOINT',
            'function loadManageTables',
            'function setTablesCount',
            "dataset.action = 'restore'",
            'id="manageActive"',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "staff can see: {$needle}");
        }

        // Oct 2026: the section is gone for managers too, so the same markers are absent there.
        $managerHtml = $this->asAdmin($this->portalUser('supervisor', $branch->id))->get('/admin/qr-generator')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="manageTablesCard"', $managerHtml);
        $this->assertStringNotContainsString('function loadManageTables', $managerHtml);

        // ...and they still cannot Remove through the back door.
        $this->remove($staff, $table->id)->assertRedirect(route('admin.home'));
        $this->assertTrue($table->fresh()->is_active);
    }

    /** Owner and supervisor no longer get the section, its dialog or its wording; the endpoint behind it still answers them. */
    public function test_managers_no_longer_get_the_section_but_the_endpoint_still_answers(): void
    {
        $branch = $this->freshBranch();

        foreach ([$this->owner(), $this->portalUser('supervisor', $branch->id)] as $manager) {
            $html = $this->asAdmin($manager)->get('/admin/qr-generator')->assertOk()->getContent();
            $who = $manager->role;

            foreach ([
                'id="manageTablesCard"',
                'Manage tables',
                'id="removeTableDialog"',
                route('admin.qr-generator.tables', [], false),
                'MANAGE_TABLES_ENDPOINT',
                'loadManageTables',
                "'Remove Table ' + t.table_number",
                "'Restore Table ' + t.table_number",
                "'Remove Table ' + removeTarget.number + ' from '",
                "'Order history is kept, and you can restore it later.'",
                // The card preview's old Deactivate / Reactivate button and dialog stay gone too.
                'id="serviceCardBtn"', 'onServiceButton()', 'id="tableServiceDialog"', 'id="serviceConfirmBtn"',
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "{$who}: {$needle}");
            }

            // The page is the generator, then Occupied Tables — nothing between them.
            $this->assertLessThan(strpos($html, 'id="tablesBody"'), strpos($html, 'id="generateBtn"'), $who);

            // Kept on purpose, unused by the UI.
            $this->list($manager, $branch->id)->assertOk();
        }
    }

    // ══════════ the collapsible section ══════════

    /** The collapsed section's toggle row, its panel, its CSS and its script are all gone — no dead markup is left behind. */
    public function test_the_manage_tables_toggle_panel_css_and_script_are_gone(): void
    {
        $html = $this->managerPage();

        foreach ([
            'id="manageTablesToggle"', 'id="manageTablesPanel"', 'id="manageTablesToggleText"', 'Manage tables (',
            'id="manageRefreshBtn"', 'id="manageTablesWrap"', 'id="manageMsg"', 'id="manageBranchName"',
            '.mt-section-toggle', '.mt-section-chevron', '#manageTablesPanel', '.mt-head',
            'setTablesPanel', 'tablesToggle', 'tablesPanel', 'refreshManageTables',
        ] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "left over: {$gone}");
        }
    }

    /** N (every registered table at a branch) is still what the kept endpoint returns, though no page label draws it any more. */
    public function test_the_list_endpoint_still_counts_every_registered_table_with_no_label_on_the_page(): void
    {
        $html = $this->managerPage();

        foreach (['setTablesCount', 'renderManageTables', 'clearManageLists', 'loadManageTables', "addEventListener('change', function () { loadManageTables"] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "left over: {$gone}");
        }

        // What N is made of, per branch: every row the endpoint returns, including a removed table, and it follows a new table.
        $owner = $this->owner();

        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $n = fn () => count($this->list($owner, $branch->id)->assertOk()->json('tables'));

            $baseline = $n();
            $a = $this->table($branch);
            $this->table($branch);

            $this->assertSame($baseline + 2, $n(), "{$label}: after two new tables");
            $this->assertSame(
                $baseline + 2,
                RestaurantTable::where('branch_id', $branch->id)->count(),
                "{$label}: N is the number of registered tables"
            );

            // A removed table is still registered, so N does not drop.
            $this->remove($owner, $a->id)->assertOk();
            $this->assertSame($baseline + 2, $n(), "{$label}: Remove does not change N");
        }

        $this->assertSame(0, count($this->list($owner, $this->freshBranch()->id)->json('tables')), 'a branch with no tables reads (0)');
    }

    /** Nothing on the page posts to Remove / Restore any more, and the kept endpoints still do their job. */
    public function test_remove_and_restore_endpoints_still_work_with_no_grid_posting_to_them(): void
    {
        $html = $this->managerPage();

        foreach ([
            'postTableServiceFor', 'SERVICE_ENDPOINTS', 'syncCardWithTable', 'confirmRemoveTable', 'restoreTable(',
            route('admin.qr-generator.deactivate-table'), route('admin.qr-generator.reactivate-table'),
        ] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "left over: {$gone}");
        }

        $owner = $this->owner();

        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $table = $this->table($branch);
            $status = fn () => collect($this->list($owner, $branch->id)->assertOk()->json('tables'))->firstWhere('id', $table->id)['status'];

            $this->assertSame('in_service', $status(), $label);

            // Remove
            $this->remove($owner, $table->id)->assertOk();
            $this->assertFalse($table->fresh()->is_active, $label);
            $this->assertSame('not_in_service', $status(), $label);

            // Restore
            $this->restore($owner, $table->id)->assertOk();
            $this->assertTrue($table->fresh()->is_active, $label);
            $this->assertSame('in_service', $status(), $label);

            // An occupied table is still refused.
            $this->seatAtCounter($table, $owner);
            $this->remove($owner, $table->id)->assertStatus(409);
            $this->assertTrue($table->fresh()->is_active, $label);
        }
    }

    // ══════════ the card grid (removed Oct 2026) ══════════
    //
    // The cards used to be drawn in the browser from the list endpoint. These
    // now prove the grid's markup, CSS and script are gone, while the endpoint
    // data they were drawn from is still served.

    private function managerPage(?Branch $branch = null): string
    {
        $branch ??= $this->freshBranch();

        return $this->asAdmin($this->portalUser('supervisor', $branch->id))
            ->get('/admin/qr-generator')->assertOk()->getContent();
    }

    /** The source of one named function in the page's script (they close at four-space indent). */
    private function scriptFunction(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('/    (?:async )?function ' . $name . '\(.*?\n    \}\n/s', $html, $m), "function {$name} not found");

        return $m[0];
    }

    /** The card grid — its two lists, every .mt-* rule and the card builder — is gone, as is the older row layout. */
    public function test_the_card_grid_markup_css_and_builder_are_gone(): void
    {
        $html = $this->managerPage();

        foreach ([
            'id="manageActive"', 'id="manageInactive"', 'mt-grid', 'mt-card', 'mt-num', 'mt-badge', 'mt-foot',
            'mt-btn', 'mt-muted', 'mt-note', 'mt-state', 'mt-toggle', 'buildManageCard', 'MANAGE_BADGES',
            'btn-service-on', 'btn-service-off',
            'mt-row', 'mt-list', 'mt-main', 'mt-action', 'buildManageRow', 'manageInactiveTitle',
        ] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "left over: {$gone}");
        }
    }

    /** The "not in service" toggle and section are gone from the page; the count behind them is still served and still follows Remove / Restore. */
    public function test_the_not_in_service_count_is_still_served_but_its_toggle_is_gone(): void
    {
        $html = $this->managerPage();

        foreach (['id="manageInactiveToggle"', 'id="manageInactiveWrap"', 'not in service tables (', 'setInactiveSection', 'inactiveToggle', 'inactiveWrap', 'inactiveCount'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "left over: {$gone}");
        }

        // What the count is made of: per branch, exactly the removed tables, and it follows Remove and Restore.
        $owner = $this->owner();

        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $notInService = fn () => collect($this->list($owner, $branch->id)->assertOk()->json('tables'))->where('status', 'not_in_service')->count();

            $baseline = $notInService();
            [$a, $b] = [$this->table($branch), $this->table($branch)];

            $this->assertSame($baseline, $notInService(), "{$label}: new tables are in service");

            $this->remove($owner, $a->id)->assertOk();
            $this->remove($owner, $b->id)->assertOk();
            $this->assertSame($baseline + 2, $notInService(), "{$label}: after two Removes");

            $this->restore($owner, $a->id)->assertOk();
            $this->assertSame($baseline + 1, $notInService(), "{$label}: after a Restore");

            $this->restore($owner, $b->id)->assertOk();
            $this->assertSame($baseline, $notInService(), "{$label}: after both Restores");
        }
    }

    /** No "Show not in service tables" control is drawn in any state; a branch with nothing out of service still reports zero. */
    public function test_no_inactive_toggle_remains_and_the_list_reports_zero_not_in_service(): void
    {
        $html = $this->managerPage();

        $this->assertStringNotContainsString('Show not in service tables', $html);
        $this->assertStringNotContainsString("'Hide' : 'Show'", $html);

        // The data behind it: a branch with every table in service, and a branch with none, report zero.
        $owner = $this->owner();
        $branch = $this->freshBranch();
        $this->table($branch);
        $this->table($branch);

        $this->assertCount(0, collect($this->list($owner, $branch->id)->json('tables'))->where('status', 'not_in_service'));
        $this->assertSame([], $this->list($owner, $this->freshBranch()->id)->json('tables'));
    }

    /** The endpoint still reports an occupied table with its reason; no card (or disabled card button) is drawn from it any more. */
    public function test_the_list_still_reports_occupied_with_its_reason_but_no_card_is_drawn(): void
    {
        $html = $this->managerPage();

        foreach (['.mt-btn[disabled]', "closest('.mt-btn')", 'mtNote', "' is occupied. Move or clear the customer first.'"] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "left over: {$gone}");
        }

        // The sentence is the one the server sends, per branch, only for the occupied table.
        $owner = $this->owner();

        foreach ($this->branchesUnderTest() as $branch) {
            $busy = $this->table($branch);
            $free = $this->table($branch);
            $this->seatAtCounter($busy, $owner);

            $rows = collect($this->list($owner, $branch->id)->assertOk()->json('tables'))->keyBy('id');

            $this->assertSame('occupied', $rows[$busy->id]['status']);
            $this->assertSame("Table {$busy->table_number} is occupied. Move or clear the customer first.", $rows[$busy->id]['blocked_reason']);
            $this->assertNull($rows[$free->id]['blocked_reason']);
        }
    }

    /** No table code in the page's markup, nor in the Move list's trash-icon builder that replaced the cards. */
    public function test_no_table_code_reaches_the_markup_or_the_move_list_trash_script(): void
    {
        $owner = $this->owner();

        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $rotated = $this->table($branch);
            $this->table($branch);
            $this->table($branch)->forceFill(['is_active' => false])->save();
            TableEntry::rotateCode($rotated, $owner->id);

            $html = $this->managerPage($branch);

            foreach (RestaurantTable::where('branch_id', $branch->id)->get() as $t) {
                $this->assertStringNotContainsString($t->code, $html, "{$label}: Table {$t->table_number}'s code is in the page");

                if ($t->previous_code) {
                    $this->assertStringNotContainsString($t->previous_code, $html, "{$label}: a previous code is in the page");
                }
            }
        }

        // The card builder is gone; the trash-icon builder that replaced it never reads a code field or builds a QR URL.
        $html = $this->managerPage();
        $this->assertStringNotContainsString('buildManageCard', $html);
        $trash = $this->scriptFunction($html, 'moveTargetWithDelete');

        foreach (['.code', 'previous_code', '.url', 'k=', 'qrUrl'] as $needle) {
            $this->assertStringNotContainsString($needle, $trash, "trash builder: {$needle}");
        }
    }

    /** Nothing on the page fetches the list any more: no loader, no stale-answer counter, no reload on branch change. */
    public function test_nothing_on_the_page_loads_the_list_any_more(): void
    {
        $html = $this->managerPage();

        foreach ([
            'manageSeq', 'loadManageTables', 'renderManageTables', 'keepRows', 'MANAGE_TABLES_ENDPOINT',
            route('admin.qr-generator.tables', [], false),
            "addEventListener('change', function () { loadManageTables(false); });",
        ] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "left over: {$gone}");
        }
    }

    public function test_the_route_is_get_only_role_gated_and_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.qr-generator.tables');

        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('role:admin,supervisor', $route->gatherMiddleware());
        $this->assertContains('throttle:admin-tables-occupancy', $route->gatherMiddleware());

        // The same gate as the actions its buttons post to.
        foreach (['admin.qr-generator.deactivate-table', 'admin.qr-generator.reactivate-table'] as $name) {
            $this->assertContains(
                'role:admin,supervisor',
                app('router')->getRoutes()->getByName($name)->gatherMiddleware(),
                $name
            );
        }
    }

    // ══════════ Remove and Restore from the list ══════════

    /** Remove moves the row to "not in service"; Restore brings it back. Nothing else changes and nothing is deleted. */
    public function test_remove_moves_the_row_to_not_in_service_and_restore_brings_it_back(): void
    {
        $owner = $this->owner();
        $branches = $this->branchesUnderTest();

        foreach ($branches as $branch) {
            $label = "branch #{$branch->id}";
            $table = $this->table($branch)->fresh();
            $neighbour = $this->table($branch);
            $rows = RestaurantTable::count();
            $before = $this->statuses($this->list($owner, $branch->id)->assertOk());

            $this->assertSame('in_service', $before[$table->id], $label);

            // Remove.
            $this->remove($owner, $table->id)->assertOk()->assertJson(['changed' => true, 'is_active' => false]);

            $after = $this->statuses($this->list($owner, $branch->id)->assertOk());
            $this->assertSame('not_in_service', $after[$table->id], "{$label}: the row did not move");
            $this->assertSame('in_service', $after[$neighbour->id], "{$label}: another table changed");
            $this->assertSame(array_keys($before), array_keys($after), "{$label}: the set of rows changed");

            $row = RestaurantTable::find($table->id);
            $this->assertNotNull($row, "{$label}: the row was deleted");
            $this->assertSame($table->code, $row->code, "{$label}: the code changed");
            $this->assertSame($rows, RestaurantTable::count(), "{$label}: the table count changed");

            // Restore.
            $this->restore($owner, $table->id)->assertOk()->assertJson(['changed' => true, 'is_active' => true]);

            $back = $this->statuses($this->list($owner, $branch->id)->assertOk());
            $this->assertSame('in_service', $back[$table->id], "{$label}: Restore did not bring it back");
            $this->assertSame($table->code, RestaurantTable::find($table->id)->code, "{$label}: the code changed");
            $this->assertSame($rows, RestaurantTable::count(), $label);
        }

        // Each real change was one audit line, by the existing action names:
        // one Remove then one Restore per branch, nothing for the list reads.
        $this->assertSame(
            array_merge(...array_fill(0, $branches->count(), ['deactivate', 'reactivate'])),
            collect($this->auditRecords())->pluck('action')->all()
        );
    }

    /** The button is off for a table with a customer — and if the click lands anyway, the server still refuses. */
    public function test_an_occupied_table_cannot_be_removed(): void
    {
        $owner = $this->owner();

        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            [$byCustomer, $byCounter] = [$this->table($branch), $this->table($branch)];

            $session = $this->seat('phone', $byCustomer);
            $this->seatAtCounter($byCounter, $owner);

            $response = $this->list($owner, $branch->id)->assertOk();
            $statuses = $this->statuses($response);

            $this->assertSame('occupied', $statuses[$byCustomer->id], "{$label}: customer at table");
            $this->assertSame('occupied', $statuses[$byCounter->id], "{$label}: counter order at table");

            // A stale list can still send the click; the endpoint says no.
            $this->remove($owner, $byCustomer->id)
                ->assertStatus(409)
                ->assertExactJson(['message' => "Table {$byCustomer->table_number} is occupied. Move or clear it first."]);
            $this->remove($owner, $byCounter->id)->assertStatus(409);

            $this->assertTrue($byCustomer->fresh()->is_active, "{$label}: an occupied table was removed");
            $this->assertTrue($byCounter->fresh()->is_active, $label);
            $this->assertSame($session->id, TableOccupancy::activeFor($byCustomer->branch_id, $byCustomer->table_number)?->id, "{$label}: the session was disturbed");
            $this->assertSame('occupied', $this->statuses($this->list($owner, $branch->id))[$byCustomer->id], "{$label}: the refusal changed the list");

            // Freeing the table (what the message says to do) makes it removable.
            TableOccupancy::releaseTable($branch->id, $byCustomer->table_number, $owner->id);

            $this->assertSame('in_service', $this->statuses($this->list($owner, $branch->id))[$byCustomer->id], $label);
            $this->remove($owner, $byCustomer->id)->assertOk();
            $this->assertSame('not_in_service', $this->statuses($this->list($owner, $branch->id))[$byCustomer->id], $label);
        }
    }

    /** A table somebody walked away from for the idle window is not "occupied" — the list sweeps like the Move list does. */
    public function test_a_session_that_went_silent_does_not_show_as_occupied(): void
    {
        $branch = $this->freshBranch();
        $table = $this->table($branch);
        $session = $this->seat('walked-off', $table);

        $this->assertSame('occupied', $this->statuses($this->list($this->owner(), $branch->id))[$table->id]);

        TableSession::whereKey($session->id)->update([
            'last_seen_at' => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES + 5),
        ]);

        $this->assertSame('in_service', $this->statuses($this->list($this->owner(), $branch->id))[$table->id]);
    }

    /** A table already out of service reads as such even if a counter order left a session at it: the only action left is Restore. */
    public function test_a_removed_table_with_a_leftover_session_still_reads_as_not_in_service(): void
    {
        $owner = $this->owner();
        $branch = $this->freshBranch();
        $table = $this->table($branch);

        $this->remove($owner, $table->id)->assertOk();
        // Staff manual dine-in orders do not check the registry (a known gap).
        $this->seatAtCounter($table, $owner);

        $row = collect($this->list($owner, $branch->id)->assertOk()->json('tables'))->firstWhere('id', $table->id);

        $this->assertSame('not_in_service', $row['status']);
        $this->assertNull($row['blocked_reason']);

        $this->restore($owner, $table->id)->assertOk();
        $this->assertSame('occupied', $this->statuses($this->list($owner, $branch->id))[$table->id]);
    }

    // ══════════ what a removed table does to everything else ══════════

    /** It leaves the Move table list at once, and the QR scan and the typed code both turn it away. */
    public function test_a_removed_table_is_gone_from_the_move_list_and_refused_by_the_customer_doors(): void
    {
        $owner = $this->owner();

        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            [$seat, $stays, $gone] = [$this->table($branch), $this->table($branch), $this->table($branch)];

            $session = $this->seat('party', $seat);

            $offered = collect($this->moveTargets($owner, $session->id)->assertOk()->json('tables'))->pluck('id')->all();
            $this->assertContains($gone->id, $offered, "{$label}: a table in service was not offered");

            // Remove from the list...
            $this->remove($owner, $gone->id)->assertOk();

            // ...and the very next Move list no longer offers it.
            $after = collect($this->moveTargets($owner, $session->id)->assertOk()->json('tables'))->pluck('id')->all();
            $this->assertNotContains($gone->id, $after, "{$label}: the removed table is still in the Move list");
            $this->assertContains($stays->id, $after, "{$label}: a table still in service was dropped");

            // A hand-built Move to it is refused as well.
            $this->asAdmin($owner)->postJson('/admin/tables/move', ['session_id' => $session->id, 'table_id' => $gone->id])
                ->assertStatus(422);
            $this->assertSame($session->id, TableOccupancy::activeFor($seat->branch_id, $seat->table_number)?->id, $label);

            // The phone camera opening the QR's URL.
            $this->on('camera');
            app('cache')->store()->flush();
            $this->scanQr($gone)->assertRedirect(route('customer.dineinqr'));
            $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, session('error'), "{$label}: QR scan");

            // The code typed off the standee.
            $this->on('typed');
            app('cache')->store()->flush();
            $this->from('/customer/dineinqr')->post('/customer/dineinqr', [
                'table_code' => $gone->code,
                'next'       => 'guest',
            ])->assertRedirect('/customer/dineinqr');
            $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, session('error'), "{$label}: typed code");

            $this->assertNull(TableOccupancy::activeFor($gone->branch_id, $gone->table_number), "{$label}: a refused door still seated somebody");
        }
    }

    /** Orders and help requests only ever stored the number as text; removing the table leaves all of it as it was. */
    public function test_orders_and_help_requests_keep_their_table_number(): void
    {
        $owner = $this->owner();

        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $table = $this->table($branch);

            $order = $this->orderAt($table, ['status' => 'completed']);
            $help = HelpRequest::create([
                'branch_id'    => $table->branch_id,
                'table_number' => $table->table_number,
                'status'       => 'resolved',
                'message'      => 'ManageTablesListTest',
                'requested_at' => now(),
            ]);
            $orders = Order::where('branch_id', $branch->id)->count();
            $helps = HelpRequest::where('branch_id', $branch->id)->count();

            $this->remove($owner, $table->id)->assertOk();

            $this->assertSame($table->table_number, $order->fresh()->table_number, "{$label}: the order lost its table");
            $this->assertSame('completed', $order->fresh()->status, $label);
            $this->assertSame($table->table_number, $help->fresh()->table_number, "{$label}: the help request lost its table");
            $this->assertSame($orders, Order::where('branch_id', $branch->id)->count(), "{$label}: an order was deleted");
            $this->assertSame($helps, HelpRequest::where('branch_id', $branch->id)->count(), "{$label}: a help request was deleted");

            // Still there after a Restore too.
            $this->restore($owner, $table->id)->assertOk();
            $this->assertSame($table->table_number, $order->fresh()->table_number, $label);
        }
    }

    /** Over repeated Remove / Restore no DELETE is ever issued against the registry, and the count never moves. */
    public function test_no_row_is_ever_deleted(): void
    {
        $owner = $this->owner();
        $deletes = [];

        DB::connection()->beforeExecuting(function ($sql) use (&$deletes) {
            if (preg_match('/^\s*(delete|truncate|drop)\b/i', $sql) && stripos($sql, 'restaurant_tables') !== false) {
                $deletes[] = $sql;
            }
        });

        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $table = $this->table($branch);
            $rows = RestaurantTable::count();
            $code = $table->code;

            for ($cycle = 1; $cycle <= 3; $cycle++) {
                $this->remove($owner, $table->id)->assertOk();
                $this->list($owner, $branch->id)->assertOk();
                $this->assertNotNull(RestaurantTable::find($table->id), "{$label}: cycle {$cycle}: deleted by Remove");

                $this->restore($owner, $table->id)->assertOk();
                $this->assertNotNull(RestaurantTable::find($table->id), "{$label}: cycle {$cycle}: deleted by Restore");
            }

            // Removing a table that is already out of service changes nothing and deletes nothing.
            $this->remove($owner, $table->id)->assertOk();
            $this->remove($owner, $table->id)->assertOk()->assertJson(['changed' => false]);

            $this->assertSame($rows, RestaurantTable::count(), "{$label}: the table count changed");
            $this->assertSame($code, RestaurantTable::find($table->id)->code, "{$label}: the code changed");
        }

        $this->assertSame([], $deletes, 'a DELETE / TRUNCATE / DROP was issued against restaurant_tables');
    }

    // ══════════ the reason this exists ══════════

    /**
     * A table typed in wrongly is registered by pressing Generate; this removes it from the list, with
     * no card ever printed, and proves nobody can reach it afterwards.
     */
    public function test_a_typo_table_can_be_removed_end_to_end(): void
    {
        foreach ($this->branchesUnderTest() as $branch) {
            $label = "branch #{$branch->id}";
            $supervisor = $this->portalUser('supervisor', $branch->id);
            $intended = $this->table($branch);
            $party = $this->table($branch);
            $session = $this->seat('party', $party);

            // 1. The typo: a random number is entered and Generate pressed — that registers it.
            do {
                $typo = (string) random_int(1000000, 9999999);
            } while (RestaurantTable::where('table_number', $typo)->exists());

            $card = $this->asAdmin($supervisor)
                ->getJson('/admin/qr-generator/table-card?branch_id=' . $branch->id . '&table_number=' . $typo)
                ->assertOk()
                ->assertJson(['table_number' => $typo, 'is_active' => true]);
            $typoId = $card->json('table_id');
            $typoCode = $card->json('code');

            // 2. It is on the list, free, and removable.
            $row = collect($this->list($supervisor, $branch->id)->assertOk()->json('tables'))->firstWhere('id', $typoId);
            $this->assertSame(['id' => $typoId, 'table_number' => $typo, 'status' => 'in_service', 'blocked_reason' => null], $row, $label);
            $this->assertContains($typoId, collect($this->moveTargets($supervisor, $session->id)->json('tables'))->pluck('id')->all(), "{$label}: not offered before");

            // 3. Remove it from the list — no card was printed.
            $this->remove($supervisor, $typoId)->assertOk()->assertJson(['is_active' => false, 'table_number' => $typo]);

            // 4. Not in service on the list; gone from Move; invisible to customers.
            $this->assertSame('not_in_service', $this->statuses($this->list($supervisor, $branch->id))[$typoId], $label);
            $this->assertNotContains($typoId, collect($this->moveTargets($supervisor, $session->id)->json('tables'))->pluck('id')->all(), "{$label}: still in Move");

            $this->on('customer');
            app('cache')->store()->flush();
            $this->get('/customer/menu?branch_id=' . $branch->id . '&table=' . $typo . '&k=' . $typoCode)
                ->assertRedirect(route('customer.dineinqr'));
            $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, session('error'), "{$label}: QR");

            $this->on('customer-typed');
            app('cache')->store()->flush();
            $this->from('/customer/dineinqr')->post('/customer/dineinqr', ['table_code' => $typoCode, 'next' => 'guest'])
                ->assertRedirect('/customer/dineinqr');
            $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, session('error'), "{$label}: typed code");
            $this->assertNull(TableOccupancy::activeFor($branch->id, $typo), $label);

            // 5. Nothing was deleted, and the table that was MEANT is untouched and still works.
            $this->assertNotNull(RestaurantTable::find($typoId), "{$label}: the typo row was deleted");
            $this->assertTrue($intended->fresh()->is_active, $label);
            $this->assertSame($session->id, TableOccupancy::activeFor($branch->id, $party->table_number)?->id, $label);

            $this->on('guest-at-intended');
            app('cache')->store()->flush();
            $this->scanQr($intended)->assertOk();
            $this->assertNotNull(TableOccupancy::activeFor($branch->id, $intended->table_number), "{$label}: the intended table stopped working");

            // 6. Asking for its card again says "not in service" (the number stays reserved), and Restore undoes all of it.
            $this->asAdmin($supervisor)
                ->getJson('/admin/qr-generator/table-card?branch_id=' . $branch->id . '&table_number=' . $typo)
                ->assertOk()
                ->assertJson(['table_id' => $typoId, 'code' => $typoCode, 'is_active' => false]);

            $this->restore($supervisor, $typoId)->assertOk();
            $this->assertSame('in_service', $this->statuses($this->list($supervisor, $branch->id))[$typoId], $label);
        }
    }
}

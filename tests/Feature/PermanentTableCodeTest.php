<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use App\Providers\RateLimitServiceProvider;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The permanent per-table code: the thing printed on the acrylic standee that
 * never expires and is never used up.
 *
 * WHAT THIS SUITE IS REALLY FOR
 * -----------------------------
 * A permanent code is a permanent credential, and that is only defensible
 * because of the controls around it. So this file is not mainly about "does the
 * code work" — it is about whether each of those controls actually holds:
 *
 *   - a second and third person at one table JOIN rather than being refused
 *   - a session ends by itself, on order completion and on inactivity
 *   - session creation is rate limited, with a readable message
 *   - one table's code can be regenerated WITHOUT touching any other
 *
 * plus the part that must survive the change: every malformed-payload refusal
 * the scanner suite already required is still enforced when the same rubbish
 * arrives at the permanent-code door instead.
 *
 * ISOLATION
 * ---------
 * Every assertion is pinned to the row or element under test — by primary key,
 * by active_lock, or by the suite's own table numbers. Nothing here does a bare
 * assertSee() of a code or a number that another row on the page could
 * legitimately carry.
 *
 * The tables this suite creates are numbered with the PT_PREFIX below so the
 * bounded cleanup can identify them without an id-only bound.
 */
class PermanentTableCodeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Table numbers this suite invents. Distinctive on purpose: nothing in the
     * live data uses a P-prefixed table number, so a row carrying one is
     * unambiguously this suite's.
     */
    private const PT_PREFIX = 'PT';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLimiters();
    }

    protected function tearDown(): void
    {
        $this->clearLimiters();
        parent::tearDown();
    }

    private function clearLimiters(): void
    {
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
        RateLimiter::clear('table-session|ip:127.0.0.1');
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->firstOrFail();
    }

    // ── entry doors ──

    private function typeCode(string $code)
    {
        return $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', ['table_code' => $code, 'next' => 'guest']);
    }

    private function scanUrl(RestaurantTable $table)
    {
        return $this->from('/customer/dineinqr')->post('/customer/dineinqr', [
            'tableData' => $table->qrUrl(),
            'next'      => 'guest',
        ]);
    }

    /** A registered table with a permanent code, owned by this suite. */
    private function table(string $suffix, int $branchId = 1): RestaurantTable
    {
        return TableEntry::findOrRegister($branchId, self::PT_PREFIX . strtoupper($suffix));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Goal 1 — the code is permanent and is not consumed
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_permanent_code_uses_only_unconfusable_characters(): void
    {
        $table = $this->table('A1');

        // No 0/O and no 1/I/L — the characters people actually misread off a
        // card. Exactly the alphabet TableEntry documents.
        $this->assertMatchesRegularExpression(
            '/^[' . TableEntry::ALPHABET . ']{' . TableEntry::CODE_LENGTH . '}$/',
            $table->code
        );

        $this->assertStringNotContainsString('0', $table->code);
        $this->assertStringNotContainsString('O', $table->code);
        $this->assertStringNotContainsString('1', $table->code);
        $this->assertStringNotContainsString('I', $table->code);
        $this->assertStringNotContainsString('L', $table->code);
    }

    public function test_the_code_is_case_insensitive_on_entry(): void
    {
        $table = $this->table('A2');

        // Typed in lower case, with the spacing a person adds by accident.
        $this->typeCode(' ' . strtolower($table->code) . ' ')
            ->assertRedirect(route('customer.menu'));

        $this->assertSame($table->table_number, session('table_number'));
        $this->assertSame($table->branch_id, session('branch_id'));
    }

    /**
     * THE POINT OF THE WHOLE CHANGE. The old code died after ten minutes; this
     * one has to work long after that window would have closed.
     */
    public function test_a_permanent_code_still_works_long_after_the_old_expiry_window(): void
    {
        $table = $this->table('A3');

        // Well past the ten minutes a staff-issued code used to last, and past
        // the inactivity timeout too.
        $this->travel(TableOccupancy::INACTIVITY_MINUTES + 600)->minutes();

        $this->typeCode($table->code)->assertRedirect(route('customer.menu'));
        $this->assertSame($table->table_number, session('table_number'));

        // And the code itself is untouched by having been used.
        $this->assertSame($table->code, $table->fresh()->code);

        $this->travelBack();
    }

    public function test_the_same_code_validates_a_second_and_a_third_time(): void
    {
        $table = $this->table('A4');

        foreach (['first', 'second', 'third'] as $attempt) {
            $this->flushSession();
            RateLimiter::clear('table-code:127.0.0.1');

            $this->typeCode($table->code)
                ->assertRedirect(route('customer.menu'), "the $attempt use was refused");

            $this->assertSame(
                $table->table_number,
                session('table_number'),
                "the $attempt use did not seat the customer"
            );
        }

        // Not spent, not rotated, not marked.
        $this->assertSame($table->code, $table->fresh()->code);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Goal 3 — scanned URL and typed code reach the same place
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_scanned_url_and_the_typed_code_produce_the_identical_session(): void
    {
        $table = $this->table('B1');

        // Door 1: the QR URL.
        $this->scanUrl($table)->assertRedirect(route('customer.menu'));

        $viaScan = TableOccupancy::activeFor($table->branch_id, $table->table_number);
        $this->assertNotNull($viaScan);
        $this->assertSame($table->table_number, session('table_number'));
        $this->assertSame($table->branch_id, session('branch_id'));
        $this->assertSame('dine_in', session('order_type'));

        // Door 2: a different person at the same table types the code.
        $this->flushSession();
        RateLimiter::clear('table-code:127.0.0.1');

        $this->typeCode($table->code)->assertRedirect(route('customer.menu'));

        $viaCode = TableOccupancy::activeFor($table->branch_id, $table->table_number);

        // The IDENTICAL session row, by primary key — not merely a similar one.
        $this->assertSame($viaScan->id, $viaCode->id);
        $this->assertSame($table->table_number, session('table_number'));
        $this->assertSame($table->branch_id, session('branch_id'));
        $this->assertSame('dine_in', session('order_type'));

        $this->assertSame(
            1,
            TableSession::where('active_lock', $table->branch_id . ':' . $table->table_number)->count()
        );
    }

    /**
     * Ported from the scanner suite. table_id still arrives from a URL a
     * stranger can edit, so losing the scanner does NOT lose the attack
     * surface — every one of these must still be refused, in a sentence.
     */
    public static function malformedPairs(): array
    {
        return [
            'branch id as array'      => [['1'], '5'],
            'branch id as nested array' => [[[1]], '5'],
            'table as array'          => [1, ['5']],
            'sql-ish branch id'       => ['1 OR 1=1', '5'],
            'branch id with spaces'   => [' 1', '5'],
            'float branch id'         => ['1.9', '5'],
            'negative branch id'      => ['-1', '5'],
            'zero branch id'          => ['0', '5'],
            'hex branch id'           => ['0x1', '5'],
            'nonexistent branch'      => ['999999', '5'],
            'empty branch id'         => ['', '5'],
            'null branch id'          => [null, '5'],
            'table with markup'       => [1, '<script>alert(1)</script>'],
            'table with punctuation'  => [1, '5;DROP TABLE orders'],
            'table too long'          => [1, '99999999999999999999'],
            'table non-ascii'         => [1, '５'],
            'empty table'             => [1, ''],
            'null table'              => [1, null],
            'table with whitespace only' => [1, '   '],
        ];
    }

    /**
     * @dataProvider malformedPairs
     */
    public function test_every_malformed_pair_is_refused_with_a_readable_sentence($branchId, $tableNumber): void
    {
        $result = TableEntry::validate($branchId, $tableNumber);

        $this->assertFalse($result['ok'] ?? true, 'a malformed pair was accepted');
        $this->assertArrayNotHasKey('branch', $result, 'a refusal must not resolve a branch');
        $this->assertArrayNotHasKey('table_number', $result, 'a refusal must not resolve a table');

        // A readable sentence, not a code and not an empty string.
        $error = $result['error'] ?? null;
        $this->assertIsString($error);
        $this->assertNotSame('', trim($error));
        $this->assertGreaterThan(20, strlen($error), 'the refusal must be a sentence a customer can act on');
        $this->assertStringEndsWith('.', trim($error));
    }

    /**
     * The same rubbish arriving through the live HTTP door: never a 500, never
     * a blank page, never a session.
     */
    public function test_malformed_url_parameters_are_refused_over_http_without_a_500(): void
    {
        foreach ([
            'branch_id=999999&table=5',
            'branch_id=abc&table=5',
            'branch_id=-1&table=5',
            'branch_id=0&table=5',
            'branch_id=0x1&table=5',
            'branch_id=1.9&table=5',
            'branch_id[]=1&table=5',
            'branch_id=1&table[]=5',
            'branch_id=1&table=' . str_repeat('9', 200),
            'branch_id=1&table=' . urlencode('<script>'),
        ] as $query) {
            $this->flushSession();

            $res = $this->get('/customer/menu?' . $query);

            $res->assertStatus(302);
            $res->assertRedirect(route('customer.dineinqr'));

            $error = session('error');
            $this->assertIsString($error, "no readable error for: $query");
            $this->assertNotSame('', trim($error), "empty error for: $query");

            $this->assertNull(session('table_number'), "table_number leaked for: $query");
            $this->assertNull(session('branch_id'), "branch_id leaked for: $query");
            $this->assertNotSame('dine_in', session('order_type'), "order_type leaked for: $query");
        }
    }

    public function test_a_code_for_a_closed_branch_is_refused_with_the_reason(): void
    {
        $table = $this->table('B2', 2);

        DB::table('branches')->where('id', 2)->update(['is_active' => 0]);

        $this->typeCode($table->code)->assertRedirect('/customer/dineinqr');

        $this->assertSame(TableEntry::ERR_CLOSED_BRANCH, session('error'));
        $this->assertNull(session('table_number'));
    }

    public function test_a_table_taken_out_of_service_is_refused_with_the_reason(): void
    {
        $table = $this->table('B3');

        $table->forceFill(['is_active' => false])->save();

        $this->typeCode($table->code)->assertRedirect('/customer/dineinqr');

        $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, session('error'));
        $this->assertNull(session('table_number'));
    }

    public function test_an_unknown_code_is_refused_without_revealing_anything(): void
    {
        // Well-formed shape, never allocated.
        $this->typeCode('ZZZZZZZZ')->assertRedirect('/customer/dineinqr');

        $error = (string) session('error');
        $this->assertNotSame('', $error);
        $this->assertNull(session('table_number'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Goal 4a — occupancy is shared
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_second_and_third_person_at_one_table_join_the_session(): void
    {
        $table = $this->table('C1');

        $this->scanUrl($table)->assertRedirect(route('customer.menu'));
        $opened = TableOccupancy::activeFor($table->branch_id, $table->table_number);
        $this->assertNotNull($opened);

        foreach (['second', 'third'] as $who) {
            $this->flushSession();
            $this->clearLimiters();

            // One types the code, one scans — both doors, both must join.
            $response = $who === 'second' ? $this->typeCode($table->code) : $this->scanUrl($table);

            $response->assertRedirect(route('customer.menu'), "the $who person was refused");

            $this->assertNull(session('error'), "the $who person got an error");
            $this->assertSame($table->table_number, session('table_number'));

            $this->assertSame(
                $opened->id,
                TableOccupancy::activeFor($table->branch_id, $table->table_number)->id,
                "the $who person did not join the existing session"
            );
        }

        $this->assertSame(
            1,
            TableSession::where('active_lock', $table->branch_id . ':' . $table->table_number)->count()
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // Goal 4b — a session ends by itself
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_session_auto_clears_when_its_order_completes(): void
    {
        $table = $this->table('D1');

        $this->scanUrl($table);
        $session = TableOccupancy::activeFor($table->branch_id, $table->table_number);
        $this->assertNotNull($session);

        $order = Order::create([
            'order_number' => 'PT-' . substr(uniqid(), -8),
            'branch_id'    => $table->branch_id,
            'type'         => 'dine_in',
            'table_number' => $table->table_number,
            'status'       => 'pending',
            'subtotal'     => 100,
            'total'        => 100,
        ]);

        TableOccupancy::attachOrder($order);
        $this->assertSame($order->id, $session->fresh()->order_id);

        $order->status = 'completed';
        $order->save();

        $this->assertNull(
            TableOccupancy::activeFor($table->branch_id, $table->table_number),
            'completing the order must free the table'
        );

        // Asserted on THIS row by id.
        $released = TableSession::find($session->id);
        $this->assertNull($released->active_lock);
        $this->assertSame('order_completed', $released->release_reason);
    }

    public function test_a_session_auto_clears_after_the_inactivity_period(): void
    {
        $table = $this->table('D2');

        $this->scanUrl($table);
        $session = TableOccupancy::activeFor($table->branch_id, $table->table_number);
        $this->assertNotNull($session);

        // Silent for longer than the timeout, and never ordered.
        DB::table('table_sessions')->where('id', $session->id)->update([
            'last_seen_at' => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES + 1),
            'created_at'   => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES + 1),
        ]);

        $this->assertGreaterThanOrEqual(1, TableOccupancy::sweepIdle());

        $this->assertNull(TableOccupancy::activeFor($table->branch_id, $table->table_number));

        $released = TableSession::find($session->id);
        $this->assertNull($released->active_lock);
        $this->assertSame('abandoned', $released->release_reason);
        $this->assertNotNull($released->released_at);
    }

    public function test_the_staff_panel_sweeps_idle_sessions_without_anyone_pressing_anything(): void
    {
        $table = $this->table('D3');

        $this->scanUrl($table);
        $session = TableOccupancy::activeFor($table->branch_id, $table->table_number);

        DB::table('table_sessions')->where('id', $session->id)->update([
            'last_seen_at' => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES + 1),
            'created_at'   => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES + 1),
        ]);

        // Just loading the panel is enough.
        $this->actingAs($this->admin(), 'admin')
            ->getJson('/admin/tables/occupancy')
            ->assertOk();

        $this->assertNull(TableSession::find($session->id)->active_lock);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Goal 4c — session creation is rate limited
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A LOOP AGAINST THE PERMANENT CODE HITS A WALL, AND THE WALL IS POLITE.
     *
     * The per-IP ceiling is what is exercised here rather than the per-party
     * one. That is deliberate and it is the honest test: Laravel's test client
     * starts a NEW session on every request (no cookie jar), so the per-party
     * counter can never accumulate in a feature test — it would look like it
     * was being tested while actually asserting nothing. The per-IP ceiling is
     * the limit that genuinely applies to a script, which is the threat this
     * control exists for.
     *
     * A junk code is used so the loop costs nothing but still passes through
     * the throttle middleware, which runs before the controller.
     */
    public function test_a_burst_of_session_creations_is_refused_with_a_friendly_message(): void
    {
        $table = $this->table('E1');

        for ($i = 0; $i < RateLimitServiceProvider::TABLE_SESSION_PER_IP; $i++) {
            $this->post('/customer/dineinqr', ['table_code' => 'ZZZZZZZZ', 'next' => 'guest']);
        }

        // Even a genuine, valid permanent code is refused once the ceiling is
        // reached — otherwise the limit would protect nothing.
        $response = $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', ['table_code' => $table->code, 'next' => 'guest']);

        // The friendly wrapper, not a raw 429 page: a redirect back to where
        // they were, carrying a readable sentence, with the marker header.
        $response->assertStatus(302);
        $response->assertHeader('X-RateLimit-Rejected', '1');

        $errors = $response->baseResponse->getSession()->get('errors');
        $this->assertNotNull($errors, 'the throttle must leave a readable message');

        $message = (string) $errors->first('error');
        $this->assertStringContainsString('too quickly', $message);
        $this->assertStringNotContainsString('429', $message);
        $this->assertStringNotContainsString('Too Many', $message);

        // And it did not seat anyone.
        $this->assertNull(
            TableOccupancy::activeFor($table->branch_id, $table->table_number),
            'a throttled attempt must not open a session'
        );
    }

    /**
     * The third door — a phone's own camera app opening the QR URL — is limited
     * too, or the limit on the other two would just be a detour.
     *
     * The counter is pre-loaded to its ceiling rather than looped, because this
     * door's keys are plain, documented strings owned by
     * AuthController::tableSessionRateLimit() rather than a framework-hashed
     * name. Setting the counter directly asserts against the exact production
     * key.
     */
    public function test_the_url_landing_door_is_rate_limited_too(): void
    {
        $table = $this->table('E2');
        $url = '/customer/menu?branch_id=' . $table->branch_id
            . '&table=' . $table->table_number . '&k=' . $table->code;

        // One ordinary arrival works.
        $this->get($url)->assertOk();

        RateLimiter::clear('table-session|ip:127.0.0.1');

        for ($i = 0; $i < RateLimitServiceProvider::TABLE_SESSION_PER_IP; $i++) {
            RateLimiter::hit('table-session|ip:127.0.0.1', 60);
        }

        $this->flushSession();

        $this->get($url)->assertRedirect(route('customer.dineinqr'));

        $this->assertStringContainsString('too quickly', (string) session('error'));
        $this->assertNull(session('table_number'), 'a throttled arrival must not be seated');
    }

    // ══════════════════════════════════════════════════════════════════════
    // Goal 4d — regenerating ONE table's code
    // ══════════════════════════════════════════════════════════════════════

    public function test_regenerating_one_code_leaves_every_other_table_untouched(): void
    {
        $target = $this->table('F1');
        $neighbourA = $this->table('F2');
        $neighbourB = $this->table('F3', 2);

        $oldCode = $target->code;

        // A snapshot of EVERY other table's code, so "only one changed" is an
        // assertion about the whole table rather than about two neighbours.
        $before = RestaurantTable::where('id', '!=', $target->id)
            ->pluck('code', 'id')
            ->all();

        $res = $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/qr-generator/regenerate-code', ['table_id' => $target->id]);

        $res->assertOk();

        $newCode = $res->json('code');

        $this->assertNotSame($oldCode, $newCode, 'the code must actually change');
        $this->assertMatchesRegularExpression(
            '/^[' . TableEntry::ALPHABET . ']{' . TableEntry::CODE_LENGTH . '}$/',
            $newCode
        );

        // The target row, by primary key.
        $target = $target->fresh();
        $this->assertSame($newCode, $target->code);
        $this->assertSame($oldCode, $target->previous_code, 'the replaced code must be kept for audit');
        $this->assertNotNull($target->code_rotated_at);
        $this->assertSame($this->admin()->id, (int) $target->code_rotated_by);

        // Every other row, unchanged.
        $after = RestaurantTable::where('id', '!=', $target->id)->pluck('code', 'id')->all();
        $this->assertSame($before, $after, 'no other table may have its code rotated');

        $this->assertSame($neighbourA->code, $neighbourA->fresh()->code);
        $this->assertSame($neighbourB->code, $neighbourB->fresh()->code);
    }

    public function test_the_old_code_stops_working_and_the_new_one_starts(): void
    {
        $table = $this->table('F4');
        $oldCode = $table->code;

        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/qr-generator/regenerate-code', ['table_id' => $table->id])
            ->assertOk();

        $newCode = $table->fresh()->code;

        // The old code is dead.
        $this->flushSession();
        $this->clearLimiters();
        $this->typeCode($oldCode)->assertRedirect('/customer/dineinqr');
        $this->assertNull(session('table_number'), 'a regenerated-away code must not open a table');

        // The new one works.
        $this->flushSession();
        $this->clearLimiters();
        $this->typeCode($newCode)->assertRedirect(route('customer.menu'));
        $this->assertSame($table->table_number, session('table_number'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // The code in the QR — the QR's `k` guards the camera door too
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A phone's own camera app opens the URL the QR encodes. Simulate that by
     * opening qrUrl() verbatim.
     */
    private function openQr(RestaurantTable $table, ?string $overrideK = null)
    {
        $url = $table->qrUrl();

        if ($overrideK !== null) {
            $url = preg_replace('/([?&]k=)[^&]*/', '$1' . rawurlencode($overrideK), $url);
        }

        return $this->from('/customer/dineinqr')->get($url);
    }

    public function test_the_qr_url_carries_the_tables_permanent_code(): void
    {
        $table = $this->table('K1');

        parse_str(parse_url($table->qrUrl(), PHP_URL_QUERY), $params);

        $this->assertSame($table->code, $params['k'] ?? null, 'the QR must carry the code as k');
        $this->assertSame((string) $table->branch_id, $params['branch_id'] ?? null);
        $this->assertSame($table->table_number, $params['table'] ?? null);
    }

    public function test_the_matching_k_opens_the_dine_in_session(): void
    {
        $table = $this->table('K2');

        $this->openQr($table)->assertOk();

        $this->assertSame($table->table_number, session('table_number'));
        $this->assertSame($table->branch_id, session('branch_id'));
        $this->assertSame('dine_in', session('order_type'));
        $this->assertNull(session('error'));
    }

    public function test_a_missing_k_lands_on_the_code_entry_page_not_an_error(): void
    {
        $table = $this->table('K3');

        $bare = '/customer/menu?branch_id=' . $table->branch_id . '&table=' . $table->table_number;

        $res = $this->from('/customer/dineinqr')->get($bare);

        $res->assertRedirect(route('customer.dineinqr'));
        $this->assertSame(TableEntry::ERR_QR_STALE, session('error'));
        $this->assertNull(session('table_number'), 'a k-less QR URL must not seat anyone');
    }

    public function test_a_stale_k_lands_on_the_code_entry_page_not_an_error(): void
    {
        $table = $this->table('K4');
        $staleUrl = $table->qrUrl();          // photographed now

        // Admin regenerates: the photo's k is now the OLD code.
        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/qr-generator/regenerate-code', ['table_id' => $table->id])
            ->assertOk();

        $this->flushSession();
        $this->clearLimiters();

        $res = $this->from('/customer/dineinqr')->get($staleUrl);

        $res->assertRedirect(route('customer.dineinqr'));
        $res->assertStatus(302);
        $this->assertSame(TableEntry::ERR_QR_STALE, session('error'));
        $this->assertNull(session('table_number'));

        // The freshly-drawn QR does work.
        $this->flushSession();
        $this->clearLimiters();
        $this->openQr($table->fresh())->assertOk();
        $this->assertSame($table->table_number, session('table_number'));
    }

    public function test_previous_code_is_not_accepted_in_the_qr(): void
    {
        $table = $this->table('K5');
        $oldCode = $table->code;

        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/qr-generator/regenerate-code', ['table_id' => $table->id])
            ->assertOk();

        $table = $table->fresh();
        $this->assertSame($oldCode, $table->previous_code, 'precondition: old code kept for audit');

        $this->flushSession();
        $this->clearLimiters();

        // k set to the audit-only previous_code — must be refused, not honoured.
        $this->openQr($table, $oldCode)->assertRedirect(route('customer.dineinqr'));
        $this->assertNull(session('table_number'), 'previous_code must never open a door');
    }

    public function test_rotating_a_code_changes_the_qr_url(): void
    {
        $table = $this->table('K6');
        $before = $table->qrUrl();

        $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/qr-generator/regenerate-code', ['table_id' => $table->id])
            ->assertOk();

        $after = $table->fresh()->qrUrl();

        $this->assertNotSame($before, $after, 'the QR the card draws must change when the code rotates');

        // Only k changed; branch and table are the same physical table.
        parse_str(parse_url($before, PHP_URL_QUERY), $b);
        parse_str(parse_url($after, PHP_URL_QUERY), $a);
        $this->assertSame($b['branch_id'], $a['branch_id']);
        $this->assertSame($b['table'], $a['table']);
        $this->assertNotSame($b['k'], $a['k']);
    }

    /**
     * THE CONTRACT THE "QR still encodes the old code" BUG BROKE.
     *
     * regenerateTableCode() returns a `url`, and the generator page redraws the
     * printable QR from it WITHOUT the admin pressing Generate again. That `url`
     * must be exactly what RestaurantTable::qrUrl() now returns for the rotated
     * code — same shape as qrTableCard() hands back on a fresh generate — so the
     * redrawn QR encodes the new &k= and not the dead one.
     *
     * The client-side half (the blade assigning currentCard.url = data.url
     * before calling renderQrCanvas) cannot be exercised in a feature test; it
     * is pinned by test_the_generator_redraws_the_qr_from_the_refreshed_url
     * below and proven end-to-end by decoding the rendered canvas in a browser.
     */
    public function test_the_regenerate_response_url_is_qrUrl_for_the_new_code(): void
    {
        $table = $this->table('K8');

        $res = $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/qr-generator/regenerate-code', ['table_id' => $table->id])
            ->assertOk();

        $newCode = $table->fresh()->code;
        $oldCode = $res->json('previous_code');

        // The payload the card redraws its QR from.
        $this->assertSame($table->fresh()->qrUrl(), $res->json('url'));

        // It carries the new code as k, and the dead one is nowhere in it.
        parse_str(parse_url($res->json('url'), PHP_URL_QUERY), $params);
        $this->assertSame($newCode, $params['k'] ?? null);
        $this->assertStringNotContainsString($oldCode, $res->json('url'));

        // Same fields, same shape as a fresh generate returns.
        $fresh = $this->actingAs($this->admin(), 'admin')->getJson(
            '/admin/qr-generator/table-card?branch_id=' . $table->branch_id
                . '&table_number=' . $table->table_number
        )->assertOk();
        $this->assertSame($fresh->json('url'), $res->json('url'));
        $this->assertSame($fresh->json('code'), $res->json('code'));
    }

    /**
     * The generator page must redraw the QR from the url the regenerate
     * response returns, not from the stale currentCard.url it held before the
     * rotation. Asserted against the blade source because the behaviour is pure
     * client-side state: regenerateCurrentCode() has to copy data.url onto
     * currentCard before it hands the url to renderQrCanvas().
     *
     * Written so it would have FAILED while the bug was live — the old handler
     * assigned only currentCard.code and rendered from the untouched
     * currentCard.url.
     */
    public function test_the_generator_redraws_the_qr_from_the_refreshed_url(): void
    {
        $blade = file_get_contents(resource_path('views/admin/qr-generator.blade.php'));

        $start = strpos($blade, 'async function regenerateCurrentCode()');
        $this->assertNotFalse($start, 'regenerateCurrentCode() not found');
        $body = substr($blade, $start, 4000);

        // The url is refreshed from the response...
        $refreshPos = strpos($body, 'currentCard.url = data.url');
        $this->assertNotFalse($refreshPos, 'currentCard.url must be reassigned from the regenerate response');

        // ...before the QR is rendered from it.
        $renderPos = strpos($body, 'renderQrCanvas(currentCard.url)');
        $this->assertNotFalse($renderPos, 'the QR must be redrawn from currentCard.url');
        $this->assertLessThan($renderPos, $refreshPos, 'currentCard.url must be refreshed BEFORE the QR is redrawn');
    }

    /**
     * The regenerate response's success message shows the transition so staff
     * can see something happened and know to reprint.
     */
    public function test_the_regenerate_message_shows_the_code_transition(): void
    {
        $table = $this->table('K7');
        $oldCode = $table->code;

        $res = $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/qr-generator/regenerate-code', ['table_id' => $table->id])
            ->assertOk();

        $message = $res->json('message');
        $newCode = $res->json('code');

        $this->assertStringContainsString($oldCode, $message);
        $this->assertStringContainsString($newCode, $message);
        $this->assertStringContainsString('reprint', $message);
    }

    public function test_regenerating_requires_an_admin_session(): void
    {
        $table = $this->table('F5');
        $code = $table->code;

        // The admin area bounces to the login screen rather than answering 401
        // — the established convention for this portal, see RoleMiddleware.
        $this->postJson('/admin/qr-generator/regenerate-code', ['table_id' => $table->id])
            ->assertRedirect(route('admin.login'));

        $this->assertSame($code, $table->fresh()->code, 'an unauthenticated request must not rotate a code');
    }

    public function test_a_staff_account_cannot_regenerate_a_code(): void
    {
        $staff = User::where('role', 'staff')->first();

        if (!$staff) {
            $this->markTestSkipped('no staff account to test with');
        }

        $table = $this->table('F6');
        $code = $table->code;

        // RoleMiddleware bounces a wrong-role staff account to the admin home
        // with a message rather than a raw 403 — this portal's convention.
        $this->actingAs($staff, 'admin')
            ->postJson('/admin/qr-generator/regenerate-code', ['table_id' => $table->id])
            ->assertRedirect(route('admin.home'));

        $this->assertSame($code, $table->fresh()->code, 'staff must not be able to rotate a code');
    }

    public function test_regenerate_validates_its_input(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/qr-generator/regenerate-code', [])
            ->assertStatus(422);

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/qr-generator/regenerate-code', ['table_id' => 999999999])
            ->assertStatus(422);

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/qr-generator/regenerate-code', ['table_id' => ['1']])
            ->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Goal 5 — the admin dashboard
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The middle "Tables & Permanent Codes" list was removed to keep the page
     * to just the card generator and the occupied-tables panel. No full list of
     * codes is rendered any more, and the per-row Regenerate button is gone
     * with it (replaced by the single one beside the card preview).
     */
    public function test_the_admin_page_no_longer_lists_every_table_with_its_code(): void
    {
        $table = $this->table('G1');

        $res = $this->actingAs($this->admin(), 'admin')->get('/admin/qr-generator');

        $res->assertOk();
        $html = $res->getContent();

        $this->assertStringNotContainsString('Tables &amp; Permanent Codes', $html);
        $this->assertStringNotContainsString('data-table-row="' . $table->id . '"', $html);
        $this->assertStringNotContainsString('data-code-for=', $html);
        $this->assertStringNotContainsString('regen-code-btn', $html);
    }

    /**
     * The staff-issued OTP fallback is gone completely: no expiry timer, no
     * "Issue a Staff Code" panel, no route to mint one.
     */
    public function test_the_issue_a_staff_code_panel_is_gone(): void
    {
        $res = $this->actingAs($this->admin(), 'admin')->get('/admin/qr-generator');

        $res->assertOk();
        $html = $res->getContent();

        $this->assertStringNotContainsString('Issue a Staff Code', $html);
        $this->assertStringNotContainsString('issueStaffCode', $html);
        $this->assertStringNotContainsString('codeCountdown', $html);
        $this->assertStringNotContainsString('expires after', $html);

        $this->postJson('/admin/qr-generator/table-code', ['branch_id' => 1, 'table_number' => '9'])
            ->assertStatus(404);
    }

    /** The two sections that remain, in order, and nothing else. */
    public function test_the_admin_page_has_exactly_generate_and_occupied_sections(): void
    {
        $res = $this->actingAs($this->admin(), 'admin')->get('/admin/qr-generator');

        $res->assertOk();
        $html = $res->getContent();

        // Each marker is the section's own body copy, not its heading — a
        // heading string ("Occupied Tables") also appears earlier as a CSS
        // comment, which would corrupt the ordering check.
        $generatePos = strpos($html, 'Generate the printable card for each table');
        $occupiedPos = strpos($html, 'Everyone at a table shares one session');

        $this->assertNotFalse($generatePos);
        $this->assertNotFalse($occupiedPos);
        $this->assertTrue($generatePos < $occupiedPos, 'sections must stay in this order');

        // The middle codes list is gone.
        $this->assertFalse(strpos($html, 'Each code stays the same until an admin regenerates it'));
    }

    /** Regenerate is a single control beside the card preview, admin only. */
    public function test_regenerate_is_offered_beside_the_card_preview(): void
    {
        $res = $this->actingAs($this->admin(), 'admin')->get('/admin/qr-generator');

        $res->assertOk();
        $html = $res->getContent();

        $this->assertStringContainsString('id="regenCardBtn"', $html);
        $this->assertStringContainsString('regenerateCurrentCode()', $html);

        // The old per-row form must not have come back.
        $this->assertStringNotContainsString('regen-code-btn', $html);
    }

    /**
     * The description text must tell the truth now: regenerating stops BOTH the
     * printed QR and the typed code, and the card has to be reprinted. The old
     * copy claimed only the "code" was invalidated, which was false for the QR.
     */
    public function test_the_description_says_regenerating_kills_the_qr_and_the_code(): void
    {
        $res = $this->actingAs($this->admin(), 'admin')->get('/admin/qr-generator');

        $res->assertOk();

        // Collapse the blade's line wrapping so the phrase can be matched whole.
        $text = preg_replace('/\s+/', ' ', $res->getContent());

        $this->assertStringContainsString('stops both the printed QR and the typed code', $text);
        $this->assertStringContainsString('print and place the new card afterward', $text);
    }

    /** A staff account (no Regenerate permission) gets no Regenerate control at all. */
    public function test_staff_get_no_regenerate_control(): void
    {
        $staff = User::where('role', 'staff')->first();

        if (!$staff) {
            $this->markTestSkipped('no staff account to test with');
        }

        $res = $this->actingAs($staff, 'admin')->get('/admin/qr-generator');

        $res->assertOk();
        $html = $res->getContent();

        $this->assertStringNotContainsString('regen-code-btn', $html);
        $this->assertStringNotContainsString('id="regenCardBtn"', $html);
        $this->assertStringNotContainsString('regenerateCurrentCode()', $html);
    }

    public function test_the_printable_card_carries_the_qr_url_and_the_code_together(): void
    {
        $table = $this->table('G2');

        $res = $this->actingAs($this->admin(), 'admin')->getJson(
            '/admin/qr-generator/table-card?branch_id=' . $table->branch_id
                . '&table_number=' . $table->table_number
        );

        $res->assertOk();

        // Both, on the same card: the QR for the customer whose camera works,
        // and the code for the one whose camera does not.
        $res->assertJson([
            'table_id'     => $table->id,
            'table_number' => $table->table_number,
            'code'         => $table->code,
            'url'          => $table->qrUrl(),
        ]);
    }

    public function test_generating_a_card_for_a_new_table_registers_it_with_a_code(): void
    {
        $number = self::PT_PREFIX . 'G3';

        $this->assertNull(TableEntry::find(1, $number), 'precondition: table not registered yet');

        $res = $this->actingAs($this->admin(), 'admin')
            ->getJson('/admin/qr-generator/table-card?branch_id=1&table_number=' . $number);

        $res->assertOk();

        $registered = TableEntry::find(1, $number);

        $this->assertNotNull($registered, 'generating a card must register the table');
        $this->assertSame($registered->code, $res->json('code'));
        $this->assertMatchesRegularExpression(
            '/^[' . TableEntry::ALPHABET . ']{' . TableEntry::CODE_LENGTH . '}$/',
            $registered->code
        );
    }

    /**
     * The customer-facing door must NOT create registry rows. It reads its
     * table number from a URL a stranger can edit.
     *
     * Since the QR now carries the table's permanent code as `k` and every
     * door requires it to match restaurant_tables.code, an unregistered table
     * — which has no code — can no longer be entered by URL at all. It lands on
     * the code-entry page. The guarantee this test exists for is unchanged and
     * still asserted: an anonymous request mints no registry row.
     */
    public function test_a_customer_scan_never_registers_a_new_table(): void
    {
        $number = self::PT_PREFIX . 'G4';

        // A hand-built URL for a table that was never registered — any `k` at
        // all, since none can match a row that does not exist.
        $this->get('/customer/menu?branch_id=1&table=' . $number . '&k=ANYCODE12')
            ->assertRedirect(route('customer.dineinqr'));

        // Not seated: an unregistered table cannot be entered by URL.
        $this->assertNull(session('table_number'));

        // And no registry row was minted by the anonymous request.
        $this->assertNull(TableEntry::find(1, $number));
    }

    public function test_clearing_a_table_says_what_happened_to_its_order(): void
    {
        $table = $this->table('H1');

        $this->scanUrl($table);
        $session = TableOccupancy::activeFor($table->branch_id, $table->table_number);

        $order = Order::create([
            'order_number' => 'PT-' . substr(uniqid(), -8),
            'branch_id'    => $table->branch_id,
            'type'         => 'dine_in',
            'table_number' => $table->table_number,
            'status'       => 'preparing',
            'subtotal'     => 100,
            'total'        => 100,
        ]);

        TableOccupancy::attachOrder($order);

        $res = $this->actingAs($this->admin(), 'admin')->postJson('/admin/tables/clear', [
            'branch_id'    => $table->branch_id,
            'table_number' => $table->table_number,
        ]);

        $res->assertOk();

        // The order is NAMED and its fate stated — never silently orphaned.
        $res->assertJson([
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'order_status' => 'preparing',
        ]);

        $this->assertStringContainsString($order->order_number, $res->json('message'));
        $this->assertStringContainsString('Preparing', $res->json('message'));

        // The table is free; the order is untouched and still points at it.
        $this->assertNull(TableOccupancy::activeFor($table->branch_id, $table->table_number));
        $this->assertSame('preparing', $order->fresh()->status, 'clearing must not change the order');

        $released = TableSession::find($session->id);
        $this->assertNull($released->active_lock);
        $this->assertSame('staff_cleared', $released->release_reason);
        $this->assertSame($order->id, $released->order_id, 'the order link is kept as history');
    }

    public function test_clearing_a_table_with_no_order_says_so(): void
    {
        $table = $this->table('H2');

        $this->scanUrl($table);

        $res = $this->actingAs($this->admin(), 'admin')->postJson('/admin/tables/clear', [
            'branch_id'    => $table->branch_id,
            'table_number' => $table->table_number,
        ]);

        $res->assertOk();
        $res->assertJson(['order_id' => null, 'order_number' => null]);
        $this->assertStringContainsString('no order on it', $res->json('message'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Goal 2 — the migration's outcome
    // ══════════════════════════════════════════════════════════════════════

    public function test_every_registered_table_has_a_non_null_unique_code(): void
    {
        $total = RestaurantTable::count();

        $this->assertGreaterThan(0, $total, 'the migration should have backfilled the live tables');
        $this->assertSame(0, RestaurantTable::whereNull('code')->count(), 'no table may be left without a code');
        $this->assertSame(0, RestaurantTable::where('code', '')->count());
        $this->assertSame($total, RestaurantTable::distinct()->count('code'), 'codes must be unique across tables');
    }

    public function test_every_table_a_dine_in_order_ever_used_came_out_of_the_migration_with_a_code(): void
    {
        $pairs = DB::table('orders')
            ->where('type', 'dine_in')
            ->whereNotNull('branch_id')
            ->whereNotNull('table_number')
            ->select('branch_id', 'table_number')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $number = strtoupper(trim((string) $pair->table_number));

            // Only tables of a shape the entry path would ever accept.
            if ($number === '' || strlen($number) > 10 || !preg_match('/^[A-Z0-9]+$/', $number)) {
                continue;
            }

            if (!Branch::find($pair->branch_id)) {
                continue;
            }

            $registered = TableEntry::find((int) $pair->branch_id, $number);

            $this->assertNotNull(
                $registered,
                "branch {$pair->branch_id} table {$number} has no registry row"
            );
            $this->assertNotNull($registered->code);
        }
    }
}

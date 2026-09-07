<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Services\QrPayload;
use App\Services\TableEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Every way a customer can enter the Dine-In flow, and every way that entry can
 * be fed something it should refuse.
 *
 * There are three entry points and they must all agree:
 *   1. POST /customer/dineinqr with tableData  — our in-page camera scanner
 *   2. POST /customer/dineinqr with table_code — the typed permanent code
 *   3. GET  /customer/menu?branch_id=&table=   — a phone's own camera app
 *                                                opening the QR's URL
 *
 * (Door 2 used to also accept a staff-issued, single-use, ten-minute fallback
 * code. It has been retired: the permanent code is now shown to staff directly
 * on the admin dashboard, so a second credential system was no longer needed.
 * Every case below that used to fetch one of those now registers and uses a
 * permanent code instead — see permanentCode().)
 *
 * The bar for all of them: never a 500, never a raw exception, never a silent
 * assignment to the wrong table, and always a message a customer can act on.
 */
class DineInQrEntryTest extends TestCase
{
    // Registering a table writes a row; roll it all back.
    use DatabaseTransactions;

    /** Register (or fetch) a table's permanent code, the way the admin dashboard would. */
    private function permanentCode(int $branchId = 1, string $table = '3'): string
    {
        return TableEntry::findOrRegister($branchId, $table)->code;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // These limiters are keyed on the client IP, which is identical for
        // every test, so they must be reset or later tests inherit earlier hits.
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');

        parent::tearDown();
    }

    private function scan(string $payload)
    {
        return $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', ['tableData' => $payload, 'next' => 'guest']);
    }

    private function typeCode(string $code)
    {
        return $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', ['table_code' => $code, 'next' => 'guest']);
    }

    /** A rejected attempt must leave no Dine-In session behind at all. */
    private function assertRejected($response, string $context): void
    {
        $response->assertStatus(302);
        $response->assertRedirect('/customer/dineinqr');

        $error = session('error');
        $this->assertIsString($error, "no user-facing error for: $context");
        $this->assertNotSame('', trim($error), "empty error message for: $context");

        $this->assertNull(session('table_number'), "table_number leaked for: $context");
        $this->assertNull(session('branch_id'), "branch_id leaked for: $context");
        $this->assertNotSame('dine_in', session('order_type'), "order_type leaked for: $context");
    }

    // ══════════ Happy paths ══════════

    public function test_valid_qr_url_starts_dine_in(): void
    {
        // The QR now carries the table's permanent code as `k`; every door
        // requires it to match restaurant_tables.code.
        $code = $this->permanentCode(1, '5');
        $res = $this->scan("http://127.0.0.1:8000/customer/menu?branch_id=1&table=5&k={$code}");

        $res->assertRedirect(route('customer.menu'));
        $this->assertSame(1, session('branch_id'));
        $this->assertSame('5', session('table_number'));
        $this->assertSame('dine_in', session('order_type'));
    }

    public function test_valid_qr_json_and_query_string_forms_also_work(): void
    {
        // A distinct table per payload form: each pass is a fresh visitor
        // (flushSession), and the table-occupancy lock would rightly refuse a
        // second visitor on a table the previous pass is still holding. The
        // point here is that all three encodings parse, not table reuse.
        $k7  = $this->permanentCode(2, '7');
        $k71 = $this->permanentCode(2, '71');
        $k72 = $this->permanentCode(2, '72');

        foreach ([
            '{"branch_id":2,"table":"7","k":"' . $k7 . '"}' => '7',
            'branch_id=2&table=71&k=' . $k71               => '71',
            'branch_id=2&table_number=72&k=' . $k72        => '72',
        ] as $payload => $expectedTable) {
            $this->flushSession();
            $res = $this->scan($payload);

            $res->assertRedirect(route('customer.menu'));
            $this->assertSame(2, session('branch_id'), "failed for payload: $payload");
            $this->assertSame($expectedTable, session('table_number'), "failed for payload: $payload");
        }
    }

    public function test_table_number_is_normalised_identically_by_scan_and_code(): void
    {
        $code = $this->permanentCode(1, 'a3');
        $this->scan("branch_id=1&table=a3&k={$code}");
        $viaScan = session('table_number');

        // Same physical table via the other door. The first visitor's occupancy
        // has to be released first, otherwise this is a second party on a busy
        // table and would (correctly) be blocked.
        \App\Services\TableOccupancy::releaseTable(1, 'A3', null);

        $this->flushSession();
        RateLimiter::clear('table-code:127.0.0.1');

        $this->typeCode($this->permanentCode(1, 'a3'));
        $viaCode = session('table_number');

        $this->assertSame('A3', $viaScan);
        $this->assertSame('A3', $viaCode);
    }

    public function test_valid_permanent_code_starts_dine_in(): void
    {
        $res = $this->typeCode($this->permanentCode(1, '3'));

        $res->assertRedirect(route('customer.menu'));
        $this->assertSame(1, session('branch_id'));
        $this->assertSame('3', session('table_number'));
        $this->assertSame('dine_in', session('order_type'));
    }

    // ══════════ Malformed / tampered scan payloads ══════════

    public static function malformedPayloads(): array
    {
        return [
            'empty'                  => [''],
            'garbage text'           => ['hello there'],
            'unrelated url'          => ['https://example.com/'],
            'url with no query'      => ['http://127.0.0.1:8000/customer/menu'],
            'missing table'          => ['branch_id=1'],
            'missing branch'         => ['table=5'],
            'branch id as array'     => ['branch_id[]=1&table=5'],
            'table as array'         => ['branch_id=1&table[]=5'],
            'array branch inside url' => ['http://127.0.0.1:8000/customer/menu?branch_id[]=1&table=5'],
            'json with array branch' => ['{"branch_id":[1],"table":"5"}'],
            'sql-ish branch id'      => ['branch_id=1 OR 1=1&table=5'],
            'branch id with spaces'  => ['branch_id= 1&table=5'],
            'float branch id'        => ['branch_id=1.9&table=5'],
            'negative branch id'     => ['branch_id=-1&table=5'],
            'zero branch id'         => ['branch_id=0&table=5'],
            'hex branch id'          => ['branch_id=0x1&table=5'],
            'nonexistent branch'     => ['branch_id=999999&table=5'],
            'table with markup'      => ['branch_id=1&table=<script>alert(1)</script>'],
            'table with punctuation' => ['branch_id=1&table=5;DROP TABLE orders'],
            'table too long'         => ['branch_id=1&table=' . str_repeat('9', 500)],
            'table non-ascii'        => ['branch_id=1&table=' . urlencode('５')],
            'truncated code'         => ['branch_id=1&tab'],
            'oversized payload'      => [null],
        ];
    }

    /** @dataProvider malformedPayloads */
    public function test_malformed_scan_payload_is_rejected(?string $payload): void
    {
        // The oversized case is generated here to keep the provider readable.
        $payload = $payload ?? str_repeat('A', QrPayload::MAX_PAYLOAD_LENGTH + 1);

        $this->assertRejected($this->scan($payload), 'scan: ' . substr($payload, 0, 40));
    }

    // ══════════ Malformed / tampered typed codes ══════════

    public static function malformedCodes(): array
    {
        return [
            'garbage'              => ['zzzzzzzz'],
            'never issued'         => ['K7QMP3'],
            'old permanent format' => ['MAIN-3-Z6L3'],
            'too short'            => ['K7QM'],
            'too long'             => ['K7QMP3X'],
            'punctuation soup'     => ['!!!---???'],
            'oversized'            => [null],
        ];
    }

    /** @dataProvider malformedCodes */
    public function test_malformed_typed_code_is_rejected(?string $code): void
    {
        $code = $code ?? str_repeat('A', 5000);

        $this->assertRejected($this->typeCode($code), 'code: ' . substr($code, 0, 40));
    }

    /**
     * The old printed codes were "<BRANCH>-<TABLE>-<4 char HMAC>", derived
     * deterministically from (branch, table), so anyone who once saw a printed
     * card could keep using its code forever. That scheme is gone — the class
     * that produced it has been deleted — and a code in that shape must not
     * open the door. The literal below is a real code the old scheme emitted
     * for branch 1 / table 3, kept as a fixture so this stays a genuine
     * regression guard rather than a test of arbitrary garbage.
     *
     * (The malformedCodes provider covers the same string; this case exists to
     * name the reason.)
     */
    public function test_the_old_permanent_printed_code_format_no_longer_works(): void
    {
        $this->assertRejected($this->typeCode('MAIN-3-Z6L3'), 'legacy permanent code');
    }

    public function test_tampering_any_character_of_a_permanent_code_is_rejected(): void
    {
        $valid = $this->permanentCode(1, '3');
        $rejected = 0;

        foreach (str_split('23456789ABCDEFGHJKMNPQRSTUVWXYZ') as $replacement) {
            for ($pos = 0; $pos < strlen($valid); $pos++) {
                $mutated = $valid;
                $mutated[$pos] = $replacement;

                if ($mutated === $valid) {
                    continue;
                }

                $this->flushSession();
                RateLimiter::clear('table-code:127.0.0.1');

                $res = $this->typeCode($mutated);

                $res->assertStatus(302);
                $this->assertNull(
                    session('table_number'),
                    "mutated code $mutated was accepted"
                );
                $rejected++;
            }
        }

        $this->assertGreaterThan(100, $rejected, 'expected many mutations to be tested');

        // The real code must still work after all that guessing.
        $this->flushSession();
        RateLimiter::clear('table-code:127.0.0.1');
        $this->typeCode($valid)->assertRedirect(route('customer.menu'));
    }

    // ══════════ Inactive / deleted branch ══════════

    public function test_inactive_branch_is_rejected_on_every_entry_point(): void
    {
        $branch = Branch::findOrFail(2);

        // Registered while the branch was still open, typed after it closed.
        $validCode = $this->permanentCode($branch->id, '4');

        DB::table('branches')->where('id', $branch->id)->update(['is_active' => 0]);

        $this->flushSession();
        $this->assertRejected(
            $this->scan('branch_id=' . $branch->id . '&table=4'),
            'scan of inactive branch'
        );

        $this->flushSession();
        RateLimiter::clear('table-code:127.0.0.1');
        $this->assertRejected($this->typeCode($validCode), 'valid code for inactive branch');

        $this->flushSession();
        $this->get('/customer/menu?branch_id=' . $branch->id . '&table=4')
            ->assertRedirect(route('customer.dineinqr'));
        $this->assertNull(session('table_number'));
    }

    public function test_nonexistent_branch_is_rejected(): void
    {
        $this->assertRejected($this->scan('branch_id=999999&table=5'), 'nonexistent branch');
    }

    // ══════════ The phone-camera URL landing ══════════

    public function test_menu_landing_validates_exactly_like_the_scanner(): void
    {
        $code = $this->permanentCode(1, '9');
        $res = $this->get('/customer/menu?branch_id=1&table=9&k=' . $code);
        $res->assertOk();
        $this->assertSame(1, session('branch_id'));
        $this->assertSame('9', session('table_number'));
        $this->assertSame('dine_in', session('order_type'));

        foreach ([
            'branch_id=999999&table=9&k=' . $code,
            'branch_id=abc&table=9&k=' . $code,
            'branch_id=1&table=' . str_repeat('9', 200) . '&k=' . $code,
            'branch_id=1&table=' . urlencode('<script>') . '&k=' . $code,
            'branch_id[]=1&table=9&k=' . $code,
            // Missing k, and a k that never matched this table — a photograph
            // of a reprinted table's old card. Both land on code entry.
            'branch_id=1&table=9',
            'branch_id=1&table=9&k=WRONGWRONG',
        ] as $query) {
            $this->flushSession();

            $res = $this->get('/customer/menu?' . $query);

            $res->assertStatus(302);
            $res->assertRedirect(route('customer.dineinqr'));
            $this->assertIsString(session('error'), "no error for: $query");
            $this->assertNull(session('table_number'), "table_number leaked for: $query");
            $this->assertNotSame('dine_in', session('order_type'), "order_type leaked for: $query");
        }
    }

    public function test_menu_without_qr_params_is_untouched(): void
    {
        $this->get('/customer/menu')->assertOk();
        $this->assertNull(session('table_number'));
    }

    // ══════════ Rate limiting ══════════

    public function test_typed_code_attempts_are_throttled(): void
    {
        for ($i = 0; $i < TableEntry::MAX_ATTEMPTS; $i++) {
            $this->flushSession();
            $this->typeCode('K7QMP3');
            $this->assertStringNotContainsString('Too many', (string) session('error'));
        }

        $this->flushSession();
        $this->typeCode('K7QMP3');
        $this->assertStringContainsString('Too many attempts', (string) session('error'));
    }

    public function test_throttled_customer_cannot_get_through_with_a_valid_code(): void
    {
        $valid = $this->permanentCode(1, '3');

        for ($i = 0; $i < TableEntry::MAX_ATTEMPTS; $i++) {
            $this->flushSession();
            $this->typeCode('K7QMP3');
        }

        // A correct code must still be refused while the throttle is active,
        // otherwise the throttle does not actually protect anything.
        $this->flushSession();
        $this->typeCode($valid);

        $this->assertStringContainsString('Too many attempts', (string) session('error'));
        $this->assertNull(session('table_number'));

        // And it must not have been silently spent by the refused attempt —
        // a permanent code is never consumed, but this pins the code itself
        // unchanged, which is the closest analogue that still makes sense now
        // there is no "claimed" state to check.
        $this->assertDatabaseHas('restaurant_tables', ['branch_id' => 1, 'table_number' => '3', 'code' => $valid]);
    }

    public function test_scan_attempts_are_throttled(): void
    {
        for ($i = 0; $i < QrPayload::MAX_ATTEMPTS; $i++) {
            $this->flushSession();
            $this->scan('branch_id=999999&table=5');
            $this->assertStringNotContainsString('Too many', (string) session('error'));
        }

        $this->flushSession();
        $this->scan('branch_id=999999&table=5');
        $this->assertStringContainsString('Too many scan attempts', (string) session('error'));
    }

    public function test_successful_entry_clears_the_throttle_counter(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->flushSession();
            $this->typeCode('K7QMP3');
        }

        $this->flushSession();
        $this->typeCode($this->permanentCode(1, '3'))->assertRedirect(route('customer.menu'));

        $this->assertSame(0, RateLimiter::attempts('table-code:127.0.0.1'));
    }
}

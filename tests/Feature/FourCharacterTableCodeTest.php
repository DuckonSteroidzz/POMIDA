<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The four-character table code (Oct 2026) and the controls that make a code
 * that short safe. See App\Services\TableEntry for the reasoning and the
 * arithmetic.
 *
 *   1. Format: four characters, the unconfusable alphabet, unique, drawn
 *      again on a collision (both before the write and on a write-time race).
 *   2. Entry normalises (trim, uppercase, separators) at every door.
 *   3. An old eight-character card is told it is out of date — by shape,
 *      not by looking anything up — and an un-migrated eight-character code
 *      still works.
 *   4. One failed-code budget, per device and per address, across the typed
 *      door, Change table and the QR doors; a success spends and clears
 *      nothing; an old card spends nothing.
 *   5. No enumeration: a wrong code, a real code at a table out of service and
 *      a real code at a closed branch get byte-identical answers.
 *   6. The migration: what it changes, what it leaves, idempotence, live
 *      sessions untouched, and down().
 *   7. Admin Regenerate Code and new-table registration give four characters.
 *
 * Every table here belongs to a branch created inside the test, so nothing
 * depends on (or touches) the rows the testing database already holds. All of
 * it is rolled back.
 */
class FourCharacterTableCodeTest extends TestCase
{
    use DatabaseTransactions;

    private const MIGRATION = '2026_10_07_000000_shorten_table_codes_to_four_characters.php';

    protected function setUp(): void
    {
        parent::setUp();
        app('cache')->store()->flush();
    }

    protected function tearDown(): void
    {
        TableEntry::useCodeSource(null);
        RestaurantTable::flushEventListeners();
        app('cache')->store()->flush();
        parent::tearDown();
    }

    // ══════════ fixtures ══════════

    private function branch(bool $active = true): Branch
    {
        return Branch::create([
            'name'           => 'Four Char ' . Str::random(6),
            'code'           => 'FC' . strtoupper(Str::random(6)),
            'address'        => 'Created by FourCharacterTableCodeTest',
            'is_active'      => $active,
            'is_main_branch' => false,
        ]);
    }

    private function table(?Branch $branch = null): RestaurantTable
    {
        $branch ??= $this->branch();

        return TableEntry::findOrRegister($branch->id, 'F' . strtoupper(Str::random(5)));
    }

    /** A four-character, in-alphabet code no table holds — a realistic wrong guess. */
    private function unusedCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < TableEntry::CODE_LENGTH; $i++) {
                $code .= TableEntry::ALPHABET[random_int(0, strlen(TableEntry::ALPHABET) - 1)];
            }
        } while (RestaurantTable::where('code', $code)->exists());

        return $code;
    }

    /** An eight-character code in the old format that no table holds. */
    private function unusedLegacyCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < TableEntry::LEGACY_CODE_LENGTH; $i++) {
                $code .= TableEntry::ALPHABET[random_int(0, strlen(TableEntry::ALPHABET) - 1)];
            }
        } while (RestaurantTable::where('code', $code)->orWhere('previous_code', $code)->exists());

        return $code;
    }

    /** A table still carrying an old eight-character code, as before the migration. */
    private function legacyTable(?Branch $branch = null, ?string $previous = null): RestaurantTable
    {
        $branch ??= $this->branch();

        $id = DB::table('restaurant_tables')->insertGetId([
            'branch_id'     => $branch->id,
            'table_number'  => 'L' . strtoupper(Str::random(5)),
            'code'          => $this->unusedLegacyCode(),
            'previous_code' => $previous,
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return RestaurantTable::findOrFail($id);
    }

    private function typeCode(string $code)
    {
        return $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', ['table_code' => $code, 'next' => 'guest']);
    }

    private function openQr(RestaurantTable $table, ?string $k = null)
    {
        return $this->from('/customer/dineinqr')->get('/customer/menu?branch_id=' . $table->branch_id
            . '&table=' . $table->table_number . '&k=' . rawurlencode($k ?? $table->code));
    }

    /** Pin every following request to ONE browser session (the per-device key). */
    private function asDevice(string $id): void
    {
        $this->assertSame(40, strlen($id));
        $this->withCookie(config('session.cookie'), $id);
    }

    private function ipAttempts(): int
    {
        return RateLimiter::attempts('table-code:127.0.0.1');
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function migration(): object
    {
        return require database_path('migrations/' . self::MIGRATION);
    }

    private function assertNewFormat(string $code, string $context = ''): void
    {
        $this->assertMatchesRegularExpression(
            '/^[' . TableEntry::ALPHABET . ']{' . TableEntry::CODE_LENGTH . '}$/',
            $code,
            $context
        );
    }

    // ══════════ 1. format, uniqueness, collision retry ══════════

    public function test_the_code_length_is_four(): void
    {
        $this->assertSame(4, TableEntry::CODE_LENGTH);
        // 31 symbols: 2-9 and A-Z without I, L, O.
        $this->assertSame(31, strlen(TableEntry::ALPHABET));
    }

    public function test_allocated_codes_are_four_unconfusable_characters(): void
    {
        $seen = [];

        for ($i = 0; $i < 300; $i++) {
            $code = TableEntry::allocateCode();
            $this->assertNewFormat($code);

            foreach (['0', 'O', '1', 'I', 'L'] as $lookAlike) {
                $this->assertStringNotContainsString($lookAlike, $code);
            }

            $seen[] = $code;
        }

        // Drawn independently from the whole space, not a counter: 300 draws
        // from 923,521 are overwhelmingly distinct.
        $this->assertGreaterThan(290, count(array_unique($seen)));
    }

    public function test_every_registered_table_gets_a_unique_four_character_code(): void
    {
        $branch = $this->branch();
        $codes = [];

        for ($i = 0; $i < 25; $i++) {
            $codes[] = TableEntry::findOrRegister($branch->id, 'U' . $i)->code;
        }

        foreach ($codes as $code) {
            $this->assertNewFormat($code);
        }

        $this->assertCount(25, array_unique($codes));
    }

    public function test_the_database_refuses_a_duplicate_code(): void
    {
        $taken = $this->table();

        try {
            DB::table('restaurant_tables')->insert([
                'branch_id'    => $taken->branch_id,
                'table_number' => 'DUP' . random_int(10, 99),
                'code'         => $taken->code,
                'is_active'    => 1,
            ]);
            $this->fail('the unique index on code must refuse a duplicate');
        } catch (QueryException $e) {
            $this->assertSame(1062, $e->errorInfo[1] ?? null);
        }
    }

    public function test_a_collision_is_drawn_again(): void
    {
        $taken = $this->table()->code;
        $fresh = $this->unusedCode();
        $draws = [$taken, $taken, $fresh];

        TableEntry::useCodeSource(function () use (&$draws) {
            return array_shift($draws);
        });

        $this->assertSame($fresh, TableEntry::allocateCode());
        $this->assertSame([], $draws, 'it should have drawn exactly three times');
    }

    public function test_a_full_space_fails_loudly_rather_than_reusing_a_code(): void
    {
        $taken = $this->table()->code;
        TableEntry::useCodeSource(fn () => $taken);

        $this->expectException(\RuntimeException::class);
        TableEntry::allocateCode();
    }

    /**
     * The race allocateCode()'s check cannot see: another table takes the code
     * between the check and the INSERT. Simulated by inserting that row from
     * the `creating` event, the instant before Eloquent writes.
     */
    public function test_a_write_time_collision_on_register_is_retried(): void
    {
        $branch = $this->branch();
        [$first, $second] = [$this->unusedCode(), $this->unusedCode()];
        $draws = [$first, $second];
        TableEntry::useCodeSource(function () use (&$draws) {
            return array_shift($draws);
        });

        $raced = false;
        RestaurantTable::creating(function (RestaurantTable $row) use (&$raced, $branch) {
            if ($raced) {
                return;
            }
            $raced = true;
            DB::table('restaurant_tables')->insert([
                'branch_id' => $branch->id, 'table_number' => 'RACER', 'code' => $row->code, 'is_active' => 1,
            ]);
        });

        $table = TableEntry::findOrRegister($branch->id, 'RACED');

        $this->assertTrue($raced, 'precondition: the race was staged');
        $this->assertSame($second, $table->code, 'the collided code must be replaced, not shared');
        $this->assertSame(1, RestaurantTable::where('code', $first)->count());
    }

    public function test_a_write_time_collision_on_regenerate_is_retried(): void
    {
        $table = $this->table();
        $old = $table->code;
        [$first, $second] = [$this->unusedCode(), $this->unusedCode()];
        $draws = [$first, $second];
        TableEntry::useCodeSource(function () use (&$draws) {
            return array_shift($draws);
        });

        $raced = false;
        RestaurantTable::updating(function (RestaurantTable $row) use (&$raced) {
            if ($raced) {
                return;
            }
            $raced = true;
            DB::table('restaurant_tables')->insert([
                'branch_id' => $row->branch_id, 'table_number' => 'RACER', 'code' => $row->code, 'is_active' => 1,
            ]);
        });

        $rotated = TableEntry::rotateCode($table);

        $this->assertTrue($raced);
        $this->assertSame($second, $rotated->code);
        $this->assertSame($old, $rotated->previous_code);
    }

    // ══════════ 2. normalisation ══════════

    public function test_typed_entry_is_trimmed_uppercased_and_separator_tolerant(): void
    {
        $table = $this->table();
        $code = $table->code;

        $variants = [
            ' ' . strtolower($code) . ' ',
            substr($code, 0, 2) . ' ' . substr($code, 2),
            substr($code, 0, 2) . '-' . strtolower(substr($code, 2)),
        ];

        foreach ($variants as $typed) {
            $this->flushSession();
            app('cache')->store()->flush();

            $this->typeCode($typed)->assertRedirect(route('customer.menu'));
            $this->assertSame($table->table_number, session('table_number'), "typed as '{$typed}'");
        }
    }

    public function test_the_qr_k_is_normalised_too(): void
    {
        $table = $this->table();

        $this->openQr($table, strtolower($table->code))->assertOk();
        $this->assertSame($table->table_number, session('table_number'));
    }

    // ══════════ 3. old cards ══════════

    public function test_an_old_eight_character_card_is_told_it_is_out_of_date(): void
    {
        // Typed off an old card.
        $this->typeCode($this->unusedLegacyCode())->assertRedirect('/customer/dineinqr');
        $this->assertSame(TableEntry::ERR_CARD_OUTDATED, session('error'));
        $this->assertNull(session('table_number'));

        // Scanned off an old card: the QR's k is the old code.
        $table = $this->table();
        $this->flushSession();
        $this->openQr($table, $this->unusedLegacyCode())->assertRedirect(route('customer.dineinqr'));
        $this->assertSame(TableEntry::ERR_CARD_OUTDATED, session('error'));
        $this->assertNull(session('table_number'));

        // The message helps without hinting: it names staff, not codes.
        $this->assertStringContainsString('staff', TableEntry::ERR_CARD_OUTDATED);
    }

    /**
     * Decided by SHAPE, never by previous_code: the answer for a table's real
     * old code and for a made-up eight-character string is identical, so the
     * message cannot be used to learn anything.
     */
    public function test_the_out_of_date_answer_is_the_same_for_a_real_old_code_and_junk(): void
    {
        $realOld = $this->unusedLegacyCode();
        $table = $this->legacyTable(null, $realOld);
        TableEntry::rotateCode($table->fresh());   // previous_code is now a 4-char code; realOld is two rotations back

        $junk = $this->unusedLegacyCode();

        $answers = [];
        foreach (['real old' => $realOld, 'junk' => $junk] as $label => $code) {
            $this->flushSession();
            $res = $this->typeCode($code);
            $answers[$label] = [$res->status(), $res->headers->get('Location'), session('error')];
        }

        $this->assertSame($answers['real old'], $answers['junk']);
    }

    public function test_an_old_card_does_not_spend_the_guessing_budget(): void
    {
        for ($i = 0; $i < TableEntry::MAX_FAILED_PER_IP + 5; $i++) {
            $this->flushSession();
            $this->typeCode($this->unusedLegacyCode());
        }

        $this->assertSame(0, $this->ipAttempts());

        // Customers with old cards cannot lock the café out of typed entry.
        $table = $this->table();
        $this->flushSession();
        $this->typeCode($table->code)->assertRedirect(route('customer.menu'));
    }

    /** Between deploying the code and running the migration, nothing breaks. */
    public function test_an_unmigrated_eight_character_code_still_works_at_every_door(): void
    {
        $table = $this->legacyTable();
        $this->assertSame(8, strlen($table->code), 'precondition');

        $this->typeCode($table->code)->assertRedirect(route('customer.menu'));
        $this->assertSame($table->table_number, session('table_number'));

        $this->flushSession();
        $other = $this->legacyTable();
        $this->openQr($other)->assertOk();
        $this->assertSame($other->table_number, session('table_number'));
    }

    // ══════════ 4. the failed-code budget ══════════

    public function test_one_device_is_stopped_after_five_wrong_codes_even_with_a_right_one(): void
    {
        $table = $this->table();
        $this->asDevice(str_repeat('a', 40));

        for ($i = 0; $i < TableEntry::MAX_FAILED_PER_DEVICE; $i++) {
            $this->typeCode($this->unusedCode());
            $this->assertSame(TableEntry::ERR_CODE_INVALID, session('error'), "guess #{$i}");
        }

        $this->typeCode($table->code)->assertRedirect('/customer/dineinqr');
        $this->assertSame(TableEntry::ERR_TOO_MANY, session('error'));
        $this->assertNull(session('table_number'), 'a throttled device must not be seated');

        // Only that device: the address has spent 5 of its 30, so another
        // phone on the same wi-fi still gets in.
        $this->assertSame(TableEntry::MAX_FAILED_PER_DEVICE, $this->ipAttempts());
        $this->asDevice(str_repeat('b', 40));
        $this->flushSession();
        $this->typeCode($table->code)->assertRedirect(route('customer.menu'));
    }

    public function test_the_device_window_reopens_after_ten_minutes(): void
    {
        $table = $this->table();
        $this->asDevice(str_repeat('c', 40));

        for ($i = 0; $i < TableEntry::MAX_FAILED_PER_DEVICE; $i++) {
            $this->typeCode($this->unusedCode());
        }
        $this->typeCode($table->code);
        $this->assertSame(TableEntry::ERR_TOO_MANY, session('error'));

        $this->travel(TableEntry::DEVICE_WINDOW_SECONDS + 1)->seconds();

        $this->flushSession();
        $this->typeCode($table->code)->assertRedirect(route('customer.menu'));
    }

    public function test_one_address_is_stopped_after_thirty_wrong_codes_across_devices(): void
    {
        $table = $this->table();

        // A fresh session every request — a script discarding cookies.
        for ($i = 0; $i < TableEntry::MAX_FAILED_PER_IP; $i++) {
            $this->flushSession();
            $this->typeCode($this->unusedCode());
            $this->assertSame(TableEntry::ERR_CODE_INVALID, session('error'), "guess #{$i}");
        }

        $this->flushSession();
        $res = $this->typeCode($table->code);
        $res->assertRedirect('/customer/dineinqr');
        $this->assertSame(TableEntry::ERR_TOO_MANY, session('error'));
        $this->assertTrue((bool) session('show_manual'), 'the refusal lands back on the code box');
        $this->assertNull(session('table_number'));

        // Within the hour it stays shut; after it, it reopens.
        $this->travel(TableEntry::IP_WINDOW_SECONDS - 60)->seconds();
        $this->flushSession();
        $this->typeCode($table->code);
        $this->assertSame(TableEntry::ERR_TOO_MANY, session('error'));

        $this->travel(120)->seconds();
        $this->flushSession();
        $this->typeCode($table->code)->assertRedirect(route('customer.menu'));
    }

    public function test_the_qr_door_and_the_typed_door_share_one_budget(): void
    {
        $table = $this->table();

        // Guessing `k` on the URL door...
        for ($i = 0; $i < TableEntry::MAX_FAILED_PER_IP; $i++) {
            $this->flushSession();
            $this->openQr($table, $this->unusedCode())->assertRedirect(route('customer.dineinqr'));
            $this->assertSame(TableEntry::ERR_QR_STALE, session('error'), "k guess #{$i}");
        }

        // ...closes the URL door, even to the right k...
        $this->flushSession();
        $this->openQr($table)->assertRedirect(route('customer.dineinqr'));
        $this->assertSame(TableEntry::ERR_TOO_MANY, session('error'));
        $this->assertNull(session('table_number'));

        // ...and the typed door, and Change table's door.
        $this->flushSession();
        $this->typeCode($table->code);
        $this->assertSame(TableEntry::ERR_TOO_MANY, session('error'));

        // And the old in-page scanner.
        $this->flushSession();
        $this->from('/customer/dineinqr')->post('/customer/dineinqr', ['tableData' => $table->qrUrl(), 'next' => 'guest']);
        $this->assertSame(TableEntry::ERR_TOO_MANY, session('error'));
    }

    public function test_successes_and_branch_level_refusals_spend_nothing(): void
    {
        $table = $this->table();

        for ($i = 0; $i < 3; $i++) {
            $this->flushSession();
            $this->typeCode($table->code)->assertRedirect(route('customer.menu'));
            $this->flushSession();
            $this->openQr($table)->assertOk();
        }

        // A QR for a branch that is closed is refused before the code is
        // looked at; it is not a guess.
        $closed = $this->branch(false);
        $this->flushSession();
        $this->get('/customer/menu?branch_id=' . $closed->id . '&table=5&k=' . $this->unusedCode())
            ->assertRedirect(route('customer.dineinqr'));
        $this->assertSame(TableEntry::ERR_CLOSED_BRANCH, session('error'));

        // A QR URL with no k at all (it cannot match anything).
        $this->flushSession();
        $this->get('/customer/menu?branch_id=' . $table->branch_id . '&table=' . $table->table_number)
            ->assertRedirect(route('customer.dineinqr'));

        $this->assertSame(0, $this->ipAttempts());
    }

    public function test_the_change_table_door_spends_the_same_budget(): void
    {
        $branch = $this->branch();
        [$seat, $target] = [$this->table($branch), $this->table($branch)];

        $this->openQr($seat)->assertOk();

        $this->from('/customer/menu')->post('/customer/table/change', ['table_code' => $this->unusedCode()]);
        $this->assertSame(TableEntry::ERR_CODE_INVALID, session('table_change_error'));
        $this->assertSame(1, $this->ipAttempts());

        $this->assertSame($seat->table_number, session('table_number'), 'a refused change leaves the customer where they were');
        $this->assertNotSame($target->table_number, session('table_number'));
    }

    // ══════════ 5. no enumeration ══════════

    /**
     * The whole observable answer to a typed code: status, where it sends you,
     * what it says, what else it flashes, and what it costs.
     */
    private function typedAnswer(string $code): array
    {
        $this->flushSession();
        app('cache')->store()->flush();

        $res = $this->typeCode($code);

        return [
            'status'      => $res->status(),
            'location'    => $res->headers->get('Location'),
            'error'       => session('error'),
            'show_manual' => session('show_manual'),
            'old_input'   => session()->getOldInput('table_code') !== null,
            'seated'      => session('table_number') !== null,
            'spent'       => $this->ipAttempts(),
        ];
    }

    public function test_a_wrong_code_an_out_of_service_table_and_a_closed_branch_answer_identically(): void
    {
        $inactive = $this->table();
        $inactive->forceFill(['is_active' => false])->save();

        $closedBranch = $this->branch();
        $atClosed = $this->table($closedBranch);
        $closedBranch->forceFill(['is_active' => false])->save();

        $wrong = $this->typedAnswer($this->unusedCode());

        $this->assertSame(TableEntry::ERR_CODE_INVALID, $wrong['error']);
        $this->assertFalse($wrong['seated']);
        $this->assertSame(1, $wrong['spent'], 'a wrong code is a guess');

        $this->assertSame($wrong, $this->typedAnswer($inactive->code), 'out-of-service table answered differently');
        $this->assertSame($wrong, $this->typedAnswer($atClosed->code), 'closed-branch table answered differently');
    }

    public function test_the_qr_door_answers_out_of_service_like_a_wrong_k(): void
    {
        $inactive = $this->table();
        $inactive->forceFill(['is_active' => false])->save();
        $active = $this->table();

        $answer = function (RestaurantTable $t, string $k): array {
            $this->flushSession();
            app('cache')->store()->flush();
            $res = $this->openQr($t, $k);

            return [$res->status(), $res->headers->get('Location'), session('error'), session('table_number'), $this->ipAttempts()];
        };

        $wrongOnActive = $answer($active, $this->unusedCode());

        $this->assertSame(TableEntry::ERR_QR_STALE, $wrongOnActive[2]);
        $this->assertSame($wrongOnActive, $answer($inactive, $inactive->code), 'right k on an out-of-service table differed');
        $this->assertSame($wrongOnActive, $answer($inactive, $this->unusedCode()), 'wrong k on an out-of-service table differed');
    }

    public function test_a_seated_customer_whose_table_goes_out_of_service_still_gets_the_precise_reason(): void
    {
        // Nobody is guessing here — the code comes from the server — so the
        // re-check of a live seat keeps validate()'s own sentence.
        $table = $this->table();
        $this->openQr($table)->assertOk();

        $table->forceFill(['is_active' => false])->save();

        $this->getJson('/customer/table-session-status')->assertJson(['valid' => false]);
        $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, session('error'));
    }

    // ══════════ 6. the migration ══════════

    public function test_the_migration_reissues_old_codes_and_keeps_them_as_previous_code(): void
    {
        $branch = $this->branch();
        $plain = $this->legacyTable($branch);
        $rotatedBefore = $this->legacyTable($branch, $this->unusedLegacyCode());
        $alreadyNew = $this->table($branch);

        $before = RestaurantTable::whereIn('id', [$plain->id, $rotatedBefore->id, $alreadyNew->id])->get()->keyBy('id');

        $this->travel(5)->minutes();
        $this->migration()->up();

        $after = RestaurantTable::whereIn('id', [$plain->id, $rotatedBefore->id, $alreadyNew->id])->get()->keyBy('id');

        foreach ([$plain->id, $rotatedBefore->id] as $id) {
            $this->assertNewFormat($after[$id]->code, "row {$id}");
            $this->assertSame($before[$id]->code, $after[$id]->previous_code, 'the old code is kept in previous_code');
            $this->assertNotNull($after[$id]->code_rotated_at);
            $this->assertNull($after[$id]->code_rotated_by, 'a system change, not an admin');
            $this->assertSame($before[$id]->table_number, $after[$id]->table_number);
            $this->assertSame($before[$id]->is_active, $after[$id]->is_active);
        }

        // A table already in the new format is not touched at all.
        $this->assertSame($before[$alreadyNew->id]->toArray(), $after[$alreadyNew->id]->toArray());

        // Unique across EVERY table, not just these.
        $this->assertSame(
            RestaurantTable::count(),
            RestaurantTable::distinct()->count('code'),
            'every code must be unique after the migration'
        );
        $this->assertSame(0, RestaurantTable::whereRaw('CHAR_LENGTH(code) <> 4')->count(), 'no old-format code may remain');
    }

    public function test_after_the_migration_the_old_code_is_out_of_date_and_the_new_one_works(): void
    {
        $table = $this->legacyTable();
        $old = $table->code;

        $this->migration()->up();
        $table = $table->fresh();

        $this->typeCode($old)->assertRedirect('/customer/dineinqr');
        $this->assertSame(TableEntry::ERR_CARD_OUTDATED, session('error'));

        $this->flushSession();
        $this->openQr($table, $old)->assertRedirect(route('customer.dineinqr'));
        $this->assertSame(TableEntry::ERR_CARD_OUTDATED, session('error'));

        $this->flushSession();
        $this->typeCode($table->code)->assertRedirect(route('customer.menu'));
        $this->assertSame($table->table_number, session('table_number'));
    }

    public function test_the_migration_does_not_interrupt_a_seated_customer(): void
    {
        $table = $this->legacyTable();

        // Seated through the QR before the migration (old k)...
        $this->openQr($table)->assertOk();
        $live = TableOccupancy::activeFor($table->branch_id, $table->table_number);
        $this->assertNotNull($live, 'precondition: seated');

        $sessionsBefore = DB::table('table_sessions')->where('id', $live->id)->first();

        $this->migration()->up();

        // ...still seated, same occupancy row, untouched, and their own
        // status poll still says so.
        $this->getJson('/customer/table-session-status')->assertExactJson(['valid' => true]);
        $this->assertSame($table->table_number, session('table_number'));
        $this->assertEquals($sessionsBefore, DB::table('table_sessions')->where('id', $live->id)->first());
        $this->get('/customer/menu')->assertOk();
        $this->assertSame('dine_in', session('order_type'));
    }

    public function test_running_the_migration_twice_changes_nothing_the_second_time(): void
    {
        $table = $this->legacyTable();

        $this->migration()->up();
        $once = $table->fresh()->toArray();

        $this->migration()->up();
        $this->assertSame($once, $table->fresh()->toArray());
    }

    public function test_down_restores_the_old_codes_but_not_a_table_regenerated_since(): void
    {
        $branch = $this->branch();
        $kept = $this->legacyTable($branch);
        $regenerated = $this->legacyTable($branch);
        [$keptOld, $regeneratedOld] = [$kept->code, $regenerated->code];

        $this->migration()->up();

        // An admin regenerates one table after the migration.
        TableEntry::rotateCode($regenerated->fresh(), $this->admin()->id);
        $regeneratedNow = $regenerated->fresh()->code;

        $this->migration()->down();

        $kept = $kept->fresh();
        $this->assertSame($keptOld, $kept->code, 'down() restores the eight-character code');
        $this->assertNull($kept->previous_code);
        $this->assertNull($kept->code_rotated_at);

        $this->assertSame($regeneratedNow, $regenerated->fresh()->code, 'a table regenerated since keeps its new code');
        $this->assertNotSame($regeneratedOld, $regenerated->fresh()->code);

        // And the restored code opens the table again.
        $this->typeCode($keptOld)->assertRedirect(route('customer.menu'));
    }

    // ══════════ 7. admin: register and regenerate ══════════

    public function test_regenerate_code_gives_four_characters_and_a_matching_qr(): void
    {
        $table = $this->legacyTable();

        $res = $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/qr-generator/regenerate-code', ['table_id' => $table->id])
            ->assertOk();

        $this->assertNewFormat($res->json('code'));
        $this->assertSame($table->code, $res->json('previous_code'));

        parse_str(parse_url($res->json('url'), PHP_URL_QUERY), $params);
        $this->assertSame($res->json('code'), $params['k']);
    }

    public function test_a_new_table_card_carries_a_four_character_code(): void
    {
        $branch = $this->branch();

        $res = $this->actingAs($this->admin(), 'admin')
            ->getJson('/admin/qr-generator/table-card?branch_id=' . $branch->id . '&table_number=N' . random_int(100, 999))
            ->assertOk();

        $this->assertNewFormat($res->json('code'));
        $this->assertStringEndsWith('&k=' . $res->json('code'), $res->json('url'));
    }

    // ══════════ visible wording and the input boxes ══════════

    public function test_both_code_boxes_take_four_characters_in_capitals(): void
    {
        foreach (['/customer/dineinqr' => 'tableCodeInput', '/' => 'welcomeTableCodeInput'] as $page => $id) {
            $html = $this->get($page)->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<input\s[^>]*id="' . $id . '"[^>]*>/s',
                $html,
                "{$page}: code box missing"
            );
            preg_match('/<input\s[^>]*id="' . $id . '"[^>]*>/s', $html, $m);

            $this->assertStringContainsString('maxlength="4"', $m[0], "{$page}: maxlength");
            $this->assertStringContainsString('autocapitalize="characters"', $m[0], "{$page}: autocapitalize");
            $this->assertStringContainsString('autocomplete="off"', $m[0], "{$page}: autocomplete");
        }

        $this->assertStringContainsString('4-character code', $this->get('/customer/dineinqr')->getContent());
    }

    public function test_no_visible_copy_still_says_eight_characters(): void
    {
        $admin = $this->actingAs($this->admin(), 'admin')->get('/admin/qr-generator')->assertOk()->getContent();
        $this->assertStringContainsString('4-character code', $admin);

        foreach (['/customer/dineinqr', '/customer/help', '/'] as $page) {
            $html = strtolower($this->get($page)->getContent());
            $this->assertStringNotContainsString('8 characters', $html, $page);
            $this->assertStringNotContainsString('8-character', $html, $page);
            $this->assertStringNotContainsString('eight characters', $html, $page);
        }
    }
}

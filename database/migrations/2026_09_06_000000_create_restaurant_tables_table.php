<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The register of physical tables, and the PERMANENT code printed on each one.
 *
 * WHY THIS TABLE HAS TO EXIST AT ALL
 * ----------------------------------
 * Until now a "table" was not a thing in this database. It was a free-text
 * string on orders.table_number, invented on the spot by whoever typed it, and
 * nothing anywhere held the list of tables a branch actually has. That was fine
 * while the only durable table artefact was a QR encoding (branch, table) —
 * there was nothing to store. A permanent per-table code is different: it is a
 * value that must be allocated once, kept unique across every table, and looked
 * up by a customer typing it. That needs a row.
 *
 * WHAT THIS MIGRATION DOES TO EXISTING DATA
 * -----------------------------------------
 * Nothing. It is purely additive:
 *   - It CREATES one new table and writes only into it.
 *   - It does not ALTER, UPDATE or DELETE a single row of orders,
 *     table_access_codes, table_sessions, branches or anything else.
 *   - The live table_sessions row (an open occupancy) is not read for writing,
 *     not modified, and keeps holding its table straight through the migration.
 *   - Historical table_access_codes rows are left exactly as they are. Those
 *     codes are still meaningful: the staff-issued single-use code remains a
 *     supported door (see App\Services\TableAccessCode), so nothing about them
 *     is obsolete and nothing is discarded.
 *
 * The backfill READS existing rows to discover which tables really exist, then
 * INSERTS a registry row for each one. Every table the business has ever seated
 * a dine-in party at therefore comes out of this migration with a code, and the
 * NOT NULL + UNIQUE constraints on `code` make "a table with no code"
 * unrepresentable from here on.
 *
 * Sources for the backfill, in order of authority:
 *   1. orders             — every branch/table pair ever actually used
 *   2. table_sessions     — including any occupancy open right now
 *   3. table_access_codes — tables staff have issued a counter code for
 *
 * down() drops the table. Nothing else has to be undone, precisely because
 * nothing else was changed.
 */
return new class extends Migration
{
    /**
     * Read-aloud and thumb-typing friendly: no 0/O and no 1/I/L.
     *
     * Byte-identical to the alphabet App\Services\TableAccessCode already uses
     * for counter codes, so staff reading a code out over a noisy room have
     * exactly one character set to think about rather than two.
     */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * Eight characters, where a staff counter code is six.
     *
     * Two reasons, and the second is the load-bearing one:
     *
     *   1. A permanent code cannot be revoked by waiting, so it has to survive
     *      being guessed at over months rather than over ten minutes. 31 to the
     *      8th is about 8.5e11 — with the entry throttle in front of it,
     *      guessing is not a strategy.
     *
     *   2. The two kinds of code can then never be confused for one another. A
     *      typed string of six characters is a counter code and eight is a table
     *      code, so the two lookups can be tried in a defined order with no
     *      possibility of one code accidentally being valid in the other's
     *      namespace.
     */
    private const CODE_LENGTH = 8;

    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            // Same shape as orders.table_number, table_access_codes.table_number
            // and table_sessions.table_number. Always stored uppercased.
            $table->string('table_number', 10);

            /*
             * THE PERMANENT CODE. Unique across every table in every branch, so
             * a customer typing it identifies one physical table with no branch
             * context needed. varchar(12) leaves room above CODE_LENGTH without
             * committing to a longer format.
             */
            $table->string('code', 12)->unique();

            // A table taken out of service. Its code stays allocated (so it can
            // never be handed to a different table) but stops opening a session.
            $table->boolean('is_active')->default(true);

            /*
             * Regeneration audit. When an admin rotates a table's code because
             * it has been abused, the code it replaced is kept here rather than
             * vanishing — so "which code was on Table 5 last month" stays an
             * answerable question. The old value is NOT usable: only `code` is
             * ever looked up.
             */
            $table->string('previous_code', 12)->nullable();
            $table->timestamp('code_rotated_at')->nullable();
            $table->foreignId('code_rotated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One registry row per physical table.
            $table->unique(['branch_id', 'table_number']);

            // The admin "all tables at this branch" listing.
            $table->index(['branch_id', 'is_active']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_tables');
    }

    /**
     * Give every table that demonstrably exists a permanent code.
     *
     * Deliberately conservative: it invents nothing. A branch that has never
     * seated a dine-in party gets no rows, because guessing "branches probably
     * have tables 1 to 20" would print codes for tables that do not exist.
     * Admins add tables from the QR and Table Codes page.
     */
    private function backfill(): void
    {
        $pairs = [];

        $collect = function (string $tableName) use (&$pairs) {
            if (!Schema::hasTable($tableName)) {
                return;
            }

            $rows = DB::table($tableName)
                ->select('branch_id', 'table_number')
                ->whereNotNull('branch_id')
                ->whereNotNull('table_number')
                ->when($tableName === 'orders', fn ($q) => $q->where('type', 'dine_in'))
                ->distinct()
                ->get();

            foreach ($rows as $row) {
                $number = strtoupper(trim((string) $row->table_number));

                // Apply exactly the shape rule the entry path enforces, so the
                // registry can never contain a table nothing could ever open.
                if ($number === '' || strlen($number) > 10 || !preg_match('/^[A-Z0-9]+$/', $number)) {
                    continue;
                }

                $pairs[((int) $row->branch_id) . ':' . $number] = [
                    'branch_id'    => (int) $row->branch_id,
                    'table_number' => $number,
                ];
            }
        };

        $collect('orders');
        $collect('table_sessions');
        $collect('table_access_codes');

        if (!$pairs) {
            return;
        }

        $branchIds = DB::table('branches')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $now = now();
        $used = [];
        $insert = [];

        foreach ($pairs as $pair) {
            // A table_number can outlive its branch in a restored dump; the FK
            // would reject it and take the whole migration down with it.
            if (!in_array($pair['branch_id'], $branchIds, true)) {
                continue;
            }

            $code = $this->allocateCode($used);
            $used[$code] = true;

            $insert[] = $pair + [
                'code'       => $code,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, 100) as $chunk) {
            DB::table('restaurant_tables')->insert($chunk);
        }
    }

    /** A code not already taken in this run nor already in the table. */
    private function allocateCode(array $used): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = '';

            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            if (!isset($used[$code]) && !DB::table('restaurant_tables')->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not allocate a unique permanent table code.');
    }
};

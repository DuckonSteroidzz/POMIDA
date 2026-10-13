<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-issue every table code in the four-character format (Oct 2026).
 *
 * WHY
 * ---
 * The defence panel asked for a code customers can type more easily: eight
 * characters became four, same alphabet (no 0/O, no 1/I/L). App\Services\
 * TableEntry now draws four-character codes for new tables and for Regenerate
 * Code; this migration brings the tables that already exist into line.
 *
 * WHAT IT DOES TO EXISTING DATA
 * -----------------------------
 * Touches ONE table, restaurant_tables, and only rows whose code is not
 * already four characters — so running it twice, or after some tables were
 * already regenerated in the new format, changes nothing the second time.
 * For each such row, inside one transaction (all or nothing):
 *
 *   previous_code   := the old eight-character code (audit, and down())
 *   code            := a fresh four-character code, unique across all tables
 *   code_rotated_at := now
 *   code_rotated_by := NULL  (a system change, not an admin's)
 *
 * Every printed card stops working the moment this commits — both its QR
 * (which carries the code as `k`) and its typed code. That is unavoidable
 * when the code itself changes, and it is why the cards must be reprinted
 * straight after. A customer who scans or types an old card in the meantime
 * is told the card is out of date and to ask staff (TableEntry::
 * ERR_CARD_OUTDATED), not shown a generic error.
 *
 * WHAT IT DOES NOT TOUCH
 * ----------------------
 * Live dine-in sessions: table_sessions holds no code, and a seated session is
 * re-checked against the table's CURRENT code, so nobody already seated is
 * moved, logged out or interrupted. Orders, branches and every other table
 * are not read for writing.
 *
 * WHAT IS LOST
 * ------------
 * A table whose code had already been regenerated before this ran had its
 * earlier replaced code in previous_code; that value is overwritten here (the
 * column holds one). It is written to the application log first, one line
 * per table, so the audit trail survives without a backup table. Those codes
 * were already dead and stay dead.
 *
 * down() puts the eight-character codes back from previous_code for every row
 * this migration changed and nobody has regenerated since. It cannot restore
 * the overwritten earlier previous_code or rotation time (see the log), and a
 * table regenerated after this ran keeps its new code.
 */
return new class extends Migration
{
    /** Same alphabet App\Services\TableEntry uses, copied so this file never drifts. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const CODE_LENGTH = 4;

    private const LEGACY_CODE_LENGTH = 8;

    public function up(): void
    {
        DB::transaction(function () {
            $rows = DB::table('restaurant_tables')
                ->whereRaw('CHAR_LENGTH(code) <> ?', [self::CODE_LENGTH])
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'branch_id', 'table_number', 'code', 'previous_code', 'code_rotated_at', 'code_rotated_by']);

            if ($rows->isEmpty()) {
                return;
            }

            // Codes already in the new format (tables added or regenerated
            // after the new code shipped) are taken; a new code must avoid them
            // and every code drawn earlier in this run. An old eight-character
            // code can never equal a four-character one, so those need no check.
            $taken = DB::table('restaurant_tables')
                ->whereRaw('CHAR_LENGTH(code) = ?', [self::CODE_LENGTH])
                ->lockForUpdate()
                ->pluck('code')
                ->flip()
                ->map(fn () => true)
                ->all();

            $now = now();

            foreach ($rows as $row) {
                if ($row->previous_code !== null) {
                    Log::info('Table code format migration: replacing an earlier rotation record.', [
                        'restaurant_table_id'   => $row->id,
                        'branch_id'             => $row->branch_id,
                        'table_number'          => $row->table_number,
                        'earlier_previous_code' => $row->previous_code,
                        'earlier_rotated_at'    => $row->code_rotated_at,
                        'earlier_rotated_by'    => $row->code_rotated_by,
                    ]);
                }

                $code = $this->draw($taken);
                $taken[$code] = true;

                DB::table('restaurant_tables')->where('id', $row->id)->update([
                    'previous_code'   => $row->code,
                    'code'            => $code,
                    'code_rotated_at' => $now,
                    'code_rotated_by' => null,
                    'updated_at'      => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Exactly the rows up() changed and nobody has regenerated since:
            // a four-character code, an eight-character previous_code, and no
            // admin recorded against the rotation. An admin regenerating a
            // table afterwards writes a four-character previous_code and an
            // admin id, so those rows are left alone.
            $rows = DB::table('restaurant_tables')
                ->whereRaw('CHAR_LENGTH(code) = ?', [self::CODE_LENGTH])
                ->whereRaw('CHAR_LENGTH(previous_code) = ?', [self::LEGACY_CODE_LENGTH])
                ->whereNull('code_rotated_by')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'previous_code']);

            $now = now();

            foreach ($rows as $row) {
                DB::table('restaurant_tables')->where('id', $row->id)->update([
                    'code'            => $row->previous_code,
                    'previous_code'   => null,
                    'code_rotated_at' => null,
                    'updated_at'      => $now,
                ]);
            }
        });
    }

    /** A fresh four-character code from a cryptographically secure source. */
    private function draw(array $taken): string
    {
        $max = strlen(self::ALPHABET) - 1;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = '';

            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, $max)];
            }

            if (!isset($taken[$code])) {
                return $code;
            }
        }

        // Rolls the whole transaction back: no table is left half-migrated.
        throw new \RuntimeException('Could not allocate a unique four-character table code.');
    }
};

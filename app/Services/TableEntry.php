<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\RestaurantTable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * TableEntry — the ONE function every dine-in door validates through, plus the
 * allocation and rotation of the permanent per-table code.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS CLASS EXISTS: ONE VALIDATION FUNCTION, THREE DOORS
 * ─────────────────────────────────────────────────────────────────────────────
 * A customer can arrive at a dine-in session three ways:
 *
 *   1. our in-page camera scanner   POST /customer/dineinqr  (tableData)
 *   2. a typed code                 POST /customer/dineinqr  (table_code)
 *   3. their phone's own camera app GET  /customer/menu?branch_id=&table=
 *
 * Each one starts with a DIFFERENT shape of untrusted input, and each one used
 * to do its own checking. That is the failure mode this class removes: three
 * check-lists drift, and the weakest of them is the one that matters.
 *
 * So each door does exactly one job — turn its own input shape into a candidate
 * (branch id, table number) pair — and then every one of them calls
 * validate(). validate() is the only place a refusal is ever decided, which
 * means a refusal added there is added to all three doors at once and cannot be
 * dropped from one of them by accident.
 *
 *   scanner ──► QrPayload::parse() ──┐
 *   URL     ──► query params      ───┼──► TableEntry::validate() ──► ok / refusal
 *   code    ──► code lookup       ───┘
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE PERMANENT CODE, AND WHAT IT COSTS
 * ─────────────────────────────────────────────────────────────────────────────
 * Each table now carries one code that never expires and is never consumed by
 * use, printed on the acrylic standee next to its QR. That is what the business
 * needs: a customer whose camera will not focus can type eight characters
 * instead of finding a staff member, every single time, forever.
 *
 * Be honest about the other half of that. A permanent code is a PERMANENT
 * CREDENTIAL. Anyone who has ever seen the standee — a customer from six months
 * ago, someone who walked past, someone holding a photo — can open a dine-in
 * session for that table from anywhere, indefinitely. Nothing in this class
 * changes that; it is the accepted trade-off.
 *
 * What makes it acceptable is the set of controls around it, none of which are
 * optional:
 *
 *   - Occupancy is SHARED, not exclusive (TableOccupancy::claim). A second
 *     scanner joins the party's existing session rather than opening a rival
 *     one, so a stranger with the code lands inside a session staff are already
 *     looking at rather than creating a second, invisible one.
 *   - Sessions END BY THEMSELVES — on order completion, and after
 *     TableOccupancy::INACTIVITY_MINUTES of silence — so a stale credential
 *     cannot accumulate ghost tables.
 *   - Session creation is RATE LIMITED (the `table-session` limiter), so the
 *     code cannot be used to spawn sessions in a loop.
 *   - An admin can REGENERATE one table's code (rotateCode below). That is the
 *     escape hatch: the moment a code is being abused it is replaced, the old
 *     one stops working immediately, and only that table is reprinted.
 *
 * The QR URL is deliberately unsigned, for the reasons set out at length in
 * App\Services\QrPayload — a signature raises the bar for inventing a URL, not
 * for photographing a real one, which is the actual threat here.
 */
class TableEntry
{
    /**
     * Read-aloud and thumb-typing friendly: no 0/O and no 1/I/L, the two
     * confusions that actually happen when someone reads a code off a card.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * Eight characters — see the migration that creates restaurant_tables for
     * the full reasoning. In short: a code that can never expire needs to
     * survive being guessed at over months rather than minutes, and 31^8 is
     * about 8.5e11 combinations.
     *
     * (This used to also need to differ from a second, shorter, staff-issued
     * code so the two could never collide. That second code is gone — this is
     * now the only kind of table code — but the length itself is unchanged,
     * since the guessing-resistance reasoning stands on its own.)
     */
    public const CODE_LENGTH = 8;

    /** Longest table number the schema and the entry path accept. */
    public const MAX_TABLE_LENGTH = 10;

    /**
     * Attempts allowed per IP per minute on the customer-facing typed-code
     * submission (POST /customer/dineinqr with table_code).
     *
     * This is now the only throttle standing between a guesser and the door —
     * there is no second, shorter-lived code to fall back on any more, so this
     * number carries the full weight of making guessing pointless rather than
     * merely slow. Ten a minute against an 8-character, 31-symbol alphabet
     * (8.5e11 combinations) means an attacker gains nothing from the ceiling
     * being low; it exists to stop a script iterating quickly, not to make a
     * human guesser's odds meaningfully different.
     */
    public const MAX_ATTEMPTS = 10;

    // ══════════ refusal messages ══════════
    //
    // Every one of these is a sentence a customer standing in a cafe can act
    // on. None of them is a status code, a stack trace or a blank page.

    public const ERR_MALFORMED = 'That table code is not valid. Please scan the QR on your table, or type the code printed underneath it.';
    public const ERR_NO_BRANCH = 'That table QR points to a branch that no longer exists. Please ask our staff for help.';
    public const ERR_CLOSED_BRANCH = 'That branch is currently closed. Please ask our staff for help.';
    public const ERR_TABLE_INACTIVE = 'That table is not in service right now. Please ask our staff to seat you.';

    /**
     * Shown when a QR carries no code, a stale code, or a code that never
     * matched this table — the "someone photographed the old card" case. It is
     * not an accusation: an ordinary customer holding a reprinted table's old
     * card lands here, and the fix is simply to type the code now printed on
     * the card. The camera doors redirect to the code-entry page with this.
     */
    public const ERR_QR_STALE = 'This QR code is out of date. Please enter the code printed on your table card, or ask our staff for help.';

    /**
     * ══════════════════════════════════════════════════════════════════════
     * THE single validation function. Every dine-in door ends up here.
     * ══════════════════════════════════════════════════════════════════════
     *
     * $branchId and $tableNumber are RAW and UNTRUSTED. They arrive from a
     * query string a stranger can edit, from bytes a camera decoded, or from a
     * code row. Nothing above this point may assume anything about their type.
     *
     * Every refusal the malformed-payload suite requires is decided here:
     * non-scalar (array injection), non-integer, negative, zero, hex, float,
     * whitespace-padded and SQL-ish branch ids; empty, over-long, non-ASCII,
     * markup-bearing and punctuation-bearing table numbers; branches that do
     * not exist; branches that are closed; and tables taken out of service.
     *
     * $code is the permanent per-table secret, and it now guards EVERY door,
     * not just the typed one. The QR carries it as `k`; the typed door passes
     * the string the customer keyed. A missing, stale or wrong code is refused
     * with ERR_QR_STALE — the same refusal for all three, so nothing here tells
     * an outsider whether a given (branch, table) is registered. previous_code
     * is never consulted; only `code` is. This is the ONE place the rule lives.
     *
     * @return array{ok: bool, branch?: Branch, table?: ?RestaurantTable, table_number?: string, error?: string}
     */
    public static function validate($branchId, $tableNumber, $code = null): array
    {
        $branchId = self::cleanBranchId($branchId);
        $tableNumber = self::cleanTableNumber($tableNumber);

        if ($branchId === null || $tableNumber === null) {
            return self::fail(self::ERR_MALFORMED);
        }

        $branch = Branch::find($branchId);

        if (!$branch) {
            return self::fail(self::ERR_NO_BRANCH);
        }

        if (!$branch->is_active) {
            return self::fail(self::ERR_CLOSED_BRANCH);
        }

        /*
         * The registry row is looked up but NOT required.
         *
         * Requiring it would mean a table that has never had its card printed
         * — a new table pushed into the corner on a busy Saturday — refuses a
         * customer who is genuinely sitting at it, which is a worse failure
         * than the one it would prevent. A well-formed table number at an open
         * branch has always been accepted here and still is; the registry adds
         * a code and a printable card, it does not become a gate.
         *
         * What the registry DOES gate is a table explicitly taken OUT of
         * service. That is a deliberate admin decision and it is honoured.
         */
        $table = self::find($branch->id, $tableNumber);

        if ($table && !$table->is_active) {
            return self::fail(self::ERR_TABLE_INACTIVE);
        }

        /*
         * The permanent code. Checked AFTER every structural refusal above, so
         * a closed branch or an out-of-service table still produces its own
         * message rather than this one.
         *
         * An unregistered table has no code and so cannot pass here any more —
         * that is deliberate. The only way a QR exists is that an admin printed
         * a card, which registers the table; a well-formed table number with no
         * card behind it is exactly the "editable address bar" case this closes.
         *
         * normalise() is the SAME folding the typed-code door applies, so "k4 m9"
         * off a URL and "K4M9…" typed by hand compare equal. previous_code is
         * never looked at.
         */
        $providedCode = is_scalar($code) ? self::normalise((string) $code) : '';

        if ($table === null || $providedCode === '' || $providedCode !== $table->code) {
            return self::fail(self::ERR_QR_STALE);
        }

        return [
            'ok'           => true,
            'branch'       => $branch,
            'table'        => $table,
            'table_number' => $tableNumber,
        ];
    }

    /**
     * Door 2: a customer typed the permanent code off the standee.
     *
     * Resolves the code to its table, then hands the result to validate() —
     * the same function the scanner and the URL landing use — so a typed code
     * gets every refusal a scan gets. A code for a branch that has since closed
     * is refused by validate(), not here.
     *
     * Returns null when the string is not the right SHAPE for a permanent code
     * (wrong length) or does not match any table. The caller (AuthController::
     * processQr()) folds that into the same one-sentence refusal as a shaped
     * code that failed validate() — deliberately: telling a customer "that
     * code does not exist" versus "that code is wrong somehow" would let
     * someone probe for which codes are real.
     *
     * @return array{ok: bool, branch?: Branch, table?: ?RestaurantTable, table_number?: string, error?: string}|null
     */
    public static function resolveCode(?string $code): ?array
    {
        $normalised = self::normalise($code);

        if (strlen($normalised) !== self::CODE_LENGTH) {
            return null;
        }

        $table = RestaurantTable::where('code', $normalised)->first();

        if (!$table) {
            return null;
        }

        // The same code we just matched is handed to validate()'s own code
        // check — one rule, one place. It re-confirms rather than trusts.
        return self::validate($table->branch_id, $table->table_number, $normalised);
    }

    /**
     * Uppercase and strip everything outside the alphabet's character set, so
     * "tbl-a3 c9" and "TBLA3C9" are the same code. Case-insensitivity on entry
     * is the point: nobody types a printed code in the case it was printed in.
     */
    public static function normalise(?string $code): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $code))) ?? '';
    }

    // ══════════ registry ══════════

    /** The registry row for a table, or null if it has never been registered. */
    public static function find(int $branchId, string $tableNumber): ?RestaurantTable
    {
        return RestaurantTable::where('branch_id', $branchId)
            ->where('table_number', strtoupper(trim($tableNumber)))
            ->first();
    }

    /**
     * The registry row for a table, registering it with a fresh permanent code
     * if this is the first time anyone has asked for it.
     *
     * ONLY ever called from admin-authenticated, throttled endpoints. A
     * customer-facing path must never reach this: registering a table is a
     * decision about the physical room, and letting an anonymous request create
     * rows would turn a URL a stranger can edit into unbounded row creation.
     */
    public static function findOrRegister(int $branchId, string $tableNumber): RestaurantTable
    {
        $tableNumber = strtoupper(trim($tableNumber));

        $existing = self::find($branchId, $tableNumber);

        if ($existing) {
            return $existing;
        }

        // The unique index on (branch_id, table_number) is the real guarantee;
        // this catches two admins pressing Generate for the same new table at
        // the same instant and returns the row that won rather than a 500.
        try {
            return RestaurantTable::create([
                'branch_id'    => $branchId,
                'table_number' => $tableNumber,
                'code'         => self::allocateCode(),
                'is_active'    => true,
            ]);
        } catch (QueryException $e) {
            if (!self::isDuplicateKey($e)) {
                throw $e;
            }

            return self::find($branchId, $tableNumber)
                ?? throw $e;
        }
    }

    /**
     * Regenerate ONE table's permanent code.
     *
     * This is the escape hatch that makes a permanent credential defensible: the
     * moment a code is being abused, an admin replaces it, the old code stops
     * working on the next request, and only that one table's card is reprinted.
     *
     * Scoped to a single row by primary key inside a transaction, and it writes
     * nothing anywhere else. Regenerating Table 5 must leave Table 6, and every
     * table at every other branch, byte-for-byte untouched — a "rotate all the
     * codes" button would force a reprint of every standee in the company and
     * is not a thing this system can do.
     *
     * The replaced code is kept in previous_code for the audit trail. It is not
     * usable: only `code` is ever looked up.
     */
    public static function rotateCode(RestaurantTable $table, ?int $rotatedBy = null): RestaurantTable
    {
        return DB::transaction(function () use ($table, $rotatedBy) {
            $old = $table->code;

            $table->forceFill([
                'previous_code'   => $old,
                'code'            => self::allocateCode(),
                'code_rotated_at' => now(),
                'code_rotated_by' => $rotatedBy,
            ])->save();

            return $table->refresh();
        });
    }

    /**
     * Every registered table at a branch (or everywhere), in the order a person
     * reads them: Table 2 before Table 10, A1 before B1.
     */
    public static function listFor($branchId = null)
    {
        return RestaurantTable::with('branch')
            ->when($branchId && $branchId !== 'all', fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('branch_id')
            ->orderByRaw('LENGTH(table_number), table_number')
            ->get();
    }

    // ══════════ internals ══════════

    /**
     * Allocate a code no table currently holds.
     *
     * The UNIQUE index on restaurant_tables.code is the actual guarantee; this
     * loop only spares the caller a duplicate-key error on a collision that,
     * at 31^8, will not happen.
     */
    public static function allocateCode(): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = '';

            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            if (!RestaurantTable::where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not allocate a unique permanent table code.');
    }

    /**
     * A branch id must be a plain positive integer and nothing else.
     *
     * is_scalar() is the important guard: parse_str("branch_id[]=1") and a JSON
     * object both hand back arrays, and passing an array into a where() clause
     * either resolved to the wrong branch or threw. ctype_digit then rejects
     * "1 OR 1=1", "1.9", "-1", "0x1" and " 1" — all of which a plain (int) cast
     * would have happily turned into 1.
     */
    private static function cleanBranchId($value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = (string) $value;

        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * A table number must be 1-10 alphanumeric characters, uppercased.
     *
     * Anything else — arrays, markup, null bytes, full-width digits, 500-digit
     * numbers — is not a table number, and letting one through only defers the
     * failure to the INSERT that happens after the customer has already built a
     * cart. Uppercasing here is what makes a scan of "a3" and a code for "A3"
     * land on the same physical table instead of two.
     */
    private static function cleanTableNumber($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = strtoupper(trim((string) $value));

        if ($value === '' || strlen($value) > self::MAX_TABLE_LENGTH) {
            return null;
        }

        return preg_match('/^[A-Z0-9]+$/', $value) === 1 ? $value : null;
    }

    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }

    private static function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}

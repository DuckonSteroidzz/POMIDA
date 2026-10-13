<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\RestaurantTable;
use App\Providers\RateLimitServiceProvider;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

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
 * needs: a customer whose camera will not focus can type four characters
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
 *   - Code GUESSING has its own, much tighter budget (throughCodeBudget()),
 *     shared by every door that checks a code, and no door's answer says
 *     whether a code was real (see validateQr() and resolveCode()).
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
     * Four characters (Oct 2026 — defence panel: eight was too long to type
     * off a card). 31^4 = 923,521 combinations.
     *
     * That space is small enough that the length is no longer the protection
     * on its own: the failed-code budget below is. Eight characters (8.5e11)
     * made guessing hopeless at any rate; four makes it hopeless only at the
     * rate the budget allows. Do not raise the budget without redoing the
     * arithmetic beside MAX_FAILED_PER_IP.
     */
    public const CODE_LENGTH = 4;

    /**
     * The length every code had before the Oct 2026 change, and therefore the
     * length of every code printed on a card before the reprint.
     *
     * Recognised ONLY to answer "this card is out of date" (ERR_CARD_OUTDATED)
     * instead of a bare "not valid". The decision is made from the string's
     * SHAPE alone — never from previous_code — so it tells a guesser nothing a
     * glance at the code's length would not. An eight-character code that is
     * still somebody's CURRENT code (the minutes between deploying this and
     * running the migration) is matched like any other code and keeps working.
     */
    public const LEGACY_CODE_LENGTH = 8;

    /** Longest table number the schema and the entry path accept. */
    public const MAX_TABLE_LENGTH = 10;

    /** Fresh draws allocateCode() tries before giving up on a full space. */
    public const ALLOCATE_ATTEMPTS = 50;

    /** Writes retried when the UNIQUE index on code refuses one (a race). */
    private const WRITE_ATTEMPTS = 5;

    /**
     * THE FAILED-CODE BUDGET — the control that makes four characters safe.
     *
     * Every door that checks a code (typed code, Change table, the QR URL, the
     * old in-page scanner) spends the SAME two counters, and only on a failure
     * that could have been a guess. While either counter is full every door
     * refuses before looking at the code, a correct one included, otherwise
     * the budget would protect nothing. A success does NOT reset the counters:
     * it used to, which let anyone holding one real code wipe the café's count
     * after every few guesses.
     *
     * Per device (the customer account, else the browser session — see
     * RateLimitServiceProvider::visitorKey()): 5 failures per 10 minutes. A
     * real customer mistypes once or twice; five lets them fumble and still
     * stops one phone looping.
     *
     * Per IP: 30 failures per hour. This is the one a script meets, because it
     * can throw cookies away but not its address. Every phone on the shop
     * wi-fi shares one IP, so this is sized for a whole café's typos in an
     * hour, not one person's.
     *
     * The arithmetic, against the full 923,521-code space:
     *   - Typed code (any table's code wins): with T registered tables one
     *     guess succeeds with probability T / 923,521. At 30 guesses an hour
     *     from one address and T = 13 (today), the expected wait for a first
     *     hit is 923,521 / (13 × 30) ≈ 2,370 hours ≈ 99 days of non-stop
     *     guessing; at T = 50, about 26 days.
     *   - QR URL (one known table): 923,521 / 2 / 30 ≈ 15,400 hours ≈ 640
     *     days to a 50% chance.
     * Many addresses at once (a botnet) divide those numbers by the number of
     * addresses; that residual risk is why rotateCode() exists, and the log
     * line in recordFailedCode() is how staff would notice.
     *
     * The trade-off, stated plainly: someone INSIDE the café can spend the
     * café's hourly budget on purpose, which stops new dine-in entries from
     * that wi-fi for up to an hour (counter orders and seated customers are
     * unaffected). That is the price of a per-IP limit and the reason the
     * window is an hour, not a day.
     */
    public const MAX_FAILED_PER_DEVICE = 5;
    public const DEVICE_WINDOW_SECONDS = 600;
    public const MAX_FAILED_PER_IP = 30;
    public const IP_WINDOW_SECONDS = 3600;

    /**
     * Seam for tests only: a callable returning the next candidate code, so
     * the collision-retry loop in allocateCode() can be driven
     * deterministically. Null in production — random_int() is used.
     */
    private static ?Closure $codeSource = null;

    // ══════════ refusal messages ══════════
    //
    // Every one of these is a sentence a customer standing in a cafe can act
    // on. None of them is a status code, a stack trace or a blank page.

    public const ERR_MALFORMED = 'That table code is not valid. Please scan the QR on your table, or type the code printed underneath it.';
    public const ERR_NO_BRANCH = 'That table QR points to a branch that no longer exists. Please ask our staff for help.';
    public const ERR_CLOSED_BRANCH = 'That branch is currently closed. Please ask our staff for help.';
    public const ERR_TABLE_INACTIVE = 'That table is not in service right now. Please ask our staff to seat you.';

    /**
     * The table this customer's code was validated against was deleted (a
     * manager removing an unused typo table from the Move table list) in the
     * instant between validate() and TableOccupancy::claim(). Decided under the
     * registry row's lock — see claim().
     */
    public const ERR_TABLE_NOT_FOUND = 'That table could not be found. Please ask our staff to seat you.';

    /**
     * The QR doors' one refusal for anything involving the code: no `k`, a
     * wrong `k`, a regenerated-away `k`, or a table taken out of service (see
     * validateQr() for why that last one is folded in). The camera doors
     * redirect to the code-entry page with this.
     */
    public const ERR_QR_STALE = 'This QR code did not work. Please type the code printed under it, or ask our staff for help.';

    /**
     * The typed doors' one refusal: a code that matches nothing, AND a real
     * code whose table is out of service or whose branch is closed. Saying
     * which would confirm to a guesser that they had hit a real code.
     */
    public const ERR_CODE_INVALID = 'That code is not valid. Please check the code printed on your table, or ask our staff for help.';

    /**
     * An old-format (pre-Oct 2026) code that matches no table, typed or in a
     * QR. Decided from the code's length and alphabet alone — see
     * LEGACY_CODE_LENGTH — so it reveals nothing about which codes exist.
     */
    public const ERR_CARD_OUTDATED = 'This table card is out of date. Please ask our staff for help — they can give you the new code.';

    /** Any door, while the failed-code budget is spent. */
    public const ERR_TOO_MANY = 'Too many attempts. Please wait a few minutes, or ask our staff for help.';

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

        if ($table === null || $providedCode === '' || !hash_equals((string) $table->code, $providedCode)) {
            // An old-format card gets a message that says so. Shape only: the
            // same answer whether or not any table ever held that code.
            // A missing `k` or an old-format one cannot be a guess at a
            // current code, so neither spends the failed-code budget.
            $legacy = self::isLegacyFormat($providedCode);

            return self::fail(
                $legacy ? self::ERR_CARD_OUTDATED : self::ERR_QR_STALE,
                guess: !$legacy && $providedCode !== ''
            );
        }

        return [
            'ok'           => true,
            'branch'       => $branch,
            'table'        => $table,
            'table_number' => $tableNumber,
        ];
    }

    /**
     * Doors 1 and 3: both shapes of QR (the phone camera opening the URL, and
     * the old in-page scanner). validate() decides; this only chooses how much
     * of the reason a stranger is shown.
     *
     * ERR_TABLE_INACTIVE becomes ERR_QR_STALE. validate() checks a table's
     * service state BEFORE the code, so without this fold a URL with a WRONG
     * `k` for an out-of-service table answered "not in service" — and a
     * guesser working through `k` values for such a table would see the
     * answer change on the right one. Branch-level refusals (closed, missing,
     * malformed) stay specific: they are decided before the code is looked at
     * and say nothing about it.
     *
     * Seated-session re-validation (TableOccupancy) and a confirmed table
     * change (TableChange::revalidate) call validate() directly, with the
     * table's own code: nobody is guessing there, so they keep the precise
     * message.
     *
     * @return array{ok: bool, branch?: Branch, table?: ?RestaurantTable, table_number?: string, error?: string, guess?: bool}
     */
    public static function validateQr($branchId, $tableNumber, $code = null): array
    {
        $result = self::validate($branchId, $tableNumber, $code);

        if (!$result['ok'] && $result['error'] === self::ERR_TABLE_INACTIVE) {
            return self::fail(self::ERR_QR_STALE, guess: true);
        }

        return $result;
    }

    /**
     * Door 2: a customer typed the permanent code off the standee (also the
     * Change table endpoint).
     *
     * Resolves the code to its table, then hands the result to validate() —
     * the same function the scanner and the URL landing use — so a typed code
     * gets every refusal a scan gets.
     *
     * Every refusal comes back as the ONE sentence ERR_CODE_INVALID: a string
     * that matches no table, and equally a REAL code whose table is out of
     * service or whose branch is closed. A typed code is its own lookup key,
     * so any more specific answer would confirm "that code exists" to whoever
     * typed it. The one exception is ERR_CARD_OUTDATED, decided from the
     * string's shape alone (see LEGACY_CODE_LENGTH).
     *
     * @return array{ok: bool, branch?: Branch, table?: ?RestaurantTable, table_number?: string, error?: string, guess?: bool}
     */
    public static function resolveCode(?string $code): array
    {
        $normalised = self::normalise($code);
        $length = strlen($normalised);

        // A current code, or an old-format one that is still some table's
        // current code because the migration has not run yet.
        if ($length === self::CODE_LENGTH || $length === self::LEGACY_CODE_LENGTH) {
            $table = RestaurantTable::where('code', $normalised)->first();

            if ($table) {
                // The same code we just matched is handed to validate()'s own
                // code check — one rule, one place. It re-confirms rather
                // than trusts.
                $result = self::validate($table->branch_id, $table->table_number, $normalised);

                return $result['ok'] ? $result : self::fail(self::ERR_CODE_INVALID, guess: true);
            }
        }

        if (self::isLegacyFormat($normalised)) {
            return self::fail(self::ERR_CARD_OUTDATED);
        }

        return self::fail(self::ERR_CODE_INVALID, guess: true);
    }

    /**
     * Run one code-checking door through the failed-code budget.
     *
     * Every door that checks a code calls this with the check as a closure —
     * the typed code, Change table, the QR URL and the in-page scanner — so
     * there is one budget and one place it is enforced. See the constants
     * block beside MAX_FAILED_PER_IP for the numbers and the arithmetic.
     *
     *   - Budget spent: refuse with ERR_TOO_MANY WITHOUT running the check.
     *   - Check failed with guess = true: spend one from each counter.
     *   - Check passed, or failed for a reason that is not a guess (an
     *     old-format card, a closed branch on a QR): spend nothing.
     *
     * @param  Closure(): array  $check
     */
    public static function throughCodeBudget(Request $request, Closure $check): array
    {
        [$deviceKey, $ipKey] = self::budgetKeys($request);

        if (RateLimiter::tooManyAttempts($deviceKey, self::MAX_FAILED_PER_DEVICE)
            || RateLimiter::tooManyAttempts($ipKey, self::MAX_FAILED_PER_IP)) {
            return self::fail(self::ERR_TOO_MANY);
        }

        $result = $check();

        if (!$result['ok'] && ($result['guess'] ?? false)) {
            RateLimiter::hit($deviceKey, self::DEVICE_WINDOW_SECONDS);
            RateLimiter::hit($ipKey, self::IP_WINDOW_SECONDS);

            // The moment an address spends its whole hourly budget, say so
            // once — the signal that someone may be guessing, and that a
            // table's code may need regenerating.
            if (RateLimiter::attempts($ipKey) === self::MAX_FAILED_PER_IP) {
                Log::warning('Table code guessing budget exhausted for an address.', [
                    'ip' => $request->ip(),
                ]);
            }
        }

        return $result;
    }

    /**
     * The two counters. The per-IP key keeps the name the typed door has
     * always used ('table-code:' . ip), so existing tooling and tests that
     * clear it keep clearing the right thing.
     *
     * @return array{0: string, 1: string}
     */
    public static function budgetKeys(Request $request): array
    {
        return [
            'table-code-device:' . RateLimitServiceProvider::visitorKey($request),
            'table-code:' . $request->ip(),
        ];
    }

    /**
     * True for a string shaped like a pre-Oct 2026 code: exactly
     * LEGACY_CODE_LENGTH characters, all from the alphabet. Input is expected
     * already normalised.
     */
    public static function isLegacyFormat(string $normalised): bool
    {
        return strlen($normalised) === self::LEGACY_CODE_LENGTH
            && preg_match('/^[' . self::ALPHABET . ']+$/', $normalised) === 1;
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

        // Two unique indexes can refuse this insert. (branch_id, table_number):
        // two admins pressing Generate for the same new table at the same
        // instant — return the row that won rather than a 500. code: another
        // table took the same freshly drawn code between allocateCode()'s
        // check and this write — rare, but no longer astronomically so at
        // four characters, so draw again.
        for ($attempt = 1; ; $attempt++) {
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

                $winner = self::find($branchId, $tableNumber);

                if ($winner) {
                    return $winner;
                }

                if ($attempt >= self::WRITE_ATTEMPTS) {
                    throw $e;
                }
            }
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
        $old = $table->code;

        // Same write-time collision retry as findOrRegister(): the UNIQUE
        // index on code refusing the write means another table took the code
        // just drawn, so draw again rather than 500.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($table, $rotatedBy, $old) {
                    $table->forceFill([
                        'previous_code'   => $old,
                        'code'            => self::allocateCode(),
                        'code_rotated_at' => now(),
                        'code_rotated_by' => $rotatedBy,
                    ])->save();

                    return $table->refresh();
                });
            } catch (QueryException $e) {
                if (!self::isDuplicateKey($e) || $attempt >= self::WRITE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
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
     * loop spares the caller a duplicate-key error, and the write-time retry
     * in findOrRegister()/rotateCode() covers the race between this check and
     * the write. At four characters a collision is a real event once a
     * company has thousands of tables, not a theoretical one: with 1,000
     * tables a single draw collides about one time in 900. Fifty draws all
     * colliding needs the space to be essentially full.
     *
     * random_int() is PHP's cryptographically secure generator. Every code is
     * drawn independently, so knowing one table's code (or its previous_code)
     * says nothing about another's.
     */
    public static function allocateCode(): string
    {
        for ($attempt = 0; $attempt < self::ALLOCATE_ATTEMPTS; $attempt++) {
            $code = self::$codeSource ? (string) (self::$codeSource)() : self::randomCode();

            if (!RestaurantTable::where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not allocate a unique permanent table code.');
    }

    /** Tests only — see $codeSource. Pass null to restore random_int(). */
    public static function useCodeSource(?Closure $source): void
    {
        self::$codeSource = $source;
    }

    private static function randomCode(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
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

    /**
     * $guess marks a refusal that could have been someone guessing a code —
     * the only kind that spends the failed-code budget (throughCodeBudget()).
     */
    private static function fail(string $message, bool $guess = false): array
    {
        return $guess
            ? ['ok' => false, 'error' => $message, 'guess' => true]
            : ['ok' => false, 'error' => $message];
    }

    private static function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}

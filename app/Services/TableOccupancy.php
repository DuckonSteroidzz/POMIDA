<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Order;
use App\Models\TableSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Support\GuestOrders;
use Illuminate\Support\Str;

/**
 * TableOccupancy — ONE SHARED dine-in session per physical table.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SHARED, NOT EXCLUSIVE. READ THIS BEFORE CHANGING claim().
 * ─────────────────────────────────────────────────────────────────────────────
 * This class used to enforce that a table could host at most one session and
 * that a second visitor was REFUSED. That was wrong in the room, not in theory:
 *
 *   Four people sit down at Table 5. All four scan the standee, because that is
 *   what a QR code on a table invites everybody to do. Under the old rule the
 *   second, third and fourth were told "This table currently has an active
 *   order. Please ask our staff for assistance." — in front of the party, for
 *   doing exactly the right thing.
 *
 * So a table now has ONE session and everyone at that table SHARES it. The
 * second, third and fourth scan JOIN the existing session: they are handed its
 * token, its last_seen_at is refreshed, and they carry on to the menu. There is
 * no path through claim() that refuses a well-formed scan because the table is
 * busy. Re-introducing one would break a table of four in front of a panel.
 *
 * The unique index on active_lock has NOT gone away and still matters: it is
 * what guarantees there is exactly ONE session row per live table, which is
 * what the staff panel, the order link and the release logic all depend on.
 * What changed is what happens when a second visitor arrives at a table that
 * already has a row — join it, rather than be turned away.
 *
 * What the session token is still for
 * -----------------------------------
 * It is no longer an admission test. It is the record of who is at the table,
 * and it still does real work:
 *
 *   1. SESSION TOKEN. On claiming or joining, the row's token is stored in the
 *      visitor's Laravel session ('table_session_token'). A refresh, a new tab,
 *      or a re-scan carries the same cookie, so the visitor is recognised as
 *      already seated rather than as a new arrival — and attachOrder() uses it
 *      to link an order to the right table. Same session-cookie identity the
 *      app already uses for guest order tracking via App\Support\GuestOrders.
 *
 *   2. LINKED ORDER OWNERSHIP. If the cookie is lost mid-meal, the occupancy's
 *      linked order is matched against this visitor — the guest order set for a
 *      guest, user_id for a logged-in customer — so someone who demonstrably
 *      owns the order on that table is still recognised, and the token is
 *      re-issued to them.
 *
 * Releasing — a session ENDS BY ITSELF
 * ------------------------------------
 * Three ways, and the first two need nobody to remember anything:
 *
 *   - The linked order reaches completed or cancelled. Driven by the Order
 *     model's saved() hook, so every code path that finishes an order is
 *     covered.
 *   - INACTIVITY_MINUTES pass with no sign of life. See sweepIdle().
 *   - A staff member presses Clear.
 *
 * That automatic half is load-bearing now that the table code is permanent: a
 * session opened with a photographed code has a bounded life whether or not any
 * human notices it.
 */
class TableOccupancy
{
    /**
     * How long a live occupancy with NO order on it may sit silent before it
     * releases itself.
     *
     * WHY 90 MINUTES
     * --------------
     * last_seen_at is refreshed on every menu view, so this counts ninety
     * minutes of a customer doing NOTHING — not ninety minutes of being seated.
     * A party genuinely choosing from the menu touches it constantly; a party
     * that has left touches it never.
     *
     * It is deliberately generous, and generous is now the right direction. Back
     * when occupancy was exclusive, a stale row locked a real table out of
     * service, so a short timeout (thirty minutes) bought something real. Now
     * that occupancy is SHARED, an uncleared row blocks nobody — the next party
     * simply joins it — so the timeout's only remaining job is to stop the
     * Occupied Tables panel filling with ghosts and to bound the life of a
     * session opened with a photographed code. Against those, being late costs
     * a little noise on a staff screen, while being early would clear a table
     * with real people still sitting at it. Ninety minutes is comfortably past
     * the longest plausible gap in a real visit and comfortably short of a
     * session that outlives a whole service.
     *
     * An occupancy WITH an order is exempt: it is released by the order
     * finishing, however long the meal takes. Releasing it on a timer would
     * detach a table from an order that is still cooking.
     */
    public const INACTIVITY_MINUTES = 90;

    public const SESSION_KEY = 'table_session_token';

    /** Statuses that mean the order is over and the table is free. */
    public const FINISHED_STATUSES = ['completed', 'cancelled'];

    /**
     * Retained so nothing that still references it breaks, but NO LONGER
     * PRODUCED by claim(): occupancy is shared, so a well-formed scan of a busy
     * table is never refused. See the class docblock. The refusals a customer
     * can still meet are a malformed payload, an unknown or closed branch, an
     * out-of-service table (all in App\Services\TableEntry) and the
     * session-creation rate limit.
     */
    public const BLOCKED_MESSAGE = 'This table currently has an active order. Please ask our staff for assistance.';

    /**
     * Seat this visitor at a table: open its session, or JOIN the one already
     * there.
     *
     * There is no refusal path here. Whoever scans, in whatever order, ends up
     * on the table's single shared session — see the class docblock for why the
     * second person at a table of four must never be turned away.
     *
     * `continued` reports which of the two happened, for callers and tests that
     * care: true when this visitor joined (or resumed) an existing session,
     * false when this scan opened a new one.
     *
     * @return array{ok: bool, session?: TableSession, error?: string, continued?: bool}
     */
    public static function claim(Branch $branch, string $tableNumber, ?string $ip = null): array
    {
        $tableNumber = strtoupper(trim($tableNumber));
        $lock = self::lockKey($branch->id, $tableNumber);

        // Clear anything that has gone silent BEFORE looking, so a party
        // arriving at a table the last customer walked away from starts a fresh
        // session rather than inheriting a stale one.
        self::sweepIdle();

        // Serialised so two simultaneous scans of the same table cannot both
        // read "free" and both try to insert. The unique index on active_lock
        // is the backstop if they somehow do.
        return DB::transaction(function () use ($branch, $tableNumber, $lock, $ip) {
            $existing = TableSession::where('active_lock', $lock)->lockForUpdate()->first();

            if ($existing) {
                /*
                 * JOIN. This covers both "the same customer coming back round"
                 * (refresh, re-scan, back button) and "the second, third and
                 * fourth person at this table scanning the same standee". The
                 * two are deliberately not distinguished: the table's session
                 * belongs to the table, and everyone sitting at it is entitled
                 * to it.
                 */
                $existing->forceFill(['last_seen_at' => now()])->save();
                session()->put(self::SESSION_KEY, $existing->session_token);

                return ['ok' => true, 'session' => $existing, 'continued' => true];
            }

            return ['ok' => true, 'session' => self::open($branch, $tableNumber, $lock, $ip), 'continued' => false];
        });
    }

    /**
     * Release every live occupancy that has gone silent, so a table cannot stay
     * "occupied" forever because a customer left and nobody pressed Clear.
     *
     * TWO SEPARATE CONDITIONS, and only the first is a timer:
     *
     *   1. No order on it, and nothing seen for INACTIVITY_MINUTES. This is the
     *      customer who scanned, browsed and walked out.
     *
     *   2. Its linked order has already finished. A defensive backstop: the
     *      Order model's saved() hook is what normally releases these, and it
     *      covers every path that changes a status through Eloquent. A row
     *      written by a raw query, a restored dump, or a hook that threw would
     *      otherwise hold its table indefinitely. No timer applies here —
     *      the order is over, so the table is free now.
     *
     * An occupancy with an UNFINISHED order is never touched, at any age. The
     * meal is still happening.
     *
     * WHERE THIS RUNS. There is no scheduler configured in this project, so this
     * is called opportunistically from the two places that are already hitting
     * these rows: every claim(), and every load of the staff Occupied Tables
     * panel (which polls every five seconds while a counter screen is open).
     * That means the sweep runs whenever anyone could possibly notice the
     * difference, with no cron job required.
     *
     * @return int how many occupancies were released
     */
    public static function sweepIdle(): int
    {
        $cutoff = now()->subMinutes(self::INACTIVITY_MINUTES);

        $stale = TableSession::whereNotNull('active_lock')
            ->where(function ($q) use ($cutoff) {
                // 1. Silent, and never produced an order.
                $q->where(function ($inner) use ($cutoff) {
                    $inner->whereNull('order_id')
                        ->where(function ($seen) use ($cutoff) {
                            $seen->where('last_seen_at', '<', $cutoff)
                                ->orWhere(function ($never) use ($cutoff) {
                                    $never->whereNull('last_seen_at')
                                        ->where('created_at', '<', $cutoff);
                                });
                        });
                })
                // 2. Its order is already over.
                ->orWhereHas('order', function ($order) {
                    $order->whereIn('status', self::FINISHED_STATUSES);
                });
            })
            ->get();

        $released = 0;

        foreach ($stale as $session) {
            /*
             * Condition 2 rows may still have a sibling order cooking on the
             * same table — a second round placed after the first was completed.
             * releaseForOrder() already knows how to hand an occupancy on
             * instead of releasing it, so reuse that rather than writing a
             * second, subtly different version of the rule here.
             */
            if ($session->order_id && $session->order) {
                $released += self::releaseForOrder($session->order, 'order_' . (
                    $session->order->status === 'completed' ? 'completed' : 'cancelled'
                ));

                continue;
            }

            self::releaseRow($session, 'abandoned', null);
            $released++;
        }

        return $released;
    }

    /**
     * Attach the order the customer just placed to their live occupancy, so the
     * table is released automatically when that order finishes.
     */
    public static function attachOrder(Order $order): void
    {
        if (!self::isDineIn($order)) {
            return;
        }

        $lock = self::lockKey($order->branch_id, (string) $order->table_number);

        $session = TableSession::where('active_lock', $lock)->first();

        if (!$session) {
            return;
        }

        // Only the occupancy this visitor actually holds may be linked to their
        // order — otherwise a second customer's order could hijack the link.
        if (!self::belongsToCurrentVisitor($session)) {
            return;
        }

        $session->forceFill([
            'order_id'     => $order->id,
            'last_seen_at' => now(),
        ])->save();
    }

    /**
     * Mark a table occupied for a dine-in order STAFF keyed in at the counter.
     *
     * THE BUG THIS EXISTS FOR
     * -----------------------
     * attachOrder() above links an order to an occupancy the CURRENT VISITOR
     * already holds. That is exactly right for a customer who scanned the QR,
     * and it does nothing at all for a Manual Order: the request comes from a
     * staff member's admin session, which holds no table token and owns no
     * guest order, so belongsToCurrentVisitor() is false and — more to the
     * point — usually there is no occupancy row to link to in the first place.
     * Reported live: staff created a dine-in Manual Order for Table 1 and the
     * Occupied Tables panel still said "No tables are occupied right now",
     * leaving a table with a real party at it available to the next scan.
     *
     * A staff-entered dine-in order means a real party is genuinely sitting
     * there, so the table must be held. The three cases:
     *
     *  1. The table is already occupied by a customer who scanned in. Link to
     *     that EXISTING occupancy — never open a competing second one, and
     *     never error. Its order_id is only filled in when it is still empty,
     *     so a customer's own linked order keeps being the one that proves
     *     ownership if they lose their cookie (signal 2 above). Release is
     *     unaffected either way: releaseForOrder() hands the occupancy on to
     *     whatever unfinished order is still on the table (item 43).
     *
     *  2. The table is free. Open an occupancy attributed to the staff member
     *     via opened_by, holding the new order.
     *
     *  3. A race with a simultaneous customer scan. The unique index on
     *     active_lock catches it; the customer's session wins and this call
     *     falls back to case 1 rather than throwing at the counter.
     *
     * The generated session_token is deliberately stored in NO session. Staff
     * are not the party at the table, so nothing may later present the admin
     * browser as "the same customer continuing" — the item-40 signals stay
     * clean, and the occupancy is released by the order finishing or by a
     * staff Clear, exactly like any other.
     */
    public static function attachStaffOrder(Order $order, ?int $staffId = null): void
    {
        if (!self::isDineIn($order)) {
            return;
        }

        $tableNumber = strtoupper(trim((string) $order->table_number));
        $lock = self::lockKey($order->branch_id, $tableNumber);

        DB::transaction(function () use ($order, $tableNumber, $lock, $staffId) {
            $existing = TableSession::where('active_lock', $lock)->lockForUpdate()->first();

            if ($existing) {
                // Case 1. Keep whatever order already proves who is sitting
                // there; only adopt this one when the occupancy has none.
                $existing->forceFill(array_merge(
                    ['last_seen_at' => now()],
                    $existing->order_id ? [] : ['order_id' => $order->id]
                ))->save();

                return;
            }

            try {
                TableSession::create([
                    'branch_id'     => $order->branch_id,
                    'table_number'  => $tableNumber,
                    'session_token' => (string) Str::uuid() . Str::random(24),
                    'active_lock'   => $lock,
                    'order_id'      => $order->id,
                    'opened_by'     => $staffId,
                    'last_seen_at'  => now(),
                ]);
            } catch (QueryException $e) {
                if (!self::isDuplicateKey($e)) {
                    throw $e;
                }

                // Case 3: a customer scan landed first. Their occupancy is the
                // live one; leave it holding the table.
            }
        });
    }

    /**
     * Release whatever table the given order was occupying.
     * Called from the Order model when an order reaches a finished status.
     */
    public static function releaseForOrder(Order $order, string $reason): int
    {
        $released = 0;

        foreach (TableSession::where('order_id', $order->id)->whereNotNull('active_lock')->get() as $session) {
            /*
             * A visit can now contain more than one order — a dine-in customer
             * ordering a second round is the normal case. The occupancy points
             * at whichever order was placed most recently, so finishing THAT
             * one must not free a table whose earlier order is still cooking,
             * and finishing an earlier one must not free it either.
             *
             * Hand the occupancy over to whatever unfinished order is still on
             * the table; only release when there is genuinely nothing left.
             * The table therefore stays held for exactly as long as the party
             * has work outstanding, which is the guarantee the one-session-per-
             * table rule depends on.
             */
            $successor = self::unfinishedOrderOn($session, $order);

            if ($successor) {
                $session->forceFill([
                    'order_id'     => $successor->id,
                    'last_seen_at' => now(),
                ])->save();

                continue;
            }

            self::releaseRow($session, $reason, null);
            $released++;
        }

        return $released;
    }

    /**
     * Another dine-in order still open on this occupancy's table, if any.
     * $excluding is the order that just finished.
     */
    private static function unfinishedOrderOn(TableSession $session, Order $excluding): ?Order
    {
        return Order::query()
            ->where('branch_id', $session->branch_id)
            ->where('type', 'dine_in')
            ->whereRaw('UPPER(TRIM(table_number)) = ?', [
                strtoupper(trim((string) $session->table_number)),
            ])
            ->where('id', '!=', $excluding->id)
            ->whereNotIn('status', self::FINISHED_STATUSES)
            ->orderBy('id')
            ->first();
    }

    /**
     * Staff/admin manual clear. Returns the number of tables released (0 or 1).
     */
    public static function releaseTable(int $branchId, string $tableNumber, ?int $releasedBy): int
    {
        return self::describeRelease($branchId, $tableNumber, $releasedBy)['released'];
    }

    /**
     * Staff/admin manual clear, reporting what happened to the order that was
     * on the table.
     *
     * WHY THIS EXISTS SEPARATELY FROM releaseTable()
     * ----------------------------------------------
     * Clear used to answer "Table 5 is now available." and nothing else, even
     * when Table 5 had an order sitting in the kitchen. Staff had no way to
     * tell from the panel whether they had just cancelled someone's food or
     * merely tidied up a browsing session, so the safe-looking button was the
     * one nobody wanted to press.
     *
     * Clear does NOT touch the order. It never has: releaseRow() nulls
     * active_lock and leaves order_id in place, so the order keeps its status,
     * keeps its place in the kitchen queue, and the released row keeps pointing
     * at it for history — nothing is orphaned. That is exactly the fact staff
     * need told to them, so this returns it and AdminController puts it in the
     * message.
     *
     * @return array{released: int, order_id: ?int, order_number: ?string, order_status: ?string}
     */
    public static function describeRelease(int $branchId, string $tableNumber, ?int $releasedBy): array
    {
        $lock = self::lockKey($branchId, $tableNumber);

        $session = TableSession::with('order')->where('active_lock', $lock)->first();

        if (!$session) {
            return ['released' => 0, 'order_id' => null, 'order_number' => null, 'order_status' => null];
        }

        $order = $session->order;

        self::releaseRow($session, 'staff_cleared', $releasedBy);

        return [
            'released'     => 1,
            'order_id'     => $order?->id,
            'order_number' => $order?->order_number,
            'order_status' => $order?->status,
        ];
    }

    /** The live occupancy for a table, or null. */
    public static function activeFor(int $branchId, string $tableNumber): ?TableSession
    {
        return TableSession::where('active_lock', self::lockKey($branchId, $tableNumber))->first();
    }

    /** Every live occupancy, optionally narrowed to one branch. Staff view. */
    public static function activeSessions($branchId = null)
    {
        // The staff panel polls this every five seconds, which makes it the
        // most reliable clock this application has. Sweeping here is what
        // guarantees a walked-away customer's table disappears from the screen
        // on its own rather than waiting for the next customer to scan.
        self::sweepIdle();

        return TableSession::with(['branch', 'order'])
            ->whereNotNull('active_lock')
            ->when($branchId && $branchId !== 'all', fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('branch_id')
            ->orderByRaw('LENGTH(table_number), table_number')
            ->get();
    }

    /** Keep a live occupancy from ageing into "abandoned" while in use. */
    public static function touchCurrent(): void
    {
        $token = session(self::SESSION_KEY);

        if (!$token) {
            return;
        }

        TableSession::where('session_token', $token)
            ->whereNotNull('active_lock')
            ->update(['last_seen_at' => now()]);
    }

    // ══════════ internals ══════════

    private static function open(Branch $branch, string $tableNumber, string $lock, ?string $ip): TableSession
    {
        $token = (string) Str::uuid() . Str::random(24);

        try {
            $session = TableSession::create([
                'branch_id'     => $branch->id,
                'table_number'  => $tableNumber,
                'session_token' => $token,
                'active_lock'   => $lock,
                'last_seen_at'  => now(),
                'started_ip'    => $ip,
            ]);
        } catch (QueryException $e) {
            if (!self::isDuplicateKey($e)) {
                throw $e;
            }

            /*
             * The unique index caught a race the lockForUpdate did not: two
             * people at the same table scanned in the same instant and this one
             * lost. Under the old exclusive rule that meant "someone else got
             * the table" and became a refusal. It is not a refusal any more —
             * they are at the same table — so join the row that won.
             *
             * TableAlreadyOccupied is still thrown if the winning row cannot be
             * read back, which would mean it was released between the failed
             * insert and this read. Callers turn that into an ordinary message
             * rather than a 500.
             */
            $winner = TableSession::where('active_lock', $lock)->first();

            if (!$winner) {
                throw new TableAlreadyOccupied();
            }

            $winner->forceFill(['last_seen_at' => now()])->save();
            session()->put(self::SESSION_KEY, $winner->session_token);

            return $winner;
        }

        session()->put(self::SESSION_KEY, $token);

        return $session;
    }

    private static function releaseRow(TableSession $session, string $reason, ?int $releasedBy): void
    {
        $session->forceFill([
            // Dropping active_lock to NULL is what frees the table; the row
            // itself is kept as history.
            'active_lock'    => null,
            'released_at'    => now(),
            'released_by'    => $releasedBy,
            'release_reason' => $reason,
        ])->save();
    }

    /**
     * Signals 1 and 2 from the class docblock: does this occupancy belong to
     * whoever is making the current request?
     */
    private static function belongsToCurrentVisitor(TableSession $session): bool
    {
        // 1. The token this browser is holding.
        if (session(self::SESSION_KEY) === $session->session_token) {
            return true;
        }

        // 2. Cookie lost, but they demonstrably own the order on that table.
        if (!$session->order_id) {
            return false;
        }

        if (Auth::guard('customer')->check()) {
            $order = Order::find($session->order_id);

            return $order && (int) $order->user_id === (int) Auth::guard('customer')->id();
        }

        return GuestOrders::owns((int) $session->order_id);
    }

    /**
     * Public form of belongsToCurrentVisitor(), for callers that need to know
     * whether this visitor is the party currently holding a table — used to
     * decide whether they may order again during the same visit.
     */
    public static function heldByCurrentVisitor(TableSession $session): bool
    {
        return self::belongsToCurrentVisitor($session);
    }

    /*
     * isAbandoned() used to live here. It answered "may a DIFFERENT visitor
     * take this table over?", which was only a question while occupancy was
     * exclusive. Now that later scans simply join, nobody ever needs to take a
     * table over, and the staleness rule has moved to sweepIdle() where it does
     * a different job: releasing a silent occupancy so it stops showing on the
     * staff panel, rather than deciding who is allowed in.
     */

    private static function isDineIn(Order $order): bool
    {
        return $order->type === 'dine_in'
            && $order->branch_id
            && $order->table_number !== null
            && $order->table_number !== '';
    }

    private static function lockKey($branchId, string $tableNumber): string
    {
        return ((int) $branchId) . ':' . strtoupper(trim($tableNumber));
    }

    private static function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}

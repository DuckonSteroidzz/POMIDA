<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\TableSessionDevice;
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

    /**
     * How long a dine-in GUEST may sit idle before their own session expires
     * and they are sent back to the code-entry page to scan again.
     *
     * THIS IS NOT A SECOND sweepIdle(). READ THIS BEFORE CHANGING EITHER NUMBER.
     * ------------------------------------------------------------------------
     * INACTIVITY_MINUTES above and this constant look like the same idea at two
     * lengths. They are not the same idea at all:
     *
     *   INACTIVITY_MINUTES (90)   Housekeeping, for OTHER PEOPLE. Releases the
     *                             occupancy row so the staff Occupied Tables
     *                             panel stops showing a table nobody is at, and
     *                             so a session opened with a photographed table
     *                             code has a bounded life. Reads last_seen_at.
     *                             The guest never sees it happen.
     *
     *   GUEST_IDLE_MINUTES (15)   The guest's OWN session. Their phone has been
     *                             sitting on the menu untouched for a quarter of
     *                             an hour, so whatever is on that screen is no
     *                             longer trustworthy as "the party at this
     *                             table". Reads last_activity_at. Only that one
     *                             guest is affected, and all that happens is
     *                             they scan the standee again.
     *
     * Fifteen minutes is short because the two costs are asymmetric. Being late
     * means a phone left face-up on a table stays a live ordering session for
     * whoever picks it up. Being early means one person re-scans a QR that is
     * eighteen inches from their hand. The first is the one worth avoiding.
     *
     * The clock is extended ONLY by a real interaction — scroll, tap, key —
     * relayed by the throttled activity ping, and by opening or re-joining the
     * session in claim(). It is deliberately NOT extended by the passive check
     * that reads it, or the check would keep alive exactly the abandoned
     * session it exists to catch.
     */
    public const GUEST_IDLE_MINUTES = 15;

    public const SESSION_KEY = 'table_session_token';

    /**
     * Where THIS BROWSER's own device identifier lives, distinct from
     * SESSION_KEY. Every device sharing a table carries the identical
     * SESSION_KEY value (that is the whole point of sharing — see the class
     * docblock), so it cannot tell two devices at the same table apart. This
     * can: it is generated once per browser, independent of which table's
     * session it is currently attached to.
     *
     * Purely a visibility identifier for App\Models\TableSessionDevice. It
     * grants nothing and is never checked by claim() or validate() — losing it
     * (a cleared cookie, a different browser) only means the staff panel's
     * device count treats the visitor as a new device on their next scan, the
     * same way a lost SESSION_KEY only means rejoining the table normally.
     */
    public const DEVICE_KEY = 'table_session_device_token';

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
     * Shown on the code-entry page when a dine-in guest's session has gone
     * quiet for GUEST_IDLE_MINUTES.
     *
     * Delivered by exactly the plumbing App\Services\TableEntry::ERR_QR_STALE
     * already uses — a session('error') flash read by customer/dineinqr.blade
     * — so it renders in the same alert, with the same markup, in the same
     * place on the page. It is a distinct sentence because it describes a
     * distinct situation: nothing is wrong with the QR, the customer simply
     * stopped for a while.
     */
    public const ERR_SESSION_IDLE = 'Session expired due to inactivity. Please scan table QR code again.';

    /**
     * WHY A RELEASE CARRIES A NAMED REASON, AND WHY ONLY ONE OF THEM BOUNCES
     * THE CUSTOMER OFF THEIR PAGE.
     *
     * releaseRow() stamps release_reason on every occupancy it frees, and there
     * are exactly five values it can write:
     *
     *   staff_cleared     a staff member pressed Clear on the Occupied Tables
     *                     panel. Somebody deliberately ended this party's
     *                     visit, and the phones still sitting at that table
     *                     have to be told so.
     *   order_completed   the party's order finished normally.
     *   order_cancelled   the party's order was cancelled.
     *   abandoned         sweepIdle() reclaimed a table nobody had touched for
     *                     INACTIVITY_MINUTES.
     *   table_changed     the last device at the table moved to another one
     *                     (RELEASE_TABLE_CHANGED, leaveAfterTableChange()).
     *
     * Only the first is a reason to interrupt somebody mid-page.
     * order_completed in particular must NEVER bounce anyone: it fires on the
     * perfectly ordinary "the food came and the bill is settled" path, and
     * inspectGuestSession()'s docblock has always promised that a customer
     * whose order completed keeps their page. This constant exists so that
     * promise is kept by a value comparison rather than by remembering it.
     */
    public const RELEASE_STAFF_CLEARED = 'staff_cleared';

    /** See leaveAfterTableChange(). Fits release_reason's varchar(20). */
    public const RELEASE_TABLE_CHANGED = 'table_changed';

    /**
     * Shown on the code-entry page when staff ended this table's session from
     * the Occupied Tables panel while the customer still had the menu open.
     *
     * Delivered through the identical session('error') flash that
     * ERR_SESSION_IDLE and TableEntry::ERR_QR_STALE already use, so it renders
     * in the same alert, with the same markup, in the same place. It is a
     * separate sentence only because it describes a separate situation:
     * nothing expired and nothing is broken, a person made a decision, and
     * saying so is what stops the customer re-scanning in confusion.
     */
    public const ERR_SESSION_ENDED_BY_STAFF = 'This session has been ended by staff.';

    /**
     * Seat this visitor at a table: open its session, or JOIN the one already
     * there.
     *
     * There is no refusal path for a second person. Whoever scans, in whatever
     * order, ends up on the table's single shared session — see the class
     * docblock for why the second person at a table of four must never be
     * turned away.
     *
     * The ONE refusal is a table an admin took out of service in the instant
     * between TableEntry::validate() (a plain read, outside this transaction)
     * and here. It is decided under the registry row's lock — the same lock
     * setInService() takes before it looks for a live session — so a claim and
     * a deactivation are serialised: either the claim commits first and the
     * deactivation sees it and refuses, or the deactivation commits first and
     * the claim sees is_active = 0 and refuses. Without it a customer who had
     * already passed validation could seat themselves at a table the admin was
     * told was empty.
     *
     * The same lock answers a table DELETED in that instant (deleteUnusedTable()).
     * Every customer door validated a registry row before calling this and
     * passes its id as $registeredId; if that row is gone (or a different row
     * now holds the number) when the lock is taken, the claim is refused with
     * ERR_TABLE_NOT_FOUND and nothing is written. Either the claim commits first
     * and the delete sees a live session and refuses, or the delete commits
     * first and the claim finds no row. A caller that passes no id keeps the
     * old behaviour, in which an unregistered table is not a refusal.
     *
     * `continued` reports which of the two happened, for callers and tests that
     * care: true when this visitor joined (or resumed) an existing session,
     * false when this scan opened a new one.
     *
     * @return array{ok: bool, session?: TableSession, error?: string, continued?: bool}
     */
    public static function claim(Branch $branch, string $tableNumber, ?string $ip = null, ?int $registeredId = null): array
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
        return DB::transaction(function () use ($branch, $tableNumber, $lock, $ip, $registeredId) {
            // Registry row first, session second — the order moveSession(),
            // setInService() and deleteUnusedTable() use. Without $registeredId
            // an unregistered table has no row to lock and is not a refusal.
            $registered = RestaurantTable::where('branch_id', $branch->id)
                ->where('table_number', $tableNumber)
                ->lockForUpdate()
                ->first();

            if ($registeredId !== null && (!$registered || (int) $registered->id !== $registeredId)) {
                return ['ok' => false, 'error' => TableEntry::ERR_TABLE_NOT_FOUND];
            }

            if ($registered && !$registered->is_active) {
                return ['ok' => false, 'error' => TableEntry::ERR_TABLE_INACTIVE];
            }

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
                $existing->forceFill([
                    'last_seen_at'     => now(),
                    // Scanning the standee IS an interaction, so it starts the
                    // guest's fifteen-minute clock too. See GUEST_IDLE_MINUTES.
                    'last_activity_at' => now(),
                ])->save();
                session()->put(self::SESSION_KEY, $existing->session_token);

                // Visibility only — see DEVICE_KEY's docblock. Recorded AFTER
                // the decision above, never influencing it: this is the second,
                // third or fourth device at the table, and it is let in exactly
                // as it always was.
                self::recordDevice($existing);

                return ['ok' => true, 'session' => $existing, 'continued' => true];
            }

            $session = self::open($branch, $tableNumber, $lock, $ip);
            self::recordDevice($session);

            return ['ok' => true, 'session' => $session, 'continued' => false];
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

        /*
         * RELEASE_STAFF_CLEARED rather than the bare string: this exact value
         * is what staffClearedSession() matches on to decide whether the
         * customer's phone gets bounced off the menu, so the two must be the
         * same token in the same place. See that constant's docblock.
         */
        self::releaseRow($session, self::RELEASE_STAFF_CLEARED, $releasedBy);

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

    /**
     * How many devices are CURRENTLY attached to this occupancy, for the staff
     * panel. Visibility only — see App\Models\TableSessionDevice's migration
     * for why this is a read-time count rather than a maintained counter.
     *
     * A released session (active_lock already NULL) always answers 0 — a
     * device row's own last_activity_at can still look recent for a moment
     * right after release, and a caller asking about a freed table must never
     * be told it still looks occupied.
     *
     * "Currently" uses the exact same staleness rule as a single guest's own
     * clock (inspectGuestSession(), GUEST_IDLE_MINUTES) — one device that has
     * gone quiet drops out of the count on its own, with nothing anywhere
     * having to notice and decrement it.
     */
    public static function activeDeviceCount(TableSession $session): int
    {
        if ($session->active_lock === null) {
            return 0;
        }

        return $session->devices()
            ->where('last_activity_at', '>=', now()->subMinutes(self::GUEST_IDLE_MINUTES))
            ->count();
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

    // ══════════ the dine-in guest's own inactivity clock ══════════
    //
    // Everything below reads and writes last_activity_at and NOTHING ELSE. It
    // does not touch last_seen_at, active_lock, or any order, which is what
    // makes "sweepIdle() is unaffected by this feature" a fact about the code
    // rather than a claim about intent. See GUEST_IDLE_MINUTES.

    /**
     * The live occupancy this browser is holding, if any.
     *
     * Deliberately token-only. belongsToCurrentVisitor()'s second signal — "you
     * demonstrably own the order on that table" — is for deciding OWNERSHIP,
     * and re-using it here would let a customer whose cookie is gone keep a
     * table's idle clock alive from a different browser. The idle clock is
     * about one device with the menu open on it.
     */
    public static function currentGuestSession(): ?TableSession
    {
        $token = session(self::SESSION_KEY);

        if (!$token) {
            return null;
        }

        return TableSession::where('session_token', $token)
            ->whereNotNull('active_lock')
            ->first();
    }

    /**
     * A real interaction happened on the menu — restart the guest's fifteen
     * minutes.
     *
     * Written with a query update rather than a model save so updated_at is
     * left alone: this fires once a minute while someone scrolls a menu, and it
     * should not look like the row was edited. Returns whether there was a live
     * session to record against, which is what the endpoint answers with.
     *
     * The client throttles to at most one call per sixty seconds of activity;
     * the value written is the same either way, so a client that ignored the
     * throttle would cost extra writes and change no behaviour.
     */
    public static function recordGuestActivity(): bool
    {
        $session = self::currentGuestSession();

        if (!$session) {
            return false;
        }

        TableSession::where('id', $session->id)
            ->whereNotNull('active_lock')
            ->update(['last_activity_at' => now()]);

        // Same interaction, recorded against THIS device too — see
        // DEVICE_KEY's docblock. recordDevice() is an upsert, so a session that
        // was already live before this feature shipped (no device row yet)
        // backfills one here rather than being left uncounted.
        self::recordDevice($session);

        return true;
    }

    /**
     * Is this browser's dine-in session still good? STRICTLY READ-ONLY.
     *
     * Nothing in here writes anything, and that is the whole point: this is
     * called on every page load and every time the tab regains focus, so if it
     * refreshed the clock it would keep alive precisely the phone-left-on-the-
     * table session it exists to expire. A test pins that down by hammering it
     * and checking the session still expires exactly on schedule.
     *
     * Two ways to fail, in this order:
     *
     *   1. Silent for longer than GUEST_IDLE_MINUTES.
     *   2. The table itself is no longer usable — branch gone, branch closed,
     *      table taken out of service. That question is NOT re-implemented
     *      here; it is handed to App\Services\TableEntry::validate(), the one
     *      function every dine-in door validates through, using the branch and
     *      table number off the occupancy row (server-side, not anything the
     *      client sent) and the registry's current code. So this endpoint
     *      cannot drift from what the doors enforce, and it reports validate()'s
     *      own sentence rather than inventing a second wording.
     *
     * NO LIVE SESSION IS NOT A FAILURE — WITH ONE NAMED EXCEPTION. A visitor
     * with no token, or one whose occupancy was released because their order
     * completed, gets `valid`. This is an idleness check on a live occupancy,
     * not an admission gate — bouncing a customer to the code-entry page the
     * moment their meal finishes would be a rule nobody asked for, and
     * admission is already decided by validate() at the three doors.
     *
     * The exception is a table a staff member CLEARED by hand. That case used
     * to fall through this very first line and answer `valid`: the Clear button
     * nulls active_lock, currentGuestSession() only ever looks at rows where
     * active_lock is still set, so the moment staff ended the visit the
     * customer's own poll started reporting that everything was fine and their
     * phone carried on ordering against a table the panel had already handed to
     * somebody else. staffClearedSession() is the lookup that first line was
     * missing; RELEASE_STAFF_CLEARED explains why it is the only release reason
     * that answers this way.
     *
     * `reason` rides along on every failure so the caller can tell a
     * deliberate, staff-initiated end (which has a session to tear down and a
     * cart to empty) from an ordinary expiry, without re-deciding it from the
     * message text.
     *
     * @return array{valid: bool, error?: string, reason?: string}
     */
    public static function inspectGuestSession(): array
    {
        $session = self::currentGuestSession();

        if (!$session) {
            if (self::staffClearedSession()) {
                return [
                    'valid'  => false,
                    'error'  => self::ERR_SESSION_ENDED_BY_STAFF,
                    'reason' => self::RELEASE_STAFF_CLEARED,
                ];
            }

            return ['valid' => true];
        }

        /*
         * last_seen_at and created_at are FALLBACKS, read only when
         * last_activity_at is null — a row that was already live when this
         * column shipped, or one a staff member opened from the counter. They
         * are never written by this feature, so they cannot extend anything;
         * they only keep a mid-meal party from being thrown out by a deploy.
         */
        $since = $session->last_activity_at ?? $session->last_seen_at ?? $session->created_at;

        if ($since === null || $since->lt(now()->subMinutes(self::GUEST_IDLE_MINUTES))) {
            return ['valid' => false, 'error' => self::ERR_SESSION_IDLE, 'reason' => 'idle'];
        }

        $table = TableEntry::find((int) $session->branch_id, (string) $session->table_number);

        $check = TableEntry::validate(
            $session->branch_id,
            $session->table_number,
            $table?->code
        );

        if (!$check['ok']) {
            return ['valid' => false, 'error' => $check['error'], 'reason' => 'table_unusable'];
        }

        return ['valid' => true];
    }

    /**
     * The occupancy this browser's token points at, IF a staff member cleared
     * it by hand. Null in every other case. STRICTLY READ-ONLY, like
     * inspectGuestSession() around it.
     *
     * Deliberately NOT written as "any released row": the whole point is to
     * separate the one release reason that should interrupt a customer from the
     * three that should not, so the reason is matched exactly rather than
     * inferred from released_at being set. A row freed by a completed order, a
     * cancelled order or the ninety-minute sweep is invisible to this lookup,
     * which is what keeps requirement "normal status changes must not bounce
     * anyone" true by construction. See RELEASE_STAFF_CLEARED.
     *
     * Token-only, matching currentGuestSession(): the question is "was the
     * table THIS DEVICE is sitting at ended", and session_token is exactly the
     * thing every device sharing that table holds. Every phone at a cleared
     * table therefore finds the same row and is told the same thing, each on
     * its own next poll.
     *
     * whereNull('active_lock') is not a second filter so much as a statement:
     * if the table has since been re-opened (a new party scanned in, which
     * writes a NEW session_token), this browser's stale token no longer matches
     * any live row and the cleared row it does match stays cleared. A token can
     * never resolve to somebody else's live occupancy here.
     */
    public static function staffClearedSession(): ?TableSession
    {
        $token = session(self::SESSION_KEY);

        if (!$token) {
            return null;
        }

        return TableSession::where('session_token', $token)
            ->whereNull('active_lock')
            ->where('release_reason', self::RELEASE_STAFF_CLEARED)
            ->first();
    }

    // ══════════ leaving a table for another one ══════════

    /**
     * THIS device has just moved from $from to another table
     * (App\Services\TableChange::move(), after claim() seated it there).
     *
     * Two steps, and only the first always happens:
     *
     *   1. This device stops counting at the old table — its device row goes,
     *      so the staff panel's Devices figure drops by one straight away
     *      instead of fifteen minutes later.
     *   2. The old table is released ONLY if nobody else is still on it, by the
     *      same rule the Devices figure uses (activeDeviceCount()), and no order
     *      placed during this occupancy is still open. People still seated there
     *      keep their session; an order still cooking keeps its table.
     *
     * Nothing else on the old row is written. In particular last_seen_at and
     * last_activity_at are left alone, so a table someone else is still at gets
     * no extra time on either clock from this device leaving it.
     *
     * Locked so a second device leaving the same table at the same moment
     * cannot have both see "one other device left" and both skip the release.
     *
     * @return bool whether the old table was released
     */
    public static function leaveAfterTableChange(TableSession $from): bool
    {
        return DB::transaction(function () use ($from) {
            $row = TableSession::whereKey($from->id)->lockForUpdate()->first();

            if (!$row || $row->active_lock === null) {
                return false;
            }

            $device = session(self::DEVICE_KEY);

            if (is_string($device) && $device !== '') {
                TableSessionDevice::where('table_session_id', $row->id)
                    ->where('device_token', $device)
                    ->delete();
            }

            if (self::activeDeviceCount($row) > 0 || self::hasOpenOrderSince($row)) {
                return false;
            }

            self::releaseRow($row, self::RELEASE_TABLE_CHANGED, null);

            return true;
        });
    }

    /**
     * An order still open on this table that belongs to this occupancy — its
     * linked order, or any dine-in order placed there since it opened. An older
     * party's order left in the queue after a staff Clear predates it.
     */
    private static function hasOpenOrderSince(TableSession $session): bool
    {
        return self::visitOpenOrders($session)->exists();
    }

    /**
     * The open dine-in orders of this occupancy's visit — the rule
     * hasOpenOrderSince() has always used, as a query, so moveSession() moves
     * exactly the orders that hold a table and no others.
     */
    private static function visitOpenOrders(TableSession $session)
    {
        return Order::query()
            ->where('branch_id', $session->branch_id)
            ->where('type', 'dine_in')
            ->whereRaw('UPPER(TRIM(table_number)) = ?', [
                strtoupper(trim((string) $session->table_number)),
            ])
            ->whereNotIn('status', self::FINISHED_STATUSES)
            ->where(function ($q) use ($session) {
                $q->where('created_at', '>=', $session->created_at);

                if ($session->order_id) {
                    $q->orWhere('id', $session->order_id);
                }
            });
    }

    // ══════════ staff moving a party to another table ══════════
    //
    // "Move table" on the Occupied Tables panel. The occupancy row itself
    // moves — same row, same session_token — so every phone sharing it follows
    // without being told anything: followSessionTable() below re-points each
    // device's own session on its next request. Nothing is released, so no
    // release_reason is written; the old table is free because active_lock no
    // longer names it.

    public const ERR_MOVE_NO_TABLE = 'That table is not available. Please pick a table from the list.';

    public const ERR_MOVE_FAILED = 'Could not move the table just now. Nothing was changed. Please try again.';

    /** Is this table free (no live session)? The one definition of "free" the dialog and the move share. */
    public static function isFree(int $branchId, string $tableNumber): bool
    {
        return !TableSession::where('active_lock', self::lockKey($branchId, $tableNumber))->exists();
    }

    /**
     * Where a staff member may move this party: registered tables at the
     * occupancy's OWN branch, in service, with nobody at them. The branch is
     * read off the occupancy row; nothing a client sends can widen it.
     *
     * @return \Illuminate\Support\Collection<int, RestaurantTable>
     */
    public static function moveTargets(TableSession $source)
    {
        // Same housekeeping the panel's poll runs, so a table someone walked
        // away from is offered rather than hidden behind a ghost session.
        self::sweepIdle();

        return RestaurantTable::where('branch_id', $source->branch_id)
            ->where('is_active', true)
            ->orderByRaw('LENGTH(table_number), table_number')
            ->get()
            ->filter(fn (RestaurantTable $t) => self::isFree((int) $t->branch_id, (string) $t->table_number))
            ->values();
    }

    /**
     * Move a live occupancy, and every open order of its visit, to another
     * table at the same branch.
     *
     * ONE transaction, READ COMMITTED like every order path (OrderTransaction),
     * and the locks are always taken in the same order:
     *
     *   1. the destination restaurant_tables row — two moves into one table
     *      queue here;
     *   2. the visit's open orders, by id — the order completion path locks
     *      the order before it writes the occupancy (releaseForOrder() from the
     *      Order saved() hook), so orders-before-occupancy is the order both
     *      paths agree on;
     *   3. the source occupancy row.
     *
     * Everything is re-checked under those locks: the source is still live and
     * is still where it was when the orders were chosen, the destination is at
     * the SAME branch, in service and free. Any change is a refusal and nothing
     * is written. The unique index on active_lock stays the last word: a
     * customer who scans the destination in the instant between the check and
     * the write makes this update fail, which is reported as the table having
     * just been taken.
     *
     * Completed and cancelled orders keep the table they were served at. Who
     * moved what is written to the table_moves log after the commit.
     *
     * @return array{ok: bool, status?: int, error?: string, session_id?: int, branch_id?: int, from?: string, to?: string, order_ids?: array<int, int>}
     */
    public static function moveSession(TableSession $source, RestaurantTable $to, ?\App\Models\User $by = null): array
    {
        self::sweepIdle();

        $result = \App\Support\OrderTransaction::run(function () use ($source, $to) {
            $dest = RestaurantTable::whereKey($to->id)->lockForUpdate()->first();
            $seen = TableSession::find($source->id);

            if (!$seen || $seen->active_lock === null) {
                return self::refuse(409, 'Table ' . ($seen ?? $source)->table_number
                    . ' no longer has an active session. Please check the list.');
            }

            if (!$dest || (int) $dest->branch_id !== (int) $seen->branch_id) {
                return self::refuse(404, self::ERR_MOVE_NO_TABLE);
            }

            $orderIds = self::visitOpenOrders($seen)->orderBy('id')->lockForUpdate()->pluck('id')->all();

            $row = TableSession::whereKey($seen->id)->lockForUpdate()->first();

            // Its order finished (or staff cleared it) while this move waited.
            if (!$row || $row->active_lock === null) {
                return self::refuse(409, 'Table ' . $seen->table_number
                    . ' no longer has an active session. Please check the list.');
            }

            if ($row->active_lock !== $seen->active_lock) {
                return self::refuse(409, 'Table ' . $seen->table_number
                    . ' was just changed by someone else. Please check the list and try again.');
            }

            $from = (string) $row->table_number;
            $toNumber = strtoupper(trim((string) $dest->table_number));

            if ($toNumber === strtoupper(trim($from))) {
                return self::refuse(409, 'This customer is already at Table ' . $toNumber . '.');
            }

            if (!$dest->is_active) {
                return self::refuse(422, 'Table ' . $toNumber . ' is not in service. Please pick another table.');
            }

            if (!self::isFree((int) $row->branch_id, $toNumber)) {
                return self::refuse(409, self::takenMessage($toNumber));
            }

            try {
                $row->forceFill([
                    'table_number' => $toNumber,
                    'active_lock'  => self::lockKey($row->branch_id, $toNumber),
                    // A staff member just saw this party, so the 90-minute
                    // housekeeping clock restarts. The guest's own fifteen-minute
                    // clock (last_activity_at) is theirs and is left alone.
                    'last_seen_at' => now(),
                ])->save();
            } catch (QueryException $e) {
                if (!self::isDuplicateKey($e)) {
                    throw $e;
                }

                return self::refuse(409, self::takenMessage($toNumber));
            }

            if ($orderIds) {
                Order::whereKey($orderIds)->update(['table_number' => $toNumber]);
            }

            return [
                'ok'         => true,
                'session_id' => (int) $row->id,
                'branch_id'  => (int) $row->branch_id,
                'from'       => $from,
                'to'         => $toNumber,
                'order_ids'  => array_map('intval', $orderIds),
            ];
        });

        if ($result['ok']) {
            // After the commit, so it never records a move that rolled back.
            self::auditTableAction('Table moved', [
                'action'        => 'move',
                'moved_by_id'   => $by?->id,
                'moved_by_name' => $by?->name,
                'moved_by_role' => $by?->role,
                'branch_id'     => $result['branch_id'],
                'session_id'    => $result['session_id'],
                'from_table'    => $result['from'],
                'to_table'      => $result['to'],
                'order_ids'     => $result['order_ids'],
                'moved_at'      => now()->toIso8601String(),
            ]);
        }

        return $result;
    }

    /**
     * One line in the table_moves log. Every table action by staff — a move, a
     * deactivation, a reactivation, a deletion — goes through here, so the channel stays a
     * single trail and the `action` field says which it was.
     *
     * A log that cannot be written (a full disk, a read-only storage/logs) is
     * reported, but must not turn an action that DID happen into "Nothing was
     * changed" on the staff member's screen.
     */
    private static function auditTableAction(string $message, array $context): void
    {
        try {
            \Illuminate\Support\Facades\Log::channel('table_moves')->info($message, $context);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ══════════ taking a table out of service ══════════
    //
    // The QR & Table Codes card's "Deactivate table" / "Reactivate table".
    // Tables are never deleted: this flips restaurant_tables.is_active and
    // nothing else. Its code, its orders, its help requests and its session
    // history are not read for writing, let alone touched. Every door already
    // honours the flag — TableEntry::validate() refuses a scan or a typed code
    // ("not in service"), moveTargets() hides the table, moveSession() refuses
    // it — so this is the missing control, not new behaviour.

    /**
     * Take a table out of service, or put it back.
     *
     * Deactivating is refused while the table has a live session. The check and
     * the write are ONE transaction (READ COMMITTED like every order path,
     * OrderTransaction), locked in the order every other path uses:
     *
     *   1. the registry row — the first lock moveSession() takes on a
     *      destination, and now the first one claim() takes;
     *   2. the table's live occupancy row, by active_lock — the row claim()
     *      joins and moveSession() moves.
     *
     * A customer claiming the table at the same instant therefore waits for
     * this transaction or this one waits for theirs: if the claim lands first
     * the table is occupied and this refuses; if this lands first claim() reads
     * is_active = 0 under the same lock and refuses. Orders are not locked
     * because nothing here writes one, so no lock cycle with the order
     * completion path (order, then occupancy) is possible.
     *
     * The table is identified by its registry id alone and its branch is read
     * off the locked row, never taken from the caller.
     *
     * An action that changes nothing (already out of service / already in
     * service) answers ok with changed = false and writes no audit line.
     *
     * @return array{ok: bool, status?: int, error?: string, changed?: bool, table_id?: int, branch_id?: int, table_number?: string, is_active?: bool}
     */
    public static function setInService(RestaurantTable $table, bool $inService, ?\App\Models\User $by = null): array
    {
        if (!$inService) {
            // The same housekeeping the Move list runs, so a table somebody
            // walked away from is not kept "occupied" by a ghost session.
            self::sweepIdle();
        }

        $result = \App\Support\OrderTransaction::run(function () use ($table, $inService) {
            $row = RestaurantTable::whereKey($table->id)->lockForUpdate()->first();

            if (!$row) {
                return self::refuse(404, 'That table no longer exists.');
            }

            $number = (string) $row->table_number;
            $state = [
                'ok'           => true,
                'changed'      => false,
                'table_id'     => (int) $row->id,
                'branch_id'    => (int) $row->branch_id,
                'table_number' => $number,
                'is_active'    => (bool) $row->is_active,
            ];

            if ((bool) $row->is_active === $inService) {
                return $state;
            }

            if (!$inService) {
                $live = TableSession::where('active_lock', self::lockKey($row->branch_id, $number))
                    ->lockForUpdate()
                    ->first();

                if ($live) {
                    return self::refuse(409, 'Table ' . $number . ' is occupied. Move or clear it first.');
                }
            }

            $row->forceFill(['is_active' => $inService])->save();

            return ['changed' => true, 'is_active' => $inService] + $state;
        });

        if ($result['ok'] && $result['changed']) {
            self::auditTableAction($inService ? 'Table reactivated' : 'Table deactivated', [
                'action'        => $inService ? 'reactivate' : 'deactivate',
                'acted_by_id'   => $by?->id,
                'acted_by_name' => $by?->name,
                'acted_by_role' => $by?->role,
                'branch_id'     => $result['branch_id'],
                'table_id'      => $result['table_id'],
                'table_number'  => $result['table_number'],
                'acted_at'      => now()->toIso8601String(),
            ]);
        }

        return $result;
    }

    // ══════════ deleting a table that was never used ══════════
    //
    // The trash icon beside a free table in the Move table dialog. This is the
    // one place a restaurant_tables row is ever deleted, and only for a table
    // with no trace anywhere — the typo (100 for 10) a manager registered by
    // pressing Generate. Anything a customer, an order, a help request or a
    // code rotation ever touched is history, and history is never deleted.

    public const ERR_TABLE_HAS_HISTORY = 'This table has history and cannot be deleted.';

    /**
     * Hard-delete ONE registry row, if and only if nothing has ever used it.
     *
     * $branchId is the branch the caller resolved the table under (the Move
     * dialog's own occupancy). It is re-checked against the locked row, so a
     * table at any other branch is a 404 here whatever the caller believed.
     *
     * ONE transaction (READ COMMITTED, OrderTransaction), with the lock order
     * every table path shares:
     *
     *   1. the registry row — the lock claim() takes first, so a customer
     *      seating themselves and this delete are serialised: if the claim
     *      commits first its session is seen below and this refuses; if this
     *      commits first claim() finds no row and refuses (ERR_TABLE_NOT_FOUND);
     *   2. the table's live occupancy row, by active_lock.
     *
     * Every refusal is decided under those locks and writes nothing:
     *
     *   - a live session (occupied);
     *   - an open order at this branch + table number;
     *   - ANY history at this branch + table number: an order of any status, a
     *     session (released ones too), a help request, a staff-issued access
     *     code, or a code that was ever rotated.
     *
     * The deleted code is not handed to anything; allocateCode() only ever
     * draws fresh random codes. One line goes to the table_moves log, after the
     * commit, with action = table_deleted.
     *
     * @return array{ok: bool, status?: int, error?: string, table_id?: int, branch_id?: int, table_number?: string}
     */
    public static function deleteUnusedTable(RestaurantTable $table, int $branchId, ?\App\Models\User $by = null): array
    {
        $result = \App\Support\OrderTransaction::run(function () use ($table, $branchId) {
            $row = RestaurantTable::whereKey($table->id)->lockForUpdate()->first();

            if (!$row || (int) $row->branch_id !== $branchId) {
                return self::refuse(404, 'That table no longer exists.');
            }

            $number = (string) $row->table_number;

            $live = TableSession::where('active_lock', self::lockKey($row->branch_id, $number))
                ->lockForUpdate()
                ->first();

            if ($live) {
                return self::refuse(409, 'Table ' . $number . ' is occupied. Move or clear it first.');
            }

            $orders = Order::where('branch_id', $row->branch_id)->where('table_number', $number);

            if ((clone $orders)->whereNotIn('status', self::FINISHED_STATUSES)->exists()) {
                return self::refuse(409, 'Table ' . $number . ' has an open order and cannot be deleted.');
            }

            $hasHistory = $row->previous_code !== null
                || $row->code_rotated_at !== null
                || $orders->exists()
                || TableSession::where('branch_id', $row->branch_id)->where('table_number', $number)->exists()
                || DB::table('help_requests')->where('branch_id', $row->branch_id)->where('table_number', $number)->exists()
                || DB::table('table_access_codes')->where('branch_id', $row->branch_id)->where('table_number', $number)->exists();

            if ($hasHistory) {
                return self::refuse(409, self::ERR_TABLE_HAS_HISTORY);
            }

            $row->delete();

            return [
                'ok'           => true,
                'table_id'     => (int) $row->id,
                'branch_id'    => (int) $row->branch_id,
                'table_number' => $number,
            ];
        });

        if ($result['ok']) {
            self::auditTableAction('Table deleted', [
                'action'        => 'table_deleted',
                'acted_by_id'   => $by?->id,
                'acted_by_name' => $by?->name,
                'acted_by_role' => $by?->role,
                'branch_id'     => $result['branch_id'],
                'table_id'      => $result['table_id'],
                'table_number'  => $result['table_number'],
                'acted_at'      => now()->toIso8601String(),
            ]);
        }

        return $result;
    }

    // ══════════ the "Manage tables" list ══════════

    public const STATE_IN_SERVICE = 'in_service';

    public const STATE_OCCUPIED = 'occupied';

    public const STATE_OUT_OF_SERVICE = 'not_in_service';

    /**
     * Every table registered at ONE branch, with where it stands — the
     * QR & Table Codes "Manage tables" list, where a table added by mistake
     * (100 instead of 10) is taken out of service without printing a card.
     *
     * Reads only. "Remove" and "Restore" are the existing setInService()
     * actions, so the occupied refusal and every lock stay in one place; this
     * only says in advance which tables that refusal would stop.
     *
     * Occupied means exactly what setInService() refuses on: a live occupancy
     * (active_lock) at the table, after the same idle sweep. A table that is
     * already out of service is reported as such even if a staff-opened counter
     * order left a session at it, because the only thing left to do with it is
     * restore it.
     *
     * The table's code is never selected, so it cannot reach the response.
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, table_number: string, status: string, blocked_reason: ?string}>
     */
    public static function manageList(int $branchId)
    {
        self::sweepIdle();

        $live = TableSession::where('branch_id', $branchId)
            ->whereNotNull('active_lock')
            ->pluck('active_lock')
            ->flip();

        return RestaurantTable::where('branch_id', $branchId)
            ->orderByRaw('LENGTH(table_number), table_number')
            ->orderBy('id')
            ->get(['id', 'branch_id', 'table_number', 'is_active'])
            ->map(function (RestaurantTable $t) use ($live) {
                $number = (string) $t->table_number;

                if (!$t->is_active) {
                    $status = self::STATE_OUT_OF_SERVICE;
                } elseif ($live->has(self::lockKey($t->branch_id, $number))) {
                    $status = self::STATE_OCCUPIED;
                } else {
                    $status = self::STATE_IN_SERVICE;
                }

                return [
                    'id'             => (int) $t->id,
                    'table_number'   => $number,
                    'status'         => $status,
                    'blocked_reason' => $status === self::STATE_OCCUPIED
                        ? 'Table ' . $number . ' is occupied. Move or clear the customer first.'
                        : null,
                ];
            })
            ->values();
    }

    /**
     * Point THIS device at the table its shared occupancy is at now.
     *
     * A device's table lives in its own Laravel session (session('table_number'))
     * while the occupancy row is shared through the token every device at the
     * table holds. After a staff move the row names the new table and each
     * device's session still names the old one; this closes the gap on the
     * device's next request, before any page or order reads it — so an order
     * from a tab rendered before the move is still placed at the new table
     * (placeOrder() prefers the session's table over the posted field).
     *
     * Only the table number is touched, only for Dine-In, and only when the
     * row is at the branch this device is already at — a token never carries a
     * device across branches. Every other way a device changes table also
     * rewrites its token (claim()), so outside a staff move the two always
     * agree and this changes nothing.
     *
     * @return string|null the table this device was moved to, or null when nothing changed
     */
    public static function followSessionTable(): ?string
    {
        if (session('order_type') !== 'dine_in') {
            return null;
        }

        $token = session(self::SESSION_KEY);
        $branchId = session('branch_id');
        $current = session('table_number');

        if (!is_string($token) || $token === '' || !is_numeric($branchId) || !is_scalar($current)) {
            return null;
        }

        $row = TableSession::where('session_token', $token)->first(['branch_id', 'table_number']);

        if (!$row || (int) $row->branch_id !== (int) $branchId) {
            return null;
        }

        $table = strtoupper(trim((string) $row->table_number));

        if ($table === '' || $table === strtoupper(trim((string) $current))) {
            return null;
        }

        session()->put('table_number', $table);

        return $table;
    }

    private static function takenMessage(string $tableNumber): string
    {
        return 'Table ' . $tableNumber . ' was just taken. Please pick another table.';
    }

    /** @return array{ok: false, status: int, error: string} */
    private static function refuse(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error];
    }

    // ══════════ internals ══════════

    private static function open(Branch $branch, string $tableNumber, string $lock, ?string $ip): TableSession
    {
        $token = (string) Str::uuid() . Str::random(24);

        try {
            $session = TableSession::create([
                'branch_id'     => $branch->id,
                'table_number'  => $tableNumber,
                'session_token'    => $token,
                'active_lock'      => $lock,
                'last_seen_at'     => now(),
                'last_activity_at' => now(),
                'started_ip'       => $ip,
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

            $winner->forceFill([
                'last_seen_at'     => now(),
                'last_activity_at' => now(),
            ])->save();
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
     * THIS browser's own device identifier, creating one on first use.
     *
     * Independent of SESSION_KEY on purpose — see DEVICE_KEY's docblock. A
     * fresh value here means only that the staff panel has never seen this
     * browser before; it grants no access and is checked by nothing.
     */
    private static function deviceToken(): string
    {
        $token = session(self::DEVICE_KEY);

        if (!is_string($token) || $token === '') {
            $token = (string) Str::uuid() . Str::random(24);
            session()->put(self::DEVICE_KEY, $token);
        }

        return $token;
    }

    /**
     * Record that THIS device is present on this occupancy right now.
     *
     * An upsert keyed on (table_session_id, device_token): the same device
     * re-scanning, re-joining, or simply pinging activity updates its one row
     * rather than accumulating duplicates. Visibility only — never called from
     * anywhere that decides whether a scan is allowed.
     */
    private static function recordDevice(TableSession $session): void
    {
        TableSessionDevice::updateOrCreate(
            ['table_session_id' => $session->id, 'device_token' => self::deviceToken()],
            ['last_activity_at' => now()]
        );
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

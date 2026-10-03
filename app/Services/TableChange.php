<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Support\GuestOrders;
use Illuminate\Support\Facades\Auth;

/**
 * TableChange — a Dine-In device that is already seated moving to another
 * table at the SAME branch.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS DECIDES, AND WHAT IT DOES NOT
 * ─────────────────────────────────────────────────────────────────────────────
 * It never validates a credential. The new table's QR or typed code goes
 * through App\Services\TableEntry exactly as a first entry does, and only a
 * table that came back `ok` from there reaches assess(). The branch and table
 * therefore come from the credential and nothing else: no submitted branch_id,
 * table_id or table number is ever read on this path.
 *
 * It never opens or joins a session itself. Moving is TableOccupancy::claim()
 * on the new table (the same call every scan makes) followed by
 * TableOccupancy::leaveAfterTableChange() on the old one, so the shared-session
 * rules in TableOccupancy's docblock are untouched.
 *
 * What it adds is the answer to "what does this credential MEAN for a device
 * that already has a table":
 *
 *   not_seated     no Dine-In seat in this session — an ordinary first entry
 *   same           the table this device is already at — an ordinary re-scan
 *   other_branch   a table at a different branch — refused by Change table
 *   active_order   an order placed at the current table is still open — refused,
 *                  staff move orders, the customer does not
 *   free           nobody is at the new table — a typed code moves straight
 *                  away; the phone-camera QR door asks first (see stage())
 *   occupied       the new table has a live session — join it, but only after
 *                  the customer confirms (see stage() / pending())
 *
 * Every branch comparison here is between two ids read at run time (the
 * session's seat and the credential's table). Nothing names a branch.
 */
class TableChange
{
    /** Session key for a join the customer still has to confirm. */
    public const PENDING_KEY = 'pending_table_change';

    /**
     * How long a "Table X is already in use. Join it?" prompt stays answerable.
     * Long enough to read it and look round the room; short enough that a
     * prompt left on a phone does not quietly seat someone an hour later.
     */
    public const PENDING_TTL_MINUTES = 5;

    public const ERR_NOT_DINE_IN = 'Change table is only for Dine-In. Please scan the QR code on your table to start.';

    public const ERR_OTHER_BRANCH = "That table belongs to another branch, so we can't move you there. "
        . "To dine at that branch, please scan that branch's QR instead.";

    public const ERR_PENDING_EXPIRED = "That table change has expired. Please scan or enter the new table's code again.";

    public static function activeOrderMessage(string $tableNumber): string
    {
        return 'You already have an order at Table ' . $tableNumber . '. Please ask our staff to move your order.';
    }

    /**
     * Where this device is seated, read from its own session.
     *
     * `session` is the live occupancy the device's token points at, and only
     * when that occupancy is the seat's table — a token for some other row is
     * not treated as this seat's session.
     *
     * Null when the session is not Dine-In or carries no branch or table. There
     * is no fallback branch here: no seat means no seat.
     *
     * @return array{branch_id: int, table_number: string, session: ?\App\Models\TableSession}|null
     */
    public static function currentSeat(): ?array
    {
        if (session('order_type') !== 'dine_in') {
            return null;
        }

        $branchId = session('branch_id');
        $tableNumber = session('table_number');

        if (!is_numeric($branchId) || (int) $branchId <= 0 || !is_scalar($tableNumber)) {
            return null;
        }

        $branchId = (int) $branchId;
        $tableNumber = strtoupper(trim((string) $tableNumber));

        if ($tableNumber === '') {
            return null;
        }

        $held = TableOccupancy::currentGuestSession();

        if ($held && ((int) $held->branch_id !== $branchId
            || strtoupper(trim((string) $held->table_number)) !== $tableNumber)) {
            $held = null;
        }

        return ['branch_id' => $branchId, 'table_number' => $tableNumber, 'session' => $held];
    }

    /**
     * What presenting this (already validated) table means for this device.
     *
     * @return array{kind: string, seat?: array, order?: Order}
     */
    public static function assess(Branch $branch, string $tableNumber): array
    {
        $tableNumber = strtoupper(trim($tableNumber));
        $seat = self::currentSeat();

        if ($seat === null) {
            return ['kind' => 'not_seated'];
        }

        if ($seat['branch_id'] !== (int) $branch->id) {
            return ['kind' => 'other_branch', 'seat' => $seat];
        }

        if ($seat['table_number'] === $tableNumber) {
            return ['kind' => 'same', 'seat' => $seat];
        }

        $order = self::openOrderAtSeat($seat);

        if ($order) {
            return ['kind' => 'active_order', 'seat' => $seat, 'order' => $order];
        }

        // Same housekeeping claim() runs before it looks, so a table the last
        // party walked away from is reported free rather than "in use".
        TableOccupancy::sweepIdle();

        return [
            'kind' => TableOccupancy::activeFor((int) $branch->id, $tableNumber) ? 'occupied' : 'free',
            'seat' => $seat,
        ];
    }

    /**
     * An order placed at the seat's table during THIS visit that is not yet
     * completed or cancelled, if any.
     *
     * "This visit" is either signal, not both:
     *
     *   - placed while the occupancy this device holds was live. The session is
     *     shared, so a friend's order on their own phone counts — staff will
     *     carry that food to the table this device is sitting at.
     *   - placed by this visitor: a guest's own order set, or a signed-in
     *     customer's account. Covers a device whose occupancy has already been
     *     released while its order is still cooking.
     *
     * An older party's unfinished order at the same table (left in the queue
     * after a staff Clear) matches neither, so it cannot hold a new party in
     * place.
     */
    public static function openOrderAtSeat(array $seat): ?Order
    {
        $held = $seat['session'];
        $ownIds = GuestOrders::ids();
        $userId = Auth::guard('customer')->id();

        if (!$held && !$ownIds && !$userId) {
            return null;
        }

        return Order::query()
            ->where('branch_id', $seat['branch_id'])
            ->where('type', 'dine_in')
            ->whereRaw('UPPER(TRIM(table_number)) = ?', [$seat['table_number']])
            ->whereNotIn('status', TableOccupancy::FINISHED_STATUSES)
            ->where(function ($q) use ($held, $ownIds, $userId) {
                if ($held) {
                    $q->orWhere('created_at', '>=', $held->created_at);

                    if ($held->order_id) {
                        $q->orWhere('id', $held->order_id);
                    }
                }

                if ($ownIds) {
                    $q->orWhereIn('id', $ownIds);
                }

                if ($userId) {
                    $q->orWhere('user_id', $userId);
                }
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * Move this device to the new table: claim it (open, or join), then leave
     * the old one. The branch never changes — assess() has already refused a
     * different branch — so the cart, which lives in this device's own session
     * and was priced for this branch, stays exactly as it is.
     *
     * Claim first, leave second: if the claim throws — or refuses, because an
     * admin took the new table out of service after it was validated — the
     * customer still has the table they started with.
     *
     * @return array{ok: bool, error?: string, session?: \App\Models\TableSession, continued?: bool, moved?: bool, released_previous?: bool}
     */
    public static function move(Branch $branch, string $tableNumber, ?string $ip, ?int $registeredId = null): array
    {
        $tableNumber = strtoupper(trim($tableNumber));
        $from = self::currentSeat()['session'] ?? null;

        // $registeredId: the registry row the caller validated, so a table
        // deleted since then is refused under claim()'s lock.
        $claim = TableOccupancy::claim($branch, $tableNumber, $ip, $registeredId);

        if (!$claim['ok']) {
            return $claim;
        }

        $released = false;

        if ($from && (int) $claim['session']->id !== (int) $from->id) {
            $released = TableOccupancy::leaveAfterTableChange($from);
        }

        session()->put('table_number', $tableNumber);
        session()->forget(self::PENDING_KEY);

        return $claim + ['moved' => true, 'released_previous' => $released];
    }

    /**
     * Remember a move the customer has to confirm with a tap.
     *
     * Two callers. A typed code for a table that is already in use ("Table X
     * is already in use. Join it?"). And the phone-camera QR door for ANY
     * other table: that door renders the menu at the QR's own URL, so a
     * refresh or the Back button replays an old table's QR — it must never
     * move a seated customer on its own. $occupied only picks the wording.
     *
     * Holds what was validated, not the credential: the table's id plus a hash
     * of the code it carried at that moment, so confirming after an admin
     * regenerated the code is refused just as the old code itself would be.
     * Bound to the seat it was raised from, so moving or leaving in the
     * meantime voids it.
     */
    public static function stage(RestaurantTable $table, array $seat, bool $occupied): void
    {
        session()->put(self::PENDING_KEY, [
            'table_id'       => (int) $table->id,
            'branch_id'      => (int) $table->branch_id,
            'table_number'   => (string) $table->table_number,
            'occupied'       => $occupied,
            'code_hash'      => hash('sha256', (string) $table->code),
            'from_branch_id' => $seat['branch_id'],
            'from_table'     => $seat['table_number'],
            'expires_at'     => now()->addMinutes(self::PENDING_TTL_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * The staged join, if it is still answerable from where this device sits.
     * Anything stale is dropped on the way.
     */
    public static function pending(): ?array
    {
        $pending = session(self::PENDING_KEY);

        if (!is_array($pending)) {
            return null;
        }

        $seat = self::currentSeat();

        $valid = $seat !== null
            && $seat['branch_id'] === ($pending['from_branch_id'] ?? null)
            && $seat['table_number'] === ($pending['from_table'] ?? null)
            && (int) ($pending['expires_at'] ?? 0) >= now()->getTimestamp();

        if (!$valid) {
            session()->forget(self::PENDING_KEY);

            return null;
        }

        return $pending;
    }

    /**
     * Re-run first-entry validation on a staged table at the moment the
     * customer confirms: the branch may have closed, the table may have been
     * taken out of service, the code may have been regenerated.
     *
     * @return array{ok: bool, branch?: Branch, table?: RestaurantTable, table_number?: string, error?: string}
     */
    public static function revalidate(array $pending): array
    {
        $table = RestaurantTable::find((int) ($pending['table_id'] ?? 0));

        if (!$table
            || (int) $table->branch_id !== (int) ($pending['branch_id'] ?? 0)
            || !hash_equals((string) ($pending['code_hash'] ?? ''), hash('sha256', (string) $table->code))) {
            return ['ok' => false, 'error' => TableEntry::ERR_QR_STALE];
        }

        return TableEntry::validate($table->branch_id, $table->table_number, $table->code);
    }
}

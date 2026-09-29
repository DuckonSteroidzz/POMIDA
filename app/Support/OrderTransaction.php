<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The transaction an order is PLACED in — customer checkout
 * (OrderController::placeOrder) and the walk-in counter
 * (AdminController::storeManualOrder). Both call run() instead of
 * DB::transaction().
 *
 * WHY: THE OVERSELL GUARD NEEDS TO SEE THE ORDER THAT BEAT IT
 * ------------------------------------------------------------
 * The stock gate (InventoryDeductionService::cartShortfalls($lines, $branch,
 * lock: true)) takes SELECT … FOR UPDATE on the inventory rows, then counts
 * the stock that open orders have already committed. The lock makes two
 * checkouts queue; the count is what makes the loser refuse. But MariaDB's
 * default isolation is REPEATABLE READ, where a transaction's plain reads all
 * come from the snapshot taken at its FIRST plain read — and the gate reads
 * recipes (plain reads) before it locks. So the checkout that lost the race
 * waited on the lock and then counted committed orders from a snapshot taken
 * before the winner committed: it never saw the winning order, and both were
 * placed.
 *
 * Proven with two real processes through the real placeOrder() (Menu Item
 * Sizes Phase 2 investigation, 2026-09-27): 1 unit of stock, both checkouts
 * queued behind a held row lock -> 2 orders. The same race at READ COMMITTED
 * -> 1 order and the normal "out of stock" refusal for the other; control
 * with 5 units -> 2 orders. The pre-existing single-process tests could not
 * see this: they seed the competing order first, so it is always in the
 * snapshot.
 *
 * READ COMMITTED gives every plain read inside the transaction the latest
 * committed data, so the count taken after the lock includes the winner —
 * whatever the gate happens to read before it locks, now or after some later
 * change. It is scoped to this one transaction (SET TRANSACTION without
 * SESSION/GLOBAL applies to the next transaction only), so nothing else in
 * the app changes isolation.
 *
 * Skipped when a transaction is already open: the level cannot change inside
 * one (MySQL error 1568), and the only callers that nest are the test suite's
 * DatabaseTransactions wrapper, where the competing order is always visible
 * anyway.
 *
 * Operational note: with binary logging ON and binlog_format=STATEMENT,
 * InnoDB refuses writes under READ COMMITTED (error 1665). This server has
 * log_bin OFF (and MariaDB's default format is MIXED, which is fine); a host
 * that turns on STATEMENT-format binlogs must switch it to MIXED or ROW.
 */
final class OrderTransaction
{
    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback)
    {
        if (DB::transactionLevel() === 0 && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return DB::transaction($callback);
    }
}

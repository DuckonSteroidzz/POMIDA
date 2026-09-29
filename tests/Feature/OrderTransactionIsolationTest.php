<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Customer\OrderController;
use App\Support\OrderTransaction;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The order transaction runs at READ COMMITTED — and only it.
 *
 * Why it must (App\Support\OrderTransaction): under MariaDB's default
 * REPEATABLE READ the stock gate's plain reads came from a snapshot taken
 * before it waited on the row lock, so the checkout that lost a race never
 * saw the winning order and both were placed — proven with two real
 * processes through placeOrder() (1 unit of stock -> 2 orders; at READ
 * COMMITTED -> 1 order + the normal refusal; 5-unit control -> 2 orders).
 *
 * That race needs two real connections and cannot run inside this suite
 * (DatabaseTransactions wraps each test in ONE transaction, where the
 * competing order is always visible). What CAN be pinned here is the
 * mechanism: the level the database actually applies to the order
 * transaction, and that both order paths use it.
 *
 * No DatabaseTransactions on purpose — the level can only be set when no
 * transaction is open. Nothing here writes; the probe takes one row lock on
 * a branch for a quarter of a second.
 *
 * INFORMATION_SCHEMA.INNODB_TRX is served from a cache InnoDB refreshes at
 * most every ~100 ms, so each probe waits first; without that, consecutive
 * probes return the first answer again and prove nothing.
 */
class OrderTransactionIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pomida_db_testing', DB::selectOne('select database() as d')->d);
        $this->assertSame(0, DB::transactionLevel(), 'no transaction may be open around these tests');
    }

    /** The isolation level InnoDB is running the CURRENT transaction at. */
    private function currentTransactionLevel(): ?string
    {
        DB::table('branches')->orderBy('id')->limit(1)->lockForUpdate()->get();
        usleep(250000); // let the INNODB_TRX cache refresh

        return DB::selectOne(
            'select trx_isolation_level as l from information_schema.INNODB_TRX where trx_mysql_thread_id = connection_id()'
        )?->l;
    }

    public function test_an_order_transaction_runs_at_read_committed_and_nothing_else_does(): void
    {
        $this->assertSame('REPEATABLE READ', DB::transaction(fn () => $this->currentTransactionLevel()), 'the app default');

        $this->assertSame('READ COMMITTED', OrderTransaction::run(fn () => $this->currentTransactionLevel()));

        $this->assertSame('REPEATABLE READ', DB::transaction(fn () => $this->currentTransactionLevel()),
            'the next ordinary transaction is back to the default — the level never leaks');
        $this->assertSame('REPEATABLE-READ', DB::selectOne('select @@tx_isolation as s')->s, 'session untouched');

        $this->assertSame('READ COMMITTED', OrderTransaction::run(fn () => $this->currentTransactionLevel()), 'every time');
    }

    public function test_inside_an_already_open_transaction_it_just_joins_it(): void
    {
        $result = DB::transaction(function () {
            $inner = OrderTransaction::run(fn () => 'ran');

            return [$inner, DB::transactionLevel(), $this->currentTransactionLevel()];
        });

        $this->assertSame(['ran', 1, 'REPEATABLE READ'], $result,
            'no error 1568, the outer transaction and its level unchanged');
    }

    public function test_it_returns_the_callbacks_value_and_rolls_back_on_a_throw(): void
    {
        $this->assertSame(42, OrderTransaction::run(fn () => 42));

        try {
            OrderTransaction::run(function () {
                throw new \DomainException('boom');
            });
            $this->fail('the exception must propagate');
        } catch (\DomainException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, DB::transactionLevel(), 'rolled back, nothing left open');
    }

    /** Both doors into the pantry place their order through it. */
    public function test_both_order_paths_place_orders_through_it(): void
    {
        foreach ([[OrderController::class, 'placeOrder'], [AdminController::class, 'storeManualOrder']] as [$class, $method]) {
            $reflection = new ReflectionMethod($class, $method);
            $source = implode('', array_slice(
                file($reflection->getFileName()),
                $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1
            ));

            $this->assertSame(1, substr_count($source, 'OrderTransaction::run('), $class . '::' . $method);
            $this->assertSame(0, substr_count($source, 'DB::transaction('), $class . '::' . $method . ' must not open a plain transaction');
        }
    }
}

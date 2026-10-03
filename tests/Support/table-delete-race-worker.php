<?php

/*
 * The second (and third) process for Tests\Feature\TableDeleteRaceTest. A real
 * race needs two database connections in two processes; one PHPUnit process
 * cannot hold a lock against itself. TESTING DATABASE ONLY: it forces
 * pomida_db_testing and refuses to act if anything else resolved.
 *
 * usage: php table-delete-race-worker.php <command> ...
 *   hold-claim  <branchId> <tableNumber> <tableId> <seconds>  claim() inside a transaction held open
 *   hold-delete <tableId> <branchId> <seconds>                deleteUnusedTable() inside a transaction held open
 *   claim       <branchId> <tableNumber> <tableId>            plain claim(), as a customer door makes it
 *   delete      <tableId> <branchId>                          plain deleteUnusedTable(), as the endpoint makes it
 *
 * Prints "HOLDING" once the locks are held (hold-* only), then one line:
 * RESULT {"ok":..,"status":..,"error":..,"ms":..} — ms is how long the
 * service call took, so the test can tell a call that waited on a lock from
 * one that did not.
 */

foreach ([
    'APP_ENV'        => 'testing',
    'DB_DATABASE'    => 'pomida_db_testing',
    'SESSION_DRIVER' => 'array',
    'CACHE_STORE'    => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER'    => 'array',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$base = dirname(__DIR__, 2);

require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Branch;
use App\Models\RestaurantTable;
use App\Services\TableOccupancy;
use Illuminate\Support\Facades\DB;

if (DB::connection()->getDatabaseName() !== 'pomida_db_testing') {
    fwrite(STDERR, 'REFUSING: resolved database is ' . DB::connection()->getDatabaseName() . "\n");
    exit(2);
}

// Keep this process's audit lines out of storage/logs.
if (getenv('RACE_AUDIT_LOG')) {
    config(['logging.channels.table_moves.path' => getenv('RACE_AUDIT_LOG')]);
}

$args = array_slice($argv, 1);
$command = $args[0] ?? '';

$say = function (string $line): void {
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
};

$result = function (array $r, float $started) use ($say): void {
    $say('RESULT ' . json_encode([
        'ok'     => $r['ok'],
        'status' => $r['status'] ?? null,
        'error'  => $r['error'] ?? null,
        'ms'     => (int) round((microtime(true) - $started) * 1000),
    ]));
};

switch ($command) {
    case 'hold-claim':
        [, $branchId, $number, $tableId, $seconds] = $args;
        $branch = Branch::findOrFail((int) $branchId);
        $started = microtime(true);

        $r = DB::transaction(function () use ($branch, $number, $tableId, $seconds, $say) {
            $r = TableOccupancy::claim($branch, $number, '127.0.0.1', (int) $tableId);
            $say('HOLDING');
            usleep((int) ((float) $seconds * 1e6));

            return $r;
        });

        $result($r, $started);
        break;

    case 'hold-delete':
        [, $tableId, $branchId, $seconds] = $args;
        $table = RestaurantTable::findOrFail((int) $tableId);
        $started = microtime(true);

        // The isolation deleteUnusedTable() would pick for itself (OrderTransaction skips it inside an open transaction).
        DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

        $r = DB::transaction(function () use ($table, $branchId, $seconds, $say) {
            $r = TableOccupancy::deleteUnusedTable($table, (int) $branchId);
            $say('HOLDING');
            usleep((int) ((float) $seconds * 1e6));

            return $r;
        });

        $result($r, $started);
        break;

    case 'claim':
        [, $branchId, $number, $tableId] = $args;
        $branch = Branch::findOrFail((int) $branchId);
        $started = microtime(true);

        $result(TableOccupancy::claim($branch, $number, '127.0.0.1', (int) $tableId), $started);
        break;

    case 'delete':
        [, $tableId, $branchId] = $args;
        $table = RestaurantTable::find((int) $tableId);
        $started = microtime(true);

        $result($table
            ? TableOccupancy::deleteUnusedTable($table, (int) $branchId)
            : ['ok' => false, 'status' => 404, 'error' => 'not found before the call'], $started);
        break;

    default:
        fwrite(STDERR, "unknown command\n");
        exit(1);
}

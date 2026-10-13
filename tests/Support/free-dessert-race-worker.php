<?php

/*
 * The second process for Tests\Feature\FreeDessertRaceTest. Two requests
 * racing for the same free dessert need two database connections in two
 * processes; one PHPUnit process cannot block on itself. TESTING DATABASE
 * ONLY: it forces pomida_db_testing and refuses to act if anything else
 * resolved.
 *
 * usage: php free-dessert-race-worker.php <command> <userId> <arg> [seconds]
 *
 *   grant-hold-commit <userId> <number> <s>        grant() of dessert <number> inside an
 *                                                  outer transaction held open, then COMMIT
 *   grant             <userId> <number>            plain grant() of dessert <number>
 *   grant-due-hold-commit <userId> - <s>           grantDue() — what completion runs —
 *                                                  held open, then COMMIT
 *   grant-due         <userId> -                   plain grantDue(), as completion runs it
 *   spend-hold-commit <userId> <rewardId> <s>      the checkout's spend, held open, then COMMIT
 *   spend-hold-rollback <userId> <rewardId> <s>    ... then ROLLBACK
 *   spend             <userId> <rewardId>          the checkout's spend
 *
 * "The checkout's spend" is exactly what OrderController::placeOrder() does
 * with a reward code inside its OrderTransaction: lockForSpend() on the
 * reward the code named, write the order and the ₱0 line for the reward's own
 * item, markUsed(). Orders are numbered FDRACE-… so the test can delete them.
 *
 * Prints "HOLDING" once the work is done but not committed (hold-* only),
 * then one line: RESULT {"ok":..,"ms":..}.
 */

foreach ([
    'APP_ENV'          => 'testing',
    'DB_DATABASE'      => 'pomida_db_testing',
    'SESSION_DRIVER'   => 'array',
    'CACHE_STORE'      => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER'      => 'array',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$base = dirname(__DIR__, 2);

require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\FreeDesserts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (DB::connection()->getDatabaseName() !== 'pomida_db_testing') {
    fwrite(STDERR, 'REFUSING: resolved database is ' . DB::connection()->getDatabaseName() . "\n");
    exit(2);
}

// The test pins the stamp-card config it built its rows for, so the owner
// changing config/loyalty.php cannot change what this process decides.
foreach (['FD_VISIT_DAYS' => 'loyalty.free_dessert_visit_days', 'FD_COUNTS_FROM' => 'loyalty.free_dessert_counts_from'] as $env => $key) {
    if (($value = getenv($env)) !== false) {
        config([$key => $value]);
    }
}

[$command, $userId, $arg, $seconds] = array_pad(array_slice($argv, 1), 4, null);

$say = function (string $line): void {
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
};

$user = User::findOrFail((int) $userId);

// Every race runs at branch 1: the stamp orders, the reward and the spend.
$branchId = 1;

$grant = function () use ($user, $arg, $branchId): array {
    $t0 = microtime(true);
    $ok = FreeDesserts::grant($user, $branchId, (int) $arg) !== null;

    return ['ok' => $ok, 'ms' => (int) round((microtime(true) - $t0) * 1000)];
};

// Loyalty::orderCompleted()'s dessert step: every dessert the stamps have earned.
$grantDue = function () use ($user, $branchId): array {
    $t0 = microtime(true);
    $granted = count(FreeDesserts::grantDue($user, $branchId));

    return ['ok' => $granted > 0, 'granted' => $granted, 'ms' => (int) round((microtime(true) - $t0) * 1000)];
};

// The reward half of placeOrder()'s transaction body.
$spend = function () use ($user, $arg, $branchId): array {
    $t0 = microtime(true);
    $reward = FreeDesserts::lockForSpend($user, (int) $arg, $branchId);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    if (! $reward) {
        return ['ok' => false, 'ms' => $ms];
    }

    $item = MenuItem::findOrFail((int) $reward->menu_item_id);

    $order = Order::create([
        'order_number' => 'FDRACE-' . strtoupper(Str::random(8)), 'user_id' => $user->id, 'branch_id' => 1,
        'type' => 'pick_up', 'status' => 'pending', 'payment_method' => 'cash', 'payment_status' => 'pending',
        'subtotal' => 0, 'total' => 0, 'reward_code' => $reward->code,
    ]);

    $line = OrderItem::create([
        'order_id' => $order->id, 'menu_item_id' => $item->id, 'item_name' => $item->name,
        'quantity' => 1, 'item_price' => 0, 'subtotal' => 0, 'is_free_dessert' => true,
    ]);

    FreeDesserts::markUsed($reward, $order, $line);

    return ['ok' => true, 'ms' => $ms];
};

$readCommitted = fn () => DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

switch ($command) {
    case 'grant-hold-commit':
        DB::beginTransaction();
        $result = $grant();
        $say('HOLDING');
        sleep((int) $seconds);
        DB::commit();
        $say('RESULT ' . json_encode($result));
        break;

    case 'grant':
        $say('RESULT ' . json_encode($grant()));
        break;

    case 'grant-due-hold-commit':
        DB::beginTransaction();
        $result = $grantDue();
        $say('HOLDING');
        sleep((int) $seconds);
        DB::commit();
        $say('RESULT ' . json_encode($result));
        break;

    case 'grant-due':
        $say('RESULT ' . json_encode($grantDue()));
        break;

    case 'spend-hold-commit':
    case 'spend-hold-rollback':
        $readCommitted();
        DB::beginTransaction();
        $result = $spend();
        $say('HOLDING');
        sleep((int) $seconds);
        $command === 'spend-hold-commit' ? DB::commit() : DB::rollBack();
        $say('RESULT ' . json_encode($result));
        break;

    case 'spend':
        // Same isolation as App\Support\OrderTransaction::run().
        $readCommitted();
        $result = DB::transaction($spend);
        $say('RESULT ' . json_encode($result));
        break;

    default:
        fwrite(STDERR, "unknown command: {$command}\n");
        exit(2);
}

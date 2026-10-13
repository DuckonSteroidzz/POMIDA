<?php

/*
 * The second process for Tests\Feature\LoyaltyAwardRaceTest. Two requests
 * racing to pay the same bonus need two database connections in two
 * processes; one PHPUnit process cannot block on itself. TESTING DATABASE
 * ONLY: it forces pomida_db_testing and refuses to act if anything else
 * resolved.
 *
 * usage: php loyalty-award-race-worker.php <command> <userId> <awardKey> [seconds]
 *   hold-commit   award() inside an outer transaction held open, then COMMIT
 *   hold-rollback award() inside an outer transaction held open, then ROLLBACK
 *   award         plain award(), as AdminController::completeOrder makes it
 *
 * Prints "HOLDING" once the ledger row is written but not yet committed
 * (hold-* only), then one line: RESULT {"ok":..,"ms":..} — ms is how long
 * award() took, so the test can tell a call that waited on the other
 * process's lock from one that did not.
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

use App\Models\LoyaltyAward;
use App\Models\User;
use App\Services\Loyalty;
use Illuminate\Support\Facades\DB;

if (DB::connection()->getDatabaseName() !== 'pomida_db_testing') {
    fwrite(STDERR, 'REFUSING: resolved database is ' . DB::connection()->getDatabaseName() . "\n");
    exit(2);
}

[$command, $userId, $awardKey, $seconds] = array_pad(array_slice($argv, 1), 4, null);

$say = function (string $line): void {
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
};

$user = User::findOrFail((int) $userId);

$award = function () use ($user, $awardKey): array {
    $t0 = microtime(true);
    $ok = Loyalty::award($user, LoyaltyAward::TYPE_STREAK, (string) $awardKey, 10);

    return ['ok' => $ok, 'ms' => (int) round((microtime(true) - $t0) * 1000)];
};

switch ($command) {
    case 'hold-commit':
    case 'hold-rollback':
        DB::beginTransaction();
        $result = $award();
        $say('HOLDING');
        sleep((int) $seconds);
        $command === 'hold-commit' ? DB::commit() : DB::rollBack();
        $say('RESULT ' . json_encode($result));
        break;

    case 'award':
        $say('RESULT ' . json_encode($award()));
        break;

    default:
        fwrite(STDERR, "unknown command: {$command}\n");
        exit(2);
}

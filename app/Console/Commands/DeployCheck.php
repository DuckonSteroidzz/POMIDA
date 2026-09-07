<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * `php artisan deploy:check` — the one command that says whether this server is
 * safe to expose.
 *
 * WHY THIS EXISTS
 * ---------------
 * docs/DEPLOYMENT.md used to end with a tinker one-liner whose output the
 * reader had to interpret ("must print exactly: production false smtp"). That
 * asks a non-technical person to know what a wrong answer looks like. This
 * command answers the question instead of showing the raw values, and ends with
 * one sentence that needs no interpretation.
 *
 * WHAT COUNTS AS CRITICAL
 * -----------------------
 * A check is a FAIL only if getting it wrong means the site is unsafe or
 * visibly broken for real customers. Everything softer is a NOTE, printed
 * separately, and NOTES NEVER BLOCK. That separation is deliberate: if advisory
 * items could turn the last line red, people would learn to ignore the last
 * line.
 *
 * THE GATE THIS EXISTS FOR ABOVE ALL
 * ----------------------------------
 * With APP_ENV=local AND MAIL_MAILER=log, the password-reset page prints the
 * 6-digit verification code on screen. On a public server that is a complete
 * account takeover for any ADMIN whose email address is known (staff accounts
 * can no longer request a reset at all — see
 * AdminAuthController::resettableRoles()). That
 * check below does not re-implement the condition — it calls the REAL
 * HandlesPasswordReset::devVisibleCode() through reflection and asks it for a
 * code. If the live code hands one back, the gate is open. This cannot drift
 * from production behaviour, because it IS production behaviour.
 */
class DeployCheck extends Command
{
    protected $signature = 'deploy:check';

    protected $description = 'Check every deploy-critical setting and say whether this server is safe to go live';

    /** @var list<array{label:string,ok:bool,detail:string}> */
    private array $results = [];

    /** @var list<string> */
    private array $notes = [];

    private function check(string $label, bool $ok, string $detail): void
    {
        $this->results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    private function note(string $text): void
    {
        $this->notes[] = $text;
    }

    public function handle(): int
    {
        // Artisan reuses one resolved command instance for the life of the
        // process, so these must be cleared per run rather than only being
        // initialised at construction. Without this, a second invocation in the
        // same process reprints the first run's results alongside its own —
        // caught by DeployCheckTest, which calls the command several times.
        $this->results = [];
        $this->notes = [];

        $this->newLine();
        $this->line('  POMIDA deploy check');
        $this->line('  ' . str_repeat('=', 62));
        $this->newLine();

        $this->checkEnvironment();
        $this->checkDebug();
        $this->checkAppKey();
        $this->checkAppUrl();
        $this->checkMailer();
        $this->checkDevResetCodeGate();
        $this->checkDatabase();
        $this->checkHttps();
        $this->checkDemoData();
        $this->checkStorageLink();
        $this->collectNotes();

        return $this->report();
    }

    // ══════════════════════════════════════════════════════════════════

    private function checkEnvironment(): void
    {
        $env = (string) config('app.env');

        $this->check(
            'APP_ENV is production',
            $env === 'production',
            $env === 'production'
                ? 'production'
                : "currently '{$env}' — set APP_ENV=production in .env, then run: php artisan config:clear"
        );
    }

    private function checkDebug(): void
    {
        $debug = (bool) config('app.debug');

        $this->check(
            'APP_DEBUG is off',
            $debug === false,
            $debug
                ? 'currently true — every error page would show your database password and file paths '
                    . 'to whoever triggered it. Set APP_DEBUG=false in .env, then run: php artisan config:clear'
                : 'false'
        );
    }

    private function checkAppKey(): void
    {
        $key = (string) config('app.key');

        $this->check(
            'APP_KEY is set',
            $key !== '',
            $key !== ''
                ? 'set'
                : 'empty — run: php artisan key:generate'
        );
    }

    private function checkAppUrl(): void
    {
        $url = trim((string) config('app.url'));
        $problems = [];

        if ($url === '' || $url === 'http://localhost') {
            $problems[] = 'not set to your real domain';
        }
        if (str_ends_with($url, '/')) {
            $problems[] = 'has a trailing slash (remove it)';
        }
        if ($url !== '' && ! str_starts_with($url, 'https://')) {
            $problems[] = 'does not start with https://';
        }
        if (preg_match('/localhost|127\.0\.0\.1|::1/', $url)) {
            $problems[] = 'still points at this development machine';
        }

        $this->check(
            'APP_URL is your real https domain',
            $problems === [],
            $problems === []
                ? $url
                : "'{$url}' — " . implode('; ', $problems)
                    . '. This is what printed table QR codes and password-reset links are built from.'
        );
    }

    private function checkMailer(): void
    {
        $mailer = (string) config('mail.default');
        $notDelivering = ['log', 'array', 'null'];

        $this->check(
            'MAIL_MAILER delivers real email',
            ! in_array($mailer, $notDelivering, true),
            in_array($mailer, $notDelivering, true)
                ? "currently '{$mailer}', which DELIVERS NOTHING. Customers would be told a password-reset "
                    . 'email was sent and it would never arrive. Set MAIL_MAILER=smtp and fill in the '
                    . 'MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD values from your mail provider.'
                : $mailer
        );

        // A real mailer with no credentials behind it is just as broken.
        if (! in_array($mailer, $notDelivering, true)) {
            $missing = [];
            foreach (['host' => 'MAIL_HOST', 'username' => 'MAIL_USERNAME', 'password' => 'MAIL_PASSWORD'] as $k => $envName) {
                $v = config("mail.mailers.{$mailer}.{$k}");
                if ($v === null || $v === '' || $v === 'null') {
                    $missing[] = $envName;
                }
            }
            if (config('mail.from.address') === null || config('mail.from.address') === '' || config('mail.from.address') === 'hello@example.com') {
                $missing[] = 'MAIL_FROM_ADDRESS';
            }

            $this->check(
                'Mail credentials are filled in',
                $missing === [],
                $missing === []
                    ? 'all set'
                    : 'still empty or still the example value: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * The account-takeover gate. Asks the REAL code whether it would print a
     * reset code on screen.
     */
    private function checkDevResetCodeGate(): void
    {
        try {
            $controller = app(\App\Http\Controllers\Customer\AuthController::class);
            $method = new \ReflectionMethod($controller, 'devVisibleCode');
            $method->setAccessible(true);

            $sentinel = '424242';
            $leaked = $method->invoke($controller, $sentinel);

            $this->check(
                'Reset codes are NOT shown on screen',
                $leaked === null,
                $leaked === null
                    ? 'gate closed'
                    : 'THE VERIFICATION PAGE WOULD PRINT THE 6-DIGIT RESET CODE ON SCREEN. Anyone who knows a '
                        . 'ADMIN email address could take over that account without touching their inbox. '
                        . "This happens when APP_ENV is 'local' AND MAIL_MAILER is 'log' at the same time — fix "
                        . 'both (APP_ENV=production, MAIL_MAILER=smtp), then run: php artisan config:clear'
            );
        } catch (\Throwable $e) {
            $this->check(
                'Reset codes are NOT shown on screen',
                false,
                'could not be verified (' . $e->getMessage() . ') — treat as unsafe until checked by hand'
            );
        }
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            $name = DB::connection()->getDatabaseName();
            $this->check('Database connects', true, (string) $name);
        } catch (\Throwable $e) {
            $this->check(
                'Database connects',
                false,
                'cannot connect — check DB_DATABASE, DB_USERNAME and DB_PASSWORD against the values in '
                    . 'hPanel -> Databases. (' . $e->getMessage() . ')'
            );

            return;
        }

        try {
            $pending = collect(app('migrator')->getRepository()->getRan());
            $files = collect(app('migrator')->getMigrationFiles(database_path('migrations')))->keys();
            $missing = $files->diff($pending);

            $this->check(
                'Database schema is up to date',
                $missing->isEmpty(),
                $missing->isEmpty()
                    ? 'no pending migrations'
                    : $missing->count() . ' migration(s) not yet applied — run: php artisan migrate --force'
            );
        } catch (\Throwable $e) {
            $this->note('Could not determine migration status: ' . $e->getMessage());
        }
    }

    private function checkHttps(): void
    {
        $force = (bool) config('app.force_https');

        $this->check(
            'FORCE_HTTPS is on',
            $force,
            $force
                ? 'true'
                : 'false — links and printed table QR codes would be built as http://. Turn this on only '
                    . 'AFTER https:// loads with a padlock, then run: php artisan config:clear'
        );

        $secureCookie = (bool) config('session.secure');

        $this->check(
            'Session cookie is HTTPS-only',
            $secureCookie,
            $secureCookie
                ? 'true'
                : 'false — set SESSION_SECURE_COOKIE=true once https:// works, so login sessions cannot be '
                    . 'read off an unencrypted connection'
        );
    }

    private function checkDemoData(): void
    {
        $on = (bool) config('demo.auto_top_up_sales');

        $this->check(
            'Demo sales fabrication is off',
            ! $on,
            $on
                ? 'DEMO_SALES_AUTO_TOPUP is true — this invents sales history. Set it to false in .env'
                : 'false'
        );
    }

    private function checkStorageLink(): void
    {
        $link = public_path('storage');
        $exists = File::exists($link);

        $this->check(
            'Storage link exists',
            $exists,
            $exists
                ? 'public/storage present'
                : 'public/storage is missing, so uploaded images would show as broken. Run: php artisan storage:link'
        );
    }

    /**
     * Advisory only. These never change the final verdict — see the class
     * docblock for why that separation matters.
     */
    private function collectNotes(): void
    {
        if (env('ADMIN_BOOTSTRAP_PASSWORD')) {
            $this->note(
                'ADMIN_BOOTSTRAP_PASSWORD still has a value in .env. That is correct for the very first '
                . 'deploy. Once the first admin has logged in, blank it (and ADMIN_BOOTSTRAP_EMAIL) so a '
                . 'real password is not left sitting in a file on the server.'
            );
        }

        if (config('app.debug') === false && config('app.env') === 'production') {
            $this->note(
                'Remember the two things this command cannot check for you: that https:// actually loads '
                . 'with a padlock in a browser, and that a real password-reset email actually arrives in a '
                . 'real inbox. Do both by hand before opening the site to customers.'
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════

    private function report(): int
    {
        $width = 0;
        foreach ($this->results as $r) {
            $width = max($width, strlen($r['label']));
        }

        $failures = 0;

        foreach ($this->results as $r) {
            $tag = $r['ok'] ? '<fg=green>PASS</>' : '<fg=red;options=bold>FAIL</>';
            if (! $r['ok']) {
                $failures++;
            }

            $this->line(sprintf('  [%s]  %s   %s', $tag, str_pad($r['label'], $width), $r['detail']));
        }

        if ($this->notes !== []) {
            $this->newLine();
            $this->line('  Notes (these do not block going live):');
            foreach ($this->notes as $note) {
                $this->line('    - ' . wordwrap($note, 90, "\n      "));
            }
        }

        $this->newLine();
        $this->line('  ' . str_repeat('=', 62));

        if ($failures === 0) {
            $this->line('  <fg=black;bg=green;options=bold> READY TO GO LIVE </>');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line('  <fg=white;bg=red;options=bold> NOT READY — fix the items marked FAIL above </>');
        $this->newLine();

        return self::FAILURE;
    }
}

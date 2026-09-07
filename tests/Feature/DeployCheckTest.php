<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `php artisan deploy:check` — does it actually catch what it exists to catch?
 *
 * The command is the single gate a non-technical teammate runs before opening
 * the site to customers, so "it printed READY" has to mean something. These
 * tests drive it from both ends:
 *
 *   - a production-shaped config must print READY TO GO LIVE
 *   - a development-shaped config must print NOT READY, and must specifically
 *     name the log mailer and the on-screen reset code, because those two are
 *     the account-takeover pair the whole gate was built around
 *   - and every individual check is then broken ON ITS OWN, with everything
 *     else left correct, to prove that check is load-bearing rather than
 *     riding along on its neighbours failing
 *
 * That last group is the part that matters. A command where only one check
 * really works would still pass the first two tests.
 */
class DeployCheckTest extends TestCase
{
    /**
     * A config that should pass everything. Individual tests break one key of
     * this at a time.
     */
    private function productionConfig(): array
    {
        return [
            'app.env'                      => 'production',
            'app.debug'                    => false,
            'app.key'                      => 'base64:' . base64_encode(random_bytes(32)),
            'app.url'                      => 'https://pomida.example.com',
            'app.force_https'              => true,
            'mail.default'                 => 'smtp',
            'mail.mailers.smtp.host'       => 'smtp.hostinger.com',
            'mail.mailers.smtp.username'   => 'orders@pomida.example.com',
            'mail.mailers.smtp.password'   => 'a-real-looking-password',
            'mail.from.address'            => 'orders@pomida.example.com',
            'session.secure'               => true,
            'demo.auto_top_up_sales'       => false,
        ];
    }

    private function devConfig(): array
    {
        return [
            'app.env'                => 'local',
            'app.debug'              => true,
            'app.url'                => 'http://127.0.0.1:8000',
            'app.force_https'        => false,
            'mail.default'           => 'log',
            'session.secure'         => false,
            'demo.auto_top_up_sales' => true,
        ];
    }

    /**
     * Run deploy:check under a given config and return [exitCode, output].
     *
     * app.env has to be set in TWO places, and it is worth saying why rather
     * than leaving the extra line looking redundant. config('app.env') is the
     * config value; app()->environment() reads the container's 'env' binding,
     * which Laravel fills from that same config value once, during bootstrap.
     * In a real run they always agree, because both come from APP_ENV. In a
     * test, writing config() alone leaves the container binding on 'testing',
     * so devVisibleCode() — which asks app()->environment('local') — would
     * never see the environment the test is trying to simulate, and the
     * account-takeover gate would appear to be closed when it was not being
     * exercised at all.
     */
    private function runCheck(array $config): array
    {
        foreach ($config as $key => $value) {
            config([$key => $value]);
        }

        if (array_key_exists('app.env', $config)) {
            $this->app['env'] = $config['app.env'];
        }

        $exit = Artisan::call('deploy:check');

        return [$exit, Artisan::output()];
    }

    /** Strip ANSI colour so assertions match on words, not escape codes. */
    private function plain(string $output): string
    {
        return preg_replace('/\e\[[0-9;]*m/', '', $output);
    }

    // ══════════════════════════════════════════════════════════════════
    // The two headline outcomes
    // ══════════════════════════════════════════════════════════════════

    public function test_a_production_style_config_prints_ready_to_go_live(): void
    {
        [$exit, $output] = $this->runCheck($this->productionConfig());
        $output = $this->plain($output);

        $this->assertStringContainsString('READY TO GO LIVE', $output);
        $this->assertStringNotContainsString('NOT READY', $output);
        $this->assertStringNotContainsString('FAIL', $output, "a production config should produce no FAIL lines:\n" . $output);
        $this->assertSame(0, $exit, 'a passing check should exit 0');
    }

    public function test_a_dev_style_config_prints_not_ready(): void
    {
        [$exit, $output] = $this->runCheck($this->devConfig());
        $output = $this->plain($output);

        $this->assertStringContainsString('NOT READY — fix the items marked FAIL above', $output);
        $this->assertStringNotContainsString('READY TO GO LIVE', $output);
        $this->assertSame(1, $exit, 'a failing check should exit non-zero');
    }

    /**
     * The specific requirement: a dev config must not just fail, it must SAY
     * that the mailer delivers nothing and that the reset code would be shown
     * on screen. Those two together are the account-takeover condition.
     */
    public function test_a_dev_style_config_names_the_log_mailer_and_the_exposed_reset_code(): void
    {
        [, $output] = $this->runCheck($this->devConfig());
        $output = $this->plain($output);

        $this->assertStringContainsString('MAIL_MAILER delivers real email', $output);
        $this->assertStringContainsString('DELIVERS NOTHING', $output);

        $this->assertStringContainsString('Reset codes are NOT shown on screen', $output);
        $this->assertStringContainsString('PRINT THE 6-DIGIT RESET CODE ON SCREEN', $output);
        $this->assertStringContainsString('account', $output);
    }

    // ══════════════════════════════════════════════════════════════════
    // Every check, broken on its own
    // ══════════════════════════════════════════════════════════════════

    /**
     * @dataProvider singleFailures
     */
    public function test_each_check_fails_on_its_own(array $override, string $mustMention): void
    {
        [$exit, $output] = $this->runCheck(array_merge($this->productionConfig(), $override));
        $output = $this->plain($output);

        $this->assertSame(
            1,
            $exit,
            "breaking " . json_encode($override) . " alone did not make deploy:check fail:\n" . $output
        );
        $this->assertStringContainsString('NOT READY', $output);
        $this->assertStringContainsString($mustMention, $output);
    }

    public static function singleFailures(): array
    {
        return [
            'not production'      => [['app.env' => 'local', 'mail.default' => 'smtp'], 'APP_ENV is production'],
            'debug left on'       => [['app.debug' => true], 'APP_DEBUG is off'],
            'no app key'          => [['app.key' => ''], 'APP_KEY is set'],
            'url is localhost'    => [['app.url' => 'http://localhost'], 'APP_URL is your real https domain'],
            'url not https'       => [['app.url' => 'http://pomida.example.com'], 'does not start with https://'],
            'url trailing slash'  => [['app.url' => 'https://pomida.example.com/'], 'trailing slash'],
            'log mailer'          => [['mail.default' => 'log'], 'DELIVERS NOTHING'],
            'array mailer'        => [['mail.default' => 'array'], 'DELIVERS NOTHING'],
            'no mail password'    => [['mail.mailers.smtp.password' => ''], 'MAIL_PASSWORD'],
            'example from addr'   => [['mail.from.address' => 'hello@example.com'], 'MAIL_FROM_ADDRESS'],
            'force https off'     => [['app.force_https' => false], 'FORCE_HTTPS is on'],
            'insecure cookie'     => [['session.secure' => false], 'Session cookie is HTTPS-only'],
            'demo data on'        => [['demo.auto_top_up_sales' => true], 'Demo sales fabrication is off'],
        ];
    }

    /**
     * The account-takeover gate specifically: APP_ENV=local AND MAIL_MAILER=log
     * together must trip it, while either one alone must not — that
     * fail-closed-on-both design is what makes a half-misconfigured server
     * still safe, and it is asserted here rather than assumed.
     */
    public function test_the_reset_code_gate_trips_only_when_both_conditions_are_wrong(): void
    {
        $both = $this->plain($this->runCheck(array_merge($this->productionConfig(), [
            'app.env' => 'local', 'mail.default' => 'log',
        ]))[1]);
        $this->assertStringContainsString('PRINT THE 6-DIGIT RESET CODE ON SCREEN', $both);

        $onlyEnv = $this->plain($this->runCheck(array_merge($this->productionConfig(), [
            'app.env' => 'local', 'mail.default' => 'smtp',
        ]))[1]);
        $this->assertStringNotContainsString('PRINT THE 6-DIGIT RESET CODE ON SCREEN', $onlyEnv,
            'APP_ENV=local with a real mailer does not expose the code, so the gate should not trip');

        $onlyMailer = $this->plain($this->runCheck(array_merge($this->productionConfig(), [
            'app.env' => 'production', 'mail.default' => 'log',
        ]))[1]);
        $this->assertStringNotContainsString('PRINT THE 6-DIGIT RESET CODE ON SCREEN', $onlyMailer,
            'MAIL_MAILER=log in production does not expose the code, so the gate should not trip');
    }

    // ══════════════════════════════════════════════════════════════════
    // Shape of the output
    // ══════════════════════════════════════════════════════════════════

    /**
     * The last line must be one of exactly two sentences and nothing else, so
     * there is nothing to interpret.
     */
    public function test_the_verdict_is_always_exactly_one_of_two_lines(): void
    {
        foreach ([$this->productionConfig(), $this->devConfig()] as $config) {
            $output = $this->plain($this->runCheck($config)[1]);

            $ready = substr_count($output, 'READY TO GO LIVE');
            $notReady = substr_count($output, 'NOT READY — fix the items marked FAIL above');

            $this->assertSame(1, $ready + $notReady, "expected exactly one verdict line:\n" . $output);
        }
    }

    /** Advisory notes must never turn a passing check into a failing one. */
    public function test_notes_do_not_block_going_live(): void
    {
        [$exit, $output] = $this->runCheck($this->productionConfig());
        $output = $this->plain($output);

        $this->assertStringContainsString('do not block going live', $output);
        $this->assertSame(0, $exit, 'notes were present and the command still must succeed');
    }
}

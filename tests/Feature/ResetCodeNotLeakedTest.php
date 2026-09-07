<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Manual security review 2026-08-31, item 4a.
 *
 * The password-reset verification page prints the 6-digit code in a yellow
 * "Development mode" banner when mail is not really being delivered. That is a
 * genuine convenience locally and a full account takeover anywhere else: anyone
 * who knows a staff or admin email could request a reset and read the code off
 * the page without ever touching that person's inbox.
 *
 * HandlesPasswordReset::devVisibleCode() therefore requires BOTH conditions —
 * APP_ENV=local AND a non-delivering mailer. This test exists to prove that a
 * HALF-misconfigured server is still safe, because that is the realistic
 * deployment accident: someone remembers one setting and forgets the other.
 *
 * The matrix below is the whole point. Only the first row may show the code.
 *
 *   environment   mailer   code on screen?
 *   local         log      YES  (dev convenience, deliberate)
 *   local         smtp     no
 *   production    log      no   <- forgot MAIL_MAILER
 *   production    smtp     no
 */
class ResetCodeNotLeakedTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    /**
     * Drive the real forgot-password endpoint and return the rendered
     * verification page, under a chosen environment and mailer.
     */
    private function verificationPageUnder(string $env, string $mailer, User $admin): string
    {
        /*
         * Overriding the environment away from 'testing' also switches CSRF
         * verification back on, because ValidateCsrfToken skips itself via
         * $app->runningUnitTests(), which is just environment('testing').
         * Without disabling it explicitly every POST below would 419 and the
         * "no code was shown" assertions would pass for entirely the wrong
         * reason. Found exactly that way during this review.
         */
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        /*
         * Fake the transport so a 'smtp' setting does not try to reach a real
         * mail server and fail delivery (which would abort the flow before the
         * verification page is ever rendered). What is under test is the
         * CONFIG VALUE that devVisibleCode() reads, not the delivery itself.
         */
        \Illuminate\Support\Facades\Mail::fake();

        $this->app->detectEnvironment(fn () => $env);
        config(['mail.default' => $mailer]);

        $response = $this->post(route('admin.forgot-password.post'), ['email' => $admin->email]);

        // Guard against the silent-failure mode: the reset must actually have
        // been accepted, otherwise "the page has no code on it" proves nothing.
        $response->assertRedirect(route('admin.verification'));

        return $this->get(route('admin.verification'))->getContent();
    }

    /** The code that was actually issued, read from the DB side channel. */
    private function issuedCodeExistsFor(User $admin): bool
    {
        return DB::table('password_reset_tokens')->where('email', $admin->email)->exists();
    }

    public function test_local_with_log_mailer_still_shows_the_code(): void
    {
        $admin = $this->admin();

        $html = $this->verificationPageUnder('local', 'log', $admin);

        $this->assertStringContainsString('Development mode', $html);
        $this->assertMatchesRegularExpression(
            '/so your code is\s*<strong>\s*\d{6}/s',
            $html,
            'the dev convenience should be preserved in local + log'
        );
    }

    public function test_local_but_real_mailer_does_not_show_the_code(): void
    {
        $admin = $this->admin();

        $html = $this->verificationPageUnder('local', 'smtp', $admin);

        $this->assertStringNotContainsString('Development mode', $html);
        $this->assertDoesNotMatchRegularExpression('/so your code is/', $html);
        $this->assertNull(session('dev_reset_code'));
        $this->assertTrue(
            $this->issuedCodeExistsFor($admin),
            'a code should still have been issued - it is just not displayed'
        );
    }

    public function test_production_with_log_mailer_does_not_show_the_code(): void
    {
        // The dangerous half-misconfiguration: deployed to production but
        // MAIL_MAILER was never switched off `log`.
        $admin = $this->admin();

        $html = $this->verificationPageUnder('production', 'log', $admin);

        $this->assertStringNotContainsString('Development mode', $html);
        $this->assertDoesNotMatchRegularExpression('/so your code is/', $html);
        $this->assertNull(session('dev_reset_code'));
    }

    public function test_production_with_real_mailer_does_not_show_the_code(): void
    {
        $admin = $this->admin();

        $html = $this->verificationPageUnder('production', 'smtp', $admin);

        $this->assertStringNotContainsString('Development mode', $html);
        $this->assertDoesNotMatchRegularExpression('/so your code is/', $html);
        $this->assertNull(session('dev_reset_code'));
    }

    /**
     * The gate itself, asserted directly.
     *
     * The four HTTP tests above go through the whole flow, which makes them
     * realistic but also sensitive to flash-session timing. This one calls
     * devVisibleCode() straight and pins the full truth table, so a future edit
     * that loosens the condition fails here immediately and unambiguously.
     */
    public function test_the_gate_itself_only_opens_for_local_plus_log(): void
    {
        $controller = new \App\Http\Controllers\Admin\AdminAuthController();

        $method = new \ReflectionMethod($controller, 'devVisibleCode');
        $method->setAccessible(true);

        $cases = [
            ['local',      'log',  '123456', 'local + log is the one allowed combination'],
            ['local',      'smtp', null,     'a real mailer must suppress it even locally'],
            ['production', 'log',  null,     'forgetting MAIL_MAILER must not leak the code'],
            ['production', 'smtp', null,     'production must never show it'],
            ['staging',    'log',  null,     'only local counts - staging is not local'],
            ['testing',    'log',  null,     'the test environment is not local either'],
        ];

        foreach ($cases as [$env, $mailer, $expected, $why]) {
            $this->app->detectEnvironment(fn () => $env);
            config(['mail.default' => $mailer]);

            $this->assertSame(
                $expected,
                $method->invoke($controller, '123456'),
                "{$why} (env={$env}, mailer={$mailer})"
            );
        }
    }
}

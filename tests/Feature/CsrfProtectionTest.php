<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * CSRF protection.
 *
 * RESULT: CLEAN. Nothing has switched it off. This file records the evidence
 * and pins it.
 *
 * WHAT WAS AUDITED
 * ----------------
 *   - bootstrap/app.php: withMiddleware() only registers three aliases and one
 *     priority-list tweak. No CSRF configuration of any kind.
 *   - No $except array, no validateCsrfTokens(except: ...), no
 *     withoutMiddleware(), and no VerifyCsrfToken/ValidateCsrfToken subclass
 *     anywhere in app/, bootstrap/, routes/ or config/.
 *   - All 85 state-changing routes are inside routes/web.php, which
 *     ->withRouting(web: ...) places in the `web` middleware group, and that
 *     group carries ValidateCsrfToken.
 *   - 87 Blade <form> tags. 85 emit @csrf; the two that do not are both
 *     method="GET" (admin/completed-orders and admin/summary filter forms),
 *     where CSRF does not apply.
 *   - 18 fetch() calls use a mutating method; all 18 send a CSRF token.
 *
 * ══════════════════════════════════════════════════════════════════════
 * THE TRAP, AND HOW IT IS HANDLED HERE
 * ══════════════════════════════════════════════════════════════════════
 *
 * VerifyCsrfToken::handle() begins:
 *
 *     if ($this->isReading($request) ||
 *         $this->runningUnitTests() ||        // <-- this one
 *         $this->inExceptArray($request) ||
 *         $this->tokensMatch($request))
 *
 * and runningUnitTests() is
 *
 *     $this->app->runningInConsole() && $this->app->runningUnitTests()
 *
 * where Application::runningUnitTests() is simply $this['env'] === 'testing'.
 * phpunit.xml sets APP_ENV=testing, so **CSRF validation is skipped entirely
 * for every test in this suite by default.** A test that posts without a token
 * and checks for a rejection is not testing CSRF at all; it is testing
 * whatever else went wrong with the request.
 *
 * So each test here sets $this->app['env'] = 'production' first, which makes
 * runningUnitTests() false and puts the real middleware in play. Measured, to
 * be sure the lever actually does something:
 *
 *     POST /customer/login, no token, env=testing     -> HTTP 302  (skipped)
 *     POST /customer/login, no token, env=production  -> HTTP 419  (enforced)
 *     POST /customer/login, valid token, env=production -> HTTP 302 (accepted)
 *
 * The third line is the control that matters: without it, "419" could just
 * mean the enforcement is on and nothing can ever get through.
 */
class CsrfProtectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Turn CSRF validation back on for this test.
     *
     * See the class docblock: without this, every assertion below is
     * meaningless because the middleware short-circuits on APP_ENV=testing.
     */
    private function enforceCsrf(): void
    {
        $this->app['env'] = 'production';

        $this->assertFalse(
            $this->app->runningUnitTests(),
            'CSRF is still being skipped — the rest of this test proves nothing'
        );
    }

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)
            ->orderBy('id')->firstOrFail();
    }

    private function admin(): ?User
    {
        return User::where('role', 'admin')->where('is_active', true)->orderBy('id')->first();
    }

    // ══════════════════════════════════════════════════════════════════
    // Configuration guards
    // ══════════════════════════════════════════════════════════════════

    /**
     * Nothing may exclude a route from CSRF validation. This is the guard that
     * would notice a future `validateCsrfTokens(except: ['webhooks/*'])`, which
     * is the usual way this protection gets quietly widened.
     */
    public function test_nothing_excludes_any_route_from_csrf_validation(): void
    {
        $offenders = [];

        $paths = [app_path(), base_path('bootstrap'), base_path('routes'), base_path('config')];

        foreach ($paths as $dir) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $source = file_get_contents($file->getPathname());

                foreach ([
                    'validateCsrfTokens'  => 'CSRF exclusion list configured',
                    'VerifyCsrfToken'     => 'references VerifyCsrfToken',
                    'ValidateCsrfToken'   => 'references ValidateCsrfToken',
                    'withoutMiddleware'   => 'strips middleware from a route',
                ] as $needle => $why) {
                    if (str_contains($source, $needle)) {
                        $offenders[] = "{$relative} — {$why}";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "CSRF protection may have been weakened:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * Every Blade form that submits with a mutating method must emit @csrf.
     * A form without it is broken for real users as well as unprotected, but
     * it is the kind of thing that gets noticed late.
     */
    public function test_every_mutating_blade_form_emits_a_csrf_token(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $source = file_get_contents($file->getPathname());

            // Each <form ...> ... </form> block.
            if (! preg_match_all('/<form\b.*?<\/form>/is', $source, $matches)) {
                continue;
            }

            foreach ($matches[0] as $form) {
                // Only forms that actually submit with a mutating method.
                if (! preg_match('/method\s*=\s*["\']?\s*post/i', $form)) {
                    continue;
                }

                if (! str_contains($form, '@csrf') && ! str_contains($form, 'csrf_field')) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            "these views contain a POST form with no @csrf:\n  "
            . implode("\n  ", array_unique($offenders))
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // The consequential endpoints, without a token
    // ══════════════════════════════════════════════════════════════════

    /**
     * @dataProvider consequentialEndpoints
     */
    public function test_a_post_without_a_csrf_token_is_refused(string $method, string $uri, array $payload): void
    {
        $this->enforceCsrf();

        $response = $this->call($method, $uri, $payload);

        $this->assertSame(
            419,
            $response->getStatusCode(),
            "{$method} {$uri} accepted a request with no CSRF token "
            . '(got HTTP ' . $response->getStatusCode() . ')'
        );
    }

    public static function consequentialEndpoints(): array
    {
        return [
            'place order'            => ['POST', '/customer/place-order', ['order_type' => 'pick_up']],
            'gcash mark as paid'     => ['POST', '/customer/gcash-payment/1/paid', []],
            'customer password reset'=> ['POST', '/customer/forgot-password', ['email' => 'x@example.com']],
            'customer new password'  => ['POST', '/customer/new-password', ['password' => 'Str0ng!Passw0rd']],
            'customer verification'  => ['POST', '/customer/verification', ['otp' => ['1','2','3','4','5','6']]],
            'admin password reset'   => ['POST', '/admin/forgot-password', ['email' => 'x@example.com']],
            'admin user creation'    => ['POST', '/admin/users', ['name' => 'X', 'email' => 'x@example.com']],
            'admin login'            => ['POST', '/admin/login', ['email' => 'x@example.com', 'password' => 'x']],
            'customer login'         => ['POST', '/customer/login', ['email' => 'x@example.com', 'password' => 'x']],
            'customer registration'  => ['POST', '/customer/register', ['name' => 'X']],
            'add points (the wheel)' => ['POST', '/customer/add-points', ['points' => 3]],
            'order status: complete' => ['PUT', '/admin/orders/1/complete', []],
            'order status: cancel'   => ['PUT', '/admin/orders/1/cancel', []],
            'discount approve'       => ['PUT', '/admin/orders/1/discount/approve', []],
            'payment approve'        => ['PUT', '/admin/orders/1/payment/approve', []],
            'staff account toggle'   => ['PUT', '/admin/users/1/toggle', []],
            'admin sets staff password' => ['PUT', '/admin/users/1/password', ['password' => 'Whatever!1']],
            'voucher creation'       => ['POST', '/admin/vouchers', ['code' => 'X']],
            'customer logout'        => ['POST', '/customer/logout', []],
            'admin logout'           => ['POST', '/admin/logout', []],
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // The controls — without these, "419 everywhere" proves nothing
    // ══════════════════════════════════════════════════════════════════

    /**
     * The same endpoint, WITH a valid token, must not be refused as a token
     * mismatch. If this failed, the tests above would only be showing that the
     * application rejects everything.
     */
    public function test_the_same_request_with_a_valid_token_is_not_refused(): void
    {
        $this->enforceCsrf();

        $token = 'valid-token-for-this-test';

        $response = $this->withSession(['_token' => $token])
            ->post('/customer/login', [
                '_token'   => $token,
                'email'    => 'nobody@invalid.local',
                'password' => 'whatever',
            ]);

        $this->assertNotSame(
            419,
            $response->getStatusCode(),
            'a request with a matching CSRF token was still refused — the tests above are '
            . 'only showing that everything is rejected'
        );
    }

    /**
     * And a real, complete action must go through end to end with a token —
     * proving the protection is not simply breaking the application.
     */
    public function test_a_genuine_action_succeeds_when_the_token_is_present(): void
    {
        $this->enforceCsrf();

        $customer = $this->customer();
        $customer->forceFill(['password' => 'Str0ng!Passw0rd'])->save();

        $token = 'valid-token-for-this-test';

        $response = $this->withSession(['_token' => $token])
            ->post('/customer/login', [
                '_token'   => $token,
                'email'    => $customer->email,
                'password' => 'Str0ng!Passw0rd',
            ]);

        $this->assertNotSame(419, $response->getStatusCode());
        $this->assertTrue(
            \Illuminate\Support\Facades\Auth::guard('customer')->check(),
            'a genuine login with a valid CSRF token did not succeed — CSRF enforcement is '
            . 'breaking legitimate use'
        );
    }

    /**
     * A token from a DIFFERENT session must not be accepted. This is the
     * property that actually stops the attack: an attacker can put any string
     * in their form, so what matters is that it must match the victim's
     * session token.
     */
    public function test_a_token_from_another_session_is_refused(): void
    {
        $this->enforceCsrf();

        $response = $this->withSession(['_token' => 'the-real-session-token'])
            ->post('/customer/login', [
                '_token'   => 'a-token-the-attacker-made-up',
                'email'    => 'nobody@invalid.local',
                'password' => 'whatever',
            ]);

        $this->assertSame(
            419,
            $response->getStatusCode(),
            'a mismatched CSRF token was accepted'
        );
    }

    /**
     * GET must never be refused — CSRF applies only to state-changing verbs,
     * and breaking reads would be an obvious regression.
     */
    public function test_read_requests_are_not_affected(): void
    {
        $this->enforceCsrf();

        $this->get('/customer/login')->assertOk();
        $this->get('/customer/menu')->assertSuccessful();
    }
}

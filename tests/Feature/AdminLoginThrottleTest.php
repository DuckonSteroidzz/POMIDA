<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Manual security review 2026-08-31, item 3.
 *
 * The reviewer found the staff/admin login allowed roughly ten wrong passwords
 * a minute before refusing. That is far more than a real person mistyping needs
 * on a privileged account, so the limit is now 3 a minute.
 *
 * This route is the login for BOTH staff and admin — there is no separate staff
 * limiter — so pinning it here covers both.
 *
 * The keying is deliberately left alone. Laravel keys an unauthenticated
 * throttle on the client IP, so these 3 attempts are per address across every
 * email an attacker tries. That is stricter than keying on IP+email, which
 * would give an attacker a fresh allowance for each address they guessed.
 */
class AdminLoginThrottleTest extends TestCase
{
    use DatabaseTransactions;

    private const LIMIT = 3;

    protected function setUp(): void
    {
        parent::setUp();

        // Each test starts with a clean counter, so a previous test's attempts
        // cannot make this one pass or fail for the wrong reason.
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * The cache key the admin-login limiter actually uses.
     *
     * Changed 2026-09-01 (Pass 7). This used to be sha1('|127.0.0.1') —
     * ThrottleRequests::resolveRequestSignature() for a guest on an UNNAMED
     * throttle. That key was the bug: it has an empty prefix, so it was shared
     * by every raw-throttled route on the address, and the notification bell's
     * 20-second poll was spending the login budget.
     *
     * admin.login.post is now the NAMED limiter `admin-login` (same 3/min, same
     * per-IP keying), and a named limiter keys on md5($name . $limit->key) —
     * see ThrottleRequests::handleRequestUsingNamedLimiter(). This resolves the
     * key through the registered limiter rather than reimplementing it, so it
     * cannot drift from what the middleware really does.
     */
    private function throttleKey(): string
    {
        $request = \Illuminate\Http\Request::create('http://127.0.0.1/admin/login', 'POST');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $limit = app(\Illuminate\Cache\RateLimiter::class)->limiter('admin-login')($request);
        $limit = is_array($limit) ? $limit[0] : $limit;

        return md5('admin-login' . $limit->key);
    }

    /**
     * The limit itself — asserted as a NUMBER, not as a middleware string.
     *
     * The old version asserted the literal 'throttle:3,1' was in the route's
     * middleware. That pinned an implementation detail rather than the
     * property: when the route moved to a named limiter with the identical
     * 3-per-minute limit, the assertion failed even though nothing about the
     * protection had changed. It now reads the limit out of the registered
     * limiter, which is what actually governs the route.
     */
    public function test_the_admin_login_route_is_limited_to_three_a_minute(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->getName() === 'admin.login.post'
        );

        $this->assertNotNull($route, 'the admin login route should exist');
        $this->assertContains(
            'throttle:admin-login',
            $route->gatherMiddleware(),
            'the admin login must go through the named admin-login limiter — a raw '
            . 'throttle:N,M shares its counter with every other raw-throttled route'
        );

        $request = \Illuminate\Http\Request::create('http://127.0.0.1/admin/login', 'POST');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $limiter = app(\Illuminate\Cache\RateLimiter::class)->limiter('admin-login');
        $this->assertNotNull($limiter, 'the admin-login limiter is not registered');

        $limit = $limiter($request);
        $limit = is_array($limit) ? $limit[0] : $limit;

        $this->assertSame(
            self::LIMIT,
            $limit->maxAttempts,
            'the staff/admin login must stay limited to ' . self::LIMIT . ' attempts a minute'
        );
        $this->assertSame(60, $limit->decaySeconds, 'the window must stay one minute');
    }

    public function test_the_customer_login_is_deliberately_not_reduced(): void
    {
        /*
         * Not an oversight. Every customer in the cafe shares one public IP, so
         * a 3/min per-IP limit there would lock out the whole shop as soon as
         * one person mistyped their password three times. The privileged portal
         * is the one that needs the tight limit.
         */
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->getName() === 'customer.login.post'
        );

        $this->assertNotNull($route);

        // Pass 13: customer.login.post moved from a raw throttle:10,1 to the
        // named per-IP limiter `customer-login` — same 10/min, same keying, its
        // own counter instead of one shared with the notification poll. Assert
        // the limit as a number, the way the admin-login test above does.
        $this->assertContains(
            'throttle:customer-login',
            $route->gatherMiddleware(),
            'the customer login must go through the named customer-login limiter'
        );

        $request = \Illuminate\Http\Request::create('http://127.0.0.1/customer/login', 'POST');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $limiter = app(\Illuminate\Cache\RateLimiter::class)->limiter('customer-login');
        $this->assertNotNull($limiter, 'the customer-login limiter is not registered');

        $limit = $limiter($request);
        $limit = is_array($limit) ? $limit[0] : $limit;

        $this->assertSame(10, $limit->maxAttempts, 'the customer login must stay at 10 attempts a minute');
        $this->assertSame(60, $limit->decaySeconds, 'the window must stay one minute');
    }

    public function test_the_fourth_wrong_attempt_in_a_minute_is_refused(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        for ($attempt = 1; $attempt <= self::LIMIT; $attempt++) {
            $response = $this->post(route('admin.login.post'), [
                'email' => 'nobody@invalid.local',
                'password' => 'wrong-' . $attempt,
            ]);

            $this->assertNotSame(
                429,
                $response->getStatusCode(),
                "attempt {$attempt} should still have been allowed"
            );
        }

        $this->post(route('admin.login.post'), [
            'email' => 'nobody@invalid.local',
            'password' => 'wrong-again',
        ])->assertStatus(429);
    }

    public function test_a_correct_password_still_works_once_the_window_clears(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'password' => 'Staff123!',
        ]);

        // Burn through the allowance.
        for ($attempt = 0; $attempt <= self::LIMIT; $attempt++) {
            $this->post(route('admin.login.post'), [
                'email' => $admin->email,
                'password' => 'definitely-wrong',
            ]);
        }

        $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'Staff123!',
        ])->assertStatus(429);

        // The lockout is temporary, not a permanent lockout of the account.
        RateLimiter::clear($this->throttleKey());

        $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'Staff123!',
        ])->assertRedirect(route('admin.home'));

        $this->assertAuthenticatedAs($admin, 'admin');
    }
}

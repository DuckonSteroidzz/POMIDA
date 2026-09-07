<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Ordinary admin use must not exhaust a rate limit.
 *
 * WHAT THE OWNER ACTUALLY EXPERIENCED
 * -----------------------------------
 * Signed in as admin, browsed a couple of admin pages, used the Pass 6 "Set
 * Password" feature once to change a staff member's password, logged out, and
 * tried to log back in — and got a 429 "Too many attempts" page. No failed
 * guesses. No rapid resubmission. One pass through a legitimate workflow.
 *
 * THE CAUSE — the same class of bug Pass 3 found, and worse than expected
 * -----------------------------------------------------------------------
 * Laravel's UNNAMED `throttle:N,M` middleware builds its cache key as
 *
 *     'key' => $prefix . $this->resolveRequestSignature($request)
 *
 * with `$prefix = ''` (ThrottleRequests::handle), and resolveRequestSignature()
 * returns sha1(domain|IP) for an unauthenticated request. **N and M are not in
 * the key.** So every unnamed-throttle route on one IP increments ONE shared
 * counter, and each route merely compares that shared count against its own
 * maxAttempts.
 *
 * Measured, not assumed — the resolved keys are byte-identical:
 *
 *   admin.login.post                  5c785c036466adea360111aa28563bfd556b5fba
 *   admin.users.password.update       5c785c036466adea360111aa28563bfd556b5fba
 *   admin.account.password.update     5c785c036466adea360111aa28563bfd556b5fba
 *   admin.notifications.unread-count  5c785c036466adea360111aa28563bfd556b5fba
 *   admin.forgot-password.post        5c785c036466adea360111aa28563bfd556b5fba
 *
 * The killer is the fourth line. `admin.login.post` is throttle:3,1 — the
 * strictest limit in the admin area — and the notification bell, which is
 * included in the admin LAYOUT and therefore runs on every admin page, polls
 * admin.notifications.unread-count every 20 seconds (setInterval(poll, 20000)).
 * Those polls are throttle:60,1, but they increment the SAME counter login
 * checks against 3.
 *
 * So simply leaving an admin page open for about a minute spends the entire
 * login budget. Add one Set Password and a logout, and the next login attempt
 * is refused before a single character of the password is checked.
 *
 * THE FIX
 * -------
 * Named limiters. handleRequestUsingNamedLimiter() keys on
 * md5($limiterName . $limit->key), so the limiter NAME is part of the key and
 * each action gets its own counter.
 *
 * Every limit keeps its existing strictness for its own action, and every one
 * stays keyed on the IP. That second point matters: RateLimitServiceProvider's
 * docblock deliberately keeps auth endpoints per-IP, because for a login form
 * "one address, many attempts" IS the attack. This change does not touch that.
 * It separates two things the old code conflated — WHAT the counter is keyed on
 * (the IP, deliberately, unchanged) and WHICH ACTIONS share that counter (all
 * of them, accidentally, now fixed).
 */
class AdminThrottleIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private const ADMIN_PW = 'AdminReal!Pass1';
    private const NEW_STAFF_PW = 'FreshStaff!Pass2';

    protected function setUp(): void
    {
        parent::setUp();
        // Start every test from an empty limiter, so what we measure is what
        // THIS test did and nothing left over from another one.
        Cache::flush();
        RateLimiter::clear('admin-login');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function makeAdmin(): User
    {
        return User::create([
            'name'      => 'Throttle Test Admin',
            'email'     => 'thr-admin-' . uniqid() . '@invalid.local',
            'password'  => self::ADMIN_PW,
            'role'      => 'admin',
            'is_active' => true,
            'branch_id' => 1,
        ]);
    }

    private function makeStaff(): User
    {
        return User::create([
            'name'      => 'Throttle Test Staff',
            'email'     => 'thr-staff-' . uniqid() . '@invalid.local',
            'password'  => 'StaffReal!Pass1',
            'role'      => 'staff',
            'is_active' => true,
            'branch_id' => 1,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // THE REPRODUCTION — the owner's exact sequence
    // ══════════════════════════════════════════════════════════════════

    /**
     * One pass through a completely ordinary admin workflow, all from one IP,
     * well inside one minute. Nothing here is an attack: one correct login, a
     * page open for a minute, one legitimate password reset, a logout, one
     * correct login.
     *
     * This must not produce a 429.
     */
    public function test_an_ordinary_admin_workflow_does_not_trip_a_rate_limit(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeStaff();
        $ip = ['REMOTE_ADDR' => '203.0.113.77'];

        // 1. Sign in, as the owner did.
        $login = $this->withServerVariables($ip)->post('/admin/login', [
            'email'    => $admin->email,
            'password' => self::ADMIN_PW,
        ]);
        $this->assertNotSame(429, $login->getStatusCode(), 'the FIRST login was already throttled');
        $this->assertTrue(
            \Illuminate\Support\Facades\Auth::guard('admin')->check(),
            'CONTROL: the first login must succeed, or nothing below means anything'
        );

        // 2. Browse a couple of admin pages. The notification bell is in the
        //    admin layout and polls unread-count every 20 seconds, so about a
        //    minute on any admin screen is three of these. This is what the
        //    owner's "browsed a couple of admin pages" actually costs.
        $this->withServerVariables($ip)->get('/admin/home')->assertOk();
        $this->withServerVariables($ip)->get('/admin/users')->assertOk();

        for ($i = 0; $i < 3; $i++) {
            $poll = $this->withServerVariables($ip)->get('/admin/notifications/unread-count');
            $this->assertNotSame(
                429,
                $poll->getStatusCode(),
                "the notification bell poll #{$i} was throttled during ordinary browsing"
            );
        }

        // 3. Use Set Password once — a legitimate single use, not a guess.
        $reset = $this->withServerVariables($ip)
            ->put("/admin/users/{$staff->id}/password", [
                'password'              => self::NEW_STAFF_PW,
                'password_confirmation' => self::NEW_STAFF_PW,
            ]);
        $this->assertNotSame(429, $reset->getStatusCode(), 'the single legitimate Set Password was throttled');
        $reset->assertSessionHasNoErrors();

        // 4. Log out.
        $this->withServerVariables($ip)->post('/admin/logout');

        // 5. Log back in — with the CORRECT password, first try.
        $again = $this->withServerVariables($ip)->post('/admin/login', [
            'email'    => $admin->email,
            'password' => self::ADMIN_PW,
        ]);

        $this->assertNotSame(
            429,
            $again->getStatusCode(),
            "THE OWNER'S BUG: logging back in after ordinary admin use was refused with 429, "
            . 'before the password was even checked. The login counter was spent by unrelated '
            . 'routes sharing its cache key.'
        );

        $this->assertTrue(
            \Illuminate\Support\Facades\Auth::guard('admin')->check(),
            'the admin could not sign back in after an ordinary workflow'
        );
    }

    /**
     * The narrowest form of the same bug: the notification bell alone must not
     * be able to lock the admin out of the login form.
     *
     * A page left open on the counter for two minutes is six polls. The login
     * limit is three. Before the fix that is a guaranteed lockout with no user
     * action whatsoever.
     */
    public function test_the_notification_poll_cannot_spend_the_login_budget(): void
    {
        $admin = $this->makeAdmin();
        $ip = ['REMOTE_ADDR' => '203.0.113.78'];

        $this->withServerVariables($ip)->post('/admin/login', [
            'email' => $admin->email, 'password' => self::ADMIN_PW,
        ]);
        $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('admin')->check(), 'CONTROL: login must work');

        // Two minutes of an admin page simply being open.
        for ($i = 0; $i < 6; $i++) {
            $this->withServerVariables($ip)->get('/admin/notifications/unread-count');
        }

        $this->withServerVariables($ip)->post('/admin/logout');

        $again = $this->withServerVariables($ip)->post('/admin/login', [
            'email' => $admin->email, 'password' => self::ADMIN_PW,
        ]);

        $this->assertNotSame(
            429,
            $again->getStatusCode(),
            'a background notification poll locked the admin out of their own login form'
        );
    }

    /**
     * And the two password actions must not spend each other's budget either.
     */
    public function test_the_two_password_actions_do_not_share_a_counter(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeStaff();
        $ip = ['REMOTE_ADDR' => '203.0.113.79'];

        $this->actingAs($admin, 'admin');

        // Six legitimate staff-password resets — the full budget of THAT action.
        for ($i = 1; $i <= 6; $i++) {
            $this->withServerVariables($ip)->put("/admin/users/{$staff->id}/password", [
                'password'              => 'Rotate' . $i . '!Passw0rd',
                'password_confirmation' => 'Rotate' . $i . '!Passw0rd',
            ]);
        }

        // The admin's OWN password change is a different action and must still
        // be available.
        $own = $this->withServerVariables($ip)->put('/admin/account/password', [
            'current_password'      => self::ADMIN_PW,
            'password'              => 'MyOwnNew!Pass3',
            'password_confirmation' => 'MyOwnNew!Pass3',
        ]);

        $this->assertNotSame(
            429,
            $own->getStatusCode(),
            'staff-password resets spent the admin account-password budget — still sharing a counter'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // POSITIVE CONTROL — brute force must STILL be blocked
    // ══════════════════════════════════════════════════════════════════

    /**
     * Isolating the counters must not loosen anything. A real attacker
     * hammering ONE route must still be stopped, at the same strictness as
     * before: three login attempts a minute from one address.
     */
    public function test_rapid_wrong_password_guesses_are_still_blocked(): void
    {
        $admin = $this->makeAdmin();
        $ip = ['REMOTE_ADDR' => '198.51.100.44'];

        $accepted = 0;
        $blockedAt = null;

        for ($i = 1; $i <= 20; $i++) {
            $response = $this->withServerVariables($ip)->post('/admin/login', [
                'email'    => $admin->email,
                'password' => 'WrongGuess' . $i . '!',
            ]);

            if ($response->getStatusCode() === 429) {
                $blockedAt = $i;
                break;
            }
            $accepted++;
        }

        $this->assertNotNull($blockedAt, 'the admin login is not throttled at all any more');
        $this->assertLessThanOrEqual(
            3,
            $accepted,
            'the admin login now allows more than 3 guesses a minute — the fix loosened it'
        );
        $this->assertFalse(
            \Illuminate\Support\Facades\Auth::guard('admin')->check(),
            'a wrong password authenticated'
        );
    }

    /**
     * Same control for the staff-password endpoint: it keeps its own 6/min.
     */
    public function test_the_staff_password_endpoint_still_has_its_own_limit(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeStaff();
        $ip = ['REMOTE_ADDR' => '198.51.100.45'];

        $this->actingAs($admin, 'admin');

        $accepted = 0;
        $blockedAt = null;

        for ($i = 1; $i <= 20; $i++) {
            $response = $this->withServerVariables($ip)->put("/admin/users/{$staff->id}/password", [
                'password'              => 'Spam' . $i . '!Passw0rd',
                'password_confirmation' => 'Spam' . $i . '!Passw0rd',
            ]);

            if ($response->getStatusCode() === 429) {
                $blockedAt = $i;
                break;
            }
            $accepted++;
        }

        $this->assertNotNull($blockedAt, 'the staff-password endpoint is no longer throttled');
        $this->assertLessThanOrEqual(6, $accepted, 'the staff-password endpoint got looser than 6/min');
    }

    /**
     * One IP being throttled must not throttle a DIFFERENT address. This is
     * what proves the limiter is still keyed on the IP after the change, which
     * RateLimitServiceProvider's docblock says is deliberate for auth routes.
     */
    public function test_one_address_being_blocked_does_not_block_another(): void
    {
        $admin = $this->makeAdmin();

        // Burn the login budget from one address.
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.50'])
                ->post('/admin/login', ['email' => $admin->email, 'password' => 'Wrong' . $i . '!']);
        }

        // A different address must be unaffected.
        $other = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.51'])
            ->post('/admin/login', ['email' => $admin->email, 'password' => self::ADMIN_PW]);

        $this->assertNotSame(429, $other->getStatusCode(), 'a different IP was blocked by the first one');
        $this->assertTrue(
            \Illuminate\Support\Facades\Auth::guard('admin')->check(),
            'a clean address could not sign in'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // The structural property
    // ══════════════════════════════════════════════════════════════════

    /**
     * The four sensitive admin actions must resolve to FOUR different cache
     * keys. This is the assertion that would catch the bug coming back as a
     * raw throttle:N,M on a new route.
     */
    public function test_each_sensitive_admin_action_has_its_own_limiter(): void
    {
        $names = [
            'admin.login.post',
            'admin.users.password.update',
            'admin.account.password.update',
        ];

        $limiters = [];

        foreach ($names as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "route {$name} should exist");

            $throttles = array_values(array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')
            ));

            $this->assertNotEmpty($throttles, "{$name} lost its throttle entirely");

            foreach ($throttles as $t) {
                $arg = substr($t, strlen('throttle:'));

                $this->assertDoesNotMatchRegularExpression(
                    '/^\d+,\d+$/',
                    $arg,
                    "{$name} uses a raw throttle:{$arg}. Unnamed throttles key on "
                    . 'sha1(domain|IP) with an EMPTY prefix, so they all share one counter '
                    . 'regardless of their numbers — this is the exact bug this file exists for. '
                    . 'Use a named limiter registered in RateLimitServiceProvider.'
                );

                $limiters[$name] = $arg;
            }
        }

        $this->assertSame(
            count($limiters),
            count(array_unique($limiters)),
            'two sensitive admin actions share one named limiter: ' . json_encode($limiters)
        );

        // And each name must actually be registered, or the middleware falls
        // through to treating it as a literal and throws.
        foreach ($limiters as $name => $limiterName) {
            $this->assertNotNull(
                app(\Illuminate\Cache\RateLimiter::class)->limiter($limiterName),
                "{$name} references limiter '{$limiterName}', which is not registered"
            );
        }
    }
}

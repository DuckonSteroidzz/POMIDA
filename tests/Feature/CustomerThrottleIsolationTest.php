<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The customer half of the shared-counter bug Pass 7 fixed on the admin side.
 *
 * WHAT WAS WRONG
 * --------------
 * Laravel's UNNAMED `throttle:N,M` builds its cache key as
 * `'' . resolveRequestSignature($request)` — an empty prefix plus sha1(domain|IP).
 * N and M are NOT in the key. So every raw-throttled route on one IP incremented
 * ONE shared counter, and each route only compared that shared total against its
 * own limit.
 *
 * On the customer side that meant the notification bell — polled every 6 seconds
 * from customer pages at throttle:120,1 — shared a counter with
 * `customer.login.post` (throttle:10,1) and `customer.verification.resend`
 * (throttle:3,1). On a café's single shared public IP, a few phones with the
 * menu open could spend the login or resend budget for the whole room, and a
 * first login attempt could 429 before the password was looked at.
 *
 * THE FIX (Pass 13, 2026-09-09)
 * ----------------------------
 * Every raw customer `throttle:N,M` became a NAMED per-IP limiter registered in
 * RateLimitServiceProvider. `handleRequestUsingNamedLimiter()` keys on
 * md5($limiterName . $limit->key), so the name is part of the key and each
 * logical action gets its own counter. Every limit keeps its exact previous
 * value and every one stays keyed on the IP — this isolates counters, it does
 * not loosen anything.
 *
 * NO CLEANUP NEEDED
 * ----------------
 * Nothing here writes a row: login POSTs use wrong credentials, the polled
 * endpoints are read-only, add-points/table-activity no-op without the relevant
 * session state. The class still runs inside DatabaseTransactions.
 */
class CustomerThrottleIsolationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Every customer route that used to carry a raw `throttle:N,M`, mapped to
     * the named limiter it must now use and the exact per-minute limit it must
     * keep. Re-enumerated directly from routes/web.php.
     */
    private const EXPECTED = [
        'discount-id.show'                     => ['customer-discount-lookup', 60],
        'customer.login.post'                  => ['customer-login', 10],
        'customer.register.post'               => ['customer-register', 10],
        'customer.forgot-password.post'        => ['customer-forgot-password', 6],
        'customer.verification.post'           => ['customer-verification', 10],
        'customer.verification.resend'         => ['customer-verification-resend', 3],
        'customer.new-password.post'           => ['customer-new-password', 6],
        'customer.table-activity'              => ['customer-table-clock', 120],
        'customer.table-session-status'        => ['customer-table-clock', 120],
        'customer.notifications.index'         => ['customer-notifications', 120],
        'customer.notifications.unread-count'  => ['customer-notifications', 120],
        'customer.notifications.read'          => ['customer-notifications', 120],
        'customer.add-points'                  => ['customer-add-points', 30],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        foreach ($this->limiterNames() as $name) {
            RateLimiter::clear($name);
        }
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** @return string[] */
    private function limiterNames(): array
    {
        return array_values(array_unique(array_map(fn ($e) => $e[0], self::EXPECTED)));
    }

    private function resolveLimit(string $limiterName, string $ip = '127.0.0.1')
    {
        $request = \Illuminate\Http\Request::create('http://127.0.0.1/', 'POST');
        $request->server->set('REMOTE_ADDR', $ip);

        $limiter = app(\Illuminate\Cache\RateLimiter::class)->limiter($limiterName);
        $this->assertNotNull($limiter, "limiter '{$limiterName}' is not registered");

        $limit = $limiter($request);

        return is_array($limit) ? $limit[0] : $limit;
    }

    // ══════════════════════════════════════════════════════════════════
    // The structural property — no raw throttles left, each has a counter
    // ══════════════════════════════════════════════════════════════════

    /**
     * Every converted route must go through a NAMED limiter, never a raw
     * `throttle:N,M`. This is the assertion that catches the bug coming back as
     * a raw throttle on a new customer route.
     */
    public function test_no_customer_route_uses_a_raw_throttle_any_more(): void
    {
        foreach (self::EXPECTED as $routeName => [$limiterName, $perMinute]) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "route {$routeName} should exist");

            $throttles = array_values(array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')
            ));
            $this->assertNotEmpty($throttles, "{$routeName} lost its throttle entirely");

            foreach ($throttles as $t) {
                $arg = substr($t, strlen('throttle:'));
                $this->assertDoesNotMatchRegularExpression(
                    '/^\d+,\d+$/',
                    $arg,
                    "{$routeName} still uses a raw throttle:{$arg}. Raw throttles key on "
                    . 'sha1(domain|IP) with an EMPTY prefix, so they all share one counter '
                    . 'regardless of their numbers — the exact bug this file exists for.'
                );
            }

            $this->assertContains(
                'throttle:' . $limiterName,
                $route->gatherMiddleware(),
                "{$routeName} must go through the named '{$limiterName}' limiter"
            );
        }
    }

    /**
     * Isolation must not have changed any limit: each named limiter resolves to
     * exactly the per-minute value its route carried as a raw throttle.
     */
    public function test_every_limit_is_unchanged_from_its_raw_value(): void
    {
        foreach (self::EXPECTED as $routeName => [$limiterName, $perMinute]) {
            $limit = $this->resolveLimit($limiterName);

            $this->assertSame(
                $perMinute,
                $limit->maxAttempts,
                "{$routeName} ({$limiterName}) must stay at {$perMinute} a minute"
            );
            $this->assertSame(60, $limit->decaySeconds, "{$routeName} window must stay one minute");
        }
    }

    /**
     * The distinct logical actions must resolve to distinct cache keys — a
     * shared name would mean a shared counter, which is what this fixes.
     */
    public function test_distinct_customer_limiters_have_distinct_keys(): void
    {
        $keys = [];
        foreach ($this->limiterNames() as $name) {
            $limit = $this->resolveLimit($name);
            $keys[$name] = md5($name . $limit->key);
        }

        $this->assertSame(
            count($keys),
            count(array_unique($keys)),
            'two customer limiters resolved to the same cache key: ' . json_encode($keys)
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // The behaviour — one route's limit does not spend another's
    // ══════════════════════════════════════════════════════════════════

    /**
     * THE CAFÉ BUG, customer side. Exhaust the notification poll's whole
     * 120/min budget from one IP, then a first login attempt from that same IP
     * must not be refused with 429. Before the fix the poll and the login
     * shared one counter, so background polling alone locked the login form.
     */
    public function test_exhausting_the_notification_poll_does_not_429_the_login(): void
    {
        $ip = ['REMOTE_ADDR' => '203.0.113.101'];

        // 120/min is the poll's own limit; drive it to and past the ceiling.
        $blocked = null;
        for ($i = 1; $i <= 130; $i++) {
            $poll = $this->withServerVariables($ip)->getJson('/customer/notifications/unread-count');
            if ($poll->getStatusCode() === 429) {
                $blocked = $i;
                break;
            }
        }
        $this->assertNotNull($blocked, 'the notification poll is not throttled at all');
        $this->assertGreaterThan(120, $blocked, 'the poll was refused before its own 120/min limit — it lost budget to something else');

        // Now the login form, from the SAME IP, first attempt.
        $login = $this->withServerVariables($ip)->post('/customer/login', [
            'email'    => 'nobody-throttle@invalid.local',
            'password' => 'whatever-wrong',
        ]);

        $this->assertNotSame(
            429,
            $login->getStatusCode(),
            'a first customer login was 429ed because the notification poll spent its counter'
        );
    }

    /**
     * The resend link (3/min, the tightest customer limit) must likewise be
     * independent of the notification poll.
     */
    public function test_exhausting_the_notification_poll_does_not_429_the_resend_link(): void
    {
        $ip = ['REMOTE_ADDR' => '203.0.113.102'];

        for ($i = 1; $i <= 125; $i++) {
            $this->withServerVariables($ip)->getJson('/customer/notifications/unread-count');
        }

        $resend = $this->withServerVariables($ip)->get('/customer/verification/resend');
        $this->assertNotSame(429, $resend->getStatusCode(), 'the resend link lost its budget to the notification poll');
    }

    /**
     * And login and register — both 10/min — must not spend each other's
     * budget: exhausting one leaves the other intact.
     */
    public function test_login_and_register_do_not_share_a_counter(): void
    {
        $ip = ['REMOTE_ADDR' => '203.0.113.103'];

        $blocked = null;
        for ($i = 1; $i <= 15; $i++) {
            $res = $this->withServerVariables($ip)->post('/customer/login', [
                'email'    => 'guess' . $i . '@invalid.local',
                'password' => 'wrong' . $i,
            ]);
            if ($res->getStatusCode() === 429) {
                $blocked = $i;
                break;
            }
        }
        $this->assertNotNull($blocked, 'customer login is not throttled at all any more');
        $this->assertLessThanOrEqual(11, $blocked, 'customer login got looser than 10/min');

        $register = $this->withServerVariables($ip)->post('/customer/register', []);
        $this->assertNotSame(429, $register->getStatusCode(), 'register was blocked by the login counter — still sharing');
    }

    // ══════════════════════════════════════════════════════════════════
    // Positive controls — isolation must not loosen anything
    // ══════════════════════════════════════════════════════════════════

    /** A real guesser hammering ONE route is still stopped at its own limit. */
    public function test_rapid_login_guesses_are_still_blocked_at_ten(): void
    {
        $ip = ['REMOTE_ADDR' => '198.51.100.61'];

        $accepted = 0;
        $blockedAt = null;
        for ($i = 1; $i <= 25; $i++) {
            $res = $this->withServerVariables($ip)->post('/customer/login', [
                'email'    => 'brute' . $i . '@invalid.local',
                'password' => 'wrong' . $i,
            ]);
            if ($res->getStatusCode() === 429) {
                $blockedAt = $i;
                break;
            }
            $accepted++;
        }

        $this->assertNotNull($blockedAt, 'customer login is no longer throttled');
        $this->assertLessThanOrEqual(10, $accepted, 'customer login now allows more than 10 guesses a minute');
    }

    /** One IP being blocked must not block a different address. */
    public function test_one_address_being_blocked_does_not_block_another(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.62'])
                ->post('/customer/login', ['email' => 'x' . $i . '@invalid.local', 'password' => 'w' . $i]);
        }

        $other = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.63'])
            ->post('/customer/login', ['email' => 'someone@invalid.local', 'password' => 'wrong']);

        $this->assertNotSame(429, $other->getStatusCode(), 'a different IP was blocked by the first one');
    }
}

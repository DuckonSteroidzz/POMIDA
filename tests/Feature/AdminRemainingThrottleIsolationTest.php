<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Phase 3b F2 — the raw `throttle:N,M` admin routes AdminThrottleIsolationTest
 * did not yet cover: the password-reset flow, table-code regeneration, table
 * occupancy/clear, the four admin notification-bell routes, and users.destroy.
 * Same shared-counter bug as that test documents (unnamed throttle keys on
 * sha1(domain|IP) with an EMPTY prefix — N and M are never part of the key),
 * proven live in the Phase 3a audit:
 *
 *   30x GET admin/notifications/unread-count (was throttle:120,1), then ONE
 *   POST admin/forgot-password (was throttle:6,1) -> 429, though nobody
 *   guessed a password even once.
 *
 * The structural test below is the comprehensive guard: it scans EVERY
 * registered admin route for a raw numeric throttle, so this closes the
 * finding for all twelve routes at once and catches any future regression,
 * not just the ones reproduced individually here.
 */
class AdminRemainingThrottleIsolationTest extends TestCase
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

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    // ══════════════════════════════════════════════════════════════════
    // THE STRUCTURAL PROPERTY — closes the finding for all 12 at once
    // ══════════════════════════════════════════════════════════════════

    /**
     * No route under the admin.* name prefix may carry a raw numeric
     * throttle:N,M any more. This is the assertion that would catch the bug
     * coming back on ANY admin route, not just the twelve named in the audit.
     */
    public function test_no_admin_route_uses_a_raw_numeric_throttle(): void
    {
        $offenders = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || !str_starts_with($name, 'admin.')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (!is_string($middleware) || !str_starts_with($middleware, 'throttle:')) {
                    continue;
                }

                $arg = substr($middleware, strlen('throttle:'));

                if (preg_match('/^\d+,\d+$/', $arg)) {
                    $offenders[] = "{$name} => throttle:{$arg}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these admin routes still use a raw throttle:N,M, which shares one counter "
            . "with every other raw-throttled route on the same IP:\n" . implode("\n", $offenders)
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // THE REPRODUCTION — the audit's own measured sequences
    // ══════════════════════════════════════════════════════════════════

    /**
     * The audit's exact reproduction: 30 background notification polls, then
     * a single legitimate forgot-password submission. Before the fix this was
     * a guaranteed 429 with zero password guesses.
     */
    public function test_notification_polling_does_not_spend_the_forgot_password_budget(): void
    {
        $ip = ['REMOTE_ADDR' => '203.0.113.90'];

        for ($i = 0; $i < 30; $i++) {
            $this->withServerVariables($ip)->get('/admin/notifications/unread-count');
        }

        $reset = $this->withServerVariables($ip)->post('/admin/forgot-password', [
            'email' => 'someone@example.com',
        ]);

        $this->assertNotSame(
            429,
            $reset->getStatusCode(),
            'the notification poll spent the forgot-password budget — still sharing a counter'
        );
    }

    /**
     * Same reproduction against QR-code regeneration — the audit's second
     * measured example (previously throttle:20,1, the tightest of the three
     * routes sharing the old bucket alongside the 120/min poll).
     */
    public function test_notification_polling_does_not_spend_the_qr_regenerate_budget(): void
    {
        $admin = $this->admin();
        $ip = ['REMOTE_ADDR' => '203.0.113.91'];

        $this->actingAs($admin, 'admin');

        for ($i = 0; $i < 30; $i++) {
            $this->withServerVariables($ip)->get('/admin/notifications/unread-count');
        }

        // The body does not need to be valid — a 422/404 proves the request
        // reached the controller at all, which a 429 would not.
        $regen = $this->withServerVariables($ip)->post('/admin/qr-generator/regenerate-code', []);

        $this->assertNotSame(
            429,
            $regen->getStatusCode(),
            'the notification poll spent the qr-generator.regenerate-code budget — still sharing a counter'
        );
    }

    /**
     * The audit's third measured example: 70 occupancy polls, then one
     * table-clear. Table-clear failing during ordinary use is an operational
     * blocker at a full dining room, per the finding.
     */
    public function test_occupancy_polling_does_not_spend_the_table_clear_budget(): void
    {
        $admin = $this->admin();
        $ip = ['REMOTE_ADDR' => '203.0.113.92'];

        $this->actingAs($admin, 'admin');

        for ($i = 0; $i < 70; $i++) {
            $this->withServerVariables($ip)->get('/admin/tables/occupancy');
        }

        $clear = $this->withServerVariables($ip)->post('/admin/tables/clear', []);

        $this->assertNotSame(
            429,
            $clear->getStatusCode(),
            'occupancy polling spent the tables.clear budget — still sharing a counter'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // POSITIVE CONTROL — isolating the counters must not loosen anything
    // ══════════════════════════════════════════════════════════════════

    /**
     * The tightest of the nine new limiters (3/min) must still block at
     * exactly that ceiling once isolated.
     */
    public function test_verification_resend_keeps_its_own_three_per_minute_limit(): void
    {
        $ip = ['REMOTE_ADDR' => '198.51.100.60'];

        $accepted = 0;
        $blockedAt = null;

        for ($i = 1; $i <= 10; $i++) {
            $response = $this->withServerVariables($ip)->get('/admin/verification/resend');

            if ($response->getStatusCode() === 429) {
                $blockedAt = $i;
                break;
            }
            $accepted++;
        }

        $this->assertNotNull($blockedAt, 'admin.verification.resend is not throttled at all any more');
        $this->assertLessThanOrEqual(3, $accepted, 'admin.verification.resend now allows more than 3/min');
    }

    /**
     * And a different address must be unaffected — the isolation fix must
     * not have accidentally widened the key away from per-IP.
     */
    public function test_one_address_spending_the_forgot_password_budget_does_not_block_another(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.61'])
                ->post('/admin/forgot-password', ['email' => 'a@example.com']);
        }

        $other = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.62'])
            ->post('/admin/forgot-password', ['email' => 'b@example.com']);

        $this->assertNotSame(429, $other->getStatusCode(), 'a different IP was blocked by the first one');
    }

    // ══════════════════════════════════════════════════════════════════
    // REGISTRATION — every new limiter name must actually resolve
    // ══════════════════════════════════════════════════════════════════

    public function test_every_new_named_limiter_is_registered(): void
    {
        $names = [
            'admin-forgot-password',
            'admin-verification',
            'admin-verification-resend',
            'admin-new-password',
            'admin-qr-regenerate-code',
            'admin-qr-table-service',
            'admin-tables-occupancy',
            'admin-tables-clear',
            'admin-notifications',
            'admin-users-destroy',
        ];

        foreach ($names as $name) {
            $this->assertNotNull(
                app(\Illuminate\Cache\RateLimiter::class)->limiter($name),
                "limiter '{$name}' is referenced by a route but not registered in RateLimitServiceProvider"
            );
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminBootstrap;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * First-run admin bootstrap: create the very first administrator from the web,
 * once, and never again.
 *
 * THE PROBLEM
 * -----------
 * A brand-new deployment has no admin, so nobody can sign in to create one.
 * The only route in was `php artisan db:seed --class=AdminBootstrapSeeder`,
 * which needs terminal access to the server — not something a new owner
 * installing this fresh necessarily has.
 *
 * THE RULE, AND WHY IT IS CHECKED THREE TIMES
 * -------------------------------------------
 * The path is open only while ZERO admins exist, and closes permanently once
 * one does. That is enforced at three depths, and each exists because the one
 * above it is not sufficient:
 *
 *   1. the login page hides the link          — convenience only
 *   2. the controller re-checks on GET and POST — stops a direct request, and a
 *      form that was loaded before the system was set up
 *   3. AdminBootstrap::create() re-checks inside the transaction, where a
 *      UNIQUE index on admin_bootstrap.singleton makes it atomic — stops two
 *      simultaneous requests that both passed (2)
 *
 * These tests exercise all three, and the race in particular is driven against
 * the real constraint rather than asserted from the code's shape.
 *
 * TEST DATA NOTE
 * --------------
 * This database already has a real admin, so "zero admins exist" has to be
 * simulated. Every test runs inside DatabaseTransactions and demotes the
 * existing admins for its own duration only — nothing is deleted and the
 * rollback restores them.
 */
class AdminBootstrapTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'FirstAdmin!Pass1';

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
     * Put the database into the state a brand-new installation is in: no
     * admins, and no record of a bootstrap having happened.
     *
     * Demotes rather than deletes, so foreign keys from real orders and
     * notifications stay intact. DatabaseTransactions rolls it all back.
     */
    private function simulateFreshInstall(): void
    {
        User::where('role', 'admin')->update(['role' => 'staff']);
        DB::table('admin_bootstrap')->delete();

        $this->assertSame(0, AdminBootstrap::adminCount(), 'setup: there should be no admins');
        $this->assertTrue(AdminBootstrap::isAvailable(), 'setup: bootstrap should be available');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'First Administrator',
            'email'                 => 'bootstrap-' . uniqid() . '@invalid.local',
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }

    // ══════════════════════════════════════════════════════════════════
    // The link on the login page
    // ══════════════════════════════════════════════════════════════════

    public function test_the_create_account_link_appears_only_when_no_admin_exists(): void
    {
        // With the real admin present, the link must be absent.
        $withAdmin = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            route('admin.bootstrap'),
            $withAdmin,
            'the bootstrap link is shown on a system that already has an admin'
        );

        // CONTROL: on a fresh install it appears. Without this the assertion
        // above would pass just as happily if the link never rendered at all.
        $this->simulateFreshInstall();

        $fresh = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString(
            route('admin.bootstrap'),
            $fresh,
            'the bootstrap link is missing on an installation with no admin'
        );
        $this->assertStringContainsString('no administrator yet', $fresh);
    }

    // ══════════════════════════════════════════════════════════════════
    // The happy path, and the door closing behind it
    // ══════════════════════════════════════════════════════════════════

    /**
     * The whole feature in one test: create the first admin, actually sign in
     * with the credentials just set, then confirm the same submission is
     * refused now that an admin exists.
     */
    public function test_the_first_admin_is_created_can_log_in_and_then_the_path_closes(): void
    {
        $this->simulateFreshInstall();

        $payload = $this->payload();

        $this->get('/admin/bootstrap')->assertOk();

        $response = $this->post('/admin/bootstrap', $payload);

        $response->assertRedirect(route('admin.login'));
        $response->assertSessionHasNoErrors();

        // The account exists, with the right role and no branch.
        $admin = User::where('email', $payload['email'])->first();

        $this->assertNotNull($admin, 'no account was created');
        $this->assertSame('admin', $admin->role, 'the account is not an admin');
        $this->assertTrue((bool) $admin->is_active);
        $this->assertNull($admin->branch_id, 'an admin should not be branch-scoped');

        // The password is hashed once — not stored plain, not double-hashed.
        $this->assertStringNotContainsString(self::PASSWORD, (string) $admin->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password), 'the password does not verify');

        // THE ROUND TRIP: it can actually sign in through the real login form.
        $this->post('/admin/login', [
            'email'    => $payload['email'],
            'password' => self::PASSWORD,
        ]);

        $this->assertTrue(
            Auth::guard('admin')->check(),
            'the account created by bootstrap cannot log in'
        );
        $this->assertSame($admin->id, Auth::guard('admin')->id());

        // ── And now the path is closed. Same submission, immediately after. ──
        Auth::guard('admin')->logout();
        Cache::flush();

        $adminsBefore = AdminBootstrap::adminCount();
        $second = $this->post('/admin/bootstrap', $this->payload());

        $second->assertRedirect(route('admin.login'));
        $second->assertSessionHasErrors('email');

        $this->assertSame(
            $adminsBefore,
            AdminBootstrap::adminCount(),
            'a second admin was created through a path that should be closed'
        );

        // The GET is closed too, not just the POST.
        $this->get('/admin/bootstrap')->assertRedirect(route('admin.login'));

        // And the link is gone from the login page.
        $this->assertStringNotContainsString(
            route('admin.bootstrap'),
            $this->get('/admin/login')->getContent()
        );
    }

    /**
     * A direct request with no link ever rendered must be refused on an
     * already-configured system. Hiding the link is not the control.
     */
    public function test_a_direct_request_is_refused_when_an_admin_already_exists(): void
    {
        $this->assertGreaterThan(0, AdminBootstrap::adminCount(), 'this database should have an admin');

        $before = AdminBootstrap::adminCount();

        $this->get('/admin/bootstrap')->assertRedirect(route('admin.login'));

        $this->post('/admin/bootstrap', $this->payload())
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('email');

        $this->assertSame($before, AdminBootstrap::adminCount(), 'an admin was created anyway');

        // CONTROL: the very same request works on a fresh install, so the
        // refusal above is the guard and not a broken endpoint.
        $this->simulateFreshInstall();
        $payload = $this->payload();

        $this->post('/admin/bootstrap', $payload)->assertSessionHasNoErrors();
        $this->assertNotNull(User::where('email', $payload['email'])->first(), 'CONTROL FAILED');
    }

    /**
     * Deleting every admin must NOT reopen the path — otherwise an attacker who
     * could remove admins would get a free account. The admin_bootstrap row is
     * what keeps it shut.
     */
    public function test_removing_every_admin_does_not_reopen_the_path(): void
    {
        $this->simulateFreshInstall();

        $this->post('/admin/bootstrap', $this->payload())->assertSessionHasNoErrors();
        $this->assertTrue(AdminBootstrap::hasCompleted(), 'the bootstrap record was not written');

        // Now every admin disappears.
        User::where('role', 'admin')->update(['role' => 'staff']);
        $this->assertSame(0, AdminBootstrap::adminCount(), 'setup: no admins should remain');

        $this->assertFalse(
            AdminBootstrap::isAvailable(),
            'the bootstrap path reopened after the admins were removed'
        );

        $this->post('/admin/bootstrap', $this->payload())
            ->assertSessionHasErrors('email');

        $this->assertSame(0, AdminBootstrap::adminCount(), 'a new admin was minted after removal');
    }

    // ══════════════════════════════════════════════════════════════════
    // The race
    // ══════════════════════════════════════════════════════════════════

    /**
     * Two attempts that BOTH pass the "zero admins" check must not both create
     * an admin.
     *
     * A genuine parallel-process race cannot be run inside PHPUnit, so this
     * drives the exact interleaving that a race produces: attempt A reads
     * "zero admins", attempt B completes entirely, and then A proceeds to
     * write. If the only guard were the PHP check, A would now create a second
     * admin. It is instead stopped by the unique index inside its transaction.
     */
    public function test_two_simultaneous_attempts_create_exactly_one_admin(): void
    {
        $this->simulateFreshInstall();

        $attemptA = $this->payload();
        $attemptB = $this->payload();

        // Attempt A checks availability and is about to act on it.
        $this->assertTrue(AdminBootstrap::isAvailable(), 'A saw an open path');

        // Attempt B lands first and completes.
        $this->assertNotNull(AdminBootstrap::create($attemptB), 'B should succeed');

        // A now acts on the stale answer it already has.
        $resultA = AdminBootstrap::create($attemptA);

        $this->assertNull($resultA, 'the losing attempt created an admin as well');
        $this->assertSame(1, AdminBootstrap::adminCount(), 'exactly one admin should exist');
        $this->assertNull(
            User::where('email', $attemptA['email'])->first(),
            'the losing attempt left a partial user row behind'
        );
        $this->assertNotNull(User::where('email', $attemptB['email'])->first(), 'the winner is missing');
    }

    /**
     * The database, not PHP, is what makes the race safe. Proved by attacking
     * the constraint directly: the guard row cannot be written twice.
     */
    public function test_the_bootstrap_record_cannot_be_written_twice(): void
    {
        $this->simulateFreshInstall();

        $this->assertNotNull(AdminBootstrap::create($this->payload()), 'the first bootstrap should succeed');
        $this->assertSame(1, DB::table('admin_bootstrap')->count());

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('admin_bootstrap')->insert([
            'singleton'  => 'X',
            'user_id'    => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_bootstrap_time_is_recorded(): void
    {
        $this->simulateFreshInstall();

        $this->assertNull(AdminBootstrap::completedAt(), 'nothing should be recorded yet');

        $admin = AdminBootstrap::create($this->payload());

        $this->assertNotNull(AdminBootstrap::completedAt(), 'the bootstrap time was not recorded');
        $this->assertSame(
            $admin->id,
            (int) DB::table('admin_bootstrap')->value('user_id'),
            'the record does not name the account it created'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // Validation — the same rules as everywhere else
    // ══════════════════════════════════════════════════════════════════

    /**
     * @dataProvider badPayloads
     */
    public function test_weak_or_invalid_input_is_refused(array $overrides, string $field): void
    {
        $this->simulateFreshInstall();

        $this->post('/admin/bootstrap', $this->payload($overrides))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, AdminBootstrap::adminCount(), 'an admin was created from invalid input');
        $this->assertFalse(AdminBootstrap::hasCompleted(), 'the path was closed by a failed attempt');
    }

    public static function badPayloads(): array
    {
        return [
            'no name'            => [['name' => ''], 'name'],
            'bad email'          => [['email' => 'not-an-email'], 'email'],
            'short password'     => [['password' => 'Ab!1', 'password_confirmation' => 'Ab!1'], 'password'],
            'no symbol'          => [['password' => 'Password123', 'password_confirmation' => 'Password123'], 'password'],
            'no number'          => [['password' => 'Password!!!', 'password_confirmation' => 'Password!!!'], 'password'],
            'no uppercase'       => [['password' => 'password!1', 'password_confirmation' => 'password!1'], 'password'],
            'mismatched confirm' => [['password_confirmation' => 'Different!Pass2'], 'password'],
        ];
    }

    /**
     * A failed attempt must not consume the one-shot. Otherwise a typo would
     * lock the owner out of their own brand-new installation forever.
     */
    public function test_a_failed_attempt_does_not_burn_the_one_shot(): void
    {
        $this->simulateFreshInstall();

        $this->post('/admin/bootstrap', $this->payload(['password' => 'weak', 'password_confirmation' => 'weak']))
            ->assertSessionHasErrors('password');

        $this->assertTrue(AdminBootstrap::isAvailable(), 'a rejected attempt closed the path');

        // And a correct one still works afterwards.
        $good = $this->payload();
        $this->post('/admin/bootstrap', $good)->assertSessionHasNoErrors();

        $this->assertNotNull(User::where('email', $good['email'])->first());
    }

    /** The email must be unique against existing users, admin or not. */
    public function test_an_existing_email_is_refused(): void
    {
        $existing = User::orderBy('id')->firstOrFail();
        $this->simulateFreshInstall();

        $this->post('/admin/bootstrap', $this->payload(['email' => $existing->email]))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, AdminBootstrap::adminCount());
    }

    /**
     * The password policy used here must be the SAME object every other
     * account-creating path uses, not a local copy that could drift.
     */
    public function test_the_rules_reuse_the_shared_password_policy(): void
    {
        $rules = AdminBootstrap::rules();

        $this->assertArrayHasKey('password', $rules);
        $this->assertEquals(
            \App\Support\PasswordPolicy::required(),
            $rules['password'],
            'the bootstrap form uses different password rules from the rest of the app'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // Rate limiting — same isolation rigour as AdminThrottleIsolationTest
    // ══════════════════════════════════════════════════════════════════

    /**
     * The endpoint must have its OWN named limiter. A raw throttle:N,M would
     * share one counter with every other raw-throttled route on the IP — the
     * bug Pass 7 fixed — which here would mean an unrelated page view could
     * lock the owner out of setting up their system.
     */
    public function test_the_endpoint_has_its_own_isolated_named_limiter(): void
    {
        foreach (['admin.bootstrap', 'admin.bootstrap.store'] as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "route {$name} should exist");

            $throttles = array_values(array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')
            ));

            $this->assertNotEmpty($throttles, "{$name} is not rate limited at all");

            foreach ($throttles as $t) {
                $arg = substr($t, strlen('throttle:'));

                $this->assertDoesNotMatchRegularExpression(
                    '/^\d+,\d+$/',
                    $arg,
                    "{$name} uses a raw throttle:{$arg}, which shares one counter per IP with "
                    . 'every other raw-throttled route'
                );
                $this->assertSame('admin-bootstrap', $arg);
            }
        }

        // The limiter is registered and keyed on the IP.
        $limiter = app(\Illuminate\Cache\RateLimiter::class)->limiter('admin-bootstrap');
        $this->assertNotNull($limiter, 'the admin-bootstrap limiter is not registered');

        $request = \Illuminate\Http\Request::create('http://127.0.0.1/admin/bootstrap', 'POST');
        $request->server->set('REMOTE_ADDR', '203.0.113.9');

        $limit = $limiter($request);
        $limit = is_array($limit) ? $limit[0] : $limit;

        $this->assertSame(5, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);

        // Its key must differ from every other admin limiter's.
        $keys = [];
        foreach (['admin-bootstrap', 'admin-login', 'admin-account-password', 'admin-staff-password'] as $name) {
            $l = app(\Illuminate\Cache\RateLimiter::class)->limiter($name)($request);
            $l = is_array($l) ? $l[0] : $l;
            $keys[$name] = md5($name . $l->key);
        }

        $this->assertSame(
            count($keys),
            count(array_unique($keys)),
            'admin-bootstrap shares a counter with another admin limiter: ' . json_encode($keys)
        );
    }

    /**
     * And it actually refuses. Driven through the real endpoint, with a
     * control proving the first request was allowed.
     */
    public function test_the_endpoint_is_actually_throttled(): void
    {
        $this->simulateFreshInstall();
        $ip = ['REMOTE_ADDR' => '198.51.100.77'];

        $accepted = 0;
        $blocked = false;

        for ($i = 1; $i <= 12; $i++) {
            // Deliberately invalid, so nothing is created and the path stays
            // open — this measures the limiter, not the one-shot.
            $response = $this->withServerVariables($ip)
                ->post('/admin/bootstrap', $this->payload(['email' => 'not-an-email']));

            if ($response->getStatusCode() === 429) {
                $blocked = true;
                break;
            }
            $accepted++;
        }

        $this->assertGreaterThan(0, $accepted, 'CONTROL: the first request should have been allowed');
        $this->assertTrue($blocked, 'the bootstrap endpoint is not throttled at all');
        $this->assertLessThanOrEqual(5, $accepted, 'more than 5 attempts a minute were allowed');

        // A different address is unaffected — proving it is keyed on the IP.
        $other = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.78'])
            ->get('/admin/bootstrap');

        $this->assertNotSame(429, $other->getStatusCode(), 'a different IP was blocked by the first one');
    }
}

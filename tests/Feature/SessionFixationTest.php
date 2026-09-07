<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Session fixation: does the session identifier change when a visitor
 * authenticates?
 *
 * WHY IT MATTERS HERE
 * -------------------
 * If the id survives a login, anyone who can plant a known session id in a
 * victim's browser is signed in as that victim the moment the victim
 * authenticates. In a café this is not theoretical — the staff terminal sits
 * on a counter and customers use their own phones on shared wifi.
 *
 * WHAT IS COVERED
 * ---------------
 * All three authentication entry points and both logouts. Source locations,
 * confirmed by reading rather than assumed:
 *
 *   customer login        AuthController::login       session()->regenerate()      (~line 970)
 *   customer registration AuthController::register    session()->regenerate()      (~line 886)
 *   admin / staff login   AdminAuthController::login  session()->regenerate()      (~line 105)
 *   customer logout       AuthController::logout      invalidate() + regenerateToken()
 *   admin logout          AdminAuthController::logout invalidate() + regenerateToken()
 *
 * Registration was *believed* to regenerate already. It does — and it is
 * asserted below rather than taken on trust.
 *
 * ══════════════════════════════════════════════════════════════════════
 * HOW THIS TEST OBSERVES THE SESSION ID, AND WHY THE OBVIOUS WAY IS WRONG
 * ══════════════════════════════════════════════════════════════════════
 *
 * The obvious approach is to call session()->getId() before and after a login
 * and assert it changed. In this suite that assertion is worthless, and the
 * first version of this file was green because of it.
 *
 * phpunit.xml sets SESSION_DRIVER=array. With the array driver the test
 * harness begins a BRAND NEW session on every request, so the id always
 * differs — whether or not the application regenerated anything. All six
 * transition tests passed against that, and would have passed just as happily
 * against an application that never called regenerate() at all. The negative
 * control below is what exposed it: it asserted the id was STABLE across an
 * ordinary GET, and failed.
 *
 * So these tests drive the session the way a browser does instead:
 *
 *   - the session driver is switched to `database` for this class, which is
 *     also what production uses, so a session genuinely persists between
 *     requests (and DatabaseTransactions rolls those rows back);
 *   - the session cookie is read out of each response, decrypted, and sent
 *     back on the next request with withCookie() — which re-encrypts it, so
 *     encryption stays on in both directions exactly as in production.
 *
 * With that, an ordinary request keeps its session id and a login changes it,
 * which is the distinction the whole file depends on.
 */
class SessionFixationTest extends TestCase
{
    use DatabaseTransactions;

    private string $cookieName;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // See the class docblock: the array driver cannot carry a session
        // across requests, which makes every assertion here vacuous.
        config(['session.driver' => 'database']);

        $this->cookieName = (string) config('session.cookie');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)
            ->orderBy('id')->firstOrFail();
    }

    private function adminOrStaff(): ?User
    {
        return User::whereIn('role', ['admin', 'staff'])->where('is_active', true)
            ->orderBy('id')->first();
    }

    /**
     * Give an account a password this test knows. DatabaseTransactions rolls
     * this back, so no real credential is changed.
     */
    private function withKnownPassword(User $user, string $password = 'Str0ng!Passw0rd'): User
    {
        $user->forceFill(['password' => $password])->save();

        return $user->fresh();
    }

    /**
     * The session id carried by a response, read the way a browser would: out
     * of the Set-Cookie header, decrypted.
     */
    private function sessionIdFrom($response): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() !== $this->cookieName) {
                continue;
            }

            $raw = (string) $cookie->getValue();

            if ($raw === '') {
                return null;
            }

            return CookieValuePrefix::remove(decrypt($raw, false));
        }

        return null;
    }

    /** Establish a session and return its id, as a browser would arrive with. */
    private function startSessionAndGetId(string $url = '/customer/login'): string
    {
        $id = $this->sessionIdFrom($this->get($url));

        $this->assertNotNull($id, 'no session cookie was issued, so nothing can be compared');
        $this->assertNotSame('', $id);

        return $id;
    }

    // ══════════════════════════════════════════════════════════════════
    // The negative control
    // ══════════════════════════════════════════════════════════════════

    /**
     * An ordinary, non-authenticating request must NOT change the session id.
     *
     * This is the test that makes the rest of the file mean anything. If the
     * id changed on every request — which is exactly what the array session
     * driver does in this harness — then "the id changed on login" would be
     * true for an application with no fixation protection whatsoever.
     */
    public function test_the_session_id_is_stable_across_an_ordinary_request(): void
    {
        $first = $this->startSessionAndGetId();

        $second = $this->sessionIdFrom(
            $this->withCookie($this->cookieName, $first)->get('/customer/login')
        );

        $this->assertSame(
            $first,
            $second,
            'the session id changed on an ordinary GET — every "it regenerated" assertion '
            . 'in this file would then be passing for the wrong reason'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // Login
    // ══════════════════════════════════════════════════════════════════

    public function test_the_session_id_changes_on_customer_login(): void
    {
        $customer = $this->withKnownPassword($this->customer());

        $before = $this->startSessionAndGetId();

        $response = $this->withCookie($this->cookieName, $before)
            ->post('/customer/login', [
                'email'    => $customer->email,
                'password' => 'Str0ng!Passw0rd',
            ]);

        // CONTROL: the login must actually have succeeded, or "the id changed"
        // could just mean the request blew up.
        $this->assertTrue(
            Auth::guard('customer')->check(),
            'the login did not succeed, so this proves nothing about fixation. Errors: '
            . json_encode(session('errors')?->all() ?? [])
        );

        $this->assertNotSame(
            $before,
            $this->sessionIdFrom($response),
            'SESSION FIXATION: the session id survived a customer login'
        );
    }

    public function test_the_session_id_changes_on_customer_registration(): void
    {
        $before = $this->startSessionAndGetId('/customer/register');
        $email = 'fixation-reg-' . uniqid() . '@invalid.local';

        $response = $this->withCookie($this->cookieName, $before)
            ->post('/customer/register', [
                'name'                  => 'Fixation Probe',
                'email'                 => $email,
                'password'              => 'Str0ng!Passw0rd',
                'password_confirmation' => 'Str0ng!Passw0rd',
                'contact_number'        => '09171234567',
                'terms'                 => 'on',
            ]);

        // CONTROL.
        $this->assertNotNull(
            User::where('email', $email)->first(),
            'registration did not succeed, so this proves nothing. Errors: '
            . json_encode(session('errors')?->all() ?? [])
        );
        $this->assertTrue(Auth::guard('customer')->check(), 'registration should sign the customer in');

        $this->assertNotSame(
            $before,
            $this->sessionIdFrom($response),
            'SESSION FIXATION: the session id survived registration'
        );
    }

    public function test_the_session_id_changes_on_admin_login(): void
    {
        $staff = $this->adminOrStaff();
        if (! $staff) {
            $this->markTestSkipped('no active admin/staff account in this database');
        }

        $staff = $this->withKnownPassword($staff);

        $before = $this->startSessionAndGetId('/admin/login');

        $response = $this->withCookie($this->cookieName, $before)
            ->post('/admin/login', [
                'email'    => $staff->email,
                'password' => 'Str0ng!Passw0rd',
            ]);

        // CONTROL.
        $this->assertTrue(
            Auth::guard('admin')->check(),
            'the admin login did not succeed, so this proves nothing. Errors: '
            . json_encode(session('errors')?->all() ?? [])
        );

        $this->assertNotSame(
            $before,
            $this->sessionIdFrom($response),
            'SESSION FIXATION: the session id survived an admin/staff login'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // Logout
    // ══════════════════════════════════════════════════════════════════

    /**
     * A session id captured before logout must be dead afterwards, and the
     * CSRF token reissued so one harvested from the old session cannot be
     * replayed.
     */
    public function test_customer_logout_invalidates_the_session_and_rotates_the_csrf_token(): void
    {
        $customer = $this->withKnownPassword($this->customer());

        $start = $this->startSessionAndGetId();
        $loginResponse = $this->withCookie($this->cookieName, $start)
            ->post('/customer/login', [
                'email'    => $customer->email,
                'password' => 'Str0ng!Passw0rd',
            ]);

        // CONTROL: we must be signed in before logging out means anything.
        $this->assertTrue(Auth::guard('customer')->check(), 'login must succeed first');

        $signedInId = $this->sessionIdFrom($loginResponse);
        $tokenBefore = (string) session()->token();

        $logoutResponse = $this->withCookie($this->cookieName, $signedInId)->post('/customer/logout');

        $this->assertFalse(Auth::guard('customer')->check(), 'logout should end the session');
        $this->assertNotSame(
            $signedInId,
            $this->sessionIdFrom($logoutResponse),
            'the session id survived logout — a captured id would still be usable'
        );
        $this->assertNotSame($tokenBefore, (string) session()->token(), 'the CSRF token survived logout');
    }

    public function test_admin_logout_invalidates_the_session_and_rotates_the_csrf_token(): void
    {
        $staff = $this->adminOrStaff();
        if (! $staff) {
            $this->markTestSkipped('no active admin/staff account in this database');
        }

        $staff = $this->withKnownPassword($staff);

        $start = $this->startSessionAndGetId('/admin/login');
        $loginResponse = $this->withCookie($this->cookieName, $start)
            ->post('/admin/login', [
                'email'    => $staff->email,
                'password' => 'Str0ng!Passw0rd',
            ]);

        $this->assertTrue(Auth::guard('admin')->check(), 'admin login must succeed first');

        $signedInId = $this->sessionIdFrom($loginResponse);
        $tokenBefore = (string) session()->token();

        $logoutResponse = $this->withCookie($this->cookieName, $signedInId)->post('/admin/logout');

        $this->assertFalse(Auth::guard('admin')->check(), 'logout should end the admin session');
        $this->assertNotSame(
            $signedInId,
            $this->sessionIdFrom($logoutResponse),
            'the admin session id survived logout'
        );
        $this->assertNotSame($tokenBefore, (string) session()->token(), 'the admin CSRF token survived logout');
    }

    // ══════════════════════════════════════════════════════════════════
    // Cookie configuration
    // ══════════════════════════════════════════════════════════════════

    /**
     * The two cookie flags that do not depend on the deployment environment.
     *
     * `secure` is deliberately NOT asserted: it is unset locally because there
     * is no certificate, and true in production via SESSION_SECURE_COOKIE.
     * `php artisan deploy:check` enforces it on the live server (Pass 3), which
     * is the right place for an environment-dependent setting.
     *
     * SESSION_LIFETIME is also not asserted — the team has an open decision on
     * the idle timeout for the counter terminal.
     */
    public function test_the_session_cookie_flags_are_set_correctly(): void
    {
        $this->assertTrue(
            (bool) config('session.http_only'),
            'the session cookie must be HttpOnly so injected JavaScript cannot read it'
        );

        $this->assertSame(
            'lax',
            strtolower((string) config('session.same_site')),
            'SameSite should be lax: it stops a cross-site form POST carrying the session '
            . 'cookie, a second layer underneath the CSRF token'
        );
    }
}

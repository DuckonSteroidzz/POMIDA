<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Changing your own password must ALSO change your session id.
 *
 * WHY, CONCRETELY
 * ---------------
 * Changing a password is what a person does when they believe someone else has
 * their account. Rotating `remember_token` (which both portals already did) ends
 * every OTHER session for that account through EnforceSingleSession — but it
 * cannot help when the attacker holds a COPY OF THIS SESSION'S OWN COOKIE,
 * because that copy is not another session, it is the same one. Whatever new
 * single-session fingerprint gets written, the copy presents the same session id
 * and is fingerprinted along with the victim.
 *
 * `session()->regenerate()` is what separates them: the victim's browser moves
 * to a NEW id, and the attacker's copy is left holding the old one, which
 * EnforceSingleSession then refuses.
 *
 * WHAT WAS FOUND
 * --------------
 * The CUSTOMER path already did this — AuthController::updateAccount() calls
 * regenerate() then SingleSession::claim(), with a comment spelling out this
 * exact reasoning.
 *
 * The ADMIN/owner path did NOT. AdminController::updateOwnPassword() called
 * claim() without regenerate(), so an Owner who changed their password because
 * they suspected their session had been copied kept the very session id the
 * attacker held. Reproduced by the admin test below failing before the fix
 * while the customer test beside it passed — which is what shows the test is
 * measuring the application and not the harness.
 *
 * HOW THE SESSION IS OBSERVED
 * ---------------------------
 * Exactly as SessionFixationTest does it, and for the same reason: phpunit.xml
 * sets SESSION_DRIVER=array, under which the harness starts a brand-new session
 * on every request, so "the id changed" is trivially true and proves nothing.
 * This class switches to the `database` driver (what production uses) and
 * replays the session cookie by hand, so an ordinary request keeps its id.
 * test_the_session_id_is_stable_across_an_ordinary_admin_request() is the
 * negative control that keeps the rest honest.
 */
class PasswordChangeSessionRotationTest extends TestCase
{
    use DatabaseTransactions;

    private const OLD_PASSWORD = 'Str0ng!OldPass1';
    private const NEW_PASSWORD = 'Str0ng!NewPass2';

    private string $cookieName;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->assertSame(
            'pomida_db_testing',
            DB::selectOne('select database() as d')->d,
            'Session rotation tests must only ever run against pomida_db_testing.'
        );

        // See the class docblock — the array driver makes every assertion vacuous.
        config(['session.driver' => 'database']);

        $this->cookieName = (string) config('session.cookie');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function sessionIdFrom($response): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() !== $this->cookieName) {
                continue;
            }

            $raw = (string) $cookie->getValue();

            return $raw === '' ? null : CookieValuePrefix::remove(decrypt($raw, false));
        }

        return null;
    }

    /** A throwaway account of the given role with a password this test knows. */
    private function account(string $role): User
    {
        return User::create([
            'name'              => 'Rot ' . $role . ' ' . uniqid(),
            'email'             => 'rot_' . $role . '_' . uniqid() . '@example.test',
            'password'          => self::OLD_PASSWORD,
            'role'              => $role,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    // ══════════ Negative control ══════════

    /**
     * An ordinary authenticated admin GET must NOT change the session id.
     *
     * Without this, "the id changed after the password change" would also be
     * true of an application that rotated on every single request.
     */
    public function test_the_session_id_is_stable_across_an_ordinary_admin_request(): void
    {
        $admin = $this->account('admin');

        $first = $this->sessionIdFrom(
            $this->post('/admin/login', [
                'email'    => $admin->email,
                'password' => self::OLD_PASSWORD,
            ])
        );

        $this->assertNotNull($first, 'admin login issued no session cookie');
        $this->assertTrue(Auth::guard('admin')->check(), 'the admin login did not succeed');

        $second = $this->sessionIdFrom(
            $this->withCookie($this->cookieName, $first)->get('/admin/account')
        );

        // A response that does not re-issue the cookie has not changed the id.
        if ($second !== null) {
            $this->assertSame(
                $first,
                $second,
                'the session id changed on an ordinary GET, so every rotation assertion here would pass for the wrong reason'
            );
        }
    }

    // ══════════ The finding ══════════

    /**
     * THE ADMIN GAP. Before the fix this failed: the id was unchanged.
     */
    public function test_an_admin_changing_their_own_password_gets_a_new_session_id(): void
    {
        $admin = $this->account('admin');

        $before = $this->sessionIdFrom(
            $this->post('/admin/login', [
                'email'    => $admin->email,
                'password' => self::OLD_PASSWORD,
            ])
        );

        $this->assertNotNull($before);
        $this->assertTrue(Auth::guard('admin')->check(), 'the admin login did not succeed');

        $response = $this->withCookie($this->cookieName, $before)
            ->put('/admin/account/password', [
                'current_password'      => self::OLD_PASSWORD,
                'password'              => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        // CONTROL: the change must really have happened, or a mere validation
        // failure would look like "no rotation" for the wrong reason.
        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check(self::NEW_PASSWORD, $admin->fresh()->password),
            'the password did not actually change, so this proves nothing about rotation. Errors: '
            . json_encode(session('errors')?->all() ?? [])
        );

        $after = $this->sessionIdFrom($response);

        $this->assertNotNull($after, 'no new session cookie was issued by the password change');
        $this->assertNotSame(
            $before,
            $after,
            'the admin session id survived a password change — a copied session cookie would still be signed in'
        );
    }

    /**
     * The customer path, which already behaved correctly.
     *
     * Kept beside the admin test deliberately: it is the comparison that showed
     * the admin failure was a real application difference, not a harness
     * artefact.
     */
    public function test_a_customer_changing_their_own_password_gets_a_new_session_id(): void
    {
        $customer = $this->account('customer');

        $before = $this->sessionIdFrom(
            $this->post('/customer/login', [
                'email'    => $customer->email,
                'password' => self::OLD_PASSWORD,
            ])
        );

        $this->assertNotNull($before);
        $this->assertTrue(Auth::guard('customer')->check(), 'the customer login did not succeed');

        $response = $this->withCookie($this->cookieName, $before)
            ->put('/customer/account', [
                'name'                  => $customer->name,
                'email'                 => $customer->email,
                'contact_number'        => '09170000000',
                'current_password'      => self::OLD_PASSWORD,
                'password'              => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check(self::NEW_PASSWORD, $customer->fresh()->password),
            'the password did not actually change. Errors: ' . json_encode(session('errors')?->all() ?? [])
        );

        $after = $this->sessionIdFrom($response);

        $this->assertNotNull($after, 'no new session cookie was issued by the password change');
        $this->assertNotSame($before, $after, 'the customer session id survived a password change');
    }

    /**
     * The point of rotating: the OLD cookie must no longer be signed in.
     *
     * This is the property that actually protects the owner — a new id is only
     * useful if the id the attacker holds stops working.
     */
    public function test_the_old_admin_session_cookie_is_signed_out_after_the_password_change(): void
    {
        $admin = $this->account('admin');

        $before = $this->sessionIdFrom(
            $this->post('/admin/login', [
                'email'    => $admin->email,
                'password' => self::OLD_PASSWORD,
            ])
        );

        $this->assertNotNull($before);

        $this->withCookie($this->cookieName, $before)
            ->put('/admin/account/password', [
                'current_password'      => self::OLD_PASSWORD,
                'password'              => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check(self::NEW_PASSWORD, $admin->fresh()->password),
            'the password did not actually change, so this proves nothing'
        );

        /*
         * The attacker's copy: the OLD session id, replayed.
         *
         * Deliberately NOT preceded by flushSession()/logout() — those destroy
         * the session server-side, after which replaying the id is refused
         * whether or not the application rotated anything, and this test passes
         * vacuously. (It did, on its first run.) The guard is reset instead, so
         * the only thing deciding the outcome is what the application wrote.
         */
        Auth::clearResolvedInstances();

        $replay = $this->withCookie($this->cookieName, $before)->get('/admin/home');

        $replay->assertRedirect();
        $this->assertStringContainsString(
            'login',
            (string) $replay->headers->get('Location'),
            'the old session id was still accepted after the password change'
        );
    }
}

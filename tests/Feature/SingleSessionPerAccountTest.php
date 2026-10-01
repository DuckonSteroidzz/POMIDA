<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use App\Support\SingleSession;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One active session per account, for all four roles, and closing the browser
 * ends the login.
 *
 * THE REPORTS (team testing, Sept 2026, phone + a second PC)
 * ----------------------------------------------------------
 * 1. The same account could be signed in on two devices at once, for
 *    customer, staff, supervisor and admin alike.
 * 2. After Chrome was fully closed and reopened, the admin was still signed in.
 *
 * HOW A "DEVICE" IS SIMULATED, AND WHY IT NEEDS CARE
 * --------------------------------------------------
 * Every request here carries its own session cookie, read back from the
 * previous response exactly as a browser would, so device A and device B
 * really are two sessions. Two harness traps were measured before this file
 * was written. Both make a multi-device test pass for the wrong reason:
 *
 *   - The test app reuses one session Store across requests, and Store::start()
 *     array_replace()s the new session's data over whatever the LAST request
 *     left in memory. Without a flush, device A's login leaks into device B.
 *   - Resolved guards cache their user across requests, where a real PHP
 *     process would start empty.
 *
 * on() resets both before every request, and points the store at the
 * device's own id so a withSession() cart lands in THAT device's session.
 *
 * The array session driver (phpunit.xml) keeps each id's data separately,
 * which was verified to isolate devices correctly. Every row is rolled back by
 * DatabaseTransactions; the users all carry an `ss-` @invalid.local email so
 * a leak would be easy to find.
 */
class SingleSessionPerAccountTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'SingleSess!1';

    private string $cookieName;

    /** device name => that device's current session id */
    private array $jar = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
        $this->cookieName = (string) config('session.cookie');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ══════════ harness ══════════

    private function makeUser(string $role): User
    {
        return User::create([
            'name'      => 'Single Session ' . ucfirst($role),
            'email'     => 'ss-' . $role . '-' . Str::lower(Str::random(12)) . '@invalid.local',
            'password'  => self::PASSWORD,
            'role'      => $role,
            'is_active' => true,
            'branch_id' => $role === 'customer' ? null : 1,
            // A customer who can log in has confirmed their email (Oct 2026
            // gate). Portal fixtures are left exactly as they were.
            'email_verified_at' => $role === 'customer' ? now() : null,
        ]);
    }

    /**
     * Send one request from $device, as a fresh PHP process would see it.
     * $extraCookies are added on top of the device's session cookie.
     */
    private function on(string $device, callable $request, array $extraCookies = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $id = $this->jar[$device] ??= Str::random(40);

        $store = $this->app['session']->driver();
        $store->flush();
        $store->setId($id);

        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->flushHeaders();
        // getJson()/postJson() send NO cookies unless asked to. Without this a
        // "background poll from device A" arrives as a brand-new visitor and
        // every assertion about A passes vacuously.
        $this->withCredentials();
        $this->withCookie($this->cookieName, $id);

        foreach ($extraCookies as $name => $value) {
            $this->withCookie($name, $value);
        }

        $response = $request();

        $next = $this->sessionIdFrom($response);
        if ($next !== null) {
            $this->jar[$device] = $next;
        }

        return $response;
    }

    /** The session id a response hands the browser, decrypted as the browser's next request will send it. */
    private function sessionIdFrom(TestResponse $response): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $this->cookieName && (string) $cookie->getValue() !== '') {
                return CookieValuePrefix::remove(decrypt((string) $cookie->getValue(), false));
            }
        }

        return null;
    }

    private function portal(User $user): string
    {
        return $user->role === 'customer' ? 'customer' : 'admin';
    }

    private function signIn(string $device, User $user): TestResponse
    {
        // Login throttles are per IP, and every simulated device is 127.0.0.1.
        Cache::flush();

        $portal = $this->portal($user);

        $response = $this->on($device, fn () => $this->post("/{$portal}/login", [
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ]));

        $response->assertRedirect($portal === 'admin' ? route('admin.home') : route('customer.menu'));

        return $response;
    }

    /** A page only a signed-in user of this role gets a 200 from. */
    private function homeOf(User $user): string
    {
        return $this->portal($user) === 'admin' ? '/admin/home' : '/customer/account';
    }

    private function assertStillSignedIn(string $device, User $user): void
    {
        $this->on($device, fn () => $this->get($this->homeOf($user)))->assertOk();

        $this->assertSame(
            $user->id,
            Auth::guard($this->portal($user))->id(),
            "device {$device} is no longer signed in as {$user->email}"
        );
    }

    /**
     * The old device's next page load goes to the right login page, the page
     * shows the message, and the device is then genuinely signed out.
     */
    private function assertEndedOnNextRequest(string $device, User $user, string $reason = 'other-device'): void
    {
        $portal = $this->portal($user);
        $login  = route("{$portal}.login", [SingleSession::QUERY => $reason]);

        $response = $this->on($device, fn () => $this->get($this->homeOf($user)));
        $response->assertRedirect($login);

        $this->on($device, fn () => $this->get($login))
            ->assertOk()
            ->assertSee(SingleSession::MESSAGES[$reason]);

        $this->assertFalse(Auth::guard($portal)->check(), "device {$device} still has a signed-in guard");

        // And it stays out: the next protected page is the ordinary login bounce.
        $this->on($device, fn () => $this->get($this->homeOf($user)))->assertRedirect();
        $this->assertFalse(Auth::guard($portal)->check());
    }

    // ══════════ one session per account, every role ══════════

    public static function allRoles(): array
    {
        return [
            'admin'      => ['admin'],
            'supervisor' => ['supervisor'],
            'staff'      => ['staff'],
            'customer'   => ['customer'],
        ];
    }

    /**
     * @dataProvider allRoles
     */
    public function test_a_login_on_a_second_device_ends_the_first_session(string $role): void
    {
        $user = $this->makeUser($role);

        $this->signIn('A', $user);
        $this->assertStillSignedIn('A', $user);      // CONTROL: A really was signed in

        $this->signIn('B', $user);

        $this->assertEndedOnNextRequest('A', $user);
        $this->assertStillSignedIn('B', $user);
    }

    /**
     * @dataProvider allRoles
     */
    public function test_the_newest_login_always_wins_and_the_old_device_can_sign_back_in(string $role): void
    {
        $user = $this->makeUser($role);

        $this->signIn('A', $user);
        $this->signIn('B', $user);
        $this->assertEndedOnNextRequest('A', $user);

        // Never blocked: A signs in again and now B is the one signed out.
        $this->signIn('A', $user);
        $this->assertStillSignedIn('A', $user);
        $this->assertEndedOnNextRequest('B', $user);
    }

    // ══════════ other accounts are unaffected ══════════

    public function test_two_different_staff_accounts_both_stay_signed_in(): void
    {
        $one = $this->makeUser('staff');
        $two = $this->makeUser('staff');

        $this->signIn('counter-1', $one);
        $this->signIn('counter-2', $two);

        $this->assertStillSignedIn('counter-1', $one);
        $this->assertStillSignedIn('counter-2', $two);
        $this->assertStillSignedIn('counter-1', $one);
    }

    public function test_two_different_supervisor_accounts_both_stay_signed_in(): void
    {
        $one = $this->makeUser('supervisor');
        $two = $this->makeUser('supervisor');

        $this->signIn('office-1', $one);
        $this->signIn('office-2', $two);

        $this->assertStillSignedIn('office-1', $one);
        $this->assertStillSignedIn('office-2', $two);
        $this->assertStillSignedIn('office-1', $one);
    }

    /**
     * The strong form: account X signs in on a second device, which ends X's
     * first device. Account Y, signed in the whole time, notices nothing.
     */
    public function test_a_double_login_on_one_account_leaves_every_other_account_alone(): void
    {
        $x        = $this->makeUser('staff');
        $y        = $this->makeUser('staff');
        $admin    = $this->makeUser('admin');
        $customer = $this->makeUser('customer');

        $this->signIn('x-1', $x);
        $this->signIn('y-1', $y);
        $this->signIn('admin-pc', $admin);
        $this->signIn('phone', $customer);

        $this->signIn('x-2', $x);

        $this->assertEndedOnNextRequest('x-1', $x);
        $this->assertStillSignedIn('x-2', $x);
        $this->assertStillSignedIn('y-1', $y);
        $this->assertStillSignedIn('admin-pc', $admin);
        $this->assertStillSignedIn('phone', $customer);
    }

    // ══════════ remember-me cannot bring the old device back ══════════

    /**
     * A "remember me" cookie issued before this change, in the exact format
     * Laravel's SessionGuard writes it: id|token|password-hash.
     */
    private function legacyRememberCookie(User $user): array
    {
        $token = Str::random(60);
        $user->forceFill(['remember_token' => $token])->save();

        $name  = Auth::guard($this->portal($user))->getRecallerName();
        $value = $user->id . '|' . $token . '|' . $user->fresh()->getAuthPassword();

        // CONTROL: the cookie is genuinely valid against the database, so a
        // refusal below is the middleware's doing, not a malformed cookie.
        $this->assertNotNull(
            Auth::guard($this->portal($user))->getProvider()->retrieveByToken($user->id, $token),
            'the crafted remember cookie would not authenticate anyone, so refusing it proves nothing'
        );

        return [$name => $value];
    }

    public function test_the_old_device_cannot_come_back_with_its_old_remember_cookie(): void
    {
        $admin = $this->makeUser('admin');

        // Device A signed in the old way: a session plus a remember cookie.
        $cookie = $this->legacyRememberCookie($admin);
        $this->signIn('A', $admin);

        // B signs in. A's browser is then closed, so its session cookie is
        // gone and only the remember cookie is left.
        $this->signIn('B', $admin);
        unset($this->jar['A']);

        $response = $this->on('A', fn () => $this->get('/admin/home'), $cookie);

        $response->assertRedirect(route('admin.login'));
        $this->assertFalse(Auth::guard('admin')->check(), 'the old remember cookie signed device A back in');
        $response->assertCookieExpired(array_key_first($cookie));

        $this->assertStillSignedIn('B', $admin);
    }

    /**
     * The browser-close half: a remember cookie that is still perfectly valid
     * (nobody else ever signed in) must not reopen the session either.
     */
    public function test_a_still_valid_remember_cookie_from_before_the_change_is_refused_and_deleted(): void
    {
        foreach (['staff', 'customer'] as $role) {
            $user   = $this->makeUser($role);
            $cookie = $this->legacyRememberCookie($user);

            $response = $this->on("closed-browser-{$role}", fn () => $this->get($this->homeOf($user)), $cookie);

            $response->assertRedirect();
            $this->assertFalse(Auth::guard($this->portal($user))->check(), "a {$role} remember cookie re-opened the session");
            $response->assertCookieExpired(array_key_first($cookie));
        }
    }

    // ══════════ dine-in guests are not accounts ══════════

    public function test_a_dine_in_guest_session_is_untouched(): void
    {
        $table = 'SS' . random_int(100, 999);
        $code  = TableEntry::findOrRegister(1, $table)->code;

        $this->on('table-phone', fn () => $this->get("/customer/menu?branch_id=1&table={$table}&k={$code}"))->assertOk();
        $this->assertNotNull(TableOccupancy::activeFor(1, $table), 'CONTROL: the guest was not seated');
        $token = session('table_session_token');
        $this->assertNotEmpty($token, 'CONTROL: the guest session has no table token');

        // Meanwhile an account is signed in twice elsewhere.
        $customer = $this->makeUser('customer');
        $this->signIn('A', $customer);
        $this->signIn('B', $customer);
        $this->assertEndedOnNextRequest('A', $customer);

        $this->on('table-phone', fn () => $this->getJson('/customer/table-session-status'))
            ->assertOk()
            ->assertJson(['valid' => true]);
        $this->assertSame($token, session('table_session_token'), 'the guest lost their table token');

        $this->on('table-phone', fn () => $this->get('/customer/menu'))->assertOk();
        $this->assertSame($token, session('table_session_token'));
    }

    // ══════════ the old device in the middle of something ══════════

    private function cartFor(MenuItem $item): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => (float) $item->price,
            'quantity'     => 1,
            'image'        => $item->image,
            'options'      => [],
        ]];
    }

    private function placeOrderFrom(string $device, array $cart, MenuItem $item, array $headers = []): TestResponse
    {
        return $this->on($device, fn () => $this
            ->withSession(['cart' => $cart, 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ], $headers));
    }

    private function assertNoLeak(TestResponse $response): void
    {
        $this->assertNotSame(500, $response->getStatusCode());

        foreach (['SQLSTATE', 'Stack trace', 'vendor/laravel', 'Exception', 'QueryException'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $response->getContent());
        }
    }

    public function test_the_old_device_mid_checkout_places_no_order_and_is_sent_to_login(): void
    {
        $item     = MenuItem::where('is_available', true)->orderBy('id')->firstOrFail();
        $customer = $this->makeUser('customer');
        $cart     = $this->cartFor($item);

        $this->signIn('phone-A', $customer);
        $this->signIn('phone-B', $customer);

        $before = (int) Order::max('id');

        // A native form post (the Place Order button).
        $response = $this->placeOrderFrom('phone-A', $cart, $item);

        $response->assertRedirect(route('customer.login', [SingleSession::QUERY => 'other-device']));
        $this->assertNoLeak($response);
        $this->assertSame($before, (int) Order::max('id'), 'the ended session placed an order');
        $this->assertSame(0, Order::where('user_id', $customer->id)->count());

        // CONTROL: the very same cart and payload DOES place an order from the
        // device that is signed in, so only the ended session stopped A.
        $this->placeOrderFrom('phone-B', $cart, $item);
        $this->assertGreaterThan($before, (int) Order::max('id'), 'CONTROL: the payload itself cannot place an order');
        $this->assertSame(1, Order::where('user_id', $customer->id)->count());
    }

    public function test_the_old_device_mid_checkout_by_fetch_gets_json_it_can_act_on(): void
    {
        $item     = MenuItem::where('is_available', true)->orderBy('id')->firstOrFail();
        $customer = $this->makeUser('customer');

        $this->signIn('phone-A', $customer);
        $this->signIn('phone-B', $customer);

        $before = (int) Order::max('id');

        $response = $this->placeOrderFrom('phone-A', $this->cartFor($item), $item, [
            'Accept'         => 'application/json',
            'Sec-Fetch-Mode' => 'cors',
        ]);

        $login = route('customer.login', [SingleSession::QUERY => 'other-device']);

        $response->assertStatus(401)
            ->assertHeader('X-Session-Ended', $login)
            ->assertJson([
                'success'  => false,
                'message'  => 'Your account was signed in on another device.',
                'redirect' => $login,
            ]);
        $this->assertNoLeak($response);
        $this->assertSame($before, (int) Order::max('id'));
    }

    /**
     * Background polls: the notification bell on both sides. A fetch() gets a
     * 401 + header, never a redirect it would silently follow.
     */
    public function test_a_background_poll_from_the_old_device_is_told_where_to_go(): void
    {
        foreach (['staff' => 'admin.notifications.unread-count', 'customer' => 'customer.notifications.unread-count'] as $role => $poll) {
            $user = $this->makeUser($role);

            $this->signIn("{$role}-A", $user);
            $this->signIn("{$role}-B", $user);

            $login = route($this->portal($user) . '.login', [SingleSession::QUERY => 'other-device']);

            $this->on("{$role}-A", fn () => $this->getJson(route($poll), ['Sec-Fetch-Mode' => 'cors']))
                ->assertStatus(401)
                ->assertHeader('X-Session-Ended', $login)
                ->assertJsonPath('redirect', $login);

            // The same poll from the signed-in device is untouched.
            $this->on("{$role}-B", fn () => $this->getJson(route($poll), ['Sec-Fetch-Mode' => 'cors']))->assertOk();
        }
    }

    public function test_the_session_guard_moves_the_page_on_the_ended_header(): void
    {
        $guard = file_get_contents(resource_path('views/partials/session-guard.blade.php'));

        $this->assertStringContainsString("response.headers.get('X-Session-Ended')", $guard);
        $this->assertStringContainsString("xhr.getResponseHeader('X-Session-Ended')", $guard);
        $this->assertStringContainsString('window.location.href = url', $guard);
    }

    // ══════════ logout on one device, and near-simultaneous logins ══════════

    public function test_the_old_device_pressing_logout_does_not_sign_out_the_new_one(): void
    {
        foreach (['admin', 'customer'] as $role) {
            $user = $this->makeUser($role);

            $this->signIn("{$role}-A", $user);
            $this->signIn("{$role}-B", $user);

            $response = $this->on("{$role}-A", fn () => $this->post('/' . $this->portal($user) . '/logout'));
            $this->assertNoLeak($response);
            $response->assertRedirect();

            $this->assertStillSignedIn("{$role}-B", $user);
        }
    }

    public function test_logging_out_on_the_new_device_leaves_the_old_one_cleanly_signed_out(): void
    {
        $admin = $this->makeUser('admin');

        $this->signIn('A', $admin);
        $this->signIn('B', $admin);

        $this->on('B', fn () => $this->post('/admin/logout'))->assertRedirect(route('admin.login'));
        $this->assertFalse(Auth::guard('admin')->check());

        // Laravel's logout() rotated the token without the login prefix, so A
        // gets the neutral message rather than a claim nobody made. No 500.
        $response = $this->on('A', fn () => $this->get('/admin/home'));
        $this->assertNoLeak($response);
        $response->assertRedirect(route('admin.login', [SingleSession::QUERY => 'ended']));

        // And A can simply sign in again.
        $this->signIn('A', $admin);
        $this->assertStillSignedIn('A', $admin);
    }

    /**
     * Two logins that land at nearly the same moment. Whichever token UPDATE
     * reaches the database last is the account's token, and exactly one
     * browser matches it. Simulated by replaying A's write after B's, which is
     * the interleaving where the EARLIER request's write lands last.
     */
    public function test_whichever_login_write_lands_last_wins_and_exactly_one_session_survives(): void
    {
        $user = $this->makeUser('staff');

        $this->signIn('A', $user);
        $tokenA = $user->fresh()->remember_token;

        $this->signIn('B', $user);
        $this->assertNotSame($tokenA, $user->fresh()->remember_token);

        // A's UPDATE lands after B's.
        DB::table('users')->where('id', $user->id)->update(['remember_token' => $tokenA]);

        $this->assertStillSignedIn('A', $user);
        $this->assertEndedOnNextRequest('B', $user);
    }

    public function test_a_chain_of_logins_leaves_only_the_last_device_signed_in(): void
    {
        $user    = $this->makeUser('customer');
        $devices = ['phone', 'tablet', 'laptop', 'kiosk'];

        foreach ($devices as $device) {
            $this->signIn($device, $user);
        }

        $this->assertStillSignedIn('kiosk', $user);

        foreach (['phone', 'tablet', 'laptop'] as $device) {
            $this->assertEndedOnNextRequest($device, $user);
        }

        $this->assertStillSignedIn('kiosk', $user);
    }

    // ══════════ interactions with the other auth flows ══════════

    /**
     * REWRITTEN October 2026 (email-confirmation gate). This used to be
     * "registration signs the new account in as its only session". Sign-up
     * now signs nobody in, so it must claim no session either: no
     * fingerprint in remember_token, no guard. The account's first real
     * session is its first login after confirming, and single-session then
     * behaves exactly as before.
     */
    public function test_registration_signs_nobody_in_and_the_first_login_owns_the_session(): void
    {
        Cache::flush();
        $email = 'ss-register-' . Str::lower(Str::random(12)) . '@invalid.local';

        $this->on('A', fn () => $this->post('/customer/register', [
            'name'                  => 'Single Session Register',
            'email'                 => $email,
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'contact_number'        => '09171234567',
            'terms'                 => '1',
        ]))->assertRedirect(route('customer.email-verification.pending'));

        $user = User::where('email', $email)->firstOrFail();
        $this->assertFalse(Auth::guard('customer')->check(), 'registration must not sign the customer in');
        $this->assertNull($user->remember_token, 'registration must not claim a session for the account');

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->signIn('A', $user);
        $this->signIn('B', $user);
        $this->assertEndedOnNextRequest('A', $user);
        $this->assertStillSignedIn('B', $user);
    }

    /**
     * Credentials typed at the WRONG login page are rejected, and that
     * rejection must not end the account's real session elsewhere.
     */
    public function test_a_rejected_wrong_door_login_does_not_end_the_real_session(): void
    {
        $staff    = $this->makeUser('staff');
        $customer = $this->makeUser('customer');

        $this->signIn('counter', $staff);
        $this->signIn('phone', $customer);

        Cache::flush();
        $this->on('someone-else', fn () => $this->post('/customer/login', [
            'email' => $staff->email, 'password' => self::PASSWORD,
        ]))->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('customer')->check());

        Cache::flush();
        $this->on('someone-else', fn () => $this->post('/admin/login', [
            'email' => $customer->email, 'password' => self::PASSWORD,
        ]))->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('admin')->check());

        $this->assertStillSignedIn('counter', $staff);
        $this->assertStillSignedIn('phone', $customer);
    }

    public function test_changing_your_own_password_keeps_this_device_signed_in(): void
    {
        $admin = $this->makeUser('admin');

        $this->signIn('A', $admin);

        $this->on('A', fn () => $this->from('/admin/account')->put('/admin/account/password', [
            'current_password'      => self::PASSWORD,
            'password'              => 'NewSingleSess!2',
            'password_confirmation' => 'NewSingleSess!2',
        ]))->assertSessionHas('password_success');

        $this->assertStillSignedIn('A', $admin);
    }

    /**
     * A manager resetting a staff password already rotated remember_token (to
     * kill stolen remember cookies). That now also ends the staff member's
     * live session, and the message is the neutral one: nobody else signed in
     * as them.
     */
    public function test_a_manager_password_reset_ends_the_staff_session_with_the_neutral_message(): void
    {
        $manager = $this->makeUser('admin');
        $staff   = $this->makeUser('staff');

        $this->signIn('counter', $staff);
        $this->signIn('office', $manager);

        $this->on('office', fn () => $this->put("/admin/users/{$staff->id}/password", [
            'password'              => 'ResetSingle!3',
            'password_confirmation' => 'ResetSingle!3',
        ]))->assertSessionHas('success');

        $this->assertEndedOnNextRequest('counter', $staff, 'ended');
        $this->assertStillSignedIn('office', $manager);
    }

    /**
     * Somebody signed in before this was deployed has a login but no
     * fingerprint. It cannot prove it is the current login, so it ends once
     * with the neutral message.
     */
    public function test_a_login_from_before_this_change_is_ended_with_the_neutral_message(): void
    {
        $admin = $this->makeUser('admin');

        $this->on('pre-deploy', fn () => $this
            ->withSession([Auth::guard('admin')->getName() => $admin->id])
            ->get('/admin/home'))
            ->assertRedirect(route('admin.login', [SingleSession::QUERY => 'ended']));

        $this->on('pre-deploy', fn () => $this->get(route('admin.login', [SingleSession::QUERY => 'ended'])))
            ->assertSee('Your session has ended. Please sign in again.');
    }

    // ══════════ the login-page notice ══════════

    public function test_the_notice_is_a_fixed_sentence_and_yields_to_a_real_login_error(): void
    {
        foreach (['/admin/login', '/customer/login'] as $page) {
            $this->get($page . '?signed_out=other-device')
                ->assertOk()
                ->assertSee('Your account was signed in on another device.');

            $this->get($page)->assertOk()->assertDontSee('Your account was signed in on another device.');

            // Only known reasons; nothing from the URL is echoed.
            $this->get($page . '?signed_out=%3Cscript%3Ealert(1)%3C%2Fscript%3E')
                ->assertOk()
                ->assertDontSee('<script>alert(1)</script>', false)
                ->assertDontSee('Your account was signed in on another device.');
        }

        // A wrong password after being signed out returns to the same URL,
        // query string included. The failure is what should be shown.
        Cache::flush();
        $admin = $this->makeUser('admin');
        $login = route('admin.login', [SingleSession::QUERY => 'other-device']);

        $this->on('A', fn () => $this->from($login)->post('/admin/login', [
            'email' => $admin->email, 'password' => 'wrong-password',
        ]))->assertRedirect($login);

        $this->on('A', fn () => $this->get($login))
            ->assertSee('Invalid email or password.')
            ->assertDontSee('Your account was signed in on another device.');
    }

    // ══════════ closing the browser ends the login ══════════

    public function test_expire_on_close_defaults_to_true_in_the_config_file_itself(): void
    {
        // The file's own default, independent of any .env: Hostinger's .env
        // is not in git and may never mention the key.
        $this->assertMatchesRegularExpression(
            "/'expire_on_close'\s*=>\s*env\('SESSION_EXPIRE_ON_CLOSE',\s*true\)/",
            file_get_contents(config_path('session.php'))
        );

        $this->assertTrue((bool) config('session.expire_on_close'));
    }

    /**
     * Every way in issues only a browser-session cookie (no expiry date, so a
     * full browser close deletes it) and no remember cookie.
     */
    public function test_every_login_path_issues_only_a_browser_session_cookie(): void
    {
        $responses = [];

        foreach (['admin', 'supervisor', 'staff', 'customer'] as $role) {
            $user = $this->makeUser($role);
            $responses[$role] = [$this->signIn("cookie-{$role}", $user), $this->portal($user)];
        }

        Cache::flush();
        $responses['register'] = [$this->on('cookie-register', fn () => $this->post('/customer/register', [
            'name'                  => 'Single Session Cookie',
            'email'                 => 'ss-cookie-' . Str::lower(Str::random(12)) . '@invalid.local',
            'password'              => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'contact_number'        => '09171234567',
            'terms'                 => '1',
        ])), 'customer'];

        foreach ($responses as $label => [$response, $guard]) {
            $session = $response->getCookie($this->cookieName, false);

            $this->assertNotNull($session, "{$label}: no session cookie at all");
            $this->assertSame(0, $session->getExpiresTime(), "{$label}: the session cookie outlives the browser");

            $response->assertCookieMissing(Auth::guard($guard)->getRecallerName());
        }
    }
}

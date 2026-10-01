<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Hardening pass F5 (2026-09-27): changing a customer's email or password
 * needs the current password, and a password change rotates the session.
 *
 * WHAT WAS WRONG
 * --------------
 * AuthController::updateAccount() changed the email and the password on
 * nothing more than a live session. Anyone holding one — a phone left unlocked
 * at the table, a copied session cookie — could point the account at their
 * own email or set a new password and lock the owner out. Reproduced before
 * the fix: PUT /customer/account with a new email and a new password and no
 * current password -> 302, both changed, remember_token untouched.
 * The staff side (AdminController::updateOwnPassword) has always demanded the
 * current password.
 *
 * WHAT "THE OTHER ACTIVE SESSION" CAN BE HERE
 * -------------------------------------------
 * One session per account (App\Support\SingleSession) already ends any OTHER
 * device the moment this one signs in. So the only other live session left is
 * a copy of THIS session's own cookie. Rotating remember_token alone ends
 * nothing then — the copy shares the session and gets the new fingerprint too.
 * The fix therefore also regenerates the session id (the same regenerate() +
 * claim() pair the customer login uses): the copy is left holding the old id
 * with the old fingerprint, and its next request is signed out.
 *
 * Name, address and contact edits need no password, unchanged.
 */
class CustomerAccountReauthTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD     = 'Reauth!Pass1';
    private const NEW_PASSWORD = 'Reauth!Pass2';

    private string $cookieName;

    /** device name => that device's current session id */
    private array $jar = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->cookieName = (string) config('session.cookie');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ══════════ fixtures ══════════

    private function customer(): User
    {
        return User::create([
            'name'           => 'Reauth Customer',
            'email'          => 'reauth-' . Str::lower(Str::random(12)) . '@invalid.local',
            'password'       => self::PASSWORD,
            'role'           => 'customer',
            'is_active'      => true,
            'contact_number' => '09170000000',
            'address'        => 'Original address',
            // A customer who can log in has confirmed their email (Oct 2026 gate).
            'email_verified_at' => now(),
        ]);
    }

    /** The form exactly as account-settings.blade.php posts it, with overrides. */
    private function form(User $user, array $overrides = []): array
    {
        return array_merge([
            'name'           => $user->name,
            'email'          => $user->email,
            'contact_number' => $user->contact_number,
            'address'        => $user->address,
            'password'       => '',
        ], $overrides);
    }

    private function update(User $user, array $overrides): TestResponse
    {
        return $this->actingAs($user, 'customer')
            ->from('/customer/account')
            ->put('/customer/account', $this->form($user, $overrides));
    }

    // ══════════ edits that change neither email nor password: unchanged ══════════

    public function test_name_address_and_contact_edits_still_need_no_password(): void
    {
        $user = $this->customer();

        $this->update($user, [
            'name'           => 'Renamed Customer',
            'address'        => 'New address',
            'contact_number' => '09189999999',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $fresh = $user->fresh();
        $this->assertSame('Renamed Customer', $fresh->name);
        $this->assertSame('New address', $fresh->address);
        $this->assertSame('09189999999', $fresh->contact_number);
        $this->assertTrue(Hash::check(self::PASSWORD, $fresh->password));
    }

    // ══════════ email change ══════════

    public function test_an_email_change_without_the_current_password_is_refused(): void
    {
        $user = $this->customer();
        $oldEmail = $user->email;

        $this->update($user, [
            'name'  => 'Should Not Stick',
            'email' => 'taken-over-' . Str::lower(Str::random(8)) . '@invalid.local',
        ])->assertSessionHasErrors('current_password');

        $fresh = $user->fresh();
        $this->assertSame($oldEmail, $fresh->email, 'the email changed without the current password');
        $this->assertSame('Reauth Customer', $fresh->name, 'a refused update must save nothing, not half of it');
    }

    public function test_an_email_change_with_a_wrong_current_password_is_refused(): void
    {
        $user = $this->customer();
        $oldEmail = $user->email;

        $this->update($user, [
            'email'            => 'taken-over-' . Str::lower(Str::random(8)) . '@invalid.local',
            'current_password' => 'Wrong!Pass9',
        ])->assertSessionHasErrors('current_password');

        $this->assertSame($oldEmail, $user->fresh()->email);
    }

    public function test_an_email_change_with_the_correct_current_password_is_saved(): void
    {
        $user = $this->customer();
        $newEmail = 'moved-' . Str::lower(Str::random(8)) . '@invalid.local';

        $this->update($user, [
            'email'            => $newEmail,
            'current_password' => self::PASSWORD,
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame($newEmail, $user->fresh()->email);
    }

    // ══════════ password change ══════════

    public function test_a_password_change_without_the_current_password_is_refused(): void
    {
        $user = $this->customer();
        $tokenBefore = $user->fresh()->getRememberToken();

        $this->update($user, ['password' => self::NEW_PASSWORD])
            ->assertSessionHasErrors('current_password');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check(self::PASSWORD, $fresh->password), 'the password changed without the current password');
        $this->assertSame($tokenBefore, $fresh->getRememberToken(), 'a refused change must not rotate the session token');
    }

    public function test_a_password_change_with_a_wrong_current_password_is_refused(): void
    {
        $user = $this->customer();

        $this->update($user, [
            'password'         => self::NEW_PASSWORD,
            'current_password' => 'Wrong!Pass9',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_a_password_change_with_the_correct_current_password_is_saved(): void
    {
        $user = $this->customer();
        $tokenBefore = $user->fresh()->getRememberToken();

        $this->update($user, [
            'password'         => self::NEW_PASSWORD,
            'current_password' => self::PASSWORD,
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertNotSame($tokenBefore, $fresh->getRememberToken(), 'the single-session token must rotate on a password change');
    }

    /** The shared password policy still runs first, exactly as before. */
    public function test_a_weak_new_password_is_still_refused_by_the_policy_even_with_the_right_current_password(): void
    {
        $user = $this->customer();

        $this->update($user, [
            'password'         => 'short',
            'current_password' => self::PASSWORD,
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    // ══════════ the other active session ══════════
    //
    // Two-browser harness copied from SingleSessionPerAccountTest::on() — see
    // that file for the traps each reset line exists for.

    private function on(string $device, callable $request): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $id = $this->jar[$device] ??= Str::random(40);

        $store = $this->app['session']->driver();
        $store->flush();
        $store->setId($id);

        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->flushHeaders();
        $this->withCredentials();
        $this->withCookie($this->cookieName, $id);

        $response = $request();

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $this->cookieName && (string) $cookie->getValue() !== '') {
                $this->jar[$device] = CookieValuePrefix::remove(decrypt((string) $cookie->getValue(), false));
            }
        }

        return $response;
    }

    private function signIn(string $device, User $user): void
    {
        Cache::flush(); // login is throttled per IP; every device here is 127.0.0.1

        $this->on($device, fn () => $this->post('/customer/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ]))->assertRedirect(route('customer.menu'));
    }

    private function assertSignedIn(string $device, User $user): void
    {
        $this->on($device, fn () => $this->get('/customer/account'))->assertOk();
        $this->assertSame($user->id, Auth::guard('customer')->id(), "device {$device} is not signed in");
    }

    private function assertSignedOut(string $device): void
    {
        $response = $this->on($device, fn () => $this->get('/customer/account'));

        $this->assertTrue($response->isRedirect(), "device {$device} still got the account page");
        $this->assertStringStartsWith(
            route('customer.login'),
            (string) $response->headers->get('Location'),
            "device {$device} was not sent to the customer login page"
        );
        $this->assertFalse(Auth::guard('customer')->check(), "device {$device} still has a signed-in guard");
    }

    public function test_a_password_change_signs_out_the_other_active_session_and_keeps_this_one(): void
    {
        $user = $this->customer();

        $this->signIn('phone', $user);
        // The other active session: a copy of the phone's own session cookie.
        $this->jar['copy'] = $this->jar['phone'];

        // CONTROL: both really are signed in before the change.
        $this->assertSignedIn('phone', $user);
        $this->assertSignedIn('copy', $user);

        $this->on('phone', fn () => $this->from('/customer/account')->put(
            '/customer/account',
            $this->form($user, ['password' => self::NEW_PASSWORD, 'current_password' => self::PASSWORD])
        ))->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertNotSame($this->jar['phone'], $this->jar['copy'], 'the session id was not rotated');

        $this->assertSignedOut('copy');
        $this->assertSignedIn('phone', $user);
    }

    /** Pairing: a refused change rotates nothing, so the other session is untouched. */
    public function test_a_refused_password_change_leaves_every_session_alone(): void
    {
        $user = $this->customer();

        $this->signIn('phone', $user);
        $this->jar['copy'] = $this->jar['phone'];

        $this->on('phone', fn () => $this->from('/customer/account')->put(
            '/customer/account',
            $this->form($user, ['password' => self::NEW_PASSWORD, 'current_password' => 'Wrong!Pass9'])
        ))->assertSessionHasErrors('current_password');

        $this->assertSignedIn('copy', $user);
        $this->assertSignedIn('phone', $user);
    }

    /** Pairing: a name-only edit is not a credential change and rotates nothing. */
    public function test_a_name_only_edit_leaves_every_session_alone(): void
    {
        $user = $this->customer();

        $this->signIn('phone', $user);
        $this->jar['copy'] = $this->jar['phone'];

        $this->on('phone', fn () => $this->from('/customer/account')->put(
            '/customer/account',
            $this->form($user, ['name' => 'Just A Rename'])
        ))->assertSessionHasNoErrors();

        $this->assertSame('Just A Rename', $user->fresh()->name);
        $this->assertSignedIn('copy', $user);
        $this->assertSignedIn('phone', $user);
    }

    // ══════════ guessing the current password is throttled ══════════

    public function test_the_route_goes_through_its_own_named_limiter(): void
    {
        $route = Route::getRoutes()->getByName('customer.account.update');

        $this->assertContains('throttle:customer-account-update', $route->gatherMiddleware());
    }

    public function test_current_password_guessing_stops_at_the_ceiling(): void
    {
        $user = $this->customer();
        $ceiling = \App\Providers\RateLimitServiceProvider::CUSTOMER_ACCOUNT_UPDATE_PER_IP;

        for ($i = 1; $i <= $ceiling; $i++) {
            $this->update($user, [
                'password'         => self::NEW_PASSWORD,
                'current_password' => 'Guess!Pass' . $i,
            ])->assertSessionHasErrors('current_password');
        }

        // Even the right password is not looked at once the ceiling is hit.
        $this->update($user, [
            'password'         => self::NEW_PASSWORD,
            'current_password' => self::PASSWORD,
        ])->assertStatus(429);

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    /** Its own counter: spending it does not spend the customer login budget. */
    public function test_spending_the_account_ceiling_does_not_spend_the_login_ceiling(): void
    {
        $user = $this->customer();
        $ceiling = \App\Providers\RateLimitServiceProvider::CUSTOMER_ACCOUNT_UPDATE_PER_IP;

        for ($i = 1; $i <= $ceiling + 1; $i++) {
            $this->update($user, ['name' => 'Rename ' . $i]);
        }

        $this->app['auth']->forgetGuards();
        $this->post('/customer/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('customer.menu'));

        // Only a login that was actually processed writes this fingerprint
        // (SingleSession::claim()), so the redirect above was a real sign-in.
        $this->assertTrue(session()->has('single_session.customer'), 'the login was not actually processed');
    }
}

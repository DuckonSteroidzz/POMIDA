<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\OneBrowser;
use Tests\TestCase;

/**
 * Defect Report 6, problem 1: "When I'm creating a new password, I tried a few
 * passwords to satisfy the requirements and suddenly it said 'session has
 * ended'."
 *
 * Reproduced four ways, each pinned here:
 *   1. Signed in as that account in the same browser, the password that finally
 *      PASSED rotated the login token, so the next request said "Your session
 *      has ended" over a password that had in fact been changed.
 *   2. The 7th try in a minute hit the 6/min limiter ("Too many attempts").
 *   3. A login or logout in another tab rotated the CSRF token (the 419 card).
 *   4. A reset past its window kept reporting rule errors, then expired only
 *      when a valid password finally arrived, with no way forward on the page.
 *
 * What was NOT happening, also pinned: a refused password never consumed the
 * code, cleared the reset, or regenerated the session.
 */
class PasswordResetKeepsGoingTest extends TestCase
{
    use DatabaseTransactions;
    use OneBrowser;

    private const OLD = 'Defect6!OldOne1';
    private const NEW = 'Defect6!NewOne2';
    private const CODE = '123456';

    /** Each fails a different rule. */
    private const BAD = [
        'short', 'abcdefgh', 'abcdefgh1', 'Abcdefgh1', 'ALLUPPER1!', 'alllower1!',
        'NoDigits!!', 'Toolongpassword1234567!', 'Abcdefgh1', 'abcdefgh',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public static function portals(): array
    {
        return ['customer portal' => ['customer'], 'admin portal' => ['admin']];
    }

    private function account(string $portal): User
    {
        return User::create([
            'name'              => 'Reset ' . ucfirst($portal),
            'email'             => 'reset-' . $portal . '-' . Str::lower(Str::random(12)) . '@invalid.local',
            'password'          => self::OLD,
            'role'              => $portal,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    /** Step one for real, then a code this test knows. */
    private function requestCode(User $user, string $portal, string $browser = 'tester'): void
    {
        $this->browser(fn () => $this->post("/{$portal}/forgot-password", ['email' => $user->email]), $browser)
            ->assertRedirect(route("{$portal}.verification"));

        DB::table('password_reset_tokens')->where('email', $user->email)->update(['token' => Hash::make(self::CODE)]);
    }

    private function enterCode(string $portal, string $code = self::CODE, string $browser = 'tester'): TestResponse
    {
        return $this->browser(fn () => $this->post("/{$portal}/verification", ['otp' => str_split($code)]), $browser);
    }

    private function reachNewPassword(User $user, string $portal, string $browser = 'tester'): void
    {
        $this->requestCode($user, $portal, $browser);
        $this->enterCode($portal, self::CODE, $browser)->assertRedirect(route("{$portal}.new-password"));
    }

    private function submit(string $portal, string $password, ?string $confirm = null, string $browser = 'tester'): TestResponse
    {
        return $this->browser(fn () => $this->post("/{$portal}/new-password", [
            'password'              => $password,
            'password_confirmation' => $confirm ?? $password,
        ]), $browser);
    }

    private function passwordIs(User $user, string $password): bool
    {
        return Hash::check($password, $user->fresh()->password);
    }

    private function rowExists(User $user): bool
    {
        return DB::table('password_reset_tokens')->where('email', $user->email)->exists();
    }

    // ══════════ refused passwords change nothing ══════════

    /** @dataProvider portals */
    public function test_several_rule_failures_then_a_valid_password_succeeds(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        $idBefore = $this->jar['tester'];

        foreach (self::BAD as $attempt => $bad) {
            $this->submit($portal, $bad)
                ->assertRedirect()
                ->assertSessionHasErrors('password');

            $this->assertTrue($this->rowExists($user), "the reset row died on refused password #{$attempt}");
            $this->assertTrue((bool) ($this->sessionData()["{$portal}_password_reset"]['verified'] ?? false), "the verified step was lost on refused password #{$attempt}");
        }

        // A confirmation that does not match is a rule failure too.
        $this->submit($portal, self::NEW, 'Different!Pass8')->assertSessionHasErrors('password');

        $this->assertSame($idBefore, $this->jar['tester'], 'a refused password regenerated the session');
        $this->assertTrue($this->passwordIs($user, self::OLD), 'a refused password changed something');

        $this->submit($portal, self::NEW)->assertRedirect(route("{$portal}.login"));

        $this->assertTrue($this->passwordIs($user, self::NEW));
        $this->assertFalse($this->rowExists($user), 'a used reset must not be reusable');
    }

    /** @dataProvider portals */
    public function test_the_code_still_works_after_refused_passwords_while_it_is_valid(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        foreach (array_slice(self::BAD, 0, 4) as $bad) {
            $this->submit($portal, $bad);
        }

        $this->enterCode($portal)->assertRedirect(route("{$portal}.new-password"));
    }

    /** @dataProvider portals */
    public function test_refused_passwords_do_not_touch_the_wrong_code_budget(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        // Four wrong guesses leave one more before the code dies.
        $wrong = '000000';
        foreach (range(1, 4) as $i) {
            $this->enterCode($portal, $wrong);
        }
        foreach (self::BAD as $bad) {
            $this->submit($portal, $bad);
        }

        $this->assertTrue($this->rowExists($user), 'refused passwords were charged to the code budget');
    }

    // ══════════ the limiter ══════════

    /** @dataProvider portals */
    public function test_refused_passwords_never_reach_the_six_per_minute_limit(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        foreach (range(1, 25) as $attempt) {
            $response = $this->submit($portal, 'abcdefgh');
            $this->assertNotSame(429, $response->getStatusCode(), "refused password #{$attempt} was throttled");
        }

        $this->submit($portal, self::NEW)->assertRedirect(route("{$portal}.login"));
    }

    /** @dataProvider portals */
    public function test_a_flood_of_refused_passwords_is_still_capped(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        foreach (range(1, \App\Providers\RateLimitServiceProvider::RESET_PASSWORD_RULE_FAILURES_PER_IP) as $attempt) {
            $this->assertNotSame(429, $this->submit($portal, 'abcdefgh')->getStatusCode(), "throttled early, at #{$attempt}");
        }

        $this->submit($portal, 'abcdefgh')->assertStatus(429);
    }

    /** @dataProvider portals */
    public function test_the_step_keeps_its_six_per_minute_limit_for_a_session_that_proved_nothing(string $portal): void
    {
        foreach (range(1, 6) as $attempt) {
            $this->assertNotSame(429, $this->submit($portal, 'abcdefgh')->getStatusCode(), "throttled early, at #{$attempt}");
        }

        $this->submit($portal, 'abcdefgh')->assertStatus(429);
    }

    /** @dataProvider portals */
    public function test_guessing_the_code_is_still_limited_per_code_and_per_ip(string $portal): void
    {
        $user = $this->account($portal);
        $this->requestCode($user, $portal);

        foreach (range(1, 5) as $i) {
            $this->enterCode($portal, '000000')->assertSessionHasErrors('otp');
        }

        $this->assertFalse($this->rowExists($user), 'five wrong guesses must destroy the code');
        $this->enterCode($portal)->assertSessionHasErrors('otp');

        // The per-IP limit: 10/min across every guess so far, right or wrong.
        $status = null;
        foreach (range(1, 6) as $i) {
            $status = $this->enterCode($portal, '000000')->getStatusCode();
        }
        $this->assertSame(429, $status, 'guessing the code is no longer throttled per IP');
    }

    // ══════════ a reset that has really ended ══════════

    /** @dataProvider portals */
    public function test_an_expired_reset_says_so_with_a_way_to_request_a_new_code(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        $this->travel(11)->minutes();

        // A password that fails the rules gets the honest answer, not more rule errors.
        $this->submit($portal, 'abcdefgh')
            ->assertRedirect(route("{$portal}.forgot-password"))
            ->assertSessionDoesntHaveErrors('password');

        $this->assertTrue($this->passwordIs($user, self::OLD));
        $this->assertFalse($this->rowExists($user));

        $this->browser(fn () => $this->get("/{$portal}/forgot-password"))
            ->assertOk()
            ->assertSee('Your password reset has expired', false)
            ->assertSee('your password has not been changed', false)
            ->assertSee('Request a New Code')
            ->assertSee('value="' . $user->email . '"', false);

        // The button really does start again.
        $this->requestCode($user, $portal);
        $this->enterCode($portal)->assertRedirect(route("{$portal}.new-password"));
        $this->submit($portal, self::NEW)->assertRedirect(route("{$portal}.login"));
        $this->assertTrue($this->passwordIs($user, self::NEW));
    }

    /** @dataProvider portals */
    public function test_opening_the_page_after_it_expired_shows_the_same_message(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        $this->travel(11)->minutes();

        $this->browser(fn () => $this->get("/{$portal}/new-password"))->assertRedirect(route("{$portal}.forgot-password"));
        $this->browser(fn () => $this->get("/{$portal}/forgot-password"))->assertSee('Your password reset has expired', false);
    }

    /** @dataProvider portals */
    public function test_a_session_that_lost_the_reset_gets_the_same_message(string $portal): void
    {
        $this->submit($portal, self::NEW)->assertRedirect(route("{$portal}.forgot-password"));

        $this->browser(fn () => $this->get("/{$portal}/forgot-password"))
            ->assertSee('Your password reset has expired', false)
            ->assertSee('Request a New Code');
    }

    public function test_the_ordinary_first_step_is_unchanged(): void
    {
        $this->browser(fn () => $this->get('/customer/forgot-password'))
            ->assertOk()
            ->assertSee('Send Code')
            ->assertDontSee('Request a New Code')
            ->assertDontSee('has expired');
    }

    /**
     * The code took 9 of its 10 minutes to arrive: the person still gets time to choose.
     *
     * @dataProvider portals
     */
    public function test_the_time_to_choose_starts_when_the_code_is_proved(string $portal): void
    {
        $user = $this->account($portal);
        $this->requestCode($user, $portal);

        $this->travel(9)->minutes();
        $this->enterCode($portal)->assertRedirect(route("{$portal}.new-password"));

        $this->travel(5)->minutes();
        $this->submit($portal, 'abcdefgh')->assertSessionHasErrors('password');
        $this->submit($portal, self::NEW)->assertRedirect(route("{$portal}.login"));

        $this->assertTrue($this->passwordIs($user, self::NEW));
    }

    /**
     * ...but that does not lengthen the life of the code for anyone guessing it.
     *
     * @dataProvider portals
     */
    public function test_the_code_itself_still_dies_after_ten_minutes(string $portal): void
    {
        $user = $this->account($portal);
        $this->requestCode($user, $portal);

        $this->travel(11)->minutes();

        $this->enterCode($portal)->assertSessionHasErrors('otp');
        $this->assertFalse($this->rowExists($user));
    }

    /** @dataProvider portals */
    public function test_a_consumed_reset_cannot_be_replayed(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        // The same account's code is used up elsewhere while this tab is open.
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        $this->submit($portal, self::NEW)->assertRedirect(route("{$portal}.forgot-password"));

        $this->assertTrue($this->passwordIs($user, self::OLD));
    }

    // ══════════ the exact sentence the tester saw ══════════

    /** @dataProvider portals */
    public function test_resetting_the_account_this_browser_is_signed_in_to_reports_success(string $portal): void
    {
        $user = $this->account($portal);

        Cache::flush();
        $this->browser(fn () => $this->post("/{$portal}/login", ['email' => $user->email, 'password' => self::OLD]))->assertRedirect();
        $this->assertTrue($this->hasLogin($portal), 'login must succeed first');

        $idBefore = $this->jar['tester'];

        $this->reachNewPassword($user, $portal);
        $this->submit($portal, self::NEW)->assertRedirect(route("{$portal}.login"));

        $this->assertTrue($this->passwordIs($user, self::NEW));
        $this->assertFalse($this->hasLogin($portal), 'a reset must end the login in this browser too');
        $this->assertNotSame($idBefore, $this->jar['tester'], 'the session id must rotate when the login ends');

        $this->browser(fn () => $this->get("/{$portal}/login"))
            ->assertOk()
            ->assertSee('Your password has been updated')
            ->assertDontSee('session has ended');
    }

    public function test_a_customer_reset_while_staff_is_signed_in_keeps_staff_signed_in(): void
    {
        $customer = $this->account('customer');
        $admin = $this->account('admin');

        Cache::flush();
        $this->browser(fn () => $this->post('/customer/login', ['email' => $customer->email, 'password' => self::OLD]))->assertRedirect();
        Cache::flush();
        $this->browser(fn () => $this->post('/admin/login', ['email' => $admin->email, 'password' => self::OLD]))->assertRedirect();

        $this->reachNewPassword($customer, 'customer');
        $this->submit('customer', self::NEW)->assertRedirect(route('customer.login'));

        $this->assertFalse($this->hasLogin('customer'));
        $this->assertTrue($this->hasLogin('admin'));
        $this->browser(fn () => $this->get('/admin/home'))->assertOk();
    }

    /**
     * Every OTHER device is still ended: that is the point of rotating the token.
     *
     * @dataProvider portals
     */
    public function test_a_reset_still_ends_the_login_on_another_device(string $portal): void
    {
        $user = $this->account($portal);

        Cache::flush();
        $this->browser(fn () => $this->post("/{$portal}/login", ['email' => $user->email, 'password' => self::OLD]), 'phone')->assertRedirect();

        $this->reachNewPassword($user, $portal);
        $this->submit($portal, self::NEW)->assertRedirect(route("{$portal}.login"));

        $this->browser(fn () => $this->get($portal === 'admin' ? '/admin/home' : '/customer/more'), 'phone')
            ->assertRedirect(route("{$portal}.login", ['signed_out' => 'ended']));
    }

    // ══════════ a stale CSRF token ══════════

    /** @dataProvider portals */
    public function test_a_stale_csrf_token_changes_nothing_and_the_reset_carries_on(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        // Real CSRF checking: ValidateCsrfToken skips itself in the testing env.
        $this->app['env'] = 'production';
        $this->assertFalse($this->app->runningUnitTests());

        $this->browser(fn () => $this->post("/{$portal}/new-password", [
            '_token'                => 'a-token-from-before-the-other-tab-logged-out',
            'password'              => self::NEW,
            'password_confirmation' => self::NEW,
        ]))->assertRedirect(route("{$portal}.new-password"));

        $this->assertTrue($this->passwordIs($user, self::OLD), 'a refused token still changed the password');
        $this->assertTrue($this->rowExists($user), 'a refused token ended the reset');

        // The redirect lands on the working form, and its fresh token works.
        $this->browser(fn () => $this->get("/{$portal}/new-password"))
            ->assertOk()
            ->assertSee('nothing was submitted');

        $token = $this->sessionData()['_token'];

        $this->browser(fn () => $this->post("/{$portal}/new-password", [
            '_token'                => $token,
            'password'              => self::NEW,
            'password_confirmation' => self::NEW,
        ]))->assertRedirect(route("{$portal}.login"));

        $this->assertTrue($this->passwordIs($user, self::NEW));
    }

    // ══════════ the other tab ══════════

    public function test_a_staff_logout_in_another_tab_leaves_a_customer_reset_open(): void
    {
        $customer = $this->account('customer');
        $this->reachNewPassword($customer, 'customer');

        $admin = $this->account('admin');
        Cache::flush();
        $this->browser(fn () => $this->post('/admin/login', ['email' => $admin->email, 'password' => self::OLD]))->assertRedirect();
        $this->browser(fn () => $this->post('/admin/logout'))->assertRedirect(route('admin.login'));

        $this->submit('customer', self::NEW)->assertRedirect(route('customer.login'));
        $this->assertTrue($this->passwordIs($customer, self::NEW));
    }

    public function test_a_customer_logout_in_another_tab_leaves_an_admin_reset_open(): void
    {
        $admin = $this->account('admin');
        $this->reachNewPassword($admin, 'admin');

        $customer = $this->account('customer');
        Cache::flush();
        $this->browser(fn () => $this->post('/customer/login', ['email' => $customer->email, 'password' => self::OLD]))->assertRedirect();
        $this->browser(fn () => $this->post('/customer/logout'))->assertRedirect(route('home'));

        $this->submit('admin', self::NEW)->assertRedirect(route('admin.login'));
        $this->assertTrue($this->passwordIs($admin, self::NEW));
    }

    /**
     * Deliberate: the same side's logout DOES end it. A verified admin reset can
     * set an admin password, and on a shared counter PC that must not outlive a
     * staff logout.
     */
    public function test_a_staff_logout_ends_an_admin_reset_in_progress(): void
    {
        $admin = $this->account('admin');
        $this->reachNewPassword($admin, 'admin');

        $staff = User::create([
            'name'      => 'Reset Staff',
            'email'     => 'reset-staff-' . Str::lower(Str::random(12)) . '@invalid.local',
            'password'  => self::OLD,
            'role'      => 'staff',
            'is_active' => true,
            'branch_id' => 1,
        ]);
        Cache::flush();
        $this->browser(fn () => $this->post('/admin/login', ['email' => $staff->email, 'password' => self::OLD]))->assertRedirect();
        $this->browser(fn () => $this->post('/admin/logout'))->assertRedirect(route('admin.login'));

        $this->submit('admin', self::NEW)->assertRedirect(route('admin.forgot-password'));
        $this->assertTrue($this->passwordIs($admin, self::OLD));
    }

    // ══════════ the rules on the page ══════════

    /** @dataProvider portals */
    public function test_the_page_shows_a_live_checklist_of_the_rules(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        $html = $this->browser(fn () => $this->get("/{$portal}/new-password"))->assertOk()->getContent();

        $this->assertStringContainsString('id="pwChecklist"', $html);

        foreach (['length', 'upper', 'lower', 'number', 'symbol', 'match'] as $rule) {
            $this->assertStringContainsString('data-rule="' . $rule . '"', $html, "the {$rule} line is missing");
        }

        $this->assertStringContainsString(
            \App\Support\PasswordPolicy::MIN_LENGTH . ' to ' . \App\Support\PasswordPolicy::MAX_LENGTH . ' characters',
            $html
        );
        $this->assertStringContainsString("field.addEventListener('input', update)", $html, 'the checklist script did not reach the page');
    }

    public function test_the_checklist_patterns_match_the_server_rule(): void
    {
        $source = file_get_contents(resource_path('views/partials/password-checklist.blade.php'));

        // Copied from Illuminate\Validation\Rules\Password::validate().
        foreach (["'\\\\p{Lu}'", "'\\\\p{Ll}'", "'\\\\p{N}'", "'\\\\p{Z}|\\\\p{S}|\\\\p{P}'"] as $pattern) {
            $this->assertStringContainsString($pattern, $source);
        }

        $framework = file_get_contents(base_path('vendor/laravel/framework/src/Illuminate/Validation/Rules/Password.php'));
        foreach (['\p{Z}|\p{S}|\p{P}', '\pN', '\p{Ll}', '\p{Lu}'] as $pattern) {
            $this->assertStringContainsString($pattern, $framework, "Laravel's password rule changed: re-check the checklist");
        }
    }

    public function test_every_tab_refreshes_its_token_when_it_comes_back_to_the_front(): void
    {
        $guard = $this->browser(fn () => $this->get('/customer/forgot-password'))->assertOk()->getContent();
        $this->assertStringContainsString(json_encode(route('customer.session-token')), $guard);
        $this->assertStringContainsString("addEventListener('visibilitychange', refreshToken)", $guard);

        $adminGuard = $this->browser(fn () => $this->get('/admin/forgot-password'))->assertOk()->getContent();
        $this->assertStringContainsString(json_encode(route('admin.session-token')), $adminGuard);
    }
}

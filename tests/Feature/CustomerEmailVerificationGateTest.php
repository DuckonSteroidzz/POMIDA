<?php

namespace Tests\Feature;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use App\Services\TableEntry;
use App\Support\VerificationFlow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Customer sign-up must confirm its email before the first login (October
 * 2026). This reverses the earlier locked decision that unverified customers
 * were not blocked; see CustomerEmailVerificationTest for the tests that were
 * rewritten for it.
 *
 *   1. Sign-up creates the account, emails the signed link, and does NOT
 *      sign the customer in. It lands on "Check your email".
 *   2. The link carries an allow-listed, signature-protected `flow`
 *      (pickup / dinein) so the right login page opens afterwards, on any
 *      device. Missing or unknown -> Pick-Up. Edited -> refused.
 *   3. Login: correct password + unverified -> refused with a Resend link.
 *      That message exists ONLY behind a correct password. A wrong password
 *      or an unknown email keeps the one generic error.
 *   4. Portal accounts and Dine-In guests are untouched.
 *   5. Resend from the check-your-email page gives one identical answer for
 *      every address, and has its own named limiter.
 *
 * DATA HYGIENE: DatabaseTransactions; every account carries a CEVG email.
 */
class CustomerEmailVerificationGateTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Aa1!aaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLimiters();
    }

    protected function tearDown(): void
    {
        $this->clearLimiters();
        parent::tearDown();
    }

    private function clearLimiters(): void
    {
        // Every limiter this file touches is keyed on 127.0.0.1. Named
        // limiters hash their keys inside ThrottleRequests, so flush the whole
        // store; phpunit.xml pins CACHE_STORE=array, so this is only this
        // process's in-memory cache.
        RateLimiter::clear('table-code:127.0.0.1');
        RateLimiter::clear('qr-scan:127.0.0.1');
        app('cache')->store()->flush();
    }

    private function email(string $tag): string
    {
        return 'cevg-' . $tag . '-' . uniqid() . '@example.test';
    }

    private function registerPayload(string $email): array
    {
        return [
            'name' => 'CEVG Person',
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'contact_number' => '09171234567',
            'terms' => '1',
        ];
    }

    private function customer(string $tag, bool $verified, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => 'CEVG ' . $tag,
            'email' => $this->email($tag),
            'password' => self::PASSWORD,
            'contact_number' => '09171234567',
            'role' => 'customer',
            'is_active' => true,
            'email_verified_at' => $verified ? now()->subDay() : null,
        ], $extra));
    }

    /** The link the most recent VerifyEmailMail to $email carried. */
    private function sentLink(string $email): string
    {
        $url = null;
        Mail::assertSent(VerifyEmailMail::class, function ($mail) use ($email, &$url) {
            if ($mail->hasTo($email)) {
                $url = $mail->verificationUrl;

                return true;
            }

            return false;
        });

        return $url;
    }

    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q;
    }

    private function signedLink(User $user, array $extra = []): string
    {
        return URL::temporarySignedRoute(
            'customer.email-verification.verify',
            now()->addMinutes(60),
            array_merge(['id' => $user->id, 'hash' => sha1($user->email)], $extra)
        );
    }

    // ══════════ 1. sign-up ══════════

    public function test_register_does_not_log_the_customer_in_and_shows_check_your_email(): void
    {
        Mail::fake();
        $email = $this->email('signup');

        $this->post(route('customer.register.post'), $this->registerPayload($email))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('customer.email-verification.pending'));

        $this->assertGuest('customer');

        $user = User::where('email', $email)->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertSame('customer', $user->role);

        // The existing signed link, now carrying flow=pickup.
        $link = $this->sentLink($email);
        $this->assertSame(VerificationFlow::PICKUP, $this->queryOf($link)['flow'] ?? null);
        $this->assertArrayHasKey('signature', $this->queryOf($link));

        $this->get(route('customer.email-verification.pending'))
            ->assertOk()
            ->assertSee('Check your email to activate your account')
            ->assertSee($email)
            ->assertSee('Resend confirmation email');

        // And the menu does not treat them as signed in.
        $this->assertGuest('customer');
    }

    public function test_a_dine_in_sign_up_carries_flow_dinein_and_keeps_its_table(): void
    {
        Mail::fake();
        $code = TableEntry::findOrRegister(1, '3')->code;

        $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', ['table_code' => $code, 'next' => 'register'])
            ->assertRedirect(route('customer.register'));

        $email = $this->email('dinein');
        $this->post(route('customer.register.post'), $this->registerPayload($email))
            ->assertRedirect(route('customer.email-verification.pending'));

        $this->assertGuest('customer');
        $this->assertSame(VerificationFlow::DINEIN, $this->queryOf($this->sentLink($email))['flow'] ?? null);

        // The table this browser scanned is still here for the later login.
        $this->assertSame('dine_in', session('order_type'));
        $this->assertSame('3', (string) session('table_number'));
    }

    // ══════════ 2. the verification link routes by flow ══════════

    public function test_pickup_flow_link_verifies_and_opens_the_pickup_login(): void
    {
        $user = $this->customer('pickup', false);

        $this->get($this->signedLink($user, ['flow' => 'pickup']))
            ->assertRedirect(route('customer.login', ['order_type' => 'pick_up']))
            ->assertSessionHas('success', VerificationFlow::VERIFIED_MESSAGE);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest('customer');
    }

    public function test_dinein_flow_link_on_the_browser_holding_the_table_opens_the_dine_in_login(): void
    {
        $user = $this->customer('dinein-same', false);

        $this->withSession(['order_type' => 'dine_in', 'table_number' => '3', 'branch_id' => 1])
            ->get($this->signedLink($user, ['flow' => 'dinein']))
            ->assertRedirect(route('customer.login'))
            ->assertSessionHas('success', VerificationFlow::VERIFIED_MESSAGE);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest('customer');

        // The page it lands on is the Dine-In login ("table remembered").
        $this->get(route('customer.login'))
            ->assertOk()
            ->assertSee('Your dine-in table has been remembered')
            ->assertSee(VerificationFlow::VERIFIED_MESSAGE);
    }

    public function test_dinein_flow_link_on_another_device_opens_the_dine_in_entry_page(): void
    {
        $user = $this->customer('dinein-other', false);

        // A phone's mail app: no session at all.
        $this->get($this->signedLink($user, ['flow' => 'dinein']))
            ->assertRedirect(route('customer.dineinqr'));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest('customer');

        $this->get(route('customer.dineinqr'))
            ->assertOk()
            ->assertSee(VerificationFlow::VERIFIED_MESSAGE)
            ->assertSee('choose &quot;Log In&quot;', false);
    }

    public function test_a_link_with_no_flow_falls_back_to_the_pickup_login(): void
    {
        // A link emailed before this change: signed, but no flow at all.
        $user = $this->customer('noflow', false);

        $this->get($this->signedLink($user))
            ->assertRedirect(route('customer.login', ['order_type' => 'pick_up']));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest('customer');
    }

    public function test_a_signed_but_unknown_flow_value_falls_back_to_pickup(): void
    {
        $user = $this->customer('oddflow', false);

        $this->get($this->signedLink($user, ['flow' => 'admin']))
            ->assertRedirect(route('customer.login', ['order_type' => 'pick_up']));

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_tampered_flow_breaks_the_signature_and_verifies_nothing(): void
    {
        $user = $this->customer('tamper', false);

        $link = $this->signedLink($user, ['flow' => 'pickup']);
        $tampered = str_replace('flow=pickup', 'flow=dinein', $link);
        $this->assertNotSame($link, $tampered, 'the test did not actually change the link');

        $this->get($tampered)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);

        // Removing flow is tampering too.
        $stripped = preg_replace('/([?&])flow=pickup&?/', '$1', $link);
        $this->get($stripped)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    // ══════════ 3. the login gate ══════════

    public function test_unverified_customer_with_the_correct_password_is_refused_with_the_message(): void
    {
        $user = $this->customer('gate', false);
        $tokenBefore = $user->remember_token;

        $this->from(route('customer.login'))
            ->post(route('customer.login.post'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('customer.login'))
            ->assertSessionHasErrors(['email' => VerificationFlow::UNVERIFIED_LOGIN_MESSAGE])
            ->assertSessionHas('unverified_login', true);

        $this->assertGuest('customer');
        $this->assertSame($tokenBefore, $user->fresh()->remember_token, 'a refused login must not rotate the single-session token');

        $this->get(route('customer.login'))
            ->assertOk()
            ->assertSee(VerificationFlow::UNVERIFIED_LOGIN_MESSAGE)
            ->assertSee('Resend confirmation email')
            ->assertSee(route('customer.email-verification.pending'), false);

        // The Resend link opens the check-your-email page for THIS address.
        $this->get(route('customer.email-verification.pending'))
            ->assertOk()
            ->assertSee($user->email);
    }

    public function test_unverified_customer_with_a_wrong_password_gets_only_the_generic_error(): void
    {
        $user = $this->customer('wrongpw', false);

        $unverifiedWrong = $this->from(route('customer.login'))
            ->post(route('customer.login.post'), ['email' => $user->email, 'password' => 'Wrong1!xyz']);
        $unverifiedWrong->assertSessionHasErrors(['email' => 'Invalid email or password.']);
        $this->assertNull(session('unverified_login'));
        $this->assertGuest('customer');
        $unverifiedErrors = session('errors')->getBag('default')->toArray();

        $this->flushSession();

        $unknown = $this->from(route('customer.login'))
            ->post(route('customer.login.post'), ['email' => $this->email('nobody'), 'password' => 'Wrong1!xyz']);
        $unknownErrors = session('errors')->getBag('default')->toArray();

        // Byte-for-byte the same answer: status, destination and errors.
        $this->assertSame($unknown->getStatusCode(), $unverifiedWrong->getStatusCode());
        $this->assertSame($unknown->headers->get('Location'), $unverifiedWrong->headers->get('Location'));
        $this->assertSame($unknownErrors, $unverifiedErrors);

        $this->get(route('customer.login'))->assertDontSee('Resend confirmation email');
    }

    public function test_verified_customer_logs_in_and_lands_on_the_menu(): void
    {
        $user = $this->customer('verified', true);

        $this->post(route('customer.login.post'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('customer.menu'));

        $this->assertAuthenticatedAs($user->fresh(), 'customer');
    }

    public function test_full_journey_sign_up_click_link_log_in_menu(): void
    {
        Mail::fake();
        $email = $this->email('journey');

        $this->get(route('customer.login', ['order_type' => 'pick_up']));
        $this->post(route('customer.register.post'), $this->registerPayload($email));
        $this->assertGuest('customer');

        // Before clicking: refused.
        $this->post(route('customer.login.post'), ['email' => $email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => VerificationFlow::UNVERIFIED_LOGIN_MESSAGE]);
        $this->assertGuest('customer');

        // Click on "another device".
        $link = $this->sentLink($email);
        $this->flushSession();
        $this->get($link)->assertRedirect(route('customer.login', ['order_type' => 'pick_up']));
        $this->assertGuest('customer');

        $this->post(route('customer.login.post'), ['email' => $email, 'password' => self::PASSWORD])
            ->assertRedirect(route('customer.menu'));
        $this->assertAuthenticatedAs(User::where('email', $email)->first(), 'customer');
    }

    // ══════════ 4. who is NOT gated ══════════

    public function test_an_unverified_staff_account_still_logs_in_to_the_admin_portal(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $staff = User::factory()->unverified()->create([
            'role' => 'staff',
            'is_active' => true,
            'branch_id' => DB::table('branches')->where('is_active', true)->value('id'),
            'password' => self::PASSWORD,
        ]);
        $this->assertNull($staff->email_verified_at);

        $this->post(route('admin.login.post'), ['email' => $staff->email, 'password' => self::PASSWORD]);

        $this->assertAuthenticatedAs($staff->fresh(), 'admin');
    }

    public function test_an_unverified_owner_account_still_logs_in_to_the_admin_portal(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $owner = User::factory()->unverified()->create([
            'role' => 'admin',
            'is_active' => true,
            'password' => self::PASSWORD,
        ]);

        $this->post(route('admin.login.post'), ['email' => $owner->email, 'password' => self::PASSWORD]);

        $this->assertAuthenticatedAs($owner->fresh(), 'admin');
    }

    public function test_a_dine_in_guest_is_not_asked_to_verify_anything(): void
    {
        $code = TableEntry::findOrRegister(1, '3')->code;

        $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', ['table_code' => $code, 'next' => 'guest'])
            ->assertRedirect(route('customer.menu'));

        $this->get(route('customer.menu'))->assertOk();
        $this->assertGuest('customer');
        $this->assertSame('dine_in', session('order_type'));
    }

    // ══════════ 5. resend from the check-your-email page ══════════

    public function test_resend_gives_one_identical_answer_and_only_mails_an_unverified_customer(): void
    {
        Mail::fake();

        $unverified = $this->customer('rs-unverified', false);
        $verified = $this->customer('rs-verified', true);
        $staff = User::factory()->unverified()->create(['role' => 'staff', 'is_active' => true]);
        $cases = [
            'unknown' => $this->email('rs-unknown'),
            'verified' => $verified->email,
            'staff' => $staff->email,
            'unverified' => $unverified->email,
        ];

        $answers = [];
        foreach ($cases as $label => $address) {
            $this->flushSession();
            app('cache')->store()->flush();

            $response = $this->from(route('customer.email-verification.pending'))
                ->post(route('customer.email-verification.request'), ['email' => $address]);

            $answers[$label] = [
                'status' => $response->getStatusCode(),
                'location' => $response->headers->get('Location'),
                'success' => session('success'),
                'errors' => session('errors')?->getBag('default')->toArray(),
            ];
        }

        foreach ($answers as $label => $answer) {
            $this->assertSame($answers['unknown'], $answer, "the '{$label}' answer differs from the unknown-address answer");
        }
        $this->assertSame(302, $answers['unknown']['status']);
        $this->assertSame(VerificationFlow::RESEND_ANSWER, $answers['unknown']['success']);

        Mail::assertSent(VerifyEmailMail::class, 1);
        Mail::assertSent(VerifyEmailMail::class, fn ($m) => $m->hasTo($unverified->email));
    }

    public function test_resend_mails_after_the_response_and_only_once_per_request(): void
    {
        Mail::fake();
        $user = $this->customer('rs-once', false);

        $this->post(route('customer.email-verification.request'), ['email' => $user->email]);
        // Later requests in the same test must not re-fire the terminating callback.
        $this->get(route('customer.email-verification.pending'));
        $this->get(route('customer.login'));

        Mail::assertSent(VerifyEmailMail::class, 1);
    }

    public function test_resend_uses_its_own_named_limiter(): void
    {
        $route = Route::getRoutes()->getByName('customer.email-verification.request');
        $this->assertContains('throttle:customer-email-verify-request', $route->gatherMiddleware());
    }

    public function test_resend_is_throttled_per_ip_and_the_throttle_does_not_depend_on_the_address(): void
    {
        Mail::fake();
        $user = $this->customer('rs-throttle', false);

        $limit = \App\Providers\RateLimitServiceProvider::CUSTOMER_EMAIL_VERIFY_REQUEST_PER_IP;

        // Mix real and made-up addresses under the per-IP ceiling.
        for ($i = 1; $i <= $limit; $i++) {
            $this->from(route('customer.email-verification.pending'))
                ->post(route('customer.email-verification.request'), ['email' => $i % 2 ? $user->email : $this->email('x' . $i)])
                ->assertSessionHas('success', VerificationFlow::RESEND_ANSWER);
        }

        foreach (['real' => $user->email, 'fake' => $this->email('over')] as $label => $address) {
            $this->flushSession();
            $this->from(route('customer.email-verification.pending'))
                ->post(route('customer.email-verification.request'), ['email' => $address])
                ->assertRedirect(route('customer.email-verification.pending'))
                ->assertSessionHasErrors('error')
                ->assertHeader('X-RateLimit-Rejected', '1');
        }
    }

    public function test_resend_is_throttled_per_address_across_ips(): void
    {
        Mail::fake();
        $user = $this->customer('rs-perhour', false);
        $perHour = \App\Providers\RateLimitServiceProvider::CUSTOMER_EMAIL_VERIFY_REQUEST_PER_EMAIL_PER_HOUR;

        for ($i = 1; $i <= $perHour; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.' . $i])
                ->post(route('customer.email-verification.request'), ['email' => $user->email])
                ->assertSessionHas('success', VerificationFlow::RESEND_ANSWER);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.1.1'])
            ->from(route('customer.email-verification.pending'))
            ->post(route('customer.email-verification.request'), ['email' => strtoupper($user->email)])
            ->assertSessionHasErrors('error');

        Mail::assertSent(VerifyEmailMail::class, $perHour);
    }

    // ══════════ 6. password reset does not verify ══════════

    public function test_completing_a_password_reset_does_not_mark_the_account_verified(): void
    {
        $user = $this->customer('reset', false);

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => \Illuminate\Support\Facades\Hash::make('123456'),
            'created_at' => now(),
        ]);

        $this->withSession([
            'customer_password_reset.email' => $user->email,
            'customer_password_reset.verified' => true,
        ])->post(route('customer.new-password.post'), [
            'password' => 'NewPass1!x',
            'password_confirmation' => 'NewPass1!x',
        ])->assertSessionHasNoErrors()->assertRedirect(route('customer.login'));

        $this->assertNull($user->fresh()->email_verified_at, 'a reset must not verify the email by itself');

        // Reset worked (new password accepted) but the gate still applies.
        $this->post(route('customer.login.post'), ['email' => $user->email, 'password' => 'NewPass1!x'])
            ->assertSessionHasErrors(['email' => VerificationFlow::UNVERIFIED_LOGIN_MESSAGE]);
        $this->assertGuest('customer');
    }
}

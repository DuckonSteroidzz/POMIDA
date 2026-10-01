<?php

namespace Tests\Feature;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Confirmation/verification email on customer account creation (Gmail SMTP,
 * Laravel's own MustVerifyEmail — see App\Models\User,
 * App\Http\Controllers\Concerns\HandlesEmailVerification, and the
 * 2026_09_28_000000_backfill_email_verified_at_for_existing_users migration).
 *
 * Decision 3 was REVERSED in October 2026 on the owner's instruction:
 * confirming the email is now a login gate for customers. The tests below
 * that used to pin "an unverified customer is not blocked" were rewritten
 * to pin the opposite; each rewritten test says so. The full gate
 * (enumeration safety, flow routing, resend throttling, portal/guest
 * exemptions) lives in CustomerEmailVerificationGateTest.
 *
 * DATA HYGIENE
 * Everything runs inside DatabaseTransactions and every account minted
 * carries a CEV-prefixed email, so nothing survives the run.
 */
class CustomerEmailVerificationTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'CEV';
    private const STRONG_PASSWORD = 'Aa1!aaaaaa';

    private function registerPayload(string $suffix): array
    {
        $email = strtolower(self::PREFIX) . '-' . $suffix . '-' . uniqid() . '@example.test';

        return [
            'name' => self::PREFIX . ' ' . $suffix,
            'email' => $email,
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'contact_number' => '09171234567',
            'address' => self::PREFIX . ' 1 Test St',
            'terms' => '1',
        ];
    }

    // ── 1. registration sends a verification email ──────────────────────────

    public function test_creating_a_customer_account_sends_a_verification_email(): void
    {
        Mail::fake();

        $payload = $this->registerPayload('signup');

        // Changed Oct 2026: lands on "check your email", not the menu.
        $this->post(route('customer.register.post'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('customer.email-verification.pending'));

        $user = User::where('email', $payload['email'])->firstOrFail();

        Mail::assertSent(VerifyEmailMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email) && $mail->user->is($user);
        });
    }

    // ── 2. a mail transport failure must not break account creation ─────────

    public function test_account_creation_still_succeeds_when_mail_sending_throws(): void
    {
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \RuntimeException('Simulated SMTP failure'));

        $payload = $this->registerPayload('mailfail');

        $response = $this->post(route('customer.register.post'), $payload);

        // Changed Oct 2026: the account still exists and the customer lands on
        // "check your email", where Resend can try again once mail works.
        // Nobody is signed in either way.
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('customer.email-verification.pending'));

        $this->assertDatabaseHas('users', [
            'email' => $payload['email'],
            'role' => 'customer',
        ]);
        $this->assertGuest('customer');
    }

    // ── 3. resend from the account page ──────────────────────────────────────

    public function test_resend_verification_email_queues_another_email(): void
    {
        Mail::fake();

        $user = User::create([
            'name' => self::PREFIX . ' Resend',
            'email' => strtolower(self::PREFIX) . '-resend-' . uniqid() . '@example.test',
            'password' => self::STRONG_PASSWORD,
            'contact_number' => '09171234567',
            'role' => 'customer',
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $this->actingAs($user, 'customer')
            ->post(route('customer.email-verification.resend'))
            ->assertRedirect();

        Mail::assertSent(VerifyEmailMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_resend_does_not_resend_for_an_already_verified_customer(): void
    {
        Mail::fake();

        $user = User::create([
            'name' => self::PREFIX . ' AlreadyVerified',
            'email' => strtolower(self::PREFIX) . '-already-' . uniqid() . '@example.test',
            'password' => self::STRONG_PASSWORD,
            'contact_number' => '09171234567',
            'role' => 'customer',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user, 'customer')
            ->post(route('customer.email-verification.resend'))
            ->assertRedirect();

        Mail::assertNothingSent();
    }

    // ── 4. a pre-existing (backfilled) account can still log in ─────────────

    public function test_a_backfilled_account_can_still_log_in_without_verifying(): void
    {
        // Mirrors what the 2026_09_28_000000 migration did to every row that
        // predated this feature: email_verified_at stamped, never blocking.
        $user = User::create([
            'name' => self::PREFIX . ' Backfilled',
            'email' => strtolower(self::PREFIX) . '-backfilled-' . uniqid() . '@example.test',
            'password' => self::STRONG_PASSWORD,
            'contact_number' => '09171234567',
            'role' => 'customer',
            'is_active' => true,
            'email_verified_at' => now()->subDays(30),
        ]);

        $this->post(route('customer.login.post'), [
            'email' => $user->email,
            'password' => self::STRONG_PASSWORD,
        ])->assertRedirect(route('customer.menu'));

        $this->assertAuthenticatedAs($user->fresh(), 'customer');
    }

    // ── 5. a brand-new, UNVERIFIED customer IS blocked until they confirm ───
    //
    // REVERSED Oct 2026. This was
    // test_a_new_unverified_customer_is_not_blocked_from_logging_in_or_browsing,
    // the regression guard for the old "not a gate" decision. It now pins
    // the opposite, end to end: sign-up signs nobody in, the correct
    // password is refused until the link is clicked, then it works.
    // Browsing the menu as a guest was never gated and still is not.

    public function test_a_new_unverified_customer_cannot_log_in_until_the_email_is_confirmed(): void
    {
        Mail::fake();

        $payload = $this->registerPayload('blocked');

        $this->post(route('customer.register.post'), $payload)
            ->assertRedirect(route('customer.email-verification.pending'));
        $this->assertGuest('customer');

        $user = User::where('email', $payload['email'])->firstOrFail();
        $this->assertNull($user->email_verified_at, 'a freshly registered customer starts out unverified');

        $this->post(route('customer.login.post'), [
            'email' => $payload['email'],
            'password' => self::STRONG_PASSWORD,
        ])->assertSessionHasErrors(['email' => \App\Support\VerificationFlow::UNVERIFIED_LOGIN_MESSAGE]);
        $this->assertGuest('customer');

        // Browsing as a guest is not gated.
        $this->get(route('customer.menu'))->assertOk();

        // Click the link from the email, then log in.
        $link = null;
        Mail::assertSent(VerifyEmailMail::class, function ($mail) use (&$link) {
            $link = $mail->verificationUrl;

            return true;
        });
        $this->get($link)->assertRedirect();
        $this->assertGuest('customer');

        $this->post(route('customer.login.post'), [
            'email' => $payload['email'],
            'password' => self::STRONG_PASSWORD,
        ])->assertRedirect(route('customer.menu'));

        $this->assertAuthenticatedAs($user->fresh(), 'customer');
    }

    // ── clicking the signed link actually verifies the account ──────────────

    public function test_clicking_the_signed_verification_link_marks_the_account_verified(): void
    {
        $user = User::create([
            'name' => self::PREFIX . ' ClickLink',
            'email' => strtolower(self::PREFIX) . '-clicklink-' . uniqid() . '@example.test',
            'password' => self::STRONG_PASSWORD,
            'contact_number' => '09171234567',
            'role' => 'customer',
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'customer.email-verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        // Changed Oct 2026: a link with no `flow` (like one emailed before
        // this change) opens the Pick-Up login. Verifying signs nobody in.
        $this->get($url)->assertRedirect(route('customer.login', ['order_type' => 'pick_up']));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest('customer');
    }

    public function test_a_verification_link_whose_hash_no_longer_matches_the_account_is_rejected(): void
    {
        $user = User::create([
            'name' => self::PREFIX . ' Stale',
            'email' => strtolower(self::PREFIX) . '-stale-' . uniqid() . '@example.test',
            'password' => self::STRONG_PASSWORD,
            'contact_number' => '09171234567',
            'role' => 'customer',
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'customer.email-verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('someone-elses-email@example.test')]
        );

        $this->get($url)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_verification_link_is_rejected_by_the_signed_middleware(): void
    {
        $user = User::create([
            'name' => self::PREFIX . ' Expired',
            'email' => strtolower(self::PREFIX) . '-expired-' . uniqid() . '@example.test',
            'password' => self::STRONG_PASSWORD,
            'contact_number' => '09171234567',
            'role' => 'customer',
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'customer.email-verification.verify',
            now()->subMinutes(1),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->get($url)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    // ── 6. admin/staff account creation is unaffected ────────────────────────

    public function test_admin_creating_a_staff_account_sends_no_confirmation_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $branchId = DB::table('branches')->value('id');
        $email = strtolower(self::PREFIX) . '-staff-' . uniqid() . '@example.test';

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.store'), [
                'name' => self::PREFIX . ' New Staff',
                'email' => $email,
                'branch_id' => $branchId,
                'role' => 'staff',
                'password' => self::STRONG_PASSWORD,
                'password_confirmation' => self::STRONG_PASSWORD,
            ])
            ->assertSessionHasNoErrors();

        Mail::assertNothingSent();

        // Pre-verified at creation — no confirmation-email loop for portal
        // accounts, so a future check must never treat this row as pending.
        $staff = User::where('email', $email)->firstOrFail();
        $this->assertNotNull($staff->email_verified_at);
    }
}

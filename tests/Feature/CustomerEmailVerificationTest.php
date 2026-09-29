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
 * Decision 3 (locked): this is the email only, NOT a login/order gate. An
 * unverified customer must still be able to log in and browse — several
 * tests below exist specifically to pin that down as a regression guard,
 * not an assumption.
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

        $this->post(route('customer.register.post'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('customer.menu'));

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

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('customer.menu'));

        // The account exists and the customer is signed in either way.
        $this->assertDatabaseHas('users', [
            'email' => $payload['email'],
            'role' => 'customer',
        ]);
        $this->assertAuthenticatedAs(User::where('email', $payload['email'])->first(), 'customer');
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

    // ── 5. a brand-new, UNVERIFIED customer is not blocked at all ───────────

    public function test_a_new_unverified_customer_is_not_blocked_from_logging_in_or_browsing(): void
    {
        Mail::fake();

        $payload = $this->registerPayload('unblocked');

        // Registration itself signs the customer in immediately.
        $this->post(route('customer.register.post'), $payload)
            ->assertRedirect(route('customer.menu'));

        $user = User::where('email', $payload['email'])->firstOrFail();
        $this->assertNull($user->email_verified_at, 'a freshly registered customer starts out unverified');

        // Log out, then log back in — unverified must not refuse the login.
        $this->post(route('customer.logout'));

        $this->post(route('customer.login.post'), [
            'email' => $payload['email'],
            'password' => self::STRONG_PASSWORD,
        ])->assertRedirect(route('customer.menu'));

        $this->assertAuthenticatedAs($user->fresh(), 'customer');

        // And browsing (the menu) is not blocked either.
        $this->get(route('customer.menu'))->assertOk();
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

        $this->get($url)->assertRedirect(route('customer.login'));

        $this->assertNotNull($user->fresh()->email_verified_at);
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

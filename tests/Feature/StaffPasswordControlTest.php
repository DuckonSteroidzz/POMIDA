<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Staff have NO self-service password path. Only the admin sets a staff
 * password.
 *
 * THE OWNER'S DECISION, 2026-09-01
 * ---------------------------------
 * A staff member must not be able to change their own password by any route:
 * not the admin account page, and not forgot-password. The admin sets it for
 * them from /admin/users. The admin's own password features are untouched —
 * an admin with no reset path locks the whole system out.
 *
 * PASSWORDS CANNOT BE DISPLAYED, AND THAT IS NOT A UI CHOICE
 * ----------------------------------------------------------
 * Stored passwords are bcrypt hashes. There is no decryption, so no screen
 * anywhere can show an existing password — not to the admin, not to anyone
 * with database access. Admin control is therefore exercised by SETTING a new
 * password, never by viewing the old one. That property is asserted in
 * test_a_stored_password_is_a_hash_and_cannot_be_recovered().
 *
 * THREE DOORS, ALL CLOSED
 * -----------------------
 *   1. GET  /admin/account            — the page itself      (role:admin)
 *   2. PUT  /admin/account/password   — the change endpoint  (role:admin)
 *   3. POST /admin/forgot-password    — reset by email       (resettableRoles)
 *
 * And one door opened: PUT /admin/users/{id}/password, admin-only.
 *
 * FALSE-POSITIVE DISCIPLINE
 * -------------------------
 * This suite has caught two tests that passed for the wrong reason before
 * (SESSION_DRIVER=array; ValidateCsrfToken::runningUnitTests). So every
 * "staff is refused" assertion here is paired with a positive control proving
 * the SAME request genuinely works for an admin. A test that only asserts
 * staff is blocked is worthless if the endpoint is broken for everyone.
 */
class StaffPasswordControlTest extends TestCase
{
    use DatabaseTransactions;

    private const STAFF_PW = 'StaffOld!Pass1';
    private const ADMIN_PW = 'AdminOld!Pass1';
    private const NEW_PW   = 'BrandNew!Pass9';

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

    private function makeStaff(): User
    {
        return User::create([
            'name'      => 'Test Staff',
            'email'     => 'spc-staff-' . uniqid() . '@invalid.local',
            'password'  => self::STAFF_PW,
            'role'      => 'staff',
            'is_active' => true,
            'branch_id' => 1,
        ]);
    }

    private function makeAdmin(): User
    {
        return User::create([
            'name'      => 'Test Admin',
            'email'     => 'spc-admin-' . uniqid() . '@invalid.local',
            'password'  => self::ADMIN_PW,
            'role'      => 'admin',
            'is_active' => true,
            'branch_id' => 1,
        ]);
    }

    private function passwordWorks(User $u, string $plain): bool
    {
        return Hash::check($plain, $u->fresh()->password);
    }

    /** The exact refusal RoleMiddleware produces for a wrong role. */
    private function assertRefusedLikeEveryOtherAdminOnlyRoute($response, string $what): void
    {
        $response->assertRedirect(route('admin.home'));
        $this->assertSame(
            "You don't have permission to access that.",
            session('error'),
            "[{$what}] the refusal message differs from every other admin-only route"
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // The premise: passwords are hashes
    // ══════════════════════════════════════════════════════════════════

    public function test_a_stored_password_is_a_hash_and_cannot_be_recovered(): void
    {
        $staff = $this->makeStaff();
        $stored = (string) $staff->fresh()->password;

        $this->assertStringNotContainsString(self::STAFF_PW, $stored);
        $this->assertMatchesRegularExpression('/^\$2y\$/', $stored, 'not a bcrypt hash');
        $this->assertTrue(Hash::check(self::STAFF_PW, $stored), 'the hash should verify its own password');

        // There is no way back from the hash to the password. Asserted as the
        // absence of any endpoint that would return one.
        $this->assertNull(
            \Illuminate\Support\Facades\Route::getRoutes()->getByName('admin.users.password.show'),
            'a route that displays a stored password must never exist'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // Door 1 + 2 — the account page and its update endpoint
    // ══════════════════════════════════════════════════════════════════

    public function test_staff_cannot_open_the_account_page(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff, 'admin')->get('/admin/account');
        $this->assertRefusedLikeEveryOtherAdminOnlyRoute($response, 'GET /admin/account');

        // CONTROL: the page genuinely works for an admin.
        $this->actingAs($this->makeAdmin(), 'admin')->get('/admin/account')->assertOk();
    }

    public function test_staff_cannot_change_their_own_password_by_posting_directly(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff, 'admin')->put('/admin/account/password', [
            'current_password'      => self::STAFF_PW,
            'password'              => self::NEW_PW,
            'password_confirmation' => self::NEW_PW,
        ]);

        $this->assertRefusedLikeEveryOtherAdminOnlyRoute($response, 'PUT /admin/account/password');

        $this->assertTrue($this->passwordWorks($staff, self::STAFF_PW), 'the old password should be unchanged');
        $this->assertFalse(
            $this->passwordWorks($staff, self::NEW_PW),
            'ESCALATION: a staff member changed their own password'
        );

        // CONTROL: the very same endpoint works for an admin.
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'admin')->put('/admin/account/password', [
            'current_password'      => self::ADMIN_PW,
            'password'              => self::NEW_PW,
            'password_confirmation' => self::NEW_PW,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(
            $this->passwordWorks($admin, self::NEW_PW),
            'CONTROL FAILED: the endpoint is broken for admins too, so the refusal above proves nothing'
        );
    }

    public function test_the_account_link_is_hidden_from_staff_and_shown_to_admins(): void
    {
        // A staff member lands on the dashboard, which renders the sidebar.
        $staffHtml = $this->actingAs($this->makeStaff(), 'admin')->get('/admin/home')->getContent();
        $adminHtml = $this->actingAs($this->makeAdmin(), 'admin')->get('/admin/home')->getContent();

        $accountHref = route('admin.account');

        $this->assertStringNotContainsString(
            $accountHref,
            $staffHtml,
            'the Account link is still in the sidebar for staff'
        );

        // CONTROL: it IS there for an admin, so the assertion above is not
        // just observing a broken sidebar.
        $this->assertStringContainsString(
            $accountHref,
            $adminHtml,
            'CONTROL FAILED: the Account link is missing for admins too'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // Door 3 — forgot-password
    // ══════════════════════════════════════════════════════════════════

    public function test_a_staff_email_gets_no_reset_code(): void
    {
        $staff = $this->makeStaff();

        $before = DB::table('password_reset_tokens')->count();

        $this->post('/admin/forgot-password', ['email' => $staff->email]);

        $this->assertSame(
            $before,
            DB::table('password_reset_tokens')->count(),
            'a reset token was issued for a staff account'
        );
        $this->assertFalse(
            DB::table('password_reset_tokens')->where('email', $staff->email)->exists(),
            'a reset token exists for the staff email'
        );
        $this->assertNull(
            session('admin_password_reset.email'),
            'the reset flow advanced for a staff account'
        );

        // CONTROL: an ADMIN email still gets one — the flow is not simply broken.
        $admin = $this->makeAdmin();
        $this->post('/admin/forgot-password', ['email' => $admin->email]);

        $this->assertTrue(
            DB::table('password_reset_tokens')->where('email', $admin->email)->exists(),
            'CONTROL FAILED: admin forgot-password is broken, so the staff refusal proves nothing'
        );
        $this->assertSame($admin->email, session('admin_password_reset.email'));
    }

    /**
     * THE ACCOUNT-ENUMERATION PROPERTY.
     *
     * A staff email and a completely unknown email must be indistinguishable.
     * If they differ in any observable way, the form becomes an oracle for
     * which addresses belong to staff.
     */
    public function test_a_staff_email_is_indistinguishable_from_an_unknown_email(): void
    {
        $staff = $this->makeStaff();

        $staffResponse = $this->post('/admin/forgot-password', ['email' => $staff->email]);
        $staffStatus   = $staffResponse->getStatusCode();
        $staffLocation = $staffResponse->headers->get('Location');
        $staffErrors   = session('errors') ? session('errors')->all() : [];
        $staffSession  = session('admin_password_reset.email');

        session()->flush();

        $unknownResponse = $this->post('/admin/forgot-password', [
            'email' => 'definitely-not-a-user-' . uniqid() . '@invalid.local',
        ]);
        $unknownStatus   = $unknownResponse->getStatusCode();
        $unknownLocation = $unknownResponse->headers->get('Location');
        $unknownErrors   = session('errors') ? session('errors')->all() : [];
        $unknownSession  = session('admin_password_reset.email');

        $this->assertSame($unknownStatus, $staffStatus, 'the HTTP status differs for a staff email');
        $this->assertSame($unknownLocation, $staffLocation, 'the redirect target differs for a staff email');
        $this->assertSame($unknownErrors, $staffErrors, 'the error message differs for a staff email — this is an enumeration oracle');
        $this->assertSame($unknownSession, $staffSession, 'the session state differs for a staff email');

        // And the message must still be the generic one, not a new "staff
        // cannot reset" wording.
        $this->assertNotEmpty($staffErrors);
        $this->assertStringContainsString('could not find an active', $staffErrors[0]);
        $this->assertStringNotContainsStringIgnoringCase('staff cannot', $staffErrors[0]);
        $this->assertStringNotContainsStringIgnoringCase('not permitted', $staffErrors[0]);
    }

    /**
     * A staff member holding a session from BEFORE the change must not be able
     * to finish a reset. updatePassword() re-resolves the user, so the final
     * write is refused too.
     */
    public function test_a_pre_existing_staff_reset_session_cannot_complete(): void
    {
        $staff = $this->makeStaff();

        // Simulate a reset that was already in flight: token row + verified session.
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $staff->email],
            ['token' => Hash::make('123456'), 'created_at' => now()]
        );

        $this->withSession([
            'admin_password_reset.email'    => $staff->email,
            'admin_password_reset.verified' => true,
        ])->post('/admin/new-password', [
            'password'              => self::NEW_PW,
            'password_confirmation' => self::NEW_PW,
        ]);

        $this->assertTrue($this->passwordWorks($staff, self::STAFF_PW), 'the old password should be unchanged');
        $this->assertFalse(
            $this->passwordWorks($staff, self::NEW_PW),
            'ESCALATION: an in-flight staff reset session completed after the change'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // The door that opened — admin sets a staff password
    // ══════════════════════════════════════════════════════════════════

    public function test_an_admin_can_set_a_staff_password_and_it_really_works(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeStaff();

        $this->assertTrue($this->passwordWorks($staff, self::STAFF_PW), 'setup');

        $response = $this->actingAs($admin, 'admin')
            ->put("/admin/users/{$staff->id}/password", [
                'password'              => self::NEW_PW,
                'password_confirmation' => self::NEW_PW,
            ]);

        $response->assertRedirect(route('admin.users'));
        $response->assertSessionHasNoErrors();

        $this->assertTrue(
            $this->passwordWorks($staff, self::NEW_PW),
            'the new password does not work — the reset did not take effect'
        );
        $this->assertFalse($this->passwordWorks($staff, self::STAFF_PW), 'the old password still works');

        // Through the real login form, not just the hash.
        $this->post('/admin/login', ['email' => $staff->email, 'password' => self::NEW_PW]);
        $this->assertTrue(
            \Illuminate\Support\Facades\Auth::guard('admin')->check(),
            'the staff member cannot log in with the password the admin set'
        );
    }

    public function test_the_role_is_never_changed_by_a_password_reset(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeStaff();

        $this->actingAs($admin, 'admin')->put("/admin/users/{$staff->id}/password", [
            'password'              => self::NEW_PW,
            'password_confirmation' => self::NEW_PW,
            // Smuggled, as the mass-assignment pass would try.
            'role'      => 'admin',
            'is_active' => 0,
        ]);

        $fresh = $staff->fresh();
        $this->assertSame('staff', $fresh->role, 'ESCALATION: the password reset changed the role');
        $this->assertTrue((bool) $fresh->is_active, 'the password reset deactivated the account');
    }

    public function test_a_staff_member_cannot_reset_anybody_password(): void
    {
        $staff  = $this->makeStaff();
        $victim = $this->makeStaff();

        $response = $this->actingAs($staff, 'admin')
            ->put("/admin/users/{$victim->id}/password", [
                'password'              => self::NEW_PW,
                'password_confirmation' => self::NEW_PW,
            ]);

        $this->assertRefusedLikeEveryOtherAdminOnlyRoute($response, 'PUT /admin/users/{id}/password');

        $this->assertFalse(
            $this->passwordWorks($victim, self::NEW_PW),
            'ESCALATION: a staff member reset another staff password'
        );
        $this->assertFalse(
            $this->passwordWorks($staff, self::NEW_PW),
            'ESCALATION: a staff member reset their own password through the admin endpoint'
        );

        // CONTROL: an admin can do it.
        $this->actingAs($this->makeAdmin(), 'admin')
            ->put("/admin/users/{$victim->id}/password", [
                'password'              => self::NEW_PW,
                'password_confirmation' => self::NEW_PW,
            ])->assertSessionHasNoErrors();

        $this->assertTrue($this->passwordWorks($victim, self::NEW_PW), 'CONTROL FAILED: the endpoint is broken for admins');
    }

    /**
     * The endpoint must never be usable against an ADMIN row, including the
     * caller's own. Otherwise one admin could silently take over another's
     * account, and a mistyped id could lock the owner out.
     */
    public function test_the_endpoint_cannot_target_an_admin_account(): void
    {
        $admin  = $this->makeAdmin();
        $victim = $this->makeAdmin();

        $this->actingAs($admin, 'admin')->put("/admin/users/{$victim->id}/password", [
            'password'              => self::NEW_PW,
            'password_confirmation' => self::NEW_PW,
        ])->assertRedirect(route('admin.users'));

        $this->assertFalse(
            $this->passwordWorks($victim, self::NEW_PW),
            'ESCALATION: an admin password was reset through the staff endpoint'
        );
        $this->assertTrue($this->passwordWorks($victim, self::ADMIN_PW), 'the admin password should be unchanged');
    }

    public function test_a_weak_or_mismatched_new_password_is_refused(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeStaff();

        // Too short.
        $this->actingAs($admin, 'admin')->put("/admin/users/{$staff->id}/password", [
            'password' => 'Ab!1', 'password_confirmation' => 'Ab!1',
        ])->assertSessionHasErrors('password');

        // Mismatched.
        $this->actingAs($admin, 'admin')->put("/admin/users/{$staff->id}/password", [
            'password' => self::NEW_PW, 'password_confirmation' => 'Different!Pass8',
        ])->assertSessionHasErrors('password');

        // Long enough but no complexity.
        $this->actingAs($admin, 'admin')->put("/admin/users/{$staff->id}/password", [
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($this->passwordWorks($staff, self::STAFF_PW), 'nothing should have changed');
    }

    /**
     * The password must not appear in the response, the flash message, the
     * redirect target, or anywhere else the browser or a log would see it.
     */
    public function test_the_new_password_never_appears_in_the_response_or_flash(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeStaff();

        $response = $this->actingAs($admin, 'admin')
            ->put("/admin/users/{$staff->id}/password", [
                'password'              => self::NEW_PW,
                'password_confirmation' => self::NEW_PW,
            ]);

        $this->assertStringNotContainsString(self::NEW_PW, $response->getContent());
        $this->assertStringNotContainsString(self::NEW_PW, (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString(self::NEW_PW, (string) session('success'));

        // And the page it redirects to.
        $usersPage = $this->actingAs($admin, 'admin')->get('/admin/users')->getContent();
        $this->assertStringNotContainsString(self::NEW_PW, $usersPage);
        $this->assertStringNotContainsString(
            (string) $staff->fresh()->password,
            $usersPage,
            'the password HASH is being rendered on the page'
        );
    }

    /**
     * The staff list page must never render any password material at all.
     */
    public function test_the_staff_list_never_renders_a_password_or_hash(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeStaff();

        $html = $this->actingAs($admin, 'admin')->get('/admin/users')->getContent();

        // CONTROL: the staff member IS on the page, so the assertions below
        // are not just observing an empty list.
        $this->assertStringContainsString($staff->email, $html, 'CONTROL: the staff row should be rendered');

        $this->assertStringNotContainsString(self::STAFF_PW, $html);
        $this->assertStringNotContainsString((string) $staff->fresh()->password, $html);
        $this->assertStringNotContainsString('$2y$', $html, 'a bcrypt hash is being rendered');
    }
}

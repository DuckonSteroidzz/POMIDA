<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Does the admin's own change-password on /admin/account actually work?
 *
 * The owner asked this directly. It is answered here by end-to-end proof
 * rather than by reading the controller: the new password is used to
 * authenticate for real, and the old one is confirmed dead.
 *
 * WHY THAT DISTINCTION MATTERS
 * ----------------------------
 * "The endpoint returned a success flash" proves nothing on its own — a
 * controller can flash success and write nothing, or write to the wrong row,
 * or double-hash so that the stored value can never be matched again. The only
 * assertion that settles it is Auth::attempt() with the new credentials.
 *
 * Every account used here is created by the test and rolled back by
 * DatabaseTransactions. No real seeded password is touched.
 */
class AdminOwnPasswordChangeTest extends TestCase
{
    use DatabaseTransactions;

    private const OLD = 'Old!Passw0rd123';
    private const NEW = 'New!Passw0rd456';

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

    private function makeAdmin(): User
    {
        return User::create([
            'name'      => 'Password Test Admin',
            'email'     => 'ownpw-admin-' . uniqid() . '@invalid.local',
            'password'  => self::OLD,   // hashed by the model's "hashed" cast
            'role'      => 'admin',
            'is_active' => true,
            'branch_id' => 1,
        ]);
    }

    /**
     * Authenticate out-of-band, without disturbing the session under test.
     * Returns true if the credentials are currently valid.
     */
    private function credentialsWork(User $user, string $password): bool
    {
        return Hash::check($password, $user->fresh()->password);
    }

    // ══════════════════════════════════════════════════════════════════
    // The question the owner asked
    // ══════════════════════════════════════════════════════════════════

    /**
     * THE ANSWER. Change the password, then actually sign in with the new one
     * and confirm the old one is refused.
     */
    public function test_an_admin_can_change_their_own_password_and_the_new_one_really_works(): void
    {
        $admin = $this->makeAdmin();

        // CONTROL: the starting state must be what we think it is.
        $this->assertTrue($this->credentialsWork($admin, self::OLD), 'setup: the old password should start valid');
        $this->assertFalse($this->credentialsWork($admin, self::NEW), 'setup: the new password should not work yet');

        $response = $this->actingAs($admin, 'admin')->put('/admin/account/password', [
            'current_password'      => self::OLD,
            'password'              => self::NEW,
            'password_confirmation' => self::NEW,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertNotNull(session('password_success'), 'the controller did not report success');

        // The assertions that actually settle it.
        $this->assertTrue(
            $this->credentialsWork($admin, self::NEW),
            'the NEW password does not authenticate — the change did not take effect'
        );
        $this->assertFalse(
            $this->credentialsWork($admin, self::OLD),
            'the OLD password still authenticates — the change did not replace anything'
        );

        // And prove it through the real login form, not just the hash.
        Auth::guard('admin')->logout();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => self::OLD]);
        $this->assertFalse(Auth::guard('admin')->check(), 'the old password still logs in through the real form');

        $this->post('/admin/login', ['email' => $admin->email, 'password' => self::NEW]);
        $this->assertTrue(
            Auth::guard('admin')->check(),
            'the new password does not log in through the real form'
        );
    }

    /**
     * The password must be stored hashed, never in a recoverable form. This is
     * the property that makes "the admin cannot view a staff password" a fact
     * about the data rather than a UI choice.
     */
    public function test_the_stored_password_is_hashed_and_not_recoverable(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')->put('/admin/account/password', [
            'current_password'      => self::OLD,
            'password'              => self::NEW,
            'password_confirmation' => self::NEW,
        ]);

        $stored = (string) $admin->fresh()->password;

        $this->assertStringNotContainsString(self::NEW, $stored, 'the plaintext password is in the stored value');
        $this->assertStringNotContainsString(self::OLD, $stored);
        $this->assertMatchesRegularExpression('/^\$2y\$/', $stored, 'the password is not a bcrypt hash');
        $this->assertTrue(Hash::check(self::NEW, $stored), 'the hash does not verify the password it should');
    }

    // ══════════════════════════════════════════════════════════════════
    // Refusals — each paired with proof the password did NOT change
    // ══════════════════════════════════════════════════════════════════

    public function test_a_wrong_current_password_is_refused_and_changes_nothing(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'admin')->put('/admin/account/password', [
            'current_password'      => 'NotTheCurrentOne!1',
            'password'              => self::NEW,
            'password_confirmation' => self::NEW,
        ]);

        $response->assertSessionHasErrors('current_password');

        $this->assertTrue($this->credentialsWork($admin, self::OLD), 'the old password should still be valid');
        $this->assertFalse(
            $this->credentialsWork($admin, self::NEW),
            'the password was changed despite a wrong current password'
        );
    }

    public function test_a_too_short_new_password_is_refused_and_changes_nothing(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'admin')->put('/admin/account/password', [
            'current_password'      => self::OLD,
            'password'              => 'Ab!1',      // 4 characters
            'password_confirmation' => 'Ab!1',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue($this->credentialsWork($admin, self::OLD));
        $this->assertFalse($this->credentialsWork($admin, 'Ab!1'));
    }

    public function test_a_mismatched_confirmation_is_refused_and_changes_nothing(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'admin')->put('/admin/account/password', [
            'current_password'      => self::OLD,
            'password'              => self::NEW,
            'password_confirmation' => 'Different!Passw0rd789',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue($this->credentialsWork($admin, self::OLD));
        $this->assertFalse($this->credentialsWork($admin, self::NEW));
    }

    /**
     * The policy also refuses a new password equal to the current one, and one
     * that meets the length but not the complexity rules. Both are part of
     * PasswordPolicy and are pinned so a future weakening is visible.
     */
    public function test_a_weak_or_unchanged_new_password_is_refused(): void
    {
        $admin = $this->makeAdmin();

        // Same as current.
        $this->actingAs($admin, 'admin')->put('/admin/account/password', [
            'current_password'      => self::OLD,
            'password'              => self::OLD,
            'password_confirmation' => self::OLD,
        ])->assertSessionHasErrors('password');

        // Long enough, but no symbol / no number / no mixed case.
        $this->actingAs($admin, 'admin')->put('/admin/account/password', [
            'current_password'      => self::OLD,
            'password'              => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($this->credentialsWork($admin, self::OLD), 'nothing should have changed');
    }
}

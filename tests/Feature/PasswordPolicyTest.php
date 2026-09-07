<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Manual security review 2026-08-31, item 4b.
 *
 * The reviewer set a real account's password to `123456` and to `simon123`
 * through the reset flow and signed in with both. There was no complexity
 * requirement anywhere in the app.
 *
 * This covers EVERY place a password can be set, because a policy that is
 * enforced in four of five places is not a policy. Each flow is checked twice:
 * a weak password must be refused with a message that names what is missing,
 * and a compliant one must be accepted and actually work afterwards.
 *
 * DatabaseTransactions: every row these tests create is rolled back.
 */
class PasswordPolicyTest extends TestCase
{
    use DatabaseTransactions;

    /** Fails every complexity requirement except length. */
    private const WEAK = '12345678';

    /** The two the reviewer actually got accepted before the fix. */
    private const WEAK_REVIEWER_1 = '123456';
    private const WEAK_REVIEWER_2 = 'simon123';

    private const STRONG = 'Staff123!';

    // ───────────────────────── customer registration ─────────────────────────

    public function test_customer_registration_rejects_a_weak_password(): void
    {
        foreach ([self::WEAK, self::WEAK_REVIEWER_1, self::WEAK_REVIEWER_2] as $weak) {
            $response = $this->post(route('customer.register.post'), [
                'name' => 'Weak Password Person',
                'email' => 'weak-' . md5($weak) . '@invalid.local',
                'password' => $weak,
                'password_confirmation' => $weak,
                'contact_number' => '09171234567',
                'terms' => 'on',
            ]);

            $response->assertSessionHasErrors('password');
            $this->assertDatabaseMissing('users', [
                'email' => 'weak-' . md5($weak) . '@invalid.local',
            ]);
        }
    }

    public function test_customer_registration_accepts_a_compliant_password(): void
    {
        $email = 'strong-reg@invalid.local';

        $this->post(route('customer.register.post'), [
            'name' => 'Strong Password Person',
            'email' => $email,
            'password' => self::STRONG,
            'password_confirmation' => self::STRONG,
            'contact_number' => '09171234567',
            'terms' => 'on',
        ])->assertSessionHasNoErrors();

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user, 'the account should have been created');
        $this->assertTrue(
            Hash::check(self::STRONG, $user->password),
            'the stored hash should match the password that was set'
        );
    }

    // ─────────────────────── customer account settings ───────────────────────

    public function test_customer_account_settings_rejects_a_weak_password(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'password' => self::STRONG,
        ]);

        $this->actingAs($customer, 'customer')
            ->put(route('customer.account.update'), [
                'name' => $customer->name,
                'email' => $customer->email,
                'password' => self::WEAK,
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(
            Hash::check(self::STRONG, $customer->fresh()->password),
            'the old password must survive a rejected change'
        );
    }

    public function test_customer_account_settings_accepts_a_compliant_password(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'password' => 'OldPass123!',
        ]);

        $this->actingAs($customer, 'customer')
            ->put(route('customer.account.update'), [
                'name' => $customer->name,
                'email' => $customer->email,
                'password' => self::STRONG,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check(self::STRONG, $customer->fresh()->password));
    }

    public function test_customer_account_settings_still_allows_leaving_the_password_blank(): void
    {
        // Blank means "keep my current password" — the optional() rule must not
        // turn that into a validation failure.
        $customer = User::factory()->create([
            'role' => 'customer',
            'password' => self::STRONG,
        ]);

        $this->actingAs($customer, 'customer')
            ->put(route('customer.account.update'), [
                'name' => 'Renamed Person',
                'email' => $customer->email,
                'password' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Person', $customer->fresh()->name);
        $this->assertTrue(Hash::check(self::STRONG, $customer->fresh()->password));
    }

    // ──────────────────────── admin creating a staff account ─────────────────

    public function test_admin_creating_staff_rejects_a_weak_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $branchId = DB::table('branches')->value('id');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.store'), [
                'name' => 'Weak Staff',
                'email' => 'weak-staff@invalid.local',
                'branch_id' => $branchId,
                'password' => self::WEAK,
                'password_confirmation' => self::WEAK,
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'weak-staff@invalid.local']);
    }

    public function test_admin_creating_staff_accepts_a_compliant_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $branchId = DB::table('branches')->value('id');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.store'), [
                'name' => 'Strong Staff',
                'email' => 'strong-staff@invalid.local',
                'branch_id' => $branchId,
                'password' => self::STRONG,
                'password_confirmation' => self::STRONG,
            ])
            ->assertSessionHasNoErrors();

        $staff = User::where('email', 'strong-staff@invalid.local')->first();
        $this->assertNotNull($staff);
        $this->assertTrue(Hash::check(self::STRONG, $staff->password));
    }

    // ───────────────────── admin changing their own password ─────────────────

    public function test_admin_changing_own_password_rejects_a_weak_one(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'password' => 'CurrentPass1!',
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.account.password.update'), [
                'current_password' => 'CurrentPass1!',
                'password' => self::WEAK,
                'password_confirmation' => self::WEAK,
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('CurrentPass1!', $admin->fresh()->password));
    }

    public function test_admin_changing_own_password_accepts_a_compliant_one(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'password' => 'CurrentPass1!',
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.account.password.update'), [
                'current_password' => 'CurrentPass1!',
                'password' => self::STRONG,
                'password_confirmation' => self::STRONG,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check(self::STRONG, $admin->fresh()->password));
    }

    // ───────────────────────── the password RESET flow ───────────────────────
    // This is the one the reviewer actually exploited manually.

    public function test_admin_reset_flow_rejects_the_passwords_the_reviewer_got_accepted(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'password' => 'CurrentPass1!',
        ]);

        foreach ([self::WEAK_REVIEWER_1, self::WEAK_REVIEWER_2, self::WEAK] as $weak) {
            $this->primeResetState('admin_password_reset', $admin->email);

            $this->post(route('admin.new-password.post'), [
                'password' => $weak,
                'password_confirmation' => $weak,
            ])->assertSessionHasErrors('password');

            $this->assertTrue(
                Hash::check('CurrentPass1!', $admin->fresh()->password),
                "reset must not have changed the password to '{$weak}'"
            );
        }
    }

    public function test_admin_reset_flow_accepts_a_compliant_password(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'password' => 'CurrentPass1!',
        ]);

        $this->primeResetState('admin_password_reset', $admin->email);

        $this->post(route('admin.new-password.post'), [
            'password' => self::STRONG,
            'password_confirmation' => self::STRONG,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(
            Hash::check(self::STRONG, $admin->fresh()->password),
            'the compliant password should have been set'
        );
    }

    public function test_customer_reset_flow_rejects_a_weak_password(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'password' => 'CurrentPass1!',
        ]);

        $this->primeResetState('customer_password_reset', $customer->email);

        $this->post(route('customer.new-password.post'), [
            'password' => self::WEAK,
            'password_confirmation' => self::WEAK,
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('CurrentPass1!', $customer->fresh()->password));
    }

    // ──────────────────────── the message must be specific ───────────────────

    public function test_the_error_names_the_missing_requirement(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->primeResetState('admin_password_reset', $admin->email);

        // 'abcdefgh' is long enough and lower case only: it is missing an
        // uppercase letter, a number and a symbol.
        $response = $this->post(route('admin.new-password.post'), [
            'password' => 'abcdefgh',
            'password_confirmation' => 'abcdefgh',
        ]);

        $errors = session('errors')->get('password');
        $joined = strtolower(implode(' ', $errors));

        $this->assertStringNotContainsString('invalid password', $joined);
        $this->assertTrue(
            str_contains($joined, 'uppercase')
            || str_contains($joined, 'number')
            || str_contains($joined, 'symbol'),
            'the message should name what is missing, got: ' . $joined
        );
    }

    /**
     * Put a session into the state the reset flow reaches after a code has been
     * emailed and verified, so updatePassword() runs its validation instead of
     * bouncing back to step one.
     */
    private function primeResetState(string $sessionKey, string $email): void
    {
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $this->withSession([
            $sessionKey . '.email' => $email,
            $sessionKey . '.verified' => true,
        ]);
    }
}

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
 * QA, Nov 2 2026: "When I used forgot password and set a new one, it still
 * accepted it even though it was the same as my old password."
 *
 * Reproduced before the fix: reach the last step of either portal's reset with
 * the account's CURRENT password, submit that same password -> redirect to
 * login with "Your password has been updated", the code row consumed and the
 * reset closed. updatePassword() only ran PasswordPolicy::required(), which
 * says nothing about the current password. (Admin Account Settings already had
 * `different:current_password`; the reset, customer Account Settings and the
 * owner's Set Password did not.)
 *
 * The rule, everywhere somebody sets THEIR OWN password: the new one must
 * differ from the current one. Refusing it is a rule failure like any other
 * (PasswordResetKeepsGoingTest): it never ends the reset, uses up the code or
 * counts against the six-a-minute budget.
 *
 * Set Password by the owner/manager for someone else is the exception to the
 * wording, not the rule: that person does not know the old password, so the
 * only thing refused is a password whose hash is the one already stored.
 */
class NewPasswordMustDifferTest extends TestCase
{
    use DatabaseTransactions;
    use OneBrowser;

    private const OLD  = 'Differ!OldOne1';
    private const NEW  = 'Differ!NewOne2';
    private const CODE = '123456';

    private const SAME_MESSAGE = 'Your new password must be different from your current password.';

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

    private function account(string $role): User
    {
        return User::create([
            'name'              => 'Differ ' . ucfirst($role),
            'email'             => 'differ-' . $role . '-' . Str::lower(Str::random(12)) . '@invalid.local',
            'password'          => self::OLD,
            'role'              => $role,
            'is_active'         => true,
            'branch_id'         => $role === 'customer' ? null : 1,
            'contact_number'    => '09170000000',
            'email_verified_at' => now(),
        ]);
    }

    private function reachNewPassword(User $user, string $portal): void
    {
        $this->browser(fn () => $this->post("/{$portal}/forgot-password", ['email' => $user->email]))
            ->assertRedirect(route("{$portal}.verification"));

        DB::table('password_reset_tokens')->where('email', $user->email)->update(['token' => Hash::make(self::CODE)]);

        $this->browser(fn () => $this->post("/{$portal}/verification", ['otp' => str_split(self::CODE)]))
            ->assertRedirect(route("{$portal}.new-password"));
    }

    private function submit(string $portal, string $password): TestResponse
    {
        return $this->browser(fn () => $this->post("/{$portal}/new-password", [
            'password'              => $password,
            'password_confirmation' => $password,
        ]));
    }

    private function passwordIs(User $user, string $password): bool
    {
        return Hash::check($password, $user->fresh()->password);
    }

    // ══════════ forgot password (both portals) ══════════

    /** @dataProvider portals */
    public function test_reset_refuses_the_current_password(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        $response = $this->submit($portal, self::OLD);

        $response->assertRedirect()->assertSessionHasErrors(['password' => self::SAME_MESSAGE]);
        $this->assertTrue($this->passwordIs($user, self::OLD));
    }

    /** @dataProvider portals */
    public function test_refusing_it_does_not_end_the_reset_or_use_up_the_code(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);
        $sessionId = $this->jar['tester'];

        foreach (range(1, 4) as $attempt) {
            $this->submit($portal, self::OLD)->assertSessionHasErrors('password');

            $this->assertTrue(
                DB::table('password_reset_tokens')->where('email', $user->email)->exists(),
                "the code row was used up by refused attempt #{$attempt}"
            );
            $this->assertTrue(
                (bool) ($this->sessionData()["{$portal}_password_reset"]['verified'] ?? false),
                "the verified step was lost on refused attempt #{$attempt}"
            );
        }

        $this->assertSame($sessionId, $this->jar['tester'], 'a refused password regenerated the session');

        // The same code and session still carry a DIFFERENT password through.
        $this->submit($portal, self::NEW)->assertRedirect(route("{$portal}.login"));
        $this->assertTrue($this->passwordIs($user, self::NEW));
    }

    /** @dataProvider portals */
    public function test_it_never_reaches_the_six_per_minute_limit_like_any_other_rule_failure(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        foreach (range(1, 25) as $attempt) {
            $this->assertNotSame(429, $this->submit($portal, self::OLD)->getStatusCode(), "refusal #{$attempt} was throttled");
        }

        $this->submit($portal, self::NEW)->assertRedirect(route("{$portal}.login"));
    }

    /** @dataProvider portals */
    public function test_a_flood_of_them_is_still_capped(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        foreach (range(1, \App\Providers\RateLimitServiceProvider::RESET_PASSWORD_RULE_FAILURES_PER_IP) as $attempt) {
            $this->assertNotSame(429, $this->submit($portal, self::OLD)->getStatusCode(), "throttled early, at #{$attempt}");
        }

        $this->submit($portal, self::OLD)->assertStatus(429);
    }

    /** @dataProvider portals */
    public function test_the_policy_message_still_wins_for_a_weak_password(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        $this->submit($portal, 'abcdefgh')->assertSessionHasErrors('password');

        $this->assertNotSame(self::SAME_MESSAGE, session('errors')->first('password'));
    }

    /** @dataProvider portals */
    public function test_a_page_tells_the_person_up_front(string $portal): void
    {
        $user = $this->account($portal);
        $this->reachNewPassword($user, $portal);

        $html = $this->browser(fn () => $this->get("/{$portal}/new-password"))->assertOk()->getContent();

        $this->assertStringContainsString('different from your current password', $html);
        // The live checklist is untouched: it cannot tick this one, because the
        // page must not know (or reveal) the current password.
        $this->assertStringContainsString('id="pwChecklist"', $html);
    }

    // ══════════ customer Account Settings > Security ══════════

    private function customerUpdate(User $user, array $overrides): TestResponse
    {
        return $this->actingAs($user, 'customer')
            ->from('/customer/account')
            ->put('/customer/account', array_merge([
                'name'           => $user->name,
                'email'          => $user->email,
                'contact_number' => $user->contact_number,
                'address'        => $user->address,
            ], $overrides));
    }

    public function test_customer_settings_refuse_the_current_password_as_the_new_one(): void
    {
        $user = $this->account('customer');

        $this->customerUpdate($user, [
            'name'             => 'Should Not Stick',
            'password'         => self::OLD,
            'current_password' => self::OLD,
        ])->assertSessionHasErrors(['password' => self::SAME_MESSAGE]);

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check(self::OLD, $fresh->password));
        $this->assertSame('Differ Customer', $fresh->name, 'a refused update must save nothing');
    }

    public function test_customer_settings_still_accept_a_different_password(): void
    {
        $user = $this->account('customer');

        $this->customerUpdate($user, [
            'password'         => self::NEW,
            'current_password' => self::OLD,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->passwordIs($user, self::NEW));
    }

    public function test_customer_settings_leave_a_blank_password_alone(): void
    {
        $user = $this->account('customer');

        $this->customerUpdate($user, ['name' => 'Renamed', 'password' => ''])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $user->fresh()->name);
        $this->assertTrue($this->passwordIs($user, self::OLD));
    }

    public function test_a_wrong_current_password_is_still_reported_as_wrong_not_as_the_same(): void
    {
        $user = $this->account('customer');

        $this->customerUpdate($user, [
            'password'         => self::OLD,
            'current_password' => 'Nope!Nope123',
        ])->assertSessionHasErrors(['current_password' => 'That is not your current password.']);
    }

    // ══════════ admin / staff Account Settings ══════════

    /** @dataProvider roles */
    public function test_admin_account_settings_use_the_same_sentence(string $role): void
    {
        $user = $this->account($role);

        $this->actingAs($user, 'admin')->from('/admin/account')
            ->put('/admin/account/password', [
                'current_password'      => self::OLD,
                'password'              => self::OLD,
                'password_confirmation' => self::OLD,
            ])->assertSessionHasErrors(['password' => self::SAME_MESSAGE]);

        $this->assertTrue($this->passwordIs($user, self::OLD));
    }

    public static function roles(): array
    {
        return ['admin' => ['admin'], 'supervisor' => ['supervisor'], 'staff' => ['staff']];
    }

    // ══════════ owner / manager "Set Password" for someone else ══════════

    public function test_set_password_refuses_only_a_password_that_is_already_the_stored_one(): void
    {
        $owner = $this->account('admin');
        $staff = $this->account('staff');

        $this->actingAs($owner, 'admin')->from('/admin/users')
            ->put("/admin/users/{$staff->id}/password", [
                'password'              => self::OLD,
                'password_confirmation' => self::OLD,
            ])->assertSessionHasErrors('password');

        $this->assertTrue($this->passwordIs($staff, self::OLD));
        $this->assertSame($staff->remember_token, $staff->fresh()->remember_token, 'a refused Set Password must not sign the person out');
    }

    public function test_set_password_still_sets_a_different_password_without_asking_for_the_old_one(): void
    {
        $owner = $this->account('admin');
        $staff = $this->account('staff');

        $this->actingAs($owner, 'admin')->from('/admin/users')
            ->put("/admin/users/{$staff->id}/password", [
                'password'              => self::NEW,
                'password_confirmation' => self::NEW,
            ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertTrue($this->passwordIs($staff, self::NEW));
    }
}

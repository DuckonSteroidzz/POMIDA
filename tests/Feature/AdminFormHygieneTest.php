<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Admin form hygiene: no "e.g. …" placeholder clutter on the Branches / Ads /
 * Account forms, browser autofill suppressed on the auth-adjacent forms, and
 * the shared password policy stated up front wherever a password is set.
 *
 * None of this changes what the server accepts — PasswordPolicy is unchanged
 * and still the single source of truth. These assertions pin the markup so the
 * clutter cannot quietly creep back.
 */
class AdminFormHygieneTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function html(string $uri): string
    {
        return $this->actingAs($this->admin(), 'admin')->get($uri)->assertOk()->getContent();
    }

    // ── placeholder clutter is gone ────────────────────────────────────────

    /**
     * @dataProvider clutterPages
     */
    public function test_no_eg_placeholder_clutter(string $uri): void
    {
        $html = $this->html($uri);

        $this->assertDoesNotMatchRegularExpression(
            '/placeholder="e\.g\.\s/i',
            $html,
            $uri . ' still has "e.g. …" placeholder clutter'
        );
    }

    public static function clutterPages(): array
    {
        return [
            'branches' => ['/admin/branches'],
            'ads'      => ['/admin/ads'],
            'users'    => ['/admin/users'],
        ];
    }

    public function test_the_branch_form_inputs_are_clean(): void
    {
        $html = $this->html('/admin/branches');

        // The specific hint placeholders that were removed.
        foreach (['e.g. Branch 2', 'e.g. BR2', '123 Main St.', 'branch@peachy.com', '09XX-XXX-XXXX'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
    }

    // ── autofill is ALLOWED on the login form ──────────────────────────────
    //
    // The login form is the one auth-adjacent form where standard browser
    // autofill / password-manager save is wanted: staff and the owner sign in
    // here repeatedly, and Chrome should be able to offer the saved password.
    // Autofill stays suppressed on Branches / Staff Accounts / Account Settings
    // (asserted below) — those are forms where an admin fills in someone
    // else's details and a helpful autofill is a hindrance.

    public function test_the_admin_login_form_allows_browser_autofill(): void
    {
        $html = $this->html('/admin/login'); // public, but acting-as is harmless

        // Standard autocomplete tokens so Chrome saves and offers the password.
        $this->assertMatchesRegularExpression('/name="email"[^>]*autocomplete="username"/s', $html);
        $this->assertMatchesRegularExpression('/name="password"[^>]*autocomplete="current-password"/s', $html);

        // And none of the autofill-suppression attributes remain on it.
        $this->assertDoesNotMatchRegularExpression('/name="email"[^>]*autocomplete="off"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/name="password"[^>]*(autocomplete="off"|data-lpignore="true")/s', $html);
    }

    // ── account change-password ───────────────────────────────────────────

    public function test_the_account_password_form_states_the_policy_and_kills_autofill(): void
    {
        $html = $this->html('/admin/account');

        $this->assertStringContainsString(PasswordPolicy::describe(), $html);

        // current password must not trigger the Chrome password manager popup.
        $this->assertMatchesRegularExpression(
            '/name="current_password"[^>]*(autocomplete="off"|data-lpignore="true")/s',
            $html
        );
        // new + confirm keep new-password and also carry the lpignore hint.
        $this->assertMatchesRegularExpression('/name="password"[^>]*data-lpignore="true"/s', $html);
        $this->assertMatchesRegularExpression('/name="password_confirmation"[^>]*data-lpignore="true"/s', $html);
    }

    // ── staff creation form ───────────────────────────────────────────────

    public function test_the_create_staff_form_states_the_policy(): void
    {
        $html = $this->html('/admin/users');

        $this->assertStringContainsString(PasswordPolicy::describe(), $html);
        $this->assertMatchesRegularExpression('/name="name"[^>]*autocomplete="off"/s', $html);
        $this->assertMatchesRegularExpression('/name="email"[^>]*autocomplete="off"/s', $html);
    }

    // ── the policy itself is unchanged and still enforced ─────────────────

    public function test_the_password_policy_still_rejects_a_weak_password_end_to_end(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->from('/admin/account')
            ->put('/admin/account/password', [
                'current_password'      => 'irrelevant-will-fail-complexity',
                'password'              => 'password',           // no upper, number or symbol
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('password');
    }
}

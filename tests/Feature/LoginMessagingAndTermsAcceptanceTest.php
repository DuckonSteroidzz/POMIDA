<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Bundle: login-redirect messaging clarity + Terms-of-Service acceptance loop.
 *
 * ── Issue A — which portal does this login page send me back to? ─────────────
 * One login form (admin/login.blade.php) serves Admin, Staff and Supervisor.
 * A bare "please login to access the staff portal" tells a bounced user
 * nothing. What is honestly knowable at each redirect point:
 *
 *   - Unauthenticated (fresh visit OR expired session — same code path) hitting
 *     a protected route: the PREVIOUS user's role is gone, but the destination
 *     route's `role:` filter is on its gathered middleware. So when the target
 *     is narrower than the whole portal we can name the access required; when
 *     it is open to all three portal roles we cannot, and stay generic.
 *   - Voluntary logout: the role IS still readable the instant before the guard
 *     is cleared, so the logged-out notice names it.
 *
 * These tests assert exactly that and nothing that needs data we do not have.
 *
 * ── Issue B — "Accept & Continue" looped instead of closing ──────────────────
 * The ToS modal shows via `.modal:target`. The old acceptTerms() cleared the
 * fragment with history.replaceState() (which does NOT clear :target) then did
 * a display:none / setTimeout(display:'') dance that let the still-matched
 * modal snap back open. Fixed by pointing the fragment at a non-matching id.
 * A JS bug, so the guard here is (a) the reopening dance is gone from the
 * handler and (b) an end-to-end feature test proving registration completes in
 * one pass once terms are accepted.
 *
 * DATA HYGIENE
 * Everything runs inside DatabaseTransactions and every account minted carries
 * the LMTA prefix, so nothing survives the run and no high-water cleanup is
 * needed. No pre-existing row is read for mutation and nothing live is deleted.
 */
class LoginMessagingAndTermsAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = 'LMTA';

    private function account(string $role): User
    {
        return User::create([
            'name'      => self::PREFIX . ' ' . ucfirst($role),
            'email'     => strtolower(self::PREFIX) . '-' . $role . '-' . uniqid() . '@example.test',
            'password'  => 'Aa1!aaaaaa',
            'role'      => $role,
            'branch_id' => $role === 'admin' ? null : 1,
            'is_active' => true,
        ]);
    }

    // ── Issue A: unauthenticated → protected route ──────────────────────────

    public function test_bounce_from_an_admin_only_route_names_administrator_access(): void
    {
        $this->get(route('admin.branches'))
            ->assertRedirect(route('admin.login'));

        $this->followingRedirects()
            ->get(route('admin.branches'))
            ->assertOk()
            ->assertSee('Please sign in with an Administrator account to continue.');
    }

    public function test_bounce_from_an_admin_or_supervisor_route_names_both(): void
    {
        $this->followingRedirects()
            ->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Please sign in with an Administrator or Supervisor account to continue.');
    }

    public function test_bounce_from_a_whole_portal_route_stays_generic(): void
    {
        // admin.home is open to admin, staff AND supervisor — naming a role
        // here would tell the user nothing, so the message must not try.
        $this->followingRedirects()
            ->get(route('admin.home'))
            ->assertOk()
            ->assertSee('Please login to access the staff portal.')
            ->assertDontSee('Please sign in with');
    }

    // ── Issue A: voluntary logout names the role just left ──────────────────

    /**
     * @dataProvider portalRoles
     */
    public function test_voluntary_logout_names_the_role(string $role, string $label): void
    {
        $user = $this->account($role);

        $this->actingAs($user, 'admin')
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertSame(
            "You have been logged out of your {$label} account.",
            session('success')
        );
    }

    public static function portalRoles(): array
    {
        return [
            'admin'      => ['admin', 'Administrator'],
            'staff'      => ['staff', 'Staff'],
            'supervisor' => ['supervisor', 'Supervisor'],
        ];
    }

    // ── Issue B: the reopening dance is gone from the handler ───────────────

    public function test_accept_terms_handler_no_longer_reopens_the_modal(): void
    {
        $html = $this->get(route('customer.register'))->assertOk()->getContent();

        $handler = substr(
            $html,
            strpos($html, 'function acceptTerms()'),
            600
        );

        // It still ticks the real checkbox the form requires...
        $this->assertStringContainsString("getElementById('agreeTerms')", $handler);
        $this->assertStringContainsString('checkbox.checked = true', $handler);

        // ...but the loop-causing display toggle + setTimeout re-show are gone.
        $this->assertStringNotContainsString('setTimeout', $handler);
        $this->assertStringNotContainsString("style.display = ''", $handler);
        $this->assertStringNotContainsString("style.display = 'none'", $handler);
    }

    // ── Issue B: registration completes end-to-end after accepting terms ────

    public function test_registration_completes_in_one_pass_once_terms_accepted(): void
    {
        $email = strtolower(self::PREFIX) . '-signup-' . uniqid() . '@example.test';

        $response = $this->post(route('customer.register.post'), [
            'name'                  => self::PREFIX . ' New Customer',
            'email'                 => $email,
            'password'              => 'Aa1!aaaaaa',
            'password_confirmation' => 'Aa1!aaaaaa',
            'contact_number'        => '09171234567',
            'address'               => self::PREFIX . ' 1 Test St',
            // exactly what acceptTerms() sets: the checkbox ticked
            'terms'                 => '1',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('customer.menu'));

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'role'  => 'customer',
        ]);
        $this->assertAuthenticatedAs(User::where('email', $email)->first(), 'customer');
    }

    public function test_registration_still_refuses_when_terms_not_accepted(): void
    {
        $response = $this->post(route('customer.register.post'), [
            'name'                  => self::PREFIX . ' No Terms',
            'email'                 => strtolower(self::PREFIX) . '-noterms-' . uniqid() . '@example.test',
            'password'              => 'Aa1!aaaaaa',
            'password_confirmation' => 'Aa1!aaaaaa',
            'contact_number'        => '09171234567',
        ]);

        $response->assertSessionHasErrors('terms');
    }
}

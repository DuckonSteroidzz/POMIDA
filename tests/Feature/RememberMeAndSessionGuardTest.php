<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * "Remember me" actually remembers, and a background request never dies of a
 * stale CSRF token.
 *
 * THREE REPORTS, ONE ROOT AREA
 * ----------------------------
 * 1. Ticking "Remember me" made no difference — closing the browser meant
 *    signing in again.
 * 2. Screens left open came back as a raw 419 "Page Expired" card.
 * 3. Busy shifts produced 429s from screens nobody was touching.
 *
 * What was actually true before this pass, verified rather than assumed:
 *
 *   - Both login controllers ALREADY passed a remember flag to attempt(). The
 *     flag came from $request->has('remember'), which is true for any present
 *     value including "0" — wrong in the other direction, but not the reported
 *     bug. What is asserted here is the behaviour itself (does a recaller
 *     cookie come back), not the shape of the call, so this test would have
 *     caught a genuinely missing flag and will catch one if it is dropped.
 *
 *   - No page in the application rendered <meta name="csrf-token">, even
 *     though admin/home.blade.php read .content off it for the manual-order
 *     voucher preview. That querySelector returned null and threw. Every
 *     fetch() instead carried a token baked in by Blade at render time, which
 *     is stale the moment the session is regenerated — the real 419.
 *
 * The 419-to-reload handler and the fetch wrapper are browser behaviour and
 * cannot be executed here; what CAN be pinned server-side is that the meta tag
 * and the guard script are actually present on the pages that need them, which
 * is the part that silently regresses when a new page is added.
 */
class RememberMeAndSessionGuardTest extends TestCase
{
    use DatabaseTransactions;

    private const ADMIN_PW    = 'RememberAdmin!1';
    private const CUSTOMER_PW = 'RememberCust!1';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('admin-login');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function makeAdmin(): User
    {
        return User::create([
            'name'      => 'Remember Test Admin',
            'email'     => 'rm-admin-' . uniqid() . '@invalid.local',
            'password'  => self::ADMIN_PW,
            'role'      => 'admin',
            'is_active' => true,
            'branch_id' => 1,
        ]);
    }

    private function makeCustomer(): User
    {
        return User::create([
            'name'      => 'Remember Test Customer',
            'email'     => 'rm-cust-' . uniqid() . '@invalid.local',
            'password'  => self::CUSTOMER_PW,
            'role'      => 'customer',
            'is_active' => true,
        ]);
    }

    /**
     * The cookie Laravel issues for a remembered session. Its name is derived
     * from the GUARD name, so admin and customer get separate cookies and a
     * remembered cashier is not also a remembered diner.
     */
    private function recallerName(string $guard): string
    {
        return Auth::guard($guard)->getRecallerName();
    }

    // ══════════ remember me ══════════

    public function test_admin_login_with_remember_issues_a_persistent_cookie(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->post('/admin/login', [
            'email'    => $admin->email,
            'password' => self::ADMIN_PW,
            'remember' => '1',
        ]);

        $response->assertCookie($this->recallerName('admin'));

        // A recaller is only usable if the token was persisted alongside it.
        $this->assertNotEmpty(
            $admin->fresh()->remember_token,
            'the recaller cookie was set but no remember_token was stored, so it can never authenticate anyone'
        );
    }

    public function test_admin_login_without_remember_issues_no_persistent_cookie(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->post('/admin/login', [
            'email'    => $admin->email,
            'password' => self::ADMIN_PW,
        ]);

        $response->assertCookieMissing($this->recallerName('admin'));
        $this->assertTrue(Auth::guard('admin')->check(), 'CONTROL: the login itself must have worked');
    }

    /**
     * The half has() got wrong. A browser omits an unchecked box, but a form
     * that posts an explicit remember=0 (or any client that does) must be
     * taken at its word rather than remembered anyway.
     */
    public function test_an_explicit_remember_zero_is_not_treated_as_checked(): void
    {
        $admin = $this->makeAdmin();

        $this->post('/admin/login', [
            'email'    => $admin->email,
            'password' => self::ADMIN_PW,
            'remember' => '0',
        ])->assertCookieMissing($this->recallerName('admin'));
    }

    public function test_customer_login_with_remember_issues_a_persistent_cookie(): void
    {
        $customer = $this->makeCustomer();

        $this->post('/customer/login', [
            'email'    => $customer->email,
            'password' => self::CUSTOMER_PW,
            'remember' => '1',
        ])->assertCookie($this->recallerName('customer'));

        $this->assertNotEmpty($customer->fresh()->remember_token);
    }

    public function test_customer_login_without_remember_issues_no_persistent_cookie(): void
    {
        $customer = $this->makeCustomer();

        $this->post('/customer/login', [
            'email'    => $customer->email,
            'password' => self::CUSTOMER_PW,
        ])->assertCookieMissing($this->recallerName('customer'));
    }

    /**
     * The checkbox has to survive a failed login, or a user who mistypes their
     * password silently loses the choice they made.
     */
    public function test_both_login_forms_post_a_remember_value_and_keep_it_on_re_render(): void
    {
        foreach (['admin', 'customer'] as $side) {
            $form = file_get_contents(resource_path("views/{$side}/login.blade.php"));

            $this->assertStringContainsString('name="remember"', $form, "{$side} login lost its remember field");
            $this->assertStringContainsString('value="1"', $form, "{$side} login's remember box posts no explicit value");
            $this->assertStringContainsString("old('remember')", $form, "{$side} login forgets the box on a failed attempt");
        }
    }

    // ══════════ the session guard ══════════

    public function test_an_admin_page_renders_a_live_csrf_meta_tag(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->actingAs($admin, 'admin')->get('/admin/home')->assertOk()->getContent();

        $this->assertStringContainsString('name="csrf-token"', $html);

        // admin/home reads .content off this element for the manual-order
        // voucher preview. Before the tag existed that threw a TypeError and
        // left the Check button disabled forever.
        $this->assertStringContainsString(
            'document.querySelector(\'meta[name="csrf-token"]\').content',
            $html,
            'the manual-order voucher preview no longer reads the meta tag — if it moved, re-point this assertion'
        );

        $this->assertMatchesRegularExpression(
            '/<meta name="csrf-token" content="[A-Za-z0-9]{20,}">/',
            $html,
            'the csrf-token meta rendered without a real token in it'
        );
    }

    public function test_a_customer_page_renders_a_live_csrf_meta_tag(): void
    {
        $html = $this->get('/customer/menu')->assertOk()->getContent();

        $this->assertStringContainsString('name="csrf-token"', $html);
    }

    public function test_the_guard_overrides_stale_tokens_and_reloads_on_419(): void
    {
        $guard = file_get_contents(resource_path('views/partials/session-guard.blade.php'));

        // The whole point: the token goes on the request from the meta tag at
        // send time, not from whatever Blade printed into the page.
        $this->assertStringContainsString("headers.set('X-CSRF-TOKEN', currentToken())", $guard);

        // And a session that has gone anyway ends in a reload, not a 419 card.
        $this->assertStringContainsString('response.status === 419', $guard);
        $this->assertStringContainsString('window.location.reload()', $guard);
    }

    /**
     * Every page that talks to the server from JS must include the guard.
     * This is the assertion that catches a NEW page being added without it.
     */
    public function test_every_page_that_uses_fetch_includes_the_session_guard(): void
    {
        $missing = [];

        foreach (glob(resource_path('views/customer/*.blade.php')) as $path) {
            $source = file_get_contents($path);

            if (!str_contains($source, 'fetch(')) {
                continue;
            }

            if (!str_contains($source, "@include('partials.session-guard')")) {
                $missing[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $missing,
            'these customer pages make background requests but do not include partials.session-guard, '
            . 'so their CSRF tokens go stale and they will show a raw 419: ' . implode(', ', $missing)
        );
    }

    public function test_the_admin_layout_carries_the_guard_for_every_admin_page(): void
    {
        $this->assertStringContainsString(
            "@include('partials.session-guard')",
            file_get_contents(resource_path('views/admin/layout.blade.php'))
        );
    }

    // ══════════ session lifetime ══════════

    /**
     * The 419s were not only stale tokens — at the stock 120 minutes the whole
     * session expired inside a single counter shift.
     */
    public function test_the_session_outlasts_a_shift(): void
    {
        $this->assertGreaterThanOrEqual(
            480,
            (int) config('session.lifetime'),
            'session.lifetime dropped back below one 8-hour shift, so idle counter screens will 419 again'
        );
    }
}

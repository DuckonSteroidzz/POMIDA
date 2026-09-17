<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Admin/staff/supervisor mobile investigation (2026-09-14), Symptom A.
 *
 * INVESTIGATION
 * -------------
 * A stakeholder reported the mobile admin portal feels "frozen" navigating
 * between pages (Voucher, Menu, Completed Orders, Home) or logging in — no
 * loading indicator appears at all, so a normal page load looks identical to
 * a hung one. Confirmed by reading admin/layout.blade.php: every admin click
 * is a genuine full-page navigation (see AdminSidebarIconFlickerTest) and
 * nothing on the page ever gave any visual sign a request was in flight.
 *
 * The other three reported symptoms (wrong post-login redirect, a
 * "navigation lock" needing an X closed before a link works, general mobile
 * slowness) did NOT reproduce here: login always redirects to admin.home for
 * every portal role (admin/staff/supervisor — see the login() 302 target
 * below), and a clean click-through of Vouchers/Menu Items/Completed
 * Orders/Home/Menu Options in a real headless browser at 375px navigated
 * cleanly with no stuck overlay once the mobile sidebar was opened. Those are
 * reported separately rather than assumed fixed by this change.
 *
 * THE FIX
 * -------
 * A thin top bar (#pc-nav-progress) shown via the `beforeunload` event, which
 * fires for every way this portal leaves a page: <a> clicks, <form> submits,
 * and the branch-select dropdown's `window.location.href =` navigation alike.
 * There is nothing to hide it on completion — the browser discards the
 * document. Added to admin/layout.blade.php (the authenticated shell) and
 * separately to admin/login.blade.php and admin/partials/auth-layout.blade.php
 * (the unauthenticated login/password-reset screens, which do not extend the
 * main layout), each using that page's own existing colour tokens.
 */
class AdminNavigationLoadingIndicatorTest extends TestCase
{
    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    public function test_the_authenticated_layout_renders_a_beforeunload_driven_progress_bar(): void
    {
        $html = $this->actingAs($this->staff(), 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="pc-nav-progress"', $html);
        $this->assertStringContainsString("addEventListener('beforeunload'", $html);
        $this->assertStringContainsString('pc-nav-progress-active', $html);
    }

    public function test_the_login_page_renders_its_own_progress_bar(): void
    {
        $html = $this->get('/admin/login')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="pc-nav-progress"', $html);
        $this->assertStringContainsString("addEventListener('beforeunload'", $html);
    }

    public function test_the_password_recovery_shell_renders_its_own_progress_bar(): void
    {
        $html = $this->get('/admin/forgot-password')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="pc-nav-progress"', $html);
        $this->assertStringContainsString("addEventListener('beforeunload'", $html);
    }

    /**
     * Pins the investigation finding for Symptom B (reported wrong post-
     * login redirect to Staff Accounts Creation): every portal role redirects
     * to admin.home, with no role-based branch and no intended-url capture
     * anywhere in AdminAuthController::login(). If this ever starts failing,
     * a real redirect regression exists and Symptom B needs re-opening.
     */
    public function test_login_redirects_every_portal_role_to_admin_home(): void
    {
        foreach (['admin', 'staff', 'supervisor'] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'is_active' => true,
                'branch_id' => $role === 'admin' ? null : 1,
                'password' => bcrypt('password123'),
            ]);

            $this->post('/admin/login', [
                'email' => $user->email,
                'password' => 'password123',
            ])->assertRedirect(route('admin.home'));
        }
    }
}

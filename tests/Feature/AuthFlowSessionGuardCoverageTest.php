<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 3b F7 — 8 full-document auth-flow blades never included
 * partials/session-guard: customer/forgot-password, customer/verification,
 * customer/new-password, customer/register, and their admin equivalents —
 * three via admin/partials/auth-layout (forgot-password, verification,
 * new-password) plus one standalone page.
 *
 * That fourth admin page is admin/bootstrap.blade.php (GET /admin/bootstrap,
 * the live "create the first admin" form), NOT admin/register.blade.php as
 * the finding's prose suggested — AdminAuthController::showRegister() /
 * admin.register has no route pointing at it at all (a leftover from a
 * removed self-service-signup feature). Both got the fix, since it is
 * harmless either way, but this test asserts the one that is actually
 * reachable.
 *
 * Same shape as RememberMeAndSessionGuardTest's existing coverage tests: a
 * source-content check per shared partial/page, plus a rendered-HTML check
 * confirming the include actually reaches the page a browser gets.
 */
class AuthFlowSessionGuardCoverageTest extends TestCase
{
    use DatabaseTransactions;

    public static function editedSourceFilesProvider(): array
    {
        return [
            'customer auth-shell (forgot-password/verification/new-password)' =>
                ['views/customer/partials/auth-shell.blade.php'],
            'admin auth-layout (forgot-password/verification/new-password)' =>
                ['views/admin/partials/auth-layout.blade.php'],
            'customer/register.blade.php' => ['views/customer/register.blade.php'],
            'admin/register.blade.php (unrouted, fixed anyway)' => ['views/admin/register.blade.php'],
            'admin/bootstrap.blade.php (the live equivalent)' => ['views/admin/bootstrap.blade.php'],
        ];
    }

    /** @dataProvider editedSourceFilesProvider */
    public function test_the_file_now_includes_the_session_guard(string $relativePath): void
    {
        $this->assertStringContainsString(
            "@include('partials.session-guard')",
            file_get_contents(resource_path($relativePath)),
            "{$relativePath} still does not include partials.session-guard"
        );
    }

    /**
     * Step 1 of each flow needs no prior session state. Steps 2 and 3
     * (verification, new-password) are gated behind
     * HandlesPasswordReset::showVerification()/showNewPassword() checking
     * `{prefix}_password_reset.email` (and `.verified` for step 3) — without
     * it they redirect to forgot-password rather than rendering, which is why
     * they carry their own session fixture below instead of sharing this
     * provider.
     */
    public static function liveRoutesProvider(): array
    {
        return [
            'customer.forgot-password' => ['/customer/forgot-password'],
            'customer.register'        => ['/customer/register'],
            'admin.forgot-password'    => ['/admin/forgot-password'],
        ];
    }

    /**
     * @dataProvider liveRoutesProvider
     *
     * The rendered page a browser actually receives must carry the guard's
     * meta tag — proof the @include resolved, not just that the source text
     * is present somewhere in the template tree.
     */
    public function test_the_rendered_page_carries_the_csrf_meta_tag(string $uri): void
    {
        $html = $this->get($uri)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<meta name="csrf-token" content="[A-Za-z0-9]{20,}">/',
            $html,
            "{$uri} does not render a live csrf-token meta tag"
        );
    }

    public static function stepTwoAndThreeRoutesProvider(): array
    {
        return [
            'customer.verification' => ['/customer/verification', 'customer_password_reset', false],
            'customer.new-password' => ['/customer/new-password', 'customer_password_reset', true],
            'admin.verification'    => ['/admin/verification', 'admin_password_reset', false],
            'admin.new-password'    => ['/admin/new-password', 'admin_password_reset', true],
        ];
    }

    /** @dataProvider stepTwoAndThreeRoutesProvider */
    public function test_the_rendered_step_two_or_three_page_carries_the_csrf_meta_tag(
        string $uri,
        string $sessionKey,
        bool $verified
    ): void {
        $session = ["{$sessionKey}.email" => 'guard-test@example.test'];
        if ($verified) {
            $session["{$sessionKey}.verified"] = true;
        }

        $html = $this->withSession($session)->get($uri)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<meta name="csrf-token" content="[A-Za-z0-9]{20,}">/',
            $html,
            "{$uri} does not render a live csrf-token meta tag"
        );
    }

    /**
     * admin/bootstrap only renders while zero admins exist — that gate is the
     * control, not something this pass should route around — so its coverage
     * stays a source-content check (above) rather than an HTTP render. This
     * test only pins that the route itself is still reachable in principle
     * (redirects to login once an admin exists, which every other test's
     * seed data guarantees here).
     */
    public function test_admin_bootstrap_route_exists_and_is_gated_once_an_admin_exists(): void
    {
        $response = $this->get('/admin/bootstrap');

        $response->assertRedirect(route('admin.login'));
    }
}

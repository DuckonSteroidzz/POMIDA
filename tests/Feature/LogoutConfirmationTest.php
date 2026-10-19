<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every Log out asks first (item F of the Oct 2026 defect report).
 *
 * The confirmation is an in-page modal, not the browser's confirm(). The logout
 * form itself is untouched (same route, same POST, same CSRF token): the script
 * only holds its submit back until "Log out" is pressed in the modal. These
 * tests pin the markup and wiring; the behaviour (Cancel / Esc / backdrop /
 * keyboard) is proved in a real browser, see the item F report.
 */
class LogoutConfirmationTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): User
    {
        return User::create([
            'name'              => 'Logout Test',
            'email'             => 'logout-' . Str::lower(Str::random(12)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'customer',
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    private function assertModalMarkup(string $html): void
    {
        $this->assertSame(1, substr_count($html, 'id="logoutConfirmModal"'));
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('>Log out?</h2>', $html);
        $this->assertStringContainsString('Are you sure you want to log out?', $html);
        $this->assertStringContainsString('data-logout-cancel', $html);
        $this->assertStringContainsString('data-logout-confirm', $html);
        // In-page, not the browser dialog.
        $this->assertDoesNotMatchRegularExpression('/data-logout-form[^>]*onsubmit=/i', $html);
        $this->assertStringNotContainsString("confirm('Log", $html);
    }

    public function test_the_customer_more_page_logout_form_is_held_for_confirmation(): void
    {
        $html = $this->actingAs($this->customer(), 'customer')
            ->withSession(['branch_id' => 1])
            ->get(route('customer.more'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '#<form action="' . preg_quote(route('customer.logout'), '#') . '" method="POST"[^>]*data-logout-form>#',
            $html,
            'the existing customer logout form must carry the confirmation hook, not be replaced'
        );
        $this->assertModalMarkup($html);
        // Still the same real submit button inside the form.
        $this->assertMatchesRegularExpression('#data-logout-form>.*?type="submit".*?Log Out#s', $html);
    }

    public function test_a_guest_has_no_logout_and_so_no_confirmation_modal(): void
    {
        $html = $this->withSession(['branch_id' => 1])
            ->get(route('customer.more'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-logout-form', $html);
        $this->assertStringNotContainsString('id="logoutConfirmModal"', $html);
    }

    public function test_the_logout_route_itself_is_unchanged_and_still_logs_the_customer_out(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'customer')
            ->post(route('customer.logout'))
            ->assertRedirect();

        $this->assertGuest('customer');
    }

    /**
     * @dataProvider adminRoleEmails
     */
    public function test_the_admin_staff_and_supervisor_sidebar_logout_is_held_for_confirmation(string $role): void
    {
        $user = User::where('role', $role)->where('is_active', true)->firstOrFail();

        $html = $this->actingAs($user, 'admin')
            ->get('/admin/home')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '#<form action="' . preg_quote(route('admin.logout'), '#') . '" method="POST"[^>]*data-logout-form>#',
            $html
        );
        $this->assertModalMarkup($html);
    }

    public static function adminRoleEmails(): array
    {
        return ['admin' => ['admin'], 'staff' => ['staff'], 'supervisor' => ['supervisor']];
    }

    public function test_the_shared_script_cancels_on_esc_and_backdrop_and_submits_the_original_form(): void
    {
        $script = file_get_contents(resource_path('views/partials/logout-confirm-script.blade.php'));

        $this->assertStringContainsString("e.key === 'Escape'", $script);
        $this->assertStringContainsString('e.target === modal', $script);
        $this->assertStringContainsString('HTMLFormElement.prototype.submit.call(form)', $script);
        $this->assertStringContainsString("form[data-logout-form]", $script);
    }
}

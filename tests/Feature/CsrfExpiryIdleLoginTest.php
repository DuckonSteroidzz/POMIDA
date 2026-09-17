<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The idle-login half of the 419/CSRF dead end — see CsrfExpiryDeadEndTest for
 * the dine-in code-entry case this mirrors.
 *
 * A login page (customer or admin/staff) left open past SESSION_LIFETIME and
 * then submitted throws TokenMismatchException. Both login blades now poll a
 * read-only session-token endpoint (customer.session-token / admin.session-
 * token — the admin one added by this pass, the customer one pre-existing) to
 * keep their form's token fresh, so this should rarely fire in practice. It is
 * the belt-and-braces path for when it still does (a backgrounded tab, JS
 * disabled): bootstrap/app.php now redirects a stale login submission back to
 * the same form with a message in the SAME $errors alert box the form already
 * renders for a wrong password, instead of the raw branded 419 card. An
 * XHR/fetch caller gets a JSON 419 instead of a redirect it cannot follow.
 *
 * CSRF is skipped for the whole suite by default — see CsrfProtectionTest's
 * docblock — so the negative cases below force it back on with enforceCsrf(),
 * exactly the way CsrfExpiryDeadEndTest does.
 */
class CsrfExpiryIdleLoginTest extends TestCase
{
    use DatabaseTransactions;

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

    private function enforceCsrf(): void
    {
        $this->app['env'] = 'production';
        $this->assertFalse($this->app->runningUnitTests(), 'CSRF still skipped — this test would prove nothing');
    }

    // ── Session-token endpoints (keep-alive) ─────────────────────────────

    public function test_admin_session_token_endpoint_is_read_only(): void
    {
        $res = $this->getJson('/admin/session-token');

        $res->assertOk();
        $this->assertIsString($res->json('token'));
        $this->assertNotSame('', $res->json('token'));

        // It must not touch admin auth state.
        $this->assertGuest('admin');
    }

    // ── A stale token on the login form redirects, never the 419 card ───

    public function test_a_stale_token_on_customer_login_redirects_back_with_a_flash_message_not_419(): void
    {
        $this->enforceCsrf();

        $res = $this->from('/customer/login')
            ->post('/customer/login', ['email' => 'nobody@invalid.local', 'password' => 'whatever']); // no _token

        $res->assertStatus(302);
        $res->assertRedirect('/customer/login');
        $res->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'session expired',
            strtolower(session('errors')->first('email')),
            'the flash message does not read as a session-expired notice'
        );
    }

    public function test_a_stale_token_on_admin_login_redirects_back_with_a_flash_message_not_419(): void
    {
        $this->enforceCsrf();

        $res = $this->from('/admin/login')
            ->post('/admin/login', ['email' => 'nobody@invalid.local', 'password' => 'whatever']); // no _token

        $res->assertStatus(302);
        $res->assertRedirect('/admin/login');
        $res->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'session expired',
            strtolower(session('errors')->first('email')),
            'the flash message does not read as a session-expired notice'
        );
    }

    // ── The same case over AJAX gets JSON, not a redirect ────────────────

    public function test_a_stale_token_on_customer_login_over_ajax_gets_json_419_not_a_redirect(): void
    {
        $this->enforceCsrf();

        $res = $this->postJson('/customer/login', ['email' => 'nobody@invalid.local', 'password' => 'whatever']);

        $res->assertStatus(419);
        $res->assertJson(['success' => false]);
        $this->assertIsString($res->json('message'));
        $this->assertNotSame('', $res->json('message'));
    }

    public function test_a_stale_token_on_admin_login_over_ajax_gets_json_419_not_a_redirect(): void
    {
        $this->enforceCsrf();

        $res = $this->postJson('/admin/login', ['email' => 'nobody@invalid.local', 'password' => 'whatever']);

        $res->assertStatus(419);
        $res->assertJson(['success' => false]);
        $this->assertIsString($res->json('message'));
        $this->assertNotSame('', $res->json('message'));
    }

    // ── Positive controls — a normal login still works unaffected ───────

    public function test_a_normal_customer_login_with_a_valid_token_still_works(): void
    {
        $this->enforceCsrf();

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'password' => 'Customer123!',
        ]);

        $this->get('/customer/login'); // establishes the session + token

        $res = $this->from('/customer/login')->post('/customer/login', [
            '_token' => csrf_token(),
            'email' => $customer->email,
            'password' => 'Customer123!',
        ]);

        $res->assertSessionDoesntHaveErrors();
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_a_normal_admin_login_with_a_valid_token_still_works(): void
    {
        $this->enforceCsrf();

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'password' => 'Staff123!',
        ]);

        $this->get('/admin/login'); // establishes the session + token

        $res = $this->from('/admin/login')->post('/admin/login', [
            '_token' => csrf_token(),
            'email' => $admin->email,
            'password' => 'Staff123!',
        ]);

        $res->assertSessionDoesntHaveErrors();
        $this->assertAuthenticatedAs($admin, 'admin');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\TableEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The two CSRF-expiry dead ends this pass removed.
 *
 *   1. The admin header's branch-"Viewing:" switcher was a POST form. A
 *      completed-orders tab left open past the session lifetime submitted a
 *      dead token and the admin got the raw "419 | PAGE EXPIRED" card. It is a
 *      GET now — an own-session view filter writes nothing, so CSRF has
 *      nothing to protect and cannot be the failure mode.
 *
 *   2. The customer dine-in code-entry form (POST /customer/dineinqr) posts
 *      with a native submit, so the app-wide session guard cannot swap a
 *      fresh token onto it. A slow typist on a long-open tab hit the branded
 *      419 page — a dead end, when every other refusal in this flow
 *      (ERR_QR_STALE, ERR_SESSION_IDLE) redirects back to the same form with
 *      an inline message. Now dineinqr.blade.php polls a read-only token
 *      endpoint to keep the form's token fresh, and a stale token that still
 *      slips through is rendered as that same friendly redirect, never 419.
 *
 *   3. (Sept 2026) A tester who DID land on that branded 419 page — the
 *      belt-and-braces case above still exists for a backgrounded tab whose
 *      token-refresh interval got throttled — hit a LOOP clicking its
 *      "Reload the page" button: repeated attempts surfaced a mix of 419,
 *      500 and 504. Two compounding causes, both fixed here:
 *        a) The 419 page can only ever be reached via a POST (a GET never
 *           fails CSRF), so its reload button called window.location.reload(),
 *           which resubmits that same POST — the browser's native
 *           form-resubmission behaviour. Every resubmission repeated the
 *           exact conditions that produced the page. Fixed by navigating to
 *           the same path instead, which is always a fresh GET.
 *        b) A QueryException during check-in (this dev environment's MySQL
 *           genuinely drops connections sometimes — see laravel.log) used to
 *           fall through to the generic errors.database-unavailable page,
 *           whose only exit is "Back to home" — another dead end for this
 *           flow. It now gets the SAME "back to the form, try again" redirect
 *           as the TokenMismatch case.
 *
 * CSRF is skipped for the whole suite by default (APP_ENV=testing short
 * -circuits the middleware — see CsrfProtectionTest's docblock). The dead-end
 * cases below therefore force it back on with enforceCsrf(), exactly the way
 * CsrfProtectionTest does.
 */
class CsrfExpiryDeadEndTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('table-code:127.0.0.1');
        RateLimiter::clear('qr-scan:127.0.0.1');
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('table-code:127.0.0.1');
        RateLimiter::clear('qr-scan:127.0.0.1');
        parent::tearDown();
    }

    private function enforceCsrf(): void
    {
        $this->app['env'] = 'production';
        $this->assertFalse($this->app->runningUnitTests(), 'CSRF still skipped — this test would prove nothing');
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function permanentCode(int $branchId = 1, string $table = '3'): string
    {
        return TableEntry::findOrRegister($branchId, $table)->code;
    }

    // ── Case 1: admin branch switcher ────────────────────────────────────

    public function test_switching_branch_is_a_get_and_needs_no_csrf_token(): void
    {
        $this->enforceCsrf();

        $branches = Branch::orderBy('id')->take(2)->pluck('id');
        $this->assertCount(2, $branches, 'need at least two branches seeded');

        foreach ($branches as $id) {
            $res = $this->actingAs($this->admin(), 'admin')
                ->from('/admin/completed-orders')
                ->get('/admin/branches/select/' . $id);

            $res->assertRedirect('/admin/completed-orders');
            $this->assertSame((int) $id, session('selected_branch_id'), "branch $id not applied");
        }

        $res = $this->actingAs($this->admin(), 'admin')
            ->from('/admin/completed-orders')
            ->get('/admin/branches/select/all');

        $res->assertRedirect('/admin/completed-orders');
        $this->assertSame('all', session('selected_branch_id'));
    }

    public function test_a_bogus_branch_id_is_ignored_and_the_current_view_is_kept(): void
    {
        session(['selected_branch_id' => 'all']);

        $this->actingAs($this->admin(), 'admin')
            ->from('/admin/inventory')
            ->get('/admin/branches/select/999999')
            ->assertRedirect('/admin/inventory');

        $this->assertSame('all', session('selected_branch_id'), 'a nonexistent branch id changed the scope');
    }

    public function test_the_old_post_route_for_switching_branch_is_gone(): void
    {
        $firstBranch = Branch::orderBy('id')->firstOrFail();

        $res = $this->actingAs($this->admin(), 'admin')
            ->post('/admin/branches/select', ['branch_id' => $firstBranch->id]);

        // 405 (method not allowed) — NOT 419. The dead end is structurally
        // impossible now, not merely handled.
        $this->assertSame(405, $res->getStatusCode());
    }

    public function test_switched_scope_renders_cleanly_on_several_admin_views(): void
    {
        $branch = Branch::orderBy('id')->skip(1)->first() ?? Branch::orderBy('id')->firstOrFail();

        $this->actingAs($this->admin(), 'admin')->get('/admin/branches/select/' . $branch->id);

        foreach (['/admin/completed-orders', '/admin/inventory', '/admin/menu-items'] as $view) {
            $this->actingAs($this->admin(), 'admin')->get($view)->assertOk();
        }
    }

    // ── Case 2: customer dine-in code entry ──────────────────────────────

    public function test_session_token_endpoint_is_read_only(): void
    {
        $res = $this->getJson('/customer/session-token');

        $res->assertOk();
        $this->assertIsString($res->json('token'));
        $this->assertNotSame('', $res->json('token'));

        // It must not start a dine-in session or touch occupancy.
        $this->assertNull(session('table_number'));
        $this->assertNull(session('branch_id'));
        $this->assertNotSame('dine_in', session('order_type'));
    }

    public function test_a_stale_token_on_the_dine_in_form_lands_back_on_the_same_form_not_the_419_page(): void
    {
        $this->enforceCsrf();

        $res = $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', ['table_code' => 'K7QMP3', 'next' => 'guest']); // no _token

        $res->assertStatus(302);
        $res->assertRedirect('/customer/dineinqr');
        $this->assertIsString(session('error'));
        $this->assertNotSame('', trim(session('error')));

        // No dine-in session was opened by the refused request.
        $this->assertNull(session('table_number'));
        $this->assertNotSame('dine_in', session('order_type'));
    }

    public function test_a_valid_token_on_the_dine_in_form_still_works(): void
    {
        $this->enforceCsrf();

        $this->get('/customer/dineinqr'); // establishes the session + token
        $code = $this->permanentCode(1, '3');

        $res = $this->from('/customer/dineinqr')->post('/customer/dineinqr', [
            '_token'     => csrf_token(),
            'table_code' => $code,
            'next'       => 'guest',
        ]);

        $res->assertRedirect(route('customer.menu'));
        $this->assertSame(1, session('branch_id'));
        $this->assertSame('3', session('table_number'));
    }

    // ── Case 3: the reload-loop, and what actually caused each half of it ──

    /**
     * Break the mysql connection at the config level, exactly the way
     * FriendlyErrorPagesTest::withDatabaseDown() does — the same technique
     * used to reproduce this environment's "MySQL is not running" incidents.
     */
    private function withDatabaseDown(\Closure $callback)
    {
        $original = config('database.connections.mysql');

        config([
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '1',
        ]);
        DB::purge('mysql');

        try {
            return $callback();
        } finally {
            config(['database.connections.mysql' => $original]);
            DB::purge('mysql');
        }
    }

    /**
     * Root-cause test for the "500" half of the reported loop: a DB blip
     * during check-in must land the customer back on the same form with a
     * retryable message, not the dead-end database-unavailable page. Without
     * the bootstrap/app.php fix, this asserted a 500 with only a "Back to
     * home" link — confirmed failing before the fix, during the sabotage
     * check.
     */
    public function test_a_database_blip_during_check_in_redirects_back_to_the_form_not_a_dead_end(): void
    {
        // A real, DB-backed permanent code — registered while the connection
        // is still up, so the query that fails is the one processQr() itself
        // runs against restaurant_tables, not an early length-check no-op
        // (a too-short code like 'K7QMP3' never reaches the database at all).
        $code = $this->permanentCode(1, 'ZDBLIP');

        $res = $this->withDatabaseDown(fn () => $this->from('/customer/dineinqr')
            ->post('/customer/dineinqr', ['table_code' => $code, 'next' => 'guest']));

        $res->assertStatus(302);
        $res->assertRedirect('/customer/dineinqr');

        $error = session('error');
        $this->assertIsString($error);
        $this->assertNotSame('', trim($error));
    }

    /**
     * Root-cause test for the "clicking Reload looped" half: the 419 page can
     * only ever be reached via a POST, so its reload control must never be
     * window.location.reload() — that resubmits the POST body that produced
     * the page, repeating the exact conditions that failed. It must instead
     * navigate to the same path, which is always a fresh GET.
     */
    public function test_the_419_pages_reload_control_never_resubmits_the_failed_post(): void
    {
        $this->enforceCsrf();

        // A plain POST outside the dine-in flow still gets the raw branded
        // 419 card (bootstrap/app.php only redirects qr.process/dineinqr) —
        // that is the page whose reload control this test is guarding.
        $res = $this->post('/customer/login', ['email' => 'a@a.com', 'password' => 'x']);
        $res->assertStatus(419);

        $html = $res->getContent();

        $this->assertStringNotContainsString(
            'window.location.reload()',
            $html,
            'the 419 page still calls location.reload(), which resubmits the POST that produced it'
        );

        $this->assertStringContainsString(
            "window.location.href = window.location.pathname",
            $html,
            'the 419 page\'s reload control no longer navigates via a safe GET'
        );
    }
}

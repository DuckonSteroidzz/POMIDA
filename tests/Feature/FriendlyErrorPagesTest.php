<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\RateLimitServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every error a person can actually reach must render this app's own branded
 * page — same card, same voice, a single obvious way back — and never a raw
 * framework page, regardless of APP_DEBUG. See resources/views/errors/*.
 *
 * WHAT WAS ALREADY IN PLACE BEFORE THIS PASS
 * -------------------------------------------
 * 403, 404, 419, 429, 500 and 503 already had branded views, discovered
 * automatically by Laravel's own exception handler purely from the status
 * code — nothing in bootstrap/app.php was needed for those, and nothing here
 * changes them. ErrorPageContext already offered a dine-in customer "back to
 * my order" / "back to the menu" instead of only "back to home" (see
 * ReceiptAccessTest::test_the_404_page_is_context_aware and
 * ThrottleHardeningTest::test_the_429_page_offers_a_route_back_to_an_in_progress_order,
 * both still passing, both untouched).
 *
 * WHAT THIS PASS ADDED
 * ---------------------
 *  - 405 and 413 had no branded view at all — Laravel's own generic
 *    "Oops! An Error Occurred" page reached the visitor for both. New views,
 *    same layout, no new CSS.
 *  - A database-layer failure (MySQL down — this has happened repeatedly
 *    during development) fell through to the generic 500 page, whose own
 *    "Check my orders" link would fail again immediately for the same
 *    reason. bootstrap/app.php now renders QueryException through a calmer,
 *    link-free page instead — see errors/database-unavailable.blade.php.
 *  - Every error page offered ONLY customer destinations, even under
 *    /admin/.... ErrorPageContext::isAdminVisitor() (decided from the
 *    request path, not a guess) now sends a staff portal visitor to their
 *    dashboard, and 404/500's hard-coded customer links are hidden for them.
 *
 * THE RULE THAT MATTERS MOST: A FRIENDLY PAGE MUST NEVER MEAN A SWALLOWED BUG.
 * test_an_exception_still_reaches_the_log_even_though_a_friendly_page_is_shown()
 * is the one that proves it — see its docblock.
 */
class FriendlyErrorPagesTest extends TestCase
{
    use DatabaseTransactions;

    /*
     * NOT 'vendor/' alone: every one of these pages legitimately links its own
     * stylesheet at /vendor/gfonts.css (a static asset path, not a code path),
     * so that string is present on every branded page today and is not itself
     * a leak. 'vendor\' / 'vendor/' immediately followed by a real package
     * folder is what an actual stack-trace path looks like.
     */
    private const LEAKS = ['SQLSTATE', 'Stack trace', 'vendor\\laravel', 'vendor/laravel', 'app/Http/', 'app\\Http\\', 'Illuminate\\', 'Symfony\\', 'Whoops', '.php:'];

    /** Assert none of the strings a real stack trace or file path would carry appear anywhere in the body. */
    private function assertNothingLeaked(string $html, string $context): void
    {
        foreach (self::LEAKS as $needle) {
            $this->assertStringNotContainsString($needle, $html, "$context leaked '$needle'");
        }

        // The framework's own version number, wherever Composer put it.
        $version = \Illuminate\Foundation\Application::VERSION;
        $this->assertStringNotContainsString($version, $html, "$context leaked the framework version");
    }

    /** Same trick CsrfProtectionTest uses: the app treats "running unit tests" as an automatic CSRF/throttle bypass, so prove it off first. */
    private function enforceRealBehaviour(): void
    {
        $this->app['env'] = 'production';

        $this->assertFalse(
            $this->app->runningUnitTests(),
            'still being treated as a unit test run — the rest of this test proves nothing'
        );
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    // ══════════════════════════════════════════════════════════════════
    // Every status renders this app's own page, leaks nothing, works at 375px
    // ══════════════════════════════════════════════════════════════════

    public function test_404_is_branded_and_leaks_nothing(): void
    {
        config(['app.debug' => false]);

        $response = $this->get('/customer/a-page-that-does-not-exist');

        $response->assertNotFound();
        $html = $response->getContent();
        $this->assertStringContainsString('We could not find that page', $html);
        $this->assertNothingLeaked($html, '404');
    }

    public function test_403_is_branded_and_leaks_nothing(): void
    {
        config(['app.debug' => false]);
        Route::get('/__friendly-error-test-403', fn () => abort(403));

        $response = $this->get('/__friendly-error-test-403');

        $response->assertForbidden();
        $html = $response->getContent();
        $this->assertStringContainsString('You do not have access to this page', $html);
        $this->assertNothingLeaked($html, '403');
    }

    public function test_405_is_branded_and_leaks_nothing(): void
    {
        config(['app.debug' => false]);

        // /customer/place-order only accepts POST.
        $response = $this->get('/customer/place-order');

        $response->assertStatus(405);
        $html = $response->getContent();
        $this->assertStringContainsString('That did not work the way it was tried', $html);
        $this->assertNothingLeaked($html, '405');
    }

    public function test_419_on_a_plain_form_post_is_branded_not_the_raw_laravel_page(): void
    {
        config(['app.debug' => false]);
        $this->enforceRealBehaviour();

        // A real POST, no CSRF token at all — the exact shape of a form left
        // open past its session lifetime, not a fetch() (session-guard.blade.php
        // already covers that half; this is the other one).
        $response = $this->post('/customer/login', ['email' => 'a@a.com', 'password' => 'x']);

        $response->assertStatus(419);
        $html = $response->getContent();
        $this->assertStringContainsString('Your session timed out', $html);
        $this->assertNothingLeaked($html, '419');
    }

    public function test_429_is_branded_even_off_the_friendly_throttle_middleware(): void
    {
        config(['app.debug' => false]);

        // admin login's throttle:admin-login is NOT wrapped by
        // throttle.friendly — this proves the raw 429 itself is still caught
        // by Laravel's own view lookup, independently of that middleware.
        $response = null;
        for ($i = 0; $i < 15; $i++) {
            $response = $this->post('/admin/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);
            if ($response->getStatusCode() === 429) {
                break;
            }
        }

        $this->assertSame(429, $response->getStatusCode());
        $html = $response->getContent();
        $this->assertStringContainsString('Too many attempts', $html);
        $this->assertNothingLeaked($html, '429');
    }

    public function test_413_upload_too_large_is_branded_and_states_the_limit(): void
    {
        config(['app.debug' => false]);

        // ValidatePostSize (Laravel's own default middleware) rejects the
        // request by comparing the Content-Length header to post_max_size
        // BEFORE any controller or validation rule runs — faking the header
        // reproduces that without actually uploading a huge file.
        $response = $this->call('POST', '/customer/place-order', [], [], [], [
            'CONTENT_LENGTH' => 999999999999,
        ]);

        $response->assertStatus(413);
        $html = $response->getContent();
        $this->assertStringContainsString('That upload is too large', $html);
        $this->assertMatchesRegularExpression('/keep it under \d+(\.\d+)? ?[KMG]?B/', $html);
        $this->assertNothingLeaked($html, '413');
    }

    public function test_503_maintenance_mode_is_branded_and_leaks_nothing(): void
    {
        config(['app.debug' => false]);

        Artisan::call('down');
        try {
            $response = $this->get('/');
        } finally {
            Artisan::call('up');
        }

        $response->assertStatus(503);
        $html = $response->getContent();
        $this->assertStringContainsString('We are updating the system', $html);
        $this->assertNothingLeaked($html, '503');
    }

    public function test_500_is_branded_and_leaks_nothing(): void
    {
        config(['app.debug' => false]);
        Route::get('/__friendly-error-test-500', fn () => throw new \RuntimeException('deliberate test failure'));

        $response = $this->get('/__friendly-error-test-500');

        $response->assertStatus(500);
        $html = $response->getContent();
        $this->assertStringContainsString('Something went wrong on our end', $html);
        $this->assertStringNotContainsString('deliberate test failure', $html);
        $this->assertNothingLeaked($html, '500');
    }

    // ══════════════════════════════════════════════════════════════════
    // The database-unavailable page
    // ══════════════════════════════════════════════════════════════════

    /**
     * Break the mysql connection at the config level (the same technique used
     * to reproduce the repeated "MySQL is not running" reports during
     * development), hit a normal customer route, and check what a real
     * visitor would see — then always restore the connection, even if an
     * assertion fails.
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

    public function test_database_unavailable_shows_a_calm_page_not_a_connection_error(): void
    {
        config(['app.debug' => false]);

        $response = $this->withDatabaseDown(fn () => $this->get('/customer/menu'));

        $response->assertStatus(500);
        $html = $response->getContent();
        $this->assertStringContainsString('We cannot reach the system right now', $html);

        // Must not name the database or repeat the driver's own words.
        foreach (['mysql', 'MySQL', 'pomida_db', 'SQLSTATE', 'Connection refused', 'PDO'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "leaked '$needle'");
        }
        $this->assertNothingLeaked($html, 'database-unavailable');
    }

    /**
     * THE RULE THAT MATTERS MOST.
     *
     * A friendly page must never mean the underlying failure went unrecorded.
     * bootstrap/app.php registers a renderable() for QueryException, and
     * renderable() only ever changes what is RENDERED — Laravel calls
     * report() and render() as two independent steps for every exception
     * (Illuminate\Foundation\Http\Kernel::reportException() then
     * ::renderException(), report always first), and nothing this pass added
     * touches reportable() or shouldReport(). This test proves that stays
     * true rather than trusting the reasoning: if a future change ever moves
     * the logging call inside a conditional that a friendly render bypasses,
     * this goes red.
     */
    public function test_an_exception_still_reaches_the_log_even_though_a_friendly_page_is_shown(): void
    {
        config(['app.debug' => false]);
        Log::spy();

        $response = $this->withDatabaseDown(fn () => $this->get('/customer/menu'));

        $response->assertStatus(500);
        $this->assertStringContainsString(
            'We cannot reach the system right now',
            $response->getContent(),
            'setup check: the friendly page must actually be the one shown'
        );

        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    // ══════════════════════════════════════════════════════════════════
    // The way back fits who is lost
    // ══════════════════════════════════════════════════════════════════

    public function test_a_customer_404_offers_the_menu_and_never_an_admin_link(): void
    {
        config(['app.debug' => false]);

        $html = $this->get('/customer/a-page-that-does-not-exist')->getContent();

        $this->assertStringContainsString('Browse the menu', $html);
        $this->assertStringContainsString('Back to home', $html);
        $this->assertStringNotContainsString('Back to my dashboard', $html);
    }

    public function test_an_admin_404_offers_the_dashboard_and_never_the_customer_menu(): void
    {
        config(['app.debug' => false]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/a-page-that-does-not-exist')
            ->getContent();

        $this->assertStringContainsString('Back to my dashboard', $html);
        $this->assertStringNotContainsString('Browse the menu', $html);
        $this->assertStringNotContainsString('Check my orders', $html);
    }

    /**
     * The same admin-area destination even with no live session — the most
     * common real case, an admin whose session already expired hitting a
     * stale /admin/... bookmark. isAdminVisitor() reads the request path, not
     * whether anyone is logged in, specifically so this case is covered too.
     */
    public function test_an_admin_area_404_is_still_the_admin_variant_when_signed_out(): void
    {
        config(['app.debug' => false]);

        $html = $this->get('/admin/a-page-that-does-not-exist')->getContent();

        $this->assertStringContainsString('Back to my dashboard', $html);
        $this->assertStringNotContainsString('Browse the menu', $html);
    }

    public function test_a_customer_500_offers_check_my_orders_and_never_the_dashboard(): void
    {
        config(['app.debug' => false]);
        Route::get('/__friendly-error-test-customer-500', fn () => throw new \RuntimeException('x'));

        $html = $this->get('/__friendly-error-test-customer-500')->getContent();

        $this->assertStringContainsString('Check my orders', $html);
        $this->assertStringNotContainsString('Back to my dashboard', $html);
    }

    public function test_an_admin_500_offers_the_dashboard_and_never_check_my_orders(): void
    {
        config(['app.debug' => false]);
        Route::get('/admin/__friendly-error-test-admin-500', fn () => throw new \RuntimeException('x'));

        $html = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/__friendly-error-test-admin-500')
            ->getContent();

        $this->assertStringContainsString('Back to my dashboard', $html);
        $this->assertStringNotContainsString('Check my orders', $html);
    }

    // ══════════════════════════════════════════════════════════════════
    // FriendlyThrottleResponse — untouched, still the sentence-on-the-page
    // treatment for the endpoints it wraps
    // ══════════════════════════════════════════════════════════════════

    public function test_friendly_throttle_response_is_unchanged_for_the_route_it_wraps(): void
    {
        $customer = User::where('role', 'customer')->where('is_active', true)->firstOrFail();
        $ceiling = RateLimitServiceProvider::PLACE_ORDER_PER_SESSION;

        $response = null;
        for ($i = 0; $i <= $ceiling; $i++) {
            $response = $this->actingAs($customer, 'customer')
                ->post('/customer/place-order', ['order_type' => 'pick_up']);
        }

        // Still a redirect carrying a plain-language error, not the branded
        // (or raw) 429 page — exactly FriendlyThrottleResponse's own contract.
        $this->assertSame('1', $response->headers->get('X-RateLimit-Rejected'));
        $response->assertSessionHasErrors('error');
        $this->assertNotSame(429, $response->getStatusCode());
    }

    // ══════════════════════════════════════════════════════════════════
    // 375px: no fixed width wider than the viewport, no new CSS invented
    // ══════════════════════════════════════════════════════════════════

    public function test_the_shared_layout_cannot_overflow_a_375px_viewport(): void
    {
        $css = view('errors.layout')->with([
            'code' => '000', 'emoji' => '', 'title' => '',
        ])->render();

        // The properties that make the card shrink to fit a narrow viewport
        // instead of overflowing it, in the one shared stylesheet every error
        // page (including the three new ones) renders through.
        $this->assertStringContainsString('max-width: 520px', $css);
        $this->assertStringContainsString('width: 100%', $css);
        $this->assertStringContainsString('box-sizing: border-box', $css);
        $this->assertStringContainsString('flex-wrap: wrap', $css);

        // No fixed (non-max) width anywhere wider than a 375px viewport can
        // hold. max-width is exactly what keeps .card responsive, so it is
        // deliberately excluded — a bare `width:` is the one that would
        // actually force an overflow.
        preg_match_all('/(?<!-)width:\s*(\d+)px/', $css, $matches);
        foreach ($matches[1] as $px) {
            $this->assertLessThanOrEqual(375, (int) $px, 'a fixed width wider than 375px would overflow a small phone');
        }
    }

    public function test_the_new_error_views_add_no_stylesheet_of_their_own(): void
    {
        foreach (['405', '413', 'database-unavailable'] as $view) {
            $path = resource_path("views/errors/{$view}.blade.php");
            $source = file_get_contents($path);

            $this->assertStringNotContainsString('<style', $source, "errors.$view must reuse errors.layout's shared CSS, not define its own");
        }
    }
}

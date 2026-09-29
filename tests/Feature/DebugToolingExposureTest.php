<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Spatie\LaravelIgnition\Http\Middleware\RunnableSolutionsEnabled;
use Tests\TestCase;

/**
 * The Ignition debug endpoints must never be exposed on a live site.
 *
 * WHY THIS IS CHECKED AT ALL
 * --------------------------
 * `php artisan route:list` on this project shows three routes nobody here wrote:
 *
 *     GET  _ignition/health-check
 *     POST _ignition/execute-solution
 *     POST _ignition/update-config
 *
 * They come from spatie/laravel-ignition, whose service provider registers them
 * UNCONDITIONALLY in boot() — there is no environment check at registration
 * time. `execute-solution` is the endpoint family behind the well-known Laravel
 * debug-mode remote-code-execution class of bug (the facade/ignition
 * CVE-2021-3129 lineage), so their presence deserved a real answer.
 *
 * WHAT THE AUDIT FOUND — NO VULNERABILITY
 * ---------------------------------------
 * Two independent protections, both verified:
 *
 *   1. The package is in composer.json's `require-dev`. A production install
 *      with `composer install --no-dev` does not have it, so the routes do not
 *      exist at all on Hostinger.
 *
 *   2. Where it IS installed, all three routes sit behind
 *      RunnableSolutionsEnabled, which 404s unless
 *      RunnableSolutionsGuard::check() passes. That guard (read at
 *      vendor/spatie/laravel-ignition/src/Support/RunnableSolutionsGuard.php)
 *      returns false on `! config('app.debug')` as its FIRST test, and then
 *      returns false for any environment that is not local/development —
 *      the package's own comment says that second test exists precisely to
 *      cover an app that is "somehow APP_ENV=production with APP_DEBUG=true".
 *      Installed version is 2.12.0, far past the 2021 advisory.
 *
 * WHY THERE IS NO HTTP TEST HERE — AND WHY THAT IS DELIBERATE
 * ----------------------------------------------------------
 * The obvious test is "POST the endpoint and assert 404". It was written, and
 * then measured and DELETED, because it proves nothing: `app()->environment()`
 * is fixed from APP_ENV when the framework boots and is NOT re-read from
 * config(), so under phpunit.xml's APP_ENV=testing the guard returns false in
 * every configuration — verified across the four combinations of
 * app.debug x app.env, all false. A 404 assertion would therefore pass just as
 * happily against an app with no protection whatsoever, which is the same
 * vacuous-pass trap this suite has been bitten by before.
 *
 * So this file asserts the two things that ARE decidable in-process and would
 * actually change if the protection regressed: the middleware is attached to
 * every one of those routes, and the package stays a dev dependency.
 * DeployCheck (see DeployCheckTest) is what gates APP_DEBUG itself for go-live.
 */
class DebugToolingExposureTest extends TestCase
{
    private const IGNITION_URIS = [
        '_ignition/health-check',
        '_ignition/execute-solution',
        '_ignition/update-config',
    ];

    /**
     * Every ignition route is behind the guard middleware.
     *
     * This is the assertion that would break if a package upgrade dropped the
     * middleware, or if someone re-registered these routes by hand.
     */
    public function test_every_ignition_route_is_behind_the_runnable_solutions_guard(): void
    {
        $seen = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_contains($route->uri(), '_ignition')) {
                continue;
            }

            $seen[] = $route->uri();

            $this->assertContains(
                RunnableSolutionsEnabled::class,
                array_map('strval', $route->gatherMiddleware()),
                '/' . $route->uri() . ' is registered WITHOUT RunnableSolutionsEnabled, so nothing stops it '
                . 'running when the package is installed.'
            );
        }

        // Control: if the package stopped registering routes, the loop above
        // would assert nothing at all and pass silently.
        foreach (self::IGNITION_URIS as $uri) {
            $this->assertContains(
                $uri,
                $seen,
                "{$uri} is no longer registered. If ignition was removed, delete this test; if it was "
                . 'renamed, update IGNITION_URIS — do not leave this passing vacuously.'
            );
        }
    }

    /**
     * Ignition stays a DEV dependency.
     *
     * If it moves to `require` it ships to Hostinger, and the only thing left
     * protecting `execute-solution` is the debug flag — one bad .env away.
     * Asserted against composer.json, the file a person actually edits.
     */
    public function test_ignition_is_not_a_production_dependency(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        $this->assertIsArray($composer, 'composer.json could not be read');

        $this->assertArrayNotHasKey(
            'spatie/laravel-ignition',
            $composer['require'] ?? [],
            'spatie/laravel-ignition has moved into require, so it now ships to production. It registers '
            . 'POST _ignition/execute-solution unconditionally — keep it in require-dev.'
        );

        // Control: it must still be a dev dependency, or this is asserting
        // something about a package that is simply gone.
        $this->assertArrayHasKey(
            'spatie/laravel-ignition',
            $composer['require-dev'] ?? [],
            'spatie/laravel-ignition is in neither require nor require-dev — if it was removed on purpose, '
            . 'this test can go too.'
        );
    }
}

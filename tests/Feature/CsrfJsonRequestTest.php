<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * JSON / fetch mutations are CSRF-protected too, not just HTML forms.
 *
 * WHY THIS IS SEPARATE FROM CsrfProtectionTest
 * -------------------------------------------
 * That file is thorough about form-shaped posts: it proves no route is excluded
 * from validation, that every mutating Blade form emits @csrf, and that 20
 * consequential endpoints refuse a tokenless request.
 *
 * It does not exercise the JSON shape, and JSON is worth its own assertion
 * because Laravel accepts the token from THREE places — the `_token` field, the
 * `X-CSRF-TOKEN` header, and an encrypted `X-XSRF-TOKEN` cookie. Much of this
 * app's admin and customer UI mutates through `fetch()` with an `X-CSRF-TOKEN`
 * header (the notification trays, the table panel, add-to-cart, the spin wheel),
 * so "does a JSON post without any of those get refused?" is a different question
 * from "does a form post without a hidden field get refused?", and a future
 * `$middleware->validateCsrfTokens(except: ['api/*'])` aimed at "the AJAX
 * endpoints" would slip past the form-shaped tests.
 *
 * WHAT THE AUDIT FOUND
 * --------------------
 * No issue. There is no CSRF exemption anywhere in this project: no custom
 * VerifyCsrfToken subclass, no `validateCsrfTokens(except:)` call in
 * bootstrap/app.php, no `withoutMiddleware()` on any route, and no routes/api.php
 * at all — every one of the 107 mutating routes is in the `web` group. These
 * tests pin the behaviour that follows from that.
 */
class CsrfJsonRequestTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Turn CSRF validation back on, the same way CsrfProtectionTest does.
     *
     * ValidateCsrfToken IS in the stack under phpunit — it simply short-circuits
     * on `runningUnitTests()`, so `withMiddleware()` changes nothing and every
     * assertion here would be vacuous. Flipping the resolved env is what actually
     * defeats that short-circuit. The assertion below and the negative control at
     * the bottom of the file are what prove it really is on.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'production';

        $this->assertFalse(
            $this->app->runningUnitTests(),
            'CSRF is still being skipped — the rest of this test proves nothing'
        );
    }

    /**
     * Endpoints this app's own JavaScript drives with fetch() + X-CSRF-TOKEN.
     *
     * Chosen because they are the ones a reader would expect to have been
     * "exempted for convenience" at some point: they are polled or fired from
     * script, never from a <form> submit.
     */
    public static function fetchDrivenEndpoints(): array
    {
        return [
            'customer notifications read'  => ['POST', '/customer/notifications/read'],
            'customer add points (wheel)'  => ['POST', '/customer/add-points'],
            'customer apply voucher'       => ['POST', '/customer/apply-voucher'],
            'customer table activity'      => ['POST', '/customer/table-activity'],
            'customer idle logout'         => ['POST', '/customer/idle-logout'],
            'customer help request'        => ['POST', '/customer/help-request'],
            'admin notifications read'     => ['POST', '/admin/notifications/read'],
            'admin clear table'            => ['POST', '/admin/tables/clear'],
        ];
    }

    /**
     * A JSON post with no token at all must be refused as a token mismatch —
     * 419, not 200, and not a validation error that implies it got through.
     *
     * @dataProvider fetchDrivenEndpoints
     */
    public function test_a_json_mutation_without_a_token_is_refused(string $method, string $uri): void
    {
        $response = $this->json($method, $uri, ['probe' => 1]);

        $this->assertSame(
            419,
            $response->getStatusCode(),
            "{$method} {$uri} answered HTTP " . $response->getStatusCode()
            . ' for a JSON request carrying no CSRF token. It must be refused with 419.'
        );
    }

    /**
     * An X-CSRF-TOKEN header from a DIFFERENT session must not be accepted.
     *
     * Sending *a* valid-looking token is the realistic attempt — a cross-site
     * page can guess the header name but cannot read this session's token.
     *
     * @dataProvider fetchDrivenEndpoints
     */
    public function test_a_json_mutation_with_a_foreign_token_is_refused(string $method, string $uri): void
    {
        $response = $this->withHeaders(['X-CSRF-TOKEN' => \Illuminate\Support\Str::random(40)])
            ->json($method, $uri, ['probe' => 1]);

        $this->assertSame(
            419,
            $response->getStatusCode(),
            "{$method} {$uri} accepted a CSRF token that did not belong to this session."
        );
    }

    // ══════════ The negative control ══════════

    /**
     * The SAME request with this session's real token must NOT be refused as a
     * token mismatch.
     *
     * Without this every assertion above would pass against an application that
     * rejected all JSON outright — or against a harness where the middleware was
     * never actually re-enabled and something else was producing the 419.
     */
    public function test_the_same_json_request_with_a_real_token_is_not_a_token_mismatch(): void
    {
        $this->get('/customer/login');

        $response = $this->withHeaders(['X-CSRF-TOKEN' => csrf_token()])
            ->json('POST', '/customer/table-activity', ['probe' => 1]);

        $this->assertNotSame(
            419,
            $response->getStatusCode(),
            'a JSON request carrying this session\'s own CSRF token was still refused as a mismatch, '
            . 'so the 419s above prove nothing about CSRF specifically'
        );
    }
}

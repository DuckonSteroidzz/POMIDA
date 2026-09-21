<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * App\Http\Middleware\LogHttpErrors — purely observational, additive logging
 * added to chase two unreproduced reports ("mobile gets more 400-500 errors
 * than laptop", "mobile feels slow"). These tests prove the two things that
 * actually matter for something billed as zero-risk:
 *
 *   1. It captures what it says it captures, for both a controller-rendered
 *      error and a framework 404 (which never has route-group middleware
 *      attached, hence the middleware is registered globally, not per-route).
 *   2. It genuinely costs nothing observable on the happy path — a 200/300
 *      response must not produce a line in http-errors.log at all.
 *
 * The log file is real, not faked (Log::spy() can't easily assert on a
 * specific channel's actual formatted content), so every test reads only the
 * bytes appended during that one request — a size-based high-water mark,
 * never a destructive truncate/delete of a file other processes and past
 * test runs share.
 */
class LogHttpErrorsTest extends TestCase
{
    use DatabaseTransactions;

    private function logPath(): string
    {
        return storage_path('logs/http-errors.log');
    }

    private function markLogSize(): int
    {
        return file_exists($this->logPath()) ? filesize($this->logPath()) : 0;
    }

    /** Only the bytes written to the log after $before — never the whole file. */
    private function newLogLines(int $before): string
    {
        if (!file_exists($this->logPath())) {
            return '';
        }

        clearstatcache(true, $this->logPath());
        $handle = fopen($this->logPath(), 'rb');
        fseek($handle, $before);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    public function test_a_404_from_an_unmatched_route_is_logged(): void
    {
        $before = $this->markLogSize();

        $this->get('/this-route-does-not-exist-anywhere-xyz')->assertStatus(404);

        $new = $this->newLogLines($before);

        $this->assertStringContainsString('HTTP 404', $new);
        $this->assertStringContainsString('"status":404', $new);
        $this->assertStringContainsString('this-route-does-not-exist-anywhere-xyz', $new);
        $this->assertStringContainsString('"method":"GET"', $new);
        $this->assertStringContainsString('"actor":"guest"', $new);
    }

    public function test_a_405_for_an_authenticated_admin_is_logged_with_the_real_actor(): void
    {
        $admin = User::where('role', 'admin')->where('is_active', true)->orderBy('id')->firstOrFail();

        $before = $this->markLogSize();

        // admin.inventory.stock-in is POST-only — GETting it is a reliable,
        // deterministic 405 that needs no form data, proving this captures a
        // normal application-level refusal, not only a framework exception,
        // and that the actor line names the real guard/id/role rather than
        // always "guest". The id need not exist: a 405 is a ROUTING decision
        // (wrong verb for this URI pattern), decided before any controller or
        // model lookup runs.
        //
        // Phase 3b F1 (2026-09-20): this used to POST to /admin/branches/select
        // for the same reliable-405 property — that was actually a side
        // effect of PUT admin/branches/{id} having no ->whereNumber('id')
        // constraint (see CsrfExpiryDeadEndTest's matching update), which F1
        // closed. This route is 405 by deliberate design (POST-only,
        // whereNumber'd from the start — see routes/web.php), not by accident.
        $this->actingAs($admin, 'admin');
        $response = $this->get('/admin/inventory/stock-in/1');
        $response->assertStatus(405);

        $new = $this->newLogLines($before);

        $this->assertStringContainsString('HTTP 405', $new);
        $this->assertStringContainsString('"actor":"admin:' . $admin->id . ':admin"', $new);
    }

    public function test_a_normal_200_response_writes_nothing_to_the_log(): void
    {
        $before = $this->markLogSize();

        $this->get('/customer/menu')->assertOk();

        $this->assertSame('', $this->newLogLines($before), 'a 200 response wrote a line to http-errors.log');
    }

    public function test_a_redirect_writes_nothing_to_the_log(): void
    {
        $before = $this->markLogSize();

        // A 302, the shape of most successful POST flows in this app.
        $this->post('/customer/dineinqr', ['table_code' => 'ZZZZZZZZ', 'next' => 'guest'])
            ->assertStatus(302);

        $this->assertSame('', $this->newLogLines($before), 'a redirect wrote a line to http-errors.log');
    }
}

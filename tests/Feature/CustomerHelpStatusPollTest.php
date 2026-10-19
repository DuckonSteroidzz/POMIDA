<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\HelpRequest;
use App\Models\User;
use App\Providers\RateLimitServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The customer's help request updates by itself (item E, Oct 2026 defect
 * report).
 *
 * The bug: after staff pressed Done, the customer's Help page (and the More
 * page's Need Assistance block) kept saying "Your request was sent. Please
 * wait" and needed a manual refresh before another request could be sent.
 *
 * Now a block that shows an open request polls GET
 * /customer/help-request/status (about every 6 seconds, only while the tab is
 * visible, and only until the request is resolved) and, once staff are done,
 * swaps the form back in with a short "Staff has helped you." note. The
 * endpoint answers about THIS session's own table only, with a status word
 * and nothing else. The browser behaviour (timing, tab visibility, the swap)
 * is proved in a real browser, see the item E report; these tests pin the
 * endpoint, its scope and throttle, and the markup the script relies on.
 */
class CustomerHelpStatusPollTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app('cache')->store()->flush();
    }

    protected function tearDown(): void
    {
        app('cache')->store()->flush();
        parent::tearDown();
    }

    private function branch(): Branch
    {
        return Branch::create([
            'name'           => 'Help Poll ' . Str::random(5),
            'code'           => 'HS' . strtoupper(Str::random(6)),
            'address'        => '1 Poll Street',
            'is_active'      => true,
            'is_main_branch' => false,
        ]);
    }

    private function seated(Branch $branch, string $table = 'P7'): array
    {
        return ['order_type' => 'dine_in', 'branch_id' => $branch->id, 'table_number' => $table];
    }

    private function help(Branch $branch, string $table, string $status = 'pending', array $extra = []): HelpRequest
    {
        return HelpRequest::create($extra + [
            'branch_id'    => $branch->id,
            'table_number' => $table,
            'status'       => $status,
            'message'      => 'I need staff assistance',
            'requested_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name'              => 'Help Poll Admin',
            'email'             => 'help-poll-admin-' . Str::lower(Str::random(10)) . '@example.test',
            'password'          => 'Aa1!aaaaaa',
            'role'              => 'admin',
            'branch_id'         => null,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    // ══════════ the endpoint ══════════

    public function test_it_reports_each_state_of_this_tables_request(): void
    {
        $branch = $this->branch();
        $session = $this->seated($branch);

        $this->withSession($session)->getJson(route('customer.help-request.status'))
            ->assertOk()->assertExactJson(['open' => false, 'status' => 'none']);

        $request = $this->help($branch, 'P7');
        $this->withSession($session)->getJson(route('customer.help-request.status'))
            ->assertOk()->assertExactJson(['open' => true, 'status' => 'pending']);

        $request->update(['status' => 'assisting', 'assisting_at' => now()]);
        $this->withSession($session)->getJson(route('customer.help-request.status'))
            ->assertOk()->assertExactJson(['open' => true, 'status' => 'assisting']);

        $request->update(['status' => 'resolved', 'resolved_at' => now()]);
        $this->withSession($session)->getJson(route('customer.help-request.status'))
            ->assertOk()->assertExactJson(['open' => false, 'status' => 'resolved']);
    }

    public function test_an_open_request_wins_over_an_older_resolved_one(): void
    {
        $branch = $this->branch();

        $this->help($branch, 'P7', 'resolved', ['requested_at' => now()->subHour(), 'resolved_at' => now()->subMinutes(50)]);
        $this->help($branch, 'P7', 'pending');

        $this->withSession($this->seated($branch))->getJson(route('customer.help-request.status'))
            ->assertExactJson(['open' => true, 'status' => 'pending']);
    }

    public function test_it_is_scoped_to_this_sessions_own_table_and_leaks_nothing_else(): void
    {
        $branch = $this->branch();
        $other = $this->branch();

        // Another table at this branch, and the SAME table number at another branch.
        $this->help($branch, 'ZZ9', 'pending', ['message' => 'secret message from another table']);
        $this->help($other, 'P7', 'assisting');

        $response = $this->withSession($this->seated($branch))->getJson(route('customer.help-request.status'));

        $response->assertOk()->assertExactJson(['open' => false, 'status' => 'none']);
        $this->assertStringNotContainsString('ZZ9', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());

        // It takes no input: an id, table or branch in the query is ignored.
        $this->withSession($this->seated($branch))
            ->getJson(route('customer.help-request.status', ['table_number' => 'ZZ9', 'branch_id' => $branch->id, 'id' => 1]))
            ->assertExactJson(['open' => false, 'status' => 'none']);
    }

    public function test_only_a_seated_dine_in_session_gets_an_answer(): void
    {
        $branch = $this->branch();
        $this->help($branch, 'P7');

        // Pick-up, even with a table number left over in the session.
        $this->withSession(['order_type' => 'pick_up', 'branch_id' => $branch->id, 'table_number' => 'P7'])
            ->getJson(route('customer.help-request.status'))
            ->assertOk()->assertExactJson(['open' => false, 'status' => 'none']);

        // Dine-in without a table.
        $this->flushSession();
        $this->withSession(['order_type' => 'dine_in', 'branch_id' => $branch->id])
            ->getJson(route('customer.help-request.status'))
            ->assertExactJson(['open' => false, 'status' => 'none']);

        // No session context at all.
        $this->flushSession();
        $this->getJson(route('customer.help-request.status'))
            ->assertExactJson(['open' => false, 'status' => 'none']);
    }

    public function test_it_is_read_only_and_never_cached(): void
    {
        $branch = $this->branch();
        $request = $this->help($branch, 'P7');

        $response = $this->withSession($this->seated($branch))->getJson(route('customer.help-request.status'));

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('pending', $request->fresh()->status, 'a status poll changed the request');

        // GET only.
        $this->withSession($this->seated($branch))->postJson(route('customer.help-request.status'))->assertStatus(405);
    }

    // ══════════ the throttle ══════════

    public function test_the_route_carries_its_own_named_throttle(): void
    {
        $middleware = Route::getRoutes()->getByName('customer.help-request.status')->gatherMiddleware();

        $this->assertContains('throttle:help-status', $middleware);
        // A refused poll skips a tick; it must not redirect like a form post.
        $this->assertNotContains('throttle.friendly', $middleware);
    }

    public function test_the_ceilings_leave_room_for_the_poll_and_stop_a_runaway_client(): void
    {
        // About 10 polls a minute from one phone (every 6 s) against 30 allowed.
        $this->assertGreaterThanOrEqual(3 * (60 / 6), RateLimitServiceProvider::HELP_STATUS_PER_SESSION);

        $ceiling = RateLimitServiceProvider::HELP_STATUS_PER_IP;
        $branch = $this->branch();

        // Each request has a fresh session, so it is the per-IP half that runs out.
        for ($i = 0; $i < $ceiling; $i++) {
            $this->withSession($this->seated($branch))->getJson(route('customer.help-request.status'))->assertOk();
        }

        $this->withSession($this->seated($branch))->getJson(route('customer.help-request.status'))
            ->assertStatus(429);
    }

    public function test_its_budget_is_separate_from_the_help_form_and_the_bell(): void
    {
        $branch = $this->branch();

        // Spend the whole status budget for this address...
        for ($i = 0; $i < RateLimitServiceProvider::HELP_STATUS_PER_IP; $i++) {
            $this->withSession($this->seated($branch))->getJson(route('customer.help-request.status'));
        }
        $this->withSession($this->seated($branch))->getJson(route('customer.help-request.status'))->assertStatus(429);

        // ...the help form and the notification bell still answer.
        $this->withSession($this->seated($branch))->from('/customer/help')
            ->post('/customer/help-request', ['message' => 'I need payment assistance'])
            ->assertHeaderMissing('X-RateLimit-Rejected');
        $this->withSession($this->seated($branch))->getJson(route('customer.notifications.unread-count'))
            ->assertStatus(200);
    }

    // ══════════ the Help page ══════════

    public function test_a_waiting_request_renders_the_watched_block_without_the_form(): void
    {
        $branch = $this->branch();
        $this->help($branch, 'P7');

        $html = $this->withSession($this->seated($branch))->get('/customer/help')->assertOk()->getContent();

        $this->assertStringContainsString('data-help-region', $html);
        $this->assertStringContainsString('data-help-open="1"', $html);
        $this->assertStringContainsString('data-help-status-url="' . route('customer.help-request.status') . '"', $html);
        $this->assertMatchesRegularExpression('/<p [^>]*data-help-message>/', $html);
        $this->assertStringContainsString('staff will be with you shortly', $html);
        $this->assertStringNotContainsString('name="message"', $html, 'the form must not show while a request is open (duplicate protection)');
        $this->assertStringContainsString('Staff has helped you.', $html, 'the poll script is not on the page');
    }

    public function test_an_assisting_request_says_staff_are_on_their_way(): void
    {
        $branch = $this->branch();
        $this->help($branch, 'P7', 'assisting');

        $this->withSession($this->seated($branch))->get('/customer/help')
            ->assertOk()
            ->assertSee('Staff are on their way to Table P7.')
            ->assertSee('data-help-open="1"', false);
    }

    public function test_with_no_open_request_the_block_shows_the_form_and_watches_nothing(): void
    {
        $branch = $this->branch();
        // Resolved earlier: the form is back.
        $this->help($branch, 'P7', 'resolved', ['resolved_at' => now()]);

        $html = $this->withSession($this->seated($branch))->get('/customer/help')->assertOk()->getContent();

        $this->assertStringContainsString('data-help-open="0"', $html);
        $this->assertStringContainsString('name="message"', $html);
        $this->assertDoesNotMatchRegularExpression('/<p [^>]*data-help-message>/', $html);
    }

    public function test_the_script_polls_every_six_seconds_only_while_visible_and_stops_when_resolved(): void
    {
        $script = file_get_contents(resource_path('views/customer/partials/help-status-poll.blade.php'));

        $this->assertSame(1, preg_match('/INTERVAL_MS = (\d+);/', $script, $m));
        $this->assertGreaterThanOrEqual(5000, (int) $m[1]);
        $this->assertLessThanOrEqual(8000, (int) $m[1]);

        $this->assertStringContainsString("addEventListener('visibilitychange'", $script);
        $this->assertStringContainsString('document.hidden', $script);
        // Stops for good once resolved, and only watches a block that starts open.
        $this->assertStringContainsString('finished = true;', $script);
        $this->assertStringContainsString("getAttribute('data-help-open') !== '1'", $script);
        $this->assertStringContainsString('Staff has helped you.', $script);
    }

    // ══════════ the More page ══════════

    public function test_the_more_page_shows_request_sent_instead_of_the_button_while_one_is_open(): void
    {
        $branch = $this->branch();
        $session = $this->seated($branch);

        $none = $this->withSession($session)->get('/customer/more')->assertOk()->getContent();
        $this->assertStringContainsString('data-help-open="0"', $none);
        $this->assertStringContainsString('onclick="openHelpModal()"', $none);
        $this->assertDoesNotMatchRegularExpression('/<p [^>]*data-help-message>/', $none);

        $this->help($branch, 'P7');

        $open = $this->withSession($session)->get('/customer/more')->assertOk()->getContent();
        $this->assertStringContainsString('data-help-open="1"', $open);
        $this->assertStringContainsString('data-help-status-url="' . route('customer.help-request.status') . '"', $open);
        $this->assertStringContainsString('staff will be with you shortly', $open);
        // The block's own button (the one that opens the modal) is gone while one is open.
        $this->assertStringNotContainsString('onclick="openHelpModal()"', $open);
        $this->assertStringContainsString('Staff has helped you.', $open, 'the poll script is not on the More page');
    }

    public function test_the_more_page_has_no_assistance_block_outside_dine_in(): void
    {
        $html = $this->withSession(['order_type' => 'pick_up', 'branch_id' => $this->branch()->id])
            ->get('/customer/more')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-help-region', $html);
        $this->assertStringNotContainsString('Staff has helped you.', $html);
    }

    // ══════════ the whole loop, through the real doors ══════════

    public function test_ask_wait_staff_press_done_and_ask_again_without_a_reload(): void
    {
        $branch = $this->branch();
        $session = $this->seated($branch);

        // The customer asks.
        $this->withSession($session)->from('/customer/help')
            ->post('/customer/help-request', ['message' => 'I need payment assistance'])
            ->assertSessionHas('success');
        $request = HelpRequest::where('branch_id', $branch->id)->sole();

        $this->withSession($session)->getJson(route('customer.help-request.status'))
            ->assertExactJson(['open' => true, 'status' => 'pending']);

        // Duplicate protection is unchanged while it is open.
        $this->withSession($session)->from('/customer/help')
            ->post('/customer/help-request', ['message' => 'I need staff assistance'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'already requested'));
        $this->assertSame(1, HelpRequest::where('branch_id', $branch->id)->count());

        // Staff assist, then press Done (the real admin routes).
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->put(route('admin.help-requests.assist', $request->id))->assertRedirect();
        $this->withSession($session)->getJson(route('customer.help-request.status'))
            ->assertExactJson(['open' => true, 'status' => 'assisting']);

        $this->actingAs($admin, 'admin')->put(route('admin.help-requests.resolve', $request->id))->assertRedirect();

        // The poll sees it...
        $this->withSession($session)->getJson(route('customer.help-request.status'))
            ->assertExactJson(['open' => false, 'status' => 'resolved']);

        // ...the page the script then asks for carries the form again...
        $this->withSession($session)->get('/customer/help')
            ->assertSee('data-help-open="0"', false)
            ->assertSee('name="message"', false);

        // ...and a new request is accepted.
        $this->withSession($session)->from('/customer/help')
            ->post('/customer/help-request', ['message' => 'I need help with my order'])
            ->assertSessionHas('success');
        $this->assertSame(2, HelpRequest::where('branch_id', $branch->id)->count());
        $this->withSession($session)->getJson(route('customer.help-request.status'))
            ->assertExactJson(['open' => true, 'status' => 'pending']);
    }
}

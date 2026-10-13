<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\HelpRequest;
use App\Providers\RateLimitServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The customer Help page (GET /customer/help) and its tie to the existing
 * dine-in help-request flow (POST /customer/help-request).
 *
 * What is pinned
 * --------------
 *  - Every customer type can open the page and sees the how-to topics.
 *  - Only a seated Dine-In customer is offered the "Request Assistance" form;
 *    pick-up customers and guests see the shop contact details instead.
 *  - The form posts to the existing route, carries a CSRF token, and an open
 *    request replaces the form with a "please wait" note.
 *  - The submit path: valid -> one pending row at the right branch + table;
 *    missing / over-long message -> nothing written; pick-up -> refused;
 *    a second request while one is open -> not duplicated.
 *  - The submit route keeps its throttle middleware, and the per-IP ceiling
 *    actually bites (the per-session half cannot be reached from the test
 *    client, which opens a new session per request).
 *  - The More page links to Help.
 *
 * Every row is created inside DatabaseTransactions and rolled back.
 */
class CustomerHelpPageTest extends TestCase
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
            'name'           => 'Help Page ' . Str::random(5),
            'code'           => 'HP' . strtoupper(Str::random(6)),
            'address'        => '1 Help Street',
            'is_active'      => true,
            'is_main_branch' => false,
        ]);
    }

    private function dineInSession(Branch $branch, string $table = 'H7'): array
    {
        return [
            'order_type'   => 'dine_in',
            'branch_id'    => $branch->id,
            'table_number' => $table,
        ];
    }

    // ══════════ the page ══════════

    public function test_a_visitor_with_no_order_context_can_read_the_instructions(): void
    {
        $response = $this->get('/customer/help');

        $response->assertOk();
        $response->assertSee('Help');
        foreach (['Ordering dine-in', 'Ordering for pick-up', 'Paying with GCash', 'Using a voucher', 'Spin &amp; Win', 'Contact the shop'] as $topic) {
            $response->assertSee($topic, false);
        }
        $response->assertDontSee('Request Assistance');
    }

    public function test_a_pickup_customer_sees_instructions_and_no_assistance_form(): void
    {
        $branch = $this->branch();

        $response = $this->withSession(['order_type' => 'pick_up', 'branch_id' => $branch->id])
            ->get('/customer/help');

        $response->assertOk();
        $response->assertSee('Ordering for pick-up');
        $response->assertSee('1 Help Street');
        $response->assertDontSee('Request Assistance');
        $response->assertDontSee(route('customer.help-request'), false);
    }

    public function test_a_seated_dine_in_customer_gets_the_assistance_form_with_a_csrf_token(): void
    {
        $branch = $this->branch();

        $response = $this->withSession($this->dineInSession($branch))->get('/customer/help');

        $response->assertOk();
        $response->assertSee('Request Assistance');
        $response->assertSee('Table H7');
        $response->assertSee('action="' . route('customer.help-request') . '"', false);
        $response->assertSee('name="_token"', false);
        $response->assertSee('name="message"', false);
    }

    public function test_a_dine_in_session_without_a_table_gets_no_form(): void
    {
        $branch = $this->branch();

        $response = $this->withSession(['order_type' => 'dine_in', 'branch_id' => $branch->id])
            ->get('/customer/help');

        $response->assertOk();
        $response->assertDontSee('Request Assistance');
    }

    public function test_an_open_request_replaces_the_form_with_a_wait_note(): void
    {
        $branch = $this->branch();
        HelpRequest::create([
            'branch_id' => $branch->id, 'table_number' => 'H7',
            'status' => 'pending', 'message' => 'I need staff assistance',
            'requested_at' => now(),
        ]);

        $response = $this->withSession($this->dineInSession($branch))->get('/customer/help');

        $response->assertOk();
        $response->assertSee('staff will be with you shortly', false);
        $response->assertDontSee('name="message"', false);
    }

    public function test_another_tables_open_request_does_not_hide_this_tables_form(): void
    {
        $branch = $this->branch();
        HelpRequest::create([
            'branch_id' => $branch->id, 'table_number' => 'ZZ9',
            'status' => 'pending', 'message' => 'x', 'requested_at' => now(),
        ]);

        $this->withSession($this->dineInSession($branch))
            ->get('/customer/help')
            ->assertSee('name="message"', false);
    }

    public function test_the_more_page_links_to_help(): void
    {
        $this->get('/customer/more')
            ->assertOk()
            ->assertSee('href="' . route('customer.help') . '"', false);
    }

    // ══════════ the submit path ══════════

    public function test_a_valid_dine_in_request_is_stored_for_this_branch_and_table(): void
    {
        $branch = $this->branch();

        $this->withSession($this->dineInSession($branch))
            ->from('/customer/help')
            ->post('/customer/help-request', ['message' => 'I need payment assistance'])
            ->assertRedirect('/customer/help')
            ->assertSessionHas('success');

        $row = HelpRequest::where('branch_id', $branch->id)->sole();
        $this->assertSame('H7', $row->table_number);
        $this->assertSame('pending', $row->status);
        $this->assertSame('I need payment assistance', $row->message);
    }

    public function test_a_missing_message_is_refused_and_writes_nothing(): void
    {
        $branch = $this->branch();

        $this->withSession($this->dineInSession($branch))
            ->from('/customer/help')
            ->post('/customer/help-request', [])
            ->assertRedirect('/customer/help')
            ->assertSessionHasErrors('message');

        $this->assertSame(0, HelpRequest::where('branch_id', $branch->id)->count());
    }

    public function test_an_over_long_message_is_refused_and_writes_nothing(): void
    {
        $branch = $this->branch();

        $this->withSession($this->dineInSession($branch))
            ->from('/customer/help')
            ->post('/customer/help-request', ['message' => str_repeat('a', 256)])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, HelpRequest::where('branch_id', $branch->id)->count());

        // The limit is inclusive of 255.
        $this->withSession($this->dineInSession($branch))
            ->post('/customer/help-request', ['message' => str_repeat('a', 255)])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, HelpRequest::where('branch_id', $branch->id)->count());
    }

    public function test_a_non_string_message_is_refused(): void
    {
        $branch = $this->branch();

        $this->withSession($this->dineInSession($branch))
            ->post('/customer/help-request', ['message' => ['x']])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, HelpRequest::where('branch_id', $branch->id)->count());
    }

    public function test_a_pickup_customer_cannot_create_a_help_request(): void
    {
        $branch = $this->branch();

        $this->withSession(['order_type' => 'pick_up', 'branch_id' => $branch->id, 'table_number' => 'H7'])
            ->post('/customer/help-request', ['message' => 'I need staff assistance'])
            ->assertSessionHas('error');

        $this->assertSame(0, HelpRequest::where('branch_id', $branch->id)->count());
    }

    public function test_a_second_request_while_one_is_open_is_not_duplicated(): void
    {
        $branch = $this->branch();
        $post = fn () => $this->withSession($this->dineInSession($branch))
            ->post('/customer/help-request', ['message' => 'I need staff assistance']);

        $post()->assertSessionHas('success');
        $post()->assertSessionHas('error');

        $this->assertSame(1, HelpRequest::where('branch_id', $branch->id)->count());
    }

    public function test_a_request_is_addressed_to_the_session_branch_not_a_posted_one(): void
    {
        $mine = $this->branch();
        $other = $this->branch();

        $this->withSession($this->dineInSession($mine))
            ->post('/customer/help-request', [
                'message' => 'I need staff assistance',
                'branch_id' => $other->id,
                'table_number' => 'ZZ9',
            ]);

        $this->assertSame(1, HelpRequest::where('branch_id', $mine->id)->where('table_number', 'H7')->count());
        $this->assertSame(0, HelpRequest::where('branch_id', $other->id)->count());
    }

    // ══════════ protection ══════════

    public function test_the_submit_route_keeps_its_throttle_and_friendly_wrapper(): void
    {
        $middleware = Route::getRoutes()->getByName('customer.help-request')->gatherMiddleware();

        $this->assertContains('throttle:help-request', $middleware);
        $this->assertContains('throttle.friendly', $middleware);
    }

    public function test_the_per_ip_ceiling_rejects_the_next_request_with_a_friendly_answer(): void
    {
        $ceiling = RateLimitServiceProvider::HELP_REQUEST_PER_IP;
        $branch = $this->branch();

        // Pick-up posts are refused by the controller and write nothing, so
        // this spends the budget without touching the table. Each request
        // gets a fresh session, so it is the per-IP half that runs out.
        for ($i = 0; $i < $ceiling; $i++) {
            $this->withSession(['order_type' => 'pick_up', 'branch_id' => $branch->id])
                ->post('/customer/help-request', ['message' => 'x'])
                ->assertHeaderMissing('X-RateLimit-Rejected');
        }

        $this->withSession($this->dineInSession($branch))
            ->post('/customer/help-request', ['message' => 'I need staff assistance'])
            ->assertHeader('X-RateLimit-Rejected', '1');

        $this->assertSame(0, HelpRequest::where('branch_id', $branch->id)->count());
    }
}

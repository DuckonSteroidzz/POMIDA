<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The dine-in guest's own fifteen-minute inactivity clock.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE ONE THING THAT MAKES THIS FEATURE HARD
 * ─────────────────────────────────────────────────────────────────────────────
 * There are two endpoints, and the whole design lives in the difference between
 * them:
 *
 *   POST /customer/table-activity        WRITES the clock. Driven by a real
 *                                        interaction — scroll, tap, key.
 *
 *   GET  /customer/table-session-status  READS the clock and nothing else.
 *                                        Driven by the page looking at itself,
 *                                        on load and whenever the tab regains
 *                                        focus.
 *
 * If the read ever wrote, the check that exists to catch a phone left face-up
 * on a table would be the very thing keeping that phone's session alive — and
 * because it fires on every focus event, it would keep it alive forever. That
 * is the failure this suite is mostly about; see the "never extends" cases.
 *
 * The second thing worth stating plainly: this clock is NOT
 * TableOccupancy::sweepIdle(). That is ninety minutes, it reads last_seen_at,
 * it releases the occupancy row for the benefit of the staff panel, and it is
 * untouched by any of this. The cases lower down prove it, both on a table
 * being pinged and on a table that has never heard of the ping.
 *
 * Table numbers are prefixed IDL so nothing here can collide with another
 * suite's tables. DatabaseTransactions rolls every row back — no cleanup pass
 * is needed and none is performed.
 */
class DineInGuestInactivityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
        parent::tearDown();
    }

    // ══════════ helpers ══════════

    /** Sit a guest at a table the way a phone's camera app opening the QR does. */
    private function seat(string $table, int $branchId = 1): TableSession
    {
        $code = TableEntry::findOrRegister($branchId, $table)->code;

        $this->get("/customer/menu?branch_id={$branchId}&table={$table}&k={$code}")->assertOk();

        $session = TableOccupancy::activeFor($branchId, $table);

        $this->assertNotNull($session, "seating at table {$table} did not open an occupancy");

        return $session;
    }

    /** Wind this session's activity clock back, without touching anything else. */
    private function idleFor(TableSession $session, int $minutes): void
    {
        DB::table('table_sessions')
            ->where('id', $session->id)
            ->update(['last_activity_at' => now()->subMinutes($minutes)]);
    }

    /** The same, at second resolution, for the boundary case. */
    private function idleForSeconds(TableSession $session, int $seconds): void
    {
        DB::table('table_sessions')
            ->where('id', $session->id)
            ->update(['last_activity_at' => now()->subSeconds($seconds)]);
    }

    private function check()
    {
        return $this->getJson('/customer/table-session-status');
    }

    private function ping()
    {
        return $this->postJson('/customer/table-activity');
    }

    private function activityOf(int $id): ?string
    {
        return DB::table('table_sessions')->where('id', $id)->value('last_activity_at');
    }

    private function seenOf(int $id): ?string
    {
        return DB::table('table_sessions')->where('id', $id)->value('last_seen_at');
    }

    // ══════════ the active customer ══════════

    /**
     * The case that must never break: somebody who is actually there.
     */
    public function test_an_active_customer_is_never_redirected(): void
    {
        $session = $this->seat('IDL1');

        $this->check()->assertOk()->assertJson(['valid' => true]);

        // Fourteen minutes of quiet is still inside the window.
        $this->idleFor($session, TableOccupancy::GUEST_IDLE_MINUTES - 1);

        $response = $this->check();
        $response->assertOk()->assertJson(['valid' => true]);
        $this->assertArrayNotHasKey('redirect', $response->json());
        $this->assertNull(session('error'), 'an active customer must not be handed an error');
    }

    /**
     * A ping mid-visit puts the full fifteen minutes back, which is the only
     * reason an hour of browsing works at all.
     */
    public function test_the_activity_ping_extends_the_window(): void
    {
        $session = $this->seat('IDL2');

        // Well past the window — this session would be expired right now.
        $this->idleFor($session, TableOccupancy::GUEST_IDLE_MINUTES + 1);
        $this->check()->assertJson(['valid' => false]);

        $this->ping()->assertOk()->assertJson(['recorded' => true]);

        $this->check()->assertOk()->assertJson(['valid' => true]);
    }

    /** And the ping is what actually moves the column, not a side effect. */
    public function test_the_activity_ping_writes_the_activity_column(): void
    {
        $session = $this->seat('IDL3');

        $this->idleFor($session, 10);
        $before = $this->activityOf($session->id);

        $this->ping()->assertOk();

        $after = $this->activityOf($session->id);

        $this->assertNotSame($before, $after, 'the ping must move last_activity_at');
        $this->assertTrue(
            Carbon::parse($after)->gt(Carbon::parse($before)),
            'the ping must move last_activity_at forward'
        );
    }

    // ══════════ the customer who walked away ══════════

    public function test_a_customer_idle_past_the_window_is_expired_with_the_exact_message(): void
    {
        $session = $this->seat('IDL4');

        $this->idleFor($session, TableOccupancy::GUEST_IDLE_MINUTES + 1);

        $response = $this->check();

        $response->assertOk()->assertJson([
            'valid'    => false,
            'redirect' => route('customer.dineinqr'),
        ]);

        $this->assertSame(
            'Session expired due to inactivity. Please scan table QR code again.',
            session('error'),
            'the expiry message must be the exact agreed sentence'
        );
    }

    /**
     * The message has to arrive through the SAME flash the QR-stale refusal
     * uses, or the code-entry page will not render it in the existing alert.
     */
    public function test_the_expiry_message_renders_in_the_existing_code_entry_alert(): void
    {
        $session = $this->seat('IDL5');
        $this->idleFor($session, TableOccupancy::GUEST_IDLE_MINUTES + 1);

        $this->check()->assertJson(['valid' => false]);

        $page = $this->get('/customer/dineinqr');

        $page->assertOk();
        $page->assertSee('Session expired due to inactivity. Please scan table QR code again.', false);
        // The same markup ERR_QR_STALE lands in — no new alert was invented.
        $page->assertSee('class="manual-error" role="alert"', false);
    }

    /**
     * The boundary really is fifteen minutes, to the second — not fourteen and
     * not twenty.
     *
     * Ten seconds either side rather than exactly on it: the cutoff is computed
     * from now() at the moment of the request, which is necessarily a hair
     * later than the moment the stamp was written, so "exactly on the line" is
     * a coin toss rather than a rule. Ten seconds is far tighter than any
     * plausible wrong threshold and immune to that.
     */
    public function test_the_boundary_is_fifteen_minutes(): void
    {
        $session = $this->seat('IDL6');
        $window = TableOccupancy::GUEST_IDLE_MINUTES * 60;

        $this->idleForSeconds($session, $window - 10);
        $this->check()->assertJson(['valid' => true]);

        $this->idleForSeconds($session, $window + 10);
        $this->check()->assertJson(['valid' => false]);
    }

    // ══════════ the read must never write ══════════

    /**
     * THE CENTRAL CASE. A phone left on a table with the menu open fires
     * visibilitychange and focus events all by itself. If the passive check
     * refreshed the clock, that session would never expire.
     */
    public function test_repeated_passive_checks_never_delay_expiry(): void
    {
        $session = $this->seat('IDL7');

        // Fourteen minutes in: still valid, and about to expire.
        $this->idleFor($session, TableOccupancy::GUEST_IDLE_MINUTES - 1);

        $stamp = $this->activityOf($session->id);

        for ($i = 0; $i < 20; $i++) {
            $this->check()->assertOk()->assertJson(['valid' => true]);
        }

        $this->assertSame(
            $stamp,
            $this->activityOf($session->id),
            'the passive check wrote to last_activity_at — it must be read-only'
        );

        // Two more minutes of real time pass. Twenty checks bought nothing:
        // the session expires exactly when it was always going to.
        $this->travel(2)->minutes();

        $this->check()->assertJson(['valid' => false]);

        $this->travelBack();
    }

    /** It must not touch last_seen_at either — that column belongs to sweepIdle. */
    public function test_the_passive_check_touches_nothing_at_all(): void
    {
        $session = $this->seat('IDL8');

        /*
         * Backdated first, and this matters. These columns are second-
         * resolution, so a freshly seated row and a stray write a few
         * milliseconds later are the SAME VALUE — the assertions below would
         * hold even for a check that writes. Winding the clock back five
         * minutes makes any write visible.
         */
        $this->idleFor($session, 5);

        $row = DB::table('table_sessions')->where('id', $session->id)->first();

        for ($i = 0; $i < 5; $i++) {
            $this->check()->assertOk();
        }

        $after = DB::table('table_sessions')->where('id', $session->id)->first();

        $this->assertSame($row->last_seen_at, $after->last_seen_at);
        $this->assertSame($row->last_activity_at, $after->last_activity_at);
        $this->assertSame($row->updated_at, $after->updated_at);
        $this->assertNotNull($after->active_lock, 'the check must not release anything');
    }

    // ══════════ scope: nothing else may be affected ══════════

    /**
     * sweepIdle() is a different feature on a different column with a different
     * threshold. A table being pinged constantly must look EXACTLY the same to
     * it as it did before this feature existed.
     */
    public function test_the_activity_ping_never_touches_the_column_sweep_idle_reads(): void
    {
        $session = $this->seat('IDL9');

        // Backdated for the same second-resolution reason as the case above:
        // a stray write to last_seen_at moments after seating would otherwise
        // be indistinguishable from the value already there.
        DB::table('table_sessions')->where('id', $session->id)
            ->update(['last_seen_at' => now()->subMinutes(5)]);

        $seenBefore = $this->seenOf($session->id);

        for ($i = 0; $i < 5; $i++) {
            $this->ping()->assertOk()->assertJson(['recorded' => true]);
        }

        $this->assertSame(
            $seenBefore,
            $this->seenOf($session->id),
            'the activity ping wrote last_seen_at — sweepIdle would no longer behave as before'
        );
    }

    /**
     * And the ninety-minute rule still fires on its own schedule, on a table
     * that knows nothing about any of this.
     */
    public function test_sweep_idle_still_releases_other_tables_at_ninety_minutes(): void
    {
        // A guest at IDLA pinging away, and a walked-away party at IDLB.
        $live = $this->seat('IDLA');

        for ($i = 0; $i < 3; $i++) {
            $this->ping()->assertOk();
        }

        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');

        $abandoned = $this->seat('IDLB');

        DB::table('table_sessions')->where('id', $abandoned->id)->update([
            'last_seen_at' => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES + 1),
            'created_at'   => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES + 1),
            // Deliberately fresh: the guest clock must have no say here.
            'last_activity_at' => now(),
        ]);

        $this->assertGreaterThanOrEqual(1, TableOccupancy::sweepIdle());

        $released = TableSession::find($abandoned->id);

        $this->assertNull(
            $released->active_lock,
            'the ninety-minute rule must still release a silent table'
        );
        $this->assertSame('abandoned', $released->release_reason);

        // And the pinged table, which is nowhere near ninety minutes, is untouched.
        $this->assertNotNull(TableSession::find($live->id)->active_lock);
    }

    /** Under ninety minutes is still left alone, exactly as before. */
    public function test_sweep_idle_still_leaves_a_briefly_idle_table_alone(): void
    {
        $session = $this->seat('IDLC');

        DB::table('table_sessions')->where('id', $session->id)->update([
            // Long past the GUEST window, nowhere near the sweep threshold.
            'last_seen_at'     => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES - 5),
            'last_activity_at' => now()->subMinutes(TableOccupancy::GUEST_IDLE_MINUTES + 30),
        ]);

        TableOccupancy::sweepIdle();

        $this->assertNotNull(
            TableSession::find($session->id)->active_lock,
            'an expired GUEST session must not release the occupancy row early'
        );
    }

    /**
     * The two thresholds are separate numbers and must stay that way — a
     * refactor that made one read the other would pass every case above.
     */
    public function test_the_two_thresholds_are_independent(): void
    {
        $this->assertSame(90, TableOccupancy::INACTIVITY_MINUTES);
        $this->assertSame(15, TableOccupancy::GUEST_IDLE_MINUTES);
    }

    // ══════════ everyone who is not a dine-in guest ══════════

    /**
     * A pick-up customer holds no table token. Neither endpoint may do anything
     * to them, and above all the check must never tell them to go and scan a QR
     * that does not apply to their order.
     */
    public function test_a_pick_up_customer_is_untouched(): void
    {
        $this->withSession(['order_type' => 'pick_up', 'branch_id' => 1]);

        $this->check()->assertOk()->assertJson(['valid' => true]);
        $this->ping()->assertOk()->assertJson(['recorded' => false]);

        $this->assertNull(session('error'));
        $this->get('/customer/menu')->assertOk();
    }

    /**
     * Admin and staff sign in on the `admin` guard. Neither endpoint reads that
     * guard, writes to it, or can log anybody out of it.
     */
    public function test_admin_sessions_are_untouched(): void
    {
        $admin = User::where('email', 'admin@peachycafe.com')->firstOrFail();

        $this->actingAs($admin, 'admin');

        $this->check()->assertOk()->assertJson(['valid' => true]);
        $this->ping()->assertOk()->assertJson(['recorded' => false]);

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertNull(session('error'));

        // The staff panel still answers, so nothing about their session moved.
        $this->getJson('/admin/tables/occupancy')->assertOk();
    }

    public function test_staff_sessions_are_untouched(): void
    {
        $staff = User::where('email', 'simon@peachy.com')->firstOrFail();

        $this->actingAs($staff, 'admin');

        $this->check()->assertOk()->assertJson(['valid' => true]);
        $this->ping()->assertOk()->assertJson(['recorded' => false]);

        $this->assertAuthenticatedAs($staff, 'admin');
    }

    /**
     * A logged-in DINE-IN customer is still just a dine-in guest as far as this
     * clock is concerned — and expiring their table session must not log them
     * out of their account.
     */
    public function test_expiry_does_not_log_a_customer_out_of_their_account(): void
    {
        $customer = User::where('role', 'customer')->firstOrFail();

        $this->actingAs($customer, 'customer');

        $session = $this->seat('IDLD');
        $this->idleFor($session, TableOccupancy::GUEST_IDLE_MINUTES + 1);

        $this->check()->assertJson(['valid' => false]);

        $this->assertAuthenticatedAs($customer, 'customer');
    }

    // ══════════ the rest of the dine-in flow ══════════

    /**
     * A released occupancy — the ordinary end of a meal — is NOT an expiry.
     * Bouncing a customer to the code-entry page the moment their order
     * completes would be a rule nobody asked for.
     */
    public function test_a_released_occupancy_is_not_reported_as_expired(): void
    {
        $this->seat('IDLE');

        TableOccupancy::releaseTable(1, 'IDLE', null);

        $this->check()->assertOk()->assertJson(['valid' => true]);
        $this->assertNull(session('error'));
    }

    /**
     * The structural half of the check is TableEntry::validate()'s, not a
     * second copy of it — so a table taken out of service mid-visit produces
     * validate()'s own sentence.
     */
    public function test_a_table_taken_out_of_service_reuses_the_entry_refusal(): void
    {
        $this->seat('IDLF');

        TableEntry::find(1, 'IDLF')->forceFill(['is_active' => false])->save();

        $this->check()->assertOk()->assertJson(['valid' => false]);

        $this->assertSame(TableEntry::ERR_TABLE_INACTIVE, session('error'));
    }

    /** Same for a branch that closes mid-visit. */
    public function test_a_closed_branch_reuses_the_entry_refusal(): void
    {
        $branch = Branch::where('is_active', true)->firstOrFail();

        $this->seat('IDLG', $branch->id);

        $branch->forceFill(['is_active' => false])->save();

        $this->check()->assertOk()->assertJson(['valid' => false]);

        $this->assertSame(TableEntry::ERR_CLOSED_BRANCH, session('error'));
    }

    /**
     * A second device joining a still-occupied table (the table-of-four case)
     * gets the shared session AND a fresh clock — it must not inherit the first
     * device's staleness.
     */
    public function test_a_second_device_joining_a_table_starts_the_clock_fresh(): void
    {
        $first = $this->seat('IDLH');

        $this->idleFor($first, TableOccupancy::GUEST_IDLE_MINUTES + 1);

        // A different phone, same standee.
        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');

        $second = $this->seat('IDLH');

        $this->assertSame($first->id, $second->id, 'the second device must JOIN, not open a rival session');

        $this->check()->assertOk()->assertJson(['valid' => true]);
    }

    // ══════════ wiring ══════════

    /** The menu only carries the client half for a dine-in session. */
    public function test_the_inactivity_script_is_dine_in_only(): void
    {
        $this->seat('IDLJ');

        $this->get('/customer/menu')
            ->assertOk()
            ->assertSee('table-session-status', false)
            ->assertSee('table-activity', false);

        $this->flushSession();
        $this->withSession(['order_type' => 'pick_up', 'branch_id' => 1]);

        $this->get('/customer/menu')
            ->assertOk()
            ->assertDontSee('table-session-status', false)
            ->assertDontSee('table-activity', false);
    }

    /** The client throttle is the 60-second one that was agreed. */
    public function test_the_client_throttles_the_ping_to_once_a_minute(): void
    {
        $source = file_get_contents(base_path('resources/views/customer/menu.blade.php'));

        $this->assertStringContainsString('PING_EVERY_MS = 60000', $source);

        foreach (['scroll', 'click', 'touchstart', 'keydown'] as $event) {
            $this->assertStringContainsString("'{$event}'", $source);
        }

        $this->assertStringContainsString('visibilitychange', $source);
    }
}

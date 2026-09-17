<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Staff pressing Clear on the Occupied Tables panel must end the CUSTOMER's
 * session, not just the row in the database.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE BUG THIS SUITE EXISTS FOR
 * ─────────────────────────────────────────────────────────────────────────────
 * Clear nulls active_lock. TableOccupancy::currentGuestSession() only ever
 * looks at rows where active_lock is still set. inspectGuestSession() opens
 * with "no live session is not a failure" and answers `valid`. So the customer's
 * own poll — the mechanism that exists precisely to notice this kind of thing —
 * was told everything was fine the moment staff ended the visit, and the phone
 * at the table carried on browsing and adding to a cart for a table the panel
 * had already handed back to the floor.
 *
 * Every case below is written so it FAILS against that behaviour and passes
 * against the fix. The load-bearing one is the pair at the top: staff_cleared
 * bounces, order_completed does not.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT MUST *NOT* BOUNCE, AND WHY IT HAS AS MANY CASES AS WHAT MUST
 * ─────────────────────────────────────────────────────────────────────────────
 * releaseRow() frees a table for four different reasons and only one of them is
 * somebody deciding to end a visit. order_completed in particular fires on the
 * wholly ordinary "the food came and the bill is settled" path — a fix that
 * bounced on any released row would throw every customer off their receipt at
 * the exact moment their meal finished. That regression is cheaper to prevent
 * than to notice, so it is pinned three ways: completed, cancelled, and the
 * ninety-minute abandonment sweep.
 *
 * Table numbers are prefixed SC so nothing here can collide with another
 * suite's tables. DatabaseTransactions rolls every row back — no cleanup pass
 * is needed and none is performed.
 */
class StaffClearEndsCustomerSessionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLimiters();
    }

    protected function tearDown(): void
    {
        $this->clearLimiters();
        parent::tearDown();
    }

    /**
     * The entry limiters plus place-order's per-IP half.
     *
     * CACHE_STORE is `array`, so a limiter counter lives for the whole PHPUnit
     * process rather than one test — the checkout cases below would otherwise
     * be counting each other's requests, and a suite that passes alone but 429s
     * when run with its neighbours is worse than no suite. The per-session half
     * needs no clearing: the test client issues a new session per test.
     */
    private function clearLimiters(): void
    {
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
        RateLimiter::clear('table-session|ip:127.0.0.1');
        RateLimiter::clear('place-order|ip:127.0.0.1');
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

    /** Press Clear on the Occupied Tables panel, as a real staff member would. */
    private function staffClears(string $table, int $branchId = 1): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/tables/clear', [
                'branch_id'    => $branchId,
                'table_number' => $table,
            ])
            ->assertOk();

        $this->assertNull(
            TableOccupancy::activeFor($branchId, $table),
            'the clear did not actually release the table'
        );
    }

    /** What the customer's page asks, on its interval. */
    private function poll()
    {
        return $this->getJson('/customer/table-session-status');
    }

    private function makeOrder(int $branchId, string $table, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'SC-' . substr(uniqid(), -8),
            'user_id'      => null,
            'branch_id'    => $branchId,
            'type'         => 'dine_in',
            'table_number' => $table,
            'status'       => 'pending',
            'subtotal'     => 100,
            'total'        => 100,
        ], $attrs));
    }

    // ══════════ the bug ══════════

    public function test_the_customer_poll_reports_invalid_after_staff_clear(): void
    {
        $this->seat('SC01');

        // Before the clear: nothing to report.
        $this->poll()->assertOk()->assertJson(['valid' => true]);

        $this->staffClears('SC01');

        $this->poll()
            ->assertOk()
            ->assertJson([
                'valid'    => false,
                'redirect' => route('customer.dineinqr'),
            ]);
    }

    public function test_the_customer_is_told_staff_ended_it_and_not_that_it_expired(): void
    {
        $this->seat('SC02');
        $this->staffClears('SC02');

        $this->poll()->assertOk()->assertJson(['valid' => false]);

        // The wording is the point: nothing expired and nothing is broken, so
        // "scan again, it timed out" would send the customer looking for a
        // fault that does not exist.
        $this->assertSame(
            TableOccupancy::ERR_SESSION_ENDED_BY_STAFF,
            session('error')
        );
        $this->assertSame('This session has been ended by staff.', session('error'));
        $this->assertStringNotContainsStringIgnoringCase('inactivity', (string) session('error'));
    }

    public function test_the_cart_is_cleared_when_staff_end_the_session(): void
    {
        $this->seat('SC03');

        session()->put('cart', [
            ['id' => 1, 'name' => 'Peach Tart', 'price' => 120, 'quantity' => 2],
        ]);

        $this->assertNotEmpty(session('cart'));

        $this->staffClears('SC03');
        $this->poll()->assertOk()->assertJson(['valid' => false]);

        $this->assertEmpty(
            session('cart'),
            'a cart priced for a table the customer no longer holds must not survive'
        );
    }

    public function test_the_dine_in_session_keys_are_torn_down(): void
    {
        $this->seat('SC04');

        $this->assertNotNull(session(TableOccupancy::SESSION_KEY));
        $this->assertSame('dine_in', session('order_type'));

        $this->staffClears('SC04');
        $this->poll()->assertOk()->assertJson(['valid' => false]);

        $this->assertNull(
            session(TableOccupancy::SESSION_KEY),
            'the table token must not survive a staff clear'
        );
        $this->assertNull(session('order_type'));
        $this->assertNull(session('table_number'));
    }

    public function test_a_signed_in_customer_is_not_logged_out_by_a_staff_clear(): void
    {
        $customer = User::where('role', 'customer')->first();

        if (!$customer) {
            $this->markTestSkipped('no customer account in this database');
        }

        $this->actingAs($customer, 'customer');
        $this->seat('SC05');
        $this->staffClears('SC05');

        $this->poll()->assertOk()->assertJson(['valid' => false]);

        // Ending a dine-in visit is not a reason to take away someone's
        // account, their points or their vouchers.
        $this->assertTrue(
            auth()->guard('customer')->check(),
            'a staff clear must end the table session, not the login'
        );
    }

    // ══════════ what must NOT bounce ══════════

    public function test_a_completed_order_does_not_bounce_the_customer(): void
    {
        $session = $this->seat('SC06');

        $order = $this->makeOrder(1, 'SC06');
        TableOccupancy::attachOrder($order);

        // The ordinary end of a meal. Order::booted() releases the table.
        $order->status = 'completed';
        $order->save();

        $this->assertNull(TableOccupancy::activeFor(1, 'SC06'), 'the order did not release the table');

        $row = TableSession::findOrFail($session->id);
        $this->assertSame('order_completed', $row->release_reason);

        // Released, but for an entirely normal reason — the customer keeps
        // their page and their cart.
        $this->poll()->assertOk()->assertJson(['valid' => true]);
        $this->assertNotNull(session(TableOccupancy::SESSION_KEY));
    }

    public function test_a_cancelled_order_does_not_bounce_the_customer(): void
    {
        $session = $this->seat('SC07');

        $order = $this->makeOrder(1, 'SC07');
        TableOccupancy::attachOrder($order);

        $order->status = 'cancelled';
        $order->save();

        $row = TableSession::findOrFail($session->id);
        $this->assertSame('order_cancelled', $row->release_reason);

        // Cancelling an ORDER is a different thing from cancelling a SESSION,
        // and this endpoint must not confuse the two — order cancellation has
        // its own notification path and is out of this feature's scope.
        $this->poll()->assertOk()->assertJson(['valid' => true]);
    }

    public function test_an_abandoned_table_does_not_report_a_staff_clear(): void
    {
        $session = $this->seat('SC08');

        // Age it past the ninety-minute sweep.
        DB::table('table_sessions')
            ->where('id', $session->id)
            ->update([
                'last_seen_at' => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES + 5),
                'created_at'   => now()->subMinutes(TableOccupancy::INACTIVITY_MINUTES + 5),
            ]);

        TableOccupancy::sweepIdle();

        $row = TableSession::findOrFail($session->id);
        $this->assertSame('abandoned', $row->release_reason);

        $this->assertNull(
            TableOccupancy::staffClearedSession(),
            'a sweep is not a staff decision and must never be reported as one'
        );
    }

    public function test_a_normal_status_change_does_not_bounce_the_customer(): void
    {
        $this->seat('SC09');

        $order = $this->makeOrder(1, 'SC09');
        TableOccupancy::attachOrder($order);

        // pending → preparing → serving: every non-final value orders.status
        // actually has. Not one of them is a release, and not one of them may
        // interrupt the customer's page — which is the whole of requirement
        // "don't trigger on normal status changes".
        foreach (['preparing', 'serving'] as $status) {
            $order->status = $status;
            $order->save();

            $this->poll()->assertOk()->assertJson(['valid' => true]);
        }

        $this->assertNotNull(TableOccupancy::activeFor(1, 'SC09'), 'the table should still be held');
    }

    // ══════════ the idle clock is untouched ══════════

    public function test_the_idle_message_is_still_its_own(): void
    {
        $session = $this->seat('SC10');

        DB::table('table_sessions')
            ->where('id', $session->id)
            ->update(['last_activity_at' => now()->subMinutes(TableOccupancy::GUEST_IDLE_MINUTES + 1)]);

        $this->poll()->assertOk()->assertJson(['valid' => false]);

        // Idle is still idle. It reports its own sentence, and — unlike a staff
        // clear — it does NOT tear the session down: the customer simply scans
        // back in, and the table was never handed to anyone else.
        $this->assertSame(TableOccupancy::ERR_SESSION_IDLE, session('error'));
        $this->assertNotNull(session(TableOccupancy::SESSION_KEY));
    }

    public function test_polling_after_a_staff_clear_still_never_writes_to_the_table(): void
    {
        $session = $this->seat('SC11');
        $this->staffClears('SC11');

        $before = TableSession::findOrFail($session->id);

        for ($i = 0; $i < 5; $i++) {
            $this->poll();
        }

        $after = TableSession::findOrFail($session->id);

        // The read-only contract this endpoint has always carried: the answer
        // must never be the reason anything about the occupancy changes.
        $this->assertNull($after->active_lock, 'a poll must not resurrect a cleared table');
        $this->assertSame(
            (string) $before->released_at,
            (string) $after->released_at,
            'a poll must not rewrite the release'
        );
        $this->assertSame($before->release_reason, $after->release_reason);
    }

    // ══════════ recovery, and not reading someone else's table ══════════

    public function test_scanning_back_in_recovers_a_cleared_session(): void
    {
        $this->seat('SC12');
        $this->staffClears('SC12');

        $this->poll()->assertOk()->assertJson(['valid' => false]);

        // The instruction the customer is given has to actually work.
        RateLimiter::clear('qr-scan:127.0.0.1');
        $this->seat('SC12');

        $this->poll()->assertOk()->assertJson(['valid' => true]);
        $this->assertNull(
            TableOccupancy::staffClearedSession(),
            'a fresh scan writes a new token; the stale cleared row must stop matching'
        );
    }

    public function test_a_cleared_token_cannot_resolve_to_a_new_partys_live_session(): void
    {
        $first = $this->seat('SC13');
        $staleToken = $first->session_token;

        $this->staffClears('SC13');

        // A new party sits down at the same table, in a different browser.
        $this->flushSession();
        RateLimiter::clear('qr-scan:127.0.0.1');
        $second = $this->seat('SC13');

        $this->assertNotSame(
            $staleToken,
            $second->session_token,
            'a re-opened table must issue a new token'
        );

        // The first party's phone, still holding the old token, is told its own
        // session ended — it must never be handed the new party's live row.
        $this->flushSession();
        session()->put(TableOccupancy::SESSION_KEY, $staleToken);

        $cleared = TableOccupancy::staffClearedSession();

        $this->assertNotNull($cleared);
        $this->assertSame($first->id, $cleared->id);
        $this->assertNull($cleared->active_lock);
    }

    // ══════════ the server half: checkout is refused, not just redirected ══════════

    /**
     * A checkout payload that gets PAST validation.
     *
     * placeOrder() validates `items` before anything else looks at the table,
     * so a payload without a real menu item would be turned away by the
     * validator and the case below would pass without ever reaching the guard
     * it exists to test.
     */
    private function checkoutPayload(string $table): array
    {
        $item = \App\Models\MenuItem::query()->first();

        if (!$item) {
            $this->markTestSkipped('no menu items in this database');
        }

        return [
            'order_type'     => 'dine_in',
            'table_number'   => $table,
            'payment_method' => 'cash',
            'items'          => [
                ['menu_item_id' => $item->id, 'quantity' => 1],
            ],
        ];
    }

    public function test_checkout_is_refused_on_a_staff_cleared_table(): void
    {
        $this->seat('SC14');
        $this->staffClears('SC14');

        // A client that never polled, or ignored the answer, still must not be
        // able to put an order on a table the floor has taken back.
        $this->post('/customer/place-order', $this->checkoutPayload('SC14'))
            ->assertRedirect(route('customer.dineinqr'));

        $this->assertSame(TableOccupancy::ERR_SESSION_ENDED_BY_STAFF, session('error'));

        $this->assertDatabaseMissing('orders', [
            'table_number' => 'SC14',
            'type'         => 'dine_in',
        ]);

        // The cart goes here too — the redirect is a refusal, not a detour the
        // customer can come back from with the same basket.
        $this->assertEmpty(session('cart'));
    }

    public function test_checkout_is_not_refused_on_a_live_table(): void
    {
        $this->seat('SC15');

        // NOT asserting a successful order: checkout has its own long list of
        // requirements (stock, pricing, payment) and this suite owns none of
        // them. The single thing pinned here is that the new guard is not what
        // turns this away — a live table must never be sent to the code-entry
        // page with the staff-cleared message.
        $response = $this->post('/customer/place-order', $this->checkoutPayload('SC15'));

        $this->assertNotSame(
            TableOccupancy::ERR_SESSION_ENDED_BY_STAFF,
            session('error'),
            'a live table must not be refused as staff-cleared'
        );

        if ($response->isRedirect()) {
            $this->assertNotSame(
                route('customer.dineinqr'),
                $response->headers->get('Location'),
                'a live table must not be bounced to the code-entry page'
            );
        }
    }

    // ══════════ the page actually carries the guard ══════════

    public function test_the_dine_in_pages_poll_for_cancellation(): void
    {
        $this->seat('SC16');

        $statusUrl = route('customer.table-session-status');

        foreach (['/customer/menu', '/customer/cart'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee($statusUrl)
                ->assertSee('setInterval(check', false);
        }
    }

    public function test_a_pick_up_customer_page_carries_no_table_guard(): void
    {
        session()->put('order_type', 'pick_up');
        session()->put('branch_id', 1);

        // Nothing about this feature may appear on a page with no table behind
        // it — a pick-up customer has no occupancy to lose.
        $this->get('/customer/menu')
            ->assertOk()
            ->assertDontSee(route('customer.table-session-status'));
    }
}

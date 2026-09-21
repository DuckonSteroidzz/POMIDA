<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\User;
use App\Models\Voucher;
use App\Services\InventoryDeductionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The walk-in counter's duplicate-submit guard (Phase 3a audit, Finding F8).
 *
 * THE GAP THIS EXISTS FOR
 * -----------------------
 * The customer checkout has had two guards since 2026-09-01: a synchronous
 * `confirmButton.disabled` check in confirmOrderNow() and a server-side
 * Cache::lock() mutex in OrderController::placeOrder() released in a finally.
 * The counter — the OTHER door into the same pantry, and the one that writes
 * payment_status = 'paid' on the spot — had neither. Its submit handler
 * validated the item count and the amount paid and then let the browser post as
 * many times as it was asked to, and storeManualOrder() took no mutex at all.
 *
 * The existing DB::transaction + lockForUpdate stock gate is NOT this. That
 * gate is correct and is deliberately untouched: it stops two orders being
 * waved through on the same last serving. It does nothing about two orders that
 * are each perfectly valid — a double click, a second dashboard tab, a client
 * or proxy resending the POST — which is a real duplicate charge on a real
 * customer.
 *
 * WHAT AN HONEST TEST OF THIS LOOKS LIKE
 * --------------------------------------
 * Two sequential controller calls prove NOTHING here: by the time the second
 * starts, the first has finished and released, so both succeed even with no
 * mutex at all. Every contention test below therefore puts the system into the
 * state the mutex exists to detect — the lock genuinely held, asserted as part
 * of the setup — before the request under test is made, exactly as
 * OrderConfirmModalTest does for the customer path.
 *
 * WHAT THE MUTEX IS NOT
 * ---------------------
 * It is not idempotency. Once the first request commits and the finally
 * releases, an identical resubmission is indistinguishable from staff ringing
 * up the next customer, and it creates a second order.
 * test_a_retry_after_the_first_order_committed_still_creates_a_second_order()
 * pins that deliberately: this system has no request token, no idempotency key
 * and no column to keep one on, so the limit is recorded rather than assumed
 * away. (In practice every response from storeManualOrder() is a redirect, so
 * the browser's own back button cannot re-POST it.)
 */
class ManualOrderDuplicateSubmitTest extends TestCase
{
    use DatabaseTransactions;

    /*
    |--------------------------------------------------------------------------
    | FIXTURES
    |--------------------------------------------------------------------------
    */

    /**
     * An orderable branch-1 menu item that is ALSO in stock right now.
     *
     * Same reasoning as ManualOrderVoucherTest::item(): storeManualOrder() runs
     * a pre-flight inventory check before anything this file cares about, so an
     * item whose recipe touches a depleted ingredient would fail every
     * assertion here for a reason that has nothing to do with duplicate
     * submission.
     */
    private static ?MenuItem $picked = null;

    private function item(): MenuItem
    {
        if (self::$picked !== null) {
            return self::$picked;
        }

        $service = app(InventoryDeductionService::class);

        $candidates = MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->where('price', '>', 0)
            ->orderBy('id')
            ->get();

        foreach ($candidates as $candidate) {
            // Room for two units, because several tests below place a second,
            // legitimate order after the first.
            $errors = $service->validateCartLines([[
                'menu_item'           => $candidate,
                'quantity'            => 2,
                'selected_option_ids' => [],
            ]]);

            if (empty($errors)) {
                return self::$picked = $candidate;
            }
        }

        $this->fail('no in-stock branch-1 menu item available to order in this test');
    }

    private function staff(): User
    {
        return User::where('email', 'simon@peachy.com')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@peachycafe.com')->firstOrFail();
    }

    /**
     * A valid Laravel session id: exactly 40 alphanumeric characters, which is
     * what Store::isValidId() demands before StartSession will adopt the one
     * the request carries instead of minting a fresh one.
     */
    private function sid(string $seed): string
    {
        return substr(str_pad(preg_replace('/[^A-Za-z0-9]/', '', $seed), 40, 'x'), 0, 40);
    }

    /**
     * The exact key AdminController::manualOrderLockKey() builds.
     *
     * Written out as a literal here rather than reached for through the
     * controller, so a silent change to the key format shows up as a contention
     * test that stops contending — which is the one failure mode this whole
     * file has to be immune to.
     */
    private function lockKey(User $staff, string $sessionId): string
    {
        return 'manual-order-inflight:admin:' . $staff->id . ':' . $sessionId;
    }

    /** What the Manual Order modal posts for one of the picked item. */
    private function payload(array $override = []): array
    {
        return array_merge([
            'branch_id'      => 1,
            'order_type'     => 'pick_up',
            'table_number'   => '',
            'payment_method' => 'cash',
            'amount_paid'    => '100000',
            'items'          => [
                (string) $this->item()->id => [
                    'menu_item_id' => (string) $this->item()->id,
                    'quantity'     => '1',
                    'options'      => [],
                ],
            ],
        ], $override);
    }

    /**
     * Post the manual order AS a given till: one staff account in one browser
     * session.
     *
     * The session cookie is pinned because Laravel's test client sends no
     * cookies of its own, so StartSession would mint a brand new session id for
     * every request and the till's identity — half the lock key — would change
     * between two requests that are meant to come from the same browser.
     * Pinning it is what makes "the same till" and "a different till"
     * expressible at all.
     */
    private function submit(User $staff, string $sessionId, array $payload)
    {
        return $this->actingAs($staff, 'admin')
            ->withCookie(config('session.cookie'), $sessionId)
            ->from('/admin/home')
            ->post('/admin/manual-order', $payload);
    }

    /** Orders created since a high-water mark, newest first. */
    private function ordersSince(int $mark)
    {
        return DB::table('orders')->where('id', '>', $mark)->orderByDesc('id')->get();
    }

    /** The inventory rows one of the picked item actually consumes. */
    private function ingredientIds(): array
    {
        return array_keys(
            app(InventoryDeductionService::class)
                ->requirementsForLine($this->item(), 1, [], 1)
        );
    }

    /** A public promo code, for the "nothing was spent" invariant. */
    private function voucher(): Voucher
    {
        return Voucher::create([
            'code'            => 'MDS' . strtoupper(substr(uniqid(), -7)),
            'description'     => 'manual order duplicate-submit test',
            'discount_type'   => 'fixed',
            'discount_value'  => 5,
            'max_uses'        => 100,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'expires_at'      => now()->addMonth(),
            'valid_from'      => null,
            'is_active'       => true,
            'points_required' => 0,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. THE ORDINARY CASE STILL WORKS
    |--------------------------------------------------------------------------
    */

    public function test_a_single_counter_order_still_succeeds(): void
    {
        $staff = $this->staff();
        $mark  = (int) (DB::table('orders')->max('id') ?? 0);

        $response = $this->submit($staff, $this->sid('single'), $this->payload());

        $response->assertRedirect(route('admin.home'));
        $response->assertSessionHasNoErrors();

        $orders = $this->ordersSince($mark);

        $this->assertCount(1, $orders, 'the counter order did not reach the database exactly once');
        $this->assertSame('paid', $orders[0]->payment_status);
        $this->assertSame('pending', $orders[0]->status);
        $this->assertSame((int) $staff->id, (int) $orders[0]->processed_by);
        $this->assertSame(
            1,
            DB::table('order_items')->where('order_id', $orders[0]->id)->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 2. THE REAL RACE: A SUBMISSION ARRIVING WHILE ANOTHER IS IN FLIGHT
    |--------------------------------------------------------------------------
    */

    /**
     * The deterministic contention test, with the full duplicate-order
     * invariants asserted at the database level rather than off the response.
     *
     * "Request 1 is mid-flight" is produced directly, by acquiring the SAME key
     * storeManualOrder() acquires for this till before the real POST is made.
     * That the lock was genuinely taken is asserted as part of the setup, so
     * this test cannot quietly decay into "two requests ran one after the
     * other", which would pass with no mutex at all.
     */
    public function test_a_submission_arriving_while_another_is_in_flight_creates_no_order(): void
    {
        $staff   = $this->staff();
        $session = $this->sid('inflight');
        $voucher = $this->voucher();

        $mark         = (int) (DB::table('orders')->max('id') ?? 0);
        $itemMark     = (int) (DB::table('order_items')->max('id') ?? 0);
        $movementMark = (int) (DB::table('stock_movements')->max('id') ?? 0);

        $stockBefore = Inventory::whereIn('id', $this->ingredientIds())
            ->pluck('quantity', 'id')
            ->map(fn ($q) => (float) $q)
            ->all();

        $inFlight = Cache::lock($this->lockKey($staff, $session), 20);
        $this->assertTrue($inFlight->get(), 'setup: could not simulate an in-flight submission');

        $response = $this->submit($staff, $session, $this->payload([
            'voucher_code' => $voucher->code,
        ]));

        // ---- the order itself -------------------------------------------
        $this->assertCount(
            0,
            $this->ordersSince($mark),
            'a submission arriving mid-flight created an order anyway'
        );

        // ---- and every downstream side effect an order would have --------
        $newItemIds = DB::table('order_items')->where('id', '>', $itemMark)->pluck('id');

        $this->assertCount(0, $newItemIds, 'a blocked submission still wrote order items');

        $this->assertSame(
            0,
            DB::table('order_item_options')->whereIn('order_item_id', $newItemIds)->count(),
            'a blocked submission still wrote order item options'
        );

        $this->assertSame(
            0,
            DB::table('stock_movements')->where('id', '>', $movementMark)->count(),
            'a blocked submission still moved stock'
        );

        $this->assertEquals(
            $stockBefore,
            Inventory::whereIn('id', $this->ingredientIds())
                ->pluck('quantity', 'id')
                ->map(fn ($q) => (float) $q)
                ->all(),
            'a blocked submission changed inventory'
        );

        $this->assertSame(
            0,
            (int) $voucher->fresh()->used_count,
            "a blocked submission spent the customer's voucher"
        );

        $response->assertSessionHasErrors('error');

        // ---- CONTROL -----------------------------------------------------
        // Without this, the refusal above could equally well mean the counter
        // is simply broken for everyone.
        $inFlight->release();

        $control = $this->submit($staff, $session, $this->payload());

        $control->assertSessionHasNoErrors();
        $this->assertCount(
            1,
            $this->ordersSince($mark),
            'CONTROL FAILED: the same till could not order once the lock cleared'
        );
    }

    /**
     * The same race from the till's point of view: an immediate retry while the
     * first submission is still processing must degrade the way every other
     * refusal in storeManualOrder() does — a readable sentence plus the
     * keyed-in order flashed back, which is what makes
     * reopenRejectedManualOrder() put the modal on screen again with the cart
     * intact — and never a raw error, a 500, or a second order.
     */
    public function test_an_immediate_retry_while_the_first_is_processing_is_refused_readably(): void
    {
        $staff   = $this->staff();
        $session = $this->sid('retryinflight');
        $mark    = (int) (DB::table('orders')->max('id') ?? 0);

        $inFlight = Cache::lock($this->lockKey($staff, $session), 20);
        $this->assertTrue($inFlight->get(), 'setup: could not simulate an in-flight submission');

        $first  = $this->submit($staff, $session, $this->payload());
        $second = $this->submit($staff, $session, $this->payload());

        foreach ([$first, $second] as $i => $response) {
            $response->assertRedirect('/admin/home');
            $response->assertSessionHasErrors('error');
        }

        $this->assertStringContainsString(
            'already being submitted',
            (string) session('errors')->first('error'),
            'the retry did not explain itself to staff'
        );

        // withInput(), so the modal reopens with the keyed-in cart rather than
        // silently losing it: old('amount_paid') is the marker the dashboard
        // uses for "this page load is a bounced manual order".
        $second->assertSessionHasInput('amount_paid');
        $second->assertSessionHasInput('items');

        $this->assertCount(
            0,
            $this->ordersSince($mark),
            'a retry while the first submission was in flight created an order'
        );

        $inFlight->release();
    }

    /*
    |--------------------------------------------------------------------------
    | 3. THE MUTEX MUST NOT OUTLIVE THE REQUEST THAT TOOK IT
    |--------------------------------------------------------------------------
    */

    /**
     * The finally block, proved directly: after a successful order the key is
     * free again, so the next customer at the same till is never blocked by a
     * lock left over from the last one.
     */
    public function test_the_lock_is_released_after_a_successful_order(): void
    {
        $staff   = $this->staff();
        $session = $this->sid('released');
        $mark    = (int) (DB::table('orders')->max('id') ?? 0);

        $this->submit($staff, $session, $this->payload())->assertSessionHasNoErrors();
        $this->assertCount(1, $this->ordersSince($mark), 'CONTROL: the first order should exist');

        $free = Cache::lock($this->lockKey($staff, $session), 20);

        $this->assertTrue(
            $free->get(),
            'the duplicate-submit lock was still held after the request that took it finished'
        );

        $free->release();
    }

    /**
     * A refusal must not hold the lock either. Staff who are told the amount is
     * short, fix it and submit again immediately must not then be told the
     * order is already being submitted.
     */
    public function test_a_refused_submission_does_not_hold_the_lock(): void
    {
        $staff   = $this->staff();
        $session = $this->sid('refused');

        // Refused by the amount-paid gate, which sits well past the lock.
        $this->submit($staff, $session, $this->payload(['amount_paid' => '0']))
            ->assertSessionHasErrors('amount_paid');

        $mark = (int) (DB::table('orders')->max('id') ?? 0);

        $corrected = $this->submit($staff, $session, $this->payload());

        $corrected->assertSessionHasNoErrors();
        $this->assertCount(
            1,
            $this->ordersSince($mark),
            'a refused submission held the lock and blocked the corrected resubmission'
        );
    }

    /**
     * THE LIMIT, PINNED ON PURPOSE.
     *
     * A Cache::lock mutex protects submissions that OVERLAP. It gives no
     * exactly-once guarantee after the first request has committed and
     * released: at that point a resubmission of the identical payload is, as
     * far as anything the request carries can tell, staff ringing up the next
     * customer — which is also the legitimate case the counter must keep
     * serving. This asserts the second order IS created, so the limitation is
     * recorded in the suite rather than assumed away.
     *
     * Closing it would need a per-submission token persisted server-side (a
     * schema change), which is deliberately outside F8.
     */
    public function test_a_retry_after_the_first_order_committed_still_creates_a_second_order(): void
    {
        $staff   = $this->staff();
        $session = $this->sid('postsuccess');
        $mark    = (int) (DB::table('orders')->max('id') ?? 0);

        $payload = $this->payload();

        $this->submit($staff, $session, $payload)->assertSessionHasNoErrors();
        $this->submit($staff, $session, $payload)->assertSessionHasNoErrors();

        $this->assertCount(
            2,
            $this->ordersSince($mark),
            'this test records that post-success retries are NOT idempotent today; if it '
            . 'now sees one order, an idempotency mechanism has been added and this test '
            . 'should be rewritten to assert it rather than deleted'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 4. THE KEY'S SCOPE: WHO MUST *NOT* BLOCK WHOM
    |--------------------------------------------------------------------------
    */

    /**
     * Two tills, one shared counter account — the ordinary café arrangement.
     * The session half of the key is what keeps them independent; keying on the
     * staff account alone would bounce till B's perfectly good order because
     * till A happened to be mid-request.
     */
    public function test_a_second_till_on_the_same_staff_account_is_not_blocked(): void
    {
        $staff = $this->staff();
        $tillA = $this->sid('tillAAAA');
        $tillB = $this->sid('tillBBBB');

        $this->assertNotSame($tillA, $tillB, 'setup: the two tills must have distinct sessions');

        $busy = Cache::lock($this->lockKey($staff, $tillA), 20);
        $this->assertTrue($busy->get(), 'setup: could not make till A busy');

        $mark = (int) (DB::table('orders')->max('id') ?? 0);

        $response = $this->submit($staff, $tillB, $this->payload());

        $response->assertSessionHasNoErrors();
        $this->assertCount(
            1,
            $this->ordersSince($mark),
            "a second till's order was blocked by the first till's in-flight lock"
        );

        $busy->release();
    }

    /**
     * And the case the audit named outright: two DIFFERENT staff at two
     * different terminals submitting at the same moment must not block each
     * other.
     */
    public function test_a_different_staff_members_in_flight_lock_does_not_block_this_one(): void
    {
        $staff = $this->staff();
        $other = $this->admin();

        $this->assertNotSame((int) $staff->id, (int) $other->id, 'setup: needs two distinct accounts');

        $busy = Cache::lock($this->lockKey($other, $this->sid('othertill')), 20);
        $this->assertTrue($busy->get(), 'setup: could not make the other terminal busy');

        $mark = (int) (DB::table('orders')->max('id') ?? 0);

        $response = $this->submit($staff, $this->sid('mytill'), $this->payload());

        $response->assertSessionHasNoErrors();
        $this->assertCount(
            1,
            $this->ordersSince($mark),
            "another staff member's in-flight lock blocked an unrelated order"
        );

        $busy->release();
    }

    /*
    |--------------------------------------------------------------------------
    | 5. THE STOCK GATE IS UNCHANGED BY THE NEW MUTEX
    |--------------------------------------------------------------------------
    */

    /**
     * The oversell guard is the other half of this endpoint and must be exactly
     * as it was: a shortfall is still refused, still writes nothing, and — the
     * part a badly placed mutex could have broken — still releases the lock on
     * the way out, so the till is usable the moment the shelf is restocked.
     */
    public function test_insufficient_stock_is_still_refused_and_frees_the_till(): void
    {
        $staff   = $this->staff();
        $session = $this->sid('nostock');
        $ids     = $this->ingredientIds();

        $this->assertNotEmpty($ids, 'setup: the picked item has no recipe ingredients to deplete');

        $restore = Inventory::whereIn('id', $ids)->pluck('quantity', 'id');

        Inventory::whereIn('id', $ids)->update(['quantity' => 0]);

        $mark = (int) (DB::table('orders')->max('id') ?? 0);

        $refused = $this->submit($staff, $session, $this->payload());

        $refused->assertSessionHasErrors('items');
        $this->assertCount(
            0,
            $this->ordersSince($mark),
            'an order was created despite the stock refusal'
        );

        // Restocked — and the same till orders immediately, which it could not
        // do if the refusal had left the mutex held.
        foreach ($restore as $id => $quantity) {
            Inventory::where('id', $id)->update(['quantity' => $quantity]);
        }

        $again = $this->submit($staff, $session, $this->payload());

        $again->assertSessionHasNoErrors();
        $this->assertCount(
            1,
            $this->ordersSince($mark),
            'the stock refusal held the lock and blocked the retry after restocking'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 6. INPUT VALIDATION HARDENED WHILE IN THIS PATH
    |--------------------------------------------------------------------------
    */

    /**
     * amount_paid was `required|numeric|min:0` with no ceiling, while the
     * column it lands in is decimal(10,2). A figure past that was caught only
     * by MySQL strict mode at INSERT time, which reached staff as the generic
     * "could not be saved" catch and reached the log as a QueryException — a
     * fat-fingered keypad entry reported as an application fault. It is now a
     * plain field error on the field that caused it, before any work is done.
     */
    public function test_an_out_of_range_amount_paid_is_refused_as_a_field_error(): void
    {
        $staff = $this->staff();
        $mark  = (int) (DB::table('orders')->max('id') ?? 0);

        $response = $this->submit(
            $staff,
            $this->sid('bigamount'),
            $this->payload(['amount_paid' => '99999999999999'])
        );

        $response->assertSessionHasErrors('amount_paid');
        $this->assertCount(
            0,
            $this->ordersSince($mark),
            'an out-of-range amount_paid still created an order'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 7. THE CLIENT-SIDE HALF
    |--------------------------------------------------------------------------
    */

    /**
     * The browser guard, mirroring confirmOrderNow() on the customer cart: the
     * button is read and written synchronously inside the submit handler, so a
     * second submission from the same rendered page never leaves it.
     *
     * Asserted on the markup because there is no JS engine here — the same way
     * ManualOrderCounterTest pins that the discount controls ship disabled.
     */
    public function test_the_dashboard_disables_the_manual_submit_button_on_submit(): void
    {
        $html = $this->actingAs($this->staff(), 'admin')->get('/admin/home')->content();

        $this->assertStringContainsString('id="manualSubmitButton"', $html);

        // The guard itself, and the assignment that closes the window.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*submitButton\s*&&\s*submitButton\.disabled\s*\)/',
            $html,
            'the manual-order submit handler no longer checks the button synchronously'
        );
        $this->assertStringContainsString('submitButton.disabled = true;', $html);

        // And the back/forward-cache restore, without which a till that
        // navigates away and returns comes back to a dead button.
        $this->assertStringContainsString("addEventListener('pageshow'", $html);
    }
}

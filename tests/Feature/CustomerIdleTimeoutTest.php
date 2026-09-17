<?php

namespace Tests\Feature;

use App\Models\TableSession;
use App\Models\User;
use App\Services\TableEntry;
use App\Services\TableOccupancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The server-side half of the "are you still there?" idle prompt:
 * POST /customer/idle-logout, called once a client-side warning has gone
 * unanswered.
 *
 * This is deliberately ONE endpoint for every session shape — logged-in
 * Pickup, logged-in Dine-In, and a guest holding only a table_session_token —
 * because "end the session for real" is the same server action regardless of
 * which one is live: log the customer guard out if signed in, then destroy
 * the whole session (session()->invalidate(), not a handful of forget()
 * calls) so nothing the client is still holding can be replayed.
 *
 * NOT covered here (and deliberately untouched by this feature): the
 * ninety-minute sweepIdle() table release, and the guest's own structural
 * fifteen-minute TableOccupancy::GUEST_IDLE_MINUTES clock — see
 * DineInGuestInactivityTest. This suite only proves the new endpoint.
 */
class CustomerIdleTimeoutTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('qr-scan:127.0.0.1');
        RateLimiter::clear('table-code:127.0.0.1');
    }

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    /** Sit a guest at a table the way a phone's camera opening the QR does. */
    private function seatGuest(string $table, int $branchId = 1): TableSession
    {
        $code = TableEntry::findOrRegister($branchId, $table)->code;

        $this->get("/customer/menu?branch_id={$branchId}&table={$table}&k={$code}")->assertOk();

        $session = TableOccupancy::activeFor($branchId, $table);
        $this->assertNotNull($session, "seating at table {$table} did not open an occupancy");

        return $session;
    }

    // ══════════ logged-in customer (Pickup or Dine-In account) ══════════

    public function test_idle_logout_logs_out_a_signed_in_customer(): void
    {
        $customer = $this->customer();

        $response = $this->actingAs($customer, 'customer')
            ->withSession(['order_type' => 'pick_up', 'branch_id' => 1])
            ->postJson('/customer/idle-logout');

        $response->assertOk()->assertJson(['redirect' => route('home')]);

        $this->assertFalse(
            Auth::guard('customer')->check(),
            'the customer guard must be logged out, not merely redirected client-side'
        );
    }

    public function test_idle_logout_invalidates_the_session_not_just_the_guard(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->withSession(['order_type' => 'pick_up', 'branch_id' => 1, 'cart' => ['1' => ['quantity' => 2]]])
            ->postJson('/customer/idle-logout')
            ->assertOk();

        // A real session()->invalidate() rotates the session id. A client-side
        // -only "redirect and hope" would leave the old session (and its
        // cart) exactly as it was.
        $this->assertNull(session('cart'), 'stale session data survived the idle logout');
    }

    // ══════════ guest Dine-In (no account) ══════════

    public function test_idle_logout_ends_a_guest_dine_in_session_without_an_account(): void
    {
        $this->seatGuest('IDLE1');

        $this->assertNotNull(session('table_session_token'), 'the guest never actually held a table session');

        $response = $this->postJson('/customer/idle-logout');

        $response->assertOk()->assertJson(['redirect' => route('home')]);
        $this->assertFalse(Auth::guard('customer')->check(), 'a guest has no account to begin with');
        $this->assertNull(session('table_session_token'), 'the guest table token must not survive an idle-logout');
    }

    public function test_idle_logout_is_harmless_with_no_active_session_at_all(): void
    {
        // A pick-up visitor who never logged in, or a tab that outlived its
        // session — either way this must degrade to "nothing to end", not an
        // error, exactly like tableActivity()'s own "recorded: false" case.
        $response = $this->postJson('/customer/idle-logout');

        $response->assertOk()->assertJson(['redirect' => route('home')]);
    }
}

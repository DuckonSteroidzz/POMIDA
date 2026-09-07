<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Support\GuestOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A customer must always be able to open the receipt for an order they really
 * placed — and never for one they did not.
 *
 * Reported: staff completed an order, the customer tapped "View Receipt", and
 * /customer/receipt/1985 returned the branded 404.
 *
 * showReceipt() resolves through CustomerOrderAccess::resolveOwnedOrder(),
 * which returned null in two situations that are both the customer's own
 * order:
 *
 *   a) The order was placed as a GUEST and the visitor has since signed in.
 *      The logged-in branch matched only on user_id, and a guest order has
 *      user_id NULL, so signing in revoked the claim to your own order.
 *
 *   b) A NEW dine-in visit had started in the same browser. Continuing as a
 *      guest from the QR calls GuestOrders::forget(), which cleared the whole
 *      claim set — correctly, so the next party at the table inherits nothing,
 *      but it also took the previous party's receipts with it.
 *
 * Both are fixed narrowly: (a) falls through to the unchanged guest rules, and
 * (b) keeps a read-only archive that ONLY the receipt endpoint consults. The
 * IDOR protection is asserted below in both directions, because widening
 * ownership is exactly how that protection gets lost by accident.
 */
class ReceiptAccessTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): User
    {
        return User::where('role', 'customer')->orderBy('id')->first();
    }

    private function cart(MenuItem $item): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => $item->price,
            'quantity'     => 1,
            'image'        => $item->image,
            'options'      => [],
        ]];
    }

    /** Place a real dine-in order as a guest and return it, plus the session claim. */
    private function placeGuestDineInOrder(string $table = '7'): Order
    {
        $item = MenuItem::where('is_available', true)->orderBy('id')->first();

        $this->withSession([
            'cart'         => $this->cart($item),
            'branch_id'    => 1,
            'order_type'   => 'dine_in',
            'table_number' => $table,
        ])->post('/customer/place-order', [
            'order_type'     => 'dine_in',
            'table_number'   => $table,
            'payment_method' => 'cash',
            'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        return Order::orderByDesc('id')->first();
    }

    private function placeMemberOrder(User $customer): Order
    {
        $item = MenuItem::where('is_available', true)->orderBy('id')->first();

        $this->actingAs($customer, 'customer')->withSession([
            'cart'       => $this->cart($item),
            'branch_id'  => 1,
            'order_type' => 'pick_up',
        ])->post('/customer/place-order', [
            'order_type'     => 'pick_up',
            'payment_method' => 'cash',
            'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        return Order::orderByDesc('id')->first();
    }

    private function complete(Order $order): Order
    {
        $order->status = 'completed';
        $order->completed_at = now();
        $order->save();

        return $order;
    }

    // ── the receipt a customer is entitled to ────────────────────────────────

    public function test_a_guest_can_open_their_receipt_immediately_after_completion(): void
    {
        $order = $this->placeGuestDineInOrder();
        $claims = session(GuestOrders::KEY);

        $this->complete($order);

        $this->withSession([GuestOrders::KEY => $claims])
            ->get('/customer/receipt/' . $order->id)
            ->assertOk();
    }

    public function test_a_logged_in_customer_can_open_their_receipt_immediately_after_completion(): void
    {
        $customer = $this->customer();
        $order = $this->complete($this->placeMemberOrder($customer));

        $this->assertSame($customer->id, $order->user_id);

        $this->actingAs($customer, 'customer')
            ->get('/customer/receipt/' . $order->id)
            ->assertOk();
    }

    /** (a) Ordered as a guest, then signed in — still your receipt. */
    public function test_signing_in_after_ordering_as_a_guest_does_not_revoke_the_receipt(): void
    {
        $order = $this->placeGuestDineInOrder('8');
        $claims = session(GuestOrders::KEY);

        $this->complete($order);
        $this->assertNull($order->user_id, 'this must be a genuine guest order');

        $this->actingAs($this->customer(), 'customer')
            ->withSession([GuestOrders::KEY => $claims])
            ->get('/customer/receipt/' . $order->id)
            ->assertOk();
    }

    /** (b) A new dine-in visit started — the old receipt is still readable. */
    public function test_a_new_dine_in_visit_does_not_revoke_the_previous_receipt(): void
    {
        $order = $this->complete($this->placeGuestDineInOrder('9'));
        $claims = session(GuestOrders::KEY);

        // What processQr() does on "Continue as Guest".
        $this->withSession([GuestOrders::KEY => $claims]);
        session()->put(GuestOrders::KEY, $claims);
        GuestOrders::forget();

        $this->assertFalse(GuestOrders::owns($order->id), 'the ACTIVE claim must be gone');
        $this->assertTrue(GuestOrders::ownedInPastVisit($order->id), 'the read-only archive must keep it');

        $this->withSession([GuestOrders::PAST_KEY => GuestOrders::pastIds()])
            ->get('/customer/receipt/' . $order->id)
            ->assertOk();
    }

    // ── the receipt a visitor is NOT entitled to (IDOR) ──────────────────────

    public function test_a_stranger_cannot_read_a_receipt_by_guessing_the_id(): void
    {
        $order = $this->complete($this->placeGuestDineInOrder('10'));

        // A visitor whose session placed nothing at all.
        $this->flushSession();

        $this->get('/customer/receipt/' . $order->id)->assertNotFound();
    }

    public function test_a_guest_cannot_read_a_registered_customers_receipt(): void
    {
        $memberOrder = $this->complete($this->placeMemberOrder($this->customer()));

        // Even with the id planted in this session's claim set, a guest order
        // is required — a member's order can never be claimed this way.
        // (actingAs sticks for the rest of the test, so sign out first or this
        // would be the owner reading their own receipt.)
        $this->app['auth']->guard('customer')->logout();
        $this->flushSession();

        $this->withSession([GuestOrders::KEY => [$memberOrder->id]])
            ->get('/customer/receipt/' . $memberOrder->id)
            ->assertNotFound();
    }

    public function test_a_customer_cannot_read_another_customers_receipt(): void
    {
        $owner = $this->customer();
        $other = User::where('role', 'customer')->where('id', '!=', $owner->id)->orderBy('id')->first();

        $this->assertNotNull($other, 'this test needs two customer accounts');

        $order = $this->complete($this->placeMemberOrder($owner));

        $this->flushSession();

        $this->actingAs($other, 'customer')
            ->get('/customer/receipt/' . $order->id)
            ->assertNotFound();
    }

    /**
     * The widened claim is receipt-only. An archived id must not let a visitor
     * act on the order — cancelling it, for instance.
     */
    public function test_the_past_visit_archive_grants_reading_a_receipt_and_nothing_else(): void
    {
        $order = $this->placeGuestDineInOrder('11');
        $claims = session(GuestOrders::KEY);

        session()->put(GuestOrders::KEY, $claims);
        GuestOrders::forget();
        $past = GuestOrders::pastIds();

        $this->assertContains($order->id, $past);

        // The visit has ended: only the read-only archive survives. flushSession()
        // first, because withSession() merges and would otherwise leave the
        // ACTIVE claim from placing the order in place.
        $this->flushSession();

        // Receipt: allowed.
        $this->withSession([GuestOrders::PAST_KEY => $past])
            ->get('/customer/receipt/' . $order->id)
            ->assertOk();

        // Acting on the order: still refused.
        $this->flushSession();

        $this->withSession([GuestOrders::PAST_KEY => $past])
            ->post('/customer/orders/' . $order->id . '/cancel')
            ->assertNotFound();

        $this->assertNotSame('cancelled', $order->fresh()->status);
    }

    /** A missing order is still a 404, not a 500. */
    public function test_an_unknown_order_id_is_a_clean_404(): void
    {
        $this->get('/customer/receipt/99999999')->assertNotFound();
    }

    /** The branded 404 offers a dine-in visitor a way back, not just home. */
    public function test_the_404_page_is_context_aware(): void
    {
        $branch = Branch::first();

        $this->withSession([
            'order_type'   => 'dine_in',
            'table_number' => '7',
            'branch_id'    => $branch->id,
        ]);

        $html = view('errors.404')->render();

        $this->assertStringContainsString('Table 7', $html);
        $this->assertStringContainsString('Back to home', $html);
    }
}

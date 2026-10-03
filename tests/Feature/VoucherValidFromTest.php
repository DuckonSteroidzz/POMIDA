<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\ForcesSpinOutcome;
use Tests\TestCase;

/**
 * The shared-voucher valid_from overwrite bug and its fix.
 *
 * Bug (reproduced live before this fix, with two real customer accounts and
 * the real /customer/add-points wheel-win code path): winning a wheel voucher
 * wrote "cannot use until tomorrow" onto the SHARED vouchers.valid_from
 * column. Since a voucher code can be won by many different customers over
 * time, the second customer to ever win a given code silently moved the
 * FIRST customer's redemption window too, with zero action from them.
 *
 * Fix: the per-customer window now lives on the customer's own
 * user_vouchers.valid_from row. Voucher::redemptionErrorFor() reads that row
 * for wheel-won vouchers (points_required > 0) and only falls back to the
 * shared vouchers.valid_from for public promo codes (points_required = 0),
 * which genuinely do have one shared launch date for everyone.
 */
class VoucherValidFromTest extends TestCase
{
    use DatabaseTransactions;
    use ForcesSpinOutcome;

    private function customer(int $skip = 0): User
    {
        return User::where('role', 'customer')->orderBy('id')->skip($skip)->first();
    }

    /** A real active order, required by the wheel-spin endpoint. */
    private function activeOrderFor(User $user, string $type = 'pick_up'): Order
    {
        return Order::create([
            'order_number' => 'VF' . strtoupper(substr(uniqid(), -8)),
            'user_id'      => $user->id,
            'branch_id'    => 1,
            'type'         => $type,
            'status'       => 'pending',
            'subtotal'     => 100,
            'total'        => 100,
        ]);
    }

    private function wheelVoucher(string $code, int $pointsRequired = 5): Voucher
    {
        return Voucher::create([
            'code'            => $code,
            'description'     => 'Test wheel voucher',
            'discount_type'   => 'fixed',
            'discount_value'  => 10,
            'max_uses'        => 0,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => $pointsRequired,
            'is_active'       => true,
            'valid_from'      => null,
        ]);
    }

    /**
     * Win a voucher via a real spin attributed to a real order, then close
     * that order out. Returns the (now cancelled) earning order so a caller
     * can assert it never carries the voucher it won.
     */
    private function winVoucher(User $user, string $orderType = 'pick_up'): Order
    {
        UserVoucher::where('user_id', $user->id)->delete();
        $user->points = 0;
        $user->save();
        $spinOrder = $this->activeOrderFor($user, $orderType);

        $this->forceSpinOutcome(5); // the server picks the prize since F3
        $this->actingAs($user, 'customer')
            ->postJson('/customer/add-points')
            ->assertOk();

        // The spin needed a live order to attach to, but placeOrder() refuses a
        // second order while the customer already has one pending/preparing/
        // serving (dine_in) or wants a clean slate for the assertions below.
        // Close it out so a real checkout can be attempted afterward —
        // winning a voucher must not itself block using it.
        $spinOrder->status = 'cancelled';
        $spinOrder->save();

        return $spinOrder;
    }

    // ══════════ The core bug: two customers, one voucher ══════════

    public function test_two_customers_winning_the_same_voucher_get_independent_windows(): void
    {
        $a = $this->customer(0);
        $b = $this->customer(1);
        $voucher = $this->wheelVoucher('VFSHARED1');

        $this->winVoucher($a);
        $claimA = UserVoucher::where('user_id', $a->id)->where('voucher_id', $voucher->id)->first();
        $windowA = $claimA->valid_from->toDateString();

        $this->travel(3)->days();
        $this->winVoucher($b);
        $this->travelBack();

        $claimB = UserVoucher::where('user_id', $b->id)->where('voucher_id', $voucher->id)->first();
        $windowB = $claimB->valid_from->toDateString();

        // The two windows are genuinely different (3 days apart) ...
        $this->assertNotSame($windowA, $windowB);

        // ... and A's own claim is completely unaffected by B winning later.
        $claimA->refresh();
        $this->assertSame($windowA, $claimA->valid_from->toDateString());
    }

    public function test_the_shared_voucher_row_is_never_touched_by_a_win(): void
    {
        $a = $this->customer(0);
        $b = $this->customer(1);
        $voucher = $this->wheelVoucher('VFSHARED2');

        $this->assertNull($voucher->valid_from);

        $this->winVoucher($a);
        $this->assertNull(Voucher::find($voucher->id)->valid_from, 'shared row must stay untouched after A wins');

        $this->winVoucher($b);
        $this->assertNull(Voucher::find($voucher->id)->valid_from, 'shared row must stay untouched after B wins too');
    }

    // ══════════ Redemption correctly gated per customer ══════════

    /**
     * REVERSED, 2026-10-03: this used to pin the next-day wait ("before its
     * window" meant "the same day it was won"). The owner's decision removed
     * that wait entirely — walk-in/dine-in and pick-up both now open the
     * moment the voucher is won — so the very same call must be redeemable
     * with zero elapsed time, not refused.
     */
    public function test_a_customer_can_redeem_their_own_voucher_immediately_after_winning(): void
    {
        $a = $this->customer(0);
        $voucher = $this->wheelVoucher('VFGATE1');

        $this->winVoucher($a);

        $error = $voucher->fresh()->redemptionErrorFor($a, 100);

        $this->assertNull($error, $error ?? '');
    }

    public function test_a_customer_can_redeem_their_own_voucher_once_its_window_opens(): void
    {
        $a = $this->customer(0);
        $voucher = $this->wheelVoucher('VFGATE2');

        $this->winVoucher($a);

        $this->travel(2)->days();
        $error = $voucher->fresh()->redemptionErrorFor($a, 100);
        $this->travelBack();

        $this->assertNull($error);
    }

    /** The heart of the fix: B being blocked/allowed must not depend on when A won. */
    public function test_one_customers_window_never_gates_another_customers_redemption(): void
    {
        $a = $this->customer(0);
        $b = $this->customer(1);
        $voucher = $this->wheelVoucher('VFGATE3');

        // A wins today (window opens immediately, the day A won).
        $this->winVoucher($a);

        // B wins 5 days later (B's own window opens that later day).
        $this->travel(5)->days();
        $this->winVoucher($b);
        $this->travelBack();

        // Jump to the day after A won — long before B's own window opens.
        $this->travel(1)->days();
        $errorForA = $voucher->fresh()->redemptionErrorFor($a, 100);
        $errorForB = $voucher->fresh()->redemptionErrorFor($b, 100);
        $this->travelBack();

        $this->assertNull($errorForA, "A should be able to redeem once A's own window opens");
        $this->assertNotNull($errorForB, "B's window has not opened yet and must not be affected by A's");
    }

    // ══════════ Full checkout redemption still works end to end ══════════

    public function test_full_checkout_redemption_of_a_wheel_voucher_still_works(): void
    {
        $customer = $this->customer(0);
        $voucher = $this->wheelVoucher('VFCHECKOUT1');

        $this->winVoucher($customer);
        $claim = UserVoucher::where('user_id', $customer->id)->where('voucher_id', $voucher->id)->first();

        // No time travel: this must already work the same day it is won.
        // Preview (cart page) must accept it.
        $previewError = $voucher->fresh()->redemptionErrorFor($customer, 100);
        $this->assertNull($previewError);

        // Real checkout must accept it and mark the SAME claim used.
        $menuItem = \App\Models\MenuItem::where('is_available', true)->first();
        $this->assertNotNull($menuItem, 'fixture sanity: need at least one menu item');

        session(['cart' => [
            $menuItem->id => [
                'menu_item_id' => $menuItem->id,
                'name'         => $menuItem->name,
                'price'        => (float) $menuItem->price,
                'quantity'     => 1,
                'image'        => $menuItem->image ?? '',
                'options'      => [],
            ],
        ]]);
        session(['branch_id' => 1, 'order_type' => 'pick_up']);

        $res = $this->actingAs($customer, 'customer')->post('/customer/place-order', [
            'order_type'             => 'pick_up',
            'items'                  => [['menu_item_id' => $menuItem->id, 'quantity' => 1]],
            'payment_method'         => 'cash',
            'voucher_code_confirmed' => $voucher->code,
        ]);

        $claim->refresh();
        $this->assertTrue($claim->is_used, 'checkout must mark the specific claim used');

        $order = Order::where('voucher_id', $voucher->id)->where('user_id', $customer->id)->latest('id')->first();
        $this->assertNotNull($order, 'order should have been placed with the voucher attached; response was: ' . $res->getStatusCode());
        $this->assertSame($voucher->id, $order->voucher_id);
    }

    /**
     * REVERSED, 2026-10-03: this used to prove the voucher was BLOCKED on a
     * same-day checkout (the old rule: next-order use required waiting until
     * the next calendar day). The owner's decision removed that wait, so the
     * identical checkout — same day, right after winning, no time travel —
     * must now succeed. Renamed to say what it now proves.
     */
    public function test_full_checkout_redemption_works_the_same_day_it_is_won(): void
    {
        $customer = $this->customer(0);
        $voucher = $this->wheelVoucher('VFCHECKOUT2');

        $this->winVoucher($customer);

        $menuItem = \App\Models\MenuItem::where('is_available', true)->first();

        session(['cart' => [
            $menuItem->id => [
                'menu_item_id' => $menuItem->id,
                'name'         => $menuItem->name,
                'price'        => (float) $menuItem->price,
                'quantity'     => 1,
                'image'        => $menuItem->image ?? '',
                'options'      => [],
            ],
        ]]);
        session(['branch_id' => 1, 'order_type' => 'pick_up']);

        $ordersBefore = Order::count();

        $this->actingAs($customer, 'customer')->post('/customer/place-order', [
            'order_type'             => 'pick_up',
            'items'                  => [['menu_item_id' => $menuItem->id, 'quantity' => 1]],
            'payment_method'         => 'cash',
            'voucher_code_confirmed' => $voucher->code,
        ])->assertSessionHasNoErrors();

        $this->assertSame($ordersBefore + 1, Order::count(), 'the order was not created');

        $claim = UserVoucher::where('user_id', $customer->id)->where('voucher_id', $voucher->id)->first();
        $this->assertTrue($claim->is_used, 'checkout should have spent the claim the same day it was won');
    }

    // ══════════ Public promo codes are unaffected ══════════

    public function test_public_promo_code_still_uses_the_shared_valid_from(): void
    {
        $voucher = Voucher::create([
            'code'            => 'VFPUBLIC1',
            'description'     => 'Public launch-date promo',
            'discount_type'   => 'fixed',
            'discount_value'  => 5,
            'max_uses'        => 0,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 0,
            'is_active'       => true,
            'valid_from'      => now()->addDays(3)->toDateString(),
        ]);

        // Not yet valid for anyone, logged in or not.
        $this->assertNotNull($voucher->redemptionErrorFor(null, 100));
        $this->assertNotNull($voucher->redemptionErrorFor($this->customer(0), 100));

        $this->travel(3)->days();
        $stillTooEarly = $voucher->fresh()->redemptionErrorFor(null, 100);
        $this->travelBack();

        $this->assertNull($stillTooEarly, 'a public code with no points_required should need no account and no user_vouchers row');
    }

    public function test_public_promo_code_redemption_end_to_end_still_works(): void
    {
        $voucher = Voucher::create([
            'code'            => 'VFPUBLIC2',
            'description'     => 'Public promo, no wheel involved',
            'discount_type'   => 'fixed',
            'discount_value'  => 5,
            'max_uses'        => 0,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 0,
            'is_active'       => true,
            'valid_from'      => null,
        ]);

        $res = $this->postJson('/customer/apply-voucher', [
            'code'     => $voucher->code,
            'subtotal' => 100,
        ]);

        $res->assertOk();
        $res->assertJson(['success' => true]);
    }

    // ══════════ Single-winner flow (the common case) is unaffected ══════════

    /**
     * REVERSED, 2026-10-03: used to prove the voucher was "too early" with no
     * travel and only valid after travel(1)->days(). The next-day wait is
     * gone, so the first check (zero elapsed time) must now also be null, and
     * it stays null afterwards — nothing re-closes the window later either.
     */
    public function test_a_lone_winner_with_no_second_claimant_redeems_normally(): void
    {
        $customer = $this->customer(0);
        $voucher = $this->wheelVoucher('VFLONE1');

        $this->winVoucher($customer);

        $immediately = $voucher->fresh()->redemptionErrorFor($customer, 100);
        $this->assertNull($immediately, $immediately ?? '');

        $this->travel(1)->days();
        $stillOk = $voucher->fresh()->redemptionErrorFor($customer, 100);
        $this->travelBack();

        $this->assertNull($stillOk);
    }

    /**
     * REVERSED, 2026-10-03: used to prove the preview endpoint rejected the
     * voucher before the window and accepted it after travel(2)->days(). It
     * must now accept it immediately, with no travel at all.
     */
    public function test_apply_voucher_preview_endpoint_reflects_the_per_customer_window(): void
    {
        $customer = $this->customer(0);
        $voucher = $this->wheelVoucher('VFPREVIEW1');

        $this->winVoucher($customer);

        $result = $this->actingAs($customer, 'customer')->postJson('/customer/apply-voucher', [
            'code'     => $voucher->code,
            'subtotal' => 100,
        ]);

        $result->assertOk();
        $result->assertJson(['success' => true]);
    }

    // ══════════ Walk-in/dine-in now matches pick-up (2026-10-03) ══════════

    /**
     * The owner's decision, stated directly: both order types open the
     * voucher's window the same way — immediately, never on the order that
     * earned it. Covers both explicitly rather than trusting that the mint
     * path (which never reads $order->type) stays that way.
     *
     * @dataProvider orderTypes
     */
    public function test_a_voucher_is_usable_the_same_day_but_never_on_the_order_that_earned_it(string $orderType): void
    {
        $customer = $this->customer(0);
        $voucher  = $this->wheelVoucher('VFIMM' . strtoupper($orderType));

        $earningOrder = $this->winVoucher($customer, $orderType);

        // Immediately usable — no time travel at all.
        $error = $voucher->fresh()->redemptionErrorFor($customer, 100);
        $this->assertNull($error, "a {$orderType} voucher should be usable the moment it is won");

        // The order that earned it can never carry it: a voucher is only
        // ever attached to an order at the moment that order is CREATED, and
        // the earning order already existed before the win.
        $this->assertNull(
            $earningOrder->fresh()->voucher_id,
            'the earning order must never carry the voucher it won'
        );

        // A genuinely new order, placed the same day, actually spends it.
        $menuItem = \App\Models\MenuItem::where('is_available', true)->first();

        session(['cart' => [
            $menuItem->id => [
                'menu_item_id' => $menuItem->id,
                'name'         => $menuItem->name,
                'price'        => (float) $menuItem->price,
                'quantity'     => 1,
                'image'        => $menuItem->image ?? '',
                'options'      => [],
            ],
        ]]);
        session(['branch_id' => 1, 'order_type' => $orderType]);

        $payload = [
            'order_type'             => $orderType,
            'items'                  => [['menu_item_id' => $menuItem->id, 'quantity' => 1]],
            'payment_method'         => 'cash',
            'voucher_code_confirmed' => $voucher->code,
        ];

        if ($orderType === 'dine_in') {
            session(['table_number' => '5']);
            $payload['table_number'] = '5';
        }

        $this->actingAs($customer, 'customer')
            ->post('/customer/place-order', $payload)
            ->assertSessionHasNoErrors();

        $newOrder = Order::where('voucher_id', $voucher->id)->where('user_id', $customer->id)->latest('id')->first();

        $this->assertNotNull($newOrder, 'the new order using the voucher was not created');
        $this->assertNotSame(
            $earningOrder->id,
            $newOrder->id,
            'the voucher was applied to the same order that earned it'
        );
    }

    /**
     * The win response itself must no longer claim a next-day wait, for
     * either order type.
     *
     * @dataProvider orderTypes
     */
    public function test_the_win_message_no_longer_claims_a_next_day_wait(string $orderType): void
    {
        $customer = $this->customer(0);
        $this->wheelVoucher('VFMSG' . strtoupper($orderType));

        UserVoucher::where('user_id', $customer->id)->delete();
        $customer->points = 0;
        $customer->save();
        $this->activeOrderFor($customer, $orderType);

        $this->forceSpinOutcome(5);
        $body = $this->actingAs($customer, 'customer')
            ->postJson('/customer/add-points')
            ->assertOk()
            ->json();

        $this->assertNotNull($body['voucher'] ?? null, "a {$orderType} win minted no voucher");
        $this->assertSame(today()->toDateString(), $body['voucher']['valid_from']);
        $this->assertStringNotContainsStringIgnoringCase('tomorrow', $body['voucher']['message'] ?? '');
    }

    public static function orderTypes(): array
    {
        return [
            'pick up'           => ['pick_up'],
            'walk-in (dine-in)' => ['dine_in'],
        ];
    }
}

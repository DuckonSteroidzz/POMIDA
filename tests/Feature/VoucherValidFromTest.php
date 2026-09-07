<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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

    private function customer(int $skip = 0): User
    {
        return User::where('role', 'customer')->orderBy('id')->skip($skip)->first();
    }

    /** A real active order, required by the wheel-spin endpoint. */
    private function activeOrderFor(User $user): Order
    {
        return Order::create([
            'order_number' => 'VF' . strtoupper(substr(uniqid(), -8)),
            'user_id'      => $user->id,
            'branch_id'    => 1,
            'type'         => 'pick_up',
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

    private function winVoucher(User $user): void
    {
        UserVoucher::where('user_id', $user->id)->delete();
        $user->points = 0;
        $user->save();
        $spinOrder = $this->activeOrderFor($user);

        $this->actingAs($user, 'customer')
            ->postJson('/customer/add-points', ['points' => 5])
            ->assertOk();

        // The spin needed a live order to attach to, but placeOrder() refuses a
        // second order while the customer already has one pending/preparing/
        // serving. Close it out so a real checkout can be attempted afterward
        // — winning a voucher must not itself block using it.
        $spinOrder->status = 'cancelled';
        $spinOrder->save();
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

    public function test_a_customer_cannot_redeem_their_own_voucher_before_its_window(): void
    {
        $a = $this->customer(0);
        $voucher = $this->wheelVoucher('VFGATE1');

        $this->winVoucher($a);

        $error = $voucher->fresh()->redemptionErrorFor($a, 100);

        $this->assertNotNull($error);
        $this->assertStringContainsString('not yet valid', $error);
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

        // A wins today (window opens in +1 day).
        $this->winVoucher($a);

        // B wins 5 days later (their window opens 5 days from now, i.e. +6 from today).
        $this->travel(5)->days();
        $this->winVoucher($b);
        $this->travelBack();

        // Jump to A's window opening (+1 day from today) -- long before B's.
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

        $this->travel(2)->days();

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

        $this->travelBack();

        $claim->refresh();
        $this->assertTrue($claim->is_used, 'checkout must mark the specific claim used');

        $order = Order::where('voucher_id', $voucher->id)->where('user_id', $customer->id)->latest('id')->first();
        $this->assertNotNull($order, 'order should have been placed with the voucher attached; response was: ' . $res->getStatusCode());
        $this->assertSame($voucher->id, $order->voucher_id);
    }

    public function test_full_checkout_redemption_is_blocked_before_the_window_opens(): void
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
        ])->assertSessionHasErrors('voucher_code_confirmed');

        $this->assertSame($ordersBefore, Order::count(), 'no order should have been created');

        $claim = UserVoucher::where('user_id', $customer->id)->where('voucher_id', $voucher->id)->first();
        $this->assertFalse($claim->is_used);
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

    public function test_a_lone_winner_with_no_second_claimant_redeems_normally(): void
    {
        $customer = $this->customer(0);
        $voucher = $this->wheelVoucher('VFLONE1');

        $this->winVoucher($customer);

        $tooEarly = $voucher->fresh()->redemptionErrorFor($customer, 100);
        $this->assertNotNull($tooEarly);

        $this->travel(1)->days();
        $ok = $voucher->fresh()->redemptionErrorFor($customer, 100);
        $this->travelBack();

        $this->assertNull($ok);
    }

    public function test_apply_voucher_preview_endpoint_reflects_the_per_customer_window(): void
    {
        $customer = $this->customer(0);
        $voucher = $this->wheelVoucher('VFPREVIEW1');

        $this->winVoucher($customer);

        // Before the window: rejected with the per-customer message.
        $before = $this->actingAs($customer, 'customer')->postJson('/customer/apply-voucher', [
            'code'     => $voucher->code,
            'subtotal' => 100,
        ]);
        $before->assertOk();
        $before->assertJson(['success' => false]);
        $this->assertStringContainsString('not yet valid', $before->json('message'));

        // After the window: accepted.
        $this->travel(2)->days();
        $after = $this->actingAs($customer, 'customer')->postJson('/customer/apply-voucher', [
            'code'     => $voucher->code,
            'subtotal' => 100,
        ]);
        $this->travelBack();

        $after->assertOk();
        $after->assertJson(['success' => true]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A voucher and a PWD/Senior discount never stack: both are priced and only
 * the LARGER is applied (2026-09-02).
 *
 * WHAT CHANGED
 * ------------
 * Sending both used to be refused outright ("You can use either a voucher OR
 * a PWD/Senior Citizen discount, not both."). The customer had to work out
 * which was worth more themselves — and on the cart page they could not even
 * try, because selecting PWD while a voucher was applied silently did
 * nothing (see the root cause in CartDiscountToggleTest).
 *
 * Now both are validated and priced against the same subtotal, and the bigger
 * one wins. The loser is left completely unspent — a voucher that loses keeps
 * its claim and its used_count for a future order, which is the whole point
 * of not just refusing the order.
 *
 * THE TIE RULE
 * ------------
 * Strictly greater-than, so the PWD/Senior card WINS A TIE. That is the
 * customer-favouring choice: on equal money today they keep the voucher for
 * another order, and the PWD/Senior entitlement is a legal right rather than
 * a promotion that can run out. Pinned by
 * test_a_tie_is_won_by_the_card_and_leaves_the_voucher_unspent().
 *
 * RATE
 * ----
 * PWD/Senior is 20% (Order::PWD_SENIOR_DISCOUNT_RATE), confirmed from the
 * constant rather than assumed, and asserted below so these worked examples
 * cannot silently drift.
 */
class BiggerDiscountWinsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // placeOrder() stores the uploaded PWD/Senior ID on the local disk.
        // Faked for the same reason CartTotalsMatchCheckoutTest fakes it:
        // otherwise every run leaves a real identity document behind, orphaned
        // the moment the surrounding transaction rolls the order back.
        Storage::fake('local');
        Storage::fake('public');
    }

    private function customer(): User
    {
        return User::where('role', 'customer')->orderBy('id')->firstOrFail();
    }

    private function item(): MenuItem
    {
        return MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->orderBy('id')
            ->firstOrFail();
    }

    /** A real PNG, so the `image` validation rule passes without GD. */
    private function idImage(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'idimg') . '.png';

        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC'
        ));

        return new UploadedFile($path, 'id.png', 'image/png', null, true);
    }

    /**
     * A cart of exactly $qty of the live item, so the subtotal is derived the
     * same way CartPricing derives it at checkout (never a cached price).
     */
    private function cart(MenuItem $item, int $qty): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => (float) $item->price,
            'base_price'   => (float) $item->price,
            'quantity'     => $qty,
            'image'        => $item->image,
            'options'      => [],
        ]];
    }

    private function pwdFields(): array
    {
        return [
            'discount_type'                   => 'pwd',
            'discount_beneficiary_name'       => 'Juan Dela Cruz',
            'discount_beneficiary_id'         => 'PWD-123',
            'discount_beneficiary_expiration' => now()->addYear()->toDateString(),
            'discount_beneficiary_image'      => $this->idImage(),
        ];
    }

    private function fixedVoucher(float $peso): Voucher
    {
        return Voucher::create([
            'code'            => 'BDW' . strtoupper(substr(uniqid(), -7)),
            'description'     => 'bigger-discount comparison test',
            'discount_type'   => 'fixed',
            'discount_value'  => $peso,
            'max_uses'        => 100,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 0,
            'is_active'       => true,
        ]);
    }

    private function placeOrder(array $cart, User $customer, array $extra = []): void
    {
        $line = array_values($cart)[0];

        $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $cart, 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', array_merge([
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $line['menu_item_id'], 'quantity' => $line['quantity']]],
            ], $extra));
    }

    private function newestOrderAbove(int $mark): Order
    {
        $order = Order::where('id', '>', $mark)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'no order was created — the request was refused');

        return $order;
    }

    // ══════════ the rate this all depends on ══════════

    public function test_the_pwd_senior_rate_is_twenty_percent(): void
    {
        // Confirmed from code, not assumed. Every peso figure below is built
        // on this, so it is pinned here rather than spread through the tests.
        $this->assertSame(0.20, Order::PWD_SENIOR_DISCOUNT_RATE);
        $this->assertSame(80.0, Order::pwdSeniorDiscountFor(400.00));
    }

    // ══════════ 1. voucher bigger ══════════

    public function test_a_voucher_bigger_than_the_card_is_applied_and_the_card_ignored(): void
    {
        $item = $this->item();
        $customer = $this->customer();

        $cart = $this->cart($item, 2);
        $subtotal = round((float) $item->price * 2, 2);
        $cardWorth = Order::pwdSeniorDiscountFor($subtotal);

        // Comfortably more than 20% of the subtotal.
        $voucher = $this->fixedVoucher(round($cardWorth + 50, 2));

        $before = (int) Order::max('id');

        $this->placeOrder($cart, $customer, array_merge($this->pwdFields(), [
            'voucher_code_confirmed' => $voucher->code,
        ]));

        $order = $this->newestOrderAbove($before);

        $this->assertSame('voucher', $order->discount_type, 'the bigger voucher should have won');
        $this->assertEqualsWithDelta($cardWorth + 50, (float) $order->discount_amount, 0.01);
        $this->assertSame($voucher->id, (int) $order->voucher_id);

        // The card must leave no trace on a voucher order.
        $this->assertNull($order->discount_card_id);
        $this->assertNull($order->discount_beneficiary_name);

        // And it must not be parked in the staff ID-verification queue for a
        // card that was never applied.
        $this->assertSame('approved', $order->discount_status);

        // The two discounts must not have been added together.
        $this->assertEqualsWithDelta(
            $subtotal - ($cardWorth + 50),
            (float) $order->total,
            0.01,
            'the total looks like both discounts were stacked'
        );
    }

    // ══════════ 2. card bigger ══════════

    public function test_a_card_bigger_than_the_voucher_is_applied_and_the_voucher_left_unspent(): void
    {
        $item = $this->item();
        $customer = $this->customer();

        $cart = $this->cart($item, 2);
        $subtotal = round((float) $item->price * 2, 2);
        $cardWorth = Order::pwdSeniorDiscountFor($subtotal);

        // Deliberately small, so 20% beats it.
        $voucher = $this->fixedVoucher(5.00);
        $this->assertGreaterThan(5.00, $cardWorth, 'this test needs the card to be worth more');

        $before = (int) Order::max('id');

        $this->placeOrder($cart, $customer, array_merge($this->pwdFields(), [
            'voucher_code_confirmed' => $voucher->code,
        ]));

        $order = $this->newestOrderAbove($before);

        $this->assertSame('pwd', $order->discount_type, 'the bigger PWD discount should have won');
        $this->assertEqualsWithDelta($cardWorth, (float) $order->discount_amount, 0.01);

        // The losing voucher must be completely unspent — this is the point of
        // resolving automatically rather than refusing the order.
        $this->assertNull($order->voucher_id);
        $this->assertSame(
            0,
            (int) $voucher->fresh()->used_count,
            'the losing voucher was still counted as used'
        );

        $this->assertEqualsWithDelta($subtotal - $cardWorth, (float) $order->total, 0.01);
    }

    /**
     * The losing voucher's CLAIM must survive too, not just its used_count.
     *
     * Added after sabotage testing: nulling $voucherId alone looked
     * sufficient, because used_count is gated on it — but the claim row is
     * consumed by a SEPARATE `if ($voucherClaim)` in placeOrder(), so
     * deleting the `$voucherClaim = null` line broke nothing that the first
     * draft of these tests could see. A public promo code has no claim row at
     * all, which is why it could not catch it. This uses a real claim code so
     * the second half of "left unspent" is actually pinned.
     */
    public function test_a_losing_voucher_claim_is_not_burned(): void
    {
        $item = $this->item();
        $customer = $this->customer();

        $cart = $this->cart($item, 2);
        $subtotal = round((float) $item->price * 2, 2);
        $cardWorth = Order::pwdSeniorDiscountFor($subtotal);

        // Small enough that the 20% card beats it.
        $voucher = $this->fixedVoucher(5.00);
        $this->assertGreaterThan(5.00, $cardWorth, 'this test needs the card to be worth more');

        $admin = User::where('role', 'admin')->firstOrFail();
        $claim = \App\Services\VoucherClaims::mintForCounter($voucher, (int) $admin->id);

        $before = (int) Order::max('id');

        $this->placeOrder($cart, $customer, array_merge($this->pwdFields(), [
            'voucher_code_confirmed' => \App\Services\VoucherClaims::display($claim->claim_code),
        ]));

        $order = $this->newestOrderAbove($before);

        $this->assertSame('pwd', $order->discount_type, 'the bigger card should have won');

        $this->assertFalse(
            (bool) $claim->fresh()->is_used,
            'the losing voucher claim was burned — the customer lost a voucher they never benefited from'
        );
        $this->assertNull($order->voucher_id);
        $this->assertSame(0, (int) $voucher->fresh()->used_count);
    }

    // ══════════ 3. the tie ══════════

    public function test_a_tie_is_won_by_the_card_and_leaves_the_voucher_unspent(): void
    {
        $item = $this->item();
        $customer = $this->customer();

        $cart = $this->cart($item, 2);
        $subtotal = round((float) $item->price * 2, 2);
        $cardWorth = Order::pwdSeniorDiscountFor($subtotal);

        // Exactly equal to the card.
        $voucher = $this->fixedVoucher($cardWorth);

        $before = (int) Order::max('id');

        $this->placeOrder($cart, $customer, array_merge($this->pwdFields(), [
            'voucher_code_confirmed' => $voucher->code,
        ]));

        $order = $this->newestOrderAbove($before);

        // DOCUMENTED TIE RULE: the card wins, because the customer then keeps
        // the voucher for a future order at no cost to them today.
        $this->assertSame('pwd', $order->discount_type, 'a tie should be won by the PWD/Senior card');
        $this->assertNull($order->voucher_id);
        $this->assertSame(0, (int) $voucher->fresh()->used_count);

        // Either way the money is the same — that is what makes it a tie.
        $this->assertEqualsWithDelta($cardWorth, (float) $order->discount_amount, 0.01);
    }

    // ══════════ 4 & 5. only one present — unchanged behaviour ══════════

    public function test_a_voucher_on_its_own_still_applies_exactly_as_before(): void
    {
        $item = $this->item();
        $customer = $this->customer();

        $cart = $this->cart($item, 2);
        $subtotal = round((float) $item->price * 2, 2);
        $voucher = $this->fixedVoucher(25.00);

        $before = (int) Order::max('id');

        $this->placeOrder($cart, $customer, ['voucher_code_confirmed' => $voucher->code]);

        $order = $this->newestOrderAbove($before);

        $this->assertSame('voucher', $order->discount_type);
        $this->assertEqualsWithDelta(25.00, (float) $order->discount_amount, 0.01);
        $this->assertSame($voucher->id, (int) $order->voucher_id);
        $this->assertEqualsWithDelta($subtotal - 25.00, (float) $order->total, 0.01);
    }

    public function test_a_card_on_its_own_still_applies_exactly_as_before(): void
    {
        $item = $this->item();
        $customer = $this->customer();

        $cart = $this->cart($item, 2);
        $subtotal = round((float) $item->price * 2, 2);
        $cardWorth = Order::pwdSeniorDiscountFor($subtotal);

        $before = (int) Order::max('id');

        $this->placeOrder($cart, $customer, $this->pwdFields());

        $order = $this->newestOrderAbove($before);

        $this->assertSame('pwd', $order->discount_type);
        $this->assertEqualsWithDelta($cardWorth, (float) $order->discount_amount, 0.01);
        $this->assertNull($order->voucher_id);

        // Still needs staff to verify the ID, as it always did.
        $this->assertSame('pending', $order->discount_status);
        $this->assertEqualsWithDelta($subtotal - $cardWorth, (float) $order->total, 0.01);
    }

    // ══════════ 6. the zero floor ══════════

    public function test_a_voucher_larger_than_the_subtotal_cannot_push_the_total_below_zero(): void
    {
        $item = $this->item();
        $customer = $this->customer();

        $cart = $this->cart($item, 1);
        $subtotal = round((float) $item->price, 2);

        // Worth far more than the whole order, and also more than the card,
        // so it wins the comparison and then has to be floored.
        $voucher = $this->fixedVoucher(round($subtotal + 5000, 2));

        $before = (int) Order::max('id');

        $this->placeOrder($cart, $customer, array_merge($this->pwdFields(), [
            'voucher_code_confirmed' => $voucher->code,
        ]));

        $order = $this->newestOrderAbove($before);

        $this->assertSame('voucher', $order->discount_type);

        // Capped at the subtotal, so the order is free but never negative.
        $this->assertEqualsWithDelta($subtotal, (float) $order->discount_amount, 0.01);
        $this->assertEqualsWithDelta(0.00, (float) $order->total, 0.01);
        $this->assertGreaterThanOrEqual(0, (float) $order->total, 'the total went negative');
    }

    public function test_the_card_alone_can_never_push_the_total_below_zero_either(): void
    {
        // 20% of any subtotal cannot exceed it, but the floor is asserted
        // directly so the guarantee does not rest on the rate staying < 100%.
        $this->assertSame(0.0, Order::pwdSeniorDiscountFor(0.0));
        $this->assertLessThanOrEqual(100.0, Order::pwdSeniorDiscountFor(100.0));
    }

    // ══════════ the cart page must decide it the same way ══════════

    /**
     * The cart preview and the server must agree on the tie-break, or the
     * customer is quoted one figure and charged another — the exact class of
     * bug CartTotalsMatchCheckoutTest exists for.
     *
     * Asserted on the shipped source rather than by driving a browser: the
     * comparison is one line of JS, and what matters is that it is written
     * strictly greater-than (voucher must BEAT the card, not merely equal it)
     * in both places.
     */
    public function test_the_cart_preview_uses_the_same_tie_break_as_the_server(): void
    {
        $cart = file_get_contents(resource_path('views/customer/cart.blade.php'));

        $this->assertStringContainsString(
            'voucherDiscount > cardDiscount',
            $cart,
            'the cart preview must use strictly greater-than, so a tie goes to the card exactly as the server decides it'
        );

        /*
         * The comparison moved out of OrderController and onto the Order model
         * as voucherBeatsCard() (2026-09-03), so the admin Manual Order flow
         * could use the SAME rule instead of copying it. This assertion follows
         * it to its new home rather than being dropped: what it pins is
         * unchanged — the server's tie-break is strictly greater-than — and it
         * also checks that checkout still routes through that one definition
         * rather than growing its own second copy.
         */
        $model = file_get_contents(app_path('Models/Order.php'));

        $this->assertStringContainsString(
            '$voucherDiscount > $cardDiscount',
            $model,
            'the server tie-break must stay strictly greater-than, so a tie goes to the card'
        );

        $controller = file_get_contents(app_path('Http/Controllers/Customer/OrderController.php'));

        $this->assertStringContainsString(
            'Order::voucherBeatsCard(',
            $controller,
            'checkout must decide this through the shared rule, not a second copy of it'
        );

        // And the dead mutual-exclusion guards must stay gone, or the PWD
        // buttons go silently dead again the moment a voucher is applied.
        $this->assertStringNotContainsString(
            'You can only use either a voucher OR a discount card.',
            $cart,
            'the guard that made the PWD/Senior buttons do nothing is back'
        );
        $this->assertStringNotContainsString(
            'You can use either a voucher OR a PWD/Senior Citizen discount, not both.',
            $controller,
            'the server is refusing both-present orders again instead of resolving them'
        );
    }
}

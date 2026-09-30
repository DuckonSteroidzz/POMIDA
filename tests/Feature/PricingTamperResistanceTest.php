<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\MenuItemSizeOrderFixtures;
use Tests\TestCase;

/**
 * Money is decided by the server, from the database, every time.
 *
 * WHAT THIS IS FOR
 * ----------------
 * This is a POS, so every figure the browser sends about money is an attacker's
 * input: the cart lives in the session and carries a cached `price` per line,
 * and the checkout POST carries item rows, a voucher code and (historically) a
 * discount amount. None of it may be authoritative.
 *
 * The protection already existed and is documented at length in
 * App\Support\CartPricing ("the live price wins — the session is
 * customer-controlled") and in OrderController::placeOrder ("it posts a
 * discount_amount it never gets to decide"). This file is the standing proof of
 * it, added during the 2026-09-28 security audit: no defect was found here, and
 * these tests exist so that a future change to the pricing path cannot quietly
 * start trusting the client again.
 *
 * THE DIRECTION THAT MATTERS
 * --------------------------
 * The interesting attack is DEFLATION — paying less than the menu price — not
 * inflation. Several tests below therefore lower the cached price and assert the
 * order was still saved at the real one. (CartTotalsMatchCheckoutTest already
 * covers the inflation direction, where a price rose while the cart sat open;
 * that is a mis-charge bug rather than an attack, and is not repeated here.)
 *
 * NOTHING ABOUT PRICING, SIZES OR THE PWD/SENIOR RULE IS CHANGED BY THIS FILE.
 * It only asserts the behaviour that is already in place.
 */
class PricingTamperResistanceTest extends TestCase
{
    use DatabaseTransactions;
    use MenuItemSizeOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOnTestingDatabase();
    }

    // ══════════ A tampered cached price ══════════

    /**
     * The headline case: the customer rewrites the cart's cached price to ₱1 and
     * checks out. The order must be saved at the menu price.
     */
    public function test_a_deflated_cart_price_does_not_lower_what_is_charged(): void
    {
        $branch = $this->sizeBranch('A');
        $inv = $this->sizeInventory($branch->id, 50);
        $item = $this->unsizedItem($branch, $inv, 60);

        $cart = $this->cartLine($item, null, 2);
        $key = array_key_first($cart);

        // The tamper: ₱60 each becomes ₱1 each, in both fields the cart carries.
        $cart[$key]['price'] = 1.0;
        $cart[$key]['base_price'] = 1.0;

        $this->placePickUp($branch, $cart)->assertRedirect();

        $order = $this->latestOrderAt($branch);

        $this->assertNotNull($order, 'the order was refused, so this proves nothing about pricing');
        $this->assertSame('120.00', number_format((float) $order->subtotal, 2, '.', ''), 'the tampered price was honoured');
        $this->assertSame('120.00', number_format((float) $order->total, 2, '.', ''));
    }

    /** The same for a SIZED line: the size's own price is authoritative. */
    public function test_a_deflated_size_price_does_not_lower_what_is_charged(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);

        // Large is ₱150 in the fixture; the item's own price is ₱90 ("starting from").
        $cart = $this->cartLine($f['item'], $f['large'], 1);
        $key = array_key_first($cart);

        $cart[$key]['price'] = 5.0;
        $cart[$key]['base_price'] = 5.0;

        $this->placePickUp($branch, $cart)->assertRedirect();

        $order = $this->latestOrderAt($branch);

        $this->assertNotNull($order, 'the order was refused, so this proves nothing');
        $this->assertSame(
            '150.00',
            number_format((float) $order->subtotal, 2, '.', ''),
            'the line was not priced from the Menu Item Size record'
        );
    }

    /**
     * A sized line must not fall back to menu_items.price.
     *
     * For a sized item that column is only a "starting from" figure, so pricing
     * a Large at ₱90 instead of ₱150 would be a real underpayment. Asserted
     * explicitly because it is the plausible failure mode of any refactor here.
     */
    public function test_a_sized_line_is_never_priced_at_the_items_starting_from_figure(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);

        $this->placePickUp($branch, $this->cartLine($f['item'], $f['large'], 1))->assertRedirect();

        $order = $this->latestOrderAt($branch);
        $this->assertNotNull($order);

        $subtotal = number_format((float) $order->subtotal, 2, '.', '');

        $this->assertNotSame('90.00', $subtotal, 'a Large was charged at the item\'s starting-from price');
        $this->assertSame('150.00', $subtotal);
    }

    // ══════════ Tampered add-on prices ══════════

    /** An add-on's price comes from its own row, not from the cart's copy of it. */
    public function test_a_deflated_add_on_price_does_not_lower_what_is_charged(): void
    {
        $branch = $this->sizeBranch('A');
        $f = $this->sizedCoffee($branch);
        $addOn = $this->addOn($f['item'], $branch, 20);

        $cart = $this->cartLine($f['item'], $f['regular'], 1, [$addOn]);
        $key = array_key_first($cart);

        // Regular ₱100 + add-on ₱20 = ₱120. Claim the add-on is free.
        $cart[$key]['options'][0]['price'] = 0.0;
        $cart[$key]['price'] = 100.0;

        $this->placePickUp($branch, $cart)->assertRedirect();

        $order = $this->latestOrderAt($branch);

        $this->assertNotNull($order, 'the order was refused, so this proves nothing');
        $this->assertSame(
            '120.00',
            number_format((float) $order->subtotal, 2, '.', ''),
            'the add-on was billed at the price the browser claimed'
        );
    }

    // ══════════ Tampered totals and discounts ══════════

    /**
     * Posting `total`, `subtotal` and `discount_amount` directly must change
     * nothing.
     *
     * MassAssignmentEscalationTest covers this from the mass-assignment angle;
     * this asserts the arithmetic outcome, which is the part a reader of the
     * security report cares about.
     */
    public function test_posted_totals_and_discount_amounts_are_ignored(): void
    {
        $branch = $this->sizeBranch('A');
        $inv = $this->sizeInventory($branch->id, 50);
        $item = $this->unsizedItem($branch, $inv, 60);

        $cart = $this->cartLine($item, null, 1);

        $this->withSession([
                'cart'       => $cart,
                'branch_id'  => $branch->id,
                'order_type' => 'pick_up',
            ])
            ->from(route('customer.cart'))
            ->post(route('customer.place-order'), [
                'order_type'      => 'pick_up',
                'payment_method'  => 'cash',
                'items'           => [['menu_item_id' => $item->id, 'quantity' => 1]],
                // All attacker-supplied. None of these may be believed.
                // No discount_type is sent: the point here is that a bare
                // posted discount_amount cannot create a discount at all.
                'subtotal'        => 1,
                'total'           => 1,
                'discount_amount' => 59,
                'discount_status' => 'approved',
                'status'          => 'completed',
                'payment_status'  => 'paid',
            ])
            ->assertRedirect();

        $order = $this->latestOrderAt($branch);

        $this->assertNotNull($order, 'the order was refused, so this proves nothing');
        $this->assertSame('60.00', number_format((float) $order->total, 2, '.', ''), 'a posted total was honoured');
        $this->assertSame('0.00', number_format((float) $order->discount_amount, 2, '.', ''), 'a posted discount was honoured');
        $this->assertNotSame('completed', $order->status, 'a posted status was honoured');
        $this->assertNotSame('paid', $order->payment_status, 'a posted payment_status was honoured');
    }

    // ══════════ The PWD/Senior rule, unchanged ══════════

    /**
     * The PWD/Senior discount is ONE 20%-of-subtotal deduction however many IDs
     * are recorded, and the client cannot multiply it by sending more rows.
     *
     * This asserts the EXISTING rule (Order::PWD_SENIOR_DISCOUNT_RATE applied
     * once to the subtotal) — the audit did not change the rate or its target.
     */
    public function test_extra_discount_beneficiary_rows_cannot_multiply_the_discount(): void
    {
        $branch = $this->sizeBranch('A');
        $inv = $this->sizeInventory($branch->id, 50);
        $item = $this->unsizedItem($branch, $inv, 100);

        $cart = $this->cartLine($item, null, 1);

        $this->withSession([
                'cart'       => $cart,
                'branch_id'  => $branch->id,
                'order_type' => 'pick_up',
            ])
            ->from(route('customer.cart'))
            ->post(route('customer.place-order'), [
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $item->id, 'quantity' => 1]],
                'discount_type'  => 'pwd',
                // The FIRST ID uses the original single-row fields, as the cart
                // form does; the repeatable list carries the rest. Rows accept
                // only id_number and full_name — DiscountBeneficiaries::rules()
                // pins that with `array:id_number,full_name`, which is itself a
                // smuggling guard and refuses a row with any other key.
                'discount_beneficiary_name'       => 'A Person',
                'discount_beneficiary_id'         => 'PWD-1',
                // The cart's Apply intent (Batch 2, 2026-09-29); it never
                // changes the amount, and there is no expiration field any more.
                'discount_applied'                => '1',
                'discount_beneficiaries' => [
                    ['id_number' => 'PWD-2', 'full_name' => 'B Person'],
                    ['id_number' => 'PWD-3', 'full_name' => 'C Person'],
                ],
            ])
            ->assertRedirect();

        $order = $this->latestOrderAt($branch);

        $this->assertNotNull($order, 'the order was refused, so this proves nothing about the discount');

        $expected = round(100 * Order::PWD_SENIOR_DISCOUNT_RATE, 2);

        $this->assertSame(
            number_format($expected, 2, '.', ''),
            number_format((float) $order->discount_amount, 2, '.', ''),
            'three recorded IDs produced more than one discount'
        );
    }

    // ══════════ The control ══════════

    /**
     * An UNtampered checkout of the same fixtures must still price normally.
     *
     * Without this, every assertion above would also pass against an
     * application that refused all these orders or zeroed every price.
     */
    public function test_an_untampered_checkout_still_prices_normally(): void
    {
        $branch = $this->sizeBranch('A');
        $inv = $this->sizeInventory($branch->id, 50);
        $item = $this->unsizedItem($branch, $inv, 60);

        $this->placePickUp($branch, $this->cartLine($item, null, 2))->assertRedirect();

        $order = $this->latestOrderAt($branch);

        $this->assertNotNull($order, 'an ordinary checkout was refused');
        $this->assertSame('120.00', number_format((float) $order->subtotal, 2, '.', ''));
        $this->assertSame('120.00', number_format((float) $order->total, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $order->discount_amount, 2, '.', ''));
    }
}

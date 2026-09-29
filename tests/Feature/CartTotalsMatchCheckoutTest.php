<?php

namespace Tests\Feature;

use App\Models\DiscountCard;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The cart must quote the figure the customer will actually be charged, and an
 * expired PWD/Senior card must produce no discount anywhere.
 *
 * Two defects, reported together from one screenshot of the cart page.
 *
 * 1. WRONG TOTAL — real money.
 *    The cart page totalled the prices CACHED in the session when each item
 *    was added; OrderController::placeOrder() re-derived them from the live
 *    menu. Change a price while an item sits in a cart and the two disagree,
 *    with the customer billed the figure they were never shown. Reproduced
 *    exactly: a cart of 2 x "roasted chicken" cached at 200.00 showed
 *    Subtotal 400.00, and the saved orders row read subtotal 520.00 — the
 *    reported number, 2 x the live 260.00. Fixed by both paths going through
 *    App\Support\CartPricing.
 *
 * 2. EXPIRED CARD STILL DISCOUNTED — display only, but misleading.
 *    Picking PWD applied -20% instantly, and entering an expiry of 01/01/1940
 *    printed "This discount card has already expired." while leaving the
 *    discount on screen. The server always refused such an order, so nothing
 *    was ever mis-charged by this one; the rule now lives in
 *    DiscountCard::expirationErrorFor() and the cart page previews with it.
 */
class CartTotalsMatchCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * placeOrder() stores the uploaded PWD/Senior ID on the LOCAL disk.
         * Without this the suite would leave a real file in
         * storage/app/discount_ids for every run, orphaned the moment the
         * surrounding transaction rolls the order back.
         *
         * Security review 2026-08-31 (Pass 4, item #10): this used to fake the
         * `public` disk, because that is where the upload used to go. When the
         * upload moved to `local` — see OrderController::placeOrder() — the
         * fake silently stopped covering it and the suite began leaking real
         * files again. Caught by two stray 78-byte PNGs left behind after a
         * full run. Both disks are faked now so that neither a regression to
         * `public` nor the current `local` path can leak.
         *
         * This is very likely how some of the 60 orphaned uploads that Pass 4
         * deleted accumulated in the first place.
         */
        Storage::fake('local');
        Storage::fake('public');
    }

    private function customer(): User
    {
        return User::where('role', 'customer')->orderBy('id')->first();
    }

    private function item(): MenuItem
    {
        return MenuItem::where('is_available', true)->orderBy('id')->first();
    }

    /** A real PNG, so the `image` validation rule passes without the GD extension. */
    private function idImage(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'idimg') . '.png';

        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC'
        ));

        return new UploadedFile($path, 'id.png', 'image/png', null, true);
    }

    private function cart(MenuItem $item, int $qty, float $cachedPrice): array
    {
        return [(string) $item->id => [
            'menu_item_id' => (string) $item->id,
            'name'         => $item->name,
            'price'        => $cachedPrice,
            'base_price'   => $cachedPrice,
            'quantity'     => $qty,
            'image'        => $item->image,
            'options'      => [],
        ]];
    }

    /** The subtotal the cart page hands its own JavaScript, i.e. what the customer sees. */
    private function cartPageSubtotal(array $cart, User $customer): float
    {
        $html = $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $cart, 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/cart')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/var currentSubtotal = [0-9.]+;/', $html);
        preg_match('/var currentSubtotal = ([0-9.]+);/', $html, $m);

        return (float) $m[1];
    }

    private function placeOrder(array $cart, User $customer, array $extra = [])
    {
        $line = array_values($cart)[0];

        return $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $cart, 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->post('/customer/place-order', array_merge([
                'order_type'     => 'pick_up',
                'payment_method' => 'cash',
                'items'          => [['menu_item_id' => $line['menu_item_id'], 'quantity' => $line['quantity']]],
            ], $extra));
    }

    private function pwdFields(string $expiration): array
    {
        return [
            'discount_type' => 'pwd',
            'discount_beneficiary_name' => 'Juan Dela Cruz',
            'discount_beneficiary_id' => 'PWD-123',
            'discount_beneficiary_expiration' => $expiration,
            'discount_beneficiary_image' => $this->idImage(),
        ];
    }

    /**
     * A Senior Citizen submission. $expiration defaults to '' (blank) — the
     * normal case, since a Senior Citizen ID has no expiration under
     * Philippine law (RA 9994, as amended by RA 10645). Pass a non-empty
     * string to prove a stray/garbage value in the field still never blocks
     * checkout for this type (DiscountCard::requiresExpiration()).
     */
    private function seniorFields(string $expiration = ''): array
    {
        return [
            'discount_type' => 'senior',
            'discount_beneficiary_name' => 'Maria Santos',
            'discount_beneficiary_id' => 'SC-456',
            'discount_beneficiary_expiration' => $expiration,
            'discount_beneficiary_image' => $this->idImage(),
        ];
    }

    // ── 1. The reported wrong total ──────────────────────────────────────────

    /**
     * THE REPORTED BUG. A price edited while the item sits in a cart used to
     * make the cart quote 400.00 and the order save 520.00.
     */
    public function test_a_price_change_while_in_the_cart_cannot_split_the_cart_from_the_charge(): void
    {
        $item = $this->item();
        $customer = $this->customer();

        $cart = $this->cart($item, 2, 200.00);

        // The admin raises the price after the item is already in the cart.
        $item->price = 260.00;
        $item->save();

        $shown = $this->cartPageSubtotal($cart, $customer);

        $this->placeOrder($cart, $customer);
        $order = Order::orderByDesc('id')->first();

        $this->assertSame(520.00, $shown, 'the cart must quote the live price, not the cached one');
        $this->assertSame('520.00', (string) $order->subtotal);
        $this->assertSame($shown, (float) $order->subtotal, 'cart preview and saved order must agree');
        $this->assertSame((float) $order->subtotal, (float) $order->total, 'no discount was used here');
    }

    public function test_an_unchanged_price_still_matches_exactly(): void
    {
        $item = $this->item();
        $customer = $this->customer();

        $cart = $this->cart($item, 2, (float) $item->price);

        $shown = $this->cartPageSubtotal($cart, $customer);
        $this->placeOrder($cart, $customer);

        $order = Order::orderByDesc('id')->first();

        $this->assertSame($shown, (float) $order->subtotal);
        $this->assertSame(round((float) $item->price * 2, 2), $shown);
    }

    /** Preview and charge must still agree once a discount is in play. */
    public function test_a_valid_pwd_card_discounts_the_same_figure_the_cart_showed(): void
    {
        $item = $this->item();
        $customer = $this->customer();

        $cart = $this->cart($item, 2, (float) $item->price);
        $shown = $this->cartPageSubtotal($cart, $customer);

        $this->placeOrder($cart, $customer, $this->pwdFields(now()->addYear()->format('n/j/Y')));

        $order = Order::orderByDesc('id')->first();

        $expectedDiscount = Order::pwdSeniorDiscountFor($shown);

        $this->assertSame($shown, (float) $order->subtotal);
        $this->assertSame($expectedDiscount, (float) $order->discount_amount);
        $this->assertSame(round($shown - $expectedDiscount, 2), (float) $order->total);
        $this->assertSame('pending', $order->discount_status, 'staff still verify the ID');
    }

    /** subtotal - discount = total, to the centavo, for every case above. */
    public function test_saved_orders_always_net_out_exactly(): void
    {
        $item = $this->item();
        $customer = $this->customer();

        $this->placeOrder(
            $this->cart($item, 3, (float) $item->price),
            $customer,
            // The leading-zero MM/DD/YYYY variant, for coverage of both
            // accepted shapes across this file.
            $this->pwdFields(now()->addYear()->format('m/d/Y'))
        );

        $order = Order::orderByDesc('id')->first();

        $this->assertSame(
            round((float) $order->subtotal - (float) $order->discount_amount, 2),
            (float) $order->total
        );
    }

    // ── 2. The expired discount card ─────────────────────────────────────────

    /**
     * The money question: does an expired card ever save a non-zero discount?
     * It must not, and it does not — the order is refused outright.
     */
    public function test_an_expired_card_never_saves_a_discount(): void
    {
        $item = $this->item();
        $customer = $this->customer();

        $before = Order::max('id');

        $res = $this->placeOrder(
            $this->cart($item, 2, (float) $item->price),
            $customer,
            // A real, typed calendar date, just decades in the past.
            $this->pwdFields('1/1/1940')
        );

        $res->assertSessionHasErrors('discount_beneficiary_expiration');
        $this->assertSame($before, Order::max('id'), 'no order may be created from an expired card');

        $this->assertSame(
            0,
            Order::where('id', '>', (int) $before)->where('discount_amount', '>', 0)->count(),
            'an expired card must never produce a discounted order'
        );
    }

    /** The customer keeps their cart when the card is refused. */
    public function test_an_expired_card_does_not_cost_the_customer_their_cart(): void
    {
        $item = $this->item();
        $cart = $this->cart($item, 2, (float) $item->price);

        $this->placeOrder($cart, $this->customer(), $this->pwdFields('1/1/1940'));

        $this->assertSame($cart, session('cart'));
    }

    /** The refusal wording is the shared one, so the page and the server match. */
    public function test_the_expiry_message_is_the_shared_one(): void
    {
        $item = $this->item();

        $res = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $this->customer(),
            $this->pwdFields('1/1/1940')
        );

        $res->assertSessionHasErrors([
            'discount_beneficiary_expiration' => DiscountCard::ERROR_EXPIRED,
        ]);
    }

    /** The shared rule itself, which the cart page's JavaScript mirrors. */
    public function test_the_expiration_rule_is_a_single_source_of_truth(): void
    {
        $this->assertSame(DiscountCard::ERROR_EXPIRED, DiscountCard::expirationErrorFor('1940-01-01'));
        $this->assertSame(DiscountCard::ERROR_EXPIRED, DiscountCard::expirationErrorFor(now()->subDay()->toDateString()));
        $this->assertSame(DiscountCard::ERROR_EXPIRATION_MISSING, DiscountCard::expirationErrorFor(null));
        $this->assertSame(DiscountCard::ERROR_EXPIRATION_MISSING, DiscountCard::expirationErrorFor(''));
        $this->assertSame(DiscountCard::ERROR_EXPIRATION_INVALID, DiscountCard::expirationErrorFor('not a date'));

        $this->assertNull(DiscountCard::expirationErrorFor(now()->toDateString()), 'a card expiring today is still valid today');
        $this->assertNull(DiscountCard::expirationErrorFor(now()->addYear()->toDateString()));
    }

    /**
     * The Day/Month/Year dropdowns on the cart page can construct a
     * day/month combination that never existed on any calendar (day 30 is a
     * valid <option>, February is a valid <option>, but "2027-02-30" is not
     * a real date). PHP's date parser rolls that forward into March instead
     * of rejecting it, so this has to be caught explicitly rather than left
     * to Carbon::parse().
     */
    public function test_an_impossible_calendar_date_is_refused(): void
    {
        $this->assertSame(DiscountCard::ERROR_EXPIRATION_INVALID, DiscountCard::expirationErrorFor('2027-02-30'));
        $this->assertSame(DiscountCard::ERROR_EXPIRATION_INVALID, DiscountCard::expirationErrorFor('2027-04-31'));
        $this->assertSame(DiscountCard::ERROR_EXPIRATION_INVALID, DiscountCard::expirationErrorFor('2027-13-01'));
        $this->assertNull(DiscountCard::expirationErrorFor('2027-02-28'), 'a real, non-leap-year Feb 28 must still be accepted');
    }

    // ── 3. The typed M/D/Y expiration field (September 2026 UX pass) ────────
    //
    // The cart page's Day/Month/Year dropdowns (themselves a replacement for
    // an even older native calendar picker) are gone; the customer now types
    // the expiration date directly. Accepted formats are M/D/Y and
    // MM/DD/YYYY ONLY — see DiscountCard::normalizeTypedExpiration() for the
    // single place that turns typed text into the Y-m-d value the rest of
    // the app already stored and compared before this change, and for why
    // dash-separated, year-first, and 2-digit-year input are refused rather
    // than guessed at.

    /**
     * End-to-end: a real, still-valid date typed as M/D/Y is accepted and an
     * order is placed; an impossible one typed the same way is refused
     * before any order is created, with the shared, human-readable message —
     * not a framework default — because normalizeTypedExpiration() is what
     * refuses it, before expirationErrorFor() is ever reached.
     */
    public function test_a_typed_mdy_expiration_date_is_accepted_when_real_and_refused_when_not(): void
    {
        $item = $this->item();

        $before = Order::max('id');

        $accepted = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $this->customer(),
            $this->pwdFields(now()->addYear()->format('n/j/Y'))
        );

        $accepted->assertSessionDoesntHaveErrors('discount_beneficiary_expiration');
        $this->assertGreaterThan($before, Order::max('id'), 'a real, unexpired typed date must place the order');
        $afterAccepted = Order::max('id');

        $refused = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $this->customer(),
            // February the 30th never exists on any calendar.
            $this->pwdFields('2/30/' . now()->addYear()->format('Y'))
        );

        $refused->assertSessionHasErrors([
            'discount_beneficiary_expiration' => DiscountCard::ERROR_EXPIRATION_INVALID,
        ]);
        $this->assertSame($afterAccepted, Order::max('id'), 'an impossible calendar date must not place an order');
    }

    /** A valid MM/DD/YYYY (leading-zero) value normalizes to the same Y-m-d as its M/D/Y equivalent. */
    public function test_leading_zero_mmddyyyy_normalizes_the_same_as_its_mdy_equivalent(): void
    {
        $this->assertSame(
            DiscountCard::normalizeTypedExpiration('1/5/2027'),
            DiscountCard::normalizeTypedExpiration('01/05/2027')
        );
        $this->assertSame('2027-01-05', DiscountCard::normalizeTypedExpiration('01/05/2027'));
        $this->assertSame('2027-01-05', DiscountCard::normalizeTypedExpiration('1/5/2027'));
    }

    /** End-to-end: the MM/DD/YYYY variant places the same order an M/D/Y submission would. */
    public function test_a_typed_mmddyyyy_expiration_date_is_accepted_end_to_end(): void
    {
        $item = $this->item();
        $before = Order::max('id');

        $res = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $this->customer(),
            $this->pwdFields(now()->addYear()->format('m/d/Y'))
        );

        $res->assertSessionDoesntHaveErrors('discount_beneficiary_expiration');
        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'a valid MM/DD/YYYY submission must place an order');
        $this->assertSame(now()->addYear()->toDateString(), $order->discount_beneficiary_expiration->toDateString());
    }

    /**
     * Invalid dates are rejected outright and never silently coerced into a
     * different, valid-looking date: an impossible date (13/45/2027), a
     * non-existent date (2/30/2027), a leap-year-dependent date (2/29 in a
     * year that is not a leap year), and malformed/garbage text.
     */
    public function test_invalid_typed_dates_are_rejected_without_being_coerced(): void
    {
        $this->assertNull(DiscountCard::normalizeTypedExpiration('13/45/2027'), 'no month is 13 and no day is 45');
        $this->assertNull(DiscountCard::normalizeTypedExpiration('2/30/2027'), 'February never has 30 days');

        // 2027 is not a leap year; 2028 is — same day/month, one refused and
        // the other accepted, so this is genuinely exercising checkdate(),
        // not just rejecting Feb 29 outright.
        $this->assertNull(DiscountCard::normalizeTypedExpiration('2/29/2027'), 'not a leap year');
        $this->assertSame('2028-02-29', DiscountCard::normalizeTypedExpiration('2/29/2028'), 'a real leap year must still be accepted');

        $this->assertNull(DiscountCard::normalizeTypedExpiration('not a date'));
        $this->assertNull(DiscountCard::normalizeTypedExpiration('asdf/gh/ijkl'));
        $this->assertNull(DiscountCard::normalizeTypedExpiration(''));
        $this->assertNull(DiscountCard::normalizeTypedExpiration(null));
    }

    /**
     * End-to-end: an impossible date never places an order, and is refused
     * with the shared message rather than silently becoming a different,
     * valid-looking date (the exact prior bug class this mirrors: Carbon
     * rolling 2/30/2027 forward into March).
     */
    public function test_an_impossible_typed_date_never_places_an_order(): void
    {
        $item = $this->item();
        $before = Order::max('id');

        $res = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $this->customer(),
            $this->pwdFields('13/45/2027')
        );

        $res->assertSessionHasErrors([
            'discount_beneficiary_expiration' => DiscountCard::ERROR_EXPIRATION_INVALID,
        ]);
        $this->assertSame($before, Order::max('id'), 'an impossible date must not place an order under any date');
    }

    /**
     * Ambiguous or otherwise disallowed shapes are refused outright, per the
     * documented policy — never reinterpreted as some other date:
     *
     *   - "1-5-2027"  (dashes)             — PHP reads dash-separated dates
     *                                         as day-month-year, which would
     *                                         silently reverse the day and
     *                                         month from what was typed.
     *   - "2027/1/5"  (year first)         — not one of the two accepted
     *                                         shapes.
     *   - "5/1/27"    (2-digit year)       — "27" is ambiguous between 1927
     *                                         and 2027.
     */
    public function test_ambiguous_or_disallowed_formats_are_refused(): void
    {
        $this->assertNull(DiscountCard::normalizeTypedExpiration('1-5-2027'));
        $this->assertNull(DiscountCard::normalizeTypedExpiration('2027/1/5'));
        $this->assertNull(DiscountCard::normalizeTypedExpiration('5/1/27'));
    }

    /** End-to-end: the same three disallowed shapes are refused by the endpoint. */
    public function test_ambiguous_formats_are_refused_end_to_end(): void
    {
        $item = $this->item();
        $before = Order::max('id');

        foreach (['1-5-2027', '2027/1/5', '5/1/27'] as $disallowed) {
            $res = $this->placeOrder(
                $this->cart($item, 1, (float) $item->price),
                $this->customer(),
                $this->pwdFields($disallowed)
            );

            $res->assertSessionHasErrors([
                'discount_beneficiary_expiration' => DiscountCard::ERROR_EXPIRATION_INVALID,
            ]);
        }

        $this->assertSame($before, Order::max('id'), 'none of the disallowed shapes may place an order');
    }

    /**
     * Displaying an existing Y-m-d value back to the customer in the field's
     * new M/D/Y format, and re-parsing that same text, must round-trip to
     * the identical stored date — the same guarantee "editing an existing
     * record" depends on, even though this field itself is never pre-filled
     * today (a fresh discount claim is typed at every order; see
     * DiscountCard::formatForTypedInput()).
     */
    public function test_an_existing_stored_date_round_trips_through_the_typed_format(): void
    {
        $stored = '2027-01-05';

        $displayed = DiscountCard::formatForTypedInput($stored);
        $this->assertSame('1/5/2027', $displayed);

        $this->assertSame($stored, DiscountCard::normalizeTypedExpiration($displayed));

        // The zero-padded variant of the same displayed value must normalize
        // to the identical stored date too.
        $this->assertSame($stored, DiscountCard::normalizeTypedExpiration('01/05/2027'));
    }

    /**
     * September 2026: the cart page renders PWD's expiration date as three
     * Month/Day/Year <select> dropdowns — not the typed M/D/Y text field (nor
     * a native calendar picker) this replaced. The dropdowns write into a
     * hidden input carrying the same name and the same "M/D/Y" shape
     * DiscountCard::normalizeTypedExpiration() already parses, so the server
     * side of this is unchanged; see the round-trip and end-to-end tests
     * elsewhere in this file for proof that submitted values still work.
     */
    public function test_the_cart_page_renders_month_day_year_dropdowns_not_a_typed_field(): void
    {
        $item = $this->item();

        $html = $this->actingAs($this->customer(), 'customer')
            ->withSession(['cart' => $this->cart($item, 2, (float) $item->price), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/cart')
            ->getContent();

        // The hidden field the server still reads, carrying the same name.
        $this->assertStringContainsString(
            'name="discount_beneficiary_expiration"',
            $html
        );
        $this->assertStringContainsString('id="discountBeneficiaryExpiration"', $html);
        $this->assertStringContainsString('type="hidden"', $html);

        // The three dropdowns that feed it.
        $this->assertStringContainsString('id="discountExpirationMonth"', $html);
        $this->assertStringContainsString('id="discountExpirationDay"', $html);
        $this->assertStringContainsString('id="discountExpirationYear"', $html);
        $this->assertStringContainsString('>Jan<', $html);
        $this->assertStringContainsString('>Dec<', $html);

        // Typing is gone: no free-text placeholder or format hint left behind.
        $this->assertStringNotContainsString('placeholder="M/D/Y"', $html);
        $this->assertStringNotContainsString('Format: M/D/Y or MM/DD/YYYY', $html);
        $this->assertStringNotContainsString('type="date"', $html, 'no native calendar picker either');
    }

    /**
     * The cart page must not be able to preview a discount the server would
     * refuse: it renders the SAME rate and the SAME messages the server uses,
     * and gates the preview on the same expiry check.
     */
    public function test_the_cart_page_previews_with_the_servers_own_rule(): void
    {
        $item = $this->item();

        $html = $this->actingAs($this->customer(), 'customer')
            ->withSession(['cart' => $this->cart($item, 2, (float) $item->price), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/cart')
            ->getContent();

        $this->assertStringContainsString(
            'var PWD_SENIOR_DISCOUNT_RATE = ' . json_encode(Order::PWD_SENIOR_DISCOUNT_RATE),
            $html,
            'the preview rate must come from Order::PWD_SENIOR_DISCOUNT_RATE'
        );

        $this->assertStringContainsString(json_encode(DiscountCard::ERROR_EXPIRED), $html);
        $this->assertStringContainsString('function discountCardExpirationError()', $html);

        // The preview is gated on that function — without this the page could
        // print "expired" and keep showing the discount, which is the bug.
        $this->assertStringContainsString(
            'var expirationError = discountCardExpirationError();',
            $html
        );
    }

    /** A repriced cart says so, rather than silently changing the number. */
    public function test_the_cart_tells_the_customer_when_a_price_changed(): void
    {
        $item = $this->item();
        $customer = $this->customer();

        $cart = $this->cart($item, 1, (float) $item->price + 50);

        $html = $this->actingAs($customer, 'customer')
            ->withSession(['cart' => $cart, 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/cart')
            ->getContent();

        $this->assertStringContainsString('Some prices changed since you added these items', $html);
    }

    // ── 4. Senior Citizen: expiration is optional (September 2026) ──────────
    //
    // Philippine Senior Citizen IDs (RA 9994, as amended by RA 10645) do not
    // expire. PWD IDs DO expire and are renewed (RA 10754 and its
    // implementing rules), so PWD keeps requiring a valid expiration exactly
    // as before — only Senior Citizen is exempted, via
    // DiscountCard::requiresExpiration().

    /** The headline case: a blank expiration must not block a Senior Citizen order. */
    public function test_a_senior_citizen_checkout_with_a_blank_expiration_succeeds(): void
    {
        $item = $this->item();
        $customer = $this->customer();
        $before = Order::max('id');

        $res = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $customer,
            $this->seniorFields('')
        );

        $res->assertSessionDoesntHaveErrors();
        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'a blank expiration must not block a Senior Citizen checkout');
        $this->assertSame('senior', $order->discount_type);
        $this->assertNull($order->discount_beneficiary_expiration, 'nothing to store — Senior Citizen IDs do not expire');
        $this->assertGreaterThan(0, (float) $order->discount_amount, 'the 20% discount must still apply');
    }

    /**
     * Even a garbage value reaching the request must not block Senior Citizen
     * eligibility — the field is accepted-and-ignored for this type, not
     * validated.
     */
    public function test_a_senior_citizen_checkout_with_garbage_expiration_still_succeeds(): void
    {
        $item = $this->item();
        $customer = $this->customer();
        $before = Order::max('id');

        $res = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $customer,
            $this->seniorFields('not a date at all')
        );

        $res->assertSessionDoesntHaveErrors();
        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'garbage in an optional field must not block Senior Citizen checkout');
        $this->assertNull(
            $order->discount_beneficiary_expiration,
            'garbage must never be stored as if it were a real date'
        );
    }

    /**
     * A real, already-expired-looking date — the exact value that would
     * refuse a PWD order with ERROR_EXPIRED — must not block Senior Citizen
     * either: the field is never even evaluated for this type. A stale value
     * left over from switching from PWD is the realistic way this happens.
     */
    public function test_a_senior_citizen_checkout_with_a_stale_expired_date_still_succeeds(): void
    {
        $item = $this->item();
        $customer = $this->customer();
        $before = Order::max('id');

        $res = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $customer,
            $this->seniorFields('1/1/1940')
        );

        $res->assertSessionDoesntHaveErrors();
        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'a stale/expired-looking value must not block Senior Citizen checkout');
        $this->assertNull($order->discount_beneficiary_expiration);
    }

    /**
     * THE CRITICAL REGRESSION TEST: PWD must still require a valid
     * expiration exactly as before. A blank field is refused.
     */
    public function test_a_pwd_checkout_with_a_blank_expiration_is_still_rejected(): void
    {
        $item = $this->item();
        $customer = $this->customer();
        $before = Order::max('id');

        $res = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $customer,
            $this->pwdFields('')
        );

        $res->assertSessionHasErrors();
        $this->assertSame($before, Order::max('id'), 'PWD must still require an expiration date');
    }

    /** THE CRITICAL REGRESSION TEST, invalid half: garbage still refuses a PWD order. */
    public function test_a_pwd_checkout_with_an_invalid_expiration_is_still_rejected(): void
    {
        $item = $this->item();
        $customer = $this->customer();
        $before = Order::max('id');

        $res = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $customer,
            $this->pwdFields('not a date at all')
        );

        $res->assertSessionHasErrors([
            'discount_beneficiary_expiration' => DiscountCard::ERROR_EXPIRATION_INVALID,
        ]);
        $this->assertSame($before, Order::max('id'), 'PWD must still refuse an unparseable expiration');
    }

    /** THE CRITICAL REGRESSION TEST, positive half: a valid PWD expiration still succeeds exactly as before. */
    public function test_a_pwd_checkout_with_a_valid_expiration_still_succeeds(): void
    {
        $item = $this->item();
        $customer = $this->customer();
        $before = Order::max('id');

        $res = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $customer,
            $this->pwdFields(now()->addYear()->format('n/j/Y'))
        );

        $res->assertSessionDoesntHaveErrors();
        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'a valid, unexpired PWD expiration must still place the order');
        $this->assertSame('pwd', $order->discount_type);
        $this->assertSame(now()->addYear()->toDateString(), $order->discount_beneficiary_expiration->toDateString());
    }

    /**
     * September 2026: PWD's expiration date is now entered via three
     * Month/Day/Year <select> dropdowns on the cart page (see
     * test_the_cart_page_renders_month_day_year_dropdowns_not_a_typed_field()),
     * but the three selects only ever combine into the exact "M/D/Y" string
     * this HTTP layer already expects — a selection of "May" / "12" / next
     * year is submitted over the wire exactly as "5/12/<year>", identical to
     * what the old typed field would have sent. This proves that combined
     * value places the order correctly end to end, the same way the existing
     * typed-input test above does.
     */
    public function test_a_pwd_checkout_with_a_dropdown_selected_expiration_succeeds(): void
    {
        $item = $this->item();
        $customer = $this->customer();
        $before = Order::max('id');

        $month = 5;
        $day = 12;
        $year = (int) now()->addYears(2)->format('Y');

        $res = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $customer,
            $this->pwdFields($month . '/' . $day . '/' . $year)
        );

        $res->assertSessionDoesntHaveErrors();
        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'a valid Month/Day/Year selection must still place the order');
        $this->assertSame('pwd', $order->discount_type);
        $this->assertSame(
            sprintf('%04d-%02d-%02d', $year, $month, $day),
            $order->discount_beneficiary_expiration->toDateString()
        );
    }

    /**
     * September 2026: with the static 1-31 Day dropdown (no per-month/year
     * filtering — see the comment above #discountExpirationBlock in
     * cart.blade.php for why), a combination like Feb 30 is a value the
     * dropdowns CAN produce, so the server's checkdate() guard remains the
     * only thing stopping it. Same shared error as the pre-existing typed
     * -field impossible-date coverage above.
     */
    public function test_a_pwd_checkout_with_a_dropdown_selected_impossible_date_is_still_rejected(): void
    {
        $item = $this->item();
        $customer = $this->customer();
        $before = Order::max('id');

        $res = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $customer,
            $this->pwdFields('2/30/' . (now()->year + 1))
        );

        $res->assertSessionHasErrors([
            'discount_beneficiary_expiration' => DiscountCard::ERROR_EXPIRATION_INVALID,
        ]);
        $this->assertSame($before, Order::max('id'), 'Feb 30 from the dropdowns must still be refused');
    }

    /**
     * This task must not touch discount MATH: the 20% figure for Senior
     * Citizen (now with no expiration supplied) must be byte-identical to the
     * same figure for PWD (with a valid expiration supplied), for the same
     * subtotal — both go through the same Order::pwdSeniorDiscountFor().
     */
    public function test_the_discount_amount_is_identical_for_senior_and_pwd_on_the_same_subtotal(): void
    {
        $item = $this->item();
        $customer = $this->customer();
        $qty = 2;

        $seniorBefore = Order::max('id');
        $this->placeOrder($this->cart($item, $qty, (float) $item->price), $customer, $this->seniorFields(''));
        $seniorOrder = Order::where('id', '>', $seniorBefore)->orderByDesc('id')->first();

        $pwdBefore = Order::max('id');
        $this->placeOrder(
            $this->cart($item, $qty, (float) $item->price),
            $customer,
            $this->pwdFields(now()->addYear()->format('n/j/Y'))
        );
        $pwdOrder = Order::where('id', '>', $pwdBefore)->orderByDesc('id')->first();

        $this->assertNotNull($seniorOrder);
        $this->assertNotNull($pwdOrder);
        $this->assertSame((float) $seniorOrder->subtotal, (float) $pwdOrder->subtotal, 'both orders must be priced identically to compare their discounts');

        $expected = Order::pwdSeniorDiscountFor((float) $seniorOrder->subtotal);

        $this->assertSame($expected, (float) $seniorOrder->discount_amount);
        $this->assertSame($expected, (float) $pwdOrder->discount_amount);
        $this->assertSame(
            (float) $seniorOrder->discount_amount,
            (float) $pwdOrder->discount_amount,
            'this task changes WHETHER expiration is checked, never HOW MUCH the discount is worth'
        );
    }

    /**
     * The shared rule itself (DiscountCard::expirationErrorFor()), called the
     * way both OrderController branches now call it: with the discount type.
     * Extends test_the_expiration_rule_is_a_single_source_of_truth() (PWD/
     * default behaviour, unchanged) with the Senior Citizen exemption.
     */
    public function test_expiration_is_only_required_for_pwd_not_senior(): void
    {
        $this->assertTrue(DiscountCard::requiresExpiration('pwd'));
        $this->assertTrue(DiscountCard::requiresExpiration('PWD'));
        $this->assertFalse(DiscountCard::requiresExpiration('senior'));
        $this->assertFalse(DiscountCard::requiresExpiration('SENIOR'));

        // Absent/unrecognised type keeps the original, stricter default —
        // nothing becomes optional by accident.
        $this->assertTrue(DiscountCard::requiresExpiration(null));
        $this->assertTrue(DiscountCard::requiresExpiration(''));
        $this->assertTrue(DiscountCard::requiresExpiration('voucher'));

        // Senior Citizen: no error for any input at all, including a blank,
        // garbage, or an otherwise-expired date.
        $this->assertNull(DiscountCard::expirationErrorFor(null, 'senior'));
        $this->assertNull(DiscountCard::expirationErrorFor('', 'senior'));
        $this->assertNull(DiscountCard::expirationErrorFor('garbage', 'senior'));
        $this->assertNull(DiscountCard::expirationErrorFor('1940-01-01', 'senior'));

        // PWD (and the untyped default, for backward compatibility): unchanged.
        $this->assertSame(DiscountCard::ERROR_EXPIRATION_MISSING, DiscountCard::expirationErrorFor(null, 'pwd'));
        $this->assertSame(DiscountCard::ERROR_EXPIRATION_MISSING, DiscountCard::expirationErrorFor(null));
        $this->assertSame(DiscountCard::ERROR_EXPIRED, DiscountCard::expirationErrorFor('1940-01-01', 'pwd'));
        $this->assertNull(DiscountCard::expirationErrorFor(now()->addYear()->toDateString(), 'pwd'));
    }

    /**
     * September 2026: the expiration block (label, dropdowns, help text) is
     * shipped hidden and selectDiscountType() hides it again for Senior
     * Citizen rather than merely relabelling it — manual testing on a phone
     * showed a visible-but-"not required" field still got filled in by
     * mistake. The block's wrapper carries the `hidden` class server-rendered
     * (matching the page's default "no type selected" state), and the JS
     * that shows/hides it toggles the wrapper, never the field's required-ness.
     * The JS preview/confirm gate must still mirror the server's exemption —
     * a stale value left over from switching types must remain harmless,
     * never blocking, exactly as before.
     */
    public function test_the_cart_page_hides_expiration_entirely_for_senior_citizen(): void
    {
        $item = $this->item();

        $html = $this->actingAs($this->customer(), 'customer')
            ->withSession(['cart' => $this->cart($item, 1, (float) $item->price), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/cart')
            ->getContent();

        $this->assertStringContainsString('id="discountExpirationBlock"', $html);
        $this->assertStringContainsString('id="discountExpirationLabel"', $html);
        $this->assertStringContainsString('id="discountExpirationHelp"', $html);

        // Shipped hidden by default — the page loads with no discount type
        // selected, so nothing PWD-only should be visible yet.
        $this->assertMatchesRegularExpression(
            '/id="discountExpirationBlock"\s+class="hidden"/',
            $html
        );

        // selectDiscountType() toggles that wrapper's hidden class based on
        // type — it must not just swap label/help text and leave the field
        // visible-but-optional the way the September 2026 first pass did.
        $this->assertStringContainsString("if (type === 'senior')", $html);
        $this->assertStringContainsString(
            "expirationBlock.classList.add('hidden')",
            $html
        );
        $this->assertStringContainsString(
            "expirationBlock.classList.remove('hidden')",
            $html
        );

        // The JS twin still exempts Senior Citizen before ever looking at the
        // field's value — hiding the block is cosmetic, not a second source
        // of truth for whether the date is required.
        $this->assertStringContainsString("if (discountType === 'senior')", $html);
    }
}

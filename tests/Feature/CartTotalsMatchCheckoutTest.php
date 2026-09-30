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
 *    was ever mis-charged by this one; the rule then lived in
 *    DiscountCard::expirationErrorFor() and the cart page previewed with it.
 *
 *    SUPERSEDED Batch 2 (2026-09-29): the expiration date was removed from
 *    checkout entirely, for PWD and Senior alike, on request. The end-to-end
 *    tests of section 2-4 that pinned the refusal now pin that no posted
 *    expiration value affects checkout; the cart previews only once the IDs
 *    are Applied. DiscountCard's own date helpers are unchanged and still
 *    tested here as pure functions.
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

    /**
     * A PWD submission as the cart sends it since Batch 2 (2026-09-29): the
     * Apply intent, and no expiration. $staleExpiration is what an
     * out-of-date page might still post under the old field name — checkout
     * never reads it, which the tests below that pass one prove.
     */
    private function pwdFields(?string $staleExpiration = null): array
    {
        return array_filter([
            'discount_type' => 'pwd',
            'discount_applied' => '1',
            'discount_beneficiary_name' => 'Juan Dela Cruz',
            'discount_beneficiary_id' => 'PWD-123',
            'discount_beneficiary_expiration' => $staleExpiration,
            'discount_beneficiary_image' => $this->idImage(),
        ], fn ($value) => $value !== null);
    }

    /** The Senior Citizen twin of pwdFields(). */
    private function seniorFields(?string $staleExpiration = null): array
    {
        return array_filter([
            'discount_type' => 'senior',
            'discount_applied' => '1',
            'discount_beneficiary_name' => 'Maria Santos',
            'discount_beneficiary_id' => 'SC-456',
            'discount_beneficiary_expiration' => $staleExpiration,
            'discount_beneficiary_image' => $this->idImage(),
        ], fn ($value) => $value !== null);
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

        $this->placeOrder($cart, $customer, $this->pwdFields());

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
            $this->pwdFields()
        );

        $order = Order::orderByDesc('id')->first();

        $this->assertSame(
            round((float) $order->subtotal - (float) $order->discount_amount, 2),
            (float) $order->total
        );
    }

    // ── 2. The expired discount card ─────────────────────────────────────────

    /**
     * CHANGED Batch 2 (2026-09-29). Replaces three tests that pinned the
     * refusal of an expired PWD card (never saves a discount / keeps the
     * cart / shared message): the expiry requirement was removed on request,
     * so the same 1940 date a stale page might post is now simply ignored —
     * the order is placed with the one ordinary discount and no date stored.
     */
    public function test_an_expired_looking_date_no_longer_refuses_a_pwd_order(): void
    {
        $item = $this->item();
        $customer = $this->customer();
        $before = Order::max('id');

        $res = $this->placeOrder(
            $this->cart($item, 2, (float) $item->price),
            $customer,
            $this->pwdFields('1/1/1940')
        );

        $res->assertSessionDoesntHaveErrors();
        $order = Order::where('id', '>', (int) $before)->orderByDesc('id')->first();

        $this->assertNotNull($order);
        $this->assertSame(Order::pwdSeniorDiscountFor((float) $order->subtotal), (float) $order->discount_amount);
        $this->assertNull($order->discount_beneficiary_expiration);
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
     * CHANGED Batch 2 (2026-09-29). Replaces four end-to-end tests that pinned
     * how checkout accepted or refused a typed expiration (valid M/D/Y
     * accepted, MM/DD/YYYY stored, impossible date refused, ambiguous shapes
     * refused). Checkout no longer reads the field at all: every one of those
     * shapes — valid, impossible, ambiguous, garbage, blank — now places the
     * same order with the same one discount and stores no date. The pure
     * parser tests around this one are unchanged.
     *
     * @dataProvider stalePostedExpirations
     */
    public function test_no_posted_expiration_shape_affects_checkout_any_more(?string $stale): void
    {
        $item = $this->item();
        $before = Order::max('id');

        $res = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $this->customer(),
            $this->pwdFields($stale)
        );

        $res->assertSessionDoesntHaveErrors();
        $order = Order::where('id', '>', (int) $before)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'expiration "' . $stale . '" must not refuse the order');
        $this->assertSame('pwd', $order->discount_type);
        $this->assertSame(Order::pwdSeniorDiscountFor((float) $order->subtotal), (float) $order->discount_amount);
        $this->assertNull($order->discount_beneficiary_expiration, 'nothing is stored for a new order');
    }

    public static function stalePostedExpirations(): array
    {
        return [
            'none posted'           => [null],
            'blank'                 => [''],
            'valid M/D/Y'           => ['5/12/' . (date('Y') + 2)],
            'valid MM/DD/YYYY'      => ['05/12/' . (date('Y') + 2)],
            'impossible Feb 30'     => ['2/30/' . (date('Y') + 1)],
            'impossible 13/45'      => ['13/45/2027'],
            'dash-separated'        => ['1-5-2027'],
            'year first'            => ['2027/1/5'],
            'two-digit year'        => ['5/1/27'],
            'garbage'               => ['not a date at all'],
        ];
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
     * CHANGED Batch 2 (2026-09-29). Was "renders month/day/year dropdowns, not
     * a typed field": the expiration date is gone from the cart entirely —
     * no dropdowns, no hidden field, no label — for PWD and Senior alike.
     */
    public function test_the_cart_page_renders_no_expiration_field_at_all(): void
    {
        $item = $this->item();

        $html = $this->actingAs($this->customer(), 'customer')
            ->withSession(['cart' => $this->cart($item, 2, (float) $item->price), 'branch_id' => 1, 'order_type' => 'pick_up'])
            ->get('/customer/cart')
            ->getContent();

        $this->assertStringNotContainsString('name="discount_beneficiary_expiration"', $html);
        $this->assertStringNotContainsString('id="discountBeneficiaryExpiration"', $html);
        $this->assertStringNotContainsString('id="discountExpirationMonth"', $html);
        $this->assertStringNotContainsString('id="discountExpirationDay"', $html);
        $this->assertStringNotContainsString('id="discountExpirationYear"', $html);
        $this->assertStringNotContainsString('id="discountExpirationBlock"', $html);
        $this->assertStringNotContainsString('Expiration Date', $html);
        $this->assertStringNotContainsString('placeholder="M/D/Y"', $html);
        $this->assertStringNotContainsString('type="date"', $html);
    }

    /**
     * The cart page must not be able to preview a discount the server would
     * not give: it renders the SAME rate the server uses, and — CHANGED Batch
     * 2 (2026-09-29), was "gates the preview on the same expiry check" — gates
     * the preview on the same Apply intent checkout requires.
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

        // No expiry rule is left to gate on.
        $this->assertStringNotContainsString('discountCardExpirationError', $html);
        $this->assertStringNotContainsString(json_encode(DiscountCard::ERROR_EXPIRED), $html);

        // currentCardDiscount() gives nothing until the IDs are Applied — the
        // same condition as the hidden discount_applied the server requires.
        $this->assertMatchesRegularExpression(
            '/function currentCardDiscount\(\) \{.*?if \(!discountIdsApplied\) \{\s+return null;/s',
            $html
        );
        $this->assertStringContainsString('name="discount_applied" id="discountApplied"', $html);
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
    // expire. Until Batch 2 (2026-09-29) PWD still required one; since then
    // neither type does. The three Senior tests below are unchanged apart
    // from the helper now posting the Apply intent.

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

    /*
     * CHANGED Batch 2 (2026-09-29): five PWD tests stood here —
     *   test_a_pwd_checkout_with_a_blank_expiration_is_still_rejected
     *   test_a_pwd_checkout_with_an_invalid_expiration_is_still_rejected
     *   test_a_pwd_checkout_with_a_valid_expiration_still_succeeds
     *   test_a_pwd_checkout_with_a_dropdown_selected_expiration_succeeds
     *   test_a_pwd_checkout_with_a_dropdown_selected_impossible_date_is_still_rejected
     * — pinning that PWD required a valid expiration. That requirement was
     * removed on request, so they are replaced by the data-provided
     * test_no_posted_expiration_shape_affects_checkout_any_more() in section
     * 3, which runs every one of those inputs (blank, garbage, valid,
     * dropdown-shaped, Feb 30) and asserts the order is placed with the one
     * ordinary discount and no stored date.
     */

    /**
     * This task must not touch discount MATH: the 20% figure for Senior
     * Citizen must be byte-identical to the same figure for PWD, for the same
     * subtotal — both go through the same Order::pwdSeniorDiscountFor().
     */
    public function test_the_discount_amount_is_identical_for_senior_and_pwd_on_the_same_subtotal(): void
    {
        $item = $this->item();
        $customer = $this->customer();
        $qty = 2;

        $seniorBefore = Order::max('id');
        $this->placeOrder($this->cart($item, $qty, (float) $item->price), $customer, $this->seniorFields());
        $seniorOrder = Order::where('id', '>', $seniorBefore)->orderByDesc('id')->first();

        $pwdBefore = Order::max('id');
        $this->placeOrder(
            $this->cart($item, $qty, (float) $item->price),
            $customer,
            $this->pwdFields()
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
}

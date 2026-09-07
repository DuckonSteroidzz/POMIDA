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

        $this->placeOrder($cart, $customer, $this->pwdFields(now()->addYear()->toDateString()));

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
            $this->pwdFields(now()->addYear()->toDateString())
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
            $this->pwdFields('1940-01-01')
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

        $this->placeOrder($cart, $this->customer(), $this->pwdFields('1940-01-01'));

        $this->assertSame($cart, session('cart'));
    }

    /** The refusal wording is the shared one, so the page and the server match. */
    public function test_the_expiry_message_is_the_shared_one(): void
    {
        $item = $this->item();

        $res = $this->placeOrder(
            $this->cart($item, 1, (float) $item->price),
            $this->customer(),
            $this->pwdFields('1940-01-01')
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
}

<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Services\InventoryDeductionService;
use App\Services\VoucherClaims;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Staff entering a CUSTOMER'S voucher code on a manual (counter) order.
 *
 * WHY THIS EXISTS
 * ---------------
 * Some customers cannot use the app themselves — elderly, not comfortable with
 * a phone, or they simply did not bring one. Staff already key in the whole
 * order for them through the Manual Order modal, and this lets them key in the
 * customer's voucher too, so having no phone stops being a reason to lose a
 * prize the customer genuinely won.
 *
 * WHAT THE ASSERTIONS ARE ACTUALLY ABOUT
 * --------------------------------------
 * Not "does a discount come off" — that is the easy half. The hard half is that
 * the counter must not become a second, weaker way to spend a code:
 *
 *   - a code spent here cannot then be spent online, or on another counter
 *     order, because both paths consume the SAME used_count and the SAME claim
 *     row through VoucherClaims::redeem();
 *   - every refusal is the customer flow's own sentence, from
 *     Voucher::redemptionErrorFor(), so staff can read the real reason out
 *     instead of guessing at a generic failure;
 *   - when a voucher and a PWD/Senior discount are both on one order, the
 *     bigger wins by Order::voucherBeatsCard() — the same single definition the
 *     customer's cart uses — and the LOSER is left completely unspent. Both
 *     halves of that are asserted, because burning a claim that bought the
 *     customer nothing is the failure that actually costs them money.
 */
class ManualOrderVoucherTest extends TestCase
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
     * The stock filter is not incidental. storeManualOrder() runs a pre-flight
     * inventory check before it ever looks at a discount, so an item whose
     * recipe touches a depleted ingredient is refused with "Not enough X" and
     * every assertion below would fail for a reason that has nothing to do with
     * vouchers. Resolving the item against live stock keeps this file about the
     * feature under test rather than about the state of the store cupboard.
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
            // Two of them, because several tests order a pair to get a subtotal
            // large enough for the two discounts to be told apart.
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
        return User::where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    private function customer(): User
    {
        return User::where('role', 'customer')->orderBy('id')->firstOrFail();
    }

    /** Subtotal of the payload below: two of the picked item. */
    private function subtotal(): float
    {
        return round((float) $this->item()->price * 2, 2);
    }

    /** What the Manual Order modal posts. */
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
                    'quantity'     => '2',
                    'options'      => [],
                ],
            ],
        ], $override);
    }

    private function pwdFields(): array
    {
        return [
            'discount_type'             => 'pwd',
            'discount_beneficiary_name' => 'Juan Dela Cruz',
            'discount_beneficiary_id'   => 'PWD-12345',
        ];
    }

    /**
     * Submit a manual order and return the response together with the order it
     * created, or null when it created none.
     *
     * The order is found by high-water mark rather than "the newest row", so a
     * refused submission can never be mistaken for a successful one by picking
     * up somebody else's pre-existing order.
     *
     * @return array{0: \Illuminate\Testing\TestResponse, 1: ?object}
     */
    private function submit(array $payload, ?User $actor = null): array
    {
        $before = (int) (DB::table('orders')->max('id') ?? 0);

        $response = $this->actingAs($actor ?? $this->staff(), 'admin')
            ->from('/admin/home')
            ->post('/admin/manual-order', $payload);

        $order = DB::table('orders')->where('id', '>', $before)->orderByDesc('id')->first();

        return [$response, $order];
    }

    /** A public promo code: no wheel win needed, so no claim row. */
    private function publicVoucher(array $attrs = []): Voucher
    {
        return Voucher::create(array_merge([
            'code'            => 'MOV' . strtoupper(substr(uniqid(), -7)),
            'description'     => 'manual order voucher test',
            'discount_type'   => 'fixed',
            'discount_value'  => 50,
            'max_uses'        => 100,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'expires_at'      => now()->addMonth(),
            'valid_from'      => null,
            'is_active'       => true,
            'points_required' => 0,
        ], $attrs));
    }

    /** A wheel-won voucher, which is only spendable via a claim code. */
    private function wheelVoucher(array $attrs = []): Voucher
    {
        return $this->publicVoucher(array_merge(['points_required' => 5], $attrs));
    }

    /*
    |--------------------------------------------------------------------------
    | 1. A VALID CODE COMES OFF THE TOTAL AND IS MARKED USED
    |--------------------------------------------------------------------------
    */

    public function test_a_valid_code_discounts_the_manual_order_and_is_marked_used(): void
    {
        $voucher  = $this->publicVoucher(['discount_value' => 50]);
        $subtotal = $this->subtotal();

        [$response, $order] = $this->submit($this->payload([
            'voucher_code' => $voucher->code,
        ]));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.home'));

        $this->assertNotNull($order, 'the counter order never reached the database');
        $this->assertSame('voucher', $order->discount_type);
        $this->assertSame((int) $voucher->id, (int) $order->voucher_id);

        $expected = $voucher->discountFor($subtotal);
        $this->assertEqualsWithDelta($expected, (float) $order->discount_amount, 0.001);

        // The charged total, not just the recorded discount.
        $this->assertEqualsWithDelta(
            round($subtotal - $expected, 2),
            (float) $order->total,
            0.001,
            'the saved total does not equal subtotal minus the discount'
        );

        // Spent exactly once, on the shared counter the online path also uses.
        $this->assertSame(1, (int) $voucher->fresh()->used_count);
    }

    /**
     * Every code source this project mints must work, since they all come out
     * of the same VoucherClaims minting path and are all resolved by the same
     * resolveTypedCode(). Asserted per source rather than assumed.
     */
    public static function claimSourceCases(): array
    {
        return [
            'wheel-won (guest claim)'   => ['guest'],
            'admin-issued walk-in code' => ['counter'],
            'points-reward code'        => ['reward'],
        ];
    }

    /**
     * @dataProvider claimSourceCases
     */
    public function test_every_claim_code_source_is_spendable_at_the_counter(string $source): void
    {
        $voucher = $this->wheelVoucher(['discount_value' => 40]);

        /*
         * A points-reward code and an admin-issued walk-in code are the SAME
         * call in the application (AdminController uses mintForCounter() for
         * both), and a wheel win is mintForGuest(). Minting them the way the
         * application mints them is the point: a fourth source added through
         * this service is covered too.
         */
        $claim = $source === 'guest'
            ? VoucherClaims::mintForGuest($voucher, today()->toDateString())
            : VoucherClaims::mintForCounter($voucher, (int) $this->admin()->id);

        [$response, $order] = $this->submit($this->payload([
            // Typed the way staff would read it off the customer's screen,
            // with the display separators in place.
            'voucher_code' => VoucherClaims::display($claim->claim_code),
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order, 'a ' . $source . ' code was refused at the counter');
        $this->assertSame('voucher', $order->discount_type);
        $this->assertEqualsWithDelta(40.0, (float) $order->discount_amount, 0.001);

        $this->assertTrue((bool) $claim->fresh()->is_used, 'the claim was not burned');
        $this->assertNotNull($claim->fresh()->used_at);
        $this->assertSame(1, (int) $voucher->fresh()->used_count);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. A CODE CANNOT BE SPENT TWICE — INCLUDING ACROSS THE TWO PATHS
    |--------------------------------------------------------------------------
    */

    public function test_a_claim_spent_at_the_counter_cannot_be_spent_again_at_the_counter(): void
    {
        $voucher = $this->wheelVoucher();
        $claim   = VoucherClaims::mintForCounter($voucher, (int) $this->admin()->id);
        $code    = VoucherClaims::display($claim->claim_code);

        [$first, $firstOrder] = $this->submit($this->payload(['voucher_code' => $code]));
        $first->assertSessionHasNoErrors();
        $this->assertNotNull($firstOrder);

        [$second, $secondOrder] = $this->submit($this->payload(['voucher_code' => $code]));

        $second->assertSessionHasErrors();
        $this->assertNull($secondOrder, 'a second counter order was created on an already-spent code');
        $this->assertSame(1, (int) $voucher->fresh()->used_count, 'the code was counted twice');
    }

    public function test_a_claim_spent_at_the_counter_cannot_then_be_spent_online(): void
    {
        $voucher = $this->wheelVoucher();
        $claim   = VoucherClaims::mintForCounter($voucher, (int) $this->admin()->id);
        $code    = VoucherClaims::display($claim->claim_code);

        [$counter, $counterOrder] = $this->submit($this->payload(['voucher_code' => $code]));
        $counter->assertSessionHasNoErrors();
        $this->assertNotNull($counterOrder);

        // Now the customer tries the same code in their own cart. This is the
        // assertion that the two paths share ONE counter rather than each
        // keeping their own.
        $item   = $this->item();
        $before = (int) (DB::table('orders')->max('id') ?? 0);

        $this->actingAs($this->customer(), 'customer')
            ->withSession([
                'cart' => [
                    (string) $item->id => [
                        'menu_item_id' => $item->id,
                        'quantity'     => 1,
                        'price'        => (float) $item->price,
                        'options'      => [],
                    ],
                ],
                'branch_id'  => 1,
                'order_type' => 'pick_up',
            ])
            ->post('/customer/place-order', [
                'order_type'             => 'pick_up',
                'payment_method'         => 'cash',
                'branch_id'              => 1,
                'voucher_code_confirmed' => $code,
                'items'                  => [
                    ['menu_item_id' => $item->id, 'quantity' => 1],
                ],
            ]);

        $onlineOrder = DB::table('orders')->where('id', '>', $before)->first();

        $this->assertNull(
            $onlineOrder,
            'a code already spent at the counter was spent again online'
        );
        $this->assertSame(1, (int) $voucher->fresh()->used_count);
    }

    public function test_an_already_used_code_is_refused_and_nothing_is_discounted(): void
    {
        $voucher = $this->wheelVoucher();
        $claim   = VoucherClaims::mintForCounter($voucher, (int) $this->admin()->id);

        // Burned before this order is ever attempted.
        $claim->forceFill(['is_used' => true, 'used_at' => now()])->save();

        [$response, $order] = $this->submit($this->payload([
            'voucher_code' => VoucherClaims::display($claim->claim_code),
        ]));

        $response->assertSessionHasErrors('voucher_code');
        // The validator's own sentence, not a generic failure — it is refused
        // before the order is ever built, so this never reaches the redemption
        // race guard inside the transaction.
        $this->assertSame(
            'You have already used this voucher.',
            (string) session('errors')->first('voucher_code')
        );

        // The whole order is refused, not quietly saved at full price behind an
        // error nobody reads — and certainly not saved WITH a discount.
        $this->assertNull($order, 'an order was created despite the refused code');
        $this->assertSame(0, (int) $voucher->fresh()->used_count);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. EACH REFUSAL SAYS WHICH THING IS WRONG
    |--------------------------------------------------------------------------
    |
    | Staff read this sentence out to the customer, so "invalid code" for all
    | four cases would be a worse feature than no feature: the customer cannot
    | tell a typo from an expired prize.
    */

    public function test_an_expired_code_is_refused_with_its_own_message(): void
    {
        $voucher = $this->publicVoucher(['expires_at' => now()->subDay()]);

        [$response, $order] = $this->submit($this->payload([
            'voucher_code' => $voucher->code,
        ]));

        $response->assertSessionHasErrors('voucher_code');
        $this->assertSame(
            'This voucher has expired.',
            (string) session('errors')->first('voucher_code')
        );
        $this->assertNull($order);
    }

    public function test_a_not_yet_valid_code_is_refused_with_its_own_message(): void
    {
        $voucher = $this->publicVoucher(['valid_from' => today()->addWeek()]);

        [$response, $order] = $this->submit($this->payload([
            'voucher_code' => $voucher->code,
        ]));

        $response->assertSessionHasErrors('voucher_code');
        $this->assertStringContainsString(
            'not yet valid',
            (string) session('errors')->first('voucher_code')
        );
        $this->assertNull($order);
    }

    public function test_a_code_below_its_minimum_order_is_refused_with_its_own_message(): void
    {
        // Comfortably above anything this two-item basket can reach.
        $voucher = $this->publicVoucher([
            'minimum_order' => round($this->subtotal() + 5000, 2),
        ]);

        [$response, $order] = $this->submit($this->payload([
            'voucher_code' => $voucher->code,
        ]));

        $response->assertSessionHasErrors('voucher_code');
        $this->assertStringContainsString(
            'Minimum order',
            (string) session('errors')->first('voucher_code')
        );
        $this->assertNull($order);
    }

    public function test_a_nonexistent_code_is_refused_cleanly(): void
    {
        [$response, $order] = $this->submit($this->payload([
            'voucher_code' => 'NO-SUCH-CODE-12345',
        ]));

        // A refusal, not a 500 and not a raw exception page.
        $response->assertStatus(302);
        $response->assertSessionHasErrors('voucher_code');
        $this->assertSame(
            'Invalid voucher code.',
            (string) session('errors')->first('voucher_code')
        );
        $this->assertNull($order);
    }

    /**
     * A wheel voucher's SHARED code, typed at the counter by someone who never
     * won it, must still be refused. This is the item-30 money bypass, and
     * staff must not be a way around it — the customer needs the claim code.
     */
    public function test_a_shared_wheel_code_without_a_claim_is_still_refused(): void
    {
        $voucher = $this->wheelVoucher();

        [$response, $order] = $this->submit($this->payload([
            'voucher_code' => $voucher->code,
        ]));

        $response->assertSessionHasErrors('voucher_code');
        $this->assertStringContainsString(
            'claim code',
            (string) session('errors')->first('voucher_code')
        );
        $this->assertNull($order);
        $this->assertSame(0, (int) $voucher->fresh()->used_count);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. VOUCHER vs PWD/SENIOR ON ONE COUNTER ORDER
    |--------------------------------------------------------------------------
    */

    public function test_the_bigger_voucher_wins_and_the_pwd_discount_is_not_recorded(): void
    {
        $subtotal  = $this->subtotal();
        $cardWorth = Order::pwdSeniorDiscountFor($subtotal);

        // Comfortably bigger than the 20% PWD/Senior discount.
        $worth   = round($cardWorth + 25, 2);
        $voucher = $this->publicVoucher(['discount_value' => $worth]);

        [$response, $order] = $this->submit($this->payload(array_merge(
            $this->pwdFields(),
            ['voucher_code' => $voucher->code]
        )));

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);

        $this->assertSame('voucher', $order->discount_type, 'the bigger voucher should have won');
        $this->assertEqualsWithDelta($worth, (float) $order->discount_amount, 0.001);
        $this->assertSame((int) $voucher->id, (int) $order->voucher_id);

        // The losing PWD/Senior must not be recorded as if it had applied —
        // otherwise the counter's own records show a discount that was never
        // given, against a real person's name and ID number.
        $this->assertNull($order->discount_beneficiary_name);
        $this->assertNull($order->discount_beneficiary_card_number);
    }

    public function test_the_bigger_pwd_discount_wins_and_the_voucher_is_left_completely_unspent(): void
    {
        $subtotal  = $this->subtotal();
        $cardWorth = Order::pwdSeniorDiscountFor($subtotal);

        $this->assertGreaterThan(1.0, $cardWorth, 'this test needs the card to be worth more than the voucher');

        $voucher = $this->publicVoucher(['discount_value' => 1.00]);

        [$response, $order] = $this->submit($this->payload(array_merge(
            $this->pwdFields(),
            ['voucher_code' => $voucher->code]
        )));

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);

        $this->assertSame('pwd', $order->discount_type, 'the bigger PWD discount should have won');
        $this->assertEqualsWithDelta($cardWorth, (float) $order->discount_amount, 0.001);
        $this->assertSame('Juan Dela Cruz', $order->discount_beneficiary_name);

        // Both halves of "left completely unspent".
        $this->assertNull($order->voucher_id, 'the losing voucher was attributed to the order anyway');
        $this->assertSame(
            0,
            (int) $voucher->fresh()->used_count,
            'the losing voucher was counted against its usage limit'
        );
    }

    /**
     * The half that actually costs the customer money: a claim-backed voucher
     * that LOSES must keep its claim, or they have lost a prize and received
     * nothing for it.
     */
    public function test_a_claim_backed_voucher_that_loses_keeps_its_claim(): void
    {
        $cardWorth = Order::pwdSeniorDiscountFor($this->subtotal());

        $this->assertGreaterThan(1.0, $cardWorth);

        $voucher = $this->wheelVoucher(['discount_value' => 1.00]);
        $claim   = VoucherClaims::mintForCounter($voucher, (int) $this->admin()->id);

        [$response, $order] = $this->submit($this->payload(array_merge(
            $this->pwdFields(),
            ['voucher_code' => VoucherClaims::display($claim->claim_code)]
        )));

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);
        $this->assertSame('pwd', $order->discount_type);

        $this->assertFalse(
            (bool) $claim->fresh()->is_used,
            'the losing claim was burned — the customer lost a voucher they never benefited from'
        );
        $this->assertNull($claim->fresh()->used_at);
        $this->assertNull($order->voucher_id);
        $this->assertSame(0, (int) $voucher->fresh()->used_count);
    }

    /**
     * The claim survives the loss well enough to be spent for real afterwards.
     * "Not marked used" is not the same claim as "still usable", and the second
     * is the one the customer cares about.
     */
    public function test_a_losing_claim_is_still_spendable_on_a_later_order(): void
    {
        $voucher = $this->wheelVoucher(['discount_value' => 1.00]);
        $claim   = VoucherClaims::mintForCounter($voucher, (int) $this->admin()->id);
        $code    = VoucherClaims::display($claim->claim_code);

        // Order one: the PWD/Senior discount wins, so the voucher loses.
        [$lost] = $this->submit($this->payload(array_merge(
            $this->pwdFields(),
            ['voucher_code' => $code]
        )));
        $lost->assertSessionHasNoErrors();

        // Order two: no PWD/Senior, so the voucher is unopposed.
        [$response, $order] = $this->submit($this->payload(['voucher_code' => $code]));

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);
        $this->assertSame('voucher', $order->discount_type);
        $this->assertTrue((bool) $claim->fresh()->is_used);
        $this->assertSame(1, (int) $voucher->fresh()->used_count);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. REGRESSION — THE ORDERS THAT CARRY NO CODE
    |--------------------------------------------------------------------------
    */

    public function test_a_manual_order_with_no_code_behaves_exactly_as_before(): void
    {
        [$response, $order] = $this->submit($this->payload());

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);
        $this->assertNull($order->discount_type);
        $this->assertNull($order->voucher_id);
        $this->assertEqualsWithDelta(0.0, (float) $order->discount_amount, 0.001);
        $this->assertEqualsWithDelta($this->subtotal(), (float) $order->total, 0.001);
    }

    public function test_an_empty_code_field_is_treated_as_no_code_at_all(): void
    {
        // The modal posts the field on every submission, so a blank box must
        // not be read as an attempt to use a voucher.
        [$response, $order] = $this->submit($this->payload(['voucher_code' => '   ']));

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);
        $this->assertNull($order->discount_type);
        $this->assertNull($order->voucher_id);
    }

    public function test_a_manual_order_with_pwd_only_behaves_exactly_as_before(): void
    {
        $subtotal = $this->subtotal();

        [$response, $order] = $this->submit($this->payload($this->pwdFields()));

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);
        $this->assertSame('pwd', $order->discount_type);
        $this->assertSame('Juan Dela Cruz', $order->discount_beneficiary_name);
        $this->assertSame('PWD-12345', $order->discount_beneficiary_card_number);
        // Staff verified the physical ID in person, so there is nothing left to
        // approve — unlike the online photo-upload flow's 'pending'.
        $this->assertSame('approved', $order->discount_status);
        $this->assertNull($order->voucher_id);
        $this->assertEqualsWithDelta(
            Order::pwdSeniorDiscountFor($subtotal),
            (float) $order->discount_amount,
            0.001
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 6. THE MODAL QUOTES WHAT THE SERVER CHARGES
    |--------------------------------------------------------------------------
    */

    public function test_the_preview_quotes_the_figure_the_order_actually_charges(): void
    {
        $voucher  = $this->publicVoucher(['discount_value' => 30]);
        $subtotal = $this->subtotal();

        $preview = $this->actingAs($this->staff(), 'admin')
            ->postJson('/admin/manual-order/voucher-preview', [
                'code'     => $voucher->code,
                'subtotal' => $subtotal,
            ]);

        $preview->assertOk();
        $preview->assertJson(['success' => true]);

        [$response, $order] = $this->submit($this->payload([
            'voucher_code' => $voucher->code,
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertNotNull($order);

        // Staff read the preview out and took the customer's money against it.
        $this->assertEqualsWithDelta(
            (float) $preview->json('discount'),
            (float) $order->discount_amount,
            0.001,
            'the modal quoted a discount the order did not charge'
        );
        $this->assertEqualsWithDelta(
            (float) $preview->json('final_total'),
            (float) $order->total,
            0.001,
            'the modal quoted a total the order did not charge'
        );
    }

    public function test_the_preview_reports_a_refusal_with_the_servers_own_wording(): void
    {
        $voucher = $this->publicVoucher(['expires_at' => now()->subDay()]);

        $preview = $this->actingAs($this->staff(), 'admin')
            ->postJson('/admin/manual-order/voucher-preview', [
                'code'     => $voucher->code,
                'subtotal' => $this->subtotal(),
            ]);

        $preview->assertOk();
        $preview->assertJson([
            'success' => false,
            'message' => 'This voucher has expired.',
        ]);
    }

    public function test_the_preview_spends_nothing(): void
    {
        $voucher = $this->wheelVoucher();
        $claim   = VoucherClaims::mintForCounter($voucher, (int) $this->admin()->id);

        $this->actingAs($this->staff(), 'admin')
            ->postJson('/admin/manual-order/voucher-preview', [
                'code'     => VoucherClaims::display($claim->claim_code),
                'subtotal' => $this->subtotal(),
            ])
            ->assertJson(['success' => true]);

        // Checking a code must be free: staff will check one, be interrupted,
        // and check it again before the order is ever submitted.
        $this->assertFalse((bool) $claim->fresh()->is_used);
        $this->assertSame(0, (int) $voucher->fresh()->used_count);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. THE MODAL ACTUALLY OFFERS THE FIELD
    |--------------------------------------------------------------------------
    */

    public function test_the_modal_carries_a_voucher_field_and_decides_the_tie_the_same_way(): void
    {
        $page = $this->actingAs($this->staff(), 'admin')->get('/admin/home');

        $page->assertOk();
        $page->assertSee('name="voucher_code"', false);

        $view = file_get_contents(resource_path('views/admin/home.blade.php'));

        // Strictly greater-than, matching Order::voucherBeatsCard(), so the
        // figure staff quotes is the figure the order charges on a tie too.
        $this->assertStringContainsString(
            'voucherDiscount > cardDiscount',
            $view,
            'the modal preview must use the same strictly-greater-than tie-break as the server'
        );
    }
}

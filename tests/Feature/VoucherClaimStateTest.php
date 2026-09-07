<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use App\Services\VoucherClaims;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * A voucher shown as yours must be a voucher the server will actually accept.
 *
 * THE BUG THIS EXISTS FOR
 * -----------------------
 * Reported live: a customer applied a voucher code on the cart and was told
 * "This voucher can only be used if you won it from the game." Their objection
 * was that the voucher was already associated with their account, had never
 * been used, and yet could neither be redeemed nor got rid of — it just sat
 * there in an unusable state.
 *
 * The rules were mostly right; the PRESENTATION was wrong, in a way that
 * guaranteed the two would disagree. The Vouchers page worked out for itself
 * whether a claim was usable, and it read the SHARED vouchers.valid_from column
 * while the cart and checkout read the customer's own user_vouchers.valid_from,
 * which is where item 41 moved the per-customer window. It also never looked at
 * is_active, expires_at or max_uses at all. So a claim whose personal window
 * opens tomorrow got a green "Valid now" badge and a working Copy button, and
 * the cart then refused it.
 *
 * WHAT MUST NOT MOVE
 * ------------------
 * The item-30 rule that a wheel voucher (points_required > 0) requires a
 * genuine unused claim. That closed a real money bypass and is re-asserted
 * below. Nothing here changes what any customer is charged: the rules
 * redemptionErrorFor() applies, and the discount discountFor() computes, are
 * byte-for-byte the same decisions — only who else can ask them changed.
 */
class VoucherClaimStateTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('apply-voucher');
    }

    private function customer(): User
    {
        return User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function wheelVoucher(array $attrs = []): Voucher
    {
        return Voucher::create(array_merge([
            'code'            => 'VCS' . strtoupper(substr(uniqid(), -7)),
            'description'     => 'Claim state test voucher',
            'discount_type'   => 'fixed',
            'discount_value'  => 10,
            'max_uses'        => 0,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'expires_at'      => now()->addMonth(),
            'valid_from'      => null,
            'is_active'       => true,
            'points_required' => 5,
        ], $attrs));
    }

    private function claim(Voucher $voucher, User $user, array $attrs = []): UserVoucher
    {
        return UserVoucher::create(array_merge([
            'user_id'       => $user->id,
            'voucher_id'    => $voucher->id,
            'acquired_date' => today()->toDateString(),
            'valid_from'    => today()->toDateString(),
            'is_used'       => false,
        ], $attrs));
    }

    /** What the cart's apply-voucher endpoint answers for this customer. */
    private function applyOnCart(User $user, Voucher $voucher, float $subtotal = 500.0): array
    {
        return $this->actingAs($user, 'customer')
            ->postJson('/customer/apply-voucher', [
                'code'     => $voucher->code,
                'subtotal' => $subtotal,
            ])
            ->json();
    }

    // ══════════ the page and the server must agree ══════════

    public static function unusableClaims(): array
    {
        return [
            'window opens tomorrow' => ['not_yet_valid'],
            'voucher deactivated'   => ['deactivated'],
            'voucher expired'       => ['expired'],
            'usage limit reached'   => ['limit'],
        ];
    }

    private function makeUnusable(string $kind, User $user): UserVoucher
    {
        return match ($kind) {
            'not_yet_valid' => $this->claim(
                $this->wheelVoucher(['valid_from' => today()->subDays(3)->toDateString()]),
                $user,
                ['valid_from' => today()->addDay()->toDateString()]
            ),
            'deactivated' => $this->claim($this->wheelVoucher(['is_active' => false]), $user),
            'expired'     => $this->claim($this->wheelVoucher(['expires_at' => now()->subDay()]), $user),
            'limit'       => $this->claim(
                $this->wheelVoucher(['max_uses' => 1, 'used_count' => 1]),
                $user
            ),
        };
    }

    /**
     * @dataProvider unusableClaims
     */
    public function test_an_unusable_claim_is_never_shown_as_ready(string $kind): void
    {
        $user  = $this->customer();
        $claim = $this->makeUnusable($kind, $user);

        $this->assertNotSame('ready', $claim->statusKey(), 'the page would offer a voucher the cart refuses');
        $this->assertNotNull($claim->blockingReason());

        // And the page says the same thing the cart does, word for word.
        $cart = $this->applyOnCart($user, $claim->voucher);

        $this->assertFalse($cart['success']);
        $this->assertSame($cart['message'], $claim->blockingReason());
    }

    /**
     * @dataProvider unusableClaims
     */
    public function test_the_vouchers_page_does_not_offer_copy_for_an_unusable_claim(string $kind): void
    {
        $user  = $this->customer();
        $claim = $this->makeUnusable($kind, $user);

        $html = $this->actingAs($user, 'customer')->get('/customer/vouchers')->assertOk()->getContent();

        $this->assertStringContainsString($claim->voucher->code, $html);
        $this->assertStringNotContainsString(
            "copyVoucher('{$claim->voucher->code}'",
            $html,
            'a voucher the server will refuse still had a working Copy button'
        );
        // The real reason is on the card, not just at the cart.
        $this->assertStringContainsString(e($claim->blockingReason()), $html);
    }

    public function test_the_shared_valid_from_column_no_longer_decides_the_badge(): void
    {
        $user = $this->customer();

        // Exactly the reported shape: the SHARED column is long past, so the old
        // page logic said "Valid now", while THIS customer's own window opens
        // tomorrow and the server refuses.
        $voucher = $this->wheelVoucher(['valid_from' => today()->subDays(10)->toDateString()]);
        $claim   = $this->claim($voucher, $user, ['valid_from' => today()->addDay()->toDateString()]);

        $this->assertSame('not_yet_valid', $claim->statusKey());
        $this->assertStringContainsString('not yet valid', strtolower($claim->blockingReason()));
    }

    // ══════════ the usable case still works ══════════

    public function test_a_genuinely_usable_claim_is_offered_and_accepted(): void
    {
        $user    = $this->customer();
        $voucher = $this->wheelVoucher();
        $claim   = $this->claim($voucher, $user);

        $this->assertSame('ready', $claim->statusKey());
        $this->assertNull($claim->blockingReason());

        $cart = $this->applyOnCart($user, $voucher);

        $this->assertTrue($cart['success'], 'a valid claim was refused at the cart');
        $this->assertEqualsWithDelta(10.0, (float) $cart['discount'], 0.001);

        $html = $this->actingAs($user, 'customer')->get('/customer/vouchers')->assertOk()->getContent();
        $this->assertStringContainsString("copyVoucher('{$voucher->code}'", $html);
    }

    public function test_a_used_claim_says_so_on_both_sides(): void
    {
        $user    = $this->customer();
        $voucher = $this->wheelVoucher();
        $this->claim($voucher, $user, ['is_used' => true, 'used_at' => now()]);

        $cart = $this->applyOnCart($user, $voucher);

        $this->assertFalse($cart['success']);
        $this->assertSame('You have already used this voucher.', $cart['message']);

        $claim = UserVoucher::where('voucher_id', $voucher->id)->firstOrFail();
        $this->assertSame('used', $claim->statusKey());
    }

    // ══════════ item 30 must not be weakened ══════════

    public function test_a_wheel_voucher_without_a_claim_is_still_refused(): void
    {
        $user    = $this->customer();
        $voucher = $this->wheelVoucher();

        // No user_vouchers row at all: knowing the code must remain worthless.
        $this->assertTrue(VoucherClaims::heldOn($voucher, $user)->isEmpty());

        $cart = $this->applyOnCart($user, $voucher);

        $this->assertFalse($cart['success']);
        $this->assertSame('This voucher can only be used if you won it from the game.', $cart['message']);
    }

    public function test_one_customers_claim_does_not_let_another_customer_redeem(): void
    {
        $owner  = $this->customer();
        $other  = User::where('role', 'customer')->where('id', '!=', $owner->id)->first();

        if (!$other) {
            $this->markTestSkipped('only one customer account exists in this database');
        }

        $voucher = $this->wheelVoucher();
        $this->claim($voucher, $owner);

        $this->assertNull(VoucherClaims::unusedOn($voucher, $other));

        $cart = $this->applyOnCart($other, $voucher);

        $this->assertFalse($cart['success']);
        $this->assertSame('This voucher can only be used if you won it from the game.', $cart['message']);
    }

    public function test_a_guest_still_cannot_resolve_any_claim(): void
    {
        $user    = $this->customer();
        $voucher = $this->wheelVoucher();
        $this->claim($voucher, $user);

        // holderScope() with no holder must match NOTHING, not everything —
        // that is the invariant Round 3B's ownerless claims will extend.
        $this->assertTrue(VoucherClaims::heldOn($voucher, null)->isEmpty());
        $this->assertNull(VoucherClaims::unusedOn($voucher, null));

        // The refusal itself is the invariant and is unchanged. Only the
        // wording moved on in Round 3B: a guest who genuinely won now has a
        // claim code, so the message points them at it instead of implying
        // signing in is their only option.
        $refusal = $voucher->availabilityErrorFor(null);

        $this->assertNotNull($refusal, 'a guest with no claim must still be refused');
        $this->assertStringContainsString('sign in', $refusal);
        $this->assertStringContainsString('claim code', $refusal);
    }

    // ══════════ a public promo code is a different thing entirely ══════════

    public function test_a_public_promo_code_needs_no_claim_and_keeps_its_shared_window(): void
    {
        $user = $this->customer();

        $open = $this->wheelVoucher([
            'points_required' => 0,
            'valid_from'      => today()->subDay()->toDateString(),
        ]);

        $this->assertNull($open->availabilityErrorFor($user));
        $this->assertTrue($this->applyOnCart($user, $open)['success']);

        $future = $this->wheelVoucher([
            'points_required' => 0,
            'valid_from'      => today()->addDay()->toDateString(),
        ]);

        $this->assertStringContainsString('not yet valid', strtolower($future->availabilityErrorFor($user)));
    }

    // ══════════ the money must not move ══════════

    public function test_the_minimum_order_rule_is_unchanged_by_the_split(): void
    {
        $user    = $this->customer();
        $voucher = $this->wheelVoucher(['minimum_order' => 300]);
        $this->claim($voucher, $user);

        // availabilityErrorFor() deliberately does NOT know about the cart, so
        // the minimum-order rule must still be applied by redemptionErrorFor()
        // and only there — checked at both sides of the threshold.
        $this->assertNull($voucher->availabilityErrorFor($user));

        $this->assertSame(
            'Minimum order of ₱300.00 required.',
            $voucher->redemptionErrorFor($user, 299.99)
        );
        $this->assertNull($voucher->redemptionErrorFor($user, 300.00));
    }
}

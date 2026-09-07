<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use App\Services\VoucherClaims;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * One win, one redemption, by anyone.
 *
 * THE PROBLEM THIS FIXES
 * ----------------------
 * Dine-In customers deliberately never create an account — a decision already
 * defended to the panel. But a wheel prize won by a signed-in customer was
 * bound to that account twice over:
 *
 *   1. the claim row was created with NO claim_code at all, so there was
 *      nothing transferable in existence;
 *   2. Voucher::availabilityErrorFor() refused an owned claim presented by
 *      anyone else with "This voucher belongs to another account."
 *
 * So there was never a working path for a Dine-In customer to use a voucher,
 * even though the system was described as supporting it.
 *
 * WHAT CHANGED
 * ------------
 *   - A signed-in win now mints a claim code as well, so the prize exists as
 *     something that can be handed over.
 *   - A claim presented BY ITS CODE is a bearer instrument: whoever holds the
 *     code may spend it, once.
 *
 * WHAT DID NOT CHANGE, AND IS ASSERTED HERE
 * -----------------------------------------
 * The item-30 money bypass stays closed. A SHARED PUBLIC voucher code is a
 * different thing from a CLAIM code, and typing the shared code still fails
 * for anyone who does not genuinely hold a claim. That distinction is the
 * whole reason this change is safe, so it is pinned in
 * test_the_shared_public_code_is_still_refused_for_a_guest() rather than left
 * implicit.
 *
 * FALSE-POSITIVE DISCIPLINE
 * -------------------------
 * Every refusal below is paired with the successful guest redemption as its
 * positive control — the same claim, the same code, proven to work once before
 * being proven not to work again. A refusal test alone would pass just as
 * happily if claim codes never worked for anyone.
 */
class VoucherBearerRedemptionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // apply-voucher is throttled per session AND per IP. Without this a
        // later test in the class can 429 and look like a refusal that was
        // actually a rate limit — the exact flake Pass 6 and 7 chased down.
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function customer(int $skip = 0): ?User
    {
        return User::where('role', 'customer')->where('is_active', true)
            ->orderBy('id')->skip($skip)->first();
    }

    /**
     * A customer who can actually WIN, as opposed to one who merely exists.
     *
     * customer() above is fine for the claim-ownership tests: they only need
     * somebody to hang a claim on. It is NOT fine for the spin test, because
     * addPoints() refuses a new prize to anyone already holding two unused
     * vouchers, and the live first customer (id 11) reached that cap during
     * ordinary use — so the spin correctly won nothing and the test read as a
     * minting failure. See GuestVoucherClaimTest::winnableCustomer() for the
     * full diagnosis. Rolled back with the rest of the transaction.
     */
    private function winnableCustomer(): User
    {
        return User::create([
            'name'      => 'VBR Spin Customer',
            'email'     => 'vbr-spin-' . uniqid() . '@invalid.local',
            'password'  => 'VbrSpin!Pass1',
            'role'      => 'customer',
            'is_active' => true,
            'points'    => 0,
        ]);
    }

    /** Make $keep the only voucher the wheel can award — see the sibling test. */
    private function onlyWinnableVoucherIs(Voucher $keep): void
    {
        Voucher::where('is_active', true)
            ->where('points_required', '>', 0)
            ->where('id', '!=', $keep->id)
            ->update(['is_active' => false]);
    }

    /** A wheel voucher — points_required > 0 is what makes it claim-gated. */
    private function wheelVoucher(array $attrs = []): Voucher
    {
        return Voucher::create(array_merge([
            'code'            => 'BEARER' . strtoupper(substr(uniqid(), -6)),
            'description'     => 'Bearer redemption test voucher',
            'discount_type'   => 'percent',
            'discount_value'  => 10,
            'max_uses'        => 100,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'points_required' => 5,
            'is_active'       => true,
            'valid_from'      => today()->subDay()->toDateString(),
            'expires_at'      => today()->addYear()->toDateString(),
        ], $attrs));
    }

    /**
     * A claim OWNED by a signed-in winner, usable today, carrying a code —
     * exactly what AuthController::addPoints() now mints for an account win.
     */
    private function ownedClaim(Voucher $voucher, User $owner): UserVoucher
    {
        return UserVoucher::create([
            'user_id'       => $owner->id,
            'voucher_id'    => $voucher->id,
            'claim_code'    => VoucherClaims::mintCode(),
            'acquired_date' => today()->toDateString(),
            'valid_from'    => today()->toDateString(),
            'is_used'       => false,
        ]);
    }

    private function item(): MenuItem
    {
        return MenuItem::where('is_available', true)
            ->where(fn ($q) => $q->where('branch_id', 1)->orWhereNull('branch_id'))
            ->firstOrFail();
    }

    private function cartFor(MenuItem $item, int $qty = 2): array
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

    /** Apply a code at the cart, as a GUEST with a brand-new session. */
    private function applyAsGuest(string $code, float $subtotal = 500.0): array
    {
        return $this->withSession(['order_type' => 'dine_in', 'branch_id' => 1])
            ->postJson('/customer/apply-voucher', ['code' => $code, 'subtotal' => $subtotal])
            ->json();
    }

    /** Apply a code as a signed-in customer. */
    private function applyAsCustomer(User $user, string $code, float $subtotal = 500.0): array
    {
        return $this->actingAs($user, 'customer')
            ->postJson('/customer/apply-voucher', ['code' => $code, 'subtotal' => $subtotal])
            ->json();
    }

    // ══════════════════════════════════════════════════════════════════
    // THE POSITIVE CONTROL — a guest redeems a code won by an account
    // ══════════════════════════════════════════════════════════════════

    /**
     * The Dine-In story, end to end: customer A wins, hands the code to a
     * friend at the table who has no account and no session history, and the
     * friend uses it on a real order.
     */
    public function test_a_guest_can_redeem_a_claim_won_by_a_signed_in_customer(): void
    {
        $owner = $this->customer();
        $this->assertNotNull($owner, 'need at least one customer account');

        $voucher = $this->wheelVoucher();
        $claim   = $this->ownedClaim($voucher, $owner);
        $code    = VoucherClaims::display($claim->claim_code);

        // The cart preview accepts it.
        $preview = $this->applyAsGuest($code);
        $this->assertTrue(
            $preview['success'] ?? false,
            'a guest could not apply a code won by an account: ' . ($preview['message'] ?? 'no message')
        );

        // And it goes all the way through a real order.
        $item = $this->item();
        $before = (int) Order::max('id');

        $this->withSession([
            'cart'         => $this->cartFor($item),
            'branch_id'    => 1,
            'order_type'   => 'dine_in',
            'table_number' => '5',
        ])->post('/customer/place-order', [
            'order_type'             => 'dine_in',
            'table_number'           => '5',
            'payment_method'         => 'cash',
            'items'                  => [['menu_item_id' => $item->id, 'quantity' => 2]],
            'voucher_code_confirmed' => $code,
        ]);

        $order = Order::where('id', '>', $before)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'the guest order was not created: '
            . json_encode(session('errors')?->all() ?? []));
        $this->assertSame(
            (int) $voucher->id,
            (int) $order->voucher_id,
            'the order did not record the voucher, so it was not actually redeemed'
        );
        $this->assertGreaterThan(0, (float) $order->discount_amount, 'no discount was applied');

        // The claim is consumed.
        $this->assertTrue((bool) $claim->fresh()->is_used, 'the claim was not marked used');
    }

    // ══════════════════════════════════════════════════════════════════
    // THE FOUR REFUSALS — each after a real, successful redemption
    // ══════════════════════════════════════════════════════════════════

    /**
     * Spend the claim for real, then hand the spent code to each party in turn.
     *
     * All four run against the SAME successfully-redeemed claim, so the
     * positive control is not a separate fixture that might diverge — it is
     * literally the same row, proven to have worked moments earlier.
     *
     * @dataProvider secondAttempts
     */
    public function test_a_spent_claim_is_refused_on_a_second_attempt(string $who): void
    {
        $owner = $this->customer();
        $other = $this->customer(1);

        if ($who === 'a different logged-in customer' && (!$other || $other->id === $owner->id)) {
            $this->markTestSkipped('needs two distinct customer accounts');
        }

        $voucher = $this->wheelVoucher();
        $claim   = $this->ownedClaim($voucher, $owner);
        $code    = VoucherClaims::display($claim->claim_code);

        // ── POSITIVE CONTROL: it works once, for a guest. ──
        $first = $this->applyAsGuest($code);
        $this->assertTrue(
            $first['success'] ?? false,
            'CONTROL FAILED: the code did not work even once, so the refusal below proves nothing. '
            . ($first['message'] ?? '')
        );

        // Actually spend it, the way checkout does.
        $claim->forceFill(['is_used' => true, 'used_at' => now()])->save();

        // ── Now the second attempt, by whoever this data set names. ──
        $second = match ($who) {
            'the same guest'                => $this->applyAsGuest($code),
            'a different guest'             => $this->applyAsGuest($code),
            'the original winner'           => $this->applyAsCustomer($owner, $code),
            'a different logged-in customer'=> $this->applyAsCustomer($other, $code),
        };

        $this->assertFalse(
            $second['success'] ?? false,
            "a spent claim was accepted a second time by {$who}"
        );
        $this->assertSame(
            'You have already used this voucher.',
            $second['message'] ?? null,
            "the refusal for {$who} did not name the real reason"
        );

        $this->assertTrue((bool) $claim->fresh()->is_used, 'the claim should still be marked used');
    }

    public static function secondAttempts(): array
    {
        return [
            'same guest'        => ['the same guest'],
            'different guest'   => ['a different guest'],
            'original winner'   => ['the original winner'],
            'other customer'    => ['a different logged-in customer'],
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // What must NOT have changed
    // ══════════════════════════════════════════════════════════════════

    /**
     * The item-30 bypass stays closed. A guest typing the SHARED PUBLIC code of
     * a wheel voucher — not a claim code — is still refused, because they hold
     * no claim. This is the guarantee the redesign could plausibly have broken,
     * so it is asserted alongside the change rather than trusted.
     */
    public function test_the_shared_public_code_is_still_refused_for_a_guest(): void
    {
        $voucher = $this->wheelVoucher();
        $this->ownedClaim($voucher, $this->customer());   // a claim exists, but not theirs

        $result = $this->applyAsGuest($voucher->code);

        $this->assertFalse($result['success'] ?? false, 'a guest redeemed the shared public code');
        $this->assertStringContainsString(
            'claim code',
            (string) ($result['message'] ?? ''),
            'the guest should be told to use their claim code'
        );
    }

    /**
     * And a signed-in customer typing the shared code while holding NO claim is
     * still refused — the other half of item 30.
     */
    public function test_the_shared_public_code_is_still_refused_without_a_claim(): void
    {
        $other = $this->customer(1);
        if (!$other) {
            $this->markTestSkipped('needs a second customer account');
        }

        $voucher = $this->wheelVoucher();
        $this->ownedClaim($voucher, $this->customer());   // owned by someone ELSE

        $result = $this->applyAsCustomer($other, $voucher->code);

        $this->assertFalse($result['success'] ?? false);
        $this->assertSame(
            'This voucher can only be used if you won it from the game.',
            $result['message'] ?? null
        );
    }

    /**
     * A claim code must still only work against the voucher it was minted for.
     */
    public function test_a_claim_code_cannot_be_spent_against_a_different_voucher(): void
    {
        $owner   = $this->customer();
        $cheap   = $this->wheelVoucher(['discount_value' => 5]);
        $pricey  = $this->wheelVoucher(['discount_value' => 50]);

        $claim = $this->ownedClaim($cheap, $owner);

        // CONTROL: it works against its own voucher.
        $this->assertTrue(
            $this->applyAsGuest(VoucherClaims::display($claim->claim_code))['success'] ?? false,
            'CONTROL FAILED: the claim does not work against its own voucher'
        );

        $this->assertNotNull(
            $pricey->availabilityErrorFor(null, $claim),
            'a claim on a cheap voucher was accepted against an expensive one'
        );
    }

    /**
     * A signed-in win must actually mint a code now — this is the half of the
     * fix that makes the prize transferable at all.
     */
    public function test_a_signed_in_win_now_mints_a_transferable_claim_code(): void
    {
        $voucher  = $this->wheelVoucher(['points_required' => 5]);
        $customer = $this->winnableCustomer();
        $this->onlyWinnableVoucherIs($voucher);

        // A spin is gated on having placed an order in the window — see
        // AuthController::spinWindowOrder(). Without this the endpoint answers
        // 422 and the test would look like a minting failure when it is really
        // an unmet precondition. Mirrors GuestVoucherClaimTest's setup.
        Order::create([
            'order_number'   => 'VBR-' . substr(uniqid(), -8),
            'user_id'        => $customer->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 500,
            'total'          => 500,
        ]);

        $body = $this->actingAs($customer, 'customer')
            ->postJson('/customer/add-points', ['points' => 8])
            ->assertOk()
            ->json();

        $this->assertNotNull($body['voucher'] ?? null, 'the signed-in customer did not win anything');
        $this->assertNotNull(
            $body['voucher']['claim_code'] ?? null,
            'a signed-in win still mints no claim code — the prize cannot be handed to a Dine-In customer'
        );

        $claim = UserVoucher::where('user_id', $customer->id)
            ->whereNotNull('claim_code')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($claim, 'no claim row with a code was created');
        $this->assertSame(
            VoucherClaims::display($claim->claim_code),
            $body['voucher']['claim_code'],
            'the code shown to the winner is not the one stored on their claim'
        );
    }
}

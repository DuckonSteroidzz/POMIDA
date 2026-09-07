<?php

namespace Tests\Feature;

use App\Models\GamePlayed;
use App\Models\Order;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use App\Services\VoucherClaims;
use App\Support\GuestOrders;
use App\Support\GuestVoucherClaims;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * A guest keeping — and later using — a voucher they won on the wheel.
 *
 * WHAT WAS ACTUALLY WRONG
 * -----------------------
 * Nothing: the capability did not exist. At the defense the team told the panel
 * a guest could save the code and type it in later. In fact item 36 gave guest
 * play session-only points and minted no voucher at all, and item 30 required
 * any wheel voucher to be backed by an unused user_vouchers claim — a table
 * that was keyed to a real account. So a guest could not win, could not hold a
 * claim, and could never redeem one. It was a gap, not a bug.
 *
 * THE CONSTRAINT THAT MUST NOT BREAK
 * ----------------------------------
 * Item 30 closed a real money bypass: knowing a wheel voucher's SHARED public
 * code was enough to redeem it without ever playing. That stays closed. A guest
 * who genuinely wins gets a UNIQUE SINGLE-USE claim code instead — possession
 * of which is itself the proof of the win, and which is worth exactly one
 * prize. The tests below assert both halves: the claim code works, and the
 * shared code still does not.
 */
class GuestVoucherClaimTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('apply-voucher');
    }

    // ══════════ fixtures ══════════

    private function wheelVoucher(array $attrs = []): Voucher
    {
        return Voucher::create(array_merge([
            'code'            => 'GVC' . strtoupper(substr(uniqid(), -7)),
            'description'     => 'Guest claim test voucher',
            'discount_type'   => 'fixed',
            'discount_value'  => 25,
            'max_uses'        => 0,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'expires_at'      => now()->addMonth(),
            'valid_from'      => null,
            'is_active'       => true,
            'points_required' => 5,
        ], $attrs));
    }

    /**
     * A customer who is genuinely able to win, created for this test alone.
     *
     * WHY NOT the first live customer, which is what these tests used to pick
     * ------------------------------------------------------------------------
     * `User::where('role','customer')->orderBy('id')->first()` returns whoever
     * happens to be first in the owner's real pomida_db. addPoints() refuses to
     * award a new prize to anyone already holding two UNUSED vouchers:
     *
     *     if ($existingVoucherCount < 2) { ...award... }
     *
     * That cap is deliberate and correct. But the live first customer (id 11,
     * "pedro") accumulated exactly two unused vouchers during ordinary use, so
     * from that moment every spin in these tests correctly won nothing and the
     * tests read as "the win path is broken" when it was working perfectly.
     * Proven by running the identical flow twice: pedro won nothing, a
     * customer holding nothing won and got a claim code.
     *
     * The fixture now owns its precondition instead of borrowing it. Nothing
     * about what these tests PROVE changed — only who they prove it with. The
     * class runs in DatabaseTransactions, so this user never reaches disk.
     */
    private function winnableCustomer(): User
    {
        return User::create([
            'name'      => 'GVC Spin Customer',
            'email'     => 'gvc-spin-' . uniqid() . '@invalid.local',
            'password'  => 'GvcSpin!Pass1',
            'role'      => 'customer',
            'is_active' => true,
            'points'    => 0,
        ]);
    }

    /**
     * Make the fixture voucher the ONLY thing the wheel can award.
     *
     * winnableVoucherFor() picks inRandomOrder() from every eligible voucher,
     * so a live voucher cheap enough to win would make "which prize came back"
     * a coin toss. Today none of the owner's vouchers are (they need 25 and 35
     * points against the 8 these tests spend), so this changes nothing right
     * now — it stops the tests becoming flaky the day someone adds one.
     * Deactivation is undone by the transaction rollback.
     */
    private function onlyWinnableVoucherIs(Voucher $keep): void
    {
        Voucher::where('is_active', true)
            ->where('points_required', '>', 0)
            ->where('id', '!=', $keep->id)
            ->update(['is_active' => false]);
    }

    /** A guest order, which is what unlocks a spin window (item 36). */
    private function guestOrder(): Order
    {
        $order = Order::create([
            'order_number'   => 'GV-' . substr(uniqid(), -8),
            'user_id'        => null,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 500,
            'total'          => 500,
        ]);

        GuestOrders::remember($order->id);

        return $order;
    }

    private function spin(int $points = 8)
    {
        return $this->postJson('/customer/add-points', ['points' => $points]);
    }

    /** Apply a code on the cart, as the cart page's Apply button does. */
    private function apply(string $code, float $subtotal = 500.0): array
    {
        return $this->postJson('/customer/apply-voucher', [
            'code'     => $code,
            'subtotal' => $subtotal,
        ])->json();
    }

    // ══════════ a guest can now win ══════════

    public function test_a_guest_win_mints_a_claim_with_its_own_code(): void
    {
        $voucher = $this->wheelVoucher(['points_required' => 5]);
        $this->guestOrder();

        $response = $this->spin(8)->assertOk();
        $body = $response->json();

        $this->assertTrue($body['success']);
        $this->assertNotNull($body['voucher'], 'a guest still won nothing');
        $this->assertNotNull($body['voucher']['claim_code'], 'the guest prize carries no claim code');

        $claim = VoucherClaims::resolveByCode($body['voucher']['claim_code']);

        $this->assertNotNull($claim, 'the code shown to the guest resolves to no claim');
        $this->assertNull($claim->user_id, 'a guest claim must have no owner');
        $this->assertFalse((bool) $claim->is_used);
        $this->assertSame(
            now()->addDay()->toDateString(),
            $claim->valid_from->toDateString(),
            'a guest claim must use the same next-day window the account path uses'
        );
    }

    public function test_the_win_response_tells_the_guest_to_save_the_code(): void
    {
        $this->wheelVoucher(['points_required' => 5]);
        $this->guestOrder();

        $body = $this->spin(8)->assertOk()->json();

        $this->assertNotNull($body['voucher']);
        $this->assertStringContainsString('Save your claim code', $body['guest_notice']);
    }

    public function test_a_guest_who_wins_nothing_gets_no_claim(): void
    {
        $this->wheelVoucher(['points_required' => 500]);
        $this->guestOrder();

        $before = UserVoucher::count();
        $body = $this->spin(3)->assertOk()->json();

        $this->assertNull($body['voucher']);
        $this->assertSame($before, UserVoucher::count(), 'a losing spin minted a claim');
    }

    public function test_claim_codes_are_unique_per_win(): void
    {
        $this->wheelVoucher(['points_required' => 5]);
        $this->wheelVoucher(['points_required' => 5]);
        $this->guestOrder();

        $codes = [];

        for ($i = 0; $i < 2; $i++) {
            $body = $this->spin(8)->assertOk()->json();

            if ($body['voucher']) {
                $codes[] = $body['voucher']['claim_code'];
            }
        }

        $this->assertCount(count(array_unique($codes)), $codes, 'two wins shared a claim code');
    }

    // ══════════ item 30 stays closed ══════════

    public function test_a_guest_still_cannot_redeem_the_shared_public_code(): void
    {
        $voucher = $this->wheelVoucher();
        $this->guestOrder();
        $this->spin(8);

        // Even holding a genuine claim, the SHARED code is not the proof.
        // This is exactly the bypass item 30 closed.
        $result = $this->apply($voucher->code);

        $this->assertFalse($result['success'], 'the shared wheel code was accepted for a guest');
        $this->assertStringContainsString('sign in', $result['message']);
    }

    public function test_an_invented_claim_code_is_refused(): void
    {
        $this->wheelVoucher();

        $result = $this->apply('PCH-AAAAA-BBBBB');

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid voucher code.', $result['message']);
    }

    public function test_a_claim_cannot_be_spent_against_a_different_voucher(): void
    {
        $cheap     = $this->wheelVoucher(['discount_value' => 5]);
        $expensive = $this->wheelVoucher(['discount_value' => 500]);

        $claim = VoucherClaims::mintForGuest($cheap, today()->toDateString());

        // Naming the expensive voucher while holding a claim on the cheap one
        // must not pass any of the checks below it.
        $this->assertNotNull($expensive->availabilityErrorFor(null, $claim));
        $this->assertNull($cheap->availabilityErrorFor(null, $claim));
    }

    /**
     * REVERSED DELIBERATELY, 2026-09-01.
     *
     * This used to assert that an owned claim was NOT a bearer instrument:
     * "This voucher belongs to another account." That was correct at the time
     * and is now the opposite of the intended behaviour.
     *
     * The owner's decision: one win mints one claim, and that claim's code is
     * redeemable exactly once by WHOEVER presents it. Without that there was no
     * working path at all for a Dine-In customer — who by design never creates
     * an account — to use a prize a friend won, even though the system was
     * described to the panel as supporting exactly that.
     *
     * The test is rewritten rather than deleted: what it protects now is that
     * the transfer works AND that it is still one-shot and still voucher-bound.
     * See VoucherBearerRedemptionTest for the full matrix, including the four
     * second-attempt refusals.
     *
     * NOT changed, and still asserted elsewhere: typing the SHARED PUBLIC code
     * without holding a claim is still refused (item 30). That is a different
     * code and a different guarantee — see
     * VoucherClaimStateTest::test_one_customers_claim_does_not_let_another_customer_redeem,
     * which still passes untouched.
     */
    public function test_an_owned_claim_is_a_bearer_instrument_redeemable_once(): void
    {
        $voucher = $this->wheelVoucher();
        $owner   = User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();
        $other   = User::where('role', 'customer')->where('id', '!=', $owner->id)->first();

        if (!$other) {
            $this->markTestSkipped('only one customer account exists in this database');
        }

        $claim = UserVoucher::create([
            'user_id'       => $owner->id,
            'voucher_id'    => $voucher->id,
            'claim_code'    => 'PCHOWNEDCLAIM',
            'acquired_date' => today()->toDateString(),
            'valid_from'    => today()->toDateString(),
            'is_used'       => false,
        ]);

        // Whoever holds the code may spend it — the owner, another account, or
        // a guest with no account at all.
        $this->assertNull($voucher->availabilityErrorFor($owner, $claim), 'the owner should still be able to use it');
        $this->assertNull($voucher->availabilityErrorFor($other, $claim), 'another account should be able to use it');
        $this->assertNull($voucher->availabilityErrorFor(null, $claim), 'a guest should be able to use it');

        // But exactly once.
        $claim->forceFill(['is_used' => true, 'used_at' => now()])->save();

        foreach ([$owner, $other, null] as $who) {
            $this->assertSame(
                'You have already used this voucher.',
                $voucher->availabilityErrorFor($who, $claim->fresh()),
                'a spent claim was accepted again'
            );
        }
    }

    /**
     * Transferability did NOT make a claim a skeleton key. It still names one
     * voucher, and presenting it against a different one is refused — otherwise
     * a claim on a cheap prize could be spent against an expensive voucher.
     */
    public function test_a_claim_is_still_bound_to_its_own_voucher(): void
    {
        $owner  = User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();
        $cheap  = $this->wheelVoucher();
        $pricey = $this->wheelVoucher();

        $claim = UserVoucher::create([
            'user_id'       => $owner->id,
            'voucher_id'    => $cheap->id,
            'claim_code'    => 'PCHOWNEDCLAIM',
            'acquired_date' => today()->toDateString(),
            'valid_from'    => today()->toDateString(),
            'is_used'       => false,
        ]);

        // CONTROL: it works against the voucher it was minted for.
        $this->assertNull(
            $cheap->availabilityErrorFor(null, $claim),
            'CONTROL FAILED: the claim does not work against its own voucher, so the refusal below proves nothing'
        );

        $this->assertSame(
            'This voucher can only be used if you won it from the game.',
            $pricey->availabilityErrorFor(null, $claim),
            'a claim was accepted against a voucher it was not minted for'
        );
    }

    // ══════════ a later visit, in a brand-new session ══════════

    /** Mint a guest claim that is usable today, as if a day had passed. */
    private function usableGuestClaim(?Voucher $voucher = null): UserVoucher
    {
        return VoucherClaims::mintForGuest(
            $voucher ?? $this->wheelVoucher(),
            today()->toDateString()
        );
    }

    public function test_a_fresh_session_can_apply_the_claim_code(): void
    {
        $claim = $this->usableGuestClaim();

        // Nothing of the winning session survives: no cookies, no claim list.
        $this->flushSession();

        $result = $this->apply(VoucherClaims::display($claim->claim_code));

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertEqualsWithDelta(25.0, (float) $result['discount'], 0.001);
    }

    public static function codeSpellings(): array
    {
        return [
            'as shown'      => ['display'],
            'lower case'    => ['lower'],
            'no separators' => ['raw'],
            'with spaces'   => ['spaced'],
        ];
    }

    /**
     * @dataProvider codeSpellings
     */
    public function test_the_code_is_matched_however_it_is_typed_back_in(string $how): void
    {
        $claim = $this->usableGuestClaim();
        $shown = VoucherClaims::display($claim->claim_code);

        $typed = match ($how) {
            'display' => $shown,
            'lower'   => strtolower($shown),
            'raw'     => str_replace('-', '', $shown),
            'spaced'  => str_replace('-', ' ', $shown),
        };

        $this->flushSession();

        $this->assertTrue(
            $this->apply($typed)['success'],
            "a code typed as '{$typed}' was not recognised"
        );
    }

    public function test_the_claim_is_refused_before_its_window_opens(): void
    {
        // mintForGuest() defaults to tomorrow, exactly as a real win does.
        $claim = VoucherClaims::mintForGuest($this->wheelVoucher());

        $this->flushSession();

        $result = $this->apply(VoucherClaims::display($claim->claim_code));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not yet valid', $result['message']);
    }

    public function test_a_used_claim_is_refused(): void
    {
        $claim = $this->usableGuestClaim();
        $claim->forceFill(['is_used' => true, 'used_at' => now()])->save();

        $this->flushSession();

        $result = $this->apply(VoucherClaims::display($claim->claim_code));

        $this->assertFalse($result['success']);
        $this->assertSame('You have already used this voucher.', $result['message']);
    }

    // ══════════ the money: a real order, once and only once ══════════

    private const ITEM_ID = 24;

    /** Put one of ITEM_ID in the session cart and check out with $code. */
    private function checkoutWith(?string $code)
    {
        $this->post('/customer/select-branch', ['branch_id' => 1]);
        $this->post('/customer/cart/add', ['item_id' => self::ITEM_ID, 'quantity' => 4]);

        return $this->from('/customer/cart')->post('/customer/place-order', array_filter([
            'order_type'             => 'pick_up',
            'payment_method'         => 'cash',
            'voucher_code_confirmed' => $code,
            'items'                  => [['menu_item_id' => self::ITEM_ID, 'quantity' => 4]],
        ]));
    }

    public function test_a_guest_redeems_the_claim_on_a_real_order(): void
    {
        $voucher = $this->wheelVoucher(['discount_type' => 'fixed', 'discount_value' => 25]);
        $claim   = $this->usableGuestClaim($voucher);
        $shown   = VoucherClaims::display($claim->claim_code);
        $usedCountBefore = $voucher->fresh()->used_count;

        $this->flushSession();

        $before = Order::max('id');
        $this->checkoutWith($shown)->assertSessionHasNoErrors();

        $order = Order::where('id', '>', $before ?? 0)->orderByDesc('id')->first();

        $this->assertNotNull($order, 'the order never reached the database');
        $this->assertSame((int) $voucher->id, (int) $order->voucher_id);
        $this->assertEqualsWithDelta(25.0, (float) $order->discount_amount, 0.001);
        $this->assertEqualsWithDelta(
            round((float) $order->subtotal - 25.0, 2),
            (float) $order->total,
            0.001
        );

        // The claim is spent...
        $this->assertTrue((bool) $claim->fresh()->is_used);
        $this->assertNotNull($claim->fresh()->used_at);

        /*
         * ...and the voucher's own counter moved. This used to be
         * Voucher::where('code', $typed)->increment(), which matches nothing
         * when the customer typed a CLAIM code — so a guest redemption would
         * silently not have counted against max_uses at all.
         */
        $this->assertSame($usedCountBefore + 1, (int) $voucher->fresh()->used_count);
    }

    public function test_the_same_claim_code_cannot_be_redeemed_twice(): void
    {
        $voucher = $this->wheelVoucher();
        $claim   = $this->usableGuestClaim($voucher);
        $shown   = VoucherClaims::display($claim->claim_code);

        $this->flushSession();
        $this->checkoutWith($shown)->assertSessionHasNoErrors();

        // A completely different visitor with the same code.
        $this->flushSession();
        RateLimiter::clear('apply-voucher');

        $this->assertFalse($this->apply($shown)['success']);

        $before = Order::max('id');
        $this->checkoutWith($shown)->assertSessionHasErrors('voucher_code_confirmed');

        $this->assertSame(
            $before,
            Order::max('id'),
            'a second order was created on an already-spent claim'
        );
    }

    public function test_a_race_on_one_claim_spends_it_only_once(): void
    {
        $voucher = $this->wheelVoucher();
        $claim   = $this->usableGuestClaim($voucher);

        /*
         * The item-30 concurrency guard, unchanged and now exercised on an
         * OWNERLESS claim: the used-marking is a conditional UPDATE, so the
         * second writer matches no row rather than double-spending.
         */
        $first = UserVoucher::where('id', $claim->id)->where('is_used', false)
            ->update(['is_used' => true, 'used_at' => now()]);
        $second = UserVoucher::where('id', $claim->id)->where('is_used', false)
            ->update(['is_used' => true, 'used_at' => now()]);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'a second writer was able to spend the same claim');
    }

    // ══════════ the guest -> account hand-over ══════════

    public function test_a_guest_prize_follows_the_customer_into_a_new_account(): void
    {
        $voucher = $this->wheelVoucher();
        $this->guestOrder();
        $this->spin(8);

        $claim = UserVoucher::where('voucher_id', $voucher->id)->whereNull('user_id')->firstOrFail();

        $email = 'adopt' . uniqid() . '@peachy.local';

        $this->post('/customer/register', [
            'name'                  => 'Adoption Test',
            'email'                 => $email,
            'password'              => 'LiveTest!2026',
            'password_confirmation' => 'LiveTest!2026',
            'contact_number'        => '09171234567',
            'terms'                 => 'on',
        ])->assertRedirect(route('customer.menu'));

        $user = User::where('email', $email)->firstOrFail();

        $this->assertSame(
            (int) $user->id,
            (int) $claim->fresh()->user_id,
            'the prize was left behind when the guest registered'
        );

        // The code must keep working: the customer may well have written it
        // down before signing up.
        $this->assertNotNull($claim->fresh()->claim_code);
    }

    public function test_adoption_never_breaks_the_one_wheel_voucher_per_account_rule(): void
    {
        $voucher  = $this->wheelVoucher();
        $customer = User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();

        // The account already holds a claim on this voucher.
        UserVoucher::create([
            'user_id'       => $customer->id,
            'voucher_id'    => $voucher->id,
            'acquired_date' => today()->toDateString(),
            'valid_from'    => today()->toDateString(),
            'is_used'       => false,
        ]);

        $guestClaim = VoucherClaims::mintForGuest($voucher, today()->toDateString());
        GuestVoucherClaims::remember($guestClaim);

        $adopted = GuestVoucherClaims::adoptInto($customer->id);

        // Adopting would have collided with UNIQUE(user_id, voucher_id), so it
        // is skipped — and the prize is NOT lost: its code still redeems it.
        $this->assertSame(0, $adopted);
        $this->assertNull($guestClaim->fresh()->user_id);

        $this->flushSession();
        $this->assertTrue($this->apply(VoucherClaims::display($guestClaim->claim_code))['success']);
    }

    public function test_an_already_spent_guest_claim_is_not_adopted(): void
    {
        $customer = User::where('role', 'customer')->where('is_active', true)->orderBy('id')->firstOrFail();

        $claim = VoucherClaims::mintForGuest($this->wheelVoucher(), today()->toDateString());
        $claim->forceFill(['is_used' => true, 'used_at' => now()])->save();
        GuestVoucherClaims::remember($claim);

        $this->assertSame(0, GuestVoucherClaims::adoptInto($customer->id));
        $this->assertNull($claim->fresh()->user_id, 'a spent prize was revived onto an account');
    }

    // ══════════ nothing that already worked has moved ══════════

    /**
     * REVERSED DELIBERATELY, 2026-09-01 — this used to assert that a
     * signed-in win must NOT mint a bearer code, because the claim lived on
     * the account and there was nothing to hand over.
     *
     * That was precisely what made the Dine-In story impossible: no
     * transferable artefact existed at all, so a prize could never reach a
     * customer without an account. A signed-in win now mints a claim code too.
     * The claim is STILL recorded against the winner's account — that is what
     * puts it on their Vouchers page and enforces the one-wheel-voucher-per-
     * account cap — so only the code is new.
     */
    public function test_a_signed_in_winner_gets_an_account_claim_WITH_a_transferable_code(): void
    {
        $voucher  = $this->wheelVoucher(['points_required' => 5]);
        $customer = $this->winnableCustomer();
        $this->onlyWinnableVoucherIs($voucher);

        $order = Order::create([
            'order_number'   => 'GVA-' . substr(uniqid(), -8),
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

        $this->assertNotNull($body['voucher'], 'a signed-in customer stopped winning');
        $this->assertNotNull(
            $body['voucher']['claim_code'],
            'a signed-in win must now mint a claim code, so the prize can be handed on'
        );

        $claim = UserVoucher::where('user_id', $customer->id)
            ->where('voucher_id', $voucher->id)
            ->first();

        $this->assertNotNull($claim);
        $this->assertNotNull($claim->claim_code, 'the stored claim has no code');
        $this->assertSame(
            \App\Services\VoucherClaims::display($claim->claim_code),
            $body['voucher']['claim_code'],
            'the code shown to the winner is not the one stored on their claim'
        );
        $this->assertSame(
            $customer->id,
            (int) $claim->user_id,
            'the claim must still belong to the winner, so it stays on their Vouchers page'
        );
    }

    /**
     * A customer who has already won a specific wheel voucher never wins that
     * same voucher again on a later spin — winnableVoucherFor() excludes every
     * voucher_id already on their user_vouchers rows. With only one wheel
     * voucher configured, a second qualifying spin therefore wins nothing.
     */
    public function test_a_signed_in_customer_cannot_win_the_same_voucher_twice(): void
    {
        $voucher  = $this->wheelVoucher(['points_required' => 5, 'max_uses' => 0]);
        $customer = $this->winnableCustomer();
        $this->onlyWinnableVoucherIs($voucher);

        Order::create([
            'order_number'   => 'DUP-' . substr(uniqid(), -8),
            'user_id'        => $customer->id,
            'branch_id'      => 1,
            'type'           => 'pick_up',
            'status'         => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal'       => 500,
            'total'          => 500,
        ]);

        // First qualifying spin wins it.
        $first = $this->actingAs($customer, 'customer')
            ->postJson('/customer/add-points', ['points' => 8])->assertOk()->json();
        $this->assertNotNull($first['voucher'], 'CONTROL: the first spin should have won the voucher');

        // Spin back up past the threshold — the only wheel voucher is one they
        // now hold, so nothing new can be awarded.
        $wonAgain = false;
        for ($i = 0; $i < 6; $i++) {
            $body = $this->actingAs($customer, 'customer')
                ->postJson('/customer/add-points', ['points' => 8])->assertOk()->json();
            if (!empty($body['voucher'])) {
                $wonAgain = true;
            }
        }

        $this->assertFalse($wonAgain, 'the same voucher was awarded to the customer a second time');
        $this->assertSame(
            1,
            UserVoucher::where('user_id', $customer->id)->where('voucher_id', $voucher->id)->count(),
            'a duplicate user_vouchers row was created for the same voucher'
        );
    }

    public function test_a_public_promo_code_is_unaffected_by_any_of_this(): void
    {
        $promo = $this->wheelVoucher([
            'points_required' => 0,
            'valid_from'      => today()->subDay()->toDateString(),
        ]);

        // No claim, no account, no wheel: a public code is still just a code.
        $result = $this->apply($promo->code);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertEqualsWithDelta(25.0, (float) $result['discount'], 0.001);
    }

    public function test_the_spin_window_still_caps_a_guest(): void
    {
        $this->wheelVoucher(['points_required' => 5]);
        $order = $this->guestOrder();

        /*
         * Item 36: a fixed number of spins per order, re-derived from the
         * ledger server-side.
         *
         * The count is read from SPINS_PER_ORDER rather than hardcoded. It was
         * literally 5 until 2026-09-01, when game balance raised it to 7 and
         * this test failed for a reason that had nothing to do with what it
         * guards — the cap still worked perfectly, the number had just moved.
         * What this test is actually for is that the window HAS a cap and the
         * server enforces it, so that is what it now asserts.
         */
        $perOrder = (new \ReflectionClassConstant(
            \App\Http\Controllers\Customer\AuthController::class,
            'SPINS_PER_ORDER'
        ))->getValue();

        $this->assertGreaterThan(0, $perOrder, 'the window must grant some spins');

        for ($i = 0; $i < $perOrder; $i++) {
            $this->spin(3)->assertOk();
        }

        $this->spin(3)->assertStatus(422);
        $this->assertSame($perOrder, GamePlayed::where('order_id', $order->id)->count());
    }

    public function test_a_guest_cannot_hoard_more_prizes_than_an_account_can(): void
    {
        // Three winnable vouchers, but the ceiling is two unused at a time.
        for ($i = 0; $i < 3; $i++) {
            $this->wheelVoucher(['points_required' => 1]);
        }

        $this->guestOrder();

        for ($i = 0; $i < 5; $i++) {
            $this->spin(8);
        }

        $this->assertLessThanOrEqual(
            GuestVoucherClaims::MAX_UNUSED,
            GuestVoucherClaims::unusedCount(),
            'a guest accumulated more unused prizes than the ceiling allows'
        );
    }
}

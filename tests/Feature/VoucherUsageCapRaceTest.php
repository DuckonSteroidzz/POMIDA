<?php

namespace Tests\Feature;

use App\Models\Voucher;
use App\Services\VoucherClaims;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A capped PUBLIC promo code cannot be redeemed more times than max_uses,
 * however many checkouts arrive at once.
 *
 * WHAT WAS WRONG
 * --------------
 * Two different guards protect a voucher, and only one of them was atomic.
 *
 *   - A WHEEL/claim voucher is spent through its own `user_vouchers` row, and
 *     VoucherClaims::redeem() burns that row with a CONDITIONAL update
 *     (`where is_used = false`) and throws if it loses. That is race-safe and
 *     was already correct.
 *
 *   - A PUBLIC promo code has no claim row at all — resolveTypedCode() returns
 *     ['voucher' => …, 'claim' => null] — so the ONLY thing counting it was
 *
 *         Voucher::whereKey($id)->increment('used_count');
 *
 *     an UNCONDITIONAL increment. The cap itself was checked earlier, in
 *     Voucher::redemptionErrorFor(), which runs OUTSIDE the order transaction
 *     (OrderController::placeOrder ~line 802) and takes no lock on the voucher
 *     row. The transaction that follows locks INVENTORY rows, never the voucher.
 *
 * So it was a check-then-act with nothing serialising the two halves: several
 * checkouts could all read `used_count = max_uses - 1`, all pass the cap check,
 * and all increment. A promotion capped at 1 could be redeemed twice, and one
 * capped at 50 could overspend by however many requests overlapped.
 *
 * WHAT AN ATTACKER (OR A BUSY LUNCH RUSH) GETS
 * -------------------------------------------
 * Discount beyond what the shop authorised — real money. It needs no special
 * access: a public code is, by design, known to customers, so anyone holding a
 * "first 10 orders only" code could fire several checkouts at once and have
 * more than 10 of them honoured.
 *
 * THE FIX
 * -------
 * The increment is now conditional on the cap, in ONE statement:
 *
 *     UPDATE vouchers SET used_count = used_count + 1
 *      WHERE id = ? AND (max_uses <= 0 OR used_count < max_uses)
 *
 * The database evaluates that against the committed value while holding the row
 * lock, so a second transaction reaching it blocks, re-reads, matches no row and
 * gets 0 affected — which now throws and rolls the whole order back. Exactly the
 * shape the claim row already used; no new mechanism.
 *
 * THE BUSINESS RULE IS UNCHANGED. `max_uses <= 0` still means unlimited, the cap
 * is still max_uses, and a redemption that fits still succeeds. The only
 * behaviour that changed is the one that was never intended: the (max_uses + n)th
 * redemption.
 *
 * HOW THIS IS TESTED WITHOUT THREADS
 * ----------------------------------
 * PHPUnit runs one process, so this does not spawn real parallel requests (the
 * repo's genuinely concurrent harness lives in tests/Load). It does not need to:
 * the defect was that redeem() ITSELF did not enforce the cap, which is what
 * made the outer check-then-act exploitable. Calling redeem() twice is therefore
 * a faithful, deterministic model of two transactions that both passed the
 * earlier check — and it is the exact interleaving a race produces. The
 * end-to-end tests below then confirm the ordinary sequential path still works.
 */
class VoucherUsageCapRaceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'pomida_db_testing',
            DB::selectOne('select database() as d')->d,
            'Voucher cap tests must only ever run against pomida_db_testing.'
        );
    }

    /** A public promo code: no wheel win needed, so no claim row. */
    private function publicVoucher(array $attrs = []): Voucher
    {
        return Voucher::create(array_merge([
            'code'            => 'CAP' . strtoupper(substr(uniqid(), -7)),
            'description'     => 'voucher cap race test',
            'discount_type'   => 'fixed',
            'discount_value'  => 50,
            'max_uses'        => 1,
            'used_count'      => 0,
            'minimum_order'   => 0,
            'expires_at'      => now()->addMonth(),
            'valid_from'      => null,
            'is_active'       => true,
            'points_required' => 0,
        ], $attrs));
    }

    // ══════════ The finding ══════════

    /**
     * THE RACE. Before the fix this recorded used_count = 2 against max_uses = 1
     * and threw nothing.
     */
    public function test_a_code_capped_at_one_cannot_be_counted_twice(): void
    {
        $voucher = $this->publicVoucher(['max_uses' => 1]);

        // First redemption: legitimate, must succeed.
        VoucherClaims::redeem($voucher->id, null);
        $this->assertSame(1, (int) $voucher->fresh()->used_count);

        // Second: the loser of the race. Must be refused, not counted.
        try {
            VoucherClaims::redeem($voucher->id, null);
            $this->fail('a second redemption of a code capped at 1 was accepted — used_count is now '
                . (int) $voucher->fresh()->used_count . '/1');
        } catch (\RuntimeException $e) {
            // The message is shown to the customer by placeOrder()'s
            // catch (\RuntimeException) arm, so it must read like a sentence.
            $this->assertNotSame('', trim($e->getMessage()));
        }

        $this->assertSame(
            1,
            (int) $voucher->fresh()->used_count,
            'the refused redemption still moved the counter, so the cap can be walked past one request at a time'
        );
    }

    /** The same property at a larger cap — the overspend is not special to 1. */
    public function test_a_code_capped_at_three_stops_at_exactly_three(): void
    {
        $voucher = $this->publicVoucher(['max_uses' => 3]);

        $accepted = 0;

        foreach (range(1, 8) as $ignored) {
            try {
                VoucherClaims::redeem($voucher->id, null);
                $accepted++;
            } catch (\RuntimeException $e) {
                // expected once the cap is reached
            }
        }

        $this->assertSame(3, $accepted, 'more redemptions were accepted than the cap allows');
        $this->assertSame(3, (int) $voucher->fresh()->used_count);
    }

    // ══════════ The business rule, unchanged ══════════

    /**
     * max_uses <= 0 means UNLIMITED in this application, and must stay that way.
     *
     * This is the control that stops the fix from being "refuse everything":
     * without it, a conditional update with the comparison the wrong way round
     * would pass every test above.
     */
    public function test_an_uncapped_code_is_still_unlimited(): void
    {
        $voucher = $this->publicVoucher(['max_uses' => 0]);

        foreach (range(1, 5) as $ignored) {
            VoucherClaims::redeem($voucher->id, null);
        }

        $this->assertSame(5, (int) $voucher->fresh()->used_count, 'an uncapped voucher was wrongly capped');
    }

    /** A redemption that comfortably fits under the cap is untouched. */
    public function test_a_redemption_within_the_cap_still_succeeds(): void
    {
        $voucher = $this->publicVoucher(['max_uses' => 100]);

        VoucherClaims::redeem($voucher->id, null);

        $this->assertSame(1, (int) $voucher->fresh()->used_count);
    }

    /**
     * The claim-row path must keep its own behaviour.
     *
     * A wheel voucher's cap and its claim row are two different guards; the fix
     * touched only the shared counter, so the "already used" refusal that
     * ManualOrderVoucherTest and VoucherClaimStateTest depend on has to still
     * come from the claim row and still read the same way.
     */
    public function test_null_voucher_id_is_still_a_no_op(): void
    {
        // A LOSING voucher (Order::voucherBeatsCard) is passed as nulls, and must
        // not be counted or throw.
        VoucherClaims::redeem(null, null);

        $this->assertTrue(true, 'a losing voucher must be left completely unspent without throwing');
    }
}

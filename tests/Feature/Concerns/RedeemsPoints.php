<?php

namespace Tests\Feature\Concerns;

use App\Models\Voucher;
use Illuminate\Testing\TestResponse;

/**
 * Trade the current visitor's points for a voucher, as the Redeem button does.
 *
 * Since Nov 2026 a spin only earns points; a voucher is issued only by
 * POST /customer/points/redeem. A test that used to "spin until it won" now
 * spins for the points and then redeems. Acts as whoever the test is acting
 * as (actingAs / the guest session already in place).
 */
trait RedeemsPoints
{
    protected function redeemPoints(Voucher|int $voucher): TestResponse
    {
        return $this->postJson('/customer/points/redeem', [
            'voucher_id' => $voucher instanceof Voucher ? $voucher->id : $voucher,
        ]);
    }
}

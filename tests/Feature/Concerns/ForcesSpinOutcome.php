<?php

namespace Tests\Feature\Concerns;

use App\Http\Controllers\Customer\AuthController;
use App\Services\SpinWheel;

/**
 * Fix where the SERVER's wheel lands, for tests that need a known prize.
 *
 * Since hardening pass F3 the spin endpoint ignores any points value in the
 * request and credits whatever App\Services\SpinWheel picks. A test that used
 * to post `points => 8` to win a voucher now binds the server's picker to an
 * 8-point segment instead. The request body stays empty: it carries no
 * outcome any more.
 */
trait ForcesSpinOutcome
{
    /** Every spin in this test lands on a segment worth exactly $points. */
    protected function forceSpinOutcome(int $points): int
    {
        $index = null;

        foreach (AuthController::WHEEL_SEGMENTS as $i => $segment) {
            if ((int) $segment['points'] === $points) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            $this->fail("the wheel has no {$points}-point segment to force");
        }

        $this->app->instance(SpinWheel::class, new class($index) extends SpinWheel {
            public function __construct(private int $index)
            {
            }

            public function land(array $segments): int
            {
                return $this->index;
            }
        });

        return $index;
    }
}

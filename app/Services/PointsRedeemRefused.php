<?php

namespace App\Services;

/**
 * A redeem the rules refused. Nothing was written: no points moved and no
 * claim exists. `status` is the WheelPrizes status that refused it, and the
 * message is written to be shown to the customer.
 */
final class PointsRedeemRefused extends \DomainException
{
    public function __construct(public readonly string $status, string $message)
    {
        parent::__construct($message);
    }
}

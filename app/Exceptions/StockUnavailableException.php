<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown from inside the order-creating transaction when the locked, final
 * stock check finds the cart can no longer be supplied.
 *
 * Carries EVERY short line rather than a single message, because a customer
 * fixing a multi-item order should be told about all of it at once instead of
 * discovering the next shortage on the next attempt.
 *
 * Extends RuntimeException so that any caller which only knows about the
 * existing "deliberate, user-facing refusal" catch still handles it sensibly;
 * callers that want the full list catch this type specifically.
 */
class StockUnavailableException extends RuntimeException
{
    /** @param string[] $messages */
    public function __construct(private array $messages)
    {
        parent::__construct($messages[0] ?? 'Some items in your order are no longer available.');
    }

    /** @return string[] */
    public function messages(): array
    {
        return $this->messages;
    }
}

<?php

namespace App\Support;

use App\Services\TableEntry;

/**
 * The code on every loyalty reward (Oct 2026): RWD-XXXX-XXXX.
 *
 * Every reward the stamp card earns gets its OWN code — never one shared code
 * for everybody — and that code is what the customer types in the voucher box,
 * what "Use now" fills in for them, and what a manager checks on the admin
 * Vouchers page. A code is spendable once (the reward row is locked and marked
 * used inside the order transaction) and only by the account it belongs to.
 *
 * THE SHAPE
 * ---------
 *  - RWD, so a customer, a cashier and the code box can all tell a reward
 *    code from a voucher code (anything) and a claim code (PCH-XXXXX-XXXXX).
 *  - 8 characters from the SAME look-alike-free alphabet as table codes
 *    (TableEntry::ALPHABET — no 0/O, no 1/I/L), two groups of four like a
 *    table code's one group. 31^8 is about 850 billion codes.
 *  - Stored without separators; display() adds the dashes back, and
 *    normalise() lets "rwd abcd efgh" find "RWD-ABCD-EFGH".
 *
 * GUESSING
 * --------
 * random_int() is a cryptographically secure source. On top of the size of
 * the space, a code only works for the signed-in account it belongs to (a
 * stranger's code answers exactly like a code that does not exist), and every
 * code typed into the cart goes through the apply-voucher rate limiter.
 *
 * NEVER REUSED: free_dessert_rewards.code carries a UNIQUE index, and
 * App\Services\FreeDesserts::uniqueCode() also refuses a code that any order
 * has ever recorded in orders.reward_code.
 */
final class RewardCode
{
    public const PREFIX = 'RWD';

    public const BODY_LENGTH = 8;

    /** The table-code alphabet, so the two kinds of code read the same way. */
    public const ALPHABET = TableEntry::ALPHABET;

    /** A fresh random code in its stored form (no dashes). Uniqueness is the caller's check. */
    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $body = '';

        for ($i = 0; $i < self::BODY_LENGTH; $i++) {
            $body .= self::ALPHABET[random_int(0, $max)];
        }

        return self::PREFIX . $body;
    }

    /** Strip spaces and dashes and upper-case, for tolerant matching. */
    public static function normalise(?string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    /** Is this shaped like a reward code (prefix, length, alphabet)? Says nothing about whether it exists. */
    public static function looksLike(?string $code): bool
    {
        $normalised = self::normalise($code);
        $prefixLength = strlen(self::PREFIX);

        return strlen($normalised) === $prefixLength + self::BODY_LENGTH
            && str_starts_with($normalised, self::PREFIX)
            && strspn(substr($normalised, $prefixLength), self::ALPHABET) === self::BODY_LENGTH;
    }

    /** The customer-facing spelling: RWD-XXXX-XXXX. */
    public static function display(?string $stored): string
    {
        $code = self::normalise($stored);

        if (strlen($code) !== strlen(self::PREFIX) + self::BODY_LENGTH) {
            return $code;
        }

        return substr($code, 0, 3) . '-' . substr($code, 3, 4) . '-' . substr($code, 7, 4);
    }
}

<?php

namespace App\Support;

/**
 * The largest value any number or ID box will accept, in one place.
 *
 * QA Nov 5: an exported spreadsheet showed 1.11111E+46 — a 47-digit run of 1s.
 * Most numeric boxes had a minimum but no maximum, so the only thing standing
 * between a fat-fingered (or hostile) 47-digit value and the database was a
 * MySQL "out of range" error at INSERT time, which surfaces as a 500 instead of
 * a message on the box. Every maximum below is both the server rule AND the
 * HTML `max` / `maxlength` on the matching input, so the two cannot disagree.
 *
 * Each figure is the largest REALISTIC value for a café, deliberately well
 * inside what the column can hold (the columns are decimal(10,2) / (12,3), so
 * none of these can reach a 12-digit figure that a spreadsheet would show in
 * scientific notation). Values are strings so a `max:` rule and an HTML
 * attribute print exactly the same digits.
 */
final class InputLimits
{
    /** A menu item, size or add-on price, and a menu item's cost (₱). */
    public const PRICE_MAX = '99999.99';

    /** Cost of ONE unit of an inventory ingredient (₱). */
    public const UNIT_COST_MAX = '999999.99';

    /** Cash tendered at the counter, and the subtotal a voucher preview is judged on (₱). */
    public const AMOUNT_PAID_MAX = '999999.99';

    /** Stock on hand, the low-stock alert, and one Stock In / Stock Out amount. */
    public const STOCK_MAX = '9999999.999';

    /** How much of one ingredient ONE order uses (a recipe line). */
    public const RECIPE_QTY_MAX = '99999.999';

    /** Units of one dish on one order line (the item page already stops at 99). */
    public const ORDER_QTY_MAX = 99;

    /** A fixed-peso voucher's value, and a voucher's minimum order (₱). */
    public const VOUCHER_FIXED_MAX = '99999.99';

    /** A percent voucher's value. */
    public const VOUCHER_PERCENT_MAX = '100';

    /** How many times one voucher can be claimed, and the points it can cost. */
    public const VOUCHER_COUNT_MAX = 100000;

    /** A PWD / Senior Citizen ID number. */
    public const ID_NUMBER_MAX = 30;

    /** A phone / GCash number (the contact_number columns are varchar(20)). */
    public const PHONE_MAX = 20;

    /** The cap for a voucher's discount value, which depends on its type. */
    public static function voucherValueMax(?string $type): string
    {
        return $type === 'percent' ? self::VOUCHER_PERCENT_MAX : self::VOUCHER_FIXED_MAX;
    }
}

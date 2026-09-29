<?php

namespace App\Exceptions;

use App\Models\MenuItem;
use App\Models\MenuItemSize;
use RuntimeException;

/**
 * A size was supplied to the inventory functions and it cannot be sold as that
 * item's size: it does not exist, belongs to a DIFFERENT menu item, is
 * inactive, is archived, or has no recipe.
 *
 * Thrown by MenuItem::sizeRecipe() — the one size resolver — and so by
 * InventoryDeductionService::requirementsForLine() when a size is passed.
 * The whole point is that nothing downstream can mistake a refused size for
 * "a valid recipe that needs zero ingredients": an empty requirements array
 * already means "no recipe, the recipe guard's business" to cartShortfalls()
 * and deductWithLock(), so returning one for a bad size would wave it through
 * the stock gate and deduct nothing. Throwing fails closed instead.
 *
 * Same shape as StockUnavailableException: extends RuntimeException, and the
 * message is customer-safe, so an order path that already renders a
 * RuntimeException's message verbatim (the walk-in counter does) shows a
 * sentence, never internals. $reason is for code that needs to tell the cases
 * apart; the message is for people.
 */
class MenuItemSizeUnavailableException extends RuntimeException
{
    public const NOT_FOUND  = 'not_found';
    public const WRONG_ITEM = 'wrong_item';
    public const INACTIVE   = 'inactive';
    public const ARCHIVED   = 'archived';
    public const NO_RECIPE  = 'no_recipe';

    public function __construct(private string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public static function notFound(MenuItem $item): self
    {
        return new self(self::NOT_FOUND, 'Sorry, that size of ' . $item->name . ' is not available.');
    }

    /** Never names the other item — the size simply is not one of THIS item's. */
    public static function wrongItem(MenuItem $item): self
    {
        return new self(self::WRONG_ITEM, 'Sorry, that size of ' . $item->name . ' is not available.');
    }

    public static function inactive(MenuItem $item, MenuItemSize $size): self
    {
        return new self(self::INACTIVE, 'Sorry, ' . $item->name . ' (' . $size->name . ') is not available right now.');
    }

    public static function archived(MenuItem $item, MenuItemSize $size): self
    {
        return new self(self::ARCHIVED, 'Sorry, ' . $item->name . ' (' . $size->name . ') is no longer available.');
    }

    /** Same wording as MenuItem::orderBlockedReason()'s base "No Recipe Set" refusal. */
    public static function noRecipe(MenuItem $item, MenuItemSize $size): self
    {
        return new self(self::NO_RECIPE, 'Sorry, ' . $item->name . ' (' . $size->name
            . ') is unavailable right now — no recipe has been set for it yet.');
    }
}

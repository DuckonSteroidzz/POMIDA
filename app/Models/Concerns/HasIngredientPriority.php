<?php

namespace App\Models\Concerns;

/**
 * INGREDIENT PRIORITY (Defect Report 6) — one recipe line's High/Low level.
 *
 * HIGH is essential (corned beef, garlic rice, egg): it decides the menu item's
 * stock status and customers cannot remove it. LOW is removable (pancake syrup,
 * garnish, side sauce): it never affects the item's status and never blocks an
 * order, the customer may leave it out, and when the shelf cannot cover it the
 * line simply goes out without it.
 *
 * Used by the two live recipe tables and by the frozen size recipe. Every row
 * that existed before this feature is HIGH (the column default), so nothing
 * changes until someone marks a line Low.
 */
trait HasIngredientPriority
{
    public function isLow(): bool
    {
        return $this->priority === self::PRIORITY_LOW;
    }

    public function isHigh(): bool
    {
        return ! $this->isLow();
    }
}

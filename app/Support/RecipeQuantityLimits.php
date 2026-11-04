<?php

namespace App\Support;

use App\Models\Inventory;

/**
 * The "did you mean Stock In?" check for recipe lines (QA Nov 2).
 *
 * A recipe line is how much ONE order uses. QA typed stock amounts into it
 * (Egg 30 pc and 40 pc, Rice 60 kg, Garlic 10-15 kg), which made the menu show
 * Low / Out of Stock while the shelf looked full. This class only DESCRIBES a
 * suspicious amount; it never refuses one, never touches stock logic, recipes
 * or the low-stock threshold, and the owner can always confirm and keep it.
 *
 * Two triggers, in this order:
 *   1. the line uses more than the ingredient's current stock, or
 *   2. it is larger than PER_ORDER_LIMITS says one order plausibly needs.
 *
 * The browser needs the same table and the same sentences, so
 * admin/partials/recipe-quantity-guard.blade.php hands it LIMITS and
 * template() instead of keeping a copy; warningFor() is the PHP twin tests pin
 * it against.
 */
class RecipeQuantityLimits
{
    /**
     * The most of each unit ONE order plausibly uses; anything above is
     * "unusually large". Counts are whole things on a plate (12 eggs is already
     * a big breakfast), measured units are a kitchen portion. A unit not
     * listed has no size limit (only the stock check applies), so an odd
     * legacy unit is never nagged about a guess.
     */
    public const PER_ORDER_LIMITS = [
        'pc' => 12, 'pcs' => 12, 'piece' => 12, 'pieces' => 12,
        'pack' => 3, 'packs' => 3,
        'bottle' => 2, 'bottles' => 2,
        'can' => 3, 'cans' => 3,
        'box' => 1, 'boxes' => 1,
        'tray' => 1, 'trays' => 1,
        'g' => 1000,
        'kg' => 1,
        'ml' => 1000,
        'l' => 1,
        'oz' => 32,
        'lb' => 2,
    ];

    /** Sentence templates; {qty} {unit} {name} {stock} {limit} are filled in. */
    public const TEMPLATE_OVER_STOCK = 'This recipe uses {qty} {unit} of {name} for ONE order, but you only have {stock} {unit} in stock.';
    public const TEMPLATE_LARGE = 'This recipe uses {qty} {unit} of {name} for ONE order. That is more than one order normally needs (over {limit} {unit}).';
    public const TEMPLATE_ADVICE = 'Did you mean to add stock? Use Inventory > Stock In instead.';

    /** Recipe editor wording (QA Nov 3): the tooltip on "Can make" / "Runs out first", and the line under the table. */
    public const CAN_MAKE_HINT = "Can make = how many orders this ingredient's stock can cover. The ingredient that runs out first decides the item's stock status. Restock it first.";
    public const RUNS_OUT_NOTE = 'The High ingredient that runs out first decides if the item is Low Stock or Out of Stock. Low ingredients never limit the item.';

    /** Lower-cased unit => limit, as the browser looks it up. */
    public static function limits(): array
    {
        return self::PER_ORDER_LIMITS;
    }

    /** The sentence templates, keyed as the browser reads them. */
    public static function templates(): array
    {
        return [
            'overStock' => self::TEMPLATE_OVER_STOCK,
            'large'     => self::TEMPLATE_LARGE,
            'advice'    => self::TEMPLATE_ADVICE,
            'canMakeHint' => self::CAN_MAKE_HINT,
        ];
    }

    public static function limitFor(?string $unit): ?float
    {
        $limit = self::PER_ORDER_LIMITS[strtolower(trim((string) $unit))] ?? null;

        return $limit === null ? null : (float) $limit;
    }

    /**
     * The warning for one recipe line, or null when it looks like a sensible
     * per-order amount. $stock is the ingredient's current inventory.quantity.
     */
    public static function warningFor(string $name, ?string $unit, float $qty, float $stock): ?string
    {
        $unit = trim((string) $unit);
        $limit = self::limitFor($unit);
        $fill = fn (string $t) => strtr($t, [
            '{qty}'   => Inventory::formatQuantity($qty),
            '{unit}'  => $unit,
            '{name}'  => $name,
            '{stock}' => Inventory::formatQuantity($stock),
            '{limit}' => $limit === null ? '' : Inventory::formatQuantity($limit),
        ]);

        if ($qty > $stock) {
            return $fill(self::TEMPLATE_OVER_STOCK . ' ' . self::TEMPLATE_ADVICE);
        }

        if ($limit !== null && $qty > $limit) {
            return $fill(self::TEMPLATE_LARGE . ' ' . self::TEMPLATE_ADVICE);
        }

        return null;
    }
}

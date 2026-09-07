<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\MenuItem;

/**
 * The ONE place that answers "what does this menu item cost to make?".
 *
 * Decision by the owner: when an item has a recipe, the recipe is the source
 * of truth for cost. The typed-in menu_items.cost column is a guess that drifts
 * the moment an ingredient price changes, so it is a FALLBACK only — used when
 * an item has neither a recipe nor a legacy single-ingredient link.
 *
 * The recipe walk is NOT re-implemented here. It is delegated to
 * InventoryDeductionService::requirementsForLine(), the same walker that decides
 * what an order actually deducts, so "what we charge ourselves for" and "what we
 * take off the shelf" can never disagree.
 *
 * BASE RECIPE ONLY. requirementsForLine() is called with an EMPTY selected-option
 * list on purpose: an add-on option is chosen per order, not part of the item, so
 * it must not inflate the item's standing cost. Options will count toward COGS in
 * a later pass, where a real order says which ones were actually chosen.
 *
 * Units: menu_item_ingredients.quantity_used is entered in the unit of the
 * inventory row it points at (the recipe form labels the quantity box with that
 * row's unit and says so), and inventory.unit_cost is pesos per that same unit.
 * So quantity_used x unit_cost is pesos, with no conversion.
 */
class MenuItemCosting
{
    public function __construct(private InventoryDeductionService $deduction)
    {
    }

    /**
     * Cost to make ONE of this menu item, plus everything the screen needs to
     * tell a real figure from a guess.
     *
     * @return array{
     *     cost: float,
     *     is_fallback: bool,
     *     price: float,
     *     profit: float,
     *     margin_percent: float|null,
     *     ingredient_count: int
     * }
     *   margin_percent is null when price is 0 — there is no percentage of
     *   nothing, and printing "0%" there would be a lie.
     */
    public function breakdownFor(MenuItem $menuItem): array
    {
        // Quantity 1, no selected options: the base recipe for a single unit.
        $needs = $this->deduction->requirementsForLine($menuItem, 1, []);

        $price = (float) $menuItem->price;

        if (empty($needs)) {
            // No recipe and no legacy link — fall back to the typed-in number.
            return $this->assemble($price, (float) $menuItem->cost, true, 0);
        }

        $unitCosts = Inventory::whereIn('id', array_keys($needs))->pluck('unit_cost', 'id');

        $cost = 0.0;
        foreach ($needs as $inventoryId => $amount) {
            // A deleted inventory row contributes nothing rather than crashing;
            // this matches how the deduction service skips a missing row.
            $cost += (float) $amount * (float) ($unitCosts[$inventoryId] ?? 0);
        }

        // An ingredient priced at 0 is legitimate (unit_cost is NOT NULL and
        // defaults to 0.00), so a computed cost of 0 is still a computed cost,
        // not a fallback.
        return $this->assemble($price, $cost, false, count($needs));
    }

    /**
     * Cost only, for callers that do not need the profit figures.
     */
    public function costFor(MenuItem $menuItem): float
    {
        return $this->breakdownFor($menuItem)['cost'];
    }

    /**
     * Breakdowns for a collection of items, keyed by menu item id — what the
     * Menu Items list needs.
     *
     * @param  iterable<MenuItem>  $menuItems
     * @return array<int, array>
     */
    public function breakdownForMany(iterable $menuItems): array
    {
        $out = [];
        foreach ($menuItems as $menuItem) {
            $out[$menuItem->id] = $this->breakdownFor($menuItem);
        }
        return $out;
    }

    private function assemble(float $price, float $cost, bool $isFallback, int $ingredientCount): array
    {
        $profit = $price - $cost;

        return [
            'cost'             => round($cost, 2),
            'is_fallback'      => $isFallback,
            'price'            => round($price, 2),
            // Deliberately NOT clamped at zero: an item that costs more than it
            // sells for is a real loss and the owner needs to see it as one.
            'profit'           => round($profit, 2),
            'margin_percent'   => $price > 0 ? round($profit / $price * 100, 1) : null,
            'ingredient_count' => $ingredientCount,
        ];
    }
}

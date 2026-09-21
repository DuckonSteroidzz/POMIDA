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
     * Phase 3b F3: this used to loop breakdownFor() per item, which is fine
     * for one item and was an N+1 the moment a caller had a list — measured
     * at 48 queries for 10 menu items, 128 for 30 (~4 per item), because
     * breakdownFor() takes its own Inventory::whereIn() every time it runs.
     * Now batched exactly like costForMany() below: one collect-then-whereIn
     * pass over every item's requirements, then each item's full breakdown is
     * assembled from that single shared map. The arithmetic is unchanged —
     * same requirementsForLine() walk, same quantity x unit_cost, same
     * fallback to menu_items.cost when there is no recipe — so a caller
     * switching to this method gets identical figures, not approximations.
     *
     * EAGER-LOAD recipeIngredients on the collection you pass, same rule as
     * costForMany() — without it this trades the Inventory N+1 above for a
     * menu_item_ingredients one instead.
     *
     * @param  iterable<MenuItem>  $menuItems
     * @return array<int, array>
     */
    public function breakdownForMany(iterable $menuItems): array
    {
        $needsByItem = [];
        $inventoryIds = [];

        foreach ($menuItems as $menuItem) {
            if (isset($needsByItem[$menuItem->id])) {
                continue;
            }

            $needs = $this->deduction->requirementsForLine($menuItem, 1, []);
            $needsByItem[$menuItem->id] = $needs;

            foreach (array_keys($needs) as $inventoryId) {
                $inventoryIds[$inventoryId] = true;
            }
        }

        $unitCosts = empty($inventoryIds)
            ? collect()
            : Inventory::whereIn('id', array_keys($inventoryIds))->pluck('unit_cost', 'id');

        $out = [];

        foreach ($menuItems as $menuItem) {
            if (isset($out[$menuItem->id])) {
                continue;
            }

            $needs = $needsByItem[$menuItem->id] ?? [];
            $price = (float) $menuItem->price;

            if (empty($needs)) {
                // No recipe and no legacy link — fall back to the typed-in
                // number, exactly as breakdownFor() falls back.
                $out[$menuItem->id] = $this->assemble($price, (float) $menuItem->cost, true, 0);
                continue;
            }

            $cost = 0.0;
            foreach ($needs as $inventoryId => $amount) {
                // A deleted inventory row contributes nothing rather than
                // crashing, matching breakdownFor() and the deduction service.
                $cost += (float) $amount * (float) ($unitCosts[$inventoryId] ?? 0);
            }

            $out[$menuItem->id] = $this->assemble($price, $cost, false, count($needs));
        }

        return $out;
    }

    /**
     * costFor() for many menu items at once, in a FIXED number of queries.
     *
     * breakdownFor() takes ONE Inventory::whereIn per item, which is fine for
     * a single item and is an N+1 the moment a caller has a list. The obvious
     * caller is ProfitCalculationService, which prices every sold line whose
     * historical cost snapshot is missing — over a wide date range that was
     * measured at 112 queries for one Analytics page view, growing with the
     * range rather than staying fixed.
     *
     * This collects every ingredient id all the items between them need, takes
     * ONE whereIn over the lot, and prices each item from that single map. The
     * arithmetic per item is byte-for-byte what breakdownFor() does — same
     * requirementsForLine() walk, same quantity x unit_cost, same fallback to
     * the typed-in menu_items.cost when there is no recipe — so a caller
     * switching to this method gets identical figures, not approximations.
     *
     * EAGER-LOAD recipeIngredients on the collection you pass. requirementsForLine()
     * reuses a loaded relation and queries only when it is not, so without that
     * this trades one N+1 for another.
     *
     * @param  iterable<MenuItem>  $menuItems
     * @return array<int, float>  menu_item_id => cost to make one
     */
    public function costForMany(iterable $menuItems): array
    {
        $needsByItem = [];
        $inventoryIds = [];

        foreach ($menuItems as $menuItem) {
            if (isset($needsByItem[$menuItem->id])) {
                continue;
            }

            $needs = $this->deduction->requirementsForLine($menuItem, 1, []);
            $needsByItem[$menuItem->id] = $needs;

            foreach (array_keys($needs) as $inventoryId) {
                $inventoryIds[$inventoryId] = true;
            }
        }

        $unitCosts = empty($inventoryIds)
            ? collect()
            : Inventory::whereIn('id', array_keys($inventoryIds))->pluck('unit_cost', 'id');

        $out = [];

        foreach ($menuItems as $menuItem) {
            $needs = $needsByItem[$menuItem->id] ?? [];

            if (empty($needs)) {
                // No recipe and no legacy link — the typed-in number, exactly
                // as breakdownFor() falls back.
                $out[$menuItem->id] = round((float) $menuItem->cost, 2);
                continue;
            }

            $cost = 0.0;
            foreach ($needs as $inventoryId => $amount) {
                // A deleted inventory row contributes nothing rather than
                // crashing, matching breakdownFor() and the deduction service.
                $cost += (float) $amount * (float) ($unitCosts[$inventoryId] ?? 0);
            }

            $out[$menuItem->id] = round($cost, 2);
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

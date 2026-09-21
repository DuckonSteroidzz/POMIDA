<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\MenuItem;
use Illuminate\Support\Collection;

/**
 * ProductionCapacityService — "how many MORE of this can the kitchen make
 * right now, and which ingredient runs out first?"
 *
 * Phase 2b. Deterministic, descriptive, no forecasting: every number below is
 * arithmetic over rows that already exist. Nothing here predicts anything, and
 * nothing here writes.
 *
 * THE MATH IS NOT DEFINED HERE. It is defined once, in
 * InventoryDeductionService::availabilityBreakdownFor(), which is the same
 * calculation the checkout stock gate is built on — so "capacity 5" on the
 * Analytics page and "only 5 left in stock" at the till can never disagree.
 * This class is the ANALYTICS shape around that: it decides which menu items
 * are in scope, loads their inventory in bulk, and turns each breakdown into a
 * structured row. It returns data, never presentation strings.
 *
 *   ingredient capacity = floor(available ÷ required per order)
 *   menu capacity       = MIN(every ingredient capacity)
 *   bottleneck          = ARGMIN(the same)
 *
 * AVAILABLE, not on-hand. "Available" subtracts what pending/preparing/serving
 * orders have already committed but not yet consumed — stock only leaves the
 * shelf at completion, so inventory.quantity on its own would count the same
 * serving twice. See InventoryDeductionService::committedQuantities().
 *
 * UNMEASURABLE ≠ ZERO. An item with no recipe and no legacy inventory link has
 * no capacity to report, which is a different fact from "can make none of it".
 * It is reported as is_measurable = false with a reason, exactly the
 * distinction MenuItem::isMissingRecipe() already draws for the menu grid.
 *
 * BASE RECIPE ONLY, and deliberately so. Add-on options are a per-order choice,
 * not a standing property of the item — the same convention menuItemsOutOfStock()
 * and MenuItemCosting already follow. Averaging over some invented "typical"
 * option combination would fabricate a number the database cannot support, so
 * the summary reports the base recipe and the limitation is stated on the page.
 * Callers that DO know the selected options (a real order line does) can pass
 * them to forMenuItem() and the option ingredients are honoured in full,
 * branch-filtered exactly as a real deduction would filter them.
 *
 * UNITS. None are converted. Recipe quantities are already denominated in the
 * inventory row's own unit — that is what makes floor(available ÷ required)
 * meaningful — so the unit is carried through for display and never arithmetic.
 *
 * BRANCH SCOPE. Take it from ResolvesBranchScope::getSelectedBranch() and
 * nowhere else: the caller passes 'all' or one branch id, and this class never
 * reads a request. See forBranchScope() for what each means and for the
 * cross-branch guard on individual recipe rows.
 */
class ProductionCapacityService
{
    /**
     * No recipe rows and no legacy inventory link — nothing to measure.
     *
     * Aliased from InventoryDeductionService rather than restated: the verdict
     * is produced there, and a second copy of the literal is how the page and
     * the calculation would eventually stop agreeing.
     */
    public const REASON_NO_RECIPE = InventoryDeductionService::UNMEASURABLE_NO_RECIPE;

    /** Recipe exists but every line is zero-quantity or points at a vanished row. */
    public const REASON_NO_MEASURABLE_INGREDIENT = InventoryDeductionService::UNMEASURABLE_NO_INGREDIENT;

    /**
     * Scoped to one branch, but the recipe reaches into another branch's
     * inventory. Refused rather than answered — see forBranchScope().
     */
    public const REASON_CROSS_BRANCH_INGREDIENT = 'cross_branch_ingredient';

    public function __construct(private InventoryDeductionService $deduction)
    {
    }

    /**
     * Capacity for every measurable menu item in $branchScope, most at-risk
     * first (lowest capacity leads; unmeasurable items sort last, since an
     * item with no recipe is a data-entry job rather than a stock warning).
     *
     * SCOPE. A specific branch id lists only that branch's menu items and
     * measures them only against that branch's inventory. 'all' is the
     * consolidated owner view: every branch's items, each still measured
     * against its OWN branch's stock, because branches never share a pantry.
     * Note what 'all' is NOT — there is no per-branch comparison, ranking or
     * total here, and none should be added; that shape was removed from this
     * page in the Phase 3 audit as a cross-branch disclosure risk.
     *
     * Menu items with a NULL branch_id are excluded from a scoped view for
     * the same reason menuItemsOutOfStock() excludes them: their recipe rows
     * point at whichever branch's inventory they were configured against, and
     * showing them under Branch A could put Branch B's stock levels on Branch
     * A's screen.
     *
     * is_available = true only, matching menuItemsOutOfStock(): an item the
     * admin has switched off is not in production, so it is not a capacity
     * question.
     *
     * QUERIES. Four, whatever the number of menu items: the items (with
     * recipes eager-loaded), the committed-stock walk, one whereIn for every
     * inventory row any recipe touches, and the branch names. Per-item work
     * after that is pure arithmetic — no query is issued inside the loop.
     *
     * @param  int|string  $branchScope  A branch id, or the string 'all'.
     * @return array<int, array{
     *     menu_item_id:int, menu_item_name:string, branch_id:int|null, branch_name:string|null,
     *     capacity:int|null, is_measurable:bool, unavailable_reason:string|null,
     *     bottleneck_inventory_id:int|null, bottleneck_name:string|null,
     *     bottleneck_unit:string|null, bottleneck_capacity:int|null,
     *     ingredients:array<int, array<string, mixed>>
     * }>
     */
    public function forBranchScope($branchScope): array
    {
        $scopedBranchId = $branchScope === 'all' ? null : (int) $branchScope;

        $menuItems = MenuItem::query()
            ->with('recipeIngredients')
            ->where('is_available', true)
            ->when($scopedBranchId !== null, fn ($q) => $q->where('branch_id', $scopedBranchId))
            ->orderBy('name')
            ->get();

        if ($menuItems->isEmpty()) {
            return [];
        }

        // One pass to collect every inventory id any of these recipes touches,
        // so the whole table costs a single whereIn instead of one query per
        // menu item. Options are not consulted here — base recipe only — which
        // is also why requirementsForLine() issues no MenuOption query below.
        $inventoryIds = [];
        foreach ($menuItems as $menuItem) {
            foreach (array_keys($this->perUnitRequirements($menuItem, $scopedBranchId)) as $invId) {
                $inventoryIds[$invId] = true;
            }
        }

        $inventory = empty($inventoryIds)
            ? collect()
            : Inventory::whereIn('id', array_keys($inventoryIds))->get()->keyBy('id');

        $committed = $this->deduction->committedQuantities();

        // Only the consolidated view needs branch names — it is the only one
        // where two rows can carry the same menu item name and need telling
        // apart. A branch-scoped viewer already knows which branch they are
        // looking at, so this query is not taken at all for them.
        $branchNames = $scopedBranchId === null
            ? \App\Models\Branch::query()
                ->whereIn('id', $menuItems->pluck('branch_id')->filter()->unique()->all())
                ->pluck('name', 'id')
            : collect();

        $rows = [];

        foreach ($menuItems as $menuItem) {
            $row = $this->forMenuItem($menuItem, $branchScope, [], $committed, $inventory);
            $row['branch_name'] = $menuItem->branch_id !== null
                ? ($branchNames[$menuItem->branch_id] ?? null)
                : null;
            $rows[] = $row;
        }

        // Lowest capacity first — the point of the table is "what am I about to
        // run out of". Unmeasurable rows have no capacity to rank, so they sit
        // at the bottom; ties fall back to the name for a stable order.
        usort($rows, function (array $a, array $b) {
            if ($a['is_measurable'] !== $b['is_measurable']) {
                return $a['is_measurable'] ? -1 : 1;
            }
            if ($a['is_measurable'] && $a['capacity'] !== $b['capacity']) {
                return $a['capacity'] <=> $b['capacity'];
            }
            return strcasecmp($a['menu_item_name'], $b['menu_item_name']);
        });

        return $rows;
    }

    /**
     * One menu item's capacity, bottleneck and per-ingredient working.
     *
     * $selectedOptionIds is empty for the analytics summary (base recipe only,
     * see the class docblock). Pass real option ids — from an order line, say —
     * and their ingredients are included exactly as a deduction would include
     * them, which means branch-filtered: an option's ingredient link that points
     * at a different branch's inventory is ignored, never deducted and never
     * counted here. See InventoryDeductionService::requirementsForLine().
     *
     * @param  int|string  $branchScope  A branch id, or 'all'.
     * @param  array<int,float>|null  $committed  Reuse a committedQuantities() map.
     * @param  Collection|null  $inventoryById  Reuse pre-loaded Inventory rows.
     * @return array<string, mixed>
     */
    public function forMenuItem(
        MenuItem $menuItem,
        $branchScope = 'all',
        array $selectedOptionIds = [],
        ?array $committed = null,
        ?Collection $inventoryById = null
    ): array {
        $scopedBranchId = $branchScope === 'all' ? null : (int) $branchScope;

        // The branch whose stock this item is actually made from: its own.
        // Under 'all' that is still the item's branch, never a merged pantry —
        // branches do not share stock, so there is no such thing as a
        // cross-branch capacity figure.
        $effectiveBranchId = $menuItem->branch_id !== null ? (int) $menuItem->branch_id : $scopedBranchId;

        $breakdown = $this->deduction->availabilityBreakdownFor(
            $menuItem,
            $effectiveBranchId,
            $selectedOptionIds,
            $committed,
            $inventoryById
        );

        $base = [
            'menu_item_id'            => (int) $menuItem->id,
            'menu_item_name'          => (string) $menuItem->name,
            'branch_id'               => $menuItem->branch_id !== null ? (int) $menuItem->branch_id : null,
            'branch_name'             => null,
            'capacity'                => null,
            'is_measurable'           => false,
            'unavailable_reason'      => null,
            'bottleneck_inventory_id' => null,
            'bottleneck_name'         => null,
            'bottleneck_unit'         => null,
            'bottleneck_capacity'     => null,
            'ingredients'             => [],
        ];

        // Cross-branch guard. Recipe rows are written same-branch only
        // (AdminController::inventoryIsSelectableForBranch()), so a row
        // reaching into another branch is a data anomaly rather than a normal
        // state — but if one ever exists, a branch-scoped viewer must not learn
        // that branch's stock level from it. Refuse the whole item with an
        // honest unavailable state instead of publishing a partial answer, and
        // drop the offending row's numbers on the way out.
        if ($scopedBranchId !== null) {
            foreach ($breakdown['ingredients'] as $ingredient) {
                if ($ingredient['branch_id'] !== null && $ingredient['branch_id'] !== $scopedBranchId) {
                    return array_merge($base, [
                        'unavailable_reason' => self::REASON_CROSS_BRANCH_INGREDIENT,
                    ]);
                }
            }
        }

        if ($breakdown['units'] === null) {
            return array_merge($base, [
                'unavailable_reason' => $breakdown['reason'] ?? self::REASON_NO_RECIPE,
                'ingredients'        => $this->sortIngredients($breakdown['ingredients']),
            ]);
        }

        return array_merge($base, [
            'capacity'                => (int) $breakdown['units'],
            'is_measurable'           => true,
            'bottleneck_inventory_id' => $breakdown['bottleneck']['inventory_id'] ?? null,
            'bottleneck_name'         => $breakdown['bottleneck']['name'] ?? null,
            'bottleneck_unit'         => $breakdown['bottleneck']['unit'] ?? null,
            'bottleneck_capacity'     => $breakdown['bottleneck']['capacity'] ?? null,
            'ingredients'             => $this->sortIngredients($breakdown['ingredients']),
        ]);
    }

    /**
     * The per-unit requirement map for one item, with the SAME branch
     * resolution forMenuItem() uses — so the bulk inventory preload in
     * forBranchScope() cannot miss a row the per-item pass then asks for.
     *
     * @return array<int,float>
     */
    private function perUnitRequirements(MenuItem $menuItem, ?int $scopedBranchId): array
    {
        $effectiveBranchId = $menuItem->branch_id !== null ? (int) $menuItem->branch_id : $scopedBranchId;

        return $this->deduction->requirementsForLine($menuItem, 1, [], $effectiveBranchId);
    }

    /**
     * Tightest ingredient first, so the detail view opens on the reason the
     * number is what it is. Rows with no capacity to compare (missing row,
     * zero-quantity line) sit at the bottom.
     *
     * @param  array<int, array<string, mixed>>  $ingredients
     * @return array<int, array<string, mixed>>
     */
    private function sortIngredients(array $ingredients): array
    {
        usort($ingredients, function (array $a, array $b) {
            $aHas = $a['capacity'] !== null;
            $bHas = $b['capacity'] !== null;

            if ($aHas !== $bHas) {
                return $aHas ? -1 : 1;
            }
            if ($aHas && $a['capacity'] !== $b['capacity']) {
                return $a['capacity'] <=> $b['capacity'];
            }

            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $ingredients;
    }
}

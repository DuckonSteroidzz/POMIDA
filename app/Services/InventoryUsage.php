<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Where an inventory row is still used as a recipe ingredient.
 *
 * Tester report (2026-11-01): an ingredient (Egg) was deleted while a menu
 * item still used it, and the item went on showing Egg with its old stock.
 * Archiving an inventory row deliberately leaves every recipe line pointing
 * at it (an archived row keeps deducting), so the only safe order is: take
 * it out of the recipes first, then delete. This is the ONE check behind
 * both stages — Delete (archive) and Permanently Delete — and behind the
 * browser-side pop-up, so they can never disagree.
 *
 * Covers every place a recipe can name an ingredient:
 *   - menu_item_ingredients        the menu item's own recipe
 *   - menu_item_size_ingredients   a size's recipe (replaces the item's own)
 *   - menu_option_ingredients      an add-on's recipe
 *   - menu_items.inventory_item_id the old single-ingredient link, which the
 *                                  stock maths still falls back to
 * An inventory id belongs to exactly one branch, so "same branch" is already
 * what an id match means.
 *
 * Read with the query builder on purpose: the menu models hide archived rows
 * behind a global scope, and an ARCHIVED menu item / size / add-on still owns
 * its recipe (restoring it brings the recipe back), so it must still block.
 */
class InventoryUsage
{
    /**
     * Names of everything that still uses the ingredient, sorted, no repeats:
     * "Carbonara", "Carbonara (Large)", "Add-on: Extra Egg". Archived owners
     * carry an " (archived)" suffix so the owner knows to look in Archived.
     *
     * @return list<string>
     */
    public static function usedBy(int $inventoryId): array
    {
        return self::usedByMany([$inventoryId])[$inventoryId] ?? [];
    }

    /** The pop-up wording, exactly as agreed with the tester. */
    public static function blockedMessage(array $labels): string
    {
        return 'You cannot delete this item because it is currently being used by the following: '
            . implode(', ', $labels)
            . '. Please remove it from these items first before attempting to delete it.';
    }

    /**
     * usedBy() for many ingredients at once, keyed by inventory id — four
     * queries however many ids, which is what the Inventory and Archive pages
     * embed so the pop-up opens without a round trip. Ids with no use are
     * left out.
     *
     * @param  iterable<int>  $inventoryIds
     * @return array<int, list<string>>
     */
    public static function usedByMany(iterable $inventoryIds): array
    {
        $ids = collect($inventoryIds)->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $map = [];
        $tag = fn ($archivedAt) => $archivedAt ? ' (archived)' : '';
        $add = function (int $inventoryId, string $label) use (&$map) {
            $map[$inventoryId][] = $label;
        };

        DB::table('menu_item_ingredients as r')
            ->join('menu_items as m', 'm.id', '=', 'r.menu_item_id')
            ->whereIn('r.inventory_id', $ids)
            ->get(['r.inventory_id', 'm.name', 'm.archived_at'])
            ->each(fn ($row) => $add($row->inventory_id, $row->name . $tag($row->archived_at)));

        DB::table('menu_item_size_ingredients as r')
            ->join('menu_item_sizes as s', 's.id', '=', 'r.menu_item_size_id')
            ->join('menu_items as m', 'm.id', '=', 's.menu_item_id')
            ->whereIn('r.inventory_id', $ids)
            ->get(['r.inventory_id', 'm.name as item', 'm.archived_at as item_archived', 's.name as size', 's.archived_at as size_archived'])
            ->each(fn ($row) => $add(
                $row->inventory_id,
                $row->item . ' (' . $row->size . ')' . $tag($row->item_archived ?: $row->size_archived)
            ));

        DB::table('menu_option_ingredients as r')
            ->join('menu_options as o', 'o.id', '=', 'r.menu_option_id')
            ->whereIn('r.inventory_id', $ids)
            ->get(['r.inventory_id', 'o.name', 'o.archived_at'])
            ->each(fn ($row) => $add($row->inventory_id, 'Add-on: ' . $row->name . $tag($row->archived_at)));

        DB::table('menu_items')
            ->whereIn('inventory_item_id', $ids)
            ->get(['inventory_item_id', 'name', 'archived_at'])
            ->each(fn ($row) => $add($row->inventory_item_id, $row->name . $tag($row->archived_at)));

        foreach ($map as $id => $labels) {
            $labels = array_values(array_unique($labels));
            natcasesort($labels);
            $map[$id] = array_values($labels);
        }

        return $map;
    }
}

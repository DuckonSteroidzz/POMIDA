<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\MenuItemSize;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The write side of Menu Item Sizes (Phase 1): create the two fixed sizes,
 * change one, archive one, restore one.
 *
 * Every method takes a row lock on the PARENT menu item first, inside its own
 * transaction. The parent row is what the starting price
 * (menu_items.price = lowest live size price, re-derived by MenuItemSize's
 * saved hook) is written to, so two admins saving Regular and Large at the
 * same moment queue up and the second one derives the minimum from both
 * saved prices instead of from a half-updated pair. It is also what makes two
 * simultaneous "set up sizes" clicks resolve to one pair: the second waits,
 * then sees the first's rows and is refused (the UNIQUE (menu_item_id, name)
 * index is the backstop).
 *
 * Branch authorisation is NOT here — callers resolve the menu item through
 * AdminOrderAccess::resolveRecordInScope() first, as every menu-item endpoint
 * does. A size has no branch of its own; its item is the boundary.
 */
class MenuItemSizes
{
    /**
     * Make an unsized item sized: Regular and Large, created together, both
     * active, no recipe yet (so both read "No Recipe Set" and neither is
     * orderable until a recipe is added). Refused when the item already has
     * size rows — archived ones included — so there is never a third row or a
     * second pair.
     *
     * @throws DomainException  the item already has sizes (message is admin-facing)
     */
    public function enable(MenuItem $menuItem, string|float|int $regularPrice, string|float|int $largePrice): Collection
    {
        return DB::transaction(function () use ($menuItem, $regularPrice, $largePrice) {
            $locked = $this->lockParent($menuItem);

            if (MenuItemSize::withArchived()->where('menu_item_id', $locked->id)->exists()) {
                throw new DomainException(
                    '"' . $locked->name . '" already has Regular and Large sizes — edit them below instead.'
                );
            }

            $prices = [
                MenuItemSize::REGULAR => $regularPrice,
                MenuItemSize::LARGE   => $largePrice,
            ];

            foreach (MenuItemSize::DEFINITIONS as $name => $displayOrder) {
                MenuItemSize::create([
                    'menu_item_id'  => $locked->id,
                    'name'          => $name,
                    'price'         => $prices[$name],
                    'display_order' => $displayOrder,
                    'is_active'     => true,
                ]);
            }

            return $locked->allSizes()->get();
        });
    }

    /** Price and active flag of one live size. The name and position never change. */
    public function update(MenuItemSize $size, string|float|int $price, bool $isActive): MenuItemSize
    {
        return DB::transaction(function () use ($size, $price, $isActive) {
            $this->lockParent($size->menuItem()->firstOrFail());

            $size->price = $price;
            $size->is_active = $isActive;
            $size->save();

            return $size;
        });
    }

    /**
     * Archivable::archive() — sets archived_at, keeps the row and every one of
     * its recipe lines exactly as they are.
     */
    public function archive(MenuItemSize $size): MenuItemSize
    {
        return DB::transaction(function () use ($size) {
            $this->lockParent($size->menuItem()->firstOrFail());
            $size->archive();

            return $size;
        });
    }

    /**
     * Archivable::unarchive() — the same row, so the same id and the same
     * recipe lines come back; nothing is recreated.
     */
    public function restore(MenuItemSize $size): MenuItemSize
    {
        return DB::transaction(function () use ($size) {
            $this->lockParent($size->menuItem()->firstOrFail());
            $size->unarchive();

            return $size;
        });
    }

    private function lockParent(MenuItem $menuItem): MenuItem
    {
        return MenuItem::withArchived()
            ->whereKey($menuItem->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}

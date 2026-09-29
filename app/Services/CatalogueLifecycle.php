<?php

namespace App\Services;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Subcategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * CatalogueLifecycle — the one place that decides what "remove this" means.
 *
 * THE PROBLEM THIS REPLACES
 * -------------------------
 * Deleting a menu item that had ever been ordered was answered by unticking
 * "Available", which left it in the Menu Items list forever. The owner's
 * objection was exactly right: if you are replacing an item, the list should
 * get clean, not accumulate invisible clutter. And it cascaded — the category
 * could then never be deleted either, because of an item they thought they had
 * already removed.
 *
 * THE RULE
 * --------
 * One question decides everything: does anything actually reference this row?
 *
 *   Nothing references it   -> HARD DELETE. The row is genuinely gone. This is
 *                              the case that did not exist at all before, and
 *                              it is the common one: a typo, a test item, an
 *                              option nobody ever picked.
 *
 *   Something references it -> ARCHIVE. It leaves every list and every
 *                              orderable surface, but the row survives so old
 *                              receipts still name it and sales history stays
 *                              truthful. Square, Toast and Loyverse all refuse
 *                              to delete an item with sales history for the
 *                              same reason.
 *
 * Either way the admin is told which of the two happened and why — the previous
 * behaviour's real sin was that it read like a failure ("hidden from menu") when
 * the system had made a decision.
 *
 * WHAT COUNTS AS A REFERENCE
 * --------------------------
 * Only rows that would LOSE MEANING if this one vanished — which in practice is
 * order history. A record's own children do not count and are allowed to
 * cascade with it: menu_item_options / menu_item_ingredients belong to their
 * menu item, menu_option_ingredients belongs to its option. Deleting the parent
 * is meant to take them.
 *
 * ONE DELIBERATE EXCEPTION, added 2026-09-02: removing a MENU OPTION also
 * treats its menu_item_options rows as a reference. Read from the option's
 * side those rows are not disposable children — they are which items the
 * add-on may be chosen on, i.e. the admin's own configuration work — and
 * cascading them away made the same trash click either safely archive or
 * permanently destroy depending only on whether a customer had ever picked
 * that add-on. See removeMenuOption(). The rule above still holds unchanged
 * for categories and subcategories.
 *
 * MENU ITEMS NO LONGER FOLLOW THE RULE AT ALL (2026-09-24). Removing a menu
 * item now ALWAYS archives it, sold or not — the same two-stage shape
 * Inventory already has. The irreversible step is a separate, owner-only
 * permanentlyDeleteMenuItem(), reachable only from the Archived page. See
 * removeMenuItem() for why the hybrid was wrong for menu items.
 *
 * Verified against the live schema rather than assumed. The two history
 * links are:
 *   order_items.menu_item_id          -> menu_items    ON DELETE SET NULL
 *                                                      (since 2026-09-24; was RESTRICT)
 *   order_item_options.menu_option_id -> menu_options  ON DELETE RESTRICT
 * Everything else in the catalogue is CASCADE or SET NULL. Because the
 * menu-item link no longer RESTRICTs, nothing in the database stops a
 * menu_items delete any more — permanentlyDeleteMenuItem() is the only path
 * that deletes one, and removeCategory() never hard-deletes a category that
 * still holds any item (its CASCADE would bypass every guard below).
 */
class CatalogueLifecycle
{
    public const DELETED  = 'deleted';
    public const ARCHIVED = 'archived';
    public const BLOCKED  = 'blocked';
    public const RESTORED = 'restored';

    /**
     * Decide and act.
     *
     * @return array{action: string, message: string, model: Model}
     */
    public function remove(Model $record): array
    {
        return match (true) {
            $record instanceof MenuItem    => $this->removeMenuItem($record),
            $record instanceof MenuOption  => $this->removeMenuOption($record),
            $record instanceof Category    => $this->removeCategory($record),
            $record instanceof Subcategory => $this->removeSubcategory($record),
            default => throw new \InvalidArgumentException(
                'CatalogueLifecycle does not handle ' . $record::class
            ),
        };
    }

    // ── Menu items ───────────────────────────────────────────────────────────

    /**
     * ALWAYS ARCHIVES — sold or unsold (2026-09-24).
     *
     * This used to hard-delete an item nothing had ordered, which made the
     * normal Delete button a hidden permanent-delete door with no UI:
     *
     *   - The same click archived or destroyed depending only on sales
     *     history, and the destroy half took the item's menu_item_options and
     *     menu_item_ingredients with it (both ON DELETE CASCADE) without the
     *     admin ever being shown what was being lost.
     *   - DELETE /admin/menu-items/{id} resolves archived rows too, so an
     *     ARCHIVED item whose order lines had since been purged was hard-deleted
     *     by the ordinary endpoint — including for a same-branch supervisor,
     *     who is never allowed an irreversible catalogue delete.
     *   - The image was unlinked BEFORE the row delete, so a delete that then
     *     failed still lost the image while the error said nothing had changed.
     *
     * Now the only thing this does is stamp archived_at. Option links and
     * recipe rows are not touched (archive() only saves one column), so a
     * restore brings the item back exactly as it was. Nothing here unlinks
     * the image either — only permanentlyDeleteMenuItem() may, and only
     * after its database delete has succeeded.
     *
     * Idempotent on an already-archived item (archive() keeps the first
     * date), so even a caller that skipped deleteMenuItem()'s own
     * "already archived" refusal can no longer delete anything through here.
     */
    private function removeMenuItem(MenuItem $item): array
    {
        $orderLines = DB::table('order_items')->where('menu_item_id', $item->id)->count();

        $item->archive();

        if ($orderLines > 0) {
            return $this->result(self::ARCHIVED, $item, sprintf(
                'Menu item "%s" was archived, not deleted — it appears on %s, and those '
                . 'receipts and sales figures keep showing it. It is gone from the menu and '
                . 'from every order screen, and you can bring it back any time from Archived '
                . 'items. The owner can also permanently delete it there once it is on no open '
                . 'order; its past order lines are kept either way.',
                $item->name,
                $this->plural($orderLines, 'past order line')
            ));
        }

        return $this->result(self::ARCHIVED, $item, sprintf(
            'Menu item "%s" was archived. It is gone from the menu and from every order '
            . 'screen, and its add-ons and recipe are kept, so you can bring it back any time '
            . 'from Archived items, where the owner can also permanently delete it.',
            $item->name
        ));
    }

    /**
     * The irreversible second stage — owner only, archived items only, and
     * never while the item is on an OPEN order.
     *
     * The caller (AdminController::forceDeleteArchivedMenuItem()) has already
     * resolved the row from the archived scope and checked the role. Both
     * facts are re-checked HERE, under a row lock, because they are exactly
     * what could change between the page render and this request: another
     * owner restoring the item, or an order line appearing.
     *
     * SOLD ITEMS CAN GO NOW (Phase 2). order_items.menu_item_id is NULLABLE
     * + ON DELETE SET NULL: every past order line survives with its own
     * item_name / item_price / subtotal / add-on snapshot and only its live
     * link is cut. Receipts, reports and exports all read the snapshot.
     *
     * OPEN ORDERS STILL BLOCK IT. A pending, preparing or serving order has
     * not been deducted yet, and deduction reads the LIVE recipe: with the
     * link cut, completing that order would silently deduct nothing and the
     * stock it had promised would be released early. The database no longer
     * stops that, so this check is the only thing that does.
     *
     * LOCK ORDER, and why it is this exact order. The item row is locked by
     * the FIRST statement, so an order being written for this item right
     * now (its foreign-key check holds a shared lock on this row) finishes
     * first. The open-line count is then a LOCKING read: under
     * REPEATABLE-READ, a plain read would be answered from a snapshot, and
     * any plain read taken before the lock would pin a snapshot that cannot
     * see an order committed while we waited. Proven with two real sessions
     * — the plain count said 0 while the locking count said 1. Do not add a
     * plain read of order_items above the lock.
     *
     * COSTS ARE FROZEN FIRST. A completed line with no recorded ingredient
     * cost is priced by the profit report at TODAY's recipe for this item,
     * which is about to be deleted. Before the delete, that same figure
     * (ProfitCalculationService::estimatedUnitCostFor()) is written onto the
     * line and flagged ingredient_cost_estimated, so COGS and Gross Profit
     * read identically before and after. A zero estimate is left alone — the
     * line stays "uncosted" and the report says so. Cancelled lines and lines
     * with a real cost are never touched.
     *
     * WHAT CASCADES. menu_item_options and menu_item_ingredients are
     * ON DELETE CASCADE — they are counted first so the admin is told how
     * many went, and the Archived page shows the same counts before the click.
     *
     * DATABASE FIRST, FILE SECOND. The row delete is verified to have happened
     * (a re-read, not trust in delete()'s return), and so is every past line
     * having kept its row with the link cut. The transaction commits, and
     * only THEN is the image file removed. A failed database delete therefore
     * leaves the image exactly where it was. A failed file removal after a
     * successful delete is reported as what it is — the item is gone, the
     * file is not — and is never rolled back into a half-restored row.
     *
     * @return array{action: string, message: string, model: Model, cleanup_problem: ?string}
     */
    public function permanentlyDeleteMenuItem(MenuItem $item): array
    {
        $outcome = DB::transaction(function () use ($item) {
            // 1. The row lock — the first statement in the transaction.
            $locked = MenuItem::onlyArchived()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return $this->result(self::BLOCKED, $item, sprintf(
                    '"%s" is no longer in the archive — it may have been restored or '
                    . 'permanently deleted already. Nothing was changed.',
                    $item->name
                ));
            }

            // 2. Open-order lines: a LOCKING read, after the row lock.
            $openLines = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.menu_item_id', $locked->id)
                ->whereIn('orders.status', InventoryDeductionService::COMMITTED_ORDER_STATUSES)
                ->sharedLock()
                ->count();

            if ($openLines > 0) {
                return $this->result(self::BLOCKED, $locked, sprintf(
                    'Menu item "%s" cannot be permanently deleted yet — %s still on an open order '
                    . '(pending, preparing or serving). Complete or cancel that order first, then '
                    . 'try again. It stays archived; nothing was changed.',
                    $locked->name,
                    $this->plural($openLines, 'of its order lines is', 'of its order lines are')
                ));
            }

            // 3. Everything the delete is about to touch.
            $pastLineIds = DB::table('order_items')
                ->where('menu_item_id', $locked->id)
                ->orderBy('id')
                ->sharedLock()
                ->pluck('id')
                ->all();

            $uncostedLineIds = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.menu_item_id', $locked->id)
                ->where('orders.status', 'completed')
                ->where(fn ($q) => $q->whereNull('order_items.ingredient_cost')
                    ->orWhere('order_items.ingredient_cost', '<=', 0))
                ->sharedLock()
                ->pluck('order_items.id')
                ->all();

            $optionLinks = DB::table('menu_item_options')->where('menu_item_id', $locked->id)->count();
            $recipeLines = DB::table('menu_item_ingredients')->where('menu_item_id', $locked->id)->count();

            // 4. Freeze the report's own estimate onto the uncosted lines,
            //    while the recipe it is computed from still exists.
            $frozenLines = 0;

            if ($uncostedLineIds) {
                $estimate = app(ProfitCalculationService::class)->estimatedUnitCostFor($locked);

                if ($estimate > 0) {
                    $frozenLines = DB::table('order_items')
                        ->whereIn('id', $uncostedLineIds)
                        ->update([
                            'ingredient_cost'           => $estimate,
                            'ingredient_cost_estimated' => true,
                        ]);
                }
            }

            // 5. The delete. The database cuts every past line's link.
            $locked->delete();

            // 6. Verify, and roll everything back on any surprise.
            if (DB::table('menu_items')->where('id', $locked->id)->exists()) {
                // Rolls the transaction back; HandlesSafeDeletes reports it
                // as a failed delete rather than this method claiming success.
                throw new \RuntimeException('Menu item #' . $locked->id . ' survived its permanent delete.');
            }

            $keptLines = DB::table('order_items')
                ->whereIn('id', $pastLineIds)
                ->whereNull('menu_item_id')
                ->count();

            if ($keptLines !== count($pastLineIds)) {
                throw new \RuntimeException(sprintf(
                    'Menu item #%d: expected %d past order lines to be kept with their link cut, found %d.',
                    $locked->id,
                    count($pastLineIds),
                    $keptLines
                ));
            }

            // SHORT ON PURPOSE (2026-09-24). This used to spell out every
            // count (past lines, frozen costs, add-on/recipe cascade) in the
            // flash message itself. Those counts already have a place the
            // admin sees BEFORE clicking — the Archived page's warning text
            // and the confirm() dialog, both built independently in
            // archived.blade.php from the same withCount() query this method
            // re-checks under lock. Repeating them in the SUCCESS message
            // after the fact just made an auto-dismissing toast too long to
            // read in time. $optionLinks/$recipeLines/$frozenLines are still
            // computed above exactly as before (the freeze itself, and the
            // $pastLineIds verification, are untouched) — only what gets
            // written into $message has changed.
            $message = $pastLineIds
                ? sprintf('"%s" was permanently deleted. Past receipts and sales records were kept.', $locked->name)
                : sprintf('"%s" was permanently deleted.', $locked->name);

            return $this->result(self::DELETED, $locked, $message);
        });

        $outcome['cleanup_problem'] = $outcome['action'] === self::DELETED
            ? $this->removeMenuItemImage($outcome['model'])
            : null;

        return $outcome;
    }

    /**
     * Remove a permanently-deleted item's image file. Runs only after the row
     * is gone and committed.
     *
     * Returns null when there was nothing to do or it worked, otherwise a
     * sentence saying why the file is still there. Two cases deliberately
     * leave the file alone rather than guess: another menu item row still
     * points at the same path (never pull an image out from under a live
     * item), and a path that does not resolve inside public/uploads/menu-items
     * (where every menu image upload is written) — the stored path comes from
     * the database, and an irreversible unlink should not trust it blindly.
     */
    private function removeMenuItemImage(MenuItem $deleted): ?string
    {
        $image = $deleted->image;

        if (!$image) {
            return null;
        }

        if (MenuItem::withArchived()->where('image', $image)->exists()) {
            return null;
        }

        $path = realpath(public_path($image));

        if ($path === false) {
            return null;
        }

        $uploads = realpath(public_path('uploads/menu-items'));

        if ($uploads === false || !str_starts_with($path, $uploads . DIRECTORY_SEPARATOR)) {
            return sprintf(
                'The image file for "%s" was left in place because it is not in the menu image folder.',
                $deleted->name
            );
        }

        if (!@unlink($path)) {
            \Illuminate\Support\Facades\Log::warning('Menu item image cleanup failed after permanent delete', [
                'menu_item_id' => $deleted->id,
                'image' => $image,
            ]);

            return sprintf(
                'The menu item "%s" is deleted, but its image file (%s) could not be removed '
                . 'from the server. It is no longer used by anything and can be removed by hand.',
                $deleted->name,
                $image
            );
        }

        return null;
    }

    // ── Menu options ─────────────────────────────────────────────────────────

    /**
     * Referenced by order_item_options (ON DELETE RESTRICT) — the exact
     * constraint that used to produce an unhandled QueryException.
     *
     * MENU-ITEM ASSIGNMENTS NOW COUNT AS A REFERENCE TOO (2026-09-02), which
     * is a deliberate departure from the "a record's own children cascade
     * with it" rule in this class's header — and only for options.
     *
     * WHY. That rule is right for a menu item's own recipe lines, which are
     * meaningless without the item. It was wrong here. An option's
     * menu_item_options rows are not disposable children: they are the
     * admin's configuration work — which of the menu's items this add-on may
     * be chosen on — and menu_item_options.menu_option_id is ON DELETE
     * CASCADE (verified against the live schema, not assumed). So trashing an
     * option that was assigned to items but had never happened to be ordered
     * hard-deleted it AND silently took every assignment with it. Proven
     * before changing anything: an option on two items with no order history
     * went row-gone with its pivot count 2 -> 0.
     *
     * That made restoring impossible in exactly the case where restoring is
     * most useful, and it was invisible at the moment of deletion — the same
     * click archived safely or destroyed permanently depending only on
     * whether a customer had ever picked the add-on.
     *
     * So an option is now archived when EITHER kind of reference exists, and
     * hard-deleted only when nothing points at it at all — a genuinely unused
     * add-on, which stays a real delete. Archiving never touches the pivot
     * rows (it only stamps archived_at), so assignments survive and restore
     * brings them back with no re-assigning by hand.
     *
     * Deliberately NOT changed for menu items, categories or subcategories.
     */
    private function removeMenuOption(MenuOption $option): array
    {
        $orderLines = DB::table('order_item_options')->where('menu_option_id', $option->id)->count();
        $itemLinks = DB::table('menu_item_options')->where('menu_option_id', $option->id)->count();

        if ($orderLines > 0 || $itemLinks > 0) {
            $option->archive();

            return $this->result(self::ARCHIVED, $option, sprintf(
                'Add-on "%s" was archived, not deleted — %s. It can no longer be added to '
                . 'any item, and restoring it from Archived items brings back everything '
                . 'it was assigned to.',
                $option->name,
                $this->optionReferenceSummary($orderLines, $itemLinks)
            ));
        }

        $name = $option->name;
        $option->delete();

        return $this->result(self::DELETED, $option, sprintf(
            'Add-on "%s" was permanently deleted. No order had ever used it and it was '
            . 'not assigned to any menu item.',
            $name
        ));
    }

    /**
     * Say which of the two reasons actually applied, so the admin can tell an
     * add-on kept for receipts apart from one kept for its assignments.
     */
    private function optionReferenceSummary(int $orderLines, int $itemLinks): string
    {
        if ($orderLines > 0 && $itemLinks > 0) {
            return sprintf(
                'customers chose it on %s, and it is still assigned to %s',
                $this->plural($orderLines, 'past order line'),
                $this->plural($itemLinks, 'menu item')
            );
        }

        if ($orderLines > 0) {
            return sprintf(
                'customers chose it on %s and those receipts still have to show it',
                $this->plural($orderLines, 'past order line')
            );
        }

        return sprintf(
            'it is still assigned to %s, and deleting it would throw those assignments away',
            $this->plural($itemLinks, 'menu item')
        );
    }

    // ── Categories ───────────────────────────────────────────────────────────

    /**
     * A category is not referenced by orders at all — order history reads
     * categories through a raw join on menu_items, and the receipt never shows
     * one. What it holds is menu items, and menu_items.category_id is
     * ON DELETE CASCADE, which is the dangerous part: hard-deleting a category
     * that still holds items would take those items with it, and with them the
     * link every past order line has to what was sold.
     *
     * So the reference that matters is "does it still hold any menu items":
     *
     *   no items at all           -> HARD DELETE. Nothing to lose.
     *   only ARCHIVED items left  -> ARCHIVE. This is the dead end the owner hit:
     *                                they could not delete "ice cream" because of
     *                                the one item they had already tried to
     *                                remove. Archiving is correct rather than
     *                                deleting, because those archived items are
     *                                being kept precisely so history stays
     *                                readable, and a CASCADE would destroy them.
     *   any LIVE items            -> BLOCKED, with the count and what to do.
     *                                Not a dead end: the items are right there
     *                                on the same page, and removing each of them
     *                                now genuinely resolves (delete or archive).
     *
     * Subcategories are the category's own children and are archived with it,
     * so the Subcategories list is never left showing an orphan filed under a
     * parent that is no longer there.
     */
    private function removeCategory(Category $category): array
    {
        $live = MenuItem::where('category_id', $category->id)->count();
        $archived = MenuItem::onlyArchived()->where('category_id', $category->id)->count();

        if ($live > 0) {
            return $this->result(self::BLOCKED, $category, sprintf(
                'Cannot remove category "%s" yet — %s still filed under it. Delete or '
                . 'archive those first, then remove the category.',
                $category->name,
                $this->plural($live, 'menu item is', 'menu items are')
            ));
        }

        if ($archived > 0) {
            $category->archive();

            $subcategories = Subcategory::where('category_id', $category->id)->get();

            foreach ($subcategories as $subcategory) {
                $subcategory->archive();
            }

            return $this->result(self::ARCHIVED, $category, sprintf(
                'Category "%s" was archived, not deleted — %s still filed under it, '
                . 'being kept so old receipts and sales figures stay correct.%s '
                . 'It is gone from the menu, and you can restore it from Archived items.',
                $category->name,
                $this->plural($archived, 'archived menu item is', 'archived menu items are'),
                $subcategories->isEmpty()
                    ? ''
                    : ' ' . ucfirst($this->plural($subcategories->count(), 'subcategory was', 'subcategories were'))
                        . ' archived with it.'
            ));
        }

        $name = $category->name;

        // No menu items at all, live or archived. Subcategories under it are
        // pure filing with nothing in them, so they go with it.
        Subcategory::withArchived()->where('category_id', $category->id)->delete();

        $category->delete();

        return $this->result(self::DELETED, $category, sprintf(
            'Category "%s" was permanently deleted. It had no menu items in it.',
            $name
        ));
    }

    // ── Subcategories ────────────────────────────────────────────────────────

    /**
     * menu_items.subcategory_id is ON DELETE SET NULL, so a subcategory holds
     * nothing that could be lost — it is filing, not history. There is
     * therefore no reason to ever block one, and no reason to leave the owner
     * stuck.
     *
     *   no items at all      -> HARD DELETE.
     *   any items, live or archived -> ARCHIVE. Nothing is silently un-filed:
     *                           the items keep pointing at it, live items stay
     *                           orderable (a subcategory is not what makes an
     *                           item sellable), and it is one click to restore.
     */
    private function removeSubcategory(Subcategory $subcategory): array
    {
        $live = MenuItem::where('subcategory_id', $subcategory->id)->count();
        $archived = MenuItem::onlyArchived()->where('subcategory_id', $subcategory->id)->count();

        if ($live + $archived > 0) {
            $subcategory->archive();

            return $this->result(self::ARCHIVED, $subcategory, sprintf(
                'Subcategory "%s" was archived, not deleted — %s still filed under it, '
                . 'and deleting it would quietly un-file %s. It is out of the '
                . 'Subcategories list and restorable from Archived items.',
                $subcategory->name,
                $this->plural($live + $archived, 'menu item is', 'menu items are'),
                $live + $archived === 1 ? 'that item' : 'those items'
            ));
        }

        $name = $subcategory->name;
        $subcategory->delete();

        return $this->result(self::DELETED, $subcategory, sprintf(
            'Subcategory "%s" was permanently deleted. It had no menu items in it.',
            $name
        ));
    }

    // ── Restore ──────────────────────────────────────────────────────────────

    /**
     * Bring a record back to the normal list.
     *
     * Restoring a menu item whose category or subcategory was archived in the
     * meantime restores the parents too, and says so. The alternative — putting
     * the item back into a list while the customer menu still cannot show it,
     * because the menu is built by joining categories — is a half-restore that
     * looks like a bug. Restoring a parent never touches its children: an
     * archived item under a restored category is still archived on purpose.
     *
     * RE-READ UNDER A LOCK FIRST (2026-09-24). $record was resolved by the
     * caller before this transaction began, so it may already be stale: a
     * permanent delete that committed in between used to leave this method
     * un-archiving the item's category and reporting "was restored" for a row
     * that no longer existed (unarchive() is a plain save(), which does not
     * notice that its UPDATE matched nothing). The first statement now locks
     * the row from the archived scope — the same lock
     * permanentlyDeleteMenuItem() takes — so the two serialise: whichever
     * commits second sees the other's result. Nothing else is touched unless
     * that re-read succeeds. Shared by all four catalogue types; for them it
     * changes nothing except closing the same race.
     *
     * @return array{action: string, message: string, model: Model}
     */
    public function restore(Model $record): array
    {
        return DB::transaction(function () use ($record) {
            $locked = $record::onlyArchived()
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return $this->result(self::BLOCKED, $record, sprintf(
                    '"%s" is no longer in the archive — it may have been restored or '
                    . 'permanently deleted already. Nothing was changed.',
                    $record->name
                ));
            }

            return $this->restoreLocked($locked);
        });
    }

    /** restore() after its locked re-read has confirmed the row is still archived. */
    private function restoreLocked(Model $record): array
    {
        $alsoRestored = [];

        if ($record instanceof MenuItem) {
            $category = $record->category_id
                ? Category::withArchived()->find($record->category_id)
                : null;

            if ($category && $category->isArchived()) {
                $category->unarchive();
                $alsoRestored[] = 'its category "' . $category->name . '"';
            }

            $subcategory = $record->subcategory_id
                ? Subcategory::withArchived()->find($record->subcategory_id)
                : null;

            if ($subcategory && $subcategory->isArchived()) {
                $subcategory->unarchive();
                $alsoRestored[] = 'its subcategory "' . $subcategory->name . '"';
            }
        }

        if ($record instanceof Subcategory) {
            $category = $record->category_id
                ? Category::withArchived()->find($record->category_id)
                : null;

            if ($category && $category->isArchived()) {
                $category->unarchive();
                $alsoRestored[] = 'its category "' . $category->name . '"';
            }
        }

        $record->unarchive();

        $message = sprintf('"%s" was restored.', $record->name);

        if ($alsoRestored) {
            $message .= ' ' . ucfirst(implode(' and ', $alsoRestored))
                . ' had also been archived, so ' . (count($alsoRestored) === 1 ? 'it was' : 'they were')
                . ' restored too — otherwise it would not have shown on the menu.';
        }

        if ($record instanceof MenuItem && !$record->is_available) {
            $message .= ' It is currently marked Unavailable, so tick Available when you '
                . 'want customers to see it.';
        }

        return $this->result(self::RESTORED, $record, $message);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function result(string $action, Model $model, string $message): array
    {
        return ['action' => $action, 'message' => $message, 'model' => $model];
    }

    private function plural(int $count, string $singular, ?string $plural = null): string
    {
        return $count . ' ' . ($count === 1 ? $singular : ($plural ?: $singular . 's'));
    }
}

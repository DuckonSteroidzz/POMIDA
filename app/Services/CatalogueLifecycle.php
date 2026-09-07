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
 * for menu items, categories and subcategories.
 *
 * Verified against the live schema rather than assumed. The two ON DELETE
 * RESTRICT constraints in the database are precisely the two history links:
 *   order_items.menu_item_id        -> menu_items
 *   order_item_options.menu_option_id -> menu_options
 * Everything else in the catalogue is CASCADE or SET NULL.
 */
class CatalogueLifecycle
{
    public const DELETED  = 'deleted';
    public const ARCHIVED = 'archived';
    public const BLOCKED  = 'blocked';

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
     * Referenced by order_items (ON DELETE RESTRICT). Its recipe lines and
     * option links are its own children and cascade with it.
     */
    private function removeMenuItem(MenuItem $item): array
    {
        $orderLines = DB::table('order_items')->where('menu_item_id', $item->id)->count();

        if ($orderLines > 0) {
            $item->archive();

            return $this->result(self::ARCHIVED, $item, sprintf(
                'Menu item "%s" was archived, not deleted — it appears on %s and those '
                . 'receipts and sales figures have to keep showing it. It is gone from the '
                . 'menu and from every order screen, and you can bring it back any time '
                . 'from Archived items.',
                $item->name,
                $this->plural($orderLines, 'past order line')
            ));
        }

        $name = $item->name;

        if ($item->image && file_exists(public_path($item->image))) {
            @unlink(public_path($item->image));
        }

        $item->delete();

        return $this->result(self::DELETED, $item, sprintf(
            'Menu item "%s" was permanently deleted. Nothing had ever ordered it, so '
            . 'there was no history to keep.',
            $name
        ));
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
     * @return array{message: string, model: Model}
     */
    public function restore(Model $record): array
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

        return ['message' => $message, 'model' => $record];
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

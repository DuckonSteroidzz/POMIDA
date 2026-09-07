<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Archivable — "removed from the business", as distinct from "unavailable today".
 *
 * WHY A GLOBAL SCOPE AND NOT AN OPT-IN ONE
 * ----------------------------------------
 * The requirement that matters most here is that an archived record must not be
 * orderable through ANY entry point: the customer menu, the item-details page,
 * the category listing, add-to-cart, the cart repricing, the admin Manual Order
 * picker, and the branch-scoped variants of all of those. That is a long list
 * today and a longer one tomorrow, and an opt-in `->notArchived()` fails open —
 * forget it on one query and an archived item is quietly orderable again.
 *
 * A global scope fails closed instead. Anything written later is safe without
 * its author knowing this feature exists, and the handful of places that must
 * see archived rows say so explicitly and are covered by tests.
 *
 * WHAT MUST OPT OUT, AND WHY IT IS SAFE TO
 * ----------------------------------------
 * History must never change shape because of a catalogue decision made later.
 * Two relations therefore carry withoutGlobalScope() permanently:
 *
 *   OrderItem::menuItem()   analytics reads the item through it (bestSellers)
 *   OrderItem::options()    the receipt prints $option->name through it
 *
 * Both are order-scoped: they can only ever reach a row some past order already
 * pointed at, so opting out grants no way to reach an archived record through a
 * live, orderable surface. The order line's own item_name / option_name columns
 * are still the authoritative snapshot; these relations just fill in the rest.
 *
 * WHY NOT SoftDeletes
 * -------------------
 * Laravel's SoftDeletes would give the scope for free, but it also redefines
 * `delete()` to mean "soft delete". This lifecycle needs BOTH: a real hard
 * delete when nothing references the row, and archiving when something does.
 * Making delete() secretly not delete would break the hard-delete branch and
 * the graceful-failure guards from the previous round. So `delete()` keeps
 * meaning delete, and archiving is its own verb.
 */
trait Archivable
{
    public static function bootArchivable(): void
    {
        static::addGlobalScope(new NotArchivedScope());
    }

    /** Include archived rows as well as live ones. */
    public function scopeWithArchived(Builder $query): Builder
    {
        return $query->withoutGlobalScope(NotArchivedScope::class);
    }

    /** ONLY archived rows — what the Archived area lists. */
    public function scopeOnlyArchived(Builder $query): Builder
    {
        return $query->withoutGlobalScope(NotArchivedScope::class)
            ->whereNotNull($query->getModel()->getTable() . '.archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Remove from the business. Idempotent: re-archiving keeps the first date. */
    public function archive(): bool
    {
        if ($this->isArchived()) {
            return true;
        }

        $this->archived_at = now();

        return $this->save();
    }

    /**
     * Return to the normal list.
     *
     * Deliberately NOT called restore(): SoftDeletes owns that name, and a
     * model that later adopts SoftDeletes for a different reason would silently
     * collide with it.
     */
    public function unarchive(): bool
    {
        if (!$this->isArchived()) {
            return true;
        }

        $this->archived_at = null;

        return $this->save();
    }
}

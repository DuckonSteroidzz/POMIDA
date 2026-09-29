<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of a sized menu item's two fixed sizes — Regular or Large, nothing else.
 *
 * The database enforces the definition (see the create_menu_item_sizes
 * migration: exact-byte name, CHECK on the (name, display_order) pair, UNIQUE
 * per item). DEFINITIONS below is the same pair list for PHP, so the write
 * path never has to spell a size name or position anywhere else.
 *
 * Lifecycle is Archivable (archived_at + a global scope), the same trait the
 * catalogue rows use — NOT SoftDeletes. is_active is the temporary switch,
 * archived_at the permanent one; either makes a size unorderable and removes
 * it from the starting-price calculation.
 *
 * price is the REAL price of this size. The parent's menu_items.price is only
 * the derived "starting from" figure, kept in step by the saved/deleted hooks
 * below (MenuItem::syncStartingPriceFromSizes()), and is never read back as a
 * size's price.
 *
 * No branch_id: a size belongs to its menu item and inherits its branch.
 */
class MenuItemSize extends Model
{
    use Concerns\Archivable;

    public const REGULAR = 'Regular';
    public const LARGE = 'Large';

    /** The only two sizes that exist, name => display_order, in display order. */
    public const DEFINITIONS = [
        self::REGULAR => 1,
        self::LARGE   => 2,
    ];

    protected $fillable = [
        'menu_item_id',
        'name',
        'price',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'display_order' => 'integer',
        'is_active' => 'boolean',
        'archived_at' => 'datetime',
    ];

    /**
     * Any write to a size — price, active flag, archive, restore, create —
     * re-derives the parent's starting price. A model hook rather than a call
     * in each controller action, so a future write path cannot forget it.
     * (Bulk query-builder updates skip model events; nothing writes sizes
     * that way.)
     */
    protected static function booted(): void
    {
        $sync = function (MenuItemSize $size): void {
            $size->menuItem()->first()?->syncStartingPriceFromSizes();
        };

        static::saved($sync);
        static::deleted($sync);
    }

    /**
     * The parent item, archived or not — a size of an archived item still
     * belongs to it (restoring the item brings the same sizes back).
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class)
            ->withoutGlobalScope(Concerns\NotArchivedScope::class);
    }

    /** This size's recipe — its whole recipe, replacing the item's base one. */
    public function ingredients(): HasMany
    {
        return $this->hasMany(MenuItemSizeIngredient::class);
    }

    /**
     * Sellable as a definition: active and not archived. Says nothing about
     * the recipe or stock — MenuItem::sizeRecipe() adds the recipe check, and
     * the stock functions add the rest.
     */
    public function isLive(): bool
    {
        return (bool) $this->is_active && ! $this->isArchived();
    }
}

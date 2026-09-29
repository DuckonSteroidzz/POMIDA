<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a size's recipe. The size's rows are its WHOLE recipe — they
 * replace menu_item_ingredients when that size is supplied, never add to it.
 *
 * `quantity` is in the unit of the inventory row it points at, exactly like
 * MenuItemIngredient::quantity_used; decimal(12,3), the shelf's own precision.
 */
class MenuItemSizeIngredient extends Model
{
    protected $fillable = [
        'menu_item_size_id',
        'inventory_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function size(): BelongsTo
    {
        return $this->belongsTo(MenuItemSize::class, 'menu_item_size_id')
            ->withoutGlobalScope(Concerns\NotArchivedScope::class);
    }

    // No global scope on Inventory by design (see the Inventory model), so an
    // archived-but-not-deleted ingredient still resolves here, as it does for
    // MenuItemIngredient::inventory().
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}

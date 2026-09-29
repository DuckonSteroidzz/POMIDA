<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a sized order line's recipe, FROZEN when the order was placed
 * (Menu Item Sizes, Phase 2). Copied from the size's recipe
 * (MenuItemSizeIngredient) at that moment and never re-read from it — see the
 * create_order_item_size_ingredients migration for why completion must not
 * re-resolve the live size.
 *
 * `quantity` is per ONE unit of the line, in the inventory row's own unit,
 * decimal(12,3) — the same meaning and precision as
 * MenuItemSizeIngredient::quantity.
 */
class OrderItemSizeIngredient extends Model
{
    protected $fillable = [
        'order_item_id',
        'inventory_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}

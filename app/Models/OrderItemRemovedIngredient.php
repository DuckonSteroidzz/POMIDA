<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One LOW ingredient an order line went out WITHOUT (Defect Report 6) — either
 * the customer ticked "Remove" or the shelf could not cover it. Written when the
 * order is placed, or at completion if the shelf ran short after that, and never
 * changed afterwards.
 *
 * ingredient_name is the snapshot history reads, so a receipt keeps saying the
 * same thing when the recipe changes or the inventory row is renamed or deleted.
 * inventory_id is the link InventoryDeductionService uses to leave the ingredient
 * out of what the line commits and deducts; it goes NULL if that row is
 * permanently deleted (nothing is left to deduct by then), the name stays.
 */
class OrderItemRemovedIngredient extends Model
{
    public const REASON_CUSTOMER = 'customer';

    public const REASON_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'order_item_id',
        'inventory_id',
        'ingredient_name',
        'reason',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /**
     * The one wording for a removal, on the cart and on an order alike:
     * "No Pancake Syrup" when the customer asked, "Pancake Syrup not available"
     * when the shelf decided.
     */
    public static function labelFor(string $name, string $reason): string
    {
        return $reason === self::REASON_UNAVAILABLE
            ? $name . ' not available'
            : 'No ' . $name;
    }

    public function label(): string
    {
        return self::labelFor((string) $this->ingredient_name, (string) $this->reason);
    }
}

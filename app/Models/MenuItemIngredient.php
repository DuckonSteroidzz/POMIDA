<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItemIngredient extends Model
{
    use Concerns\HasIngredientPriority;

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_LOW = 'low';

    protected $fillable = [
        'menu_item_id',
        'inventory_id',
        'quantity_used',
        'priority',
    ];

    protected $attributes = [
        'priority' => self::PRIORITY_HIGH,
    ];

    protected $casts = [
        'quantity_used' => 'decimal:3',
    ];

    /** How much of the inventory row one serving uses — the same question on both recipe tables. */
    public function recipeQuantity(): float
    {
        return (float) $this->quantity_used;
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}

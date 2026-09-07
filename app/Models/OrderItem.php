<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'menu_item_id',
        'item_name',
        'item_price',
        'quantity',
        'subtotal',
        'special_instructions',
        'ingredient_cost',
    ];

    protected $casts = [
        'item_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'ingredient_cost' => 'decimal:2',
    ];

    /**
     * RELATIONSHIPS
     */

    // Belongs to an order
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /*
    |--------------------------------------------------------------------------
    | HISTORY MUST NOT CHANGE SHAPE WHEN THE CATALOGUE DOES
    |--------------------------------------------------------------------------
    |
    | MenuItem and MenuOption carry a global scope that hides archived rows, so
    | that a removed item cannot be ordered from anywhere (see
    | App\Models\Concerns\Archivable). These two relations opt out of it, and
    | must: they are how a PAST order reaches the catalogue.
    |
    | Without the opt-out, archiving an item would silently rewrite history —
    | bestSellers() would drop the item's ->menuItem, and the receipt would stop
    | printing the add-ons the customer actually paid for, because the receipt
    | renders $option->name through the relation below.
    |
    | This is safe rather than a hole in the rule: both relations are reachable
    | only from an order line that already points at the row. There is no path
    | from here to a menu, a cart, or an order form.
    */

    // Belongs to a menu item
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class)
            ->withoutGlobalScope(\App\Models\Concerns\NotArchivedScope::class);
    }

    // Has many options (via order_item_options pivot).
    // withPivot exposes the historical snapshot — the option name + price
    // recorded AT ORDER TIME, even if the MenuOption is later renamed or repriced.
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(MenuOption::class, 'order_item_options')
            ->withoutGlobalScope(\App\Models\Concerns\NotArchivedScope::class)
            ->withPivot('option_name', 'additional_price')
            ->withTimestamps();
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'menu_item_id',
        'menu_item_size_id',
        'item_name',
        'size_name',
        'item_price',
        'quantity',
        'subtotal',
        'special_instructions',
        'ingredient_cost',
        'ingredient_cost_estimated',
    ];

    protected $casts = [
        'item_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'ingredient_cost' => 'decimal:2',
        // True when ingredient_cost was not recorded at the time of sale but
        // frozen from the recipe when the menu item was permanently deleted.
        'ingredient_cost_estimated' => 'boolean',
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

    // Belongs to a menu item — NULL once that item has been permanently
    // deleted (order_items.menu_item_id is ON DELETE SET NULL). The line's
    // own item_name / item_price snapshot is what history reads.
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

    /*
    |--------------------------------------------------------------------------
    | MENU ITEM SIZES (Phase 2)
    |--------------------------------------------------------------------------
    |
    | size_name is the snapshot and THE marker of a sized line: it is written
    | once, when the order is placed, and never changes. menu_item_size_id is
    | only the live link (ON DELETE SET NULL), so it can be NULL on a line
    | that was sold by size. Nothing that decides behaviour or prints history
    | reads the size row itself.
    */

    public function isSized(): bool
    {
        return $this->size_name !== null;
    }

    /**
     * The name every receipt and order list prints: "Iced Latte (Large)" for
     * a sized line, the plain item_name for any other — so an unsized line
     * reads exactly as it always has. Built from the two snapshots only.
     */
    public function displayName(): string
    {
        return $this->size_name !== null
            ? $this->item_name . ' (' . $this->size_name . ')'
            : (string) $this->item_name;
    }

    /**
     * The size recipe frozen when this line was placed — what completion
     * deducts for a sized line, never the size's live recipe. See
     * InventoryDeductionService::requirementsForOrderLine().
     */
    public function sizeIngredients(): HasMany
    {
        return $this->hasMany(OrderItemSizeIngredient::class);
    }

    /** The live size row, archived included; NULL once it is deleted. Reference only. */
    public function size(): BelongsTo
    {
        return $this->belongsTo(MenuItemSize::class, 'menu_item_size_id')
            ->withoutGlobalScope(\App\Models\Concerns\NotArchivedScope::class);
    }
}
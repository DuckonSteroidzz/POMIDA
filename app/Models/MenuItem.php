<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    use HasFactory;

    // "Archived" = removed from the business, as opposed to is_available's
    // "not today". Brings a global scope that hides archived rows from every
    // query — see App\Models\Concerns\Archivable for why it is global.
    use \App\Models\Concerns\Archivable;

    protected $fillable = [
        'category_id',
        'subcategory_id',
        'inventory_item_id',
        'inventory_amount_used',
        'branch_id',
        'name',
        'description',
        'ingredients',
        'price',
        'cost',
        'image',
        'display_order',
        'is_available',
        'is_featured',
        'track_stock',
        'stock_quantity',
        'low_stock_alert',
        'prep_time_minutes',
        'total_sold',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'is_available' => 'boolean',
        'is_featured' => 'boolean',
        'track_stock' => 'boolean',
        'archived_at' => 'datetime',
    ];

    /**
     * RELATIONSHIPS
     */

    // Belongs to a category
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // Belongs to a subcategory (optional)
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    // Belongs to a branch (optional — null = available sa lahat)
    public function branch()
    {
        return $this->belongsTo(\App\Models\Branch::class);
    }

    // Has many available options (via pivot table)
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(MenuOption::class, 'menu_item_options');
    }

    // Has many order items (sales tracking)
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * HELPER METHODS
     */

    // Check if item is in stock (kung naka-track yung stocks)
    public function isInStock(): bool
    {
        if (!$this->track_stock) {
            return true; // Hindi naka-track, always available
        }
        return $this->stock_quantity > 0;
    }

    // Check kung mababa na stock
    public function isLowStock(): bool
    {
        if (!$this->track_stock) {
            return false;
        }
        return $this->stock_quantity <= $this->low_stock_alert;
    }
    /**
     * Linked inventory item (auto-deduct source)
     */
    public function inventoryItem()
    {
        return $this->belongsTo(\App\Models\Inventory::class, 'inventory_item_id');
    }

    // Multi-ingredient recipe: every line is deducted whenever this item is ordered.
    // Named recipeIngredients (not ingredients) because the menu_items table already
    // has a free-text `ingredients` column for the customer-facing item description.
    // Eloquent attribute accessors win over relation accessors, so `->ingredients`
    // would return the text column and crash any ->isEmpty() / ->count() call.
    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(MenuItemIngredient::class);
    }

    /**
     * AUTOMATIC OUT-OF-STOCK — ingredient inventory check.
     *
     * Deliberately SEPARATE from is_available: that flag is the admin's
     * manual "not selling this today" switch and is never touched here. This
     * answers a different question — can the kitchen physically assemble one
     * (or $servings) of this item right now, given what is in the inventory?
     *
     * An item is out of stock when ANY line of its recipe points at an
     * inventory row that is empty (quantity <= 0) or holds less than the
     * recipe needs (quantity < quantity_used * $servings). Items with no
     * recipe and no legacy single-ingredient link are never blocked here —
     * there is nothing to measure them against, so they fall through as
     * "in stock" exactly as the ordering flow already assumes.
     *
     * Reads eager-loaded relations when the caller provides them
     * (`recipeIngredients.inventory`, `inventoryItem`) so a menu grid can
     * call this once per card without an N+1.
     */
    public function hasIngredientStock(int $servings = 1): bool
    {
        $servings = max(1, $servings);

        $recipe = $this->relationLoaded('recipeIngredients')
            ? $this->recipeIngredients
            : $this->recipeIngredients()->with('inventory')->get();

        if ($recipe->isNotEmpty()) {
            foreach ($recipe as $row) {
                $inv = $row->inventory; // eager-loaded on both paths above
                if (!$inv) {
                    // Recipe line points at a missing inventory row — cannot
                    // judge it, so do not block ordering on it.
                    continue;
                }

                $have = (float) $inv->quantity;
                $need = (float) $row->quantity_used * $servings;

                if ($have <= 0 || $have < $need) {
                    return false;
                }
            }

            return true;
        }

        // Legacy single-ingredient link for items that pre-date the recipe table.
        if ($this->inventory_item_id) {
            $inv = $this->relationLoaded('inventoryItem')
                ? $this->inventoryItem
                : $this->inventoryItem()->first();

            if (!$inv) {
                return true;
            }

            $have = (float) $inv->quantity;
            $need = (float) ($this->inventory_amount_used ?: 1) * $servings;

            return $have > 0 && $have >= $need;
        }

        return true;
    }

    /**
     * Customer-facing inverse of hasIngredientStock(): true when the item
     * should show an "Out of Stock" badge and have its order button disabled.
     */
    public function isIngredientOutOfStock(): bool
    {
        return ! $this->hasIngredientStock();
    }

    /**
     * RECIPE GUARD — an item with no recipe cannot be ordered.
     *
     * As of 2026-09-04 (second pass), an item is only orderable if the kitchen
     * has been told how to make it: at least one recipe line
     * (`menu_item_ingredients`) OR the legacy single-ingredient link
     * (`inventory_item_id`). An item with neither has no bill of materials, so
     * ordering it would deduct nothing from inventory and the kitchen would have
     * no instructions — the owner asked for these to read as "not for sale"
     * until a recipe is entered, rather than silently sellable.
     *
     * This is DELIBERATELY computed and never writes `is_available` or
     * `stock_quantity` — same principle as hasIngredientStock() above. The admin
     * toggle stays the admin's; this is the app stating a fact about the item.
     *
     * Reads an eager-loaded `recipeIngredients` relation when the caller
     * provides one (menu grids do), else costs a single EXISTS query.
     */
    public function hasRecipe(): bool
    {
        $hasRecipeLines = $this->relationLoaded('recipeIngredients')
            ? $this->recipeIngredients->isNotEmpty()
            : $this->recipeIngredients()->exists();

        return $hasRecipeLines || (bool) $this->inventory_item_id;
    }

    /**
     * Customer-facing inverse of hasRecipe(): true when the item should show an
     * "Unavailable — No Recipe Set" badge and have its order button disabled.
     */
    public function isMissingRecipe(): bool
    {
        return ! $this->hasRecipe();
    }

    /**
     * The single "can a customer order this right now?" question, folding both
     * guards together: it must have a recipe AND the inventory to cover
     * $servings of it. Used by the cart-add and checkout paths.
     */
    public function isOrderable(int $servings = 1): bool
    {
        return $this->hasRecipe() && $this->hasIngredientStock($servings);
    }

    /**
     * The customer-facing reason this item cannot be ordered, or null when it
     * can. Keeps the "no recipe" and "out of stock" wording in one place so the
     * cart-add guard, the checkout guard and the cart page all agree.
     */
    public function orderBlockedReason(int $servings = 1): ?string
    {
        if ($this->isMissingRecipe()) {
            return 'Sorry, ' . $this->name
                . ' is unavailable right now — no recipe has been set for it yet.';
        }

        if (! $this->hasIngredientStock($servings)) {
            return 'Sorry, ' . $this->name
                . ' is currently out of stock due to ingredient availability.';
        }

        return null;
    }
}

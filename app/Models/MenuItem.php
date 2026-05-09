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
}

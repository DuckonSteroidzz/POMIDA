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
    ];

    protected $casts = [
        'item_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    /**
     * RELATIONSHIPS
     */

    // Belongs to an order
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // Belongs to a menu item
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    // Has many options (via order_item_options pivot)
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(MenuOption::class, 'order_item_options');
    }
}
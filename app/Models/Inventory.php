<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    use HasFactory;

    /**
     * Override default table name
     * (Laravel default = "inventories", pero gusto natin "inventory")
     */
    protected $table = 'inventory';

    protected $fillable = [
        'branch_id',
        'item_name',
        'item_code',
        'category',
        'description',
        'quantity',
        'unit',
        'low_stock_alert',
        'unit_cost',
        'supplier',
        'is_active',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'low_stock_alert' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * RELATIONSHIPS
     */

    // Belongs to a branch
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * HELPER METHODS
     */

    // Check kung mababa na stock
    public function isLowStock(): bool
    {
        return $this->quantity <= $this->low_stock_alert;
    }

    // Check kung out of stock
    public function isOutOfStock(): bool
    {
        return $this->quantity <= 0;
    }
}
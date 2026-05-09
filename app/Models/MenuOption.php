<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MenuOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'additional_price',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'additional_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * RELATIONSHIPS
     */

    // Belongs to many menu items (via pivot table)
    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_options');
    }
}
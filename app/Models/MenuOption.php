<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuOption extends Model
{
    use HasFactory;

    // "Archived" = removed from the business, as opposed to is_available's
    // "not today". Brings a global scope that hides archived rows from every
    // query — see App\Models\Concerns\Archivable for why it is global.
    use \App\Models\Concerns\Archivable;

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
        'archived_at' => 'datetime',
    ];

    /**
     * RELATIONSHIPS
     */

    // Belongs to many menu items (via pivot table)
    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_options');
    }

    // Optional add-on ingredients. Deducted ONLY when this option is selected on an order item.
    public function ingredients(): HasMany
    {
        return $this->hasMany(MenuOptionIngredient::class);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuOptionIngredient extends Model
{
    protected $fillable = [
        'menu_option_id',
        'inventory_id',
        'quantity_used',
    ];

    protected $casts = [
        'quantity_used' => 'decimal:3',
    ];

    public function menuOption(): BelongsTo
    {
        return $this->belongsTo(MenuOption::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}

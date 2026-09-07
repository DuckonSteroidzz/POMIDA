<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subcategory extends Model
{
    use HasFactory;

    // "Archived" = removed from the business, as opposed to is_available's
    // "not today". Brings a global scope that hides archived rows from every
    // query — see App\Models\Concerns\Archivable for why it is global.
    use \App\Models\Concerns\Archivable;

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'image',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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

    // Has many menu items
    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }
}
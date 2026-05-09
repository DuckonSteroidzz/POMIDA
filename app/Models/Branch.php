<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    /**
     * Mass assignable attributes
     */
    protected $fillable = [
        'name',
        'code',
        'address',
        'contact_number',
        'email',
        'opening_time',
        'closing_time',
        'is_active',
        'is_main_branch',
        'image',
    ];

    /**
     * Casting
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_main_branch' => 'boolean',
        'opening_time' => 'datetime:H:i',
        'closing_time' => 'datetime:H:i',
    ];

    /**
     * RELATIONSHIPS
     */

    // Branch has many users (staff/admin assigned dito)
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // Branch has many categories
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    // Branch has many menu items
    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    // Branch has many orders
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // Branch has many inventory items
    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    // Branch has many settings
    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    // Branch has many ads
    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }
}
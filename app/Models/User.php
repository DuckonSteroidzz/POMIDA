<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Mass assignable attributes
     * (Yung mga columns na pwede mag-fill via User::create([...]))
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'contact_number',
        'address',
        'profile_image',
        'branch_id',
        'is_active',
        'verification_code',
        'verified_at',
        'points',
        'pwd_card_number',
        'pwd_name',
        'pwd_image',
        'senior_card_number',
        'senior_name',
        'senior_image',
    ];

    /**
     * Hidden attributes (hindi makikita pag mag-display ng user)
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Attribute casting
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'verified_at' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',  // Auto-encrypt yung password
    ];

    /**
     * RELATIONSHIPS
     */

    // User belongs to a branch
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // User has many orders (customer side)
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // User has many discount cards (PWD/Senior IDs)
    public function discountCards(): HasMany
    {
        return $this->hasMany(DiscountCard::class);
    }

    // User has many vouchers
    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * HELPER METHODS — para easy check ng role
     */

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}

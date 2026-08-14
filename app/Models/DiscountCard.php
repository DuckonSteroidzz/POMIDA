<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'id_number',
        'full_name',
        'id_image',
        'is_verified',
        'verified_at',
        'verified_by',
        'is_active',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * RELATIONSHIPS
     */

    // Belongs to a user (yung may-ari ng card)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Belongs to a verifier (admin/staff na nag-verify)
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
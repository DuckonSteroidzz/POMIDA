<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'description',
        'discount_type',
        'discount_value',
        'max_uses',
        'used_count',
        'minimum_order',
        'expires_at',
        'valid_from',
        'is_active',
        'points_required',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'minimum_order'  => 'decimal:2',
        'expires_at'     => 'datetime',
        'valid_from'     => 'date',
        'is_active'      => 'boolean',
    ];

    public function isValid(): bool
    {
        if (!$this->is_active) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        if ($this->max_uses > 0 && $this->used_count >= $this->max_uses) return false;
        return true;
    }

    // Bagong method — check kung pwede na gamitin ngayon
    public function isRedeemableToday(): bool
    {
        if (!$this->isValid()) return false;
        if (!$this->valid_from) return true;
        // Hindi pwede gamitin sa araw na nakuha (valid_from = tomorrow or later)
        return today()->greaterThanOrEqualTo($this->valid_from);
    }
}

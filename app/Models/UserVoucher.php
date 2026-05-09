<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserVoucher extends Model
{
    protected $fillable = [
        'user_id',
        'voucher_id',
        'acquired_date',
        'is_used',
        'used_at',
    ];

    protected $casts = [
        'acquired_date' => 'date',
        'is_used'       => 'boolean',
        'used_at'       => 'datetime',
    ];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

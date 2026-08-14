<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpRequest extends Model
{
    protected $fillable = [
        'branch_id',
        'order_id',
        'table_number',
        'status',
        'message',
        'requested_at',
        'assisting_at',
        'resolved_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'assisting_at' => 'datetime',
        'resolved_at'  => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}

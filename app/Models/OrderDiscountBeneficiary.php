<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One PWD / Senior Citizen ID listed on an order: ID number + full name.
 *
 * A record for compliance and the receipt only — it carries no money and is
 * never read when an order is priced. See App\Support\DiscountBeneficiaries
 * for how the rows are read from a request, and Order::pwdSeniorDiscountFor()
 * for the one discount an order gets however many rows it lists.
 */
class OrderDiscountBeneficiary extends Model
{
    protected $fillable = [
        'order_id',
        'position',
        'full_name',
        'id_number',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

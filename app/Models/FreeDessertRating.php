<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The customer's own rating and comment for the FREE DESSERT on a completed
 * order — stored apart from the meal's OrderRating, one per order. Written
 * only by OrderController::submitDessertRating(). Nothing is ever awarded for
 * leaving one.
 */
class FreeDessertRating extends Model
{
    protected $fillable = ['order_id', 'order_item_id', 'user_id', 'rating', 'comment'];

    protected $casts = ['rating' => 'integer'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}

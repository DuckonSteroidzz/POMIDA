<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One device that has joined a shared table_sessions occupancy.
 *
 * Purely a visibility record — see the migration that creates this table for
 * why it exists as a child table rather than a counter column. Nothing reads
 * or writes this except App\Services\TableOccupancy; the model itself makes no
 * decisions.
 */
class TableSessionDevice extends Model
{
    protected $fillable = [
        'table_session_id',
        'device_token',
        'last_activity_at',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
    ];

    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }
}

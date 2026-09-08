<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dine-in occupancy of a physical table.
 *
 * A row is "live" while active_lock is non-null. All claiming and releasing goes
 * through App\Services\TableOccupancy — this model is only the record.
 */
class TableSession extends Model
{
    protected $fillable = [
        'branch_id',
        'table_number',
        'session_token',
        'order_id',
        'active_lock',
        'last_seen_at',
        'last_activity_at',
        'released_at',
        'released_by',
        'release_reason',
        'started_ip',
        'opened_by',
    ];

    protected $casts = [
        'last_seen_at'     => 'datetime',
        'last_activity_at' => 'datetime',
        'released_at'      => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * The staff member who opened this occupancy from the counter, if any.
     * Null for the ordinary case: a customer who scanned the table QR.
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** Was this table claimed by staff rather than by a customer scanning in? */
    public function isStaffOpened(): bool
    {
        return $this->opened_by !== null;
    }

    public function isActive(): bool
    {
        return $this->active_lock !== null;
    }
}

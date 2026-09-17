<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * Every device that has joined this occupancy. Purely a visibility record —
     * see App\Services\TableOccupancy for how "currently active" is judged from
     * these rows (last_activity_at against GUEST_IDLE_MINUTES), and the
     * migration that creates table_session_devices for why this is a child
     * table rather than a counter column.
     */
    public function devices(): HasMany
    {
        return $this->hasMany(TableSessionDevice::class);
    }

    public function isActive(): bool
    {
        return $this->active_lock !== null;
    }
}

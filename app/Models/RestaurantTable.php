<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical table, and the permanent code printed on its standee.
 *
 * Allocation and rotation of `code` go through App\Services\TableEntry — this
 * model is only the record. In particular, never write `code` directly: doing
 * so would skip the uniqueness retry and the previous_code audit trail.
 */
class RestaurantTable extends Model
{
    protected $fillable = [
        'branch_id',
        'table_number',
        'code',
        'is_active',
        'previous_code',
        'code_rotated_at',
        'code_rotated_by',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'code_rotated_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** The admin who last rotated this table's code, if anyone ever has. */
    public function rotatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'code_rotated_by');
    }

    /**
     * The URL this table's QR encodes.
     *
     * Kept identical in shape to what the customer entry path already parses,
     * so the printed standee and the scanner cannot drift apart.
     *
     * The permanent code rides along as `k`. It is the single secret guarding
     * BOTH doors now — the typed code and the QR are the same value — so a
     * photograph of an old QR stops working the instant an admin regenerates
     * this table's code, exactly as the typed code already does. `k` is the
     * stored code verbatim: not re-encoded and not re-cased, because
     * App\Services\TableEntry::normalise() folds case and spacing on the way in.
     */
    public function qrUrl(): string
    {
        return url('/customer/menu')
            . '?branch_id=' . $this->branch_id
            . '&table=' . rawurlencode($this->table_number)
            . '&k=' . rawurlencode($this->code);
    }
}

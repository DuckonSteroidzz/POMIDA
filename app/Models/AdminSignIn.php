<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One successful sign-in of a portal account (admin, supervisor, staff).
 * Written by App\Support\SignInRecorder; read by the Recent Sign-ins card on
 * Account Settings, always for the signed-in user's own rows only.
 */
class AdminSignIn extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'signed_in_at',
        'browser',
        'os',
        'device_type',
        'ip_address',
    ];

    protected $casts = [
        'signed_in_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per accepted Spin & Win spin.
 *
 * This is the server-side ledger the spin cap is counted from. The browser
 * reports which wheel segment it landed on, so nothing the browser says about
 * how many times it has spun can be trusted — the count that matters is the
 * number of rows here for the order in question.
 *
 * @property int      $id
 * @property int|null $user_id         NULL for a guest spin.
 * @property int      $order_id        The waiting window this spin was spent on.
 * @property int      $spin_number     1..SPINS_PER_ORDER within that window.
 * @property int      $points_awarded  From AuthController::GAME_POINT_AWARDS.
 */
class GamePlayed extends Model
{
    /**
     * The migration that created this table named it `games_played`, which is
     * not the plural Eloquent would guess from the class name.
     */
    protected $table = 'games_played';

    protected $fillable = [
        'user_id',
        'order_id',
        'spin_number',
        'points_awarded',
    ];

    protected $casts = [
        'user_id'        => 'integer',
        'order_id'       => 'integer',
        'spin_number'    => 'integer',
        'points_awarded' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

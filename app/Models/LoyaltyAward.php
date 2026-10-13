<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bonus paid to one customer. Written only by App\Services\Loyalty.
 *
 * @property int      $id
 * @property int      $user_id
 * @property string   $type       welcome | order_bonus | streak_milestone
 * @property string   $award_key  Unique per (user, type) — see the migration.
 * @property int      $points
 * @property int|null $order_id
 */
class LoyaltyAward extends Model
{
    public const TYPE_WELCOME = 'welcome';
    public const TYPE_ORDER_BONUS = 'order_bonus';
    public const TYPE_STREAK = 'streak_milestone';

    /**
     * A reward earned on the stamp card (Oct 2026; named from when the reward
     * was always a free dessert). points is 0: the reward itself is the
     * free_dessert_rewards row this one links to. Key "visits:<branch>:<n>"
     * for the customer's n-th reward at that branch (App\Services\FreeDesserts;
     * stamp cards are per branch). A legacy "visits:<n>" key whose earning
     * branch was unknown may remain; it is no longer read.
     */
    public const TYPE_FREE_DESSERT = 'free_dessert';

    protected $fillable = [
        'user_id',
        'type',
        'award_key',
        'points',
        'order_id',
    ];

    protected $casts = [
        'user_id'  => 'integer',
        'points'   => 'integer',
        'order_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** What the customer sees in their recent-bonus list. */
    public function label(): string
    {
        return match ($this->type) {
            self::TYPE_WELCOME     => 'Welcome bonus',
            self::TYPE_ORDER_BONUS => 'Completed order bonus',
            self::TYPE_STREAK      => (int) strtok($this->award_key, ':') . '-day streak bonus',
            // A pre-stamp-card "<days>:<date>" key, should one exist, is just
            // "Stamp card reward". (The type keeps its first name, free_dessert.)
            // "visits:<branch>:<n>" -> "#n"; a legacy "visits:<n>" -> "#n".
            self::TYPE_FREE_DESSERT => str_starts_with($this->award_key, 'visits:')
                ? 'Stamp card reward #' . (int) substr($this->award_key, strrpos($this->award_key, ':') + 1)
                : 'Stamp card reward',
            default                => 'Bonus',
        };
    }
}

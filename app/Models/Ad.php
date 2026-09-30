<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ad extends Model
{
    use HasFactory;

    /**
     * The only placements an ad can be given now: the Spin & Win game page and
     * the customer Menu page (shown once per session as a popup — see
     * App\Support\MenuAd). Both admin validators read this list.
     *
     * The `placement` column is still an ENUM of game/menu/cart/orders, and
     * that is deliberate: cart and orders were never displayed anywhere, so
     * ads saved with them (if any exist) are simply never selected by any
     * customer query. They stay in the table, listed in admin with a "no
     * longer used" label, and can be re-pointed at game/menu or deleted.
     * Narrowing the enum would need a migration and buys nothing.
     */
    public const PLACEMENTS = ['game', 'menu'];

    /**
     * Ads that are live at this moment: switched on, started, and not ended.
     *
     * The ONE selection rule for every customer page that shows ads — the game
     * carousel (AuthController::showGame) and the Menu popup (App\Support\MenuAd)
     * both use it, so they cannot drift apart. NULL starts_at / ends_at mean no
     * limit on that side. Branch targeting and placement stay with the caller.
     * Ad::isActive() answers the same question for a single loaded row.
     */
    public function scopeLiveNow($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            });
    }

    /** True for an old cart/orders ad — one no customer page will ever show. */
    public function hasRemovedPlacement(): bool
    {
        return !in_array($this->placement, self::PLACEMENTS, true);
    }

    protected $fillable = [
        // NULL = global (shown at every branch). See Voucher::$fillable.
        'branch_id',
        'title',
        'description',
        'image',
        'link',
        'placement',
        'is_active',
        'display_order',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    /** The branch this ad is scoped to, or null when it is global. */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function isActive(): bool
    {
        if (!$this->is_active) return false;
        if ($this->starts_at && $this->starts_at->isFuture()) return false;
        if ($this->ends_at && $this->ends_at->isPast()) return false;
        return true;
    }
}


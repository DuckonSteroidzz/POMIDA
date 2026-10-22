<?php

namespace App\Models;

use App\Support\ShopDay;
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
     *
     * THE END DATE IS A WHOLE SHOP DAY. The admin form takes a date, stored at
     * 00:00:00 in APP_TIMEZONE; compared as an instant under APP_TIMEZONE=UTC,
     * that ended the ad at 8:00 AM Manila ON its end date. It is now read by
     * the same rule as a voucher's expires_at (App\Support\ShopDay): a
     * midnight value means "live through the end of that date in the shop",
     * any other value is a precise instant. Nothing stored changes.
     *
     * THE START DATE IS A WHOLE SHOP DAY TOO, from the other side: a midnight
     * starts_at begins at 00:00 Manila on that date (ShopDay::hasStartedAt /
     * whereStarted), not at 8:00 AM; a start with a time of day begins at
     * that exact instant.
     */
    public function scopeLiveNow($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => ShopDay::whereStarted($q, $this->qualifyColumn('starts_at')))
            ->where(fn ($q) => ShopDay::whereNotExpired($q, $this->qualifyColumn('ends_at')));
    }

    /** Has this ad's start been reached? See scopeLiveNow() for the rule. */
    public function hasStarted(): bool
    {
        return ShopDay::hasStartedAt($this->starts_at);
    }

    /** Has this ad's end date passed? See scopeLiveNow() for the rule. */
    public function hasEnded(): bool
    {
        return ShopDay::hasExpired($this->ends_at);
    }

    /**
     * The four words the admin Ads list shows in its Status column and offers
     * in its "All statuses" filter (Promotions & Rewards > Ads Management,
     * Defect Report 3 part B). In the reference's order of precedence: an ad
     * switched off is Inactive whatever its dates say; a switched-on ad past
     * its end is Expired; one whose start is still ahead is Scheduled; the rest
     * are Live — exactly the ads scopeLiveNow() hands to customers, so the
     * badge never claims an ad is showing when the Game / Menu page would not
     * show it.
     */
    public const LIST_STATUSES = ['live', 'inactive', 'expired', 'scheduled'];

    public function listStatus(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->hasEnded()) {
            return 'expired';
        }

        if (! $this->hasStarted()) {
            return 'scheduled';
        }

        return 'live';
    }

    /** SQL twin of listStatus(); an unknown or empty status filters nothing. */
    public function scopeWithListStatus($query, ?string $status)
    {
        $notEnded = fn ($q) => ShopDay::whereNotExpired($q, $this->qualifyColumn('ends_at'));
        $started  = fn ($q) => ShopDay::whereStarted($q, $this->qualifyColumn('starts_at'));

        return match ($status) {
            'inactive'  => $query->where('is_active', false),
            'expired'   => $query->where('is_active', true)->whereNot($notEnded),
            'scheduled' => $query->where('is_active', true)->where($notEnded)
                ->whereNotNull('starts_at')->whereNot($started),
            'live'      => $query->liveNow(),
            default     => $query,
        };
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
        if (! $this->hasStarted()) return false;
        if ($this->hasEnded()) return false;
        return true;
    }
}


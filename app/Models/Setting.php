<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'key',
        'value',
        'group',
        'label',
        'description',
        'type',
    ];

    /**
     * RELATIONSHIPS
     */

    // Setting may belong to a specific branch (or null = global)
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * STATIC HELPER METHODS — para easy access sa settings
     */

    // Get a setting value by key
    public static function get(string $key, $default = null, ?int $branchId = null)
    {
        $setting = self::where('key', $key)
                       ->where('branch_id', $branchId)
                       ->first();
        
        return $setting ? $setting->value : $default;
    }

    // Set/update a setting value
    public static function set(string $key, $value, ?int $branchId = null): self
    {
        return self::updateOrCreate(
            ['key' => $key, 'branch_id' => $branchId],
            ['value' => $value]
        );
    }

    /**
     * The Spin & Win on/off switch: ONE global row, branch_id NULL.
     *
     * The owner gets a single switch on the Vouchers page with no branch
     * picker, and AdminController::toggleGame() writes the global row. This
     * reads that same row, and the game page, the Vouchers-page label and the
     * spin endpoint all read it through here. A game_enabled row with a
     * branch_id is not the switch and is ignored. On only when the value is
     * exactly '1'. A missing row is off.
     */
    public const GAME_ENABLED = 'game_enabled';

    public static function gameEnabled(): bool
    {
        return self::get(self::GAME_ENABLED) === '1';
    }

    /**
     * The Streak Calendar & Free Dessert Stamps on/off switch (Oct 2026),
     * built exactly like the Spin & Win switch above: ONE global row,
     * branch_id NULL, written by AdminController::toggleStreakCalendar() from
     * the card under the Spin Wheel card on the Vouchers page.
     *
     * The one difference is the default: this feature shipped switched on, so
     * a MISSING row is ON and only a value of exactly '0' turns it off. No
     * migration or seed row is needed, and deploying changes nothing.
     *
     * Since stamp cards became per branch (Oct 2026) this is the MASTER
     * switch, owner only: each branch also has its own switch
     * (StreakRewardSettings::branchEnabled, owner or that branch's
     * supervisor), and a branch is on only while both are.
     *
     * What OFF means is decided in one place each: Loyalty::orderCompleted()
     * pays no streak milestone bonus (company-wide), FreeDesserts::active()
     * (earning and every stamp-card view, at every branch) is false, and the
     * pop-up stays quiet. Rewards already earned stay spendable at the cart,
     * which reads none of the switches.
     */
    public const STREAK_CALENDAR_ENABLED = 'streak_calendar_enabled';

    public static function streakCalendarEnabled(): bool
    {
        return self::get(self::STREAK_CALENDAR_ENABLED) !== '0';
    }
}
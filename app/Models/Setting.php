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
}
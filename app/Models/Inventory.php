<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    use HasFactory;

    /**
     * Override default table name
     * (Laravel default = "inventories", pero gusto natin "inventory")
     */
    protected $table = 'inventory';

    /**
     * Allowed values for the `unit` column. Used by the inventory form dropdown
     * and the controller validation rule so they stay in sync.
     */
    public const ALLOWED_UNITS = ['pc', 'pcs', 'g', 'kg', 'ml', 'L', 'pack', 'bottle', 'can'];

    protected $fillable = [
        'branch_id',
        'item_name',
        'item_code',
        'category',
        'description',
        'quantity',
        'unit',
        'low_stock_alert',
        'unit_cost',
        'supplier',
        'is_active',
    ];

    /**
     * quantity / low_stock_alert are decimal:3, NOT decimal:2.
     *
     * They have to match the column (decimal(12,3) since the Sept 2026
     * widening) and the recipe tables that subtract from them, which have
     * always been decimal(10,3). While this cast said 2, every read of
     * quantity came back already rounded — so deductWithLock()'s
     * `(float) $inv->quantity - $amount` started from a number that had lost
     * the third decimal before the subtraction even happened, and a recipe
     * finer than a hundredth left the shelf sitting exactly where it was.
     * unit_cost stays decimal:2: that one is money.
     */
    protected $casts = [
        'quantity' => 'decimal:3',
        'low_stock_alert' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'is_active' => 'boolean',
        'archived_at' => 'datetime',
    ];

    /**
     * RELATIONSHIPS
     */

    // Belongs to a branch
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * HELPER METHODS
     */

    // Check kung mababa na stock
    public function isLowStock(): bool
    {
        return $this->quantity <= $this->low_stock_alert;
    }

    // Check kung out of stock
    public function isOutOfStock(): bool
    {
        return $this->quantity <= 0;
    }

    /**
     * DELETED ITEMS — two-stage delete.
     *
     * Deliberately NOT App\Models\Concerns\Archivable, and deliberately NOT a
     * global scope. Every scope below is local/opt-in: callers that must keep
     * seeing an archived row for existing recipes, deductions or history
     * (InventoryDeductionService, MenuItemIngredient::inventory(),
     * MenuOptionIngredient::inventory(), MenuItem::inventoryItem(),
     * StockMovement::inventory()) use plain Inventory::find()/whereIn() and
     * are completely unaffected by archiving — exactly the "soft-deleting
     * doesn't break those" requirement this feature was built against. Only
     * the inventory list, its CSV export and the item_code uniqueness check
     * opt in to hiding archived rows, explicitly, each at its own call site.
     */

    /** Only rows still on the normal list. */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** ONLY archived rows — what the Deleted Items page lists. */
    public function scopeOnlyArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Move off the normal list. Idempotent: re-archiving keeps the first date.
     *
     * item_code is NOT NULL and globally UNIQUE with no partial index (see the
     * migration's docblock), so the code is parked in archived_item_code and
     * the live column is mangled — otherwise this row would permanently
     * reserve that code and block ever re-adding the same item.
     */
    public function archive(): bool
    {
        if ($this->isArchived()) {
            return true;
        }

        $this->archived_at = now();
        $this->archived_item_code = $this->item_code;

        // item_code is varchar(50). The "-DEL-{id}" suffix is what makes the
        // mangled code unique (ids never repeat), so it must always survive
        // whole — the ORIGINAL code is truncated to make room for it, not the
        // other way around.
        $suffix = '-DEL-' . $this->id;
        $this->item_code = substr($this->item_code, 0, max(0, 50 - strlen($suffix))) . $suffix;

        return $this->save();
    }

    /**
     * Back to the normal list. Restores the original item_code when nothing
     * else has since claimed it; otherwise keeps the mangled one (still
     * unique, so this never fails) and the caller is told to update it.
     *
     * @return bool  True when the original item_code was restored as well.
     */
    public function unarchive(): bool
    {
        if (!$this->isArchived()) {
            return true;
        }

        $codeRestored = false;

        if ($this->archived_item_code) {
            $taken = self::where('item_code', $this->archived_item_code)
                ->where('id', '!=', $this->id)
                ->exists();

            if (!$taken) {
                $this->item_code = $this->archived_item_code;
                $codeRestored = true;
            }
        }

        $this->archived_at = null;
        $this->archived_item_code = null;
        $this->save();

        return $codeRestored;
    }
}
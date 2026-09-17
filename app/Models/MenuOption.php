<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuOption extends Model
{
    use HasFactory;

    // "Archived" = removed from the business, as opposed to is_available's
    // "not today". Brings a global scope that hides archived rows from every
    // query — see App\Models\Concerns\Archivable for why it is global.
    use \App\Models\Concerns\Archivable;

    protected $fillable = [
        'name',
        'description',
        'additional_price',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'additional_price' => 'decimal:2',
        'is_active' => 'boolean',
        'archived_at' => 'datetime',
    ];

    /**
     * RELATIONSHIPS
     */

    // Belongs to many menu items (via pivot table)
    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_options');
    }

    // Optional add-on ingredients. Deducted ONLY when this option is selected on an order item.
    public function ingredients(): HasMany
    {
        return $this->hasMany(MenuOptionIngredient::class);
    }

    /**
     * Is this option orderable for $branchId?
     *
     * Phase 3 audit, Finding #3 (Sept 2026). menu_options stays a global
     * table — an option can be assigned to menu items in several branches —
     * but branches never share stock, no exceptions, so a global option
     * needs its OWN ingredient link (MenuOptionIngredient) pointing at a
     * given branch's inventory before it can be offered or ordered in that
     * branch. The existing unique(menu_option_id, inventory_id) constraint
     * already permits one option to carry one link per branch; this is the
     * one place that reads it.
     *
     * An option with no link at all for $branchId is UNMAPPED there, not
     * "free" — deliberately, so a misconfigured or not-yet-set-up option
     * cannot silently deduct nothing (or, before this fix, another branch's
     * stock). See InventoryDeductionService::requirementsForLine() for the
     * deduction side of this same rule.
     *
     * A null $branchId (no branch context at all) is never mapped — fails
     * closed rather than guessing which branch's stock to touch.
     *
     * Reads the `ingredients.inventory` relation; eager-load
     * 'ingredients.inventory' before calling this on many options to avoid
     * an N+1.
     */
    public function isMappedForBranch(?int $branchId): bool
    {
        if ($branchId === null) {
            return false;
        }

        $ingredients = $this->relationLoaded('ingredients')
            ? $this->ingredients
            : $this->ingredients()->with('inventory')->get();

        foreach ($ingredients as $row) {
            if ($row->inventory && (int) $row->inventory->branch_id === $branchId) {
                return true;
            }
        }

        return false;
    }
}
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two-stage delete for Inventory: archived_at turns "Delete" into a
     * recoverable soft delete (hidden from the normal list, restorable from
     * Deleted Items) instead of the previous single irreversible removal.
     *
     * NOT the Archivable trait MenuItem/Category/Subcategory/MenuOption use
     * (App\Models\Concerns\Archivable) even though the column name matches it
     * on purpose for consistency. That trait adds a GLOBAL query scope, which
     * is right for catalogue rows a customer must never see again once
     * archived. Inventory is not customer-facing — InventoryDeductionService,
     * MenuItemIngredient::inventory(), MenuOptionIngredient::inventory() and
     * MenuItem::inventoryItem() all look inventory rows up directly by id for
     * live stock math on every order. A global scope would make an archived
     * row invisible to THOSE queries too, so completing an order whose recipe
     * still points at an archived ingredient would throw "Inventory item #N
     * is missing" (InventoryDeductionService::deductWithLock()) instead of
     * quietly working — exactly the kind of breakage the brief asked this
     * pass to avoid. So Inventory gets its own explicit, opt-in scopes
     * instead (see the Inventory model): every read that must keep ignoring
     * archived rows for existing recipes/history stays untouched, and only
     * the inventory list, its CSV export and the item_code uniqueness check
     * explicitly filter archived rows out.
     *
     * archived_item_code holds the item's ORIGINAL item_code while archived.
     * inventory.item_code is a NOT NULL, globally UNIQUE column with no
     * partial/filtered index (confirmed against the live schema — see
     * InventoryItemCodeUniqueTest's own investigation notes), so an archived
     * row would otherwise permanently reserve its code and block re-adding
     * the same item later. Archiving therefore mangles item_code to free it
     * up and parks the original here; restoring puts it back if nothing else
     * has since claimed it (see Inventory::archive()/unarchive()).
     */
    public function up(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('is_active');
            $table->string('archived_item_code', 50)->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'archived_item_code']);
        });
    }
};

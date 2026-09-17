<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * RECIPES ARE FINER THAN THE SHELF THEY DEDUCT FROM.
     *
     * Every number that describes how much of an ingredient something uses is
     * stored to THREE decimals:
     *
     *   menu_item_ingredients.quantity_used    decimal(10,3)
     *   menu_option_ingredients.quantity_used  decimal(10,3)
     *   menu_items.inventory_amount_used       decimal(10,3)
     *   stock_movements.amount                 decimal(10,3)
     *   stock_movements.quantity_after         decimal(10,3)
     *
     * The shelf those numbers are subtracted from was stored to TWO:
     *
     *   inventory.quantity                     decimal(10,2)
     *
     * So InventoryDeductionService::deductWithLock() computed the right answer
     * and MySQL rounded it away on the way to disk. Reproduced end to end
     * against pomida_db_testing (InventoryDeductionEndToEndTest, "a fractional
     * recipe line actually moves the shelf"): a 0.004 L recipe line on a 10 L
     * bottle wrote a perfectly correct stock_movements row saying 0.004 L had
     * left — and left inventory.quantity reading 10.00, exactly what it read
     * before the order. Nothing threw, nothing logged, and the audit trail
     * disagreed with the shelf it was describing. That is the reported
     * "inventory does not get deducted" in its purest form, and every
     * fractional recipe drifted by up to half a hundredth per sale even when
     * it did move.
     *
     * low_stock_alert is widened alongside it because the two are compared
     * directly (Inventory::isLowStock()) and a threshold that cannot express
     * the same precision as the quantity it guards would make the low-stock
     * badge lie at the same fractions.
     *
     * Widening only — decimal(10,2) -> decimal(12,3) is loss-free for every
     * value already stored, and the extra two integer digits keep the existing
     * headroom (10,2 held 8 integer digits; 12,3 holds 9).
     *
     * Raw SQL rather than $table->decimal(...)->change() on purpose: ->change()
     * needs doctrine/dbal, which this project does not install.
     */
    public function up(): void
    {
        // Null-ability and DEFAULTs are restated exactly as the live schema
        // already has them (quantity 0.00, low_stock_alert 10.00): MySQL's
        // MODIFY replaces the whole column definition, so anything left out
        // here would be silently dropped.
        DB::statement('ALTER TABLE `inventory` MODIFY `quantity` DECIMAL(12,3) NOT NULL DEFAULT 0.000');
        DB::statement('ALTER TABLE `inventory` MODIFY `low_stock_alert` DECIMAL(12,3) NOT NULL DEFAULT 10.000');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `inventory` MODIFY `quantity` DECIMAL(10,2) NOT NULL DEFAULT 0.00');
        DB::statement('ALTER TABLE `inventory` MODIFY `low_stock_alert` DECIMAL(10,2) NOT NULL DEFAULT 10.00');
    }
};

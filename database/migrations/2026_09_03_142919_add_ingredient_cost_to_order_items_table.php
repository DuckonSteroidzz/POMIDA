<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historical COGS snapshot.
     *
     * Stores the single-unit recipe cost AT THE MOMENT stock was deducted for
     * this order line (see InventoryDeductionService::deductWithLock()). This
     * is deliberately separate from inventory.unit_cost / menu_items.cost so
     * that a future price change never rewrites a past order's profit figures.
     *
     * Nullable + default 0.00: legacy rows created before this column existed
     * stay NULL, which ProfitCalculationService treats as "unknown, fall back
     * to today's menu item cost" rather than "cost was zero".
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('ingredient_cost', 10, 2)->nullable()->default(0.00)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('ingredient_cost');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Defect Report 6 — the INGREDIENT PRIORITY system (High / Low).
 *
 * priority on the two live recipe tables, default 'high' and NOT NULL: every
 * recipe line that already exists becomes HIGH, which is exactly how it behaves
 * today, so nothing changes until someone marks a line Low. Add-on recipes
 * (menu_option_ingredients) are deliberately not part of this.
 *
 * priority on order_item_size_ingredients too, because that table is a frozen
 * COPY of the size recipe: completion reads it and never the live size, so it
 * has to carry the level the line had when it was sold. Rows frozen before this
 * migration become 'high' — they complete exactly as they were sold.
 *
 * order_item_removed_ingredients — the per-order-line snapshot of what a line
 * went out WITHOUT, and why. A row per ingredient rather than a JSON column, the
 * same choice order_item_options and order_item_size_ingredients made, so it has
 * real foreign keys:
 *   order_item_id  ON DELETE CASCADE: the snapshot goes with its line.
 *   inventory_id   ON DELETE SET NULL: only the live link. It is what
 *                  InventoryDeductionService leaves out of the line's commitment
 *                  and deduction; once the inventory row is permanently deleted
 *                  there is nothing left to deduct, and ingredient_name (the
 *                  snapshot) keeps the receipt correct.
 * UNIQUE(order_item_id, inventory_id) so a line cannot list one ingredient twice.
 * NULLs do not collide, so any number of rows can lose their inventory link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_item_ingredients', function (Blueprint $table) {
            $table->enum('priority', ['high', 'low'])->default('high')->after('quantity_used');
        });

        Schema::table('menu_item_size_ingredients', function (Blueprint $table) {
            $table->enum('priority', ['high', 'low'])->default('high')->after('quantity');
        });

        Schema::table('order_item_size_ingredients', function (Blueprint $table) {
            $table->enum('priority', ['high', 'low'])->default('high')->after('quantity');
        });

        Schema::create('order_item_removed_ingredients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('inventory_id')->nullable();
            $table->string('ingredient_name');
            $table->enum('reason', ['customer', 'unavailable']);
            $table->timestamps();

            $table->foreign('order_item_id')->references('id')->on('order_items')->onDelete('cascade');
            $table->foreign('inventory_id')->references('id')->on('inventory')->onDelete('set null');

            $table->unique(['order_item_id', 'inventory_id']);
            $table->index('inventory_id');
        });
    }

    /**
     * Refuses before any DDL while the data would be lost: dropping these would
     * turn every Low ingredient back into High and rewrite what past receipts
     * and open orders say was left out.
     */
    public function down(): void
    {
        $lowLines = DB::table('menu_item_ingredients')->where('priority', 'low')->count()
            + DB::table('menu_item_size_ingredients')->where('priority', 'low')->count()
            + DB::table('order_item_size_ingredients')->where('priority', 'low')->count();

        $removed = Schema::hasTable('order_item_removed_ingredients')
            ? DB::table('order_item_removed_ingredients')->count()
            : 0;

        if ($lowLines > 0 || $removed > 0) {
            throw new \RuntimeException(sprintf(
                'Refusing to roll back the ingredient priority migration: %d recipe line(s) are Low and %d '
                . 'order line removal(s) are recorded. Nothing was changed. Restore from a database backup instead.',
                $lowLines,
                $removed
            ));
        }

        Schema::dropIfExists('order_item_removed_ingredients');

        Schema::table('order_item_size_ingredients', function (Blueprint $table) {
            $table->dropColumn('priority');
        });

        Schema::table('menu_item_size_ingredients', function (Blueprint $table) {
            $table->dropColumn('priority');
        });

        Schema::table('menu_item_ingredients', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};

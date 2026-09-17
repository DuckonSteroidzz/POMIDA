<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PERMANENT DELETE vs stock movement history.
     *
     * stock_movements.inventory_id was ON DELETE CASCADE (original migration,
     * 2026_04_29_221341). Hard-deleting an inventory row therefore silently
     * destroyed every stock-in/stock-out log entry that ever referenced it —
     * confirmed against the live migration, not assumed, and the existing
     * deleteInventory() had no warning for this at all (unlike the recipe-link
     * cascade it did warn about). That is real audit history — the log's own
     * copy on the Inventory page says "these rows are what explains the
     * current stock count" — so it must not vanish just because the item
     * definition was cleaned up later.
     *
     * Changed to ON DELETE SET NULL, with a new deleted_item_name snapshot
     * column the permanent-delete action fills in just before deleting the
     * inventory row (see AdminController::forceDeleteInventory()). The
     * movement rows survive with inventory_id NULL and still say what they
     * were for — "kept the historical record but mark the reference as
     * belonging to a deleted item", per the brief.
     *
     * No doctrine/dbal in this project (checked vendor/doctrine — only
     * inflector and lexer are installed), so Blueprint::change() is not
     * available here; the column is widened to nullable with a raw ALTER
     * instead, matching the type MySQL already reports for it
     * (BIGINT UNSIGNED, from the original migration's unsignedBigInteger()).
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['inventory_id']);
        });

        DB::statement('ALTER TABLE stock_movements MODIFY inventory_id BIGINT UNSIGNED NULL');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('deleted_item_name')->nullable()->after('inventory_id');
            $table->foreign('inventory_id')->references('id')->on('inventory')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['inventory_id']);
            $table->dropColumn('deleted_item_name');
        });

        DB::statement('ALTER TABLE stock_movements MODIFY inventory_id BIGINT UNSIGNED NOT NULL');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign('inventory_id')->references('id')->on('inventory')->onDelete('cascade');
        });
    }
};

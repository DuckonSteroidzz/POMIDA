<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Item Sizes, Phase 2 — which size an order line was sold as.
 *
 * Mirrors the line's existing item snapshot (item_name / item_price, written
 * once when the order is placed and never re-read from the catalogue):
 *
 *   menu_item_size_id  the live link, NULLABLE + ON DELETE SET NULL — the same
 *                      policy order_items.menu_item_id already has
 *                      (2026_09_24_000000). Order history must never block
 *                      deleting a size, and deleting one never deletes history.
 *                      Archiving a size does not touch it at all.
 *   size_name          the snapshot ('Regular' / 'Large'), what every receipt
 *                      and order list prints. It is also THE marker of a sized
 *                      line: menu_item_size_id can go NULL later, size_name
 *                      cannot. NULL = an unsized line, exactly as every line
 *                      written before this migration.
 *
 * The line's frozen recipe lives in order_item_size_ingredients (next
 * migration). Nothing here is back-filled: every existing line is unsized.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('menu_item_size_id')->nullable()->after('menu_item_id');
            // Same width as menu_item_sizes.name.
            $table->string('size_name', 20)->nullable()->after('item_name');

            $table->foreign('menu_item_size_id')
                ->references('id')->on('menu_item_sizes')
                ->onDelete('set null');
        });
    }

    /**
     * Refuses BEFORE any DDL while a sized line exists: dropping these columns
     * would silently turn a sold "Large" back into an anonymous line on every
     * receipt and order list, and there is no other record of which size it was.
     */
    public function down(): void
    {
        $sized = DB::table('order_items')->whereNotNull('size_name')->count();

        if ($sized > 0) {
            throw new \RuntimeException(sprintf(
                'Refusing to drop order_items.size_name / menu_item_size_id: %d order line(s) were sold '
                . 'by size, and this is the only record of which size. Nothing was changed. '
                . 'Restore from a database backup instead.',
                $sized
            ));
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['menu_item_size_id']);
            $table->dropColumn(['menu_item_size_id', 'size_name']);
        });
    }
};

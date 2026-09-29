<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PERMANENT DELETE FOR SOLD MENU ITEMS (Phase 2).
     *
     * order_items.menu_item_id was NOT NULL with a foreign key that had no
     * ON DELETE clause (RESTRICT), so a menu item that had ever been sold could
     * never be removed. It becomes NULLABLE + ON DELETE SET NULL: deleting the
     * menu item keeps every past order line and only cuts its live link. The
     * line still carries its own item_name / item_price / subtotal snapshot,
     * and every receipt, report and export reads those, not the live row.
     *
     * The only path that deletes a menu_items row is
     * CatalogueLifecycle::permanentlyDeleteMenuItem(), which refuses while
     * the item is on an open (pending/preparing/serving) order — the database
     * no longer does that for it.
     *
     * ingredient_cost_estimated marks a line whose ingredient_cost was not
     * recorded at the time of sale but frozen from the menu item's recipe at
     * the moment the item was permanently deleted (the same figure the profit
     * report was already showing for it). Reports disclose those lines.
     *
     * Same shape as 2026_09_16_000001 (stock_movements): no doctrine/dbal is
     * installed, so the column is widened with a raw ALTER. dropForeign()
     * keeps the index order_items_menu_item_id_foreign, and re-adding the key
     * under the same name reuses it (verified on MariaDB 10.4).
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['menu_item_id']);
        });

        DB::statement('ALTER TABLE order_items MODIFY menu_item_id BIGINT UNSIGNED NULL');

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('menu_item_id')->references('id')->on('menu_items')->onDelete('set null');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('ingredient_cost_estimated')->default(false)->after('ingredient_cost');
        });
    }

    /**
     * Refuses BEFORE touching anything once a sold item has been permanently
     * deleted. MySQL DDL is not transactional: if MODIFY ... NOT NULL failed
     * after dropForeign() had run (it does, on any NULL row), the table would
     * be left with no foreign key at all. And there is nothing valid to put
     * back — the menu item rows those lines pointed at no longer exist.
     */
    public function down(): void
    {
        $this->refuseIfRollbackWouldLoseHistory();

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('ingredient_cost_estimated');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['menu_item_id']);
        });

        DB::statement('ALTER TABLE order_items MODIFY menu_item_id BIGINT UNSIGNED NOT NULL');

        Schema::table('order_items', function (Blueprint $table) {
            // The original key: no ON DELETE clause, i.e. RESTRICT.
            $table->foreign('menu_item_id')->references('id')->on('menu_items');
        });
    }

    public function refuseIfRollbackWouldLoseHistory(): void
    {
        $detached = DB::table('order_items')->whereNull('menu_item_id')->count();

        $estimated = Schema::hasColumn('order_items', 'ingredient_cost_estimated')
            ? DB::table('order_items')->where('ingredient_cost_estimated', true)->count()
            : 0;

        if ($detached > 0 || $estimated > 0) {
            throw new \RuntimeException(sprintf(
                'Refusing to roll back order_items.menu_item_id to NOT NULL + RESTRICT: %d order line(s) '
                . 'belong to permanently deleted menu items and %d carry an estimated ingredient cost. '
                . 'Their menu item rows no longer exist, so there is nothing valid to restore. '
                . 'Nothing was changed. Restore from a database backup instead.',
                $detached,
                $estimated
            ));
        }
    }
};

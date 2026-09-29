<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Item Sizes, Phase 2 — a sized order line's recipe, FROZEN when the
 * order is placed.
 *
 * Completion deducts these rows, never the size's live recipe: an order
 * already in the pipeline must complete exactly as it was sold even if the
 * size is archived, deactivated, deleted, or has its recipe changed in the
 * meantime. Re-resolving the live size at completion would throw for an
 * archived/inactive size (MenuItem::sizeRecipe() refuses them, correctly, for
 * NEW orders) and would deduct a changed recipe for an old one.
 *
 * Shape: the size recipe's own row (inventory_id + quantity decimal(12,3),
 * per ONE unit — completion multiplies by order_items.quantity exactly as
 * requirementsForLine() does), hung off the order line the way
 * order_item_options already is. No JSON column: nothing in this schema uses
 * one, and a JSON blob has no foreign key, so a permanently deleted inventory
 * row would stay referenced and make deductWithLock() throw
 * "Inventory item #N is missing" — i.e. block completion, the exact failure
 * this table exists to prevent.
 *
 * Only the SIZE recipe is frozen. Add-ons keep today's per-branch live
 * resolution for every line, sized or not, so an add-on deducts identically
 * whichever size it rides on.
 *
 * Foreign keys, both ON DELETE CASCADE:
 *   order_item_id  the snapshot goes with its line (and the line with its
 *                  order), as order_item_options does.
 *   inventory_id   the policy of all three recipe tables
 *                  (menu_item_ingredients, menu_option_ingredients,
 *                  menu_item_size_ingredients): a permanently deleted
 *                  inventory row leaves nothing on any shelf to deduct from,
 *                  so its line disappears rather than blocking completion.
 *
 * There is deliberately NO key to menu_item_sizes: the frozen rows must
 * outlive the size row itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_size_ingredients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('inventory_id');
            $table->decimal('quantity', 12, 3);
            $table->timestamps();

            $table->foreign('order_item_id')->references('id')->on('order_items')->onDelete('cascade');
            $table->foreign('inventory_id')->references('id')->on('inventory')->onDelete('cascade');

            $table->unique(['order_item_id', 'inventory_id']);
            $table->index('inventory_id');
        });
    }

    /**
     * Runs BEFORE the size-snapshot migration's own down() on a rollback, so it
     * must refuse on its own: dropping this table first would destroy every
     * open sized order's frozen recipe even though that down() then refuses.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_item_size_ingredients')) {
            $rows = DB::table('order_item_size_ingredients')->count();

            if ($rows > 0) {
                throw new \RuntimeException(sprintf(
                    'Refusing to drop order_item_size_ingredients: it holds %d frozen recipe row(s) for '
                    . 'order lines sold by size. Nothing was changed. Restore from a database backup instead.',
                    $rows
                ));
            }
        }

        Schema::dropIfExists('order_item_size_ingredients');
    }
};

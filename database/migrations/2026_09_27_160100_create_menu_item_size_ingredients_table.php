<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Item Sizes, Phase 1 — the recipe of one size.
 *
 * REPLACES, never adds to, the item's base recipe (menu_item_ingredients):
 * when a size is supplied to the inventory functions, these rows are the
 * whole bill of materials. A size with NO rows here has no recipe and is not
 * orderable ("No Recipe Set") — it never falls back to the base recipe.
 *
 * quantity is decimal(12,3): the same precision as the shelf it is subtracted
 * from (inventory.quantity, see 2026_09_16_000002), so a fractional line can
 * never be rounded away on write.
 *
 * Foreign keys, both ON DELETE CASCADE — the project's existing policy for
 * recipe links, not a new one:
 *   menu_item_size_id  a size's recipe goes with the size (and so with the
 *                      parent menu item, which cascades to its sizes).
 *   inventory_id       identical to menu_item_ingredients / menu_option_ingredients:
 *                      permanently deleting an inventory row removes the recipe
 *                      lines that pointed at it (the Deleted Items page's
 *                      permanent delete is owner-only and happens after an
 *                      archive step). No orphan row can exist. If that removes a
 *                      size's LAST line, the size becomes "No Recipe Set" and
 *                      stops being orderable — it does not silently deduct nothing.
 *
 * UNIQUE (menu_item_size_id, inventory_id): one line per ingredient per size,
 * the same rule the other two recipe tables enforce, and the index every
 * "recipe of this size" read uses. inventory_id gets its own index for the FK
 * and for "which recipes use this ingredient".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_size_ingredients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('menu_item_size_id');
            $table->unsignedBigInteger('inventory_id');
            $table->decimal('quantity', 12, 3);
            $table->timestamps();

            $table->foreign('menu_item_size_id')->references('id')->on('menu_item_sizes')->onDelete('cascade');
            $table->foreign('inventory_id')->references('id')->on('inventory')->onDelete('cascade');

            $table->unique(['menu_item_size_id', 'inventory_id']);
            $table->index('inventory_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_size_ingredients');
    }
};

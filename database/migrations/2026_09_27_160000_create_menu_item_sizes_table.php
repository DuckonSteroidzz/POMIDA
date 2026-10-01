<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Item Sizes, Phase 1 — the two fixed size definitions.
 *
 * A menu item is either UNSIZED (no rows here — it behaves exactly as it
 * always has: menu_items.price, menu_item_ingredients) or SIZED, in which case
 * it has exactly two rows here: Regular and Large. There is no third size and
 * no custom name, and the database says so rather than trusting the form:
 *
 *   name          utf8mb4_bin - case-sensitive binary comparison.
 *                 The CHECK constraint below restricts the value to exactly
 *                 'Regular' or 'Large' together with its required display
 *                 order.
 *   CHECK         (name, display_order) is one of exactly two pairs:
 *                 ('Regular', 1) or ('Large', 2). Pins both the allowed names
 *                 AND their order, so the ordering can never be edited into an
 *                 arbitrary ranking.
 *   UNIQUE        (menu_item_id, name) — at most one Regular and one Large per
 *                 item. With the CHECK above that means at most two rows per
 *                 item, ever. The "exactly two, never one" half is the write
 *                 path's job (App\Services\MenuItemSizes::enable() creates both
 *                 in one transaction, and there is no endpoint that deletes a
 *                 single size — sizes are archived, never removed alone).
 *
 * No branch_id. A size is part of its menu item and inherits the item's
 * branch; the parent row is the branch boundary, exactly as it already is for
 * menu_item_ingredients.
 *
 * archived_at follows the Archivable pattern (App\Models\Concerns\Archivable):
 * a nullable timestamp, not SoftDeletes. It is NOT indexed, unlike the
 * catalogue tables' archived_at: sizes are only ever read through their
 * parent (the unique index's leading menu_item_id column), and filtering at
 * most two rows by archived_at needs no index of its own.
 *
 * ON DELETE CASCADE from menu_items: a permanently deleted item takes its
 * sizes with it (and, through the next migration, their recipes) — the same
 * rule menu_item_ingredients and menu_item_options already follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_sizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('menu_item_id');
            $table->string('name', 20)->collation('utf8mb4_bin');
            $table->decimal('price', 10, 2);
            $table->integer('display_order');
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('menu_item_id')->references('id')->on('menu_items')->onDelete('cascade');

            // Also serves every "sizes of this item" lookup (leading column),
            // so menu_item_id needs no separate index.
            $table->unique(['menu_item_id', 'name']);
        });

        // One single-quoted literal — no interpolation, no concatenation — as
        // SqlInjectionTest::test_no_raw_sql_string_is_built_from_a_variable
        // requires of every raw SQL string in the project.
        DB::statement('ALTER TABLE `menu_item_sizes` ADD CONSTRAINT `menu_item_sizes_fixed_definition_check` CHECK ((`name` = \'Regular\' AND `display_order` = 1) OR (`name` = \'Large\' AND `display_order` = 2))');
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_sizes');
    }
};

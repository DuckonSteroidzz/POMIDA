<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branch parity audit B5 (2026-09-27). inventory.item_code was unique
     * across ALL branches (inventory_item_code_unique, defined since the
     * table's original migration), so two branches could never use the same
     * code even though they never share stock — Branch 2 could not add
     * "FLOUR-01" if Branch 1 already had, forcing every branch onto one
     * shared naming scheme with no actual benefit (the code is scoped to a
     * branch's own inventory everywhere it is read).
     *
     * Replaced with a composite unique(branch_id, item_code): the same code
     * may now exist once PER branch, and a branch still cannot reuse a code
     * that is active within itself.
     *
     * inventory.branch_id is nullable in the schema, but every live and
     * testing row has one (storeInventory() requires a specific branch
     * before it will create a row at all — confirmed by querying both
     * databases directly, not assumed) — so the null-branch edge case this
     * composite index would otherwise raise (MySQL treats each NULL as
     * distinct, so two NULL-branch rows could still share a code) does not
     * arise in practice.
     */
    public function up(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->dropUnique('inventory_item_code_unique');
            $table->unique(['branch_id', 'item_code'], 'inventory_branch_id_item_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->dropUnique('inventory_branch_id_item_code_unique');
            $table->unique('item_code', 'inventory_item_code_unique');
        });
    }
};

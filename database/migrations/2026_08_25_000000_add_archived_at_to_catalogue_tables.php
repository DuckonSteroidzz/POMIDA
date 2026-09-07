<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate "temporarily unavailable" from "removed from the business".
 *
 * WHY THIS COLUMN EXISTS
 * ----------------------
 * One checkbox was doing two unrelated jobs. `is_available = false` meant both
 * "out of stock, back tomorrow" AND "we do not sell this any more", because
 * deleting a menu item that appeared on a past order is refused by the database
 * (order_items.menu_item_id is ON DELETE RESTRICT) and the only thing the code
 * could do instead was untick Available. The item then sat in the Menu Items
 * list forever, invisible but never gone — reported by the owner as exactly the
 * wrong outcome: "hindi ung natatago lang".
 *
 * archived_at is the second concept:
 *
 *   is_available = false   temporary. Stays in the main list. Comes back.
 *   archived_at  = <time>  permanent. Leaves the main list entirely, stops being
 *                          orderable anywhere, and is restorable from the
 *                          Archived area.
 *
 * A timestamp rather than a boolean because the Archived area has to show WHEN
 * something was archived, and one column answering both questions cannot drift.
 *
 * WHY THE GUARDS
 * --------------
 * Same reasoning as add_ad_fields_to_ads_table: this runs against a live
 * development database that has been edited outside the migration system
 * before, and a migration that aborts here blocks every migration after it.
 * Each column is added only if genuinely missing, so re-running against a
 * database that already has them is a harmless no-op.
 */
return new class extends Migration
{
    /**
     * The catalogue tables that gain a lifecycle. Every one of them is a thing
     * the shop sells or files things under, and every one of them can end up
     * referenced by an order that must stay readable.
     */
    private array $tables = [
        'menu_items',
        'menu_options',
        'categories',
        'subcategories',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'archived_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                // Indexed because EVERY listing query in the app now filters on
                // it — the customer menu, the admin lists, the manual order
                // picker. An unindexed null-check on the hot path would be a
                // silly thing to leave behind.
                $t->timestamp('archived_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'archived_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('archived_at');
            });
        }
    }
};

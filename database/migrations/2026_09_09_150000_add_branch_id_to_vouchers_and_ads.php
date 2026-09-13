<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give vouchers and ads a branch.
 *
 * WHY
 * ---
 * Phase 2 of the RBAC pass implemented "Create/Edit/Activate Vouchers" and
 * "Manage Advertisements" as plain Y | Y | N and said so in routes/web.php:
 * neither table had a branch_id, so a promotion was global BY CONSTRUCTION and
 * there was nothing to narrow a supervisor to. The intent was never that a
 * branch manager should author company-wide promotions, so the data model is
 * what changes here — the authorisation rule then follows the same shape every
 * other branch-owned record already uses.
 *
 * NULL MEANS GLOBAL — the same convention menu_items.branch_id already uses:
 * a NULL row belongs to no single branch and applies everywhere, and only the
 * owner may author or touch one. See AdminController::deleteMenuItem() for the
 * rule this mirrors.
 *
 * THE BACKFILL IS DELIBERATE AND EXPLICIT
 * ---------------------------------------
 * Every row that exists before this migration is a REAL, CURRENTLY-LIVE
 * promotion of the owner's, and it must keep behaving exactly as it does
 * today: available at every branch. A freshly-added nullable column is already
 * NULL on existing rows, so the UPDATE below is technically redundant — it is
 * written out anyway because "the pre-existing promotions stay global" is a
 * requirement of this change and not an accident of MySQL's default, and
 * because a later edit to the column definition (a DEFAULT, a NOT NULL) would
 * otherwise silently re-scope live vouchers with nothing here to stop it.
 *
 * It touches branch_id and nothing else. No other column of any pre-existing
 * row is read, written or defaulted by this file, and no row is created or
 * removed. `updated_at` is untouched too: this is a raw query builder UPDATE
 * on one column, not an Eloquent save, so no timestamp is refreshed.
 *
 * THE hasColumn GUARDS
 * --------------------
 * Same reason as add_ad_fields_to_ads_table: this repo's development database
 * has had columns added outside the migration system before, and a duplicate
 * column error here would block every migration behind it. Additive migrations
 * are written to be re-runnable on a database that already has the column.
 */
return new class extends Migration
{
    /** The tables this migration scopes, in the order it touches them. */
    private const TABLES = ['vouchers', 'ads'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $t) {
                    /*
                     * nullOnDelete, not cascade. Deleting a branch must not
                     * take its promotions' history with it — the voucher
                     * simply becomes global again, which is the safe direction.
                     * (Contrast user_vouchers.voucher_id, which IS cascade, and
                     * is the reason deleting a voucher is owner-only.)
                     */
                    $t->foreignId('branch_id')
                        ->nullable()
                        ->after('id')
                        ->constrained('branches')
                        ->nullOnDelete();
                });
            }

            // The explicit backfill. See the docblock: one column, no rows
            // created or destroyed, every other value left exactly as it is.
            DB::table($table)->whereNotNull('branch_id')->update(['branch_id' => null]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('branch_id');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add 'supervisor' to the users.role enum.
 *
 * WHY A RAW STATEMENT AND NOT A SCHEMA BUILDER CALL
 * -------------------------------------------------
 * Laravel's schema builder cannot alter a MySQL ENUM in place — doctrine/dbal
 * does not map the type, and $table->enum(...)->change() throws "Unknown
 * database type enum requested". Rewriting the column definition is the only
 * way, so it is spelled out here exactly as the original migration declared it
 * (2026_04_27_031128_update_users_table_for_pomida.php) with one value added:
 *
 *   BEFORE: enum('customer','staff','admin')      NOT NULL DEFAULT 'customer'
 *   AFTER:  enum('customer','staff','admin','supervisor') NOT NULL DEFAULT 'customer'
 *
 * WIDENING ONLY — NO EXISTING ROW CHANGES
 * ---------------------------------------
 * Adding a value to an enum is purely additive: every existing customer, staff
 * and admin row keeps the exact string it already holds, the NOT NULL and the
 * DEFAULT are restated unchanged, and no row is rewritten. Verified against
 * pomida_db before writing this (customer x2, staff x2, admin x1) — the live
 * admin account is untouched by design, because losing it locks the whole
 * system out (see AdminController::updateStaffPassword).
 *
 * 'supervisor' is deliberately LAST rather than inserted next to 'staff'.
 * MySQL stores an enum by its ordinal position, so appending leaves every
 * existing value's index alone; inserting in the middle would silently
 * renumber 'admin' underneath the stored data.
 *
 * The role's meaning lives in App\Models\User (BRANCH_LOCKED_ROLES /
 * PORTAL_ROLES / ADMIN_MANAGEABLE_ROLES), not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `users`
            MODIFY `role` ENUM('customer','staff','admin','supervisor') NOT NULL DEFAULT 'customer'
        SQL);
    }

    /**
     * Narrow the enum back.
     *
     * Any supervisor account is demoted to 'staff' FIRST, deliberately. MySQL
     * would otherwise coerce a value that no longer fits to the empty string
     * (or reject the ALTER outright in strict mode), silently producing an
     * account with no usable role that AdminMiddleware would then lock out with
     * no way back. 'staff' is the safe landing: it is the least-privileged
     * portal role, and it is branch-locked exactly as the supervisor was, so
     * nobody GAINS access on the way down.
     */
    public function down(): void
    {
        DB::table('users')->where('role', 'supervisor')->update(['role' => 'staff']);

        DB::statement(<<<'SQL'
            ALTER TABLE `users`
            MODIFY `role` ENUM('customer','staff','admin') NOT NULL DEFAULT 'customer'
        SQL);
    }
};

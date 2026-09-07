<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a user_vouchers claim exist WITHOUT an account, identified instead by its
 * own unique single-use code.
 *
 * WHY
 * ---
 * A guest has no account, so under item 30 — which requires an unused
 * user_vouchers claim before a wheel voucher can be redeemed — a guest could
 * never hold one and therefore could never win or use a wheel voucher at all.
 * Two columns close that:
 *
 *   user_id NULL  — the claim has no owner.
 *   claim_code    — a unique, single-use code that IS the proof of winning.
 *
 * Item 30 is not weakened by this. The bypass it closed was that knowing a
 * voucher's SHARED public code was enough to redeem it; that is still refused.
 * A claim code is per-win, unguessable, and spendable exactly once, so holding
 * one grants nothing beyond the single prize actually won.
 *
 * WHY THE GUARDS
 * --------------
 * Same reason as add_ad_fields_to_ads_table: this project's live database has
 * been out of step with the migration history before, and a migration that
 * aborts here blocks every later one. Each change is applied only if it is
 * genuinely still needed, so running this against a database that already has
 * the shape is a harmless no-op.
 *
 * THE EXISTING UNIQUE(user_id, voucher_id) IS DELIBERATELY LEFT ALONE.
 * MySQL treats NULLs as distinct in a unique index, so many ownerless claims on
 * the same voucher coexist happily, while "one wheel voucher per account"
 * continues to hold for every real account exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_vouchers')) {
            return;
        }

        // 1. user_id becomes nullable.
        //
        // Done with raw SQL rather than ->change(): the column carries a
        // foreign key, and Laravel's change() rebuilds the column definition
        // from scratch, which on MySQL risks dropping the FK's backing index.
        // MODIFY COLUMN keeps the key in place and only relaxes nullability.
        if ($this->userIdIsRequired()) {
            DB::statement('ALTER TABLE `user_vouchers` MODIFY `user_id` BIGINT UNSIGNED NULL');
        }

        // 2. The claim code. Unique so one code can only ever name one claim,
        //    nullable because every pre-existing account claim legitimately
        //    has none — those are still resolved by user_id as they always were.
        if (!Schema::hasColumn('user_vouchers', 'claim_code')) {
            Schema::table('user_vouchers', function (Blueprint $table) {
                $table->string('claim_code', 32)
                    ->nullable()
                    ->unique()
                    ->after('voucher_id');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_vouchers')) {
            return;
        }

        if (Schema::hasColumn('user_vouchers', 'claim_code')) {
            Schema::table('user_vouchers', function (Blueprint $table) {
                $table->dropUnique('user_vouchers_claim_code_unique');
                $table->dropColumn('claim_code');
            });
        }

        // Only put NOT NULL back if nothing would be lost by it — an ownerless
        // claim cannot be represented once the column is required, and silently
        // destroying a customer's unredeemed prize on a rollback would be worse
        // than leaving the column relaxed.
        if (!$this->userIdIsRequired()
            && DB::table('user_vouchers')->whereNull('user_id')->doesntExist()) {
            DB::statement('ALTER TABLE `user_vouchers` MODIFY `user_id` BIGINT UNSIGNED NOT NULL');
        }
    }

    /** Is user_vouchers.user_id still declared NOT NULL? */
    private function userIdIsRequired(): bool
    {
        foreach (DB::select('DESCRIBE `user_vouchers`') as $column) {
            if ($column->Field === 'user_id') {
                return strtoupper($column->Null) === 'NO';
            }
        }

        return false;
    }
};

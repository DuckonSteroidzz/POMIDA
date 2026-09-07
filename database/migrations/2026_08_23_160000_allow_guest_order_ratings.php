<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a guest rate their own order.
 *
 * Dine-in guests order without an account — they are identified by
 * session('guest_order_id'), the same way they already track and receipt their
 * order. order_ratings.user_id was NOT NULL with a foreign key to users, so
 * there was literally no row shape a guest rating could take, which is why
 * rating was account-only.
 *
 * Making user_id nullable is the honest representation: a guest rating has no
 * user. Nothing else changes — the UNIQUE(order_id) constraint still guarantees
 * one rating per order regardless of who submitted it, and a rating that does
 * belong to an account still carries its user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The foreign key has to come off before the column can be modified,
        // and doctrine/dbal is not installed, so this is done with raw SQL.
        Schema::table('order_ratings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        DB::statement('ALTER TABLE `order_ratings` MODIFY `user_id` BIGINT UNSIGNED NULL');

        Schema::table('order_ratings', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        // Guest ratings cannot be represented once the column is NOT NULL
        // again, so they are removed rather than silently mis-attributed.
        DB::table('order_ratings')->whereNull('user_id')->delete();

        Schema::table('order_ratings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        DB::statement('ALTER TABLE `order_ratings` MODIFY `user_id` BIGINT UNSIGNED NOT NULL');

        Schema::table('order_ratings', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }
};

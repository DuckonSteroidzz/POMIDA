<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registered-customer loyalty bonuses (Oct 2026) — the ledger and the balance.
 *
 * loyalty_awards
 * --------------
 * One row per bonus ever paid: the welcome bonus, a completed-order bonus, a
 * streak milestone. UNIQUE(user_id, type, award_key) is the whole idempotency
 * guarantee: App\Services\Loyalty::award() inserts the row FIRST and credits
 * points only if the insert succeeded, inside one transaction, so a second
 * attempt at the same bonus (a retry, a double click, two staff completing at
 * once) hits the unique key and pays nothing. No "check, then insert".
 *
 *   type             award_key
 *   welcome          welcome
 *   order_bonus      <order id>
 *   streak_milestone <days>:<first day of the streak, Y-m-d in the shop zone>
 *
 * `award_key`, not `key`: KEY is a reserved word in MySQL.
 *
 * users.bonus_points
 * ------------------
 * Where those points are credited. Deliberately NOT users.points: that is the
 * Spin & Win balance and OrderController::placeOrder() resets it to 0 on every
 * order, so a bonus written there would be gone before it could be used. The
 * wheel adds the two together when it checks for a voucher — see
 * AuthController::addPoints().
 *
 * Unsigned with a default of 0: every existing account starts with no bonus,
 * which is correct. Nothing is backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'bonus_points')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedInteger('bonus_points')->default(0)->after('points');
            });
        }

        if (! Schema::hasTable('loyalty_awards')) {
            Schema::create('loyalty_awards', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('type', 32);
                $table->string('award_key', 64);
                $table->unsignedInteger('points');
                // The order that triggered it, for the audit trail only. SET
                // NULL so the ledger outlives a deleted order — the unique key,
                // not this column, is what stops a repeat.
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'type', 'award_key'], 'loyalty_awards_user_type_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_awards');

        if (Schema::hasColumn('users', 'bonus_points')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('bonus_points');
            });
        }
    }
};

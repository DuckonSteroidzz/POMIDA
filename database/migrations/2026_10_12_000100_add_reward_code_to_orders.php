<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Streak Rewards (Oct 2026): orders.reward_code.
 *
 * The reward code an order spent (item or discount reward), stored without
 * dashes, written inside the order transaction. A snapshot for the staff
 * board, receipts, the completed-orders list and its CSV, so they can show the
 * code with no extra query — and it stays on the order as history even after
 * a cancellation hands the reward itself back to the customer.
 *
 * Also the second half of "a code is never reused": a new code is refused if
 * any order has ever recorded it (App\Services\FreeDesserts::uniqueCode()).
 *
 * NULL for every order that used no reward, which is every existing order.
 * Runs after 2026_10_12_000000_add_codes_and_snapshots_to_free_dessert_rewards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'reward_code')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('reward_code', 16)->nullable()->after('voucher_id');
                $table->index('reward_code', 'orders_reward_code_index');
            });
        }
    }

    /** Refuses while any order records a reward code — that history would be lost. */
    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'reward_code')) {
            return;
        }

        if (DB::table('orders')->whereNotNull('reward_code')->exists()) {
            throw new RuntimeException('Refusing to roll back: orders record reward codes that would be lost.');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_reward_code_index');
            $table->dropColumn('reward_code');
        });
    }
};

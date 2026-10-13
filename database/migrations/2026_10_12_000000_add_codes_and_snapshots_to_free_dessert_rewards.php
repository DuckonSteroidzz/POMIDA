<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Streak Rewards (Oct 2026): every reward gets its own code and a snapshot of
 * what it is. Runs after 2026_10_08_000000_create_free_dessert_rewards and
 * 2026_10_08_100000_add_notified_at_to_free_dessert_rewards.
 *
 * free_dessert_rewards.code
 * -------------------------
 * RWD + 8 characters (App\Support\RewardCode), stored without dashes. UNIQUE:
 * the database itself refuses a second reward with the same code. Rows that
 * already exist are given a fresh random code here, before the column becomes
 * NOT NULL and the index is added.
 *
 * The snapshot — copied from the owner's Streak Rewards settings when the
 * reward is earned (App\Services\StreakRewardSettings::snapshotForNewReward),
 * so changing the settings later never changes a reward already earned:
 *
 *   reward_type      'menu_item' | 'voucher'; NULL only for a row earned
 *                    before this migration (it takes the owner's first saved
 *                    reward while it is still unused)
 *   menu_item_id     the item given free (SET NULL if the item is ever
 *                    permanently deleted; item_name keeps what it was)
 *   item_name        the item's name when earned
 *   voucher_id       the template voucher the terms were copied from (SET NULL
 *                    on delete; the copied terms below are what count)
 *   voucher_code     that voucher's code, for the record
 *   discount_type    'percent' | 'fixed'
 *   discount_value   the discount
 *   minimum_order    the minimum paid subtotal, 0 for none
 *   branch_id        the one branch a voucher reward may be spent at, NULL for
 *                    every branch. Deliberately NO foreign key: a SET NULL
 *                    would silently turn a one-branch reward into an
 *                    every-branch reward, and a dangling id simply matches no
 *                    order (refuses — the safe direction).
 *   used_branch_id   the branch of the order that spent it, kept beside
 *                    order_id so the admin code check can scope a supervisor
 *                    even if that order row is later gone. No foreign key, for
 *                    the same reason. Cleared when a cancelled order hands the
 *                    reward back.
 */
return new class extends Migration
{
    /** App\Support\RewardCode's alphabet (TableEntry::ALPHABET), repeated so this migration never depends on app code. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function up(): void
    {
        if (! Schema::hasTable('free_dessert_rewards')) {
            return;
        }

        Schema::table('free_dessert_rewards', function (Blueprint $table) {
            if (! Schema::hasColumn('free_dessert_rewards', 'code')) {
                $table->string('code', 16)->nullable()->after('loyalty_award_id');
            }

            if (! Schema::hasColumn('free_dessert_rewards', 'reward_type')) {
                $table->string('reward_type', 16)->nullable()->after('code');
                $table->foreignId('menu_item_id')->nullable()->after('reward_type')->constrained('menu_items')->nullOnDelete();
                $table->string('item_name')->nullable()->after('menu_item_id');
                $table->foreignId('voucher_id')->nullable()->after('item_name')->constrained('vouchers')->nullOnDelete();
                $table->string('voucher_code', 50)->nullable()->after('voucher_id');
                $table->string('discount_type', 10)->nullable()->after('voucher_code');
                $table->decimal('discount_value', 10, 2)->nullable()->after('discount_type');
                $table->decimal('minimum_order', 10, 2)->nullable()->after('discount_value');
                $table->unsignedBigInteger('branch_id')->nullable()->after('minimum_order');
            }

            if (! Schema::hasColumn('free_dessert_rewards', 'used_branch_id')) {
                $table->unsignedBigInteger('used_branch_id')->nullable()->after('used_at');
            }
        });

        // Every existing reward gets its own code.
        $taken = DB::table('free_dessert_rewards')->whereNotNull('code')->pluck('code')->flip()->all();

        foreach (DB::table('free_dessert_rewards')->whereNull('code')->orderBy('id')->pluck('id') as $id) {
            do {
                $code = 'RWD';
                for ($i = 0; $i < 8; $i++) {
                    $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
                }
            } while (isset($taken[$code]));

            $taken[$code] = true;
            DB::table('free_dessert_rewards')->where('id', $id)->update(['code' => $code]);
        }

        // Where an existing used reward was spent.
        foreach (DB::table('free_dessert_rewards')->whereNotNull('order_id')->whereNull('used_branch_id')->get(['id', 'order_id']) as $row) {
            DB::table('free_dessert_rewards')->where('id', $row->id)->update([
                'used_branch_id' => DB::table('orders')->where('id', $row->order_id)->value('branch_id'),
            ]);
        }

        Schema::table('free_dessert_rewards', function (Blueprint $table) {
            $table->string('code', 16)->nullable(false)->change();
            $table->unique('code', 'free_dessert_rewards_code_unique');
        });
    }

    /**
     * Refuses while any reward carries a snapshot: rolling back would delete
     * the codes customers hold and what their rewards are, with nothing to
     * rebuild them from. On a database with no such rows it undoes cleanly.
     */
    public function down(): void
    {
        if (! Schema::hasTable('free_dessert_rewards') || ! Schema::hasColumn('free_dessert_rewards', 'code')) {
            return;
        }

        if (Schema::hasColumn('free_dessert_rewards', 'reward_type')
            && DB::table('free_dessert_rewards')->whereNotNull('reward_type')->exists()) {
            throw new RuntimeException(
                'Refusing to roll back: customers hold reward codes and reward snapshots that would be lost. '
                . 'Back up free_dessert_rewards and remove those rows first if this is really intended.'
            );
        }

        Schema::table('free_dessert_rewards', function (Blueprint $table) {
            $table->dropUnique('free_dessert_rewards_code_unique');
            $table->dropForeign(['menu_item_id']);
            $table->dropForeign(['voucher_id']);
            $table->dropColumn([
                'code', 'reward_type', 'menu_item_id', 'item_name', 'voucher_id', 'voucher_code',
                'discount_type', 'discount_value', 'minimum_order', 'branch_id', 'used_branch_id',
            ]);
        });
    }
};

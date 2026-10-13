<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Streak Rewards per branch (Oct 2026): the stamp card ledger keys carry the
 * branch. Data only — no schema change. Runs after
 * 2026_10_12_000100_add_reward_code_to_orders.
 *
 * Stamp cards are now per branch (App\Services\FreeDesserts): the n-th reward
 * earned at branch B is loyalty_awards key "visits:<B>:<n>" (type
 * free_dessert), so two branches' rewards never collide. Before this change
 * the key was "visits:<n>", counted across every branch.
 *
 * A legacy "visits:<n>" row is RENAMED to the branch of the order whose
 * completion earned it (loyalty_awards.order_id -> orders.branch_id),
 * numbered 1, 2, ... per customer per branch in the order they were earned
 * (skipping any number that branch already holds). That marks the reward as
 * already paid AT THAT BRANCH, so the new per-branch catch-up
 * (FreeDesserts::grantDue) never pays it a second time. A row whose earning
 * order is unknown (order_id NULL, or the order is gone) is left as it is:
 * nothing reads a two-part key any more. The reward rows themselves are not
 * touched — a reward already earned keeps working where it worked.
 *
 * At the time of writing there are no such rows anywhere this was checked
 * (pomida_db_testing: 0 free_dessert ledger rows; the local pomida_db does
 * not have the rewards table yet), so on those databases this changes
 * nothing. It is idempotent: a renamed key no longer matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loyalty_awards') || ! Schema::hasTable('orders')) {
            return;
        }

        $legacy = DB::table('loyalty_awards')
            ->where('type', 'free_dessert')
            ->where('award_key', 'like', 'visits:%')
            ->orderBy('user_id')
            ->orderBy('id')
            ->get(['id', 'user_id', 'award_key', 'order_id'])
            ->filter(fn ($row) => preg_match('/^visits:[0-9]+$/', $row->award_key) === 1);

        $next = [];

        foreach ($legacy as $row) {
            $branchId = $row->order_id
                ? DB::table('orders')->where('id', $row->order_id)->value('branch_id')
                : null;

            if ($branchId === null) {
                continue;
            }

            $branchId = (int) $branchId;

            do {
                $number = ($next[$row->user_id][$branchId] ?? 0) + 1;
                $next[$row->user_id][$branchId] = $number;
                $key = 'visits:' . $branchId . ':' . $number;
            } while (DB::table('loyalty_awards')
                ->where('user_id', $row->user_id)
                ->where('type', 'free_dessert')
                ->where('award_key', $key)
                ->exists());

            DB::table('loyalty_awards')->where('id', $row->id)->update(['award_key' => $key]);
        }
    }

    /**
     * Refuses while any per-branch key exists: the code before this change
     * reads only "visits:<n>" keys, so it would pay those rewards again.
     */
    public function down(): void
    {
        if (! Schema::hasTable('loyalty_awards')) {
            return;
        }

        if (DB::table('loyalty_awards')->where('type', 'free_dessert')->where('award_key', 'like', 'visits:%:%')->exists()) {
            throw new RuntimeException(
                'Refusing to roll back: per-branch stamp card ledger keys exist, and the previous code would pay those rewards again.'
            );
        }
    }
};

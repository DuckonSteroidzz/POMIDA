<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free dessert pop-up (Oct 2026): free_dessert_rewards.notified_at.
 *
 * When the customer was shown "You earned a free dessert!" for this reward.
 * App\Support\FreeDessertPrompt::announce() stamps it with
 * `WHERE notified_at IS NULL`, so the pop-up is shown once per reward however
 * many tabs or phones ask. NULL = not shown yet; existing rows start NULL, so
 * a reward that is still available when this runs is announced once on the
 * customer's next visit. Nothing else reads it.
 *
 * Runs after 2026_10_08_000000_create_free_dessert_rewards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('free_dessert_rewards') && ! Schema::hasColumn('free_dessert_rewards', 'notified_at')) {
            Schema::table('free_dessert_rewards', function (Blueprint $table) {
                $table->timestamp('notified_at')->nullable()->after('used_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('free_dessert_rewards', 'notified_at')) {
            Schema::table('free_dessert_rewards', function (Blueprint $table) {
                $table->dropColumn('notified_at');
            });
        }
    }
};

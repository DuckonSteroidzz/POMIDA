<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * QA, 2 Nov 2026: which branch's streak and stamp card a customer sees, kept
     * on the account. It used to live only in the session, which logging out
     * empties, so the card looked "gone" after every login. See
     * App\Services\FreeDesserts::cardBranch().
     *
     * Its own column, not users.branch_id: that is a staff member's assigned
     * branch, what AdminOrderAccess locks them to, and its foreign key RESTRICTs
     * deleting the branch. A customer's remembered card must never stop a branch
     * from being deleted, so here the delete just forgets it (SET NULL).
     *
     * Every existing account starts NULL and shows the branch of its last order
     * until it next chooses one, so nothing needs backfilling.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('rewards_branch_id')->nullable()->after('branch_id')
                ->constrained('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rewards_branch_id');
        });
    }
};

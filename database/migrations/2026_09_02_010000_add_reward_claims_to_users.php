<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many points-threshold rewards a customer has already been handed at the
 * counter.
 *
 * WHY A SEPARATE COUNTER AND NOT A DEDUCTION FROM users.points
 * ------------------------------------------------------------
 * `users.points` is a SPENDABLE BALANCE, not a lifetime total: addPoints()
 * credits it on a spin and then subtracts points_required from it when the
 * wheel awards a voucher. Deriving "rewards earned" from a number that goes
 * both up and down would let the earned count fall after a wheel win, which
 * could make unclaimed go negative and would re-arm a threshold the customer
 * had already crossed.
 *
 * Lifetime points are therefore read from the `games_played` ledger instead
 * (SUM of points_awarded — append-only, never decremented; that table exists
 * precisely to answer "where did these points come from?"), and claims are
 * counted here. Neither number is ever reduced, so:
 *
 *     unclaimed = floor(lifetime / THRESHOLD) - reward_claims
 *
 * is monotonic and cannot go negative on its own.
 *
 * Guarded the same way as the other additive migrations in this project: the
 * live database has drifted from the migration history before, so an additive
 * migration that assumes a clean slate can abort and block everything behind
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'reward_claims')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('reward_claims')
                ->default(0)
                ->after('points');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'reward_claims')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reward_claims');
        });
    }
};

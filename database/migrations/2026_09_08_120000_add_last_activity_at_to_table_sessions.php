<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guest-facing inactivity clock for a dine-in session.
 *
 * WHY THIS IS NOT last_seen_at
 * ----------------------------
 * table_sessions already carries last_seen_at, and reusing it was the obvious
 * move. It is the wrong one, because the two columns answer different
 * questions on different clocks for different people:
 *
 *   last_seen_at       "should staff still see this table as occupied?"
 *                      Released after TableOccupancy::INACTIVITY_MINUTES (90)
 *                      by sweepIdle(), for the benefit of the STAFF PANEL and
 *                      to bound the life of a session opened with a
 *                      photographed table code.
 *
 *   last_activity_at   "is the person holding this phone still here?"
 *                      Expires after TableOccupancy::GUEST_IDLE_MINUTES (15)
 *                      and is felt only by THAT GUEST, who is sent back to the
 *                      code-entry page to scan again.
 *
 * Writing the guest's activity ping into last_seen_at would have folded a
 * fifteen-minute guest concern into the ninety-minute housekeeping timer, and
 * every future question about whether sweepIdle still behaves the way it did
 * would have become an argument rather than a fact. With a separate column the
 * isolation is structural: sweepIdle() never reads last_activity_at, and
 * nothing on the guest-inactivity path ever writes last_seen_at.
 *
 * Nullable with no backfill on purpose. Rows that were already live when this
 * shipped read their baseline from last_seen_at (and then created_at) instead,
 * so a party mid-meal at deploy time is not thrown back to the code-entry page
 * for a column that did not exist while they were sitting down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->dropColumn('last_activity_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per DEVICE that has joined a shared table_sessions occupancy.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS TABLE, RATHER THAN A COUNTER COLUMN ON table_sessions
 * ─────────────────────────────────────────────────────────────────────────────
 * The obvious-looking alternative is an integer `device_count` on
 * table_sessions: increment in claim(), decrement on release. It was rejected
 * because there is no event in this codebase that decrements it correctly:
 *
 *   - The three release paths (idle sweep, order completion, staff Clear) all
 *     release the WHOLE table_sessions row at once — active_lock goes to NULL
 *     for everyone at the table simultaneously. A counter on that row would
 *     need no decrement logic for these paths at all (the row simply stops
 *     being "live"), so a plain counter does not even need decrementing here.
 *
 *   - The one thing that DOES need to reduce the count without releasing the
 *     whole table is a SINGLE device going idle — the existing 15-minute
 *     guest clock (TableOccupancy::GUEST_IDLE_MINUTES). That clock is
 *     deliberately per-device already (inspectGuestSession() is read-only and
 *     per-browser), but a bare counter column has no per-device identity to
 *     hang a per-device expiry off. Making it decrement correctly would mean
 *     tracking each device's own last-seen time SOMEWHERE — which is exactly
 *     this table, just discarded instead of kept. A counter that can only be
 *     trusted by first building the thing it was meant to avoid is not
 *     simpler, only lossier: it can drift (a missed decrement leaves the
 *     count permanently too high) and it throws away the audit trail
 *     (which device joined when) for no savings.
 *
 * With a child table, "how many devices are active" is a read-time COUNT
 * query filtered by last_activity_at, computed the same way
 * TableOccupancy::inspectGuestSession() already judges a single device's own
 * idleness — no new decrement bookkeeping to keep in sync, no scheduler
 * (there isn't one in this project — see TableOccupancy::sweepIdle()'s own
 * docblock on that), and a stale device simply ages out of the count on its
 * own the next time anyone reads it.
 *
 * WHAT THIS TABLE DOES NOT DO
 * ---------------------------
 * It does not gate anything and it never causes a refusal. It is purely a
 * visibility layer on top of the sharing model TableOccupancy::claim() already
 * implements — see that class's docblock ("SHARED, NOT EXCLUSIVE"). Rows here
 * are written by claim() (one per device that joins or opens a table) and by
 * the existing per-minute activity ping, and never deleted: when the parent
 * table_sessions row releases, these rows simply stop being counted because
 * the query that counts them is always scoped to a table_session_id whose
 * parent is still live. History survives, exactly like table_sessions itself
 * keeps a released row rather than deleting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_session_devices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('table_session_id')->constrained('table_sessions')->cascadeOnDelete();

            /*
             * A random identifier stored in THIS browser's Laravel session,
             * distinct from table_sessions.session_token — every device at a
             * table shares the same session_token (that is the whole point of
             * sharing), so session_token cannot tell two devices apart. This
             * can, because it is generated once per browser rather than
             * copied from the table's row.
             */
            $table->string('device_token', 64);

            // Same signal and the same staleness rule as
            // table_sessions.last_activity_at, but per device instead of per
            // table. Updated on every claim() (join or open) and on every
            // activity ping from that device.
            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();

            // The same device re-scanning or re-polling the same session must
            // update its one row, not accumulate duplicates.
            $table->unique(['table_session_id', 'device_token']);

            // The count query's own lookup: all devices for a session, ordered
            // by nothing in particular but filtered by recency.
            $table->index(['table_session_id', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_session_devices');
    }
};

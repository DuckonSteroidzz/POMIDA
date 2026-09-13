<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-notification dismiss for the notification tray.
 *
 * "Mark all read" only ever set read_at — a read row still sits in the tray, so
 * the list grew without bound and showed entries days old. This adds a soft
 * dismiss: an X on each card stamps dismissed_at and the tray query drops the
 * row from then on. It is deliberately NOT a hard delete — the row stays for any
 * history/report use, and "dismissed" is a UI state, not a lifecycle one.
 *
 * Auto-expiry of stale rows (see Notification::scopeInTray) is a pure query
 * concern and needs no column: informational rows past 7 days are simply not
 * selected. Actionable rows (gcash_awaiting_verification, refund_pending) are
 * never auto-expired regardless of age — only a manual dismiss clears them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('read_at');

            // The tray feed now filters on (audience, branch_id, dismissed_at)
            // for staff and (audience, user_id, dismissed_at) for customers,
            // alongside the created_at cutoff. Mirrors the existing read_at
            // indexes so the added predicate stays cheap.
            $table->index(['audience', 'branch_id', 'dismissed_at'], 'notifications_staff_tray_idx');
            $table->index(['audience', 'user_id', 'dismissed_at'], 'notifications_customer_tray_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_staff_tray_idx');
            $table->dropIndex('notifications_customer_tray_idx');
            $table->dropColumn('dismissed_at');
        });
    }
};

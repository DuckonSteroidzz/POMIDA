<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * QA, 5 Nov 2026: "I can't see the refund … we can't open it again."
     *
     * Mark Refunded only flipped payment_status to 'refunded', so the moment a
     * refund was settled nothing said WHEN or WHO — the Refunds page and the
     * customer's "Refunded on [date]" both need that. See
     * AdminController::markOrderRefunded().
     *
     * refunded_by is SET NULL on delete: removing a staff account must never
     * block or erase the refund history, only forget the name.
     *
     * Nothing is backfilled. A refund settled before this column existed has
     * no honest date or person to write, so it stays NULL and the screens say
     * "Not recorded" rather than guessing from updated_at.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable()->after('cancelled_at');
            $table->foreignId('refunded_by')->nullable()->after('refunded_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn('refunded_at');
        });
    }
};

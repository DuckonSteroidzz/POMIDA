<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds 'refund_pending' to the orders.payment_status enum.
 *
 * Customer self-service cancellation (2026-09-02) needs to distinguish two
 * very different cancellations of a GCash order:
 *
 *   - cancelled BEFORE the customer clicked "I have paid" — no money ever
 *     moved, so this is a plain cancellation and payment_status stays
 *     'pending'.
 *   - cancelled AFTER "I have paid" (payment_status 'awaiting_verification')
 *     — the customer says they already sent money. There is no merchant API
 *     here, so nobody can reverse that programmatically: a refund is a real
 *     human action at the counter. That order must NOT look like a plain
 *     cancellation, or the refund silently never happens.
 *
 * 'refund_pending' is the missing intermediate state in a column that already
 * ends at 'refunded'. It mirrors the shape of the existing GCash flow:
 * 'awaiting_verification' -> 'paid' is a staff decision, and now
 * 'refund_pending' -> 'refunded' is the matching one for money going back.
 *
 * Enum-widening only: no existing row changes value, and every current value
 * remains legal, so this is safe to run against the live table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('payment_status', [
                'pending',
                'awaiting_verification',
                'paid',
                'rejected',
                'refund_pending',
                'refunded',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Any row still sitting in the removed state would become invalid, so
        // park it at 'refunded' (the state it was heading for) rather than
        // letting the enum change fail or silently blank the column.
        \Illuminate\Support\Facades\DB::table('orders')
            ->where('payment_status', 'refund_pending')
            ->update(['payment_status' => 'refunded']);

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('payment_status', [
                'pending',
                'awaiting_verification',
                'paid',
                'rejected',
                'refunded',
            ])->default('pending')->change();
        });
    }
};

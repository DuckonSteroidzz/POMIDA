<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who opened a table occupancy.
 *
 * Until now every occupancy was opened by a CUSTOMER — they scanned the QR or
 * keyed a staff-issued code, and their browser session held the token that
 * proves the table is theirs. A staff-created dine-in Manual Order has no
 * customer session at all, which is why it never marked the table occupied.
 *
 * A staff-opened occupancy is a real party sitting at a real table, but it must
 * not be mistaken for a customer session: the item-40 "same customer
 * continuing" signals are session-token and order-ownership, and a staff
 * occupancy satisfies neither by design. This column records the attribution
 * explicitly so the two can never be confused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->foreignId('opened_by')
                ->nullable()
                ->after('started_ip')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opened_by');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-issued fallback codes for the dine-in scan flow.
 *
 * The table QR itself stays permanent and static. This is only for the customer
 * whose camera will not cooperate: they ask a staff member, who taps a button
 * and reads out a short code that works once and expires quickly.
 *
 * Why a table rather than the cache
 * ---------------------------------
 * A cache entry would give expiry for free, but:
 *   - CACHE_STORE is 'database' here, so a cache-backed code is a database
 *     write either way, just an opaque one that cannot be queried or audited.
 *   - `php artisan cache:clear` is a routine action during development and
 *     deployment. With a cache-backed store that command silently voids every
 *     live code a staff member has just read out to a customer.
 *   - Staff handing out access to a table is a counter action worth being able
 *     to answer questions about: who issued it, when, whether it was used.
 *     issued_by / claimed_at / claimed_ip make that a query instead of a guess.
 *
 * The cost is one small table and a prune, which is a fair trade for something
 * that grants a session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_access_codes', function (Blueprint $table) {
            $table->id();

            // Unique across all time, including already-claimed rows, so a code
            // string is never handed out twice.
            $table->string('code', 12)->unique();

            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            // Matches orders.table_number, which is the canonical shape a
            // dine-in table is recorded as.
            $table->string('table_number', 10);

            // The staff/admin account that issued it. Nullable so deleting a
            // staff account never destroys the record of a code being used.
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('expires_at');

            // Set the moment the code is redeemed. A non-null value is what
            // makes the code single-use.
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_ip', 45)->nullable();

            $table->timestamps();

            // The lookup the customer-facing claim does on every attempt.
            $table->index(['code', 'claimed_at']);

            // Used by the prune and by the staff view of live codes.
            $table->index('expires_at');
            $table->index(['branch_id', 'table_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_access_codes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table occupancy: at most one live dine-in session per physical table.
 *
 * Why a dedicated table rather than reusing table_access_codes
 * -----------------------------------------------------------
 * They look similar but model different things with different lifecycles:
 *
 *   table_access_codes  a CREDENTIAL. Many rows per table over a shift, each
 *                       alive for minutes, spent by being redeemed once.
 *   table_sessions      the STATE of a physical table. At most one live row per
 *                       table, alive for an entire meal, released when the
 *                       order finishes or staff clear it.
 *
 * Folding occupancy into the codes table would mean a row that is simultaneously
 * "a code that was used" and "a table that is busy", with two unrelated release
 * conditions on one record — and it could not express the constraint that
 * actually matters here, which is uniqueness of the LIVE row per table.
 *
 * How "one live session per table" is enforced
 * --------------------------------------------
 * MySQL has no partial unique indexes, so the constraint is carried by
 * `active_lock`: it holds "<branch_id>:<TABLE>" while the session is live and is
 * set to NULL the moment it is released. A UNIQUE index permits any number of
 * NULLs, so released rows stay for history while exactly one live row per table
 * is possible. That makes the guarantee a database invariant rather than
 * something the application has to remember to check under concurrency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            // Same shape as orders.table_number and table_access_codes.table_number.
            $table->string('table_number', 10);

            /*
             * The secret this browser holds (in the Laravel session) to prove it
             * owns the occupancy. This is what separates "the same customer
             * refreshing the page" from "a second person scanning the same QR".
             */
            $table->string('session_token', 64)->unique();

            // Linked once the customer actually places an order. Null while they
            // are still browsing the menu.
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();

            // "<branch_id>:<TABLE>" while live, NULL once released. See the note
            // above — this column IS the one-live-session-per-table constraint.
            $table->string('active_lock', 32)->nullable()->unique();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('released_at')->nullable();

            // Who released it, when a staff member did it by hand.
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();

            // order_completed | order_cancelled | staff_cleared | abandoned
            $table->string('release_reason', 20)->nullable();

            $table->string('started_ip', 45)->nullable();

            $table->timestamps();

            // Staff "who is sitting where" lookups and the release-by-order path.
            $table->index(['branch_id', 'table_number']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_sessions');
    }
};

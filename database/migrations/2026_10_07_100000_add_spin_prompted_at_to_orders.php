<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * orders.spin_prompted_at — "the Spin & Win prompt has already been shown for
 * this order" (Oct 2026).
 *
 * The Orders page shows a one-time modal inviting the customer to play while
 * they wait. "Once per order, even after a refresh" has to be remembered by the
 * server: the browser's storage is not a record the server can rely on, and a
 * customer who signs in on another phone must not see it a second time. See
 * App\Support\SpinPrompt.
 *
 * Nullable, no default, no backfill: every existing order reads as "not yet
 * prompted", which is correct. The column grants nothing — spins are still
 * counted entirely from game_played + the order list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'spin_prompted_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('spin_prompted_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'spin_prompted_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('spin_prompted_at');
        });
    }
};

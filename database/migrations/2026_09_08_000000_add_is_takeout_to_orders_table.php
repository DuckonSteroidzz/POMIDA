<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Take-Out flag for Dine-In orders.
 *
 * A Dine-In customer can tick "Mark as Take Out" in the Confirm Your Order
 * modal; staff/admin then see a "TAKE OUT" badge on that order. Pick-Up orders
 * are inherently takeout and never carry this flag.
 *
 * PURELY ADDITIVE: one new boolean column, defaulted to false. No existing
 * order row is read, rewritten, or backfilled with anything but that default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_takeout')->default(false)->after('table_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_takeout');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer validity window for a wheel-won voucher.
 *
 * The wheel-win code used to write "cannot use until tomorrow" onto the
 * SHARED vouchers.valid_from column. A voucher code is a single row that
 * potentially many different customers each hold their own user_vouchers
 * claim on (see Voucher::redemptionErrorFor()'s points_required > 0 branch),
 * so every one of them was reading the SAME valid_from — meaning the most
 * recent winner's "starts tomorrow" date silently overwrote every earlier
 * winner's window too. Reproduced live with two real customer accounts:
 * winner A's window visibly shifted the moment winner B won the same code.
 *
 * The fix is this column: the "can't use it the day I won it" rule is a fact
 * about ONE customer's claim, not about the voucher definition, so it now
 * lives on the row that already represents that claim. vouchers.valid_from is
 * untouched by the wheel-win path from here on and keeps its original job —
 * a single shared launch date for a public promo code that has no
 * user_vouchers row at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_vouchers', function (Blueprint $table) {
            $table->date('valid_from')->nullable()->after('acquired_date');
        });
    }

    public function down(): void
    {
        Schema::table('user_vouchers', function (Blueprint $table) {
            $table->dropColumn('valid_from');
        });
    }
};

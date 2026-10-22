<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vouchers tab, Defect Report 3 part B (Oct 2026): vouchers.on_wheel.
 *
 * The owner's (or a supervisor's, on their own branch) choice to include a
 * voucher on Spin & Win. Before this the Wheel column on the admin list was a
 * picture of Voucher::isOnWheel() with no control behind it, and the Edit
 * modal's "Active on spin wheel" box really posted is_active, so the only way
 * to take a voucher off the wheel was to switch the whole voucher off.
 *
 * Defaults to true, so every existing voucher is on the wheel exactly as it
 * was until someone switches it off, and a new points voucher still goes on
 * the wheel when it is created. It is one more condition, never a way round
 * the others: a wheel prize must still be active, have Points Required above
 * 0, and be neither expired nor used up (Voucher::isOnWheel(), scopeOnWheel()
 * and AuthController::winnableVoucherFor()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vouchers') && ! Schema::hasColumn('vouchers', 'on_wheel')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->boolean('on_wheel')->default(true)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vouchers', 'on_wheel')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->dropColumn('on_wheel');
            });
        }
    }
};

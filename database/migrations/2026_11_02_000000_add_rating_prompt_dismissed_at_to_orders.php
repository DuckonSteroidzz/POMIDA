<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The one server-side "this customer answered Not now" flag behind the
     * shared Order Completed pop-up. See App\Support\OrderCompletedPopup.
     *
     * Every order that is ALREADY completed is stamped, so deploying this does
     * not greet a returning customer with one pop-up per old order. Those orders
     * stay ratable from Order History, exactly as they are today.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('rating_prompt_dismissed_at')->nullable();
        });

        DB::table('orders')
            ->where('status', 'completed')
            ->update(['rating_prompt_dismissed_at' => DB::raw('COALESCE(updated_at, NOW())')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('rating_prompt_dismissed_at');
        });
    }
};

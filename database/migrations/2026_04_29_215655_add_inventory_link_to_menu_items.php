<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->unsignedBigInteger('inventory_item_id')->nullable()->after('subcategory_id');
            $table->decimal('inventory_amount_used', 10, 3)->default(0)->after('inventory_item_id');
            
            $table->foreign('inventory_item_id')
                  ->references('id')->on('inventory')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropForeign(['inventory_item_id']);
            $table->dropColumn(['inventory_item_id', 'inventory_amount_used']);
        });
    }
};
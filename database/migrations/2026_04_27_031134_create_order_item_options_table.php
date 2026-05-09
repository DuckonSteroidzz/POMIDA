<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order Item Options — Add-ons per item per order.
     * Example: Customer ordered Pizza + Extra Cheese (+85)
     */
    public function up(): void
    {
        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('menu_option_id');
            
            // Snapshot
            $table->string('option_name');
            $table->decimal('additional_price', 10, 2);
            
            $table->timestamps();
            
            $table->foreign('order_item_id')->references('id')->on('order_items')->onDelete('cascade');
            $table->foreign('menu_option_id')->references('id')->on('menu_options')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_options');
    }
};
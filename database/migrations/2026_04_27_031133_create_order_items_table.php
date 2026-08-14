<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order Items — Per item sa isang order.
     * 1 order pwedeng may 5 items, lahat naka-track dito.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('menu_item_id');
            
            // Snapshot ng item info (in case na-edit yung menu_item later)
            $table->string('item_name');                     // Pangalan nung in-order
            $table->decimal('item_price', 10, 2);            // Price nung in-order
            
            $table->integer('quantity')->default(1);
            $table->decimal('subtotal', 10, 2);              // price * qty + addons
            
            $table->text('special_instructions')->nullable();
            
            $table->timestamps();
            
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('menu_item_id')->references('id')->on('menu_items')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
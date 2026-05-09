<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Menu Items table — yung pinaka-core ng system!
     * Yung mga actual food items na binebenta ng Peachy.
     * 
     * Examples:
     *   - Four Cheese Pizza (P400)
     *   - Carbonara (P350)
     *   - Caramel Macchiato (P150)
     * 
     * Admin pwede mag-add/edit/delete via "Menu Items" page.
     * Customer makikita yan via category browsing.
     */
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            
            // Relationships
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('subcategory_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable(); // null = available sa lahat
            
            // Basic info
            $table->string('name');                          // ex: "Four Cheese Pizza"
            $table->text('description')->nullable();         // Para sa item details page
            $table->text('ingredients')->nullable();         // List ng ingredients
            
            // Pricing
            $table->decimal('price', 10, 2);                 // ex: 400.00
            $table->decimal('cost', 10, 2)->nullable();      // Para sa profit tracking (admin only)
            
            // Image
            $table->string('image')->nullable();
            
            // Display & Availability
            $table->integer('display_order')->default(0);
            $table->boolean('is_available')->default(true);  // Customer-facing — pwede i-hide
            $table->boolean('is_featured')->default(false);  // Highlight sa home page
            
            // Stock tracking
            $table->boolean('track_stock')->default(false);  // True kung may finite stocks
            $table->integer('stock_quantity')->default(0);
            $table->integer('low_stock_alert')->default(10); // Alert pag mababa
            
            // Preparation
            $table->integer('prep_time_minutes')->nullable(); // ex: 15 mins
            
            // Sales tracking
            $table->integer('total_sold')->default(0);       // Para sa "Best Seller" tracking
            
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->foreign('subcategory_id')->references('id')->on('subcategories')->onDelete('set null');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
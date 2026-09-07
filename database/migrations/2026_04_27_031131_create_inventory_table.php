<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventory — Raw materials/supplies tracking.
     * 
     * "I" sa POMIDA = Inventory In/Out tracking.
     * Examples: Flour, Cheese, Tomato Sauce, Cups, Boxes
     */
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('branch_id')->nullable();
            
            // Item info
            $table->string('item_name');
            $table->string('item_code', 50)->unique();       // SKU/Barcode
            $table->string('category', 100)->nullable();     // ex: "Dairy", "Vegetables"
            $table->text('description')->nullable();
            
            // Stock
            $table->decimal('quantity', 10, 2)->default(0);
            $table->string('unit', 20);                       // ex: "kg", "pcs", "L"
            $table->decimal('low_stock_alert', 10, 2)->default(10);
            
            // Pricing
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->string('supplier')->nullable();
            
            // Status
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Categories table — yung mga main category ng menu items.
     * Examples: Pizza, Pasta, Desserts, Beverages, Steak, etc.
     * 
     * Yung admin pwede mag-add/edit/delete ng categories.
     * Customer makikita yan sa menu page (categories grid).
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            
            // Basic info
            $table->string('name');                     // ex: "Pizza", "Pasta"
            $table->text('description')->nullable();
            $table->string('image')->nullable();        // Display image
            
            // Display order (para sa sorting sa menu page)
            $table->integer('display_order')->default(0);
            
            // Status — admin can hide/show
            $table->boolean('is_active')->default(true);
            
            // Branch-specific or global?
            // null = global (lahat ng branches), with value = specific branch lang
            $table->unsignedBigInteger('branch_id')->nullable();
            
            $table->timestamps();
            
            // Foreign key — connect sa branches table
            $table->foreign('branch_id')
                  ->references('id')
                  ->on('branches')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
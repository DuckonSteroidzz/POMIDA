<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Subcategories table — mas detailed sub-grouping ng categories.
     * Examples:
     *   Pizza (category) → Classic Pizza, Specialty Pizza (subcategories)
     *   Pasta (category) → White Sauce, Red Sauce (subcategories)
     *   Steak (category) → Beef, Pork, Chicken (subcategories)
     * 
     * Yung admin pwede mag-add ng subcategories sa "Add Sub Category" page.
     */
    public function up(): void
    {
        Schema::create('subcategories', function (Blueprint $table) {
            $table->id();
            
            // Belongs to a parent category
            $table->unsignedBigInteger('category_id');
            
            // Basic info
            $table->string('name');                     // ex: "Classic Pizza"
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            
            // Display order
            $table->integer('display_order')->default(0);
            
            // Status
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            // Foreign key — connect sa categories table
            $table->foreign('category_id')
                  ->references('id')
                  ->on('categories')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subcategories');
    }
};
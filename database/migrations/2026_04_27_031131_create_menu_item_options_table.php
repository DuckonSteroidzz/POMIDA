<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot table — Connects menu_items at menu_options.
     * 
     * Anong options pwede sa anong items?
     * Example: 
     *   - "Pizza" pwede may "Extra Cheese", "Extra Pepperoni"
     *   - "Coffee" pwede may "Extra Shot", "Extra Sugar"
     */
    public function up(): void
    {
        Schema::create('menu_item_options', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('menu_item_id');
            $table->unsignedBigInteger('menu_option_id');
            
            $table->timestamps();
            
            $table->foreign('menu_item_id')->references('id')->on('menu_items')->onDelete('cascade');
            $table->foreign('menu_option_id')->references('id')->on('menu_options')->onDelete('cascade');
            
            // Para hindi mag-duplicate
            $table->unique(['menu_item_id', 'menu_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_options');
    }
};
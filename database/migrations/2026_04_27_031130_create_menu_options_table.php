<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menu Options — Add-ons/Customizations sa menu items.
     * Examples: Extra Cheese (+P85), Extra Bacon (+P85), Ketchup (+P10)
     */
    public function up(): void
    {
        Schema::create('menu_options', function (Blueprint $table) {
            $table->id();
            
            $table->string('name');                          // ex: "Extra Cheese"
            $table->text('description')->nullable();
            $table->decimal('additional_price', 10, 2)->default(0);  // ex: 85.00
            
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_options');
    }
};
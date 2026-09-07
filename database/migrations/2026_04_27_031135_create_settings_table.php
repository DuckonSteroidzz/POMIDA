<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settings — System customization.
     * 
     * Yung "Customizable" feature na sinabi ng leader mo.
     * Admin pwede mag-edit ng:
     * - Business logo & name
     * - Primary/Negative colors
     * - Default settings
     * 
     * Key-value pair structure para flexible.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('branch_id')->nullable();  // null = global setting
            
            // Key-value structure
            $table->string('key', 100);                            // ex: "business_name"
            $table->text('value')->nullable();                     // ex: "Peachy Cakes and Deli Cafe"
            
            // Categorization
            $table->string('group', 50)->default('general');       // ex: "appearance", "business"
            $table->string('label')->nullable();                   // Display name sa admin UI
            $table->text('description')->nullable();
            $table->enum('type', ['text', 'color', 'image', 'number', 'boolean'])->default('text');
            
            $table->timestamps();
            
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            
            // Para hindi mag-duplicate (1 key per branch)
            $table->unique(['branch_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
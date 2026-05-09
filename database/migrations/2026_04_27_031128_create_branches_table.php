<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Branches table — para sa multi-branch support ng POMIDA.
     * Yung Peachy Cakes and Deli Cafe ay pwede magkaroon ng
     * maraming branches at bawat branch may sariling staff,
     * inventory, at orders.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            
            // Basic info
            $table->string('name');                    // ex: "Peachy Antipolo Branch"
            $table->string('code', 10)->unique();      // ex: "PCY-001"
            $table->text('address');
            $table->string('contact_number', 20)->nullable();
            $table->string('email')->nullable();
            
            // Operating hours
            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_main_branch')->default(false);
            
            // Image
            $table->string('image')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
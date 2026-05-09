<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discount Cards — PWD/Senior IDs ng customers.
     * Para sa 20% discount sa orders.
     */
    public function up(): void
    {
        Schema::create('discount_cards', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('user_id');
            
            $table->enum('type', ['pwd', 'senior']);
            $table->string('id_number', 50);
            $table->string('full_name');
            $table->string('id_image')->nullable();           // Photo ng ID
            
            // Verification
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable(); // Admin/staff ID
            
            // Status
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('verified_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_cards');
    }
};
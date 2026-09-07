<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('description')->nullable();
    $table->enum('discount_type', ['fixed', 'percent']);
    $table->decimal('discount_value', 8, 2);
    $table->integer('max_uses')->default(100);
    $table->integer('used_count')->default(0);
    $table->decimal('minimum_order', 8, 2)->default(0);
    $table->timestamp('expires_at')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};

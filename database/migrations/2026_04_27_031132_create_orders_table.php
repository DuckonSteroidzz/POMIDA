<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders — Yung core table ng POS!
     * "O" sa POMIDA = Order Management.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            
            // Order identifier
            $table->string('order_number', 20)->unique();    // ex: "ORD-20260427-001"
            $table->string('receipt_number', 20)->nullable();
            
            // Relationships
            $table->unsignedBigInteger('user_id')->nullable();      // Customer (null = walk-in)
            $table->unsignedBigInteger('branch_id');                 // Saan branch
            $table->unsignedBigInteger('processed_by')->nullable();  // Staff/admin na nag-process
            
            // Order type
            $table->enum('type', ['dine_in', 'pick_up', 'walk_in']);
            $table->string('table_number', 10)->nullable();          // For dine-in
            
            // Status
            $table->enum('status', ['pending', 'preparing', 'serving', 'completed', 'cancelled'])
                  ->default('pending');
            
            // Pricing
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            
            // Discount details
            $table->string('discount_type', 50)->nullable();         // 'pwd', 'senior', 'voucher'
            $table->unsignedBigInteger('voucher_id')->nullable();
            
            // Payment
            $table->enum('payment_method', ['cash', 'gcash', 'card'])->default('cash');
            $table->enum('payment_status', ['pending', 'paid', 'refunded'])->default('pending');
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->decimal('change_amount', 10, 2)->default(0);
            
            // Notes
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            
            // Timestamps for status changes
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('serving_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $table->foreign('processed_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
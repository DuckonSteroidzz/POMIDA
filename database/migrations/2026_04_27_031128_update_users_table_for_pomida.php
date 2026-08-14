<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * I-extend natin yung default users table ni Laravel.
     * Idadagdag natin yung POMIDA-specific columns para sa
     * customer/staff/admin roles, contact info, branch assignment, etc.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Role-based access (customer/staff/admin)
            $table->enum('role', ['customer', 'staff', 'admin'])->default('customer')->after('email');
            
            // Contact info
            $table->string('contact_number', 20)->nullable()->after('role');
            $table->text('address')->nullable()->after('contact_number');
            
            // Profile
            $table->string('profile_image')->nullable()->after('address');
            
            // For staff/admin — branch assignment
            $table->unsignedBigInteger('branch_id')->nullable()->after('profile_image');
            
            // Account status
            $table->boolean('is_active')->default(true)->after('branch_id');
            
            // Verification (para sa OTP feature)
            $table->string('verification_code', 6)->nullable()->after('is_active');
            $table->timestamp('verified_at')->nullable()->after('verification_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role',
                'contact_number',
                'address',
                'profile_image',
                'branch_id',
                'is_active',
                'verification_code',
                'verified_at',
            ]);
        });
    }
};
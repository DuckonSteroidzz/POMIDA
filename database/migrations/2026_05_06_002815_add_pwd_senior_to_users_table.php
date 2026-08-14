<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pwd_card_number')->nullable()->after('address');
            $table->string('pwd_name')->nullable()->after('pwd_card_number');
            $table->string('pwd_image')->nullable()->after('pwd_name');
            $table->string('senior_card_number')->nullable()->after('pwd_image');
            $table->string('senior_name')->nullable()->after('senior_card_number');
            $table->string('senior_image')->nullable()->after('senior_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pwd_card_number', 'pwd_name', 'pwd_image', 'senior_card_number', 'senior_name', 'senior_image']);
        });
    }
};
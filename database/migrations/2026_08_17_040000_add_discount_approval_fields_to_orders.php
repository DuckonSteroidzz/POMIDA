<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'discount_status')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('discount_status', 20)
                    ->default('approved')
                    ->after('discount_type');
            });
        }

        if (!Schema::hasColumn('orders', 'discount_beneficiary_expiration')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->date('discount_beneficiary_expiration')
                    ->nullable()
                    ->after('discount_beneficiary_card_number');
            });
        }
    }

    public function down(): void
    {
        // Intentionally left empty because the columns may already exist in this project.
    }
};

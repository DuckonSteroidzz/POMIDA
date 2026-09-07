<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'discount_card_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('discount_card_id')->nullable()->after('discount_type');
            });
        }

        if (!Schema::hasColumn('orders', 'discount_beneficiary_name')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('discount_beneficiary_name')->nullable()->after('discount_card_id');
            });
        }

        if (!Schema::hasColumn('orders', 'discount_beneficiary_card_number')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('discount_beneficiary_card_number', 100)->nullable()->after('discount_beneficiary_name');
            });
        }

        if (!Schema::hasColumn('orders', 'discount_id_image')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('discount_id_image')->nullable()->after('discount_beneficiary_card_number');
            });
        }

        if (!Schema::hasColumn('orders', 'discount_beneficiary_expiration')) {
    Schema::table('orders', function (Blueprint $table) {
        $table->date('discount_beneficiary_expiration')
            ->nullable()
            ->after('discount_id_image');
            });
        }

            if (!Schema::hasColumn('orders', 'discount_status')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('discount_status')
                        ->default('approved')
                        ->after('discount_beneficiary_expiration');
                });
            }

        // Only add the foreign key if the referenced table exists.
        if (Schema::hasTable('discount_cards') && Schema::hasColumn('orders', 'discount_card_id')) {
            try {
                Schema::table('orders', function (Blueprint $table) {
                    $table->foreign('discount_card_id')
                        ->references('id')
                        ->on('discount_cards')
                        ->nullOnDelete();
                });
            } catch (\Throwable $e) {
                // Existing foreign key; nothing to do.
            }
        }
    }

    public function down(): void
    {
        // Do not remove columns here because the existing
        // 2026_08_17_000002 migration owns them on this project.
    }
};

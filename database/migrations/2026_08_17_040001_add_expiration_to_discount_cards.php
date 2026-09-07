<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('discount_cards', 'expiration_date')) {
            Schema::table('discount_cards', function (Blueprint $table) {
                $table->date('expiration_date')
                    ->nullable()
                    ->after('id_image');
            });
        }
    }

    public function down(): void
    {
        // Intentionally left empty because the columns may already exist in this project.
    }
};

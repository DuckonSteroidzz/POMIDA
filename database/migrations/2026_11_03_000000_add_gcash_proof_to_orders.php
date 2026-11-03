<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The customer's GCash payment screenshot. The path points at the private
     * `local` disk (storage/app/gcash_proofs); see App\Services\GcashProof.
     *
     * Nullable and with no backfill: it is optional, and every existing order
     * simply has none.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('gcash_proof_path')->nullable();
            $table->timestamp('gcash_proof_uploaded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['gcash_proof_path', 'gcash_proof_uploaded_at']);
        });
    }
};

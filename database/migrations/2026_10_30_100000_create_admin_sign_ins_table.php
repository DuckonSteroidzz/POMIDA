<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account Settings for every portal role, Defect Report 5 item 3 (Oct 2026):
 * admin_sign_ins, the "Recent Sign-ins" card's source.
 *
 * One row per SUCCESSFUL sign-in of an admin, supervisor or staff account,
 * written by AdminAuthController::login() through App\Support\SignInRecorder.
 * Only what the card shows is kept: when, the browser / operating system /
 * device type read from the User-Agent at sign-in, and the IP address (the
 * page shows it masked). There is no location column, because nothing in the
 * app can say where an IP really is.
 *
 * The recorder keeps the newest 20 rows per user and deletes the rest, so the
 * table stays small. Deleting a portal account deletes its sign-ins with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_sign_ins')) {
            return;
        }

        Schema::create('admin_sign_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('signed_in_at');
            $table->string('browser', 60);
            $table->string('os', 60);
            $table->string('device_type', 20);
            $table->string('ip_address', 45)->nullable();

            $table->index(['user_id', 'signed_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_sign_ins');
    }
};

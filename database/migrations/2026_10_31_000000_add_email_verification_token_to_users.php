<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time confirmation links.
 *
 * A confirmation link used to stay usable for its whole 60 minutes even after
 * the account was confirmed, and every Resend left the older links working.
 * This column holds the SHA-256 of the token carried by the NEWEST link sent
 * to the account (User::issueEmailVerificationUrl()). It is replaced on every
 * send (older links stop working) and cleared the moment a link is used.
 *
 * Only a hash is stored, so a database read never yields a usable link. NULL
 * means "no live link": every existing row starts that way, and nothing about
 * existing accounts changes. The column is additive and nullable, so the
 * migration needs no backfill and is safe on the live database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('email_verification_token', 64)->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_verification_token');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grandfather in every account that existed before the confirmation-email
 * feature shipped.
 *
 * `users.email_verified_at` already existed as a nullable column (stock
 * Laravel scaffolding, unused until now) — this migration adds no column,
 * it only backfills data. Customer accounts created going forward get a
 * real verification email (AuthController::register() ->
 * User::sendEmailVerificationNotification()) and start out unverified;
 * portal accounts (admin/staff/supervisor) are stamped verified at creation
 * time because there is no confirmation-email loop for them. Every row that
 * predates both of those code paths would otherwise show as permanently
 * "unverified" with no email ever sent to explain why — this closes that gap
 * for every existing account, customer and portal alike, without requiring
 * anyone to click anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    /**
     * Deliberately a no-op. There is no way to tell a row this migration
     * backfilled apart from one a customer genuinely verified afterward by
     * clicking their email link, so rolling back cannot safely re-null
     * anyone without also un-verifying real, later verifications.
     */
    public function down(): void
    {
    }
};

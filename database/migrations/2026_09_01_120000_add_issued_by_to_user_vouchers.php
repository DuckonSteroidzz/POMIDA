<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHO issued a claim, for codes handed out manually by an admin.
 *
 * Walk-in Dine-In and Pick-Up customers have no account and may never play the
 * spin wheel, so there was no way to give one of them a voucher. An admin can
 * now mint a bearer claim code directly from the Vouchers page and read it out
 * or write it on a receipt.
 *
 * A claim minted that way is otherwise identical to a guest wheel win —
 * ownerless (user_id NULL), one code, spendable once — so the only thing that
 * needs recording is that a person, rather than a spin, put it into the world.
 *
 * WHY issued_by AND NOT A NEW AUDIT TABLE
 * ---------------------------------------
 * This project already has exactly this convention: table_access_codes carries
 * `issued_by` as a nullable FK to users, added by
 * 2026_08_23_170000_create_table_access_codes_table for the same question
 * ("who handed this code out?"). Reusing the column name and the same
 * nullOnDelete behaviour keeps one pattern instead of two, and `created_at`
 * already supplies the "when".
 *
 * NULL means "not issued by a person" — every wheel win, guest or account.
 * That is the overwhelming majority of rows, which is why the column is
 * nullable rather than defaulted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('user_vouchers', 'issued_by')) {
            return;
        }

        Schema::table('user_vouchers', function (Blueprint $table) {
            /*
             * nullOnDelete, not cascade: deleting a staff account must never
             * delete a customer's voucher. The claim outlives whoever issued
             * it; only the attribution is lost.
             */
            $table->foreignId('issued_by')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('user_vouchers', 'issued_by')) {
            return;
        }

        Schema::table('user_vouchers', function (Blueprint $table) {
            $table->dropForeign(['issued_by']);
            $table->dropColumn('issued_by');
        });
    }
};

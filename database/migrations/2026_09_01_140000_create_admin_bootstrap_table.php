<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row, ever: the record that this installation's first admin has been
 * created through the web bootstrap form.
 *
 * WHY A TABLE AND NOT JUST A SETTINGS ROW
 * ---------------------------------------
 * Two jobs, and the second is the reason for the table:
 *
 *   1. Record WHEN the bootstrap happened, which is all the brief asked for.
 *   2. Be the ATOMIC GATE that makes "exactly one admin can ever be created
 *      this way" true under concurrency.
 *
 * The obvious home for (1) was a `settings` row, but settings' unique index is
 * on (branch_id, key) and MySQL permits multiple NULLs in a unique index — so
 * two simultaneous inserts with branch_id NULL would both succeed and (2)
 * would silently not hold.
 *
 * Here the `singleton` column is unique and every insert writes the same
 * value, so the database itself refuses the second one. Two requests that both
 * read "zero admins exist" at the same instant will both try to insert; one
 * commits and the other gets an integrity violation and is refused. That is a
 * guarantee from the storage engine rather than from getting the check-then-act
 * ordering right in PHP, which is the part that is hard to be sure of.
 *
 * Deliberately NOT dropped or reset by anything. Once this row exists the web
 * bootstrap path is closed for the life of the installation, which is the
 * behaviour the owner asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_bootstrap')) {
            return;
        }

        Schema::create('admin_bootstrap', function (Blueprint $table) {
            $table->id();

            /*
             * Always the same value. Its uniqueness is the whole mechanism:
             * the table can never hold more than one row.
             */
            $table->string('singleton', 1)->unique();

            /*
             * The account that was created. nullOnDelete so removing that
             * admin later never deletes the record that bootstrap happened —
             * the gate must stay closed even if the account is gone.
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // The "when" the brief asked for.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_bootstrap');
    }
};

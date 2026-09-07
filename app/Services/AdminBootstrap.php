<?php

namespace App\Services;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * AdminBootstrap — the one place that answers "may this installation still
 * create its first admin from the web, and how?".
 *
 * THE PROBLEM
 * -----------
 * A brand-new deployment has no admin, so nobody can log in to create one.
 * Until now the only route in was `php artisan db:seed --class=AdminBootstrapSeeder`
 * with credentials in .env, which needs terminal access to the server. Someone
 * installing this fresh — the owner's "bought the system" case — may not have
 * that, and should not have to edit a database by hand either.
 *
 * THE RULE
 * --------
 * A "Create Account" link appears on the staff portal login page ONLY while
 * ZERO admin accounts exist. The moment one exists — created here, by the
 * seeder, or by any other means — this path is closed permanently: the link
 * disappears AND the route itself refuses.
 *
 * TWO INDEPENDENT CONDITIONS, BOTH REQUIRED
 * -----------------------------------------
 *   1. No user with role = 'admin' exists.
 *   2. The admin_bootstrap row does not exist.
 *
 * (1) alone would reopen the door if every admin were later deleted, which is
 * exactly the situation an attacker would try to engineer. (2) alone would not
 * cover an installation whose first admin came from the seeder and therefore
 * never wrote that row. Requiring both means the path opens only on a
 * genuinely fresh install and never reopens afterwards.
 *
 * WHY THE RACE IS SAFE
 * --------------------
 * Checking "are there zero admins?" and then creating one is a check-then-act,
 * and two simultaneous requests can both pass the check. Rather than trying to
 * order that correctly in PHP, the guarantee is handed to the database: the
 * insert into admin_bootstrap happens in the SAME transaction as the user
 * insert, and `singleton` is UNIQUE with every insert writing the same value.
 * The second concurrent transaction therefore fails on the unique index and
 * rolls back its user too. Exactly one admin can win, always.
 *
 * NOTHING HERE WEAKENS THE PASSWORD RULES
 * ---------------------------------------
 * Validation is PasswordPolicy::required() — the same rule object as staff
 * creation, the admin's own password change, the reset flow and registration.
 * Hashing is the User model's `hashed` cast, exactly as AdminController::
 * storeUser() does it. There is no second, weaker path.
 */
class AdminBootstrap
{
    /** The value written to admin_bootstrap.singleton. Any constant works. */
    private const SINGLETON = 'X';

    /**
     * Is the web bootstrap path still open?
     *
     * Called by the login page to decide whether to render the link, and again
     * by the endpoint before it does anything. The endpoint does NOT trust the
     * link having been shown — see store().
     */
    public static function isAvailable(): bool
    {
        return ! self::hasCompleted() && self::adminCount() === 0;
    }

    public static function adminCount(): int
    {
        return User::where('role', 'admin')->count();
    }

    public static function hasCompleted(): bool
    {
        return DB::table('admin_bootstrap')->exists();
    }

    /** When the web bootstrap happened, or null if it never did. */
    public static function completedAt(): ?string
    {
        return DB::table('admin_bootstrap')->value('created_at');
    }

    /**
     * The validation rules for the bootstrap form.
     *
     * Deliberately the same shape as AdminController::storeUser(), minus
     * branch_id: an admin is not branch-scoped (every existing admin row has
     * branch_id NULL), and a fresh install may have no branches yet, so
     * requiring one would make the form impossible to submit on exactly the
     * installation it exists for.
     */
    public static function rules(): array
    {
        return [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => PasswordPolicy::required(),
        ];
    }

    /**
     * Create the first admin, or return null if the path is no longer open.
     *
     * Returns null rather than throwing for the ordinary "someone beat you to
     * it" case, so the caller can render a plain message. A genuine database
     * failure still throws.
     */
    public static function create(array $validated): ?User
    {
        // Cheap pre-check. Not the guarantee — the transaction below is — but
        // it avoids doing work that is certain to be rolled back.
        if (! self::isAvailable()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($validated) {
                /*
                 * Re-check INSIDE the transaction. Between isAvailable() above
                 * and here, another request may have finished.
                 */
                if (self::adminCount() > 0 || self::hasCompleted()) {
                    return null;
                }

                $admin = User::create([
                    'name'      => $validated['name'],
                    'email'     => $validated['email'],
                    // Hashed exactly once by the model's `hashed` cast, the
                    // same way storeUser() does it. Do NOT add Hash::make().
                    'password'  => $validated['password'],
                    'role'      => 'admin',
                    'is_active' => true,
                    'branch_id' => null,
                ]);

                /*
                 * THE GATE. Unique index on `singleton`, so a second
                 * transaction reaching here at the same moment fails and its
                 * User insert is rolled back with it.
                 */
                DB::table('admin_bootstrap')->insert([
                    'singleton'  => self::SINGLETON,
                    'user_id'    => $admin->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $admin;
            });
        } catch (QueryException $e) {
            /*
             * The losing side of a race: the unique index refused the second
             * insert. Its user was rolled back, so nothing partial survives —
             * report it as "no longer available", which is exactly what it is.
             *
             * 23000 is the SQLSTATE class for an integrity constraint
             * violation. Anything else is a real fault and is re-thrown rather
             * than silently swallowed.
             */
            if ((string) $e->getCode() === '23000') {
                return null;
            }

            throw $e;
        }
    }
}

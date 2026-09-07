<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the very first admin account so a freshly-installed system has
 * someone who can log in and create staff.
 *
 * Replaces the old public /admin/register flow, which trusted a hardcoded
 * access code anyone could read from the repository.
 *
 * Usage:
 *   1. Set these in .env (do NOT commit secrets):
 *        ADMIN_BOOTSTRAP_EMAIL=admin@peachy.test
 *        ADMIN_BOOTSTRAP_PASSWORD=somethingStrong
 *        ADMIN_BOOTSTRAP_NAME="Site Admin"   # optional
 *   2. Run:
 *        php artisan db:seed --class=AdminBootstrapSeeder
 *
 * The seeder is idempotent: re-running it updates the password of the
 * existing admin instead of creating duplicates. After the first admin
 * exists you should rotate the env vars or remove them.
 */
class AdminBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $email    = env('ADMIN_BOOTSTRAP_EMAIL');
        $password = env('ADMIN_BOOTSTRAP_PASSWORD');
        $name     = env('ADMIN_BOOTSTRAP_NAME', 'Site Admin');

        if (!$email || !$password) {
            $this->command->error(
                'ADMIN_BOOTSTRAP_EMAIL and ADMIN_BOOTSTRAP_PASSWORD must be set in .env before running this seeder.'
            );
            return;
        }

        /*
         * The SAME password policy every other account-creating path uses —
         * 8+ characters with upper, lower, a number and a symbol.
         *
         * This used to be a bare `strlen($password) < 8`, which was weaker
         * than PasswordPolicy and meant the very first account on a new
         * installation — the most privileged one there is — could be created
         * with the weakest password in the system. Tightened 2026-09-01
         * alongside the web bootstrap form, so the CLI and web paths cannot
         * disagree about what an acceptable admin password is.
         */
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['password' => $password],
            ['password' => \App\Support\PasswordPolicy::required(false)]
        );

        if ($validator->fails()) {
            $this->command->error('ADMIN_BOOTSTRAP_PASSWORD is not acceptable:');

            foreach ($validator->errors()->get('password') as $message) {
                $this->command->error('  - ' . $message);
            }

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'      => $name,
                'password'  => Hash::make($password),
                'role'      => 'admin',
                'is_active' => true,
            ]
        );

        $this->command->info('Bootstrap admin account ready: ' . $email);
    }
}

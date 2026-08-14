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

        if (strlen($password) < 8) {
            $this->command->error('ADMIN_BOOTSTRAP_PASSWORD must be at least 8 characters.');
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

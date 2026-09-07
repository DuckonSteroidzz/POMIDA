<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The default seeder, i.e. what `php artisan db:seed` and
 * `php artisan migrate --seed` run.
 *
 * It must stay safe to run on the live site, so it only creates the rows a
 * fresh install genuinely cannot work without: the application settings.
 *
 * It deliberately does NOT create the first admin. That one needs a real
 * password and so is a separate, explicit step driven by .env:
 *
 *     php artisan db:seed --class=AdminBootstrapSeeder
 *
 * It also deliberately does NOT create demo menu items, inventory or sales.
 * DemoSalesSeeder exists for local demonstrations and fabricates orders; it is
 * never part of this default run.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
        ]);
    }
}

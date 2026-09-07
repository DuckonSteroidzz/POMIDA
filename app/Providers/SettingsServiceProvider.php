<?php

namespace App\Providers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Makes the branch-independent rows of the `settings` table available to every
 * Blade view as $appSettings.
 *
 * Two deployment hazards shaped how this is written:
 *
 *  1. It must never throw. Schema::hasTable() opens a database connection, and
 *     this runs during provider boot — before the exception handler can turn a
 *     failure into a friendly page. On a fresh server whose .env still has the
 *     wrong database credentials, an uncaught throw here makes every artisan
 *     command die too, including the `php artisan migrate` that would have
 *     been the next step. The operator then sees a database stack trace from
 *     an unrelated command and has no idea the credentials are the problem.
 *
 *  2. It must always share the variable, even when the lookup fails, so a view
 *     that reads $appSettings gets an empty collection rather than an
 *     "undefined variable" error on top of whatever already went wrong.
 *
 * Console runs are skipped outright: migrations, key:generate and storage:link
 * render no views, so the query would be pure cost — and it is exactly the
 * query that used to break them.
 */
class SettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        View::share('appSettings', $this->loadSettings());
    }

    private function loadSettings(): Collection
    {
        try {
            if (! Schema::hasTable('settings')) {
                return collect();
            }

            return \App\Models\Setting::whereNull('branch_id')->pluck('value', 'key');
        } catch (\Throwable $e) {
            // Unreachable database, missing table mid-migration, bad
            // credentials. The request will very likely fail anyway, but it
            // should fail in the controller where the error handler can render
            // a proper page, not here during boot.
            report($e);

            return collect();
        }
    }
}

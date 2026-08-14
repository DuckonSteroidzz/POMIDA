<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Schema;

class SettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (Schema::hasTable('settings')) {
            $settings = \App\Models\Setting::whereNull('branch_id')->pluck('value', 'key');
            View::share('appSettings', $settings);
        }
    }
}
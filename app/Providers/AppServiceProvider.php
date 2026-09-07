<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Emit https:// links when the deployment says the site is behind TLS.
         *
         * Driven by FORCE_HTTPS in .env rather than by APP_ENV, so the switch
         * can be flipped on the day the certificate is issued and flipped back
         * if anything goes wrong, without editing code. See config/app.php.
         */
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }
    }
}

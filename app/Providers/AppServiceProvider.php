<?php

namespace App\Providers;

use App\Support\StoreContact;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
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

        // StoreContact::forBranch() memoizes per request only. Static
        // properties survive across requests within the same PHP process
        // (notably the whole PHPUnit run), so clear it once each request
        // finishes to keep the memoization scoped to a single request, in
        // production and in tests alike.
        Event::listen(RequestHandled::class, function () {
            StoreContact::clearCache();
        });
    }
}

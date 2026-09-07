<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AdminMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'throttle.friendly' => \App\Http\Middleware\FriendlyThrottleResponse::class,
        ]);

        /*
         * throttle.friendly has to WRAP the throttle middleware to be able to
         * catch its exception, and listing it first on the route is not enough:
         * ThrottleRequests appears in Laravel's middleware priority list, so it
         * is sorted ahead of anything unlisted and its exception sails straight
         * past. Verified by reproduction — without this line the customer still
         * got the raw 429 page. Naming it here puts it back in front.
         */
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \App\Http\Middleware\FriendlyThrottleResponse::class
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * A database-layer failure (connection refused, most often — MySQL has
         * gone down repeatedly during development) is otherwise reported to
         * the visitor as the generic 500 page, whose own "Check my orders"
         * link would immediately fail again for the same reason. This is a
         * calmer, accurate answer instead: nothing was saved, try again
         * shortly, no link that depends on the very thing that just failed.
         *
         * ONLY changes what is SHOWN. Reporting (the log entry) is a
         * completely separate pipeline from rendering — registering a
         * renderable() here does not touch it, and nothing below calls
         * report() or reportable(), so every QueryException still reaches
         * the log exactly as it did before this file had any content.
         * Verified: Illuminate\Foundation\Http\Kernel::reportException() and
         * ::renderException() are two independent calls, report always first.
         *
         * Deliberately every QueryException, not only ones that look like a
         * connection failure: Laravel wraps both "could not connect" and "the
         * query itself was malformed" in the exact same exception class, so
         * there is no reliable way to tell those apart from here. Either way
         * the honest, calm answer to a visitor is the same — the system could
         * not do what they asked — and the exact cause is still in the log for
         * whoever maintains this to look up.
         */
        $exceptions->renderable(function (\Illuminate\Database\QueryException $e, $request) {
            return response()->view('errors.database-unavailable', [], 500);
        });

    })->create();
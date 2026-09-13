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
            /*
             * Same belt-and-braces reasoning as the TokenMismatch handler
             * below: the dine-in check-in flow must never dead-end on a
             * static crash page. A DB blip mid check-in (the sessions table
             * write, or TableOccupancy::claim()'s transaction) used to render
             * the generic database-unavailable page here — whose only exit is
             * "Back to home" — instead of the same "back to the form, try
             * again" redirect every other refusal in this flow already gets.
             * A visitor who reloads a database-unavailable page reached via
             * POST resubmits the same doomed request, which is exactly the
             * 500-flavoured half of the reported reload loop on this route.
             */
            if ($request->routeIs('customer.qr.process') || $request->is('customer/dineinqr')) {
                return redirect()->route('customer.dineinqr')
                    ->with('error', 'We could not check in your table just now. Please try your code again in a moment.')
                    ->withInput();
            }

            return response()->view('errors.database-unavailable', [], 500);
        });

        /*
         * The dine-in code-entry flow (/customer/dineinqr) is not allowed to
         * dead-end. Every other refusal there — a stale QR, an idled-out guest
         * session — redirects the customer back to the same code-entry form
         * with an inline message under session('error'); an expired CSRF token
         * is the one case that still fell through to the branded 419 page.
         *
         * dineinqr.blade.php now polls customer.session-token to keep that
         * form's token fresh, so this should not fire in practice. It is the
         * belt-and-braces path for when it still does (JS disabled, a laptop
         * asleep for a day): the SAME landing, the SAME error channel as
         * ERR_QR_STALE, never the 419 card.
         *
         * ONLY changes what is rendered for this one route. Token validation
         * still runs and still throws for every other endpoint — no middleware
         * is stripped and no route is added to any exemption list, so nothing
         * here weakens the protection. It is the same render-only shape as the
         * QueryException handler above.
         *
         * The handler has already turned the TokenMismatchException into a
         * 419 HttpException by the time renderable callbacks run (it keeps the
         * original as ->getPrevious()), so this matches on the 419 status —
         * whose only source in this app is a token mismatch.
         */
        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            if ($request->routeIs('customer.qr.process') || $request->is('customer/dineinqr')) {
                return redirect()->route('customer.dineinqr')
                    ->with('error', 'Your session was refreshed while this page was open. Please enter your table code again.')
                    ->withInput();
            }

            return null;
        });

    })->create();
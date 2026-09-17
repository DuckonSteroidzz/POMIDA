<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Purely observational: every 4xx/5xx response gets one line in
 * storage/logs/http-errors.log, then the response goes out exactly as the
 * controller/exception handler produced it. Nothing here can change a status
 * code, a response body, or an existing exception handler's behaviour — it
 * only ever reads the Response that already exists.
 *
 * Added to chase two reports that have no repro yet: mobile testing sees
 * frequent 400-500+ responses that laptop testing of the same flows does not,
 * and mobile "feels slow". This is the evidence-gathering half — a route,
 * method, status, User-Agent and actor for every failure, so the next pass
 * can target a real pattern instead of guessing.
 *
 * WHY A MIDDLEWARE, NOT AN EXCEPTION LISTENER
 * --------------------------------------------
 * An exception is already a Response by the time anything outside the pipe
 * that threw it can observe it — Illuminate\Routing\Pipeline renders it right
 * there. Confirmed by reproduction in this codebase already, for the same
 * reason FriendlyThrottleResponse inspects $next()'s return value instead of
 * catching an exception (see that class's docblock). Inspecting the response
 * here, after $next($request), sees BOTH a controller's own error response
 * (e.g. a 422 validation failure) and a rendered exception (a 404, 419, 500)
 * — one place, one log line shape, for every kind of failure.
 *
 * WHY APPENDED GLOBALLY
 * ----------------------
 * A 404 (unmatched route) never has route-group middleware attached — only
 * the global stack wraps it. Appending this there is the only way to also
 * catch 404s, which are exactly the kind of "wrong URL on a different device"
 * report this exists to catch.
 *
 * COST ON THE HAPPY PATH
 * -----------------------
 * One integer comparison. Every 200/300 response returns immediately after
 * that, before anything is built, formatted, or written.
 */
class LogHttpErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() < 400) {
            return $response;
        }

        // Logging must never be able to break the response it is observing —
        // a session that never started for this request (a bare 404 outside
        // any route group) or an unexpected Auth/session failure downgrades
        // to a skipped log line, never an error surfaced to the visitor.
        try {
            Log::channel('http_errors')->warning('HTTP ' . $response->getStatusCode(), [
                'status' => $response->getStatusCode(),
                'method' => $request->method(),
                'uri' => $request->path(),
                'route' => optional($request->route())->getName(),
                'user_agent' => $request->userAgent(),
                'actor' => $this->actor(),
                'order_type' => $request->hasSession() ? $request->session()->get('order_type') : null,
                'branch_id' => $request->hasSession() ? $request->session()->get('branch_id') : null,
                'ip' => $request->ip(),
                'time' => now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            // Swallowed deliberately — see class docblock.
        }

        return $response;
    }

    /**
     * Whichever of this app's three session guards (admin, customer, web) is
     * authenticated, or 'guest'. Checked in this order because a browser
     * could in principle carry more than one guard's session at once (an
     * admin tab and a customer tab in the same browser profile do not share
     * a guard), and admin/customer are the ones an error report needs most.
     */
    private function actor(): string
    {
        foreach (['admin', 'customer', 'web'] as $guard) {
            $user = Auth::guard($guard)->user();

            if ($user) {
                return $guard . ':' . $user->id . ':' . ($user->role ?? 'unknown');
            }
        }

        return 'guest';
    }
}

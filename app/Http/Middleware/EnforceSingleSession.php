<?php

namespace App\Http\Middleware;

use App\Support\SingleSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a browser's login when the same account has signed in somewhere else,
 * and refuses any leftover "remember me" cookie.
 *
 * Appended to the `web` group, so it runs on every page, form post and
 * background poll, after StartSession and before any route middleware (the
 * `admin` door, role checks) or controller. A session ended here never reaches
 * the action it was trying to perform, so an old phone mid-checkout places no
 * order.
 *
 * See App\Support\SingleSession for the rule itself and why it is built on
 * users.remember_token.
 */
class EnforceSingleSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        foreach (array_keys(SingleSession::GUARDS) as $guard) {
            $this->refuseRememberCookie($request, $guard);
        }

        foreach (array_keys(SingleSession::GUARDS) as $guard) {
            $reason = SingleSession::endedReason($request, $guard);

            if ($reason !== null) {
                return $this->end($request, $guard, $reason);
            }
        }

        return $next($request);
    }

    /**
     * Logins no longer issue "remember me" cookies, because closing the browser
     * must end the login. One issued before that change can still be in a
     * browser for up to 400 days, and the guard would silently sign its holder
     * back in from it, which is exactly the "still logged in after reopening
     * Chrome" report. It is removed from the request before any guard can read
     * it, and the browser is told to delete it.
     */
    private function refuseRememberCookie(Request $request, string $guard): void
    {
        $name = Auth::guard($guard)->getRecallerName();

        if ($request->cookies->has($name)) {
            $request->cookies->remove($name);
            Cookie::queue(Cookie::forget($name));
        }
    }

    private function end(Request $request, string $guard, string $reason): Response
    {
        // logoutCurrentDevice(), NOT logout(). logout() rotates remember_token,
        // and that token now belongs to the device that just signed in.
        // Rotating it would sign THAT device out too, and the two browsers
        // would keep ending each other.
        Auth::guard($guard)->logoutCurrentDevice();

        // The same full reset every logout in this app performs: the old
        // session id is discarded and the CSRF token regenerated.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $url = SingleSession::loginUrl($guard, $reason);

        if ($this->isBackgroundRequest($request)) {
            // A redirect would be followed silently by fetch(), and the board
            // refresh deliberately ignores a login page it did not ask for.
            // partials/session-guard.blade.php watches for this header on
            // every same-origin fetch/XHR and moves the whole page to $url.
            return response()->json([
                'success'  => false,
                'message'  => SingleSession::MESSAGES[$reason],
                'redirect' => $url,
            ], 401, ['X-Session-Ended' => $url]);
        }

        return redirect()->to($url);
    }

    /**
     * A fetch()/XHR rather than the browser loading a page or submitting a
     * form. Sec-Fetch-Mode is sent by every current browser and is exact.
     * Without it, fall back to the headers the app's own fetch() calls set.
     */
    private function isBackgroundRequest(Request $request): bool
    {
        $mode = (string) $request->headers->get('Sec-Fetch-Mode', '');

        if ($mode !== '') {
            return $mode !== 'navigate';
        }

        return $request->expectsJson() || $request->ajax();
    }
}

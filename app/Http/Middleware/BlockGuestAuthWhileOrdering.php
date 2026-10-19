<?php

namespace App\Http\Middleware;

use App\Support\DineInJoin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Log in / Create account are closed to a Dine-In guest whose own order is
 * still in progress (item H, Oct 2026 defect report).
 *
 * Signing in replaces the browser session, and with it the guest's claim on
 * the order staff are still preparing: it vanished from the customer's Orders
 * page while it stayed on the staff board. The buttons in the UI open a
 * prompt instead of navigating, but a button is only a courtesy: this guard
 * is the real protection, on the login and register routes themselves (GET
 * and POST, so a typed URL, a stale tab or a hand-made request is stopped
 * the same way).
 *
 * The decision is DineInJoin::ongoingOrder(): a guest seated at a table with
 * an order of this session that is not completed, cancelled or rejected.
 * Signed-in customers and pick-up sessions never match. Once the order is
 * completed or cancelled the routes behave exactly as before.
 *
 * It sends the guest back where they came from with the one fixed message,
 * unless that page is itself a sign-in page (a stale tab would loop), then to
 * their Orders page.
 */
class BlockGuestAuthWhileOrdering
{
    /** Pages the guard must never send someone back to. */
    private const AUTH_PATHS = ['customer/login', 'customer/register', 'customer/join/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! DineInJoin::blocksGuestAuth()) {
            return $next($request);
        }

        return redirect($this->destination($request))
            ->with(DineInJoin::NOTICE_KEY, DineInJoin::MSG_ONGOING_ORDER);
    }

    private function destination(Request $request): string
    {
        // Where the guest really came from: the browser's referer and nothing
        // else. url()->previous() would invent the site root for a typed address,
        // and the session's own "previous URL" is whichever GET ran last, which
        // is often a background poll (the bell's unread-count), not a page.
        $previous = (string) $request->headers->get('referer');
        $parts = $previous !== '' ? parse_url($previous) : false;

        if (
            is_array($parts)
            && ($parts['host'] ?? null) === $request->getHost()
            && ! $this->isAuthPath(ltrim((string) ($parts['path'] ?? '/'), '/'))
            && ! ($request->isMethod('GET') && ($parts['path'] ?? null) === '/' . $request->path())
        ) {
            return $previous;
        }

        return route('customer.orders');
    }

    private function isAuthPath(string $path): bool
    {
        foreach (self::AUTH_PATHS as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}

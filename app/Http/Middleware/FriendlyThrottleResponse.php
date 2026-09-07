<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turn a rate-limit rejection on a customer action into an answer the customer
 * can act on, instead of a full-page error that loses where they were.
 *
 * The reported problem: hitting the place-order limit replaced the cart with
 * the branded 429 page, whose only way out was "Back to home". To the customer
 * that reads as being logged out mid-order — their table context is off-screen,
 * their order did not happen, and nothing on the page says either of those
 * things. (The cart itself was never actually lost: the limiter rejects the
 * request before the controller runs, so session('cart') is untouched. The
 * customer had no way to know that.)
 *
 * So for these endpoints the rejection comes back as a redirect to the page
 * they were on, carrying a plain-language error — cart intact, table intact,
 * one tap to retry. AJAX callers get the same message as JSON with the 429
 * status preserved, since they render it inline themselves. Either way the
 * response carries X-RateLimit-Rejected, so a rate-limit refusal is still
 * recognisable as one in logs and in tests.
 *
 * Why this inspects the RESPONSE rather than catching the exception
 * ----------------------------------------------------------------
 * ThrottleRequests throws, but Illuminate\Routing\Pipeline catches every
 * exception at the pipe that threw it and renders it through the exception
 * handler right there. By the time an outer middleware sees anything it is an
 * ordinary 429 Response, not an exception — verified by reproduction: a
 * try/catch here never fired once, and the customer still got the raw 429
 * page. The catch is kept below for any caller that runs this outside the
 * routing pipeline.
 *
 * Listed before the throttle middleware on the route, and registered ahead of
 * ThrottleRequests in the middleware priority list (see bootstrap/app.php),
 * so it genuinely wraps it:
 *     ->middleware(['throttle.friendly', 'throttle:place-order'])
 */
class FriendlyThrottleResponse
{
    public function handle(Request $request, Closure $next, ?string $message = null): Response
    {
        try {
            $response = $next($request);
        } catch (ThrottleRequestsException $e) {
            return $this->rejection($request, $message, $e->getHeaders()['Retry-After'] ?? null);
        }

        if ($response->getStatusCode() === 429) {
            return $this->rejection($request, $message, $response->headers->get('Retry-After'));
        }

        return $response;
    }

    private function rejection(Request $request, ?string $message, $retryAfter): Response
    {
        $text = $message ?: 'You are doing that a little too quickly. '
            . 'Please wait a moment and try again.';

        if ($retryAfter) {
            $text .= ' You can try again in about ' . (int) $retryAfter . ' second(s).';
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $text,
            ], 429)->header('X-RateLimit-Rejected', '1');
        }

        return back()
            ->withInput()
            ->withErrors(['error' => $text])
            ->withHeaders(['X-RateLimit-Rejected' => '1']);
    }
}

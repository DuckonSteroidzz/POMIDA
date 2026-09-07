<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate admin-area routes by role.
 *
 * Usage in routes:
 *   ->middleware('role:admin')           // admin only
 *   ->middleware('role:admin,staff')     // either role
 *
 * This runs INSIDE the 'admin' middleware group, so AdminMiddleware has
 * already confirmed the user is logged in through the admin guard and is
 * active. This middleware only decides whether their role is allowed on
 * this particular route.
 *
 * Behaviour:
 *  - No authenticated user      → redirect to admin.login
 *  - Inactive (is_active=false) → logged out and bounced to admin.login
 *  - Wrong role                 → bounced to admin.home with a friendly
 *                                 flash error (not a raw 403 page)
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Resolve through the admin guard — the whole admin area authenticates
        // there, not through the default (customer) guard.
        $user = Auth::guard('admin')->user();

        if (!$user) {
            return redirect()->route('admin.login')
                ->withErrors(['email' => 'Please login to access the staff portal.']);
        }

        if (!$user->is_active) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')
                ->withErrors(['email' => 'Your account has been deactivated.']);
        }

        if (!in_array($user->role, $roles, true)) {
            // Friendly bounce instead of a raw 403 — this is a live-demo app.
            return redirect()->route('admin.home')
                ->with('error', "You don't have permission to access that.");
        }

        return $next($request);
    }
}

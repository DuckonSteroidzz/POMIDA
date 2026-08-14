<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate admin-area routes by role.
 *
 * Usage in routes:
 *   ->middleware('role:admin')           // admin only
 *   ->middleware('role:admin,staff')     // either role
 *
 * Behaviour:
 *  - No authenticated user      → redirect to admin.login
 *  - Wrong role                 → HTTP 403 with a clear message
 *  - Inactive (is_active=false) → logged out and bounced to admin.login
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('admin.login');
        }

        if (!$user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('admin.login')
                ->withErrors(['email' => 'Your account has been deactivated.']);
        }

        if (!in_array($user->role, $roles, true)) {
            abort(403, 'You do not have permission to access this page.');
        }

        return $next($request);
    }
}

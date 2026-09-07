<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Check if logged in through the admin guard
        if (!Auth::guard('admin')->check()) {
            return redirect()->route('admin.login')
                ->withErrors([
                    'email' => 'Please login to access the staff portal.',
                ]);
        }

        $user = Auth::guard('admin')->user();

        // Only admin and staff can access the staff portal
        if (!in_array($user->role, ['admin', 'staff'])) {
            Auth::guard('admin')->logout();

            return redirect()->route('admin.login')
                ->withErrors([
                    'email' => 'You are not authorized to access the staff portal.',
                ]);
        }

        // Check if account is active
        if (!$user->is_active) {
            Auth::guard('admin')->logout();

            return redirect()->route('admin.login')
                ->withErrors([
                    'email' => 'Your account has been deactivated.',
                ]);
        }

        return $next($request);
    }
}
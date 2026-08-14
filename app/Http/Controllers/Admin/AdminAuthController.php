<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAuthController extends Controller
{
    // ══════════ SHOW PAGES ══════════

    public function showLogin()
    {
        return view('admin.login');
    }

    // ══════════ AUTHENTICATION LOGIC ══════════
    //
    // Public admin/staff registration is intentionally NOT supported. Self-
    // service signup with access codes was removed because anyone who guessed
    // (or leaked) the code could mint admin accounts. Staff users are now
    // created by an authenticated admin from /admin/users. The first admin
    // is created with `php artisan db:seed --class=AdminBootstrapSeeder`.

    /**
     * Process admin/staff login
     */
    public function login(Request $request)
    {
        // 1. Validate
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // 2. Get remember me checkbox value
        $remember = $request->has('remember');

        // 3. Try to login
        if (Auth::attempt($credentials, $remember)) {
            $user = Auth::user();

            // 4. Check if account is active
            if (!$user->is_active) {
                Auth::logout();
                return back()->withErrors([
                    'email' => 'Your account has been deactivated. Please contact the system administrator.',
                ]);
            }

            // 5. Check if user is admin or staff (NOT customer)
            if ($user->role === 'customer') {
                Auth::logout();
                return back()->withErrors([
                    'email' => 'Customer accounts cannot login here. Please use the customer login page.',
                ]);
            }

            // 6. Regenerate session for security
            $request->session()->regenerate();

            // 7. Redirect to admin home
            return redirect()->route('admin.home')
                ->with('success', 'Welcome back, ' . ucfirst($user->role) . ' ' . $user->name . '!');
        }

        // 8. Invalid credentials
        return back()->withErrors([
            'email' => 'Invalid email or password.',
        ])->withInput($request->only('email'));
    }

    /**
     * Logout admin/staff
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')
            ->with('success', 'You have been logged out.');
    }
}
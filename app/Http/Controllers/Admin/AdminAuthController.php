<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    // ══════════ SHOW PAGES ══════════

    public function showLogin()
    {
        return view('admin.login');
    }

    public function showRegister()
    {
        return view('admin.register');
    }

    // ══════════ AUTHENTICATION LOGIC ══════════

    /**
     * Process admin/staff registration
     * Code-based role assignment for security
     */
    public function register(Request $request)
    {
        // 1. Validate input fields
        $validated = $request->validate([
            'code' => 'required|string',
            'username' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
        ], [
            'code.required' => 'Access code is required.',
            'username.required' => 'Username is required.',
            'email.unique' => 'This email is already registered.',
            'password.min' => 'Password must be at least 6 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        // 2. Define valid access codes
        $validAdminCode = 'PEACHY-ADMIN-2026';
        $validStaffCode = 'PEACHY-STAFF-2026';

        // 3. Check the code and assign role
        if ($validated['code'] === $validAdminCode) {
            $role = 'admin';
        } elseif ($validated['code'] === $validStaffCode) {
            $role = 'staff';
        } else {
            return back()->withErrors([
                'code' => 'Invalid access code. Please contact the system administrator.',
            ])->withInput();
        }

        // 4. Create the user
        $user = User::create([
            'name' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $role,
            'is_active' => true,
        ]);

        // 5. Auto-login after successful registration
        Auth::login($user);

        // 6. Redirect to admin home
        return redirect()->route('admin.home')
            ->with('success', 'Welcome, ' . ucfirst($user->role) . ' ' . $user->name . '!');
    }

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
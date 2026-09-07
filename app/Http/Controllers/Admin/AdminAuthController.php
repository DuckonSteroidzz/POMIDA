<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HandlesPasswordReset;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAuthController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | PASSWORD RESET
    |--------------------------------------------------------------------------
    |
    | showForgotPassword / forgotPassword / showVerification / verifyCode /
    | resendCode / showNewPassword / updatePassword all come from this trait.
    | The hooks below scope the flow to ADMIN accounts only, so neither a
    | customer's nor a staff member's email can be reset through this portal.
    |
    */
    use HandlesPasswordReset;

    /**
     * ADMIN ONLY — deliberately not 'staff'.
     *
     * Changed 2026-09-01. Staff have no self-service password path anywhere in
     * the system: the account page is admin-only, and this closes the other
     * door. Leaving staff resettable here would have made forgot-password the
     * self-service route by another name — a staff member could reset their own
     * password from their own inbox without the admin involved, which is
     * exactly what the owner's decision rules out. A staff password is now set
     * only by an admin, from /admin/users.
     *
     * WHY THIS IS THE RIGHT PLACE TO ENFORCE IT
     * ------------------------------------------
     * resolveResettableUser() is the single gate all three reset steps go
     * through — forgotPassword() (issue a code), resendCode() (issue another),
     * and updatePassword() (write the new password). Narrowing the role list
     * here closes all three at once, and it cannot be bypassed by holding a
     * session from before the change: updatePassword() re-resolves the user and
     * will now refuse.
     *
     * AND WHY IT DOES NOT LEAK WHICH EMAILS ARE STAFF
     * -----------------------------------------------
     * This is the property that matters most, and it comes for free precisely
     * because the change is here rather than in a new branch. A staff email now
     * returns null from resolveResettableUser(), so it falls into the SAME
     * `if (! $user)` arm that a completely unknown email already took. Same
     * message, same status, same redirect, same session state, same number of
     * queries — because it is literally the same code path, not a parallel one
     * that happens to look similar.
     *
     * Do NOT "improve" this by adding a "staff cannot reset their password"
     * message. That would turn the form into an oracle for which addresses
     * belong to staff, which is an account-enumeration flaw. The uniformity is
     * pinned by ResetCodeBruteForceTest and by StaffPasswordControlTest.
     *
     * resetAccountLabel() is deliberately left as "admin or staff account" for
     * the same reason: the wording a stranger sees must not change.
     */
    protected function resettableRoles(): array
    {
        return ['admin'];
    }

    protected function resetSessionKey(): string
    {
        return 'admin_password_reset';
    }

    protected function resetViewPrefix(): string
    {
        return 'admin';
    }

    protected function resetRoutePrefix(): string
    {
        return 'admin';
    }

    protected function resetAccountLabel(): string
    {
        return 'admin or staff account';
    }

    // ══════════ SHOW PAGES ══════════

    public function showLogin()
    {
        return view('admin.login', [
            // Drives the "Create the first admin account" link. False on every
            // installation that already has an admin, which is all of them
            // after the first few minutes of their life.
            'canBootstrapAdmin' => \App\Services\AdminBootstrap::isAvailable(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | FIRST-RUN ADMIN BOOTSTRAP
    |--------------------------------------------------------------------------
    |
    | A brand-new deployment has no admin, so nobody can sign in to create one.
    | These two methods are the web route in, open ONLY while zero admins exist
    | and closed permanently once one does.
    |
    | Both re-ask App\Services\AdminBootstrap rather than trusting the page that
    | linked here — see the note on store() below. The rules, the password
    | policy and the hashing are all the ones already used for staff creation;
    | nothing here is a second, weaker implementation.
    |
    */

    public function showBootstrap()
    {
        if (! \App\Services\AdminBootstrap::isAvailable()) {
            return $this->bootstrapClosed();
        }

        return view('admin.bootstrap');
    }

    /**
     * Create the first admin.
     *
     * The availability check here is NOT a convenience duplicate of the one on
     * the login page. That one decides whether to draw a link; this one is the
     * control. A direct POST never went past a rendered page, and the page
     * could have been rendered minutes ago on a system that has since been set
     * up. AdminBootstrap::create() then re-checks a third time inside the
     * transaction, where the database can actually enforce it.
     */
    public function store(Request $request)
    {
        if (! \App\Services\AdminBootstrap::isAvailable()) {
            return $this->bootstrapClosed();
        }

        $validated = $request->validate(
            \App\Services\AdminBootstrap::rules(),
            [
                'password.confirmed' => 'The password and its confirmation do not match.',
                'email.unique'       => 'An account already exists with that email address.',
            ]
        );

        $admin = \App\Services\AdminBootstrap::create($validated);

        if (! $admin) {
            // Lost a race, or the system was set up between the form loading
            // and it being submitted. Either way the path is closed now.
            return $this->bootstrapClosed();
        }

        return redirect()->route('admin.login')->with(
            'success',
            'Administrator account created for ' . $admin->email . '. Please sign in.'
        );
    }

    /**
     * One refusal for every way of finding this path closed, so a direct
     * request and an expired form look identical.
     */
    private function bootstrapClosed()
    {
        return redirect()->route('admin.login')->withErrors([
            'email' => 'This system has already been set up. Please sign in, or ask an administrator for an account.',
        ]);
    }

    public function showRegister()
    {
        return view('admin.register');
    }

    // ══════════ AUTHENTICATION LOGIC ══════════
    //
    // Public admin/staff registration is intentionally NOT supported. Self-
    // service signup with access codes was removed because anyone who guessed
    // (or leaked) the code could mint admin accounts. Staff users are created
    // by an authenticated admin from /admin/users.
    //
    // The first admin comes from either the first-run bootstrap above (open
    // only while zero admins exist, then closed permanently) or
    // `php artisan db:seed --class=AdminBootstrapSeeder` for a headless
    // deploy. Both apply the same PasswordPolicy; there is no default
    // credential anywhere in this system.

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
        //
        // boolean(), not has(): has() is true for ANY present value, so a
        // client that posts remember=0 (or an empty hidden companion field,
        // the usual way a form makes an unchecked box explicit) would have
        // been remembered against the user's wishes. boolean() runs the value
        // through FILTER_VALIDATE_BOOLEAN, so "1"/"on"/"true" mean yes and
        // "0"/""/absent mean no.
        $remember = $request->boolean('remember');

        // 3. Try to login
        if (Auth::guard('admin')->attempt($credentials, $remember)) {
            $user = Auth::guard('admin')->user();

            // 4. Check if account is active
            if (!$user->is_active) {
                Auth::guard('admin')->logout();
                return back()->withErrors([
                    'email' => 'Your account has been deactivated. Please contact the system administrator.',
                ]);
            }

            // 5. Check if user is admin or staff (NOT customer)
            if ($user->role === 'customer') {
                Auth::guard('admin')->logout();
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
     *
     * Must log out through the ADMIN guard, not the default one. `Auth::logout()`
     * resolves the default guard ('web'), which nobody in the staff portal is
     * authenticated on, so it cleared nothing and — critically — left the
     * `remember_admin_*` cookie in place. Invalidating the session alone does not
     * help: on the very next request the admin guard finds that recaller cookie
     * and silently logs the person straight back in. On a shared counter PC that
     * means "Logout" did not log anyone out.
     *
     * Auth::guard('admin')->logout() clears the session key, forgets the
     * admin recaller cookie, and cycles the user's remember_token so any other
     * copy of that cookie is dead too.
     */
    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')
            ->with('success', 'You have been logged out.');
    }
}
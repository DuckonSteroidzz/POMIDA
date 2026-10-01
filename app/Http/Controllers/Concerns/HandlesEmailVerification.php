<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Support\VerificationFlow;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Customer-only Laravel MustVerifyEmail flow — the signed-link click, the
 * "check your email" page, and the two ways to ask for a fresh link.
 *
 * NOT the same feature as HandlesPasswordReset's `verification` /
 * `verification.post` / `verification.resend` routes despite the similar
 * name: that trait is a hand-rolled 6-digit-code "forgot password" flow that
 * writes to `password_reset_tokens`. This one marks `users.email_verified_at`
 * and is reached by clicking a link in an email, never by typing a code.
 *
 * THIS IS NOW A LOGIN GATE (October 2026). It reverses the earlier decision
 * that unverified customers were not blocked. Sign-up no longer signs the
 * customer in (AuthController::register()), and AuthController::login()
 * refuses a correct password on an unverified customer account. Portal
 * accounts (admin/supervisor/staff) and Dine-In guests are not affected:
 * portal logins go through AdminAuthController, and guests never log in.
 *
 * Verifying never signs anyone in. The link only marks the address confirmed
 * and sends the customer to the right login page for the door they signed up
 * through (App\Support\VerificationFlow).
 */
trait HandlesEmailVerification
{
    /**
     * The signed link a verification email points at.
     *
     * Looks the target user up directly from the URL id rather than
     * requiring an active `customer` session on this device/browser, because
     * the link is most often opened from a phone's mail app — not the
     * browser that registered. The `signed` route middleware already
     * rejects a tampered or expired link before this runs. That covers the
     * `flow` query value too. The hash check below additionally catches a
     * link whose email has since changed.
     */
    public function verifyEmail(Request $request, $id, $hash)
    {
        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            abort(403, 'This verification link is invalid.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        // The "check your email" page's memory of this address is finished with.
        session()->forget([VerificationFlow::SESSION_EMAIL, VerificationFlow::SESSION_FLOW]);

        // Only reachable by a session that was already signed in before the
        // gate existed (the account page's resend button). Nothing new is
        // signed in here.
        if (Auth::guard('customer')->check() && Auth::guard('customer')->id() === $user->id) {
            return redirect()->route('customer.menu')
                ->with('success', 'Your email address has been verified. Thanks!');
        }

        return VerificationFlow::redirectAfterVerification(
            VerificationFlow::normalize($request->query('flow'))
        );
    }

    /**
     * "Check your email to activate your account."
     *
     * The address shown is only ever the one this browser typed into sign-up,
     * into a correct-password login, or into the Resend box. It is never
     * looked up from the database.
     */
    public function showVerificationPending()
    {
        return view('customer.verify-pending', [
            'email' => session(VerificationFlow::SESSION_EMAIL),
            'loginUrl' => VerificationFlow::loginUrl(VerificationFlow::pending()),
        ]);
    }

    /**
     * The Resend button on the check-your-email page.
     *
     * Nobody is signed in, and the address comes from the form, so every
     * path returns the SAME redirect and the SAME sentence. That holds for an
     * address with no account, an already-verified account, a portal
     * account, and an unverified customer. The one real difference, the
     * mail send, happens after the response has gone out, so the response
     * time does not give it away either.
     */
    public function requestEmailVerification(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = trim((string) $request->input('email'));
        $flow = VerificationFlow::pending();

        $user = User::where('email', $email)
            ->where('role', 'customer')
            ->where('is_active', true)
            ->whereNull('email_verified_at')
            ->first();

        if ($user) {
            // One-shot: Laravel keeps terminating callbacks for the life of
            // the application object, so without this flag a test making
            // several requests would send the email again on each one.
            $sent = false;

            app()->terminating(function () use (&$sent, $user, $flow) {
                if ($sent) {
                    return;
                }

                $sent = true;
                $user->sendEmailVerificationNotification($flow);
            });
        }

        session()->put(VerificationFlow::SESSION_EMAIL, $email);
        session()->put(VerificationFlow::SESSION_FLOW, $flow);

        return redirect()->route('customer.email-verification.pending')
            ->with('success', VerificationFlow::RESEND_ANSWER);
    }

    /**
     * "Resend verification email", from the customer's own account page.
     *
     * Requires an active customer session and acts only on that session's
     * own account — it never takes an email as input — which is what keeps
     * this from becoming an address-enumeration oracle. Since the login gate,
     * only a session that was signed in before the gate shipped can still be
     * unverified here.
     */
    public function resendEmailVerification(Request $request)
    {
        $user = Auth::guard('customer')->user();

        if (! $user) {
            return redirect()->route('customer.login');
        }

        if ($user->hasVerifiedEmail()) {
            return back()->with('success', 'Your email is already verified.');
        }

        $user->sendEmailVerificationNotification(VerificationFlow::fromSession());

        return back()->with('success', 'Verification email sent! Please check your inbox.');
    }
}

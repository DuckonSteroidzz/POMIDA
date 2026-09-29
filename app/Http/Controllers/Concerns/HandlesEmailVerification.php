<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Customer-only Laravel MustVerifyEmail flow — the signed-link click and the
 * "resend" action from the account page.
 *
 * NOT the same feature as HandlesPasswordReset's `verification` /
 * `verification.post` / `verification.resend` routes despite the similar
 * name: that trait is a hand-rolled 6-digit-code "forgot password" flow that
 * writes to `password_reset_tokens`. This one marks `users.email_verified_at`
 * and is reached by clicking a link in an email, never by typing a code.
 *
 * Deliberately no login/order gate: an unverified customer can still sign in
 * and order (see AuthController::login(), untouched). This only marks the
 * account verified and lets a customer ask for a fresh link.
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
     * rejects a tampered or expired link before this runs; the hash check
     * below additionally catches a link whose email has since changed.
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

        if (Auth::guard('customer')->check() && Auth::guard('customer')->id() === $user->id) {
            return redirect()->route('customer.menu')
                ->with('success', 'Your email address has been verified. Thanks!');
        }

        return redirect()->route('customer.login')
            ->with('success', 'Your email address has been verified. Please log in.');
    }

    /**
     * "Resend verification email", from the customer's own account page.
     *
     * Requires an active customer session and acts only on that session's
     * own account — it never takes an email as input — which is what keeps
     * this from becoming an address-enumeration oracle.
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

        $user->sendEmailVerificationNotification();

        return back()->with('success', 'Verification email sent! Please check your inbox.');
    }
}

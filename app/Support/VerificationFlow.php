<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;

/**
 * Which door a new customer came in through — Pick-Up or Dine-In — carried
 * from sign-up to the moment they click the confirmation link in their email.
 *
 * WHY IT RIDES IN THE LINK
 * ------------------------
 * The link is very often opened on a different device or browser from the one
 * that registered (the phone's mail app), where the registering session and
 * its `order_type` do not exist. So the value is put into the signed
 * verification URL itself (User::sendEmailVerificationNotification()). The
 * `signed` route middleware covers every query parameter, so editing `flow`
 * invalidates the signature and the link is refused before any code here
 * runs; it cannot be tampered with.
 *
 * Even so, the value is treated as untrusted and matched against an
 * allow-list: anything missing or unknown (for example a link sent before this
 * existed, which has no `flow` at all) falls back to Pick-Up, the
 * account-only default that needs no table.
 *
 * WHAT IT NEVER CARRIES
 * ---------------------
 * A table number or branch. A table is claimed by its own permanent code
 * (App\Services\TableEntry); putting one into an email link would turn the
 * link into a second, long-lived table credential.
 */
final class VerificationFlow
{
    public const PICKUP = 'pickup';

    public const DINEIN = 'dinein';

    public const ALLOWED = [self::PICKUP, self::DINEIN];

    /** Session keys for the "check your email" page (this browser only). */
    public const SESSION_EMAIL = 'verification_pending.email';

    public const SESSION_FLOW = 'verification_pending.flow';

    public const VERIFIED_MESSAGE = 'Email verified — please log in.';

    /** The one answer the check-your-email page's Resend button ever gives. */
    public const RESEND_ANSWER = 'If that email belongs to an account that still needs confirming, '
        . 'we have sent a new confirmation link. Please check your inbox.';

    /** Login refusal for a correct password on an unconfirmed customer account. */
    public const UNVERIFIED_LOGIN_MESSAGE = 'Please confirm your email address before logging in. '
        . 'Open the confirmation link we emailed you when you signed up.';

    /** Allow-list a value from a link or a session; default Pick-Up. */
    public static function normalize(mixed $flow): string
    {
        return in_array($flow, self::ALLOWED, true) ? $flow : self::PICKUP;
    }

    /**
     * The door this browser is using right now, read from the same
     * `order_type` session key register() and login() already use. A Dine-In
     * QR or table code sets `dine_in`; everything else is Pick-Up.
     */
    public static function fromSession(): string
    {
        return session('order_type') === 'dine_in' ? self::DINEIN : self::PICKUP;
    }

    /** The flow remembered for the "check your email" page, else this session's. */
    public static function pending(): string
    {
        return self::normalize(session(self::SESSION_FLOW, self::fromSession()));
    }

    /**
     * Where a freshly verified customer is sent. Never signs anyone in.
     *
     * Pick-Up: the Pick-Up login (the same URL the welcome page's Pick Up
     * button uses, which switches the session to pick-up).
     *
     * Dine-In: the Dine-In login when this browser still holds its table.
     * Otherwise (usually the phone's mail app, which never scanned the
     * table) the Dine-In entry page. The customer enters the table code
     * there and picks "Log In", which is the only way to reach the Dine-In
     * login with a table attached. Sending them to the plain login instead
     * would sign them in as Pick-Up.
     */
    public static function redirectAfterVerification(string $flow): RedirectResponse
    {
        if (self::normalize($flow) === self::DINEIN) {
            if (session('order_type') === 'dine_in' && session('table_number')) {
                return redirect()->route('customer.login')
                    ->with('success', self::VERIFIED_MESSAGE);
            }

            return redirect()->route('customer.dineinqr')
                ->with('success', self::VERIFIED_MESSAGE
                    . ' Enter the code on your table below, then choose "Log In".');
        }

        return redirect()->route('customer.login', ['order_type' => 'pick_up'])
            ->with('success', self::VERIFIED_MESSAGE);
    }

    /** "Back to login" from the check-your-email page, per flow. */
    public static function loginUrl(string $flow): string
    {
        return self::normalize($flow) === self::DINEIN
            ? route('customer.login')
            : route('customer.login', ['order_type' => 'pick_up']);
    }
}

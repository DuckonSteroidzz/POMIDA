<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * One signed-in session per account, for every role. The newest login wins.
 *
 * HOW IT WORKS
 * ------------
 * users.remember_token is treated as the account's "current login" token.
 * Every successful login (claim()) writes a fresh one and keeps a fingerprint
 * of it in that browser's session. App\Http\Middleware\EnforceSingleSession
 * compares the two on every request. A login on a second device overwrites
 * the column, so the first browser's fingerprint stops matching and its very
 * next request (page load, form post or background poll) ends that session
 * and sends it to its login page.
 *
 * Nothing ever refuses a login because another session exists: claim() only
 * writes, it never checks. Two logins at nearly the same moment both write;
 * whichever UPDATE lands last is the token in the column, so exactly one of
 * the two browsers stays signed in.
 *
 * WHY remember_token, AND NOT SOMETHING ELSE (all checked 2026-09-25)
 * ------------------------------------------------------------------
 *  - Deleting the account's other rows from the `sessions` table cannot work
 *    here. Laravel fills sessions.user_id from the DEFAULT guard (`web`), and
 *    nobody in this app signs in on `web`: staff use `admin`, customers use
 *    `customer`. user_id was NULL on every row in both databases. It would
 *    also depend on SESSION_DRIVER=database, and it could not tell the old
 *    browser why it was signed out.
 *  - Auth::logoutOtherDevices() + the auth.session middleware re-hash the
 *    password on every login, and AuthenticateSession only understands the
 *    default guard. This app runs two guards in one session.
 *  - A new column would need a migration on every server. A forgotten
 *    migration on Hostinger would turn every login into a database error.
 *    remember_token already exists and already means "the credential a device
 *    holds for this account". "Remember me" is retired (browser close must end
 *    the login), so the column has no other job left.
 *
 * Every place that already rotates remember_token (a password reset, a
 * manager resetting a staff password, Laravel's own logout()) therefore also
 * ends any other live session of that account. That is the same intent those
 * places already documented ("a stolen remember-me cookie stops working"),
 * applied to sessions.
 */
final class SingleSession
{
    /**
     * Every guard a person signs in on, and the login page that guard's users
     * are sent back to.
     */
    public const GUARDS = [
        'admin'    => 'admin.login',
        'customer' => 'customer.login',
    ];

    public const REASON_OTHER_DEVICE = 'other-device';
    public const REASON_ENDED        = 'ended';

    public const MESSAGES = [
        self::REASON_OTHER_DEVICE => 'Your account was signed in on another device.',
        self::REASON_ENDED        => 'Your session has ended. Please sign in again.',
    ];

    /**
     * The login pages read the reason from the URL, not from flashed session
     * data. The session that would have carried a flash is the one being
     * destroyed, and a background poll already in flight can race a new
     * session cookie into the browser. A fixed whitelist of reasons maps to a
     * fixed sentence, so nothing from the URL is ever echoed.
     */
    public const QUERY = 'signed_out';

    /**
     * Tokens written by a login start with this. Str::random() only produces
     * [A-Za-z0-9], so no token written anywhere else can carry the prefix:
     * not Laravel's logout(), not a password reset, not a manager resetting a
     * staff password. That is how the old browser can be told WHY it was
     * signed out, and a staff member whose password was reset is not falsely
     * told that someone else signed in as them.
     */
    private const LOGIN_TOKEN_PREFIX = 'login.';

    private const SESSION_KEY = 'single_session';

    /**
     * Make the account signed in on $guard in this request the only live
     * session of that account. Call it after the login has succeeded and the
     * session has been regenerated.
     */
    public static function claim(Request $request, string $guard): void
    {
        $auth = Auth::guard($guard);
        $user = $auth->user();

        if (! $user) {
            return;
        }

        $token = self::LOGIN_TOKEN_PREFIX . Str::random(60);

        // The provider's own writer: it saves only this column and leaves
        // updated_at alone, exactly as Laravel does for "remember me".
        $auth->getProvider()->updateRememberToken($user, $token);

        $request->session()->put(self::SESSION_KEY . '.' . $guard, self::fingerprint($token));
    }

    /**
     * Why this browser's login on $guard is no longer the account's current
     * one. Null while it still is, or when there is no session login to check.
     */
    public static function endedReason(Request $request, string $guard): ?string
    {
        $auth    = Auth::guard($guard);
        $session = $request->session();

        // Only a login that lives in THIS session is checked. A visitor who
        // never signed in (a Dine-In guest holding only a table token, someone
        // browsing the menu) has nothing here and is never touched.
        $loginId = $session->get($auth->getName());

        if ($loginId === null) {
            return null;
        }

        $user = $auth->user();

        // A deleted account resolves to null and the guard already treats that
        // as signed out. A user the guard holds that is NOT this session's
        // login (set in-process, never from a browser) is not what the
        // fingerprint describes.
        if (! $user || (string) $user->getAuthIdentifier() !== (string) $loginId) {
            return null;
        }

        $held    = $session->get(self::SESSION_KEY . '.' . $guard);
        $current = (string) $user->getRememberToken();

        if (is_string($held) && $current !== '' && hash_equals(self::fingerprint($current), $held)) {
            return null;
        }

        // No fingerprint at all means the login predates this check (signed in
        // before it was deployed). It cannot prove it is the current login, so
        // it ends like any other, with the neutral message.
        return is_string($held) && str_starts_with($current, self::LOGIN_TOKEN_PREFIX)
            ? self::REASON_OTHER_DEVICE
            : self::REASON_ENDED;
    }

    /** Where a browser signed out on $guard is sent. */
    public static function loginUrl(string $guard, string $reason): string
    {
        return route(self::GUARDS[$guard], [self::QUERY => $reason]);
    }

    /**
     * The sentence a login page shows for ?signed_out=..., or null.
     *
     * Suppressed when the page already carries errors from the attempt the
     * person just made. back() after a wrong password returns to the same URL,
     * query string included, and "Invalid email or password" is the message
     * that matters then.
     */
    public static function loginNotice(Request $request): ?string
    {
        $reason = $request->query(self::QUERY);

        if (! is_string($reason) || ! isset(self::MESSAGES[$reason])) {
            return null;
        }

        if ($request->hasSession() && $request->session()->has('errors')) {
            return null;
        }

        return self::MESSAGES[$reason];
    }

    private static function fingerprint(string $token): string
    {
        return hash('sha256', $token);
    }
}

<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Which staff account this browser is signed in as, and what to do when a page
 * belonging to a DIFFERENT account tries to act.
 *
 * WHY (QA, 6 Nov 2026)
 * --------------------
 * QA opened two windows of the same browser profile, signed in as Admin in one
 * and Supervisor in the other, refreshed, and the Admin window had become the
 * Supervisor. That is correct: one browser has one cookie jar, so it has one
 * staff login (see App\Support\SessionSide). What was wrong is that it happened
 * silently — the login page signed the second account straight over the first,
 * and the first window carried on looking like Admin until it was refreshed.
 *
 * WHAT IS DECIDED WHERE
 * ---------------------
 * Everything that matters is still decided on the server, from the session's
 * own user: the role middleware, the branch lock and CSRF are untouched, and a
 * stale page has never been able to act with its old account's powers.
 * This class only adds the three things the people at the screen were missing:
 *
 *   - describe(): who is signed in, in the words the sidebar badge uses, for
 *     the login page's "you're already signed in" choice and the other tabs'
 *     notice (see admin/partials/account-watch.blade.php).
 *   - the stamp: every page records which account it was drawn for, and every
 *     form or fetch it sends carries that back. A request whose stamp is not
 *     the signed-in account is refused with a plain explanation and changes
 *     nothing. A page that sends no stamp is judged as it always was.
 *   - refusal(): that plain explanation, for a browser form and for a fetch.
 */
final class StaffAccount
{
    /** Hidden form field the page's script adds to every state-changing form. */
    public const STAMP_FIELD = '_staff_acct';

    /** The same stamp, on fetch()/XHR requests. */
    public const STAMP_HEADER = 'X-Staff-Account';

    /** Answer header telling account-watch that a refusal was an account change. */
    public const CHANGED_HEADER = 'X-Account-Changed';

    /**
     * The portal account signed in on this browser right now, or null.
     *
     * Only an active account with a portal role counts, the same test
     * AdminMiddleware applies at the door, so the login page never offers
     * "Continue" to a login the portal would turn away.
     */
    public static function current(): ?User
    {
        $user = Auth::guard('admin')->user();

        if (! $user || ! $user->is_active || ! in_array($user->role, User::PORTAL_ROLES, true)) {
            return null;
        }

        return $user;
    }

    /** Words for a role, as the sidebar badge shows it (Admin, Supervisor, Staff). */
    public static function roleLabel(?string $role): string
    {
        return ucfirst((string) $role);
    }

    /** @return array{id:int,name:string,role:string,label:string}|null */
    public static function describe(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id'    => (int) $user->id,
            'name'  => (string) $user->name,
            'role'  => (string) $user->role,
            'label' => self::roleLabel($user->role),
        ];
    }

    /** "Maria Cruz (Supervisor)" */
    public static function nameAndRole(User $user): string
    {
        return $user->name . ' (' . self::roleLabel($user->role) . ')';
    }

    /** The account id the page that sent this request was drawn for, if it said. */
    public static function stampFrom(Request $request): ?string
    {
        $stamp = $request->header(self::STAMP_HEADER) ?? $request->input(self::STAMP_FIELD);

        return is_scalar($stamp) && trim((string) $stamp) !== '' ? trim((string) $stamp) : null;
    }

    /**
     * Did this request come from a page drawn for a different account than the
     * one signed in now? False when the page sent no stamp: nothing to compare.
     */
    public static function isStale(Request $request, ?User $current): bool
    {
        $stamp = self::stampFrom($request);

        return $stamp !== null && (! $current || (string) $current->id !== $stamp);
    }

    /** The sentence shown when a page from another account was used. */
    public static function staleMessage(?User $current): string
    {
        return $current
            ? 'This browser is now signed in as ' . self::nameAndRole($current)
                . ', so the page you used belonged to a different account. Nothing you submitted was saved.'
            : 'You have been signed out, so nothing you submitted was saved. Please log in again.';
    }

    /**
     * Refuse a request from a stale page. A fetch gets a 409 its page script
     * recognises (and answers with the same notice as the other tabs); a form
     * goes back to Home, or the login page when nobody is signed in, with the
     * sentence on screen until it is read.
     */
    public static function refusal(Request $request, ?User $current, bool $background): SymfonyResponse
    {
        $message = self::staleMessage($current);

        if ($background) {
            return new JsonResponse([
                'success'         => false,
                'message'         => $message,
                'account_changed' => true,
                'account'         => self::describe($current),
            ], 409, [self::CHANGED_HEADER => '1']);
        }

        return $current
            ? redirect()->route('admin.home')->with('account_notice', $message)
            : redirect()->route('admin.login')->withErrors(['session' => $message]);
    }

    /**
     * A fetch()/XHR rather than the browser loading a page or submitting a
     * form. Sec-Fetch-Mode is exact in every current browser; without it, fall
     * back to the headers the app's own fetch() calls set. Same test as
     * EnforceSingleSession::isBackgroundRequest().
     */
    public static function isBackground(Request $request): bool
    {
        $mode = (string) $request->headers->get('Sec-Fetch-Mode', '');

        if ($mode !== '') {
            return $mode !== 'navigate';
        }

        return $request->expectsJson() || $request->ajax();
    }
}

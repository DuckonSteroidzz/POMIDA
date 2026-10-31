<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One browser, two independent sides: a customer in one tab, staff in another.
 *
 * Both sides live in ONE Laravel session, because the session cookie belongs to
 * the browser, not to a guard. Defect Report 6: every logout called
 * session()->invalidate(), which flushes the whole session, so signing out of
 * the staff portal also destroyed the customer's login, their branch, their
 * cart and their order_type. The customer tab came back as a guest with no
 * order_type, and the cart's default then rendered them as Dine-In.
 *
 * Ending a side now means: sign out THAT guard, remove only THAT side's keys,
 * and still rotate the session id and the CSRF token. Staff access is decided
 * by the `admin` guard and customer access by the `customer` guard, and neither
 * reads the other's login key, so what survives grants nothing new.
 *
 * WHO OWNS WHICH KEY
 * ------------------
 * The staff portal writes a short, known list (staffKeys()). The customer side
 * is everything else: a long list that keeps growing (cart, table, vouchers,
 * guest orders, the Dine-In claim). So a customer logout removes everything
 * that is not staff's or the framework's. A key nobody has listed yet dies with
 * the customer session rather than outliving it.
 */
final class SessionSide
{
    public const STAFF = 'admin';
    public const CUSTOMER = 'customer';

    /** Laravel's own bookkeeping. Belongs to neither side. */
    private const FRAMEWORK_KEYS = ['_token', '_flash', '_previous'];

    /**
     * Which side a request belongs to, by the door it came through. A request
     * outside /admin (a customer page, the public home page, the shared
     * discount-ID link) is the customer side.
     */
    public static function of(Request $request): string
    {
        return $request->is('admin', 'admin/*') ? self::STAFF : self::CUSTOMER;
    }

    /**
     * Sign one side out and leave the other exactly as it was.
     *
     * $rotateRememberToken: false for a login that has already lost its token
     * (see EnforceSingleSession::end()), because rotating it again would sign
     * out the device that now owns it.
     */
    public static function end(Request $request, string $side, bool $rotateRememberToken = true): void
    {
        $guard = Auth::guard($side);

        $rotateRememberToken ? $guard->logout() : $guard->logoutCurrentDevice();

        self::forget($request, $side);

        // New id with the old row destroyed, and a new CSRF token: everything
        // invalidate() did, except flushing the other side's data.
        $request->session()->regenerate(true);
    }

    /** Remove one side's keys. The session id is not touched. */
    public static function forget(Request $request, string $side): void
    {
        $session = $request->session();

        $session->forget($side === self::STAFF ? self::staffKeys() : self::customerKeys($request));
    }

    /** @return string[] dotted session paths only the staff portal writes */
    private static function staffKeys(): array
    {
        return [
            Auth::guard(self::STAFF)->getName(),
            ViewingBranch::SESSION_KEY,
            SignInLog::SESSION_KEY,
            'admin_password_reset',
            ...SingleSession::sessionPaths(self::STAFF),
        ];
    }

    /** @return string[] dotted session paths owned by the customer side */
    private static function customerKeys(Request $request): array
    {
        $notCustomer = [...self::FRAMEWORK_KEYS];

        foreach (self::staffKeys() as $path) {
            $notCustomer[] = explode('.', $path)[0];
        }

        return [
            Auth::guard(self::CUSTOMER)->getName(),
            ...SingleSession::sessionPaths(self::CUSTOMER),
            ...array_values(array_diff(array_keys($request->session()->all()), $notCustomer)),
        ];
    }
}

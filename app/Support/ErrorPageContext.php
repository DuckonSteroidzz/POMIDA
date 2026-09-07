<?php

namespace App\Support;

/**
 * Where can this visitor usefully go from an error page?
 *
 * The branded error pages only ever offered "Back to home". For a dine-in
 * customer that is the worst possible answer: home is the landing page, which
 * knows nothing about their table, so a 429 in the middle of ordering reads
 * exactly like being logged out — reported as "it feels like I got logged out,
 * and I can't get back to my order".
 *
 * This class works out one or two better destinations from what the session
 * already knows, so the page can offer "Back to my order" or "Back to the
 * menu" alongside home.
 *
 * The self-contained property of the error pages is preserved deliberately
 * ------------------------------------------------------------------------
 * The error views were built with no database and no session dependency, so
 * they still render when the database is what broke. That property is kept:
 *
 *   - Nothing here touches the database. Only session keys the ordering flow
 *     already writes are read (order_type, table_number, customer_order_id,
 *     guest order ids) — no Order lookup, no Branch lookup.
 *   - Every read is inside one try/catch. A session store that cannot start
 *     (its own driver is `database`) throws, and that is caught here and
 *     downgraded to the plain "Back to home" the pages had before.
 *
 * Any failure in this file must therefore degrade to the old behaviour, never
 * to an exception raised while rendering an error page.
 *
 * STAFF AND ADMINS GET THEIR OWN DESTINATION, NEVER THE CUSTOMER ONE
 * -------------------------------------------------------------------
 * Before this, every error page — including a 404 typed under /admin/... —
 * offered only customer destinations ("Browse the menu", "Check my orders").
 * An admin or staff member who mistyped a URL or hit a stale link had nothing
 * useful to click.
 *
 * Decided from the REQUEST, not a guess: the URL prefix is /admin/... or it
 * is not. That is deliberately the request's own path rather than "is someone
 * logged in on the admin guard", because the two most common ways to land on
 * one of these pages — a typo'd admin URL, or an admin session that has
 * already expired — both still carry the /admin/... path and neither
 * necessarily carries a live admin session. Using the path means both cases
 * still get sent back toward the staff portal instead of the public site.
 * isAdminVisitor() is exposed publicly so the two error views that hard-code
 * a customer-only link (404's "Browse the menu", 500's "Check my orders") can
 * hide that link for this same visitor rather than showing it beside their
 * dashboard link.
 */
final class ErrorPageContext
{
    /**
     * Links to offer, most useful first. Always contains at least one link,
     * so a caller can render the result unconditionally.
     *
     * @return array<int, array{label: string, url: string, primary: bool}>
     */
    public static function links(): array
    {
        $home = ['label' => 'Back to home', 'url' => url('/'), 'primary' => true];

        try {
            if (self::isAdminVisitor()) {
                // One obvious way back, and never the customer's — a staff
                // portal visitor is never handed a link to the public menu.
                return [[
                    'label' => 'Back to my dashboard',
                    'url' => url('/admin/home'),
                    'primary' => true,
                ]];
            }

            $links = [];

            $hasOrderInProgress = self::hasOrderInProgress();
            $isDineIn = self::sessionValue('order_type') === 'dine_in';
            $table = self::sessionValue('table_number');

            if ($hasOrderInProgress) {
                $links[] = [
                    'label' => 'Back to my order',
                    'url' => url('/customer/orders'),
                    'primary' => true,
                ];
            }

            if ($isDineIn && $table !== null && $table !== '') {
                $links[] = [
                    'label' => 'Back to the menu (Table ' . self::safeLabel($table) . ')',
                    'url' => url('/customer/menu'),
                    'primary' => !$hasOrderInProgress,
                ];
            } elseif (self::sessionValue('branch_id')) {
                $links[] = [
                    'label' => 'Back to the menu',
                    'url' => url('/customer/menu'),
                    'primary' => !$hasOrderInProgress,
                ];
            }

            if (!$links) {
                return [$home];
            }

            // Home stays available, just no longer the only way out.
            $home['primary'] = false;
            $links[] = $home;

            return $links;
        } catch (\Throwable $e) {
            // Session unavailable (its driver is the database, which may be
            // exactly what failed). Fall back to the original behaviour.
            return [$home];
        }
    }

    /**
     * Is this request under the staff portal's /admin/... URL space?
     *
     * Never throws: an unbound or unreadable request object is treated as
     * "not admin", which lands the visitor on the customer/public fallback —
     * the same safe direction this class already defaulted to before staff
     * had a destination of their own.
     */
    public static function isAdminVisitor(): bool
    {
        try {
            return app()->bound('request') && request()->is('admin', 'admin/*');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Does the session point at an order this visitor is in the middle of? */
    private static function hasOrderInProgress(): bool
    {
        if (self::sessionValue('customer_order_id')) {
            return true;
        }

        $guestIds = self::sessionValue(GuestOrders::KEY);

        if (is_array($guestIds) && $guestIds) {
            return true;
        }

        return (bool) self::sessionValue(GuestOrders::LEGACY_KEY);
    }

    /**
     * One session read, or null if the session cannot be reached at all.
     * Kept separate so a single failure cannot take the whole page down.
     */
    private static function sessionValue(string $key)
    {
        try {
            if (!app()->bound('session')) {
                return null;
            }

            return session($key);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Table labels come from the session; keep them short and printable. */
    private static function safeLabel($value): string
    {
        return substr(preg_replace('/[^A-Za-z0-9 \-]/', '', (string) $value), 0, 12);
    }
}

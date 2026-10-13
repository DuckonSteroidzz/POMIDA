<?php

namespace App\Support;

/**
 * Which customer navigation tab a page belongs to.
 *
 * The bottom bar (partials/navbar) and the desktop header links
 * (partials/desktop-nav and the two header copies on receipt and
 * account-settings) each used to decide "am I active?" from their own route
 * name only. So every page that is not itself a tab lit up nothing: a
 * category, an item, a receipt, Vouchers & Rewards, the Account page. Customers lost
 * track of where they were. This is the one map from route to tab.
 */
final class CustomerNav
{
    /** Tab => the routes that belong to it. */
    private const SECTIONS = [
        'menu'   => ['customer.menu', 'customer.items', 'customer.item', 'customer.cart'],
        'orders' => ['customer.orders', 'customer.receipt', 'customer.gcash-payment', 'customer.orders.rating.show'],
        'game'   => ['customer.game'],
        'more'   => ['customer.more', 'customer.help', 'customer.vouchers', 'customer.account', 'customer.notifications.index'],
    ];

    /** The tab the current page belongs to, or null (login, register, …). */
    public static function section(): ?string
    {
        $request = request();

        foreach (self::SECTIONS as $section => $routes) {
            if ($request->routeIs(...$routes)) {
                return $section;
            }
        }

        return null;
    }

    public static function isActive(string $section): bool
    {
        return self::section() === $section;
    }
}

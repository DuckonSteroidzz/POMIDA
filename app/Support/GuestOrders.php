<?php

namespace App\Support;

/**
 * GuestOrders — every order the CURRENT guest session has placed.
 *
 * Why this exists
 * ---------------
 * A guest has no account, so the only proof they own an order is that their
 * session placed it. That proof used to be a single value, session
 * 'guest_order_id', and it was overwritten each time an order was placed.
 *
 * That was fine only because the customer was forbidden from having two orders
 * at once: AuthController::rejectIfActiveOrder() blocked the cart, and
 * OrderController::placeOrder() blocked a second order, both until the first
 * one reached completed or cancelled. Lift that restriction — which is exactly
 * what a dine-in customer ordering a second round needs — and the single value
 * silently loses the first order: it would vanish from their order list, its
 * notifications would stop being visible, its receipt would 404, and
 * TableOccupancy would no longer recognise them as the owner of the table.
 *
 * So the ownership proof became a set. Everything that used to compare against
 * session('guest_order_id') now asks this class instead.
 *
 * Scope of the claim is unchanged and deliberately narrow: being in this list
 * is necessary but not sufficient. Callers still require the order to be a
 * genuine guest order (user_id IS NULL), so pointing a session at a registered
 * customer's order id grants nothing.
 *
 * Backward compatibility
 * ----------------------
 * The legacy 'guest_order_id' key is still written, always pointing at the most
 * recent order, and is still read as part of the set. A visitor mid-session
 * when this shipped keeps their claim, and the two Blade pages that seed the
 * status poller from it keep working untouched.
 */
class GuestOrders
{
    /** Session key holding the full set of order ids this guest placed. */
    public const KEY = 'guest_order_ids';

    /** The original single-order key. Still written, still honoured. */
    public const LEGACY_KEY = 'guest_order_id';

    /**
     * Cap on remembered orders. A session cookie is not a database, and one
     * visit does not produce dozens of orders; the oldest fall off first.
     */
    public const MAX_TRACKED = 25;

    /** Record an order this session just placed. */
    public static function remember(int $orderId): void
    {
        $ids = self::ids();

        // Move to the end rather than duplicate, so "most recent" stays true.
        $ids = array_values(array_filter($ids, fn ($id) => $id !== $orderId));
        $ids[] = $orderId;

        if (count($ids) > self::MAX_TRACKED) {
            $ids = array_slice($ids, -self::MAX_TRACKED);
        }

        session()->put(self::KEY, $ids);
        session()->put(self::LEGACY_KEY, $orderId);
    }

    /**
     * Every order id this session may claim, oldest first.
     *
     * @return int[]
     */
    public static function ids(): array
    {
        $ids = session(self::KEY, []);

        if (!is_array($ids)) {
            $ids = [];
        }

        $ids = array_map('intval', array_filter($ids, 'is_numeric'));

        // Fold in the legacy single key, for sessions that predate this class.
        $legacy = session(self::LEGACY_KEY);

        if (is_numeric($legacy) && !in_array((int) $legacy, $ids, true)) {
            $ids[] = (int) $legacy;
        }

        return array_values(array_unique($ids));
    }

    /** Does this session claim the given order? */
    public static function owns(?int $orderId): bool
    {
        return $orderId !== null && in_array($orderId, self::ids(), true);
    }

    /** The most recent order this session placed, or null. */
    public static function latest(): ?int
    {
        $legacy = session(self::LEGACY_KEY);

        if (is_numeric($legacy)) {
            return (int) $legacy;
        }

        $ids = self::ids();

        return $ids ? (int) end($ids) : null;
    }

    /**
     * Read-only claims kept from earlier visits in this same browser session.
     *
     * forget() below deliberately drops the ACTIVE claim set when a new
     * dine-in visit starts, so the next party at the table cannot act on the
     * previous party's orders. That is right — but it also silently revoked
     * the previous party's own receipts, which is what produced the reported
     * 404 on /customer/receipt/{id} for an order the visitor had just placed
     * and had watched being completed.
     *
     * So forget() now moves the ids here instead of deleting them. An id in
     * this archive grants EXACTLY ONE thing: the right to read the receipt for
     * an order that is still a genuine guest order. It is never consulted for
     * ordering, cancelling, rating, or table ownership — those all keep asking
     * ids()/owns(), which the archive does not feed.
     */
    public const PAST_KEY = 'guest_order_ids_past';

    /** @return int[] */
    public static function pastIds(): array
    {
        $ids = session(self::PAST_KEY, []);

        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    }

    /** Did an EARLIER visit in this browser session place this order? */
    public static function ownedInPastVisit(?int $orderId): bool
    {
        return $orderId !== null && in_array($orderId, self::pastIds(), true);
    }

    /**
     * Drop every ACTIVE claim. Called when a visitor starts a new dine-in
     * visit, so the previous party's orders do not follow the table into the
     * next one — they can no longer be ordered against, cancelled or rated.
     * The ids are archived (see pastIds()) so their own receipts stay readable.
     */
    public static function forget(): void
    {
        $archived = array_merge(self::pastIds(), self::ids());

        if (count($archived) > self::MAX_TRACKED) {
            $archived = array_slice($archived, -self::MAX_TRACKED);
        }

        session()->put(self::PAST_KEY, array_values(array_unique($archived)));
        session()->forget([self::KEY, self::LEGACY_KEY]);
    }
}

<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Auth;

/**
 * DiscountIdAccess — the one place that answers "may this visitor see the
 * PWD/Senior ID document attached to this order?"
 *
 * WHY THIS EXISTS (Pass 4, security checklist item #10)
 * -----------------------------------------------------
 * Discount ID uploads used to be written to the `public` disk:
 *
 *     $request->file('discount_beneficiary_image')->store('discount_ids', 'public')
 *
 * The `public` disk is exposed through the public/storage symlink, and
 * public/.htaccess sends a request straight to the file when one exists
 * (`RewriteCond %{REQUEST_FILENAME} !-f`), so Laravel never ran at all for
 * those URLs. Proven live before the change: an anonymous request with no
 * cookies and no session for
 *
 *     GET /storage/discount_ids/<name>.png
 *
 * returned HTTP 200 and the complete, byte-identical 1.3 MB image.
 *
 * There was no authorisation of any kind. Filenames are 40 random characters,
 * so they cannot be brute-forced, but the admin order board rendered the URL
 * into the page as `data-image="..."`, which means every such URL reached
 * browser history, screenshots and view-source. Once one leaked, it granted
 * permanent unauthenticated access to that person's document.
 *
 * Uploads now go to the `local` disk (storage/app), which is NOT web-reachable,
 * and are served only through a controller route that applies the rule below.
 *
 * WHO MAY VIEW ONE, AND WHY
 * -------------------------
 * Two parties, and no one else:
 *
 *  1. STAFF AND ADMIN, authenticated on the `admin` guard and active.
 *     Verifying the document IS the feature — the order board's discount modal
 *     exists so staff can approve or reject a claimed PWD/Senior discount, and
 *     they cannot do that without seeing the ID. Restricting this to admins
 *     only would break the job staff are actually there to do, so both roles
 *     are allowed, exactly as the order board itself is (`role:admin,staff`).
 *
 *  2. THE CUSTOMER WHO UPLOADED IT, resolved through
 *     CustomerOrderAccess::resolveOwnedOrder().
 *
 *     This is a deliberate choice rather than an oversight, so here is the
 *     reasoning. It is that person's own identity document. Allowing staff to
 *     read it while refusing the person it belongs to is difficult to justify
 *     on any data-protection footing. It also adds no new exposure: the same
 *     rule already governs their own receipt, which carries the beneficiary
 *     name and card number — comparable personal data about the same person.
 *     And reusing resolveOwnedOrder() rather than inventing a second ownership
 *     concept is the point CustomerOrderAccess was created to make; a
 *     hand-rolled second rule is how the rating endpoint previously ended up
 *     weaker than the receipt endpoint.
 *
 *     It covers guests correctly too, because that rule already does: a guest
 *     who uploaded an ID during a guest checkout can still see it from the
 *     session that placed the order, and can never reach anyone else's.
 *
 * Nobody else. Not an unauthenticated visitor, not a customer looking at
 * another customer's order, and not a deactivated staff account.
 *
 * WHY A MISSING CLAIM IS A 404 AND NEVER A 403
 * ---------------------------------------------
 * Same reasoning as CustomerOrderAccess and resolveOwnedOrder(): a 403 would
 * confirm that the order exists AND that it carries a discount ID document.
 * That is itself information about a stranger — it says a real person claimed
 * a PWD or Senior discount on that order. A 404 for every refusal makes "you
 * may not see this", "there is no such order" and "that order has no ID
 * attached" indistinguishable from the outside.
 */
class DiscountIdAccess
{
    /**
     * Resolve the stored ID document path for an order the CURRENT visitor is
     * allowed to see, or null.
     *
     * Returns null — never throws and never distinguishes the cases — when the
     * order does not exist, has no ID document, or the visitor has no claim to
     * it. The caller turns null into a 404.
     */
    public static function resolvePathFor(int $orderId): ?string
    {
        $order = self::resolveViewableOrder($orderId);

        if (! $order) {
            return null;
        }

        $path = trim((string) $order->discount_id_image);

        return $path === '' ? null : $path;
    }

    /**
     * The order, if this visitor may view its ID document.
     */
    public static function resolveViewableOrder(int $orderId): ?Order
    {
        if (self::viewerIsVerifyingStaff()) {
            // Branch-scoped through the one rule the per-order admin endpoints
            // use (App\Services\AdminOrderAccess): staff see only their own
            // branch's orders, admins are unrestricted as before. Verifying a
            // discount is a counter job, and a counter only ever verifies its
            // own branch's customers — an unscoped lookup here let branch-1
            // staff pull up a branch-2 customer's PWD/Senior ID document.
            // Still null, never a distinguishable refusal; the caller 404s.
            return \App\Services\AdminOrderAccess::findInScope($orderId);
        }

        // Not staff — the only remaining claim is owning the order. This is
        // the same rule the receipt uses, deliberately; see the class docblock.
        return CustomerOrderAccess::resolveOwnedOrder($orderId);
    }

    /**
     * Staff or admin, signed in on the admin guard, still active.
     *
     * is_active is re-checked here rather than trusted from the session,
     * because a staff account can be deactivated while its session is still
     * alive — AdminMiddleware makes the same check for the same reason.
     */
    public static function viewerIsVerifyingStaff(): bool
    {
        if (! Auth::guard('admin')->check()) {
            return false;
        }

        $user = Auth::guard('admin')->user();

        return $user
            && in_array($user->role, ['admin', 'staff'], true)
            && (bool) $user->is_active;
    }
}

<?php

namespace App\Support;

use App\Models\Ad;

/**
 * The one ad to pop up on the customer Menu page, or null.
 *
 * Lives here, called from customer/partials/menu-ad-popup.blade.php, rather
 * than in Customer\AuthController::showMenu(): that controller also owns
 * registration, login and verification, and this feature has no business
 * editing the file those flows live in.
 *
 * At most ONCE per session: the flag is set the moment an ad is handed out, so
 * a refresh, another page and a return to the menu do not show it again. It is
 * the server session rather than the browser's sessionStorage so it follows the
 * same lifetime as everything else per-visit here (idle logout, a new table
 * claim and a logout all start a fresh session, and with it a fresh popup), and
 * so it is testable.
 *
 * Selection matches the game page (AuthController::showGame): Ad::liveNow()
 * for the active flag and the start/end window (one shared rule), and the same
 * branch rule — NULL branch_id is global, otherwise only the customer's own
 * branch. That rule is AuthController::scopeToCustomerBranch(), which is
 * private, so its five lines are repeated in branchScoped() below; keep the two
 * in step. Pick-Up and Dine-In both keep session('branch_id'), so one rule
 * covers both.
 *
 * Only placement = 'menu' is ever read, so an old ad saved with a removed
 * placement (cart/orders) can never be selected.
 *
 * With no branch selected there is nothing to scope by, and the branch picker
 * is not the menu, so nothing is shown and the once-per-session slot is kept.
 *
 * Several live menu ads: the lowest display_order wins, then the newest.
 */
class MenuAd
{
    public const SESSION_FLAG = 'menu_ad_popup_shown';

    public static function forCurrentVisit(): ?Ad
    {
        $branchId = session('branch_id') ? (int) session('branch_id') : null;

        if ($branchId === null || session(self::SESSION_FLAG)) {
            return null;
        }

        $ad = static::branchScoped(
            Ad::where('placement', 'menu')->liveNow(),
            $branchId
        )
            ->orderBy('display_order')
            ->orderByDesc('id')
            ->first();

        if ($ad) {
            session()->put(self::SESSION_FLAG, true);
        }

        return $ad;
    }

    private static function branchScoped($query, int $branchId)
    {
        return $query->where(function ($q) use ($branchId) {
            $q->whereNull('branch_id')
                ->orWhere('branch_id', $branchId);
        });
    }
}

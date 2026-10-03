<?php

namespace App\Http\Middleware;

use App\Services\TableOccupancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After staff use "Move table" on the Occupied Tables panel, every phone at
 * that table picks up its new table on its next request — before the page,
 * the cart or the order endpoint reads session('table_number').
 *
 * The decision lives in TableOccupancy::followSessionTable(); this only runs
 * it on the customer routes and leaves a one-time note for the menu and cart
 * to show ("Our staff moved you to Table N"). A device that was not moved is
 * not changed.
 */
class FollowStaffTableMove
{
    public const NOTICE_KEY = 'table_moved_by_staff';

    public function handle(Request $request, Closure $next): Response
    {
        $movedTo = TableOccupancy::followSessionTable();

        if ($movedTo !== null) {
            $request->session()->put(self::NOTICE_KEY, $movedTo);
        }

        return $next($request);
    }
}

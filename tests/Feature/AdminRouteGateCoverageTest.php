<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every admin-area route is gated, and the gate is spelled correctly.
 *
 * WHY A STATIC GUARD AND NOT MORE PER-ROUTE TESTS
 * ----------------------------------------------
 * RolePermissionMatrixTest already drives the role boundaries hard, but its
 * matrix is hand-maintained: it proves that the routes IT LISTS are enforced
 * server-side. It cannot say anything about a route added next month whose
 * author forgot `role:`. That route would sit inside the admin group and be
 * reachable by EVERY portal role, Staff included — silently, with no failing
 * test, because nothing enumerates the routing table.
 *
 * This file closes that by asserting properties over the WHOLE table, in the
 * same spirit as SqlInjectionTest's source scan and CsrfProtectionTest's
 * "nothing excludes any route" check. It is deliberately cheap and has no
 * fixtures: it reads the router.
 *
 * THE THREE PROPERTIES
 * --------------------
 *   1. Every route behind the `admin` guard carries a `role:` filter, except
 *      logout — which every signed-in portal role must always be able to reach.
 *   2. Every `role:` filter names only real roles. A typo such as `role:admn`
 *      fails CLOSED (RoleMiddleware's in_array is strict, so nobody matches),
 *      which is safe but silently bricks the page — so it is worth catching
 *      here rather than in a bug report.
 *   3. Nothing under the `admin/` prefix is publicly reachable except the
 *      named pre-authentication endpoints. A brand-new admin page that lands
 *      outside the guarded group would otherwise be world-readable.
 *
 * Audited state when this was written (2026-09-28): 178 routes, 107 mutating,
 * exactly one admin-guarded route without a role filter (logout) and exactly
 * twelve public `admin/` routes, all of them login/bootstrap/reset screens.
 */
class AdminRouteGateCoverageTest extends TestCase
{
    /**
     * Routes behind the admin guard that legitimately have no role filter.
     *
     * Logout must stay open to every portal role: gating it would mean a
     * Staff member whose role lost access to a page could not sign out.
     */
    private const UNGATED_BY_DESIGN = [
        'admin.logout',
    ];

    /**
     * The only `admin/` routes that may be reached without signing in.
     *
     * All of them are the login / first-run bootstrap / password-reset screens.
     * `admin.bootstrap*` is open by necessity — a fresh install has nobody to
     * authenticate as — and is gated instead by AdminBootstrap::isAvailable(),
     * which AdminBootstrapTest covers in depth.
     */
    private const PUBLIC_BY_DESIGN = [
        'admin.login',
        'admin.login.post',
        'admin.logout',
        'admin.session-token',
        'admin.bootstrap',
        'admin.bootstrap.store',
        'admin.forgot-password',
        'admin.forgot-password.post',
        'admin.new-password',
        'admin.new-password.post',
        'admin.verification',
        'admin.verification.post',
        'admin.verification.resend',
    ];

    /** @return array<int, \Illuminate\Routing\Route> */
    private function allRoutes(): array
    {
        return array_values(Route::getRoutes()->getRoutes());
    }

    private function middlewareOf($route): array
    {
        return array_map('strval', $route->gatherMiddleware());
    }

    /**
     * EVERY role filter on a route, not just the first.
     *
     * A route inside a `Route::middleware('role:admin,supervisor')` group that
     * also narrows itself with `->middleware('role:admin')` gathers BOTH, and
     * the narrowing one is the security-relevant one. An earlier version of
     * this helper returned the first match and stopped, which let a deliberate
     * `role:admn` typo on the second filter pass unnoticed during the sabotage
     * check — so it now collects all of them.
     *
     * @return array<int, string>
     */
    private function roleFiltersOf($route): array
    {
        $found = [];

        foreach ($this->middlewareOf($route) as $m) {
            if (str_starts_with($m, 'role:')) {
                $found[] = substr($m, 5);
            }
        }

        return $found;
    }

    private function isAdminGuarded($route): bool
    {
        return in_array('admin', $this->middlewareOf($route), true);
    }

    // ══════════ 1. Nothing behind the admin guard is role-less ══════════

    public function test_every_admin_guarded_route_declares_a_role_filter(): void
    {
        $missing = [];

        foreach ($this->allRoutes() as $route) {
            if (! $this->isAdminGuarded($route)) {
                continue;
            }

            $name = (string) $route->getName();

            if (in_array($name, self::UNGATED_BY_DESIGN, true)) {
                continue;
            }

            if ($this->roleFiltersOf($route) === []) {
                $missing[] = implode('|', $route->methods()) . ' /' . $route->uri() . "  ({$name})";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These admin routes are reachable by EVERY portal role, Staff included, because they carry no "
            . "role: filter. Add one, or — if it really must be open to the whole portal — list it in "
            . "UNGATED_BY_DESIGN with a reason:\n  " . implode("\n  ", $missing)
        );
    }

    // ══════════ 2. Every role filter names real roles ══════════

    public function test_every_role_filter_names_only_real_portal_roles(): void
    {
        $bad = [];

        foreach ($this->allRoutes() as $route) {
            foreach ($this->roleFiltersOf($route) as $filter) {
                foreach (array_map('trim', explode(',', $filter)) as $role) {
                    if (! in_array($role, User::PORTAL_ROLES, true)) {
                        $bad[] = '/' . $route->uri() . " declares role:{$filter} — '{$role}' is not a portal role";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $bad,
            "A misspelled role fails closed, so the page is not exposed — but it IS bricked for everyone:\n  "
            . implode("\n  ", $bad)
        );
    }

    // ══════════ 3. No accidentally public admin page ══════════

    public function test_no_admin_route_is_publicly_reachable_unless_listed(): void
    {
        $public = [];

        foreach ($this->allRoutes() as $route) {
            $uri = $route->uri();

            if ($uri !== 'admin' && ! str_starts_with($uri, 'admin/')) {
                continue;
            }

            if ($this->isAdminGuarded($route)) {
                continue;
            }

            $name = (string) $route->getName();

            if (in_array($name, self::PUBLIC_BY_DESIGN, true)) {
                continue;
            }

            $public[] = implode('|', $route->methods()) . ' /' . $uri . "  ({$name})";
        }

        $this->assertSame(
            [],
            $public,
            "These admin routes can be reached WITHOUT SIGNING IN. If that is intended (a login or reset "
            . "screen), add the route name to PUBLIC_BY_DESIGN; otherwise move it inside the admin "
            . "middleware group:\n  " . implode("\n  ", $public)
        );
    }

    // ══════════ The control ══════════

    /**
     * Without this, all three tests above would pass on an app with no admin
     * routes at all — or on one where `gatherMiddleware()` silently returned
     * nothing and every loop body was skipped.
     */
    public function test_the_guard_is_actually_looking_at_a_populated_admin_area(): void
    {
        $guarded = 0;
        $withRole = 0;

        foreach ($this->allRoutes() as $route) {
            if (! $this->isAdminGuarded($route)) {
                continue;
            }

            $guarded++;

            if ($this->roleFiltersOf($route) !== []) {
                $withRole++;
            }
        }

        $this->assertGreaterThan(
            100,
            $guarded,
            'far fewer admin-guarded routes than this app has — the middleware lookup is probably broken, '
            . 'which would make every assertion in this file vacuous'
        );

        $this->assertSame(
            $guarded - count(self::UNGATED_BY_DESIGN),
            $withRole,
            'the number of role-filtered routes no longer matches the number of guarded routes minus the '
            . 'documented exceptions'
        );
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Check if logged in through the admin guard
        if (!Auth::guard('admin')->check()) {
            return redirect()->route('admin.login')
                ->withErrors([
                    'email' => $this->loginNotice($request),
                ]);
        }

        $user = Auth::guard('admin')->user();

        // Only the portal roles can access the staff portal.
        //
        // Spelled from User::PORTAL_ROLES rather than as an inline literal:
        // this is the door, and a role missing here cannot log in at all no
        // matter what the route groups say. Supervisor was added to that
        // constant in the Sept 2026 role pass; customer is deliberately absent
        // and always will be. The strict flag matters — without it PHP's loose
        // comparison lets some non-string role values slip past this array.
        if (!in_array($user->role, \App\Models\User::PORTAL_ROLES, true)) {
            Auth::guard('admin')->logout();

            return redirect()->route('admin.login')
                ->withErrors([
                    'email' => 'You are not authorized to access the staff portal.',
                ]);
        }

        // Check if account is active
        if (!$user->is_active) {
            Auth::guard('admin')->logout();

            return redirect()->route('admin.login')
                ->withErrors([
                    'email' => 'Your account has been deactivated.',
                ]);
        }

        return $next($request);
    }

    /**
     * The "please sign in" notice shown on the shared staff-portal login page.
     *
     * One login form serves all three portal roles, so a bare "please login"
     * says nothing about which door the person was just sent back to. When the
     * route they were reaching for is gated to a NARROWER set than the whole
     * portal (its `role:` filter is on the gathered middleware even though this
     * middleware runs before it), name that access — "an Administrator account",
     * "an Administrator or Supervisor account". When the destination is open to
     * every portal role, or there is simply no route context (a plain expired
     * session landing on the login page), naming a role would be a guess, so the
     * message stays generic.
     */
    private function loginNotice(Request $request): string
    {
        $generic = 'Please login to access the staff portal.';

        $route = $request->route();
        if (!$route) {
            return $generic;
        }

        $roleFilter = null;
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'role:')) {
                $roleFilter = substr($m, 5);
                break;
            }
        }
        if ($roleFilter === null) {
            return $generic;
        }

        $required = array_values(array_intersect(
            \App\Models\User::PORTAL_ROLES,
            array_map('trim', explode(',', $roleFilter))
        ));

        $allPortal = \App\Models\User::PORTAL_ROLES;
        sort($allPortal);
        $sortedRequired = $required;
        sort($sortedRequired);

        // Nothing narrower than the whole portal → generic is the honest message.
        if ($required === [] || $sortedRequired === $allPortal) {
            return $generic;
        }

        $labels = [
            'admin' => 'Administrator',
            'supervisor' => 'Supervisor',
            'staff' => 'Staff',
        ];
        $named = [];
        foreach (['admin', 'supervisor', 'staff'] as $role) {
            if (in_array($role, $required, true)) {
                $named[] = $labels[$role];
            }
        }

        $phrase = count($named) === 1
            ? $named[0]
            : implode(' or ', [
                implode(', ', array_slice($named, 0, -1)),
                end($named),
            ]);

        $article = str_starts_with($phrase, 'A') ? 'an' : 'a';

        return "Please sign in with {$article} {$phrase} account to continue.";
    }
}
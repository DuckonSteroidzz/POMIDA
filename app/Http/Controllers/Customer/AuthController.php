<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Concerns\HandlesEmailVerification;
use App\Http\Controllers\Concerns\HandlesPasswordReset;
use App\Http\Controllers\Controller;
use App\Models\GamePlayed;
use App\Models\Setting;
use App\Models\User;
use App\Models\Order;
use App\Services\SpinWheel;
use App\Services\VoucherClaims;
use App\Support\GuestVoucherClaims;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | PASSWORD RESET
    |--------------------------------------------------------------------------
    |
    | showForgotPassword / forgotPassword / showVerification / verifyCode /
    | resendCode / showNewPassword / updatePassword all come from this trait.
    | The hooks below scope the flow to CUSTOMER accounts only.
    |
    */
    use HandlesPasswordReset;

    /*
    |--------------------------------------------------------------------------
    | EMAIL VERIFICATION
    |--------------------------------------------------------------------------
    |
    | verifyEmail() / resendEmailVerification() come from this trait — the
    | signed-link click and the account-page "resend" button. Unrelated to
    | the PASSWORD RESET trait above despite the similar route names; see
    | HandlesEmailVerification's docblock.
    |
    */
    use HandlesEmailVerification;

    protected function resettableRoles(): array
    {
        return ['customer'];
    }

    protected function resetSessionKey(): string
    {
        return 'customer_password_reset';
    }

    protected function resetViewPrefix(): string
    {
        return 'customer';
    }

    protected function resetRoutePrefix(): string
    {
        return 'customer';
    }

    protected function resetAccountLabel(): string
    {
        return 'customer account';
    }

    // ══════════ SHOW PAGES ══════════

    public function showLogin(Request $request)
    {
        // Coming from the Welcome page's Pick Up option:
        // explicitly switch the session to Pickup so an old Dine-In
        // table/session cannot leak into the Pickup flow.
        if ($request->input('order_type') === 'pick_up') {
            session()->put('order_type', 'pick_up');
            session()->forget('table_number');
        }

        // "Your account was signed in on another device." See
        // AdminAuthController::showLogin() — same alert box, same key.
        $notice = \App\Support\SingleSession::loginNotice($request);

        return $notice === null
            ? view('customer.login')
            : view('customer.login')->withErrors(['session' => $notice]);
    }

    public function showRegister()
    {
        return view('customer.register');
    }

    public function showTerms()
    {
        return view('customer.terms');
    }

    public function showMenu(Request $request)
    {
        $branches = \App\Models\Branch::where('is_active', true)
            ->orderBy('id')
            ->get();

        // Set by login()/register()/startDineIn()'s guest branch on the
        // request before this one (a real redirect), so it's already here by
        // the time this request's own logic runs. The QR-landing branch below
        // is the one case that renders this same view without a prior
        // redirect, so it fills this in directly instead of flashing.
        $welcomeCustomer = session('welcome_customer');

        /*
         * Dine-in via QR. A phone's built-in camera app opens the QR's URL
         * directly instead of posting the payload to our scanner, so this
         * landing is a real entry point and has to validate exactly like the
         * scanner does. It used to write branch_id and table straight from the
         * query string with no checks at all, which meant editing the address
         * bar could start a Dine-In session at any branch and any table.
         */
        if ($request->has('table') && $request->has('branch_id')) {
            /*
             * Opening a dine-in session is rate limited wherever it happens,
             * and this is one of the three places it happens. The other two —
             * the camera scanner and the typed code — are both POST
             * /customer/dineinqr, which carries the `table-session` limiter as
             * route middleware behind throttle.friendly. This door is a GET on
             * the menu route, which every browsing customer hits constantly, so
             * the same middleware cannot be hung on the route without
             * throttling ordinary browsing.
             *
             * It is therefore checked here, INSIDE the QR-parameter branch and
             * nowhere else, so it counts session openings rather than page
             * views. Same limiter name, same numbers (single-sourced from
             * RateLimitServiceProvider) and the same visitorKey() identity, so
             * a permanent code cannot be looped through this door either.
             */
            $limited = $this->tableSessionRateLimit($request);

            if ($limited) {
                return redirect()->route('customer.dineinqr')->with('error', $limited);
            }

            /*
             * `k` is the table's permanent code, carried by the QR. It is the
             * SAME secret the typed-code door checks, and TableEntry::validate()
             * is the one place the rule lives. A missing, stale or wrong `k` —
             * a photograph of a reprinted table's old card — comes back as
             * TableEntry::ERR_QR_STALE and lands the customer on the code-entry
             * page with a calm "type the code on your card" message, never a
             * 403 or an error page.
             */
            $parsed = \App\Services\QrPayload::fromParts(
                $request->input('branch_id'),
                $request->input('table'),
                $request->input('k')
            );

            if (!$parsed['ok']) {
                return redirect()->route('customer.dineinqr')
                    ->with('error', $parsed['error']);
            }

            /*
             * Occupancy applies here too. This is the door a hand-edited URL or
             * a photographed QR opened in the phone's own camera app comes
             * through, so skipping the check here would leave the whole lock
             * trivially bypassable. A visitor who already holds the table keeps
             * it — this route is hit again on every refresh of the QR URL.
             */
            $occupancy = $this->claimTable(
                $parsed['branch'],
                $parsed['table_number'],
                $request->ip(),
                true,
                ($parsed['table'] ?? null)?->id
            );

            if (!$occupancy['ok']) {
                return $occupancy['redirect'] ?? redirect()->route('customer.dineinqr')
                    ->with('error', $occupancy['error']);
            }

            session()->put('table_number', $parsed['table_number']);
            $this->switchBranch($parsed['branch']->id);
            session()->put('order_type', 'dine_in');

            // Same "genuinely new claim" gate as startDineIn()'s guest branch —
            // a refresh of this same URL must not re-show the popup.
            if (($occupancy['continued'] ?? false) === false) {
                $authUser = Auth::guard('customer')->user();

                $welcomeCustomer = $authUser
                    ? ['type' => $authUser->orders()->exists() ? 'returning' : 'new', 'name' => $authUser->name]
                    : ['type' => 'new', 'name' => null];
            }
        }
        if (session('order_type') === 'pick_up') {
            session()->forget('table_number');
        }

        // Keep a live dine-in occupancy from ageing into "abandoned" while the
        // customer is genuinely still browsing the menu.
        if (session('order_type') === 'dine_in') {
            \App\Services\TableOccupancy::touchCurrent();
        }

        $orderType = session('order_type');
        $selectedBranchId = session('branch_id');

        // Keep these empty until a branch has been selected.
        $categories = collect();
        $menuItems = collect();

        if ($selectedBranchId) {
            // Load available menu items for the selected branch.
            $menuItems = \App\Models\MenuItem::with([
                    'category',
                    'subcategory',
                    // For the automatic out-of-stock badge on each card —
                    // eager-loaded so hasIngredientStock() costs no extra query.
                    'recipeIngredients.inventory',
                    'inventoryItem',
                    // Menu Item Sizes (Phase 2): a sized card is judged by its
                    // own sizes (MenuItem::menuCardState()), not the base recipe.
                    'allSizes.ingredients.inventory',
                ])
                ->where('is_available', true)
                ->where(function ($q) use ($selectedBranchId) {
                    $q->where('branch_id', $selectedBranchId)
                        ->orWhereNull('branch_id');
                })
                ->orderBy('name')
                ->get();

            // Load only categories that contain available menu items.
            $categoryIds = $menuItems
                ->pluck('category_id')
                ->filter()
                ->unique();

            $categories = \App\Models\Category::where('is_active', true)
                ->whereIn('id', $categoryIds)
                ->orderBy('display_order')
                ->orderBy('name')
                ->get();
        }

        // Stock already promised to open orders but not yet deducted, so the
        // Out-of-Stock and low-stock badges show what a customer can actually
        // still order rather than what the pantry looks like on paper. One
        // query for the whole grid — see InventoryDeductionService.
        $reserved = app(\App\Services\InventoryDeductionService::class)->committedQuantities();

        return view('customer.menu', compact(
            'categories',
            'menuItems',
            'branches',
            'selectedBranchId',
            'orderType',
            'reserved',
            'welcomeCustomer'
        ));
    }

public function selectBranch(Request $request)
{
    $branchId = $request->input('branch_id');

    $branch = \App\Models\Branch::find($branchId);
    if (!$branch || !$branch->is_active) {
        return redirect()->route('customer.menu')
            ->with('error', 'That branch is currently closed and cannot accept pick-up orders. Please choose another branch.');
    }

    // Explicitly switch the customer to PICKUP mode.
    // Remove all Dine-In-only information.
    $this->switchBranch($branchId);
    session()->put('order_type', 'pick_up');
    session()->forget('table_number');

    return redirect()->route('customer.menu')
        ->with('success', 'Branch selected!');
}

/**
 * The ONE place that changes session('branch_id') for any reason — the
 * pickup selector, a QR scan, a typed table code, or the phone-camera URL
 * landing.
 *
 * Branches never share inventory or stock (Phase 3 multi-branch audit,
 * 2026-09-13): a cart line added under one branch and checked out under
 * another deducts the WRONG branch's stock, because completion deducts by
 * the item's own branch_id, not the order's. Reproduced live — orders
 * 115-121 (May 2026) already have this shape. So every branch change, not
 * only a switch between two already-known branches, clears the cart
 * whenever the branch is actually different from what it was a moment
 * ago — including a previously branchless guest's FIRST branch pick, which
 * the old check (`session('branch_id') && ...`) skipped because null is
 * falsy. The comparison is deliberately loose (!=): null must count as
 * different from any real branch id, not be treated as "nothing to compare".
 *
 * Re-selecting the SAME branch never clears the cart — only a genuine
 * change does.
 */
private function switchBranch($branchId): void
{
    if (session('branch_id') != $branchId) {
        // Only worth telling the customer about it if the cart actually had
        // something in it — a branchless guest's first pick, or re-landing
        // with an already-empty cart, shouldn't produce a "cart cleared" toast.
        if (!empty(session('cart'))) {
            session()->flash('branch_changed_warning', true);
            session()->flash('branch_changed_message', 'Your cart was cleared because you switched branches — menu items and prices are branch-specific.');
        }

        session()->forget('cart');
    }

    session()->put('branch_id', $branchId);
}

    public function showDineInQr()
    {
        return view('customer.dineinqr');
    }

    /**
     * The live CSRF token for the /dineinqr code-entry form.
     *
     * The form there posts with a native submit (not fetch), so the app-wide
     * session-guard wrapper cannot swap a stale token onto it. A tab left open
     * past the session lifetime would therefore submit a dead token and land
     * the customer on the branded 419 page — the one dead end this flow is not
     * allowed to have. dineinqr.blade.php polls this every few minutes and
     * rewrites both the <meta> tag and the form's hidden _token field.
     *
     * READ-ONLY, like tableSessionStatus(): it reads csrf_token() and nothing
     * else. It writes no session key and makes no security decision.
     */
    public function sessionToken()
    {
        return response()->json(['token' => csrf_token()]);
    }

    public function processQr(Request $request)
    {
        $manualCode = trim((string) $request->input('table_code'));

        /*
         * ── Typed code. The permanent code printed on the table standee, 8
         * characters — the only kind of typed code now. It does not expire and
         * is not spent by being used; the only way it stops working is an
         * admin deliberately regenerating that one table's code (see
         * App\Services\TableEntry::rotateCode() and
         * AdminController::regenerateTableCode()).
         *
         * (A second, shorter-lived staff-issued fallback code used to exist for
         * a customer whose card was missing or damaged. It has been retired:
         * the permanent code is now shown to staff directly on the QR & Table
         * Codes dashboard, so reading it out loud no longer needs a second,
         * separate credential system.)
         *
         * Resolves through App\Services\TableEntry::validate() — the identical
         * function the camera-app QR landing validates through — so a typed
         * code gets every refusal a scan gets, and the two doors cannot drift
         * apart.
         */
        if ($manualCode !== '') {
            $throttleKey = 'table-code:' . $request->ip();

            if (RateLimiter::tooManyAttempts($throttleKey, \App\Services\TableEntry::MAX_ATTEMPTS)) {
                return back()
                    ->with('error', 'Too many attempts. Please wait a minute and try again.')
                    ->with('show_manual', true);
            }

            RateLimiter::hit($throttleKey, 60);

            $permanent = \App\Services\TableEntry::resolveCode($manualCode);

            // One message whether the string was never a code at all or was a
            // real code that failed validate() for some other reason — see
            // resolveCode()'s own docblock for why that refusal is not split
            // out further: it would let someone probe for codes that exist.
            if ($permanent === null) {
                return back()
                    ->with('error', 'That code is not valid. Please check the code printed on your table, or ask our staff for help.')
                    ->with('show_manual', true)
                    ->withInput();
            }

            if (!$permanent['ok']) {
                return back()
                    ->with('error', $permanent['error'])
                    ->with('show_manual', true)
                    ->withInput();
            }

            RateLimiter::clear($throttleKey);

            return $this->startDineIn(
                $request,
                $permanent['branch'],
                $permanent['table_number'],
                ($permanent['table'] ?? null)?->id
            );
        }

        // ── Camera scan path.
        $rawPayload = $request->input('tableData');

        // A scanned payload is untrusted input from the physical world, so it
        // is throttled too. Without this, the scan field was an unlimited probe
        // against the branch table.
        $scanKey = 'qr-scan:' . $request->ip();

        if (RateLimiter::tooManyAttempts($scanKey, \App\Services\QrPayload::MAX_ATTEMPTS)) {
            return back()->with('error', 'Too many scan attempts. Please wait a minute and try again.');
        }

        RateLimiter::hit($scanKey, 60);

        // All parsing and validation lives in QrPayload so the scan path cannot
        // drift from what the admin QR generator actually encodes.
        $parsed = \App\Services\QrPayload::parse(is_string($rawPayload) ? $rawPayload : null);

        if (!$parsed['ok']) {
            return back()->with('error', $parsed['error']);
        }

        RateLimiter::clear($scanKey);

        return $this->startDineIn($request, $parsed['branch'], $parsed['table_number'], ($parsed['table'] ?? null)?->id);
    }

    /**
     * Establish the Dine-In session for a resolved branch + table and send the
     * customer onward. Shared by both entry paths — a camera scan and a
     * manually typed table code — so they cannot drift apart.
     */
    /**
     * Claim the table for this visitor, turning the race-loss exception into the
     * same ordinary "table is busy" answer rather than a 500.
     *
     * @return array{ok: bool, error?: string, continued?: bool}
     */
    /**
     * The session-creation rate limit for the /customer/menu QR landing.
     *
     * Returns the message to show, or null when the request is within limits.
     *
     * Both counters from the `table-session` limiter are applied here — per
     * ordering party and per address — using the same constants and the same
     * RateLimitServiceProvider::visitorKey() identity the named limiter uses,
     * so this door and the POST door cannot end up with different numbers.
     * The wording matches what throttle.friendly produces on the POST door.
     */
    private function tableSessionRateLimit(Request $request): ?string
    {
        $visitorKey = 'table-session|visitor:' . \App\Providers\RateLimitServiceProvider::visitorKey($request);
        $ipKey = 'table-session|ip:' . $request->ip();

        $overVisitor = RateLimiter::tooManyAttempts(
            $visitorKey,
            \App\Providers\RateLimitServiceProvider::TABLE_SESSION_PER_SESSION
        );

        $overIp = RateLimiter::tooManyAttempts(
            $ipKey,
            \App\Providers\RateLimitServiceProvider::TABLE_SESSION_PER_IP
        );

        if ($overVisitor || $overIp) {
            return 'You are opening table sessions a little too quickly. '
                . 'Please wait a moment and scan again.';
        }

        RateLimiter::hit($visitorKey, 60);
        RateLimiter::hit($ipKey, 60);

        return null;
    }

    /**
     * The one place both dine-in doors — the QR/code entry flow's
     * startDineIn() and the phone-camera URL landing in showMenu() — claim a
     * table, so a visibility side effect added here reaches both without being
     * duplicated in each caller.
     */
    private function claimTable(\App\Models\Branch $branch, string $tableNumber, ?string $ip, bool $askBeforeMoving = false, ?int $registeredId = null): array
    {
        /*
         * A device already seated at ANOTHER table of this branch is changing
         * table, not arriving — App\Services\TableChange decides what that
         * means. Everything else (no seat, the same table, another branch's
         * QR) falls through to the ordinary claim below, exactly as before.
         *
         * $askBeforeMoving is the phone-camera door: it renders the menu at the
         * QR's own URL, so a refresh or the Back button replays an old table's
         * QR, and that must never move a seated customer without a tap.
         */
        $change = \App\Services\TableChange::assess($branch, $tableNumber);

        if ($change['kind'] === 'active_order') {
            return ['ok' => false, 'redirect' => redirect()->route('customer.menu')->with(
                'table_change_error',
                \App\Services\TableChange::activeOrderMessage((string) $change['order']->table_number)
            )];
        }

        if ($change['kind'] === 'occupied' || ($change['kind'] === 'free' && $askBeforeMoving)) {
            $staged = \App\Services\TableEntry::find($branch->id, $tableNumber);

            // Deleted (an unused typo table) since this door validated it.
            if (!$staged) {
                return ['ok' => false, 'error' => \App\Services\TableEntry::ERR_TABLE_NOT_FOUND];
            }

            \App\Services\TableChange::stage($staged, $change['seat'], $change['kind'] === 'occupied');

            return ['ok' => false, 'redirect' => redirect()->route('customer.menu')];
        }

        // $registeredId is the registry row this door validated: claim()
        // refuses under its lock if that row has been deleted since.
        try {
            $occupancy = $change['kind'] === 'free'
                ? \App\Services\TableChange::move($branch, $tableNumber, $ip, $registeredId)
                : \App\Services\TableOccupancy::claim($branch, $tableNumber, $ip, $registeredId);
        } catch (\App\Services\TableAlreadyOccupied) {
            return ['ok' => false, 'error' => \App\Services\TableOccupancy::BLOCKED_MESSAGE];
        }

        if ($occupancy['moved'] ?? false) {
            session()->flash('success', "You've moved to Table " . strtoupper(trim($tableNumber)) . '.');

            return $occupancy;
        }

        // Visibility only — see TableOccupancy::claim()'s docblock. Never a
        // refusal: it only tells a joining device it landed on a table someone
        // else already opened, the same table it was always going to land on.
        // Flashed rather than returned to the caller so it survives exactly
        // one redirect (startDineIn's guest path goes through customer.menu
        // via a redirect; showMenu's own URL-landing branch reads it back in
        // the very same request).
        if (($occupancy['continued'] ?? false) === true) {
            session()->flash('table_session_joined_table', $tableNumber);
        }

        return $occupancy;
    }

    private function startDineIn(Request $request, \App\Models\Branch $branch, $tableNumber, ?int $registeredId = null)
    {
        /*
         * Table occupancy. Both dine-in doors — a camera scan and a staff-issued
         * code — funnel through here, so one check covers both. A visitor who
         * already holds this table (refresh, re-scan, back-and-forward) is let
         * straight through by TableOccupancy; a genuinely different visitor is
         * turned away rather than starting a second concurrent session on the
         * same physical table.
         */
        $occupancy = $this->claimTable($branch, (string) $tableNumber, $request->ip(), false, $registeredId);

        if (!$occupancy['ok']) {
            return $occupancy['redirect'] ?? back()
                ->with('error', $occupancy['error'])
                ->with('show_manual', (bool) $request->input('table_code'));
        }

        // Same party, new table: no "welcome" moment, and their orders from
        // earlier in this visit stay theirs. See App\Services\TableChange.
        $moved = (bool) ($occupancy['moved'] ?? false);

        // Always establish the Dine-In context before authentication.
        // This lets Login/Sign Up preserve the scanned branch and table.
        $this->switchBranch($branch->id);
        session()->put('table_number', $tableNumber);
        session()->put('order_type', 'dine_in');

        $next = $request->input('next', 'guest');

        if ($next === 'login') {
            return redirect()->route('customer.login');
        }

        if ($next === 'register') {
            return redirect()->route('customer.register');
        }

        /*
         * Continue as Guest:
         * If a customer was previously logged in, explicitly log them out
         * so the Dine-In Guest session does not inherit account-only access
         * such as My Account, Order History, and Vouchers & Points.
         *
         * Preserve the Dine-In context after logout because the guest still
         * needs the scanned branch and table.
         */
        if ($next === 'guest') {
            if (Auth::guard('customer')->check()) {
                Auth::guard('customer')->logout();
            }

            $this->switchBranch($branch->id);
            session()->put('table_number', $tableNumber);
            session()->put('order_type', 'dine_in');

            // A guest order is tracked separately from an account order.
            // Clearing the whole set matters now that a visit can hold several
            // orders: the next party at this table must not inherit them.
            if (!$moved) {
                \App\Support\GuestOrders::forget();
            }

            // Only a genuinely new table claim is a "just scanned the QR"
            // moment — a refresh or a re-scan of the same table (continued)
            // must not re-show the welcome popup every time.
            if (!$moved && ($occupancy['continued'] ?? false) === false) {
                session()->flash('welcome_customer', ['type' => 'new', 'name' => null]);
            }
        }

        return redirect()->route('customer.menu');
    }

    public function scanQr()
    {
        return view('customer.dineinqr');
    }

    /**
     * The dine-in menu reporting that somebody is still there.
     *
     * Called from a scroll/tap/key listener that is throttled client-side to at
     * most one call per sixty seconds of continuous activity, so a customer
     * reading a menu for half an hour costs thirty writes, not thirty thousand.
     * This is the ONLY thing that extends the fifteen-minute guest window; see
     * App\Services\TableOccupancy::GUEST_IDLE_MINUTES.
     *
     * Answers `recorded` rather than failing when there is nothing to record.
     * A pick-up customer, a logged-out browser, a tab left open after the table
     * was released — none of those are errors, they are simply requests with no
     * live dine-in occupancy behind them, and the page has nothing to do about
     * it either way.
     */
    public function tableActivity(Request $request)
    {
        return response()->json([
            'recorded' => \App\Services\TableOccupancy::recordGuestActivity(),
        ]);
    }

    /**
     * "Is my table session still good?" — asked on page load, on a short
     * interval, and whenever the tab comes back to the foreground.
     *
     * READ-ONLY WHERE IT COUNTS, and that is load-bearing: this answer must
     * never be the reason the session SURVIVES. It writes no clock and touches
     * no occupancy row, so hammering it cannot keep a phone-left-on-the-table
     * session alive — a test pins exactly that. All the reasoning lives on
     * App\Services\TableOccupancy::inspectGuestSession(), which is also where
     * App\Services\TableEntry::validate() is reused so this cannot drift from
     * what the three dine-in doors enforce.
     *
     * On a failure the message is flashed under the SAME session('error') key
     * the QR-stale refusal already uses and the code-entry route is handed back
     * for the page to navigate to — the identical alert, in the identical
     * place, as every other dine-in refusal. Nothing new was invented for it.
     *
     * THE ONE CASE THAT ALSO TEARS THE SESSION DOWN
     * ----------------------------------------------
     * A staff-cleared table is the only failure where somebody deliberately
     * ended this visit, and it is the only one where leaving the customer's
     * session half-standing would matter. Every other failure is an expiry the
     * customer can simply scan out of; this one means the table has been handed
     * back to the floor and may already be seating a new party.
     *
     * So the dine-in keys go, and the cart goes with them. The cart is the
     * point: those lines were priced against THIS branch for THIS table, and
     * forgetting the table while keeping the basket is how a customer ends up
     * checking out against a table that is no longer theirs. Cart-follows-table
     * is the same rule switchBranch() already applies when the branch changes,
     * and for the same reason.
     *
     * Not an invalidate(): a signed-in customer stays signed in, keeps their
     * points and their vouchers, and lands on the code-entry page ready to
     * scan back in. Ending their dine-in visit is not a reason to log them out.
     */
    public function tableSessionStatus(Request $request)
    {
        $result = \App\Services\TableOccupancy::inspectGuestSession();

        if ($result['valid']) {
            return response()->json(['valid' => true]);
        }

        if (($result['reason'] ?? null) === \App\Services\TableOccupancy::RELEASE_STAFF_CLEARED) {
            $request->session()->forget([
                \App\Services\TableOccupancy::SESSION_KEY,
                'cart',
                'order_type',
                'table_number',
            ]);
        }

        session()->flash('error', $result['error']);

        return response()->json([
            'valid'    => false,
            'redirect' => route('customer.dineinqr'),
        ]);
    }


    public function showMore()
    {
        $customer = Auth::guard('customer')->user();

        // A dine-in guest may open More without an account.
        // Prefer the branch from the QR/session, then the logged-in customer's branch,
        // then fall back to the main branch.
        $branchId = session('branch_id') ?: ($customer?->branch_id);

        $branch = $branchId
            ? \App\Models\Branch::find($branchId)
            : \App\Models\Branch::where('is_main_branch', true)->first();

        if (!$branch) {
            $branch = \App\Models\Branch::where('is_active', true)->orderBy('id')->first();
        }

        // Resolved once, here and on the customer's order page / receipt — see
        // App\Support\StoreContact.
        $storeInfo = \App\Support\StoreContact::forBranch($branch?->id);

        return view('customer.more', compact('customer', 'branch', 'storeInfo'));
    }

    public function showAccount()
    {
        if (!Auth::guard('customer')->check()) {
            return redirect()->route('customer.menu');
        }

        return view('customer.account-settings');
    }

    public function showItem($id)
    {
        // findOrFail() first, unscoped by branch, so an archived or genuinely
        // nonexistent id still 404s exactly as it always has — that refusal
        // has nothing to do with branches and must not change shape here.
        $item = \App\Models\MenuItem::with([
            'category',
            'subcategory',
            // .ingredients.inventory so optionsAvailableForBranch() below can
            // check each option's branch mapping without an N+1.
            'options.ingredients.inventory',
            // Automatic out-of-stock check on the item-details page.
            'recipeIngredients.inventory',
            'inventoryItem',
            // Menu Item Sizes (Phase 2): the Regular/Large picker and each
            // size's own stock state.
            'allSizes.ingredients.inventory',
        ])->findOrFail($id);

        /*
         * Branch scoping (Phase 3 audit, Door B). This route is reachable by
         * URL alone — no menu link — so a visitor with no branch chosen yet
         * used to be able to open ANY branch's item details, and one whose
         * branch didn't match the item's could too. A shared item
         * (branch_id NULL) stays visible everywhere, exactly like the menu
         * and category listings already treat it.
         *
         * "No branch chosen yet" is decided as "nothing to see" here, not
         * "show everything" — 404, the same refusal a genuinely missing item
         * gets, so this endpoint never distinguishes "no such item" from
         * "not open to you" any more than the admin's per-branch endpoints do.
         */
        if ($item->branch_id !== null && (int) $item->branch_id !== (int) session('branch_id')) {
            abort(404);
        }

        /*
         * Branch-aware add-ons (Phase 3 audit, Finding #3). $item->branch_id
         * is either this session's own branch (checked above) or null (a
         * shared item, viewable from any branch) — either way the actual
         * ordering branch is the customer's session branch, which is what
         * MenuOption::isMappedForBranch() must be checked against. An option
         * assigned to this item but with no ingredient link for this branch
         * is left off the list entirely rather than shown-but-inert.
         */
        $branchId = session('branch_id') ? (int) session('branch_id') : null;
        $availableOptions = $item->optionsAvailableForBranch($branchId);

        $reserved = app(\App\Services\InventoryDeductionService::class)->committedQuantities();

        /*
         * Menu Item Sizes (Phase 2). A sized item is ordered by size, so the
         * page offers each LIVE size with its own price and its own
         * orderability — every verdict from the Phase 1 size functions, never
         * the base recipe (MenuItem::sizeChoices()). Empty for an unsized
         * item, whose page renders exactly as before.
         */
        // Sized even when every size is archived (MenuItem::hasSizes()): such
        // an item has an empty picker and nothing to add, never a fall-back to
        // its base price.
        $itemIsSized = $item->hasSizes();
        $sizeChoices = $itemIsSized ? $item->sizeChoices($reserved) : [];

        return view('customer.item-details', compact('item', 'availableOptions', 'reserved', 'sizeChoices', 'itemIsSized'));
    }

    public function showItems($id)
    {
        $selectedBranchId = session('branch_id');
        $orderType = session('order_type');

        /*
         * Kailangan ng branch bago makita ang listing na ito.
         *
         * This used to only apply to a LOGGED-IN customer — a guest with no
         * branch chosen yet fell through the `if ($selectedBranchId)` filter
         * below untouched and saw every branch's items in the category
         * (Phase 3 audit, Door B). Branches never share inventory, so that
         * was a real leak, not just an inconvenience. Forcing branch
         * selection first, for guest and account alike, is the same rule
         * addToCart() now enforces unconditionally.
         */
        if (
            $orderType !== 'dine_in' &&
            !$selectedBranchId
        ) {
            return redirect()->route('customer.menu')
                ->with('error', 'Please select a pick-up branch first.');
        }

        $category = \App\Models\Category::findOrFail($id);

        $subcategories = \App\Models\Subcategory::where('category_id', $id)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $itemsQuery = \App\Models\MenuItem::with([
                'recipeIngredients.inventory',
                'inventoryItem',
                // Sized cards are judged by their own sizes (Phase 2).
                'allSizes.ingredients.inventory',
            ])
            ->where('category_id', $id)
            ->where('is_available', true);

        // Filter by branch
        if ($selectedBranchId) {
            $itemsQuery->where(function ($q) use ($selectedBranchId) {
                $q->where('branch_id', $selectedBranchId)
                    ->orWhereNull('branch_id');
            });
        }

        $items = $itemsQuery
            ->orderBy('subcategory_id')
            ->orderBy('name')
            ->get();

        $categories = \App\Models\Category::where('is_active', true)
            ->orderBy('name')
            ->get();

        $branches = \App\Models\Branch::where('is_active', true)
            ->orderBy('id')
            ->get();

        $reserved = app(\App\Services\InventoryDeductionService::class)->committedQuantities();

        return view('customer.menu', compact(
            'categories',
            'items',
            'category',
            'subcategories',
            'branches',
            'selectedBranchId',
            'orderType',
            'reserved'
        ));
    }

    // ══════════ ACCOUNT SETTINGS ══════════

    public function updateAccount(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = Auth::guard('customer')->user();

        if (!$user) {
            return redirect()->route('customer.login');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'contact_number' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            // Security review 2026-08-31: blank still means "keep my current
            // password", but anything typed must meet the shared policy.
            'password' => \App\Support\PasswordPolicy::optional(),
            // Only demanded below, when the email or password is changing.
            'current_password' => 'nullable|string',
        ]);

        $changingEmail    = $validated['email'] !== $user->email;
        $changingPassword = !empty($validated['password']);

        /*
         * RE-AUTHENTICATION (hardening pass F5, 2026-09-27).
         *
         * The email and the password are what take an account over, and this
         * used to change both on nothing more than a live session — a phone
         * left unlocked at the table, or a copied session cookie, was enough.
         * The current password is now required for either, the same rule
         * AdminController::updateOwnPassword() applies to staff. Name,
         * address and contact edits still need nothing. A refusal saves
         * nothing at all, not the rest of the form. Guessing is capped by the
         * route's `customer-account-update` limiter.
         */
        if ($changingEmail || $changingPassword) {
            if (empty($validated['current_password'])) {
                return back()->withErrors([
                    'current_password' => 'Enter your current password to change your email or password.',
                ]);
            }

            if (!Hash::check($validated['current_password'], $user->password)) {
                return back()->withErrors([
                    'current_password' => 'That is not your current password.',
                ]);
            }
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->contact_number = $validated['contact_number'] ?? $user->contact_number;
        $user->address = $validated['address'] ?? $user->address;

        if ($changingPassword) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        if ($changingPassword) {
            /*
             * Rotate this account out of every other session.
             *
             * One session per account (App\Support\SingleSession) already
             * ends every OTHER device at login, so the only other live
             * session left is a copy of this session's own cookie — which
             * shares this session and would pick up any new fingerprint too.
             * A new session id leaves that copy holding the old id and the
             * old fingerprint. claim() then writes a fresh remember_token (the
             * single-session token) and fingerprints only THIS session with
             * it, so the copy's next request is signed out and this device
             * stays — exactly the regenerate() + claim() the customer login
             * does. (updateOwnPassword() also sets a random token first; claim()
             * overwrites it at once, so it is not repeated here.)
             */
            $request->session()->regenerate();
            \App\Support\SingleSession::claim($request, 'customer');
        }

        return redirect()->back()
            ->with('success', 'Account updated successfully!');
    }

    public function deleteAccount()
    {
        /** @var \App\Models\User $user */
        $user = Auth::guard('customer')->user();

        if (!$user) {
            return redirect()->route('customer.login');
        }

        Auth::guard('customer')->logout();

        $user->is_active = false;
        $user->save();

        return redirect()->route('customer.login')
            ->with('success', 'Account deactivated.');
    }

    /**
     * Return the customer's current active order, if any.
     * Active orders are pending, preparing, or serving.
     */
    private function getActiveCustomerOrder(): ?Order
    {
        $query = Order::whereIn('status', [
            'pending',
            'preparing',
            'serving',
        ]);

        if (Auth::guard('customer')->check()) {
            return $query
                ->where('user_id', Auth::guard('customer')->id())
                ->latest()
                ->first();
        }

        $guestOrderIds = \App\Support\GuestOrders::ids();

        if (!$guestOrderIds) {
            return null;
        }

        return $query
            ->whereIn('id', $guestOrderIds)
            ->latest()
            ->first();
    }

    /**
     * Block cart changes while the customer has an active order they are not
     * allowed to order alongside.
     *
     * A dine-in customer still seated at their table is allowed — see
     * CustomerOrderAccess::mayOrderAlongside(). Anyone else with an order
     * still in flight is held to the original one-at-a-time rule.
     */
    private function rejectIfActiveOrder()
    {
        $activeOrder = $this->getActiveCustomerOrder();

        if ($activeOrder && !\App\Services\CustomerOrderAccess::mayOrderAlongside($activeOrder)) {
            return redirect()->route('customer.menu')->with([
                'active_order_warning' => true,
                'active_order_message' => 'You already have an ongoing order. You cannot add another item until your current order is completed or cancelled by staff.',
                'active_order_id' => $activeOrder->id,
            ]);
        }

        return null;
    }

    // ══════════ CART (SESSION-BASED) ══════════

            public function addToCart(Request $request)
        {
            if ($response = $this->rejectIfActiveOrder()) {
                return $response;
            }

            $request->validate([
                'item_id' => 'required|exists:menu_items,id',
                'quantity' => 'required|integer|min:1',
                // Same shape storeManualOrder() requires. Also keeps the
                // assignment check below honest: (int) of an array is 1, so a
                // nested array would otherwise pass it as option #1.
                'options' => 'nullable|array',
                'options.*' => 'integer',
                // Menu Item Sizes (Phase 2). Required for a sized item — see
                // the size block below; a scalar integer or nothing.
                'size_id' => 'nullable|integer',
            ]);

            $selectedBranchId = session('branch_id');

            /*
             * A branch must be chosen before anything reaches the cart — no
             * exception for "branchless" (Phase 3 audit, Door B). This used
             * to skip the branch check entirely when no branch was selected,
             * which let a guest add ANY branch's item; branches never share
             * inventory or stock, so checking that item out would deduct the
             * wrong branch's stock regardless of what branch the order was
             * eventually placed at.
             */
            if (!$selectedBranchId) {
                return redirect()->back()
                    ->with('error', 'Please select a pick-up branch first.');
            }

            $item = \App\Models\MenuItem::where('id', $request->input('item_id'))
                ->where(function ($q) use ($selectedBranchId) {
                    $q->where('branch_id', $selectedBranchId)
                    ->orWhereNull('branch_id');
                })
                ->first();

            if (!$item) {
                return redirect()->back()
                    ->with('error', 'This item is not available for your selected branch.');
            }

            /*
             * Menu Item Sizes (Phase 2) — enforced here, server-side, not just
             * by the item page's radio buttons.
             *
             * A sized item cannot reach the cart without a size: its
             * menu_items.price is only the "starting from" figure and is never
             * charged, and its base recipe is not what any size deducts. The
             * size must be one of THIS item's own, live and active — decided by
             * MenuItem::resolveOrderableSize(), the Phase 1 resolver, whose
             * refusal is already a customer-facing sentence. A size posted for
             * an unsized item is refused the same way rather than ignored.
             */
            $item->loadMissing('allSizes.ingredients.inventory');
            $size = null;
            $postedSizeId = $request->input('size_id');

            if ($item->hasSizes()) {
                if ($postedSizeId === null || $postedSizeId === '') {
                    return redirect()->back()
                        ->with('error', 'Please choose a size for ' . $item->name . ' first.');
                }

                try {
                    $size = $item->resolveOrderableSize((int) $postedSizeId);
                } catch (\App\Exceptions\MenuItemSizeUnavailableException $e) {
                    return redirect()->back()->with('error', $e->getMessage());
                }
            } elseif ($postedSizeId !== null && $postedSizeId !== '') {
                return redirect()->back()->with(
                    'error',
                    \App\Exceptions\MenuItemSizeUnavailableException::notFound($item)->getMessage()
                );
            }

            $cart = session()->get('cart', []);

            $itemId = $request->input('item_id');
            $quantity = (int) $request->input('quantity', 1);
            $selectedOptions = $request->input('options', []);

            $optionDetails = [];
            $optionsTotal = 0;

            if (!empty($selectedOptions)) {
                /*
                 * Each add-on must be one of THIS item's own (hardening pass
                 * F7, 2026-09-27). The branch check below only proves the
                 * add-on can be made at this branch, not that it belongs on
                 * this item — without this, any other item's add-on could be
                 * posted, carted and charged. Same check, same order, as the
                 * counter's storeManualOrder(). A missing id fails it too.
                 */
                foreach ($selectedOptions as $optionId) {
                    if (! $item->options->firstWhere('id', (int) $optionId)) {
                        return redirect()->back()
                            ->with('error', 'Sorry, that add-on is not available for "' . $item->name . '".');
                    }
                }

                $options = \App\Models\MenuOption::with('ingredients.inventory')->whereIn('id', $selectedOptions)->get();

                foreach ($options as $opt) {
                    // Branch-aware add-on guard (Phase 3 audit, Finding #3),
                    // defense in depth alongside showItem() hiding an
                    // unmapped option from the checkbox list — a stale tab,
                    // a cached page, or a crafted request could still post
                    // its id. Refused the same way orderBlockedReason()
                    // below refuses the base item.
                    if (! $opt->isMappedForBranch((int) $selectedBranchId)) {
                        return redirect()->back()
                            ->with('error', 'Sorry, "' . $opt->name . '" is not available for your selected branch right now.');
                    }

                    $optionDetails[] = [
                        'id' => $opt->id,
                        'name' => $opt->name,
                        'price' => $opt->additional_price,
                    ];

                    $optionsTotal += $opt->additional_price;
                }
            }

            // One line per distinct selection. A size is part of the selection
            // ("26_s5", "26_s5_8"), so Regular and Large are separate lines;
            // an unsized item keeps its "26" / "26_8" keys exactly as before.
            $cartKey = $size !== null ? $itemId . '_s' . $size->id : $itemId;

            if (!empty($selectedOptions)) {
                sort($selectedOptions);
                $cartKey = $cartKey . '_' . implode('_', $selectedOptions);
            }

            $menuItem = \App\Models\MenuItem::findOrFail($itemId);

            // Automatic out-of-stock guard: an item whose recipe cannot be
            // covered by current inventory can never reach the cart, no matter
            // what the admin's is_available toggle says. Checked against the
            // TOTAL this add would bring the line to, not just this request.
            $alreadyInCart = isset($cart[$cartKey]) ? (int) $cart[$cartKey]['quantity'] : 0;
            $wanted = $alreadyInCart + $quantity;

            // No recipe set at all is an admin problem with its own wording.
            // A sized line asks about ITS size's recipe (Phase 2) — the base
            // recipe says nothing about whether a Large can be made.
            if ($size !== null) {
                if (! $menuItem->hasRecipe($size)) {
                    return redirect()->back()
                        ->with('error', $menuItem->orderBlockedReason($wanted, $size));
                }
            } elseif ($menuItem->isMissingRecipe()) {
                return redirect()->back()
                    ->with('error', $menuItem->orderBlockedReason($wanted));
            }

            /*
             * Stock is judged against what is genuinely still promisable —
             * inventory MINUS the stock open orders have already committed but
             * not yet had deducted. Checkout applies the very same rule under a
             * row lock; doing it here too means the customer finds out while
             * they can still fix it, rather than at the confirm screen.
             */
            $deduction = app(\App\Services\InventoryDeductionService::class);
            $available = $deduction->unitsAvailableFor(
                $menuItem,
                (int) $selectedBranchId,
                $selectedOptions ?: [],
                null,
                $size // null for an unsized item: the base recipe, as before
            );

            if ($available !== null && $wanted > $available) {
                return redirect()->back()->with(
                    'error',
                    $deduction->shortfallMessage(
                        $size !== null ? $menuItem->name . ' (' . $size->name . ')' : $menuItem->name,
                        $available,
                        $wanted
                    )
                );
            }

            // A sized line costs its size's own price; add-ons stay flat.
            $basePrice = $size !== null ? $size->price : $menuItem->price;
            $unitPrice = $basePrice + $optionsTotal;

            if (isset($cart[$cartKey])) {
                $cart[$cartKey]['quantity'] += $quantity;
            } else {
                $cart[$cartKey] = [
                    'menu_item_id' => $itemId,
                    'name' => $menuItem->name,
                    'price' => $unitPrice,
                    'base_price' => $basePrice,
                    'quantity' => $quantity,
                    'image' => $menuItem->image,
                    'options' => $optionDetails,
                ];

                if ($size !== null) {
                    $cart[$cartKey]['size_id'] = (int) $size->id;
                    $cart[$cartKey]['size_name'] = $size->name;
                }
            }

            session()->put('cart', $cart);

            return redirect()->back()
                ->with('success', 'Added to cart!');
        }

    public function showCart()
{
    $cart = session()->get('cart', []);

    /*
     * Price the cart the SAME way checkout will, through CartPricing.
     *
     * This page used to total the prices cached in the session when each item
     * was added, while OrderController::placeOrder() re-derived them from the
     * live menu. When an admin edited a price while an item sat in a cart the
     * two disagreed and the customer was charged the figure they were never
     * shown (reproduced: cart said 400.00 / 320.00, the saved order said
     * 520.00 / 416.00). One method now answers for both.
     */
    $priced = \App\Support\CartPricing::price($cart);
    $total = $priced['subtotal'];
    $cart = \App\Support\CartPricing::displayCart($priced['lines']);
    $cartRepriced = $priced['repriced'];
    $cartHasUnavailableItem = $priced['missing'];

    // Automatic out-of-stock: the live cart lines whose recipe the current
    // inventory can no longer cover. Drives the per-line badge, the banner
    // and the disabled Place Order button; checkout re-checks server-side
    // regardless.
    $reserved = app(\App\Services\InventoryDeductionService::class)->committedQuantities();

    // Menu Item Sizes (Phase 2): sized lines, and lines CartPricing could not
    // price by size, are judged separately below — per cart line, since
    // Regular and Large of one item are two lines of the same menu_item_id.
    // Every other line goes through the two lists exactly as before.
    $unsizedLines = collect($priced['lines'])
        ->filter(fn ($line) => ($line['size'] ?? null) === null && ($line['size_problem'] ?? null) === null);

    $outOfStockItemIds = $unsizedLines
        ->filter(fn ($line) => $line['menu_item']
            && $line['menu_item']->hasRecipe()
            && ! $line['menu_item']->hasIngredientStock((int) $line['quantity'], $reserved))
        ->map(fn ($line) => (int) $line['menu_item_id'])
        ->values()
        ->all();

    // Recipe guard: cart lines for an item that has no recipe set at all.
    // Separate list and banner from out-of-stock because the fix is different
    // (an admin must enter a recipe, not restock an ingredient).
    $noRecipeItemIds = $unsizedLines
        ->filter(fn ($line) => $line['menu_item'] && $line['menu_item']->isMissingRecipe())
        ->map(fn ($line) => (int) $line['menu_item_id'])
        ->values()
        ->all();

    /*
     * Sized lines (Phase 2), keyed by cart key. A size that can no longer be
     * sold as chosen — no size at all, not this item's, inactive, archived,
     * no recipe — carries its own refusal sentence (the Phase 1 resolver's,
     * via orderBlockedReason()), the same words checkout would refuse with.
     * A sellable size short of stock joins the existing out-of-stock flag,
     * judged against ITS recipe and what open orders already hold.
     */
    $outOfStockCartKeys = [];
    $sizeIssueByCartKey = [];

    foreach ($priced['lines'] as $line) {
        $cartKey = (string) $line['cart_key'];

        if (($line['size_problem'] ?? null) !== null) {
            $sizeIssueByCartKey[$cartKey] = $line['size_problem'];
            continue;
        }

        $size = $line['size'] ?? null;

        if ($size === null || ! $line['menu_item']) {
            continue;
        }

        if (! $line['menu_item']->hasRecipe($size)) {
            $sizeIssueByCartKey[$cartKey] = $line['menu_item']->orderBlockedReason((int) $line['quantity'], $size);
        } elseif (! $line['menu_item']->hasIngredientStock((int) $line['quantity'], $reserved, $size)) {
            $outOfStockCartKeys[] = $cartKey;
        }
    }

    $cartHasOutOfStockItem = ! empty($outOfStockItemIds) || ! empty($outOfStockCartKeys);
    $cartHasNoRecipeItem = ! empty($noRecipeItemIds);
    $cartHasSizeIssue = ! empty($sizeIssueByCartKey);

    $activeOrder = $this->getActiveCustomerOrder();

    /*
     * Clear stale discount-verification session data.
     *
     * The discount modal on cart.blade is opened from:
     *   session('discount_pending')
     *   session('pending_order_id')
     *
     * These values can otherwise survive after:
     *   - staff cancels the order,
     *   - customer cancels the rejected order, or
     *   - customer continues without the discount.
     *
     * Only keep them when the referenced order is still active AND its
     * discount is still pending/rejected.
     */
    $pendingDiscountOrderId = session('pending_order_id');

    // Defensive: a stale/corrupted session value (anything but a genuine
    // order id — an array, a non-numeric string, a leftover from an older
    // session shape) must never reach a where('id', ...) query. Treat it the
    // same as "no order to monitor" instead of letting a malformed value
    // surface as a 500 on a page every customer visits after every order.
    if ($pendingDiscountOrderId !== null && !is_numeric($pendingDiscountOrderId)) {
        session()->forget(['discount_pending', 'pending_order_id']);
        $pendingDiscountOrderId = null;
    }

    if ($pendingDiscountOrderId) {
        // The cart page is where every customer lands right after an order
        // event (placed, completed, cancelled — several of which also fire a
        // bell notification), so this lookup runs on nearly every visit.
        // Anything unexpected here (a since-deleted order, a session left
        // over from a code path this was never updated for) must degrade to
        // "nothing to monitor" rather than 500 the whole cart page.
        try {
            $pendingDiscountOrderQuery = Order::where('id', (int) $pendingDiscountOrderId);

            if (Auth::guard('customer')->check()) {
                $pendingDiscountOrderQuery->where(
                    'user_id',
                    Auth::guard('customer')->id()
                );
            } else {
                $pendingDiscountOrderQuery->whereIn(
                    'id',
                    \App\Support\GuestOrders::ids() ?: [0]
                );
            }

            $pendingDiscountOrder = $pendingDiscountOrderQuery->first();

            $keepDiscountSession = $pendingDiscountOrder
                && in_array(
                    $pendingDiscountOrder->status,
                    ['pending', 'preparing', 'serving'],
                    true
                )
                && in_array(
                    $pendingDiscountOrder->discount_status,
                    ['pending', 'rejected'],
                    true
                );
        } catch (\Throwable $e) {
            report($e);
            $keepDiscountSession = false;
        }

        if (!$keepDiscountSession) {
            session()->forget([
                'discount_pending',
                'pending_order_id',
            ]);
        }
    } else {
        // No order ID means there is nothing for the cart to monitor.
        session()->forget('discount_pending');
    }

    // The Confirm Your Order modal renders outside the "cart has items" guard
    // that wraps the summary block, so the order-type it reads for the Dine-In
    // "Take Out" checkbox has to be passed in unconditionally — an empty cart
    // otherwise left $sessionOrderType undefined and 500'd the page (regression
    // from 9348f22, "Add Take Out flag for Dine-In orders"). Same expression and
    // default the summary block uses.
    $sessionOrderType = session('order_type', Auth::check() ? 'pick_up' : 'dine_in');

    $discountCards = collect();

    if (Auth::guard('customer')->check()) {
        $discountCards = \App\Models\DiscountCard::where(
                'user_id',
                Auth::guard('customer')->id()
            )
            ->where('is_active', true)
            ->where('is_verified', true)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    return view('customer.cart', compact(
        'cart',
        'total',
        'activeOrder',
        'discountCards',
        'cartRepriced',
        'cartHasUnavailableItem',
        'cartHasOutOfStockItem',
        'outOfStockItemIds',
        'cartHasNoRecipeItem',
        'noRecipeItemIds',
        'outOfStockCartKeys',
        'sizeIssueByCartKey',
        'cartHasSizeIssue',
        'sessionOrderType'
    ));
}

    public function updateCart(Request $request, $itemId)
    {
        if ($response = $this->rejectIfActiveOrder()) {
            // The cart page's background quantity sync (see cart.blade.php)
            // speaks JSON; hand it a JSON refusal rather than a 302 to the
            // menu that fetch() would silently follow and treat as success.
            if ($request->expectsJson()) {
                return response()->json([
                    'success'  => false,
                    'message'  => 'You already have an ongoing order.',
                    'redirect' => route('customer.menu'),
                ], 409);
            }

            return $response;
        }

        $cart = session()->get('cart', []);
        $newQty = (int) $request->input('quantity');

        /*
         * Stock guard for the +/- quantity control (Sept 2026).
         *
         * Every OTHER cart mutation (addToCart, placeOrder) already refuses a
         * quantity current inventory cannot cover — this endpoint used to be
         * the one gap: it wrote whatever quantity the client sent straight
         * into the session with no check at all. The session ended up
         * holding an over-stock line that placeOrder() would still catch and
         * refuse, but only at the very end, after the customer had already
         * gone through the motions of confirming the order — with nothing on
         * the cart page itself telling them why.
         *
         * Refused the same way addToCart() refuses: don't apply the change,
         * report the reason and the true max (from
         * MenuItem::remainingServings()) so the client can revert its
         * optimistic UI to a value the server will actually accept.
         */
        $blockedReason = null;
        $maxQuantity = null;

        if ($newQty >= 1 && isset($cart[$itemId])) {
            $menuItemId = $cart[$itemId]['menu_item_id'] ?? $itemId;
            $menuItem = \App\Models\MenuItem::find($menuItemId);

            /*
             * Menu Item Sizes (Phase 2): a sized line is measured as its size —
             * same resolver, same refusals as addToCart(). A line with no size
             * for an item that has since gained sizes cannot grow: it has to
             * be removed and re-added with a size.
             */
            $lineSize = null;
            $lineSizeId = $cart[$itemId]['size_id'] ?? null;

            if ($menuItem && $lineSizeId !== null) {
                try {
                    $lineSize = $menuItem->resolveOrderableSize((int) $lineSizeId);
                } catch (\App\Exceptions\MenuItemSizeUnavailableException $e) {
                    $blockedReason = $e->getMessage();
                    $maxQuantity = 0;
                }
            } elseif ($menuItem && $menuItem->hasSizes()) {
                $blockedReason = 'Please choose a size for ' . $menuItem->name
                    . ' — remove it from your cart and add it again from the menu.';
                $maxQuantity = 0;
            }

            if ($blockedReason !== null) {
                // Refused above; nothing more to measure.
            } elseif ($menuItem && $lineSize !== null) {
                if (! $menuItem->hasRecipe($lineSize)) {
                    $blockedReason = $menuItem->orderBlockedReason($newQty, $lineSize);
                    $maxQuantity = 0;
                } else {
                    $deduction = app(\App\Services\InventoryDeductionService::class);
                    $available = $deduction->unitsAvailableFor(
                        $menuItem,
                        (int) (session('branch_id') ?? $menuItem->branch_id),
                        collect($cart[$itemId]['options'] ?? [])->pluck('id')->filter()->all(),
                        null,
                        $lineSize
                    );

                    if ($available !== null && $newQty > $available) {
                        $blockedReason = $deduction->shortfallMessage(
                            $menuItem->name . ' (' . $lineSize->name . ')',
                            $available,
                            $newQty
                        );
                        $maxQuantity = $available;
                    }
                }
            } elseif ($menuItem && $menuItem->isMissingRecipe()) {
                $blockedReason = $menuItem->orderBlockedReason($newQty);
                $maxQuantity = 0;
            } elseif ($menuItem) {
                /*
                 * Same "genuinely promisable" figure addToCart() and checkout
                 * use — inventory minus what open orders have committed — so
                 * the +/- control cannot walk the line up to a quantity
                 * checkout is about to refuse.
                 */
                $deduction = app(\App\Services\InventoryDeductionService::class);
                $available = $deduction->unitsAvailableFor(
                    $menuItem,
                    (int) (session('branch_id') ?? $menuItem->branch_id),
                    collect($cart[$itemId]['options'] ?? [])->pluck('id')->filter()->all()
                );

                if ($available !== null && $newQty > $available) {
                    $blockedReason = $deduction->shortfallMessage($menuItem->name, $available, $newQty);
                    $maxQuantity = $available;
                }
            }
        }

        if ($blockedReason !== null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success'      => false,
                    'message'      => $blockedReason,
                    'max_quantity' => $maxQuantity,
                ], 422);
            }

            return redirect()->route('customer.cart')->with('error', $blockedReason);
        }

        if ($newQty < 1) {
            unset($cart[$itemId]);
        } elseif (isset($cart[$itemId])) {
            $cart[$itemId]['quantity'] = $newQty;
        }

        session()->put('cart', $cart);

        if ($request->input('voucher_code')) {
            session()->put('voucher_code', $request->input('voucher_code'));
        }

        // No `table_number` here any more. This used to copy any posted value
        // straight into session('table_number') — a table change with no QR or
        // code behind it, which nothing on the cart page ever sent. A Dine-In
        // table now only changes through a validated credential: see
        // App\Services\TableChange.

        /*
         * The '+' / '-' controls on the cart page update the quantity on screen
         * instantly and then sync to here in the background (debounced), so the
         * page no longer navigates for a quantity change. That fetch() asks for
         * JSON: give it back the authoritative figures — priced through
         * CartPricing, exactly as showCart() and placeOrder() do — so the
         * optimistic client total can be reconciled against the server's.
         */
        if ($request->expectsJson()) {
            $priced = \App\Support\CartPricing::price($cart);

            $lines = [];
            foreach ($priced['lines'] as $line) {
                $lines[(string) $line['cart_key']] = [
                    'quantity'   => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'subtotal'   => $line['subtotal'],
                ];
            }

            return response()->json([
                'success'     => true,
                'subtotal'    => $priced['subtotal'],
                'lines'       => $lines,
                'item_count'  => count($cart),
                'removed'     => ! isset($cart[$itemId]),
            ]);
        }

        return redirect()->route('customer.cart');
    }

    public function removeFromCart($itemId)
    {
        if ($response = $this->rejectIfActiveOrder()) {
            return $response;
        }

        $cart = session()->get('cart', []);

        if (isset($cart[$itemId])) {
            unset($cart[$itemId]);
            session()->put('cart', $cart);
        }

        return redirect()->route('customer.cart')
            ->with('success', 'Item removed from cart.');
    }

    public function showOrders()
    {
        return app(
            \App\Http\Controllers\Customer\OrderController::class
        )->showOrders();
    }

    // ══════════ AUTHENTICATION ══════════

    public function register(Request $request)
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users,email',
        // Security review 2026-08-31: shared complexity policy — see
        // App\Support\PasswordPolicy.
        'password' => \App\Support\PasswordPolicy::required(),
        'contact_number' => 'required|numeric|digits_between:10,13',
        'address' => 'nullable|string',
        'terms' => 'required|accepted',
    ], [
        'email.unique' => 'This email is already registered.',
        'password.confirmed' => 'Password confirmation does not match.',
        'contact_number.numeric' => 'Contact number must contain numbers only.',
        'contact_number.digits_between' => 'Contact number must be 10-13 digits.',
        'terms.required' => 'You must accept the Terms and Conditions.',
        'terms.accepted' => 'You must accept the Terms and Conditions.',
    ]);

    /*
     * Which door they came in through (Pick-Up, or Dine-In via a table QR or
     * code). It goes into the signed confirmation link so the right login
     * page opens afterwards, even on another device. The session's Dine-In
     * context (branch, table) is left untouched, so this browser can still
     * log in to its table later.
     */
    $flow = \App\Support\VerificationFlow::fromSession();

    $user = User::create([
        'name' => $validated['name'],
        'email' => $validated['email'],
        'password' => Hash::make($validated['password']),
        'contact_number' => $validated['contact_number'],
        'address' => $validated['address'] ?? null,
        'role' => 'customer',
        'is_active' => true,
    ]);

    /*
     * Confirmation email. A mail transport failure is caught and logged
     * inside the model method, so the account row is still created and the
     * customer can ask for another link from the next page.
     */
    $user->sendEmailVerificationNotification($flow);

    /*
     * NOT signed in (October 2026). The customer must click the emailed
     * link first; AuthController::login() refuses an unconfirmed customer.
     * So there is no SingleSession::claim() and no welcome popup here. Wheel
     * prizes won as a guest in this browser stay in this session and move
     * into the account at login (login() calls GuestVoucherClaims::adoptInto()).
     *
     * The session id is still regenerated: the new account's address is
     * about to be written into this session for the next page, and a session
     * that changes what it holds should not keep an id someone else may have
     * set.
     */
    $request->session()->regenerate();

    session()->put(\App\Support\VerificationFlow::SESSION_EMAIL, $user->email);
    session()->put(\App\Support\VerificationFlow::SESSION_FLOW, $flow);

    return redirect()->route('customer.email-verification.pending')
        ->with('success', 'Your account has been created.');
}
        public function login(Request $request)
        {
            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            // Never "remember me": closing the browser ends the login. See
            // AdminAuthController::login().
            if (Auth::guard('customer')->attempt($credentials, false)) {
                $user = Auth::guard('customer')->user();

                if (!$user->is_active) {
                    Auth::guard('customer')->logout();

                    return back()->withErrors([
                        'email' => 'Your account has been deactivated. Please contact support.',
                    ]);
                }

                if ($user->role !== 'customer') {
                    // logoutCurrentDevice(), not logout(): logout() rotates
                    // remember_token, the account's single-session token. Staff
                    // typing their password here must not sign the counter PC
                    // out. See App\Support\SingleSession.
                    Auth::guard('customer')->logoutCurrentDevice();

                    return back()->withErrors([
                        'email' => 'This is an administrator account. Please use the Admin Login page..',
                    ]);
                }

                /*
                 * Email-confirmation gate (October 2026). Reached ONLY after
                 * the password has already matched, so a wrong password or
                 * an unknown address still gets the one generic "Invalid
                 * email or password" below, and this message reveals nothing
                 * to someone who does not know the password.
                 *
                 * logoutCurrentDevice(), not logout(), for the same reason
                 * as the administrator branch above: no remember_token
                 * rotation, and no SingleSession::claim() either. Nothing
                 * about the account's sessions changes.
                 *
                 * Only customers reach this line. Portal accounts were turned
                 * away just above and log in through AdminAuthController,
                 * which has no such check.
                 */
                if (! $user->hasVerifiedEmail()) {
                    Auth::guard('customer')->logoutCurrentDevice();

                    session()->put(\App\Support\VerificationFlow::SESSION_EMAIL, $user->email);
                    session()->put(\App\Support\VerificationFlow::SESSION_FLOW, \App\Support\VerificationFlow::fromSession());

                    return back()
                        ->withErrors(['email' => \App\Support\VerificationFlow::UNVERIFIED_LOGIN_MESSAGE])
                        ->withInput($request->only('email'))
                        ->with('unverified_login', true);
                }

                /*
                 * Preserve the order context that brought the customer here.
                 *
                 * If the customer scanned a Dine-In QR code before logging in,
                 * keep the branch, table number, and dine-in order type.
                 * Do not reset the customer to Pickup after login.
                 */
                $orderType = session('order_type');
                $tableNumber = session('table_number');
                $branchId = session('branch_id');

                $request->session()->regenerate();

                // This is now the account's only session; any other phone or
                // browser signed in as this customer is signed out on its next
                // request. See App\Support\SingleSession.
                \App\Support\SingleSession::claim($request, 'customer');

                // Same hand-over as registration: a prize won as a guest in
                // this browser follows the customer into the account they just
                // signed in to.
                $adopted = GuestVoucherClaims::adoptInto($user->id);

                if ($orderType === 'dine_in') {
                    session()->put('order_type', 'dine_in');

                    if ($tableNumber !== null) {
                        session()->put('table_number', $tableNumber);
                    }

                    if ($branchId !== null) {
                        session()->put('branch_id', $branchId);
                    }
                } else {
                    session()->put('order_type', 'pick_up');
                    session()->forget('table_number');
                }

                $this->flashWelcomePopup($user);

                return redirect()->route('customer.menu')
                    ->with('success', 'Welcome back, ' . $user->name . '!'
                        . ($adopted > 0
                            ? ' Your ' . ($adopted === 1 ? 'voucher has' : $adopted . ' vouchers have')
                                . ' been moved to your account.'
                            : ''));
            }

            return back()->withErrors([
                'email' => 'Invalid email or password.',
            ])->withInput($request->only('email'));
        }

        /**
         * Flash the one-shot welcome popup payload the menu page reads on the
         * very next request. "Returning" vs "new" is decided purely by order
         * history — a registered account that has never actually ordered gets
         * the same first-time welcome as a guest, since nothing about their
         * account reflects a past visit yet.
         */
        private function flashWelcomePopup(\App\Models\User $user): void
        {
            session()->flash('welcome_customer', [
                'type' => $user->orders()->exists() ? 'returning' : 'new',
                'name' => $user->name,
            ]);
        }

    public function logout(Request $request)
    {
        // Logout only the customer guard
        Auth::guard('customer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')
            ->with('success', 'You have been logged out.');
    }

    /**
     * Server-side half of the "are you still there?" idle prompt: called by
     * partials.idle-timeout once a warning has gone unanswered for the grace
     * period, for ANY active customer session — logged-in Pickup, logged-in
     * Dine-In, or a guest holding only a table_session_token.
     *
     * Deliberately NOT a client-side-only redirect. Logging out the guard (when
     * one is signed in) and then invalidating the whole session — not just
     * forgetting order_type/table_number — is what makes this a real session
     * end rather than a cookie the browser could still replay: the old session
     * id is discarded, a guest's table_session_token goes with it (so it cannot
     * be used to keep pinging table-activity after the prompt fired), and the
     * CSRF token is regenerated for whatever loads next.
     *
     * No branching on order_type/guard here on purpose — "am I logged in" is
     * the only fact that changes what has to happen, and that already reads
     * straight off the guard rather than off anything the client asserts.
     */
    public function idleLogout(Request $request)
    {
        if (Auth::guard('customer')->check()) {
            Auth::guard('customer')->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['redirect' => route('home')]);
    }

    // ══════════ VOUCHER ══════════

    public function applyVoucher(Request $request)
    {
        $code = strtoupper(trim($request->input('code')));
        $subtotal = (float) $request->input('subtotal', 0);

        /*
         * One box, two kinds of code: the SHARED voucher code, and the unique
         * single-use CLAIM code a guest is given when they win on the wheel.
         * VoucherClaims::resolveTypedCode() is the single place that tells them
         * apart, and placeOrder() calls the very same resolver — so the claim
         * this preview judges is the claim checkout will spend.
         */
        $resolved = VoucherClaims::resolveTypedCode($code);
        $voucher  = $resolved['voucher'];
        $claim    = $resolved['claim'];

        if (!$voucher) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid voucher code.'
            ]);
        }

        /*
         * Every redemption rule lives on the Voucher model so this preview and
         * the real charge in OrderController::placeOrder() can never disagree.
         *
         * The branch is read from the SESSION, which is the same place
         * placeOrder() reads it from — so the branch this preview judges is the
         * branch the order will be placed at. Null when no branch is chosen
         * yet; a branch-scoped voucher is refused for that, which costs the
         * customer nothing because checkout refuses a branchless order anyway.
         */
        $sessionBranchId = session('branch_id');

        $error = $voucher->redemptionErrorFor(
            Auth::guard('customer')->user(),
            $subtotal,
            $claim,
            $sessionBranchId ? (int) $sessionBranchId : null
        );

        if ($error !== null) {
            return response()->json([
                'success' => false,
                'message' => $error,
            ]);
        }

        // Round the discount once, then derive the total from that rounded
        // figure so the preview matches the saved order to the centavo.
        $discount = $voucher->discountFor($subtotal);
        $final_total = round($subtotal - $discount, 2);

        return response()->json([
            'success' => true,
            'message' => $voucher->description ?? 'Voucher applied!',
            'discount' => $discount,
            'final_total' => $final_total,
        ]);
    }

    // ══════════ GAME ══════════

    public function showVouchers()
    {
        if (!Auth::guard('customer')->check()) {
            return redirect()->route('customer.login')
                ->with('error', 'Please login to view your vouchers.');
        }

        /*
         * 'user' is eager-loaded alongside 'voucher' because each card asks
         * UserVoucher::blockingReason(), which runs the SAME check the cart
         * runs — and that needs the holder. Without it the page would fire one
         * query per card for a user it already has in hand.
         */
        $userVouchers = \App\Models\UserVoucher::with(['voucher', 'user'])
            ->where('user_id', Auth::guard('customer')->id())
            ->orderBy('created_at', 'desc')
            ->get()
            /*
             * Usable ones first, then the ones that are merely waiting, then
             * everything that is finished with. A customer opening this page
             * wants to know what they can spend right now; before this, a used
             * or unusable claim could sit above a live one purely because it
             * was won later.
             */
            ->sortBy(fn ($uv) => match ($uv->statusKey()) {
                'ready'         => 0,
                'not_yet_valid' => 1,
                'unavailable'   => 2,
                'expired'       => 3,
                default         => 4, // used
            })
            ->values();

        return view('customer.vouchers', compact('userVouchers'));
    }

    // ══════════ HELP REQUEST ══════════

    public function submitHelpRequest(Request $request)
    {
        $orderType = session('order_type');
        $branchId = session('branch_id');
        $tableNumber = session('table_number');

        $message = $request->input('message');

        $request->validate([
            'message' => 'required|string|max:255',
        ]);

        if ($orderType !== 'dine_in') {
            return back()->with(
                'error',
                'Assistance is only available for dine-in customers.'
            );
        }

        if (!$branchId) {
            return back()->with(
                'error',
                'Unable to identify your branch.'
            );
        }

        if (!$tableNumber) {
            return back()->with(
                'error',
                'Unable to identify your table.'
            );
        }

        $existing = \App\Models\HelpRequest::where('branch_id', $branchId)
            ->where('table_number', $tableNumber)
            ->whereIn('status', ['pending', 'assisting'])
            ->first();

        if ($existing) {
            return back()->with(
                'error',
                'Help is already requested. Please wait for staff. 🙏'
            );
        }

        $activeOrder = \App\Models\Order::where('branch_id', $branchId)
            ->where('table_number', $tableNumber)
            ->whereIn('status', ['pending', 'preparing', 'serving'])
            ->latest()
            ->first();

        \App\Models\HelpRequest::create([
            'branch_id' => $branchId,
            'order_id' => $activeOrder?->id,
            'table_number' => $tableNumber,
            'status' => 'pending',
            'message' => $message,
            'requested_at' => now(),
        ]);

        return back()->with(
            'success',
            'Assistance requested! Staff will help you shortly. 🙏'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SPIN & WIN — HOW MANY SPINS, AND WHOSE
    |--------------------------------------------------------------------------
    |
    | The wheel is "play while you wait": it is tied to an order that has not
    | finished yet. Before this existed the wheel had no limit whatsoever — a
    | logged-in customer who had never placed a single order could spin forever
    | and mint real, redeemable vouchers out of nothing. (Reproduced live: 15
    | spins, 0 orders, one DISCCCCCCC voucher awarded.) The point VALUES were
    | already allowlisted then; the number of spins was not. (Since F3 the
    | server picks the prize itself — see WHEEL_SEGMENTS and addPoints().)
    |
    | The rule:
    |   - A spin needs an order of the visitor's own in a non-terminal status
    |     ('pending', 'preparing', 'serving' — the same three the rest of this
    |     app treats as active).
    |   - That order grants SPINS_PER_ORDER spins in total. One Order record is
    |     one window, however many line items it contains: a checkout with a
    |     Coke and a Chicken meal in it is one order, so five spins, not ten.
    |   - When the order reaches 'completed' or 'cancelled' the window closes.
    |     Placing a new order opens a fresh one.
    |
    | Only SPINNING is gated. Viewing the points balance, the Vouchers page and
    | redeeming something already won all keep working with no active order —
    | those paths do not go through here.
    */

    /**
     * Spins granted by one order's waiting window.
     *
     * Raised from 5 to 7 on 2026-09-01. Five felt, in the owner's own testing,
     * like there was no real chance at anything — a game-balance decision, not
     * a security one. See WHEEL_SEGMENTS for the odds that go with it.
     *
     * NOTE THE INTERACTION WITH SPINS_PER_DAY, WHICH WAS NOT CHANGED.
     * The daily cap is still 15. At 5 spins that was exactly three windows;
     * at 7 it is two windows plus one spare. A customer placing a third order
     * in one day now gets 1 spin on it rather than 7. That is a real
     * consequence and it is flagged rather than fixed here, because 15 is an
     * anti-farming backstop and this pass was explicitly not to touch rate
     * limiting or validation. If the intent "three complete windows" is to be
     * preserved, SPINS_PER_DAY should become 21 — the owner's call.
     */
    private const SPINS_PER_ORDER = 7;

    /**
     * Anti-farming backstop: spins one ACCOUNT may use across all windows in a
     * calendar day.
     *
     * The per-order cap alone is not quite enough. This app allows at most one
     * active order at a time (OrderController::placeOrder refuses a second
     * one), so windows cannot overlap — but a customer can self-cancel their
     * own pending order at any time (OrderController::cancelCustomerOrder) and
     * immediately place another. That is a free loop: order → 5 spins → cancel
     * → order → 5 spins, with no money and no staff involvement.
     *
     * 15 was chosen as three complete windows back when a window was 5 spins.
     * SPINS_PER_ORDER became 7 on 2026-09-01 and this number was deliberately
     * NOT changed with it, because it is an anti-farming control and that pass
     * was scoped to game balance only. So 15 is now two full windows plus one
     * spare: a third order in the same day yields 1 spin, not 7.
     *
     * That is still well past normal use — three separate orders in one day is
     * already unusual — and it still cuts the farming loop off early. But it is
     * no longer the round "three windows" the number was picked to be. Raising
     * it to 21 would restore that intent; leaving it is the safer default and
     * is the current state.
     *
     * Guests are deliberately not subject to this: guest points live in the
     * session and never convert into a voucher (see the guest branch of
     * addPoints), so there is nothing for a guest to farm.
     */
    private const SPINS_PER_DAY = 15;

    /**
     * The statuses an order may hold and still grant spins.
     *
     * A cancelled order grants nothing; everything else the app treats as a
     * real order — still in flight OR already completed — keeps its spins.
     * 'completed' is included deliberately: spins ACCUMULATE across orders
     * (see eligibleSpinOrders()), so finishing an order must not wipe the
     * balance it contributed.
     */
    private const SPIN_ELIGIBLE_STATUSES = ['pending', 'preparing', 'serving', 'completed'];

    /**
     * Every order of this visitor's that grants spins.
     *
     * ACCUMULATION — 2026-09-03, scoped to TODAY 2026-09-04
     * -----------------------------------------------------
     * Each eligible order is worth SPINS_PER_ORDER spins and they STACK: one
     * order = 7 spins, two orders = 14 spins total, and so on. The previous
     * behaviour fetched only the latest active order, so completing an order
     * (or placing the next one) silently discarded any spins left on the
     * earlier one.
     *
     * Only orders placed TODAY count, and a cancelled order counts for
     * nothing. Combined with the SPINS_PER_DAY backstop this keeps the wheel a
     * "play while you wait for today's order" feature rather than a balance
     * that quietly grows across every order the customer has ever placed.
     *
     * Ownership follows the pattern already established by
     * OrderController::resolveOwnedOrder() and Notification's guest scoping —
     * a logged-in customer is matched on user_id; a guest is matched on the
     * orders THIS session placed AND those must be genuine guest orders
     * (user_id IS NULL). The second condition is what stops a guest pointing
     * the guest order set at a registered customer's order.
     *
     * @return \Illuminate\Support\Collection<int,\App\Models\Order>
     */
    private function eligibleSpinOrders(): \Illuminate\Support\Collection
    {
        $query = Order::whereDate('created_at', now()->today())
            ->where('status', '!=', 'cancelled');

        if (Auth::guard('customer')->check()) {
            return $query
                ->where('user_id', Auth::guard('customer')->id())
                ->orderBy('id')
                ->get();
        }

        $guestOrderIds = \App\Support\GuestOrders::ids();

        if (!$guestOrderIds) {
            return collect();
        }

        return $query
            ->whereIn('id', $guestOrderIds)
            ->whereNull('user_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * The order a NEW spin is attributed to — the visitor's most recent
     * eligible order — or null when there is none. Kept as one place so the
     * counter and the spin endpoint attribute spins the same way.
     */
    private function spinWindowOrder(): ?Order
    {
        return $this->eligibleSpinOrders()->last();
    }

    /**
     * Everything the Game page and the spin endpoint need to agree on.
     *
     * Both callers read this one method so the counter the customer sees and
     * the decision the server enforces can never disagree. The client renders
     * it; the server re-derives it on every spin and is the only thing that
     * actually allows or refuses.
     *
     * @return array{
     *   can_spin:bool, blocked:?string, message:string,
     *   spins_used:int, spins_total:int, spins_remaining:int,
     *   order_id:?int, order_number:?string, daily_remaining:?int
     * }
     */
    private function spinWindowState(): array
    {
        $userId = Auth::guard('customer')->check()
            ? Auth::guard('customer')->id()
            : null;

        $dailyUsed = $userId
            ? GamePlayed::where('user_id', $userId)->whereDate('created_at', today())->count()
            : 0;

        // null for guests — the daily ceiling does not apply to them.
        $dailyRemaining = $userId ? max(0, self::SPINS_PER_DAY - $dailyUsed) : null;

        // array_merge, not `+`: the union operator keeps the LEFT value on a key
        // collision, so the overrides below (notably spins_remaining => 0 on the
        // daily cap) would have been silently discarded.
        $orders = $this->eligibleSpinOrders();
        $total  = self::SPINS_PER_ORDER * $orders->count();

        $base = [
            'spins_total'     => $total,
            'daily_remaining' => $dailyRemaining,
        ];

        if ($orders->isEmpty()) {
            return array_merge($base, [
                'can_spin'        => false,
                'blocked'         => 'no_active_order',
                'spins_used'      => 0,
                'spins_remaining' => 0,
                'order_id'        => null,
                'order_number'    => null,
                'message'         => 'Place an order to unlock '
                    . self::SPINS_PER_ORDER . ' spins while you wait for it.',
            ]);
        }

        // Spins are counted across the customer's WHOLE eligible order history,
        // so the balance stacks instead of resetting each order.
        $latest    = $orders->last();
        $orderIds  = $orders->pluck('id')->all();
        $used      = GamePlayed::whereIn('order_id', $orderIds)->count();
        $remaining = max(0, $total - $used);

        $withOrder = array_merge($base, [
            'spins_used'      => $used,
            'spins_remaining' => $remaining,
            'order_id'        => $latest->id,
            'order_number'    => $latest->order_number,
        ]);

        if ($remaining <= 0) {
            return array_merge($withOrder, [
                'can_spin' => false,
                'blocked'  => 'window_exhausted',
                'message'  => "You've used all {$total} spins from your "
                    . $orders->count() . ' order' . ($orders->count() === 1 ? '' : 's')
                    . '. Your next order unlocks ' . self::SPINS_PER_ORDER . ' more.',
            ]);
        }

        if ($dailyRemaining !== null && $dailyRemaining <= 0) {
            return array_merge($withOrder, [
                'can_spin'        => false,
                'blocked'         => 'daily_limit',
                // The window still has spins left on paper; the daily ceiling
                // is what is stopping them, so report 0 available either way.
                'spins_remaining' => 0,
                'message'         => "You've reached today's spin limit of "
                    . self::SPINS_PER_DAY . '. Come back tomorrow!',
            ]);
        }

        return array_merge($withOrder, [
            'can_spin' => true,
            'blocked'  => null,
            'message'  => $remaining === 1
                ? 'Last spin — make it count!'
                : $remaining . ' spins remaining.',
        ]);
    }

    public function showGame()
    {
        // Branch-aware Voucher/Ad listing (Phase 3 audit, Finding #5).
        // NULL branch_id = global (valid/shown at every branch), the same
        // convention menu_items/inventory already use and the one
        // Voucher::branchErrorFor() already enforces at redemption time —
        // see $this->scopeToCustomerBranch() for the read-side of that same
        // rule. Before this fix neither query looked at branch_id at all, so
        // a branch-3-only voucher's prize showed on the wheel legend (and
        // could be WON, see winnableVoucherFor() below) for a customer at
        // branch 1, and a branch-3-only ad played in every branch's carousel.
        $vouchers = $this->scopeToCustomerBranch(\App\Models\Voucher::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now()->endOfDay());
            })
            ->where(
                'used_count',
                '<',
                \Illuminate\Support\Facades\DB::raw('max_uses')
            ))
            ->get();

        // liveNow() is the shared active + starts_at + ends_at rule (the Menu
        // popup uses it too). This query used to skip starts_at, so an ad
        // scheduled for next week already played in the carousel.
        $gameAds = $this->scopeToCustomerBranch(\App\Models\Ad::where('placement', 'game')
            ->liveNow())
            ->get();

        // Rendered into the page so the counter is already correct on first
        // paint and after a refresh, instead of only appearing once the first
        // spin comes back.
        $spinState = $this->spinWindowState();

        // The Points card used Auth::check(), which reads the DEFAULT ('web')
        // guard — nobody on the customer side authenticates there, so a
        // logged-in customer's card always fell through to the guest total and
        // rendered "0 pts" no matter what they had actually earned.
        $pointsBalance = Auth::guard('customer')->check()
            ? (int) Auth::guard('customer')->user()->points
            : (int) session('guest_points', 0);

        /*
         * Prizes a GUEST has won in this session, re-shown on every visit to
         * this page for as long as the session lasts.
         *
         * The claim code is the only way a guest can ever redeem their prize,
         * so showing it once inside a result panel that fades is not enough —
         * a customer who looked away, or tapped Spin again, would have lost it
         * with no way back. Empty for a signed-in customer: their prizes live
         * on the Vouchers page against their account.
         */
        $guestClaims = Auth::guard('customer')->check()
            ? []
            : GuestVoucherClaims::forDisplay();

        // The wheel's segments come from the server so the odds live in one
        // place and can be tested — see WHEEL_SEGMENTS. The view shuffles them
        // for display only: addPoints() picks the segment and returns its
        // index, and the view animates to wherever that segment was drawn.
        $wheelSegments = self::WHEEL_SEGMENTS;

        return view('customer.game', compact(
            'vouchers',
            'gameAds',
            'spinState',
            'pointsBalance',
            'guestClaims',
            'wheelSegments'
        ));
    }

    /**
     * The wheel's segments — the ODDS, in one place.
     *
     * Every segment is equally likely: App\Services\SpinWheel picks an index
     * uniformly, and the page draws every segment the same size. So a value's
     * probability is simply how many segments carry it. Repeating a value is
     * how it is weighted; there is no separate weight field to fall out of
     * step with the drawing.
     *
     * WHY THIS MOVED OUT OF THE BLADE — 2026-09-01
     * ---------------------------------------------
     * It used to be a literal `var segments = [...]` inside game.blade.php.
     * Two problems: nothing server-side could describe the odds, so they could
     * not be tested at all; and the list had to be kept in step by hand with
     * the GAME_POINT_AWARDS allowlist the server checked posted values
     * against. Now the view renders from this and a test can assert the
     * distribution.
     *
     * THE SERVER PICKS THE SEGMENT — hardening pass F3, 2026-09-27
     * ------------------------------------------------------------
     * Until F3 this table was only the odds an honest client played by. The
     * browser chose the landing angle, read off the segment and POSTed its
     * points, and the server checked them against GAME_POINT_AWARDS
     * [0, 3, 5, 8]. That capped the VALUE, but a tampered client could claim 8
     * on every spin and get it. Now addPoints() ignores the request body and
     * asks App\Services\SpinWheel for a uniform index into this table. It
     * credits that segment and returns it for the page to animate to. The
     * odds are the same as before, because the page's uniform angle over
     * equal segments was already a uniform pick over indexes. The allowlist
     * was removed because the server no longer accepts a prize value from
     * anyone.
     *
     * BALANCE, 2026-09-01
     * -------------------
     * Was 8 segments: 3pts ×3, 5pts ×2, 8pts ×1, Try Again ×2 —
     * i.e. 37.5% / 25% / 12.5% / 25%.
     *
     * Now 12 segments, which gives finer control than eighths:
     *
     *   3 pts       ×4   33.3%   (was 37.5%)
     *   5 pts       ×3   25.0%   (was 25.0%)
     *   8 pts       ×2   16.7%   (was 12.5%)
     *   Try Again   ×3   25.0%   (was 25.0%)
     *
     * Try Again is deliberately left at 25%. It was ALREADY at the good end of
     * the 1-in-4 to 1-in-3 target — it was never the thing making the game feel
     * mean. What changed is the mix of what you win when you do win: the top
     * prize is a third more likely, and expected points per spin rise from
     * 3.375 to 3.583. Combined with 7 spins per order instead of 5, expected
     * points per order go from 16.9 to 25.1, a ~49% increase.
     */
    public const WHEEL_SEGMENTS = [
        ['type' => 'points', 'points' => 3, 'label' => '3 pts'],
        ['type' => 'points', 'points' => 5, 'label' => '5 pts'],
        ['type' => 'lose',   'points' => 0, 'label' => 'Try Again'],
        ['type' => 'points', 'points' => 3, 'label' => '3 pts'],
        ['type' => 'points', 'points' => 8, 'label' => '8 pts'],
        ['type' => 'points', 'points' => 5, 'label' => '5 pts'],
        ['type' => 'points', 'points' => 3, 'label' => '3 pts'],
        ['type' => 'lose',   'points' => 0, 'label' => 'Try Again'],
        ['type' => 'points', 'points' => 5, 'label' => '5 pts'],
        ['type' => 'points', 'points' => 3, 'label' => '3 pts'],
        ['type' => 'points', 'points' => 8, 'label' => '8 pts'],
        ['type' => 'lose',   'points' => 0, 'label' => 'Try Again'],
    ];

    /**
     * The voucher a spin wins at this point total, or null.
     *
     * ONE definition, used by both the guest branch and the signed-in branch of
     * addPoints(), so the two can never drift into offering different prizes on
     * the same points. Everything a wheel prize must be is here: active, a
     * wheel voucher rather than a public promo (points_required > 0), affordable
     * at this total, not expired, and not one the player already holds.
     *
     * @param  int[]  $excludeVoucherIds  vouchers this player has already won
     */
    private function winnableVoucherFor(int $totalPoints, array $excludeVoucherIds): ?\App\Models\Voucher
    {
        // Branch-aware (Phase 3 audit, Finding #5): only a GLOBAL voucher or
        // one scoped to THIS customer's own branch can be won — see
        // showGame()'s comment and scopeToCustomerBranch() below. Before this
        // fix a branch-3-only voucher could be spun and awarded to a
        // customer at branch 1, live, with a valid claim code for a
        // redemption Voucher::branchErrorFor() would then refuse at checkout
        // — a prize that could be WON but never SPENT.
        return $this->scopeToCustomerBranch(\App\Models\Voucher::where('is_active', true)
            ->where('points_required', '>', 0)
            ->where('points_required', '<=', $totalPoints)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            // A prize that has already been claimed to its limit is not a prize.
            // The redemption path refuses it (Voucher::availabilityErrorFor),
            // so awarding one would hand the customer something dead on arrival.
            ->where(function ($q) {
                $q->where('max_uses', '<=', 0)
                    ->orWhereColumn('used_count', '<', 'max_uses');
            })
            ->whereNotIn('id', $excludeVoucherIds ?: [0]))
            ->inRandomOrder()
            ->first();
    }

    /**
     * Scope a Voucher/Ad query to what THIS customer's current branch may be
     * shown or awarded: their own branch's rows, plus every GLOBAL (NULL
     * branch_id) row. NULL = global is the established convention for both
     * models — see Voucher::$fillable's own comment and
     * Voucher::branchErrorFor(), which already enforces the identical rule
     * at redemption time. This is the read side of that same rule for the
     * Spin & Win prize picker (winnableVoucherFor()) and the game page's
     * prize/ad listing (showGame()).
     *
     * A customer with no branch selected yet (session('branch_id') is null)
     * sees only global rows — mirrors branchErrorFor()'s own "no branch
     * known" handling, which refuses a branch-scoped voucher rather than
     * guessing which branch it should be treated as.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    private function scopeToCustomerBranch($query)
    {
        $branchId = session('branch_id') ? (int) session('branch_id') : null;

        return $query->where(function ($q) use ($branchId) {
            $q->whereNull('branch_id');

            if ($branchId !== null) {
                $q->orWhere('branch_id', $branchId);
            }
        });
    }

    /**
     * Whether the owner has Spin & Win switched on.
     *
     * Setting::gameEnabled() is the one global switch row. customer/game.blade.php
     * reads it to decide whether to draw the wheel, admin/vouchers.blade.php
     * reads it to label the toggle, and AdminController::toggleGame() flips it.
     * It is on only when the value is exactly '1'. A missing row is off.
     *
     * Until 2026-09-27 every reader took the first game_enabled row with no
     * branch filter while the toggle wrote the branch_id NULL row. In both
     * databases the first row was a legacy branch_id = 1 row, so the owner's
     * "switch off" never reached any reader. See GameSwitchSingleRowTest.
     */
    private function gameIsEnabled(): bool
    {
        return Setting::gameEnabled();
    }

    /** "10% off" / "₱50.00 off", for the win panel. */
    private function voucherDiscountText(\App\Models\Voucher $voucher): string
    {
        return $voucher->discount_type === 'percent'
            ? $voucher->discount_value . '% off'
            : '₱' . number_format($voucher->discount_value, 2) . ' off';
    }

    public function addPoints(Request $request)
    {
        /*
         * THE OWNER'S ON/OFF SWITCH — enforced here, not only on the page
         * (hardening pass F4, 2026-09-27).
         *
         * The Spin & Win toggle on the Vouchers page was only ever read by
         * customer/game.blade.php, which hides the wheel. This endpoint never
         * looked, so with the game switched OFF a plain POST still recorded a
         * spin and credited points (and could mint a voucher). Refused first,
         * before validation and before the spin ledger is touched: no spin is
         * used and no points move. Same 422 + `blocked` shape as the other
         * refusals below, so the page's existing handler renders it.
         */
        if (! $this->gameIsEnabled()) {
            return response()->json([
                'success'         => false,
                'blocked'         => 'game_disabled',
                'can_spin'        => false,
                'spins_remaining' => 0,
                'message'         => 'Spin & Win is turned off right now.',
            ], 422);
        }

        /*
         * NOTHING IN THE REQUEST BODY IS READ (hardening pass F3, 2026-09-27).
         *
         * This endpoint used to take `points` from the browser, which had
         * picked its own landing segment, and only checked it against an
         * allowlist. Posting 8 therefore won 8 on every spin. The outcome is
         * now picked below, by the server, once every gate has passed. Any
         * `points`, `segment` or anything else a client sends is ignored.
         */

        /*
         * SPIN CAP — the server is the only thing that decides.
         *
         * The page shows a spins-remaining counter, but that number lives in
         * the browser and is therefore worth nothing as a control: the whole
         * bug being fixed here is that anyone could POST straight to this
         * endpoint and keep earning. So the count is re-derived from the
         * games_played ledger on every single request, and the row that
         * consumes the spin is written BEFORE any points are awarded.
         *
         * The order row is locked for the duration so two spins fired at the
         * same instant cannot both read "4 used" and both go through — the
         * second one blocks, re-reads 5, and is refused. (Same order-row lock
         * the staff completion path uses for the same class of problem.)
         */
        $spinUserId = Auth::guard('customer')->check()
            ? Auth::guard('customer')->id()
            : null;

        try {
            [$spinNumber, $segment] = DB::transaction(function () use ($spinUserId) {
                $orders = $this->eligibleSpinOrders();

                if ($orders->isEmpty()) {
                    throw new \DomainException('no_active_order');
                }

                // Every spin locks the visitor's newest order row, and that is
                // also the row a new spin is attributed to — so two spins fired
                // at once serialise on the same lock: the second blocks,
                // re-reads the ledger, and is refused if the total is now spent.
                $attributeOrder = $orders->last();
                $locked = Order::whereKey($attributeOrder->id)->lockForUpdate()->first();

                if (!$locked || !in_array($locked->status, self::SPIN_ELIGIBLE_STATUSES, true)) {
                    // The order was cancelled between the page load and this spin.
                    throw new \DomainException('no_active_order');
                }

                // Cap is the accumulated total across the whole eligible
                // history, not this one order — see eligibleSpinOrders().
                $orderIds = $orders->pluck('id')->all();
                $used     = GamePlayed::whereIn('order_id', $orderIds)->count();
                $total    = self::SPINS_PER_ORDER * $orders->count();

                if ($used >= $total) {
                    throw new \DomainException('window_exhausted');
                }

                if ($spinUserId !== null) {
                    $dailyUsed = GamePlayed::where('user_id', $spinUserId)
                        ->whereDate('created_at', today())
                        ->count();

                    if ($dailyUsed >= self::SPINS_PER_DAY) {
                        throw new \DomainException('daily_limit');
                    }
                }

                // spin_number stays 1..SPINS_PER_ORDER WITHIN the attributed
                // order, so the once-per-window ad trigger still works.
                $spinInOrder = GamePlayed::where('order_id', $locked->id)->count() + 1;

                // THE OUTCOME. Drawn only now that the switch, the order window
                // and the daily cap have all let this spin through, so a
                // refused spin never draws one. Same odds the page used to
                // play by — see WHEEL_SEGMENTS.
                $segment = app(SpinWheel::class)->land(self::WHEEL_SEGMENTS);

                if (! isset(self::WHEEL_SEGMENTS[$segment])) {
                    throw new \LogicException("SpinWheel landed on segment {$segment}, which the wheel does not have.");
                }

                GamePlayed::create([
                    'user_id'        => $spinUserId,
                    'order_id'       => $locked->id,
                    'spin_number'    => $spinInOrder,
                    'points_awarded' => (int) self::WHEEL_SEGMENTS[$segment]['points'],
                ]);

                return [$spinInOrder, $segment];
            });
        } catch (\DomainException $e) {
            // Nothing was written and no points were awarded. Report the state
            // the client should now render, so a blocked spin updates the
            // counter and the explanation rather than failing silently.
            return response()->json(array_merge(
                ['success' => false, 'blocked' => $e->getMessage()],
                $this->spinWindowState()
            ), 422);
        }

        // What the server's wheel awarded. Everything below (guest points,
        // account points, the reward threshold, a wheel voucher and its
        // "valid starting tomorrow" window) runs on this value, unchanged.
        $points = (int) self::WHEEL_SEGMENTS[$segment]['points'];

        /*
         * GUEST WIN.
         *
         * This branch used to award session points and nothing else — no
         * voucher was ever minted for a guest, so a guest could not win one,
         * could not hold a claim, and therefore (item 30) could never redeem
         * one. What the team described at the defense, "save the code and type
         * it in next time", simply did not exist.
         *
         * A guest now wins the same prizes on the same thresholds as a signed-in
         * customer. The difference is only in how the prize is HELD: with no
         * account to key a claim to, the claim is ownerless and carries its own
         * unique, single-use claim code, which is handed to the customer to
         * keep. See App\Services\VoucherClaims for why that does not reopen the
         * item-30 bypass.
         */
        if (!Auth::guard('customer')->check()) {
            $totalPoints = session('guest_points', 0) + $points;

            session()->put('guest_points', $totalPoints);

            $voucherData = null;
            $claimCode   = null;

            // Same ceiling the signed-in path applies below, so playing as a
            // guest cannot be used to hoard more prizes than an account can.
            if (GuestVoucherClaims::unusedCount() < GuestVoucherClaims::MAX_UNUSED) {
                // A guest is identified by their session, so "already won"
                // means "already won in this session". What they take away is
                // the claim code, not this list.
                $earnedVoucher = $this->winnableVoucherFor(
                    $totalPoints,
                    GuestVoucherClaims::all()->pluck('voucher_id')->map('intval')->all()
                );

                if ($earnedVoucher) {
                    $totalPoints -= (int) $earnedVoucher->points_required;
                    session()->put('guest_points', $totalPoints);

                    $claim = VoucherClaims::mintForGuest($earnedVoucher);
                    GuestVoucherClaims::remember($claim);

                    $claimCode = VoucherClaims::display($claim->claim_code);

                    $voucherData = [
                        'code'        => $earnedVoucher->code,
                        'claim_code'  => $claimCode,
                        'description' => $earnedVoucher->description
                            ?? $this->voucherDiscountText($earnedVoucher),
                        'valid_from'  => $claim->valid_from?->toDateString(),
                        'expires_at'  => $earnedVoucher->expires_at?->format('M d, Y'),
                        'message'     => 'Valid now — use it on your next order.',
                    ];
                }
            }

            return response()->json(array_merge([
                'success'      => true,
                'total_points' => $totalPoints,
                'voucher'      => $voucherData,
                'next_voucher' => null,
                'points_needed' => 0,
                // Renamed from 'message' so it cannot shadow the spin-state
                // message the counter renders. Nothing in the page read it.
                'guest_notice' => $voucherData
                    ? 'Save your claim code — it is the only way to use this voucher later.'
                    : 'Keep spinning to win a voucher!',
            ], $this->spinResultState($spinNumber, $segment)));
        }

        /** @var \App\Models\User $user */
        $user = Auth::guard('customer')->user();

        $user->points += $points;
        $user->save();

        $totalPoints = $user->points;

        /*
         * POINTS-THRESHOLD REWARD (2026-09-02).
         *
         * Every PointsRewards::THRESHOLD lifetime points earns one voucher
         * reward, minted straight onto the signed-in winner's account as a
         * UserVoucher claim — self-service, no staff needed. Tell the
         * customer the moment they cross a new multiple.
         *
         * Measured against LIFETIME points from the games_played ledger, not
         * $totalPoints above. $user->points is a spendable balance — the
         * wheel-voucher branch a few lines below subtracts points_required
         * from it — so a milestone measured on it would be crossed again
         * every time the customer spent points and re-earned them, paying out
         * repeatedly for the same points. The ledger only ever grows.
         *
         * FIRING EXACTLY ONCE
         * -------------------
         * The GamePlayed row for this spin is already committed by the time
         * we get here, so the lifetime SUM below is the total INCLUDING this
         * spin, and the total before it is exactly that minus this spin's
         * award. A new multiple was crossed if the reward count went up.
         *
         * Because the ledger is append-only, each multiple is passed exactly
         * once in the customer's lifetime — so this fires once per threshold
         * and stays silent on every subsequent point until the next one. A
         * zero-point spin ("Try Again") cannot trigger it either: the before
         * and after totals are equal, so the counts are too.
         */
        $lifetimePoints = \App\Services\PointsRewards::lifetimePointsFor($user);
        $rewardsBefore  = \App\Services\PointsRewards::rewardsIn($lifetimePoints - $points);
        $rewardsAfter   = \App\Services\PointsRewards::rewardsIn($lifetimePoints);

        if ($rewardsAfter > $rewardsBefore) {
            // Name the milestone actually reached. A single spin cannot award
            // enough to skip a whole threshold today (max 8 vs 30), but if the
            // awards ever grow, announcing the HIGHEST new multiple is the
            // honest number rather than the first one passed.
            \App\Models\Notification::pointsRewardEarned(
                $user,
                $rewardsAfter * \App\Services\PointsRewards::THRESHOLD
            );
        }

        // Check kung may 2 na vouchers ang user — hindi na pwede kumita pa
        $existingVoucherCount = \App\Models\UserVoucher::where('user_id', $user->id)
            ->where('is_used', false)
            ->count();

        $voucherData = null;

        if ($existingVoucherCount < 2) {
            /*
             * Any voucher this customer has EVER been awarded, used or not.
             *
             * This used to filter on `is_used = false`, which meant a voucher
             * went back into the eligible pool the moment the customer spent it
             * — and the insert below then collided with user_vouchers'
             * UNIQUE(user_id, voucher_id) and threw a 500 straight out of the
             * spin endpoint. Reproduced live: win ZZWHEEL, redeem it at
             * checkout, spin back up to the threshold, and every qualifying
             * spin returned HTTP 500 (SQLSTATE 23000, "Duplicate entry
             * '32-37'"). The unique key is the authoritative statement of
             * intent — a given wheel voucher is winnable once per customer — so
             * this matches it instead of contradicting it.
             */
            $alreadyWon = \App\Models\UserVoucher::where('user_id', $user->id)
                ->pluck('voucher_id')
                ->map('intval')
                ->all();

            // Same selection rules the guest branch above uses, from one place,
            // so a guest and a signed-in customer can never be offered
            // different prizes on the same points.
            $earnedVoucher = $this->winnableVoucherFor($totalPoints, $alreadyWon);

            if ($earnedVoucher) {
                // Deduct points
                $totalPoints -= $earnedVoucher->points_required;
                $user->points = $totalPoints;
                $user->save();

                // Valid from = today (pwede nang gamitin sa susunod na order,
                // hindi lang sa order na kinuha ang panalo)
                $validFrom = today()->toDateString();

                /*
                 * This window belongs on THIS CUSTOMER'S claim, not on the
                 * shared voucher row. A wheel-won voucher code can be won by
                 * many different customers over time (see the UNIQUE(order_id)
                 * -free, per-user_id user_vouchers rows this creates one of),
                 * and they do not all win it on the same day. Writing
                 * valid_from onto $earnedVoucher used to mean the SECOND
                 * customer who ever won a given code silently moved the
                 * FIRST customer's redemption window too — reproduced live:
                 * winner A's effective valid_from visibly changed the moment
                 * winner B won the same voucher, with no action from A at all.
                 *
                 * vouchers.valid_from is untouched here and keeps its original,
                 * correct job: a single shared launch date for a PUBLIC promo
                 * code, which has no per-customer user_vouchers row to carry
                 * it on instead. See Voucher::redemptionErrorFor() for the
                 * matching read-side split.
                 */
                /*
                 * A signed-in win now mints a CLAIM CODE too (2026-09-01).
                 *
                 * It used to be omitted deliberately — the claim lived on the
                 * account and there was nothing to hand over. That made the
                 * Dine-In story impossible rather than merely blocked: a
                 * Dine-In customer never creates an account, so a prize won by
                 * a friend could not reach them because no transferable
                 * artefact existed at all.
                 *
                 * The claim is still recorded against the winner's account, so
                 * it still appears on their Vouchers page and still counts
                 * against the one-wheel-voucher-per-account cap. What changed
                 * is that it also has a code they can pass on.
                 */
                $accountClaim = \App\Models\UserVoucher::create([
                    'user_id'       => $user->id,
                    'voucher_id'    => $earnedVoucher->id,
                    'claim_code'    => \App\Services\VoucherClaims::mintCode(),
                    'acquired_date' => today()->toDateString(),
                    'valid_from'    => $validFrom,
                    'is_used'       => false,
                ]);

                $discountText = $this->voucherDiscountText($earnedVoucher);

                $voucherData = [
                    'code'        => $earnedVoucher->code,
                    // A signed-in winner now gets a claim code as well, so the
                    // prize can be handed to a Dine-In customer who has no
                    // account. It is shown to them the same way a guest's is.
                    'claim_code'  => \App\Services\VoucherClaims::display($accountClaim->claim_code),
                    'description' => ($earnedVoucher->description ?? $discountText),
                    'valid_from'  => $validFrom,
                    'expires_at'  => $earnedVoucher->expires_at
                        ? $earnedVoucher->expires_at->format('M d, Y')
                        : null,
                    'message'     => 'Valid now — use it on your next order.',
                ];
            }
        }

        // Next voucher to earn. Branch-scoped (Branch parity audit B6,
        // 2026-09-27) — the two other Voucher queries on this same page
        // ($vouchers above and winnableVoucherFor()) already run through
        // scopeToCustomerBranch(); this one didn't, so a branch-3-only
        // voucher's description could be shown as the "next voucher" hint to
        // a customer at branch 1, who could never actually earn or redeem it.
        $nextVoucher = $this->scopeToCustomerBranch(\App\Models\Voucher::where('is_active', true)
            ->where('points_required', '>', $totalPoints)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            }))
            ->orderBy('points_required', 'asc')
            ->first();

        return response()->json(array_merge([
            'success'        => true,
            'total_points'   => $totalPoints,
            'voucher'        => $voucherData,
            'next_voucher'   => $nextVoucher ? $nextVoucher->description : null,
            'points_needed'  => $nextVoucher
                ? ($nextVoucher->points_required - $totalPoints)
                : 0,
            'voucher_slots'  => 2 - $existingVoucherCount,
        ], $this->spinResultState($spinNumber, $segment)));
    }

    /**
     * The post-spin counter payload, shared by the guest and customer replies.
     *
     * `show_ad` is what reconciles this cap with the game-page ad popup.
     * That popup used to fire on `spinCount % 5` where spinCount was a plain
     * JavaScript variable that reset to 0 on every page load — so it depended
     * entirely on the customer taking five spins without refreshing. Now that a
     * window is exactly SPINS_PER_ORDER spins, the ad is driven off the
     * server's spin_number instead: it fires on the last spin of every window,
     * once per window, and survives refreshes because the number comes from the
     * ledger rather than from page-local state.
     *
     * `outcome` is the segment the server picked (F3): its index into
     * WHEEL_SEGMENTS plus that segment's type/points/label. The page animates
     * the wheel to land on it and shows it as the result.
     */
    private function spinResultState(int $spinNumber, int $segment): array
    {
        return array_merge([
            'spin_number' => $spinNumber,
            'show_ad'     => $spinNumber === self::SPINS_PER_ORDER,
            'outcome'     => ['segment' => $segment] + self::WHEEL_SEGMENTS[$segment],
        ], $this->spinWindowState());
    }
}
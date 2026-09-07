<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Concerns\HandlesPasswordReset;
use App\Http\Controllers\Controller;
use App\Models\GamePlayed;
use App\Models\User;
use App\Models\Order;
use App\Services\VoucherClaims;
use App\Support\GuestVoucherClaims;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

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

        return view('customer.login');
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
                $request->ip()
            );

            if (!$occupancy['ok']) {
                return redirect()->route('customer.dineinqr')
                    ->with('error', $occupancy['error']);
            }

            session()->put('table_number', $parsed['table_number']);
            session()->put('branch_id', $parsed['branch']->id);
            session()->put('order_type', 'dine_in');
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

        return view('customer.menu', compact(
            'categories',
            'menuItems',
            'branches',
            'selectedBranchId',
            'orderType'
        ));
    }

public function selectBranch(Request $request)
{
    $branchId = $request->input('branch_id');

    // If the customer changes pickup branch, clear the old cart
    // so items from another branch are not mixed.
    if (session('branch_id') && session('branch_id') != $branchId) {
        session()->forget('cart');
    }

    // Explicitly switch the customer to PICKUP mode.
    // Remove all Dine-In-only information.
    session()->put('branch_id', $branchId);
    session()->put('order_type', 'pick_up');
    session()->forget('table_number');

    return redirect()->route('customer.menu')
        ->with('success', 'Branch selected!');
}

    public function showDineInQr()
    {
        return view('customer.dineinqr');
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
                $permanent['table_number']
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

        return $this->startDineIn($request, $parsed['branch'], $parsed['table_number']);
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

    private function claimTable(\App\Models\Branch $branch, string $tableNumber, ?string $ip): array
    {
        try {
            return \App\Services\TableOccupancy::claim($branch, $tableNumber, $ip);
        } catch (\App\Services\TableAlreadyOccupied) {
            return ['ok' => false, 'error' => \App\Services\TableOccupancy::BLOCKED_MESSAGE];
        }
    }

    private function startDineIn(Request $request, \App\Models\Branch $branch, $tableNumber)
    {
        /*
         * Table occupancy. Both dine-in doors — a camera scan and a staff-issued
         * code — funnel through here, so one check covers both. A visitor who
         * already holds this table (refresh, re-scan, back-and-forward) is let
         * straight through by TableOccupancy; a genuinely different visitor is
         * turned away rather than starting a second concurrent session on the
         * same physical table.
         */
        $occupancy = $this->claimTable($branch, (string) $tableNumber, $request->ip());

        if (!$occupancy['ok']) {
            return back()
                ->with('error', $occupancy['error'])
                ->with('show_manual', (bool) $request->input('table_code'));
        }

        // Always establish the Dine-In context before authentication.
        // This lets Login/Sign Up preserve the scanned branch and table.
        session()->put('branch_id', $branch->id);
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

            session()->put('branch_id', $branch->id);
            session()->put('table_number', $tableNumber);
            session()->put('order_type', 'dine_in');

            // A guest order is tracked separately from an account order.
            // Clearing the whole set matters now that a visit can hold several
            // orders: the next party at this table must not inherit them.
            \App\Support\GuestOrders::forget();
        }

        return redirect()->route('customer.menu');
    }

    public function scanQr()
    {
        return view('customer.dineinqr');
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
        $item = \App\Models\MenuItem::with([
            'category',
            'subcategory',
            'options',
            // Automatic out-of-stock check on the item-details page.
            'recipeIngredients.inventory',
            'inventoryItem',
        ])->findOrFail($id);

        return view('customer.item-details', compact('item'));
    }

    public function showItems($id)
    {
        $selectedBranchId = session('branch_id');
        $orderType = session('order_type');

        // Pickup customer — kailangan ng branch
        if (
            Auth::guard('customer')->check() &&
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

        return view('customer.menu', compact(
            'categories',
            'items',
            'category',
            'subcategories',
            'branches',
            'selectedBranchId',
            'orderType'
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
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->contact_number = $validated['contact_number'] ?? $user->contact_number;
        $user->address = $validated['address'] ?? $user->address;

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

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
            ]);

            $selectedBranchId = session('branch_id');

            if ($selectedBranchId) {
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
            }

            $cart = session()->get('cart', []);

            $itemId = $request->input('item_id');
            $quantity = (int) $request->input('quantity', 1);
            $selectedOptions = $request->input('options', []);

            $optionDetails = [];
            $optionsTotal = 0;

            if (!empty($selectedOptions)) {
                $options = \App\Models\MenuOption::whereIn('id', $selectedOptions)->get();

                foreach ($options as $opt) {
                    $optionDetails[] = [
                        'id' => $opt->id,
                        'name' => $opt->name,
                        'price' => $opt->additional_price,
                    ];

                    $optionsTotal += $opt->additional_price;
                }
            }

            $cartKey = $itemId;

            if (!empty($selectedOptions)) {
                sort($selectedOptions);
                $cartKey = $itemId . '_' . implode('_', $selectedOptions);
            }

            $menuItem = \App\Models\MenuItem::findOrFail($itemId);

            // Automatic out-of-stock guard: an item whose recipe cannot be
            // covered by current inventory can never reach the cart, no matter
            // what the admin's is_available toggle says. Checked against the
            // TOTAL this add would bring the line to, not just this request.
            $alreadyInCart = isset($cart[$cartKey]) ? (int) $cart[$cartKey]['quantity'] : 0;

            // Refuses both "no recipe set" and "recipe can't be covered by
            // current inventory", with the right message for each — see
            // MenuItem::orderBlockedReason().
            if ($reason = $menuItem->orderBlockedReason($alreadyInCart + $quantity)) {
                return redirect()->back()->with('error', $reason);
            }

            $unitPrice = $menuItem->price + $optionsTotal;

            if (isset($cart[$cartKey])) {
                $cart[$cartKey]['quantity'] += $quantity;
            } else {
                $cart[$cartKey] = [
                    'menu_item_id' => $itemId,
                    'name' => $menuItem->name,
                    'price' => $unitPrice,
                    'base_price' => $menuItem->price,
                    'quantity' => $quantity,
                    'image' => $menuItem->image,
                    'options' => $optionDetails,
                ];
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
    $outOfStockItemIds = collect($priced['lines'])
        ->filter(fn ($line) => $line['menu_item']
            && $line['menu_item']->hasRecipe()
            && ! $line['menu_item']->hasIngredientStock((int) $line['quantity']))
        ->map(fn ($line) => (int) $line['menu_item_id'])
        ->values()
        ->all();

    $cartHasOutOfStockItem = ! empty($outOfStockItemIds);

    // Recipe guard: cart lines for an item that has no recipe set at all.
    // Separate list and banner from out-of-stock because the fix is different
    // (an admin must enter a recipe, not restock an ingredient).
    $noRecipeItemIds = collect($priced['lines'])
        ->filter(fn ($line) => $line['menu_item'] && $line['menu_item']->isMissingRecipe())
        ->map(fn ($line) => (int) $line['menu_item_id'])
        ->values()
        ->all();

    $cartHasNoRecipeItem = ! empty($noRecipeItemIds);

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

    if ($pendingDiscountOrderId) {
        $pendingDiscountOrderQuery = Order::where('id', $pendingDiscountOrderId);

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
        'noRecipeItemIds'
    ));
}

    public function updateCart(Request $request, $itemId)
    {
        if ($response = $this->rejectIfActiveOrder()) {
            return $response;
        }

        $cart = session()->get('cart', []);
        $newQty = (int) $request->input('quantity');

        if ($newQty < 1) {
            unset($cart[$itemId]);
        } elseif (isset($cart[$itemId])) {
            $cart[$itemId]['quantity'] = $newQty;
        }

        session()->put('cart', $cart);

        if ($request->input('voucher_code')) {
            session()->put('voucher_code', $request->input('voucher_code'));
        }

        if ($request->input('table_number')) {
            session()->put('table_number', $request->input('table_number'));
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
     * Preserve the current order context before creating/logging in
     * the customer.
     *
     * This is important for Dine-In because the QR code already
     * stored the branch and table number in the session.
     */
    $orderType = session('order_type');
    $tableNumber = session('table_number');
    $branchId = session('branch_id');

    $user = User::create([
        'name' => $validated['name'],
        'email' => $validated['email'],
        'password' => Hash::make($validated['password']),
        'contact_number' => $validated['contact_number'],
        'address' => $validated['address'] ?? null,
        'role' => 'customer',
        'is_active' => true,
    ]);

    // Automatically log the newly registered customer in.
    Auth::guard('customer')->login($user);

    /*
     * Regenerate the session for security.
     */
    $request->session()->regenerate();

    /*
     * Hand over any wheel prizes won as a guest in this browser.
     *
     * "I played while I waited, won something, then made an account" is an
     * ordinary sequence, and without this the prize would only be reachable by
     * re-typing its claim code — which does still work, but looks to the
     * customer as though signing up cost them their voucher. See
     * GuestVoucherClaims::adoptInto() for what it deliberately will not do.
     */
    $adopted = GuestVoucherClaims::adoptInto($user->id);

    /*
     * Restore the Dine-In context after session regeneration.
     *
     * Without this, the customer can lose the QR table information
     * when registering.
     */
    if ($orderType === 'dine_in') {

        session()->put('order_type', 'dine_in');

        if ($tableNumber !== null) {
            session()->put('table_number', $tableNumber);
        }

        if ($branchId !== null) {
            session()->put('branch_id', $branchId);
        }

    } else {

        // Normal registration = Pickup
        session()->put('order_type', 'pick_up');
        session()->forget('table_number');
    }

    return redirect()->route('customer.menu')
        ->with('success', 'Account created successfully! Welcome, ' . $user->name . '!'
            . ($adopted > 0
                ? ' Your ' . ($adopted === 1 ? 'voucher has' : $adopted . ' vouchers have')
                    . ' been moved to your account.'
                : ''));
}
        public function login(Request $request)
        {
            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            // boolean(), not has() — see AdminAuthController::login() for why
            // a present-but-falsey remember value must not count as checked.
            $remember = $request->boolean('remember');

            if (Auth::guard('customer')->attempt($credentials, $remember)) {
                $user = Auth::guard('customer')->user();

                if (!$user->is_active) {
                    Auth::guard('customer')->logout();

                    return back()->withErrors([
                        'email' => 'Your account has been deactivated. Please contact support.',
                    ]);
                }

                if ($user->role !== 'customer') {
                    Auth::guard('customer')->logout();

                    return back()->withErrors([
                        'email' => 'This is an administrator account. Please use the Admin Login page..',
                    ]);
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

    public function logout(Request $request)
    {
        // Logout only the customer guard
        Auth::guard('customer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')
            ->with('success', 'You have been logged out.');
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

        // Every redemption rule lives on the Voucher model so this preview and
        // the real charge in OrderController::placeOrder() can never disagree.
        $error = $voucher->redemptionErrorFor(Auth::guard('customer')->user(), $subtotal, $claim);

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
    | already allowlisted by GAME_POINT_AWARDS; the number of spins was not.
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
        $vouchers = \App\Models\Voucher::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now()->endOfDay());
            })
            ->where(
                'used_count',
                '<',
                \Illuminate\Support\Facades\DB::raw('max_uses')
            )
            ->get();

        $gameAds = \App\Models\Ad::where('placement', 'game')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            })
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
        // place and can be tested — see WHEEL_SEGMENTS. The view still shuffles
        // them for display; order on the wheel does not change any probability
        // because every segment is the same size.
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
     * The only point values the spin-the-wheel game can legitimately award.
     * Mirrors the `segments` array in resources/views/customer/game.blade.php.
     *
     * The score is reported by the browser, so it is attacker-controlled by
     * definition. This allowlist is what stops a crafted POST to
     * /customer/add-points from awarding an arbitrary balance: before it
     * existed, a single request with points=99999 credited the account and
     * immediately minted a real, redeemable discount voucher.
     */
    private const GAME_POINT_AWARDS = [0, 3, 5, 8];

    /**
     * The wheel's segments — the ODDS, in one place.
     *
     * Every segment is drawn the same size and the landing angle is uniform
     * (`Math.random() * 2π` in game.blade.php), so a value's probability is
     * simply how many segments carry it. Repeating a value is how it is
     * weighted; there is no separate weight field to fall out of step with the
     * drawing.
     *
     * WHY THIS MOVED OUT OF THE BLADE — 2026-09-01
     * ---------------------------------------------
     * It used to be a literal `var segments = [...]` inside game.blade.php.
     * Two problems: nothing server-side could describe the odds, so they could
     * not be tested at all; and the list had to be kept in step by hand with
     * GAME_POINT_AWARDS just above, which is the allowlist that actually
     * decides what the server will accept. Now the view renders from this and
     * a test can assert the distribution.
     *
     * THIS IS PRESENTATION, NOT A SECURITY CONTROL
     * --------------------------------------------
     * Worth being explicit, because moving it server-side makes it look more
     * authoritative than it is. The browser still decides which segment it
     * landed on and posts that value. What stops a crafted POST is
     * GAME_POINT_AWARDS, which caps the VALUE — a tampered client can still
     * claim 8 every time. That was true before this change and is unchanged by
     * it; the allowlist is the control, and this table is the odds an honest
     * client plays by. Making the distribution itself server-authoritative
     * would mean the server picking the segment, which is a different piece of
     * work and is not in this pass.
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
        return \App\Models\Voucher::where('is_active', true)
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
            ->whereNotIn('id', $excludeVoucherIds ?: [0])
            ->inRandomOrder()
            ->first();
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
        $validated = $request->validate([
            'points' => ['required', 'integer', Rule::in(self::GAME_POINT_AWARDS)],
        ]);

        $points = (int) $validated['points'];

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
            $spinNumber = DB::transaction(function () use ($spinUserId, $points) {
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

                GamePlayed::create([
                    'user_id'        => $spinUserId,
                    'order_id'       => $locked->id,
                    'spin_number'    => $spinInOrder,
                    'points_awarded' => $points,
                ]);

                return $spinInOrder;
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
                        'message'     => 'Valid starting ' .
                            ($claim->valid_from?->format('M d, Y') ?? 'today') . '.',
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
            ], $this->spinResultState($spinNumber)));
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
         * reward that staff hand over at the counter. Tell the customer the
         * moment they cross a new multiple.
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

                // Valid from = tomorrow (hindi pwede gamitin ngayon)
                $validFrom = now()->addDay()->toDateString();

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
                    'message'     => 'Valid starting tomorrow — ' .
                        \Carbon\Carbon::parse($validFrom)->format('M d, Y'),
                ];
            }
        }

        // Next voucher to earn
        $nextVoucher = \App\Models\Voucher::where('is_active', true)
            ->where('points_required', '>', $totalPoints)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
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
        ], $this->spinResultState($spinNumber)));
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
     */
    private function spinResultState(int $spinNumber): array
    {
        return array_merge([
            'spin_number' => $spinNumber,
            'show_ad'     => $spinNumber === self::SPINS_PER_ORDER,
        ], $this->spinWindowState());
    }
}
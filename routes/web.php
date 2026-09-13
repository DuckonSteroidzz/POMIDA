<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Customer\AuthController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Customer\OrderController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Customer\NotificationController as CustomerNotificationController;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;


/*
|--------------------------------------------------------------------------
| HOME
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
})->name('home');


/*
|--------------------------------------------------------------------------
| PWD / SENIOR ID DOCUMENTS
|--------------------------------------------------------------------------
|
| Deliberately OUTSIDE both the customer and admin route groups, because the
| two parties allowed to view an ID document authenticate on different guards:
| staff verifying the discount sign in on `admin`, and the customer who
| uploaded it on `customer` (or is a guest holding the order in session).
| One route serving both keeps a single copy of the file-serving logic and a
| single authorisation rule — App\Services\DiscountIdAccess — instead of two
| that could drift apart.
|
| No middleware here on purpose: the rule is not "is anyone logged in", it is
| "does THIS visitor have a claim to THIS order", which only DiscountIdAccess
| can answer. Every refusal is a 404; see that class for why not 403.
|
| Throttled because {order} is a guessable integer read straight into a lookup
| — the same shape as the receipt and rating endpoints throttled elsewhere in
| this file, and the response is a person's identity document.
*/
Route::get('/discount-id/{order}', [\App\Http\Controllers\DiscountIdController::class, 'show'])
    ->whereNumber('order')
    ->middleware('throttle:customer-discount-lookup')
    ->name('discount-id.show');


/*
|--------------------------------------------------------------------------
| CUSTOMER ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('customer')->name('customer.')->group(function () {

    // ══════════ AUTH ══════════

    Route::get('/login', [AuthController::class, 'showLogin'])
        ->name('login');

    // Unlimited password guessing was possible here; 10/min per IP still
    // leaves plenty of room for a real customer mistyping a password.
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:customer-login')
        ->name('login.post');

    Route::get('/register', [AuthController::class, 'showRegister'])
        ->name('register');

    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:customer-register')
        ->name('register.post');

    Route::get('/terms', [AuthController::class, 'showTerms'])
        ->name('terms');


    // ══════════ PASSWORD / VERIFICATION ══════════

    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])
        ->name('forgot-password');

    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:customer-forgot-password')
        ->name('forgot-password.post');

    Route::get('/verification', [AuthController::class, 'showVerification'])
        ->name('verification');

    Route::post('/verification', [AuthController::class, 'verifyCode'])
        ->middleware('throttle:customer-verification')
        ->name('verification.post');

    Route::get('/verification/resend', [AuthController::class, 'resendCode'])
        ->middleware('throttle:customer-verification-resend')
        ->name('verification.resend');

    Route::get('/new-password', [AuthController::class, 'showNewPassword'])
        ->name('new-password');

    // Final step of the reset flow — throttled like the rest of that flow.
    Route::post('/new-password', [AuthController::class, 'updatePassword'])
        ->middleware('throttle:customer-new-password')
        ->name('new-password.post');


    // ══════════ CUSTOMER MENU ══════════

    Route::get('/menu', [AuthController::class, 'showMenu'])
        ->name('menu');

    Route::get('/item/{id}', [AuthController::class, 'showItem'])
        ->name('item');

    Route::get('/items/{id}', [AuthController::class, 'showItems'])
        ->name('items');


    // ══════════ QR / DINE-IN ══════════

    Route::get('/dineinqr', [AuthController::class, 'showDineInQr'])
        ->name('dineinqr');

    // The live CSRF token, so the code-entry form on a long-open /dineinqr tab
    // can refresh its own token before the customer submits and never dead-end
    // on the branded 419 page. READ-ONLY — it only reads csrf_token(), the same
    // way tableSessionStatus() is read-only; it touches no session key.
    Route::get('/session-token', [AuthController::class, 'sessionToken'])
        ->name('session-token');

    // Opens a dine-in session, from a camera scan or from a typed code.
    //
    // The table code printed on a standee is PERMANENT now — it never expires
    // and use does not consume it — so anyone who has photographed a table card
    // holds a working credential indefinitely. The limiter is what stops that
    // credential being used in a loop to fill the staff Occupied Tables panel
    // with fake sessions during service. See RateLimitServiceProvider::
    // TABLE_SESSION_PER_SESSION for the numbers and the reasoning.
    //
    // throttle.friendly wraps it so a customer who hits the limit gets a
    // readable sentence on the page they were already on, rather than the raw
    // 429 page — the same treatment place-order and the GCash endpoints get.
    Route::post('/dineinqr', [AuthController::class, 'processQr'])
        ->middleware([
            'throttle.friendly:You are opening table sessions a little too quickly. '
                . 'Please wait a moment and scan again.',
            'throttle:table-session',
        ])
        ->name('qr.process');

    Route::get('/scan', [AuthController::class, 'scanQr'])
        ->name('scan');

    // ── the dine-in guest's fifteen-minute inactivity clock ──
    //
    // Two endpoints, and the split between them is the entire design. One
    // WRITES the clock and is driven by real interaction; the other only READS
    // it and is driven by the page looking at itself. Merging them would make
    // the check that catches an abandoned phone the very thing keeping that
    // phone's session alive.
    //
    // 120/min each, for the same reason the notification pollers carry it: the
    // counter is per IP, and in a café every phone in the room shares the shop's
    // one public address. Neither endpoint can do anything on behalf of a
    // visitor who holds no live dine-in occupancy, so the ceiling is here to
    // catch a runaway client, not to ration a busy room.
    //
    // Nothing here touches admin, staff, kitchen or pick-up: both live in the
    // customer group with no auth middleware, and both no-op unless the caller
    // is holding a live table_session_token.

    Route::post('/table-activity', [AuthController::class, 'tableActivity'])
        ->middleware('throttle:customer-table-clock')
        ->name('table-activity');

    Route::get('/table-session-status', [AuthController::class, 'tableSessionStatus'])
        ->middleware('throttle:customer-table-clock')
        ->name('table-session-status');


    // ══════════ CART ══════════

    Route::get('/cart', [AuthController::class, 'showCart'])
        ->name('cart');

    Route::put('/cart/update/{id}', [AuthController::class, 'updateCart'])
        ->name('cart.update');

    Route::delete('/cart/remove/{id}', [AuthController::class, 'removeFromCart'])
        ->name('cart.remove');

    Route::post('/cart/add', [AuthController::class, 'addToCart'])
        ->name('cart.add');


    // ══════════ ORDERS / PAYMENT ══════════

    // Creates a real order (money, inventory, and the staff dashboard queue).
    // Was unthrottled — a follow-up security pass on the money-endpoint list
    // in docs/SECURITY_TESTING_SUMMARY.md missed this one because it does not
    // read like an auth endpoint, but it has the same abuse shape as any of
    // them: unlimited automated submissions with real-world side effects.
    //
    // The limit itself is unchanged in spirit and stronger in practice; what
    // changed is what it counts. `throttle:10,1` counted per IP, and in a café
    // every customer shares the shop's one public IP, so ten orders a minute
    // was the ceiling for the WHOLE ROOM — the eleventh customer to order in a
    // busy minute was refused for someone else's activity. It now counts per
    // ordering party with a much higher per-IP backstop underneath; see
    // App\Providers\RateLimitServiceProvider for the full reasoning.
    //
    // throttle.friendly wraps it so a customer who does hit the limit gets a
    // "please wait a moment" message on the cart they were already on, with
    // their cart and table still intact, instead of a full-page 429.
    Route::post('/place-order', [OrderController::class, 'placeOrder'])
        ->middleware([
            'throttle.friendly:Your order was not placed — you tried a little too quickly. '
                . 'Your cart is still here, so please wait a moment and tap Place Order again.',
            'throttle:place-order',
        ])
        ->name('place-order');

    Route::get('/orders', [AuthController::class, 'showOrders'])
        ->name('orders');


    // ══════════ GCASH PAYMENT ══════════

    Route::get('/gcash-payment/{id}', [OrderController::class, 'showGcashPayment'])
        ->name('gcash-payment');

    // Pushes an order into the staff GCash-verification queue. Same
    // brute-force protection as the customer login — this is a money
    // endpoint, and it was flagged for an IDOR fix in the same
    // SECURITY_TESTING_SUMMARY.md pass without also getting a rate limit.
    Route::post('/gcash-payment/{id}/paid', [OrderController::class, 'markGcashAsPaid'])
        ->middleware([
            'throttle.friendly:We could not record that payment just now because it was '
                . 'submitted too quickly. Please wait a moment and tap "I have paid" again.',
            'throttle:gcash-paid',
        ])
        ->name('gcash-payment.paid');

    Route::get('/gcash-payment/{id}/status', [OrderController::class, 'gcashPaymentStatus'])
        ->name('gcash-payment.status');

    // Lightweight endpoint used while the customer waits for discount verification.
    Route::get('/order-status', [OrderController::class, 'customerOrderStatus'])
        ->name('order-status');

    // Every order the visitor should be polling for right now, not just one —
    // see OrderController::customerOrdersStatus() for why a single tracked id
    // was not enough once a visitor could hold more than one open order.
    Route::get('/orders-status', [OrderController::class, 'customerOrdersStatus'])
        ->name('orders-status');

    // Customer may cancel a still-pending order after a discount card is rejected.
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancelCustomerOrder'])
        ->name('orders.cancel');

    // Customer may continue a rejected-discount order at the regular price.
    Route::post('/orders/{id}/continue-without-discount', [OrderController::class, 'continueWithoutDiscount'])
        ->name('continue-without-discount');

    // Customer submits a star rating for a completed dine-in/pick-up order.
    // {id} is a guessable integer read straight into a DB lookup with no
    // rate limit — the same class of endpoint the QR-scan/table-code doors
    // are throttled for elsewhere in this file, just added in a later round
    // than the original security pass and never covered.
    Route::post('/orders/{id}/rating', [OrderController::class, 'submitRating'])
        ->middleware(['throttle.friendly', 'throttle:order-rating'])
        ->name('orders.rating');

    // Lets the order-completed popup show an existing rating instead of an
    // empty control when it is reopened. Same ID-enumeration exposure as the
    // POST above; a higher ceiling because this is read-only and may
    // legitimately be polled a few times as the popup opens.
    Route::get('/orders/{id}/rating', [OrderController::class, 'orderRating'])
        ->middleware(['throttle.friendly', 'throttle:order-rating-read'])
        ->name('orders.rating.show');

    Route::get('/receipt/{id}', [OrderController::class, 'showReceipt'])
        ->name('receipt');


    // ══════════ NOTIFICATIONS ══════════
    //
    // Polled by the bell partial, so they are throttled like every other
    // frequently-hit endpoint in this app.
    //
    // 120/min, raised from 60. The bell polls every 6s (10/min) and that alone
    // was never near the old ceiling — but the counter is keyed per IP, and in
    // a café every phone in the room shares the shop's one public address. Ten
    // customers with the app open is 100/min of pure background polling before
    // anybody taps anything, and the eleventh got a 429 for everyone else's
    // idle screens. These are read-only endpoints that return the visitor's
    // own notifications, so the ceiling exists to catch a runaway client, not
    // to ration a busy room.

    Route::get('/notifications', [CustomerNotificationController::class, 'index'])
        ->middleware('throttle:customer-notifications')
        ->name('notifications.index');

    Route::get('/notifications/unread-count', [CustomerNotificationController::class, 'unreadCount'])
        ->middleware('throttle:customer-notifications')
        ->name('notifications.unread-count');

    Route::post('/notifications/read', [CustomerNotificationController::class, 'markAllRead'])
        ->middleware('throttle:customer-notifications')
        ->name('notifications.read');


    // ══════════ ACCOUNT ══════════

    Route::get('/more', [AuthController::class, 'showMore'])
        ->name('more');

    Route::get('/account', [AuthController::class, 'showAccount'])
        ->name('account');

    Route::put('/account', [AuthController::class, 'updateAccount'])
        ->name('account.update');

    Route::delete('/account', [AuthController::class, 'deleteAccount'])
        ->name('account.delete');

    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('logout');


    // ══════════ VOUCHERS / GAME ══════════

    // Voucher codes are guessable strings that convert into money, so the
    // apply endpoint is throttled to stop code enumeration.
    // Enumeration protection is still per IP (the ceiling in the named
    // limiter), but a shared café address no longer means one customer trying
    // three codes uses up everyone else's allowance.
    Route::post('/apply-voucher', [AuthController::class, 'applyVoucher'])
        ->middleware(['throttle.friendly', 'throttle:apply-voucher'])
        ->name('apply-voucher');

    Route::get('/game', [AuthController::class, 'showGame'])
        ->name('game');

    // Points convert into vouchers. The value is allowlisted server-side in
    // AuthController::addPoints(); this caps how fast spins can be replayed.
    Route::post('/add-points', [AuthController::class, 'addPoints'])
        ->middleware('throttle:customer-add-points')
        ->name('add-points');

    Route::get('/vouchers', [AuthController::class, 'showVouchers'])
        ->name('vouchers');


    // ══════════ BRANCH ══════════

    Route::post('/select-branch', [AuthController::class, 'selectBranch'])
        ->name('select-branch');


    // ══════════ HELP REQUEST ══════════

    // Flooding this would spam the staff dashboard queue.
    Route::post('/help-request', [AuthController::class, 'submitHelpRequest'])
        ->middleware(['throttle.friendly', 'throttle:help-request'])
        ->name('help-request');

});


/*
|--------------------------------------------------------------------------
| ADMIN / STAFF AUTHENTICATION
|--------------------------------------------------------------------------
|
| These routes MUST remain outside the admin middleware.
| Otherwise users could not reach the login page.
|
*/

Route::prefix('admin')->name('admin.')->group(function () {

    // ══════════ AUTH ══════════
    //
    // Self-service admin/staff registration is intentionally NOT offered.
    // Staff accounts are created by an authenticated admin via /admin/users.
    //
    // The ONE exception is the first-run bootstrap below, which exists because
    // a brand-new installation has no admin and therefore nobody who can log
    // in to create one. It is open only while ZERO admins exist and closes
    // permanently once one does — see App\Services\AdminBootstrap. The
    // AdminBootstrapSeeder remains available for scripted/headless deploys and
    // is gated by the same rule.

    Route::get('/login', [AdminAuthController::class, 'showLogin'])
        ->name('login');

    // ══════════ FIRST-RUN BOOTSTRAP ══════════
    //
    // Deliberately NOT inside any auth middleware: on a fresh install there is
    // nobody to authenticate as. The gate is AdminBootstrap::isAvailable(),
    // re-checked in the controller on BOTH routes and again inside the
    // creating transaction, so hiding the link is never the control.
    //
    // Rate limited like every other sensitive auth endpoint, keyed on IP
    // because there is no account to key on yet — the same shape as
    // admin-login. See RateLimitServiceProvider.

    Route::get('/bootstrap', [AdminAuthController::class, 'showBootstrap'])
        ->middleware('throttle:admin-bootstrap')
        ->name('bootstrap');

    Route::post('/bootstrap', [AdminAuthController::class, 'store'])
        ->middleware('throttle:admin-bootstrap')
        ->name('bootstrap.store');

    // Brute-force protection for the staff/admin portal. This single route is
    // the login for BOTH staff and admin accounts, so there is no separate
    // staff limiter to change.
    //
    // Security review 2026-08-31: reduced from 10/min to 3/min. Ten guesses a
    // minute against a privileged account is far more than a real person
    // mistyping needs. The keying is deliberately unchanged — Laravel's
    // throttle keys an unauthenticated request on the client IP, so these 3
    // attempts are per address across ALL emails tried. That is stricter than
    // keying on IP+email, which would hand an attacker a fresh allowance for
    // every address they guessed.
    //
    // The customer login stays at 10/min on purpose: every customer in the
    // café shares one public IP, so 3/min there would lock out the whole shop
    // after a single person mistyped their password three times.
    //
    // Pass 7 (2026-09-01): this was `throttle:3,1` and is now the NAMED
    // limiter `admin-login`, still 3 per minute and still keyed on the IP.
    // Nothing about the strictness or the keying changed — what changed is
    // that it no longer shares its counter with every other raw-throttled
    // route on the same address.
    //
    // Under raw `throttle:3,1` the key was sha1(domain|IP) with an EMPTY
    // prefix, so the notification bell — which polls every 20 seconds from the
    // admin layout at throttle:60,1 — spent this budget. A minute on any admin
    // page and the owner could not log back in. See RateLimitServiceProvider.
    Route::post('/login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:admin-login')
        ->name('login.post');


    // ══════════ PASSWORD RESET ══════════
    //
    // Scoped to admin/staff accounts only — see
    // App\Http\Controllers\Concerns\HandlesPasswordReset.
    // These MUST stay outside the "admin" middleware: a locked-out admin is
    // by definition not authenticated.

    Route::get('/forgot-password', [AdminAuthController::class, 'showForgotPassword'])
        ->name('forgot-password');

    Route::post('/forgot-password', [AdminAuthController::class, 'forgotPassword'])
        ->middleware('throttle:6,1')
        ->name('forgot-password.post');

    Route::get('/verification', [AdminAuthController::class, 'showVerification'])
        ->name('verification');

    Route::post('/verification', [AdminAuthController::class, 'verifyCode'])
        ->middleware('throttle:10,1')
        ->name('verification.post');

    Route::get('/verification/resend', [AdminAuthController::class, 'resendCode'])
        ->middleware('throttle:3,1')
        ->name('verification.resend');

    Route::get('/new-password', [AdminAuthController::class, 'showNewPassword'])
        ->name('new-password');

    Route::post('/new-password', [AdminAuthController::class, 'updatePassword'])
        ->middleware('throttle:6,1')
        ->name('new-password.post');

});


/*
|--------------------------------------------------------------------------
| PROTECTED ADMIN / STAFF AREA
|--------------------------------------------------------------------------
|
| Only users authenticated through the "admin" guard
| with role "admin" or "staff" can access these routes.
|
*/

Route::prefix('admin')
    ->name('admin.')
    ->middleware('admin')
    ->group(function () {


        // ══════════ LOGOUT ══════════

        Route::post('/logout', [AdminAuthController::class, 'logout'])
            ->name('logout');


        /*
        |----------------------------------------------------------------------
        | SHARED — admin AND staff
        |----------------------------------------------------------------------
        |
        | Day-to-day shift operations: running the order queue, serving
        | customers, recording stock movement, reprinting receipts.
        |
        */

        // supervisor joins this group and ONLY this group (Sept 2026 role
        // pass). It gets exactly the staff shift surface, branch-locked by
        // AdminOrderAccess, and is deliberately NOT added to the `role:admin`
        // group below — so branch CRUD, the "Viewing:" branch picker, portal
        // account management and system configuration all refuse it
        // server-side, not merely in the sidebar.
        Route::middleware('role:admin,staff,supervisor')->group(function () {

            // ══════════ DASHBOARD ══════════

            Route::get('/home', [AdminController::class, 'showHome'])
                ->name('home');


            // ══════════ MANUAL (WALK-IN) ORDER ══════════

            Route::post('/manual-order', [AdminController::class, 'storeManualOrder'])
                ->name('manual-order.store');

            /*
             * Prices the customer's voucher for the modal before the order is
             * submitted, so the figure staff reads out is the figure the order
             * charges. Read-only — it spends nothing.
             *
             * Throttled on the same limiter as the customer's apply-voucher
             * endpoint. A claim code is random over a large space, but the
             * counter must not become the one unthrottled place to guess at
             * one; sharing the limiter keeps that single rule in one place.
             */
            Route::post('/manual-order/voucher-preview', [AdminController::class, 'previewManualVoucher'])
                ->middleware('throttle:apply-voucher')
                ->name('manual-order.voucher-preview');


            // ══════════ MENU ITEMS — view only ══════════
            //
            // "View Menu Items" is Y for all three roles. Availability
            // (menu-items.toggle) used to sit here as well, so any staff
            // member could take a dish off sale mid-shift. The Sept 2026
            // matrix makes "Enable/Disable Menu Items" Y | Y | N, so the
            // toggle moved to the manager group below. Taking a dish off the
            // customer-facing menu changes what the business sells, which is
            // the line that group draws.

            Route::get('/menu-items', [AdminController::class, 'showMenuItems'])
                ->name('menu-items');


            // ══════════ ARCHIVED CATALOGUE — view only ══════════
            //
            // Seeing what has been archived is read-only information — it does
            // not change anything the business sells — so it follows the same
            // rule as seeing the main Menu Items list above: admin AND staff.
            // The reported bug was that there was no way back to the archive at
            // all except by first archiving something, which is exactly the
            // wrong kind of hidden for a screen someone may need in order to
            // RESTORE something. Restoring itself is destructive and stays
            // admin-only — see admin.archived.restore in the admin-only group
            // below — this route only renders the list.

            Route::get('/archived', [AdminController::class, 'showArchivedCatalogue'])
                ->name('archived');


            // ══════════ COMPLETED ORDERS ══════════

            Route::get('/completed-orders', [AdminController::class, 'showCompletedOrders'])
                ->name('completed-orders');


            // ══════════ SUMMARY — the matrix's one LIMITED report ══════════
            //
            // "View Summary/Reports" is Y | Y | LIMITED, where LIMITED means
            // "their own assigned branch only, never a consolidated view".
            //
            // That is why this route is in the ALL-THREE group rather than the
            // manager group, and why it needs no extra check to be safe:
            // showSummary() scopes every figure through
            // ResolvesBranchScope::getSelectedBranch(), which returns
            // AdminOrderAccess::lockedBranchId() for a branch-locked role and
            // only falls through to the 'all' picker for an admin. A staff
            // member therefore cannot reach the consolidated numbers here —
            // there is no request they can make that widens the scope, because
            // the scope is never read from the request.
            //
            // The CSV at admin.export.orders is deliberately NOT here. It is a
            // bulk sales extract rather than a branch summary screen, which
            // puts it under "View Sales/Financial Data" (Y | Y | N); it sits
            // in the manager group below.
            Route::get('/summary', [AdminController::class, 'showSummary'])
                ->name('summary');


            // ══════════ QR CODE ══════════

            Route::get('/qr-generator', [AdminController::class, 'showQrGenerator'])
                ->name('qr-generator');

            // Supplies the QR URL and labelling for the printable table card.
            Route::get('/qr-generator/table-card', [AdminController::class, 'qrTableCard'])
                ->name('qr-generator.table-card');

            // The single-use, ten-minute staff-issued fallback code that used
            // to live here has been retired: the permanent table code is now
            // shown directly on this page for staff to read aloud, so a second
            // credential system for the same job was no longer earning its
            // keep. See AdminController::showQrGenerator() and
            // App\Services\TableEntry.

            // Replaces ONE table's permanent code — the escape hatch for a code
            // that is being abused. Rotating a code invalidates a printed
            // standee and sends somebody to the table with a new one, which is
            // a management call rather than a counter action.
            //
            // MANAGER tier as of the Sept 2026 matrix, widened from
            // `role:admin`: "QR & Table Codes" is Y | Y | VIEW-ONLY, so a
            // supervisor gets the full capability and staff get the page
            // without this button. A supervisor is still refused another
            // branch's table by regenerateTableCode() itself, which asks
            // AdminOrderAccess::allowsBranch() — the VIEW-ONLY half is the
            // role gate here, the branch half is the check in the controller.
            //
            // Throttled low because a legitimate rotation is one table,
            // occasionally.
            Route::post('/qr-generator/regenerate-code', [AdminController::class, 'regenerateTableCode'])
                ->middleware(['role:admin,supervisor', 'throttle:20,1'])
                ->name('qr-generator.regenerate-code');


            // ══════════ TABLE OCCUPANCY ══════════
            //
            // Which tables are mid-meal, and freeing one by hand when a
            // customer walks off without finishing.

            // Polled every 5s by the Occupied Tables panel (one open counter
            // screen = 12 requests a minute). The ceiling is well clear of a
            // few screens polling at once and only ever catches a runaway
            // client.
            //
            // 300/min, raised from 120: counter terminals, the manager's
            // laptop and the kitchen display all sit on the shop's one NAT'd
            // IP, so their polls share a single counter. Twelve a minute each
            // meant ten open screens hit the old ceiling with nobody doing
            // anything. Read-only, branch-scoped server-side.
            Route::get('/tables/occupancy', [AdminController::class, 'tableOccupancy'])
                ->middleware('throttle:300,1')
                ->name('tables.occupancy');

            Route::post('/tables/clear', [AdminController::class, 'clearTableOccupancy'])
                ->middleware('throttle:60,1')
                ->name('tables.clear');


            // ══════════ INVENTORY — VIEW ONLY ══════════
            //
            // "View Inventory" is Y | Y | VIEW-ONLY, and this GET is the whole
            // of what VIEW-ONLY means: staff still see the stock list for
            // their branch (they need to know what has run out) but move
            // nothing.
            //
            // stock-in and stock-out used to sit here, giving staff the
            // ability to change recorded quantities directly. "Update Stock"
            // and "Stock Adjustments" are both Y | Y | N in the matrix, so
            // they moved to the manager group below. This is a real reduction
            // in what a staff account can do, and it is the intended one:
            // a stock movement is an inventory correction, not a shift action,
            // and stock_movements rows are what the cost figures are built on.

            Route::get('/inventory', [AdminController::class, 'showInventory'])
                ->name('inventory');


            // ══════════ ORDERS MANAGEMENT ══════════

            Route::put('/orders/{id}/prepare', [AdminController::class, 'prepareOrder'])
                ->name('orders.prepare');

            Route::put('/orders/{id}/serve', [AdminController::class, 'serveOrder'])
                ->name('orders.serve');

            Route::put('/orders/{id}/complete', [AdminController::class, 'completeOrder'])
                ->name('orders.complete');

            Route::put('/orders/{id}/cancel', [AdminController::class, 'cancelOrder'])
                ->name('orders.cancel');


            // ══════════ DISCOUNT APPROVAL ══════════

            Route::put('/orders/{id}/discount/approve', [AdminController::class, 'approveDiscount'])
                ->name('orders.discount.approve');

            Route::put('/orders/{id}/discount/reject', [AdminController::class, 'rejectDiscount'])
                ->name('orders.discount.reject');


            // ══════════ GCASH PAYMENT ══════════

            Route::put('/orders/{id}/payment/approve', [AdminController::class, 'approveGcashPayment'])
                ->name('orders.payment.approve');

            Route::put('/orders/{id}/payment/reject', [AdminController::class, 'rejectGcashPayment'])
                ->name('orders.payment.reject');

            // Staff confirm they have manually sent back the money for an order
            // the customer cancelled after marking it paid. Same admin+staff
            // scope as approve/reject above: it is a counter action during a
            // shift, not an owner-only one.
            Route::put('/orders/{id}/payment/refunded', [AdminController::class, 'markOrderRefunded'])
                ->name('orders.payment.refunded');


            // ══════════ NOTIFICATIONS ══════════
            //
            // Shared by admin AND staff: the counter queue is exactly who
            // needs these. Branch scoping happens server-side in the
            // controller via ResolvesBranchScope.

            //
            // 120/min, raised from 60, for the same shared-IP reason as the
            // customer bell above: every counter screen in the shop polls
            // these from one address, and the dashboard's own auto-refresh
            // re-renders the bell on each reload on top of that.

            Route::get('/notifications', [AdminNotificationController::class, 'index'])
                ->middleware('throttle:120,1')
                ->name('notifications.index');

            Route::get('/notifications/unread-count', [AdminNotificationController::class, 'unreadCount'])
                ->middleware('throttle:120,1')
                ->name('notifications.unread-count');

            Route::post('/notifications/read', [AdminNotificationController::class, 'markAllRead'])
                ->middleware('throttle:120,1')
                ->name('notifications.read');

            // The X on a single tray card — soft dismiss, scoped to the caller's
            // own branch. Same 120/min ceiling as the rest of the bell traffic.
            Route::post('/notifications/{notification}/dismiss', [AdminNotificationController::class, 'dismiss'])
                ->whereNumber('notification')
                ->middleware('throttle:120,1')
                ->name('notifications.dismiss');


            // ══════════ RECEIPT ══════════

            Route::get('/receipt/{id}', [AdminController::class, 'showReceipt'])
                ->name('receipt');


            // ══════════ HELP REQUESTS ══════════

            Route::put('/help-requests/{id}/assist', [AdminController::class, 'assistHelpRequest'])
                ->name('help-requests.assist');

            Route::put('/help-requests/{id}/resolve', [AdminController::class, 'resolveHelpRequest'])
                ->name('help-requests.resolve');


            // ══════════ VOUCHERS — read-only list for staff ══════════
            //
            // Staff need to read active voucher codes off this page to share
            // them with customers at the counter. The view (admin.vouchers)
            // hides the Spin Wheel toggle, the Create form, and the Edit/Delete
            // row actions for non-admins — the only per-row action staff get is
            // the green "Issue Code" button, whose route sits directly below.
            // Every voucher-defining route stays in the role:admin group.
            Route::get('/vouchers', [AdminController::class, 'showVouchers'])
                ->name('vouchers');

            // Issue one bearer claim code for a walk-in customer who has no
            // account and never played the wheel. Shared with staff as of
            // 2026-09-04: reading an active code off the list to a customer and
            // minting a single-use one for them are the same counter task, so
            // the green "Issue Code" button on admin.vouchers is now the one
            // voucher action staff can take. Everything that creates or edits a
            // voucher itself stays in the role:admin group below.
            //
            // Throttled: this mints rows and draws against a voucher's
            // max_uses, so a stuck finger on the button should not empty a
            // promotion's supply. 30/min is far more than a counter needs.
            Route::post('/vouchers/{id}/issue-code', [AdminController::class, 'issueVoucherCode'])
                ->middleware('throttle:admin-issue-voucher-code')
                ->name('vouchers.issue-code');

        });


        /*
        |----------------------------------------------------------------------
        | MANAGER TIER — admin AND supervisor
        |----------------------------------------------------------------------
        |
        | Every row the Sept 2026 permission matrix marks Y for the owner and Y
        | for a manager/supervisor, but N for staff.
        |
        | The theme is running the shop's catalogue, promotions, stock records
        | and people: things a branch manager decides, that a counter shift
        | does not. What is deliberately NOT here is anything DESTRUCTIVE at
        | the business level (deleting a voucher, deleting an inventory item)
        | and anything defining the shape of the business itself (branches,
        | roles, system configuration) — those stay in the owner-only group
        | below.
        |
        | `role:admin,supervisor` is spelled from User::MANAGER_ROLES. Two
        | matrix rows are LIMITED for a supervisor rather than plain Y —
        | deleting a staff account and deleting a menu item — and route
        | middleware cannot express "own branch only", so those two carry an
        | additional per-row check inside the controller. The route gate says
        | WHO may call; the controller check says WHICH RECORD they may call it
        | on. Neither is sufficient alone and both are always applied.
        |
        */

        Route::middleware('role:admin,supervisor')->group(function () {

            // ══════════ REPORTS — analytics + the sales extract ══════════
            //
            // "View Analytics" and "View Sales/Financial Data" are both
            // Y | Y | N, so unlike admin.summary (which is LIMITED for staff
            // and lives in the all-three group above) these are closed to
            // staff outright.
            //
            // Both are still branch-scoped for a supervisor by
            // getSelectedBranch(): a supervisor sees their branch's analytics,
            // never the consolidated 'all' view, because they have no picker
            // and lockedBranchId() answers before the session is consulted.

            Route::get('/analytics', [AdminController::class, 'showAnalytics'])
                ->name('analytics');

            Route::get('/export/orders', [AdminController::class, 'exportOrders'])
                ->name('export.orders');


            // ══════════ STAFF MANAGEMENT ══════════
            //
            // View / Create / Edit / Reset Password / Activate / Deactivate
            // are all Y | Y | N. Delete is Y | LIMITED | N.
            //
            // The role gate here admits a supervisor to the SCREEN. Which
            // ACCOUNTS they may then act on is decided per row by
            // User::canManageAccount(), which for a supervisor means a
            // `staff` account in their own branch and nothing else — not a
            // peer supervisor, not the owner, not another branch's staff even
            // when that account's id is typed straight into the URL. Every one
            // of the five endpoints below asks that same method, so they
            // cannot drift apart from one another.

            Route::get('/users', [AdminController::class, 'showUsers'])
                ->name('users');

            Route::post('/users', [AdminController::class, 'storeUser'])
                ->name('users.store');

            // Edit an account's details — name, email, contact, branch, and
            // (owner only) its role. "Change Staff Role" is Y | N | N, so the
            // role field is ignored for a supervisor rather than refused: see
            // updateUser(). Without that, "Create Staff Accounts = Y" plus a
            // writable role field would have let a supervisor mint or promote
            // a peer, which is the escalation ADMIN_MANAGEABLE_ROLES exists to
            // prevent one tier up.
            Route::put('/users/{id}', [AdminController::class, 'updateUser'])
                ->whereNumber('id')
                ->name('users.update');

            Route::put('/users/{id}/toggle', [AdminController::class, 'toggleUser'])
                ->name('users.toggle');

            // Set a NEW password for a staff member. Not "view" — a stored
            // password is a bcrypt hash and cannot be read back by anyone,
            // including the owner. Control over an account is achieved by
            // replacing the password, never by revealing it.
            //
            // Guarded twice over: this group admits only manager roles, and
            // updateStaffPassword() re-checks the TARGET through
            // canManageAccount() so the endpoint can never be pointed at an
            // admin, at a peer supervisor, or across a branch boundary.
            //
            // Named limiter `admin-staff-password`, 6/min per IP. As raw
            // throttle:6,1 this shared one counter with admin-login (limit 3),
            // so a single legitimate use here helped lock the owner out of
            // their own login form.
            Route::put('/users/{id}/password', [AdminController::class, 'updateStaffPassword'])
                ->middleware('throttle:admin-staff-password')
                ->name('users.password.update');

            // Delete a portal account outright — the matrix's LIMITED row.
            // Throttled: it is irreversible and there is no bulk use for it.
            Route::delete('/users/{id}', [AdminController::class, 'destroyUser'])
                ->whereNumber('id')
                ->middleware('throttle:20,1')
                ->name('users.destroy');


            // ══════════ MENU ITEMS — create / edit / availability ══════════
            //
            // Add, Edit and Enable/Disable are Y | Y | N. Delete is
            // Y | LIMITED | N and carries its own branch check inside
            // deleteMenuItem().
            //
            // Add and Edit both happen inside the modal on the Menu Items list
            // (menu-items.blade.php) — there is no standalone page for either
            // any more, so only the actions that WRITE are routed.

            Route::put('/menu-items/toggle/{id}', [AdminController::class, 'toggleMenuItem'])
                ->name('menu-items.toggle');

            Route::put('/menu-items/{id}', [AdminController::class, 'updateMenuItem'])
                ->name('menu-items.update');

            Route::delete('/menu-items/{id}', [AdminController::class, 'deleteMenuItem'])
                ->name('menu-items.delete');

            Route::post('/new-menu-item', [AdminController::class, 'storeNewMenuItem'])
                ->name('new-menu-item.post');


            // ══════════ MENU ITEM INGREDIENTS ══════════
            //
            // A recipe is part of the menu item it belongs to, so it follows
            // "Edit Menu Items" (Y | Y | N) rather than the inventory rows.

            Route::post('/menu-items/{menuItem}/ingredients', [AdminController::class, 'addIngredient'])
                ->name('menu-items.ingredients.add');

            Route::delete('/menu-items/{menuItem}/ingredients/{ingredient}', [AdminController::class, 'deleteIngredient'])
                ->name('menu-items.ingredients.delete');


            // ══════════ CATEGORIES ══════════
            //
            // "Manage Categories" is Y | Y | N — the whole CRUD, delete
            // included. The matrix gives categories no LIMITED entry, unlike
            // menu items, so no per-row branch rule is added here: doing so
            // would be inventing a narrowing that was not specified.

            Route::get('/add-category', [AdminController::class, 'showAddCategory'])
                ->name('add-category');

            Route::post('/add-category', [AdminController::class, 'storeCategory'])
                ->name('add-category.post');

            Route::get('/add-category/edit/{id}', [AdminController::class, 'editCategory'])
                ->name('add-category.edit');

            Route::put('/add-category/{id}', [AdminController::class, 'updateCategory'])
                ->name('add-category.update');

            Route::delete('/add-category/{id}', [AdminController::class, 'deleteCategory'])
                ->name('add-category.delete');


            // ══════════ SUB CATEGORIES ══════════

            Route::get('/add-subcategory', function () {
                return redirect()->route('admin.add-category');
            })->name('add-subcategory');

            Route::post('/add-subcategory', [AdminController::class, 'storeSubcategory'])
                ->name('add-subcategory.post');

            // Name and parent category only — see updateSubcategory() for why
            // moving to a new parent also updates the category_id of every
            // menu item already filed under this subcategory.
            Route::put('/add-subcategory/{id}', [AdminController::class, 'updateSubcategory'])
                ->name('add-subcategory.update');

            Route::delete('/add-subcategory/{id}', [AdminController::class, 'deleteSubcategory'])
                ->name('add-subcategory.delete');


            // ══════════ MENU OPTIONS / ADD-ONS ══════════
            //
            // "Manage Menu Options/Add-ons" is Y | Y | N.

            Route::get('/menu-options', [AdminController::class, 'showMenuOptions'])
                ->name('menu-options');

            Route::post('/menu-options', [AdminController::class, 'storeMenuOption'])
                ->name('menu-options.post');

            // Name/price only — assignments (menu_item_options) are untouched.
            Route::put('/menu-options/{id}', [AdminController::class, 'updateMenuOption'])
                ->name('menu-options.update');

            Route::delete('/menu-options/{id}', [AdminController::class, 'deleteMenuOption'])
                ->name('menu-options.delete');

            Route::post('/menu-options/assign/{menuItemId}', [AdminController::class, 'assignOptions'])
                ->name('menu-options.assign');

            // Recipe ingredients for an add-on option (MenuOptionIngredient).
            // Mirrors the menu-items ingredient routes above.
            Route::post('/menu-options/{menuOption}/ingredients', [AdminController::class, 'addOptionIngredient'])
                ->name('menu-options.ingredients.add');

            Route::delete('/menu-options/{menuOption}/ingredients/{ingredient}', [AdminController::class, 'deleteOptionIngredient'])
                ->name('menu-options.ingredients.delete');


            // ══════════ INVENTORY — definitions + stock movement ══════════
            //
            // "Add Inventory", "Update Stock" and "Stock Adjustments" are all
            // Y | Y | N. "Delete Inventory Records" is Y | N | N and stays in
            // the owner-only group below — deleting a stock item cascades
            // through menu_item_ingredients and silently stops menu items
            // deducting anything, which is why it is the one inventory action
            // a manager does not get.
            //
            // stock-in and stock-out moved here FROM the all-three group: they
            // write stock_movements rows, which the cost and profit figures are
            // built on, so they are an inventory correction rather than a
            // counter action.

            Route::post('/inventory', [AdminController::class, 'storeInventory'])
                ->name('inventory.store');

            Route::get('/inventory/edit/{id}', [AdminController::class, 'editInventory'])
                ->name('inventory.edit');

            Route::put('/inventory/{id}', [AdminController::class, 'updateInventory'])
                ->name('inventory.update');

            Route::post('/inventory/stock-in/{id}', [AdminController::class, 'stockIn'])
                ->name('inventory.stock-in');

            Route::post('/inventory/stock-out/{id}', [AdminController::class, 'stockOut'])
                ->name('inventory.stock-out');


            // ══════════ VOUCHERS — author / edit / activate ══════════
            //
            // Create, Edit and Activate/Deactivate are Y | LIMITED | N. DELETE
            // is Y | N | N and stays in the owner-only group below.
            //
            // The LIMITED changed here (Sept 2026). This group used to say the
            // vouchers table had no branch_id and that a voucher was therefore
            // global by construction, so no own-branch narrowing was possible
            // for a supervisor. That was true of the SCHEMA, not of the intent:
            // a branch manager authoring a company-wide promotion was never
            // wanted. vouchers.branch_id now exists (nullable, NULL = global),
            // so the narrowing is real:
            //
            //   owner      -> any voucher, and may author a global one or scope
            //                 one to a branch, their choice.
            //   supervisor -> their OWN branch's vouchers only. Their new
            //                 vouchers are auto-scoped to their branch, and a
            //                 global voucher or another branch's is refused.
            //
            // As with menu items, route middleware cannot express "own branch
            // only", so each of the three carries a per-record check inside the
            // controller — AdminController::promotionScopeRefusal(), which is
            // deleteMenuItem()'s pattern factored into one definition. The route
            // gate says WHO may call; that check says WHICH RECORD.
            //
            // Deleting one stays owner-only regardless: it cascades to every
            // customer's unused claims, which is not a per-branch act.

            Route::post('/vouchers', [AdminController::class, 'storeVoucher'])
                ->name('vouchers.store');

            Route::put('/vouchers/{id}', [AdminController::class, 'updateVoucher'])
                ->name('vouchers.update');

            Route::put('/vouchers/{id}/toggle', [AdminController::class, 'toggleVoucher'])
                ->name('vouchers.toggle');


            // ══════════ ADS ══════════
            //
            // "Manage Advertisements" is Y | LIMITED | N — the whole CRUD,
            // delete included, bounded for a supervisor to their own branch.
            //
            // ads.branch_id was added alongside vouchers.branch_id and carries
            // the same NULL-means-global convention, so all four writes below
            // go through the same promotionScopeRefusal() the voucher routes
            // do. Unlike vouchers, DELETE is not pulled out to the owner: it is
            // part of the one "Manage Advertisements" row, and an ad has no
            // claim history to destroy.

            Route::get('/ads', [AdminController::class, 'showAds'])
                ->name('ads');

            Route::post('/ads', [AdminController::class, 'storeAd'])
                ->name('ads.store');

            Route::put('/ads/{id}', [AdminController::class, 'updateAd'])
                ->name('ads.update');

            Route::put('/ads/{id}/toggle', [AdminController::class, 'toggleAd'])
                ->name('ads.toggle');

            Route::delete('/ads/{id}', [AdminController::class, 'deleteAd'])
                ->name('ads.delete');

        });


        /*
        |----------------------------------------------------------------------
        | OWNER ONLY (role:admin)
        |----------------------------------------------------------------------
        |
        | What is left after the manager tier above: the owner's own account,
        | the four DESTRUCTIVE actions a manager does not get (delete a
        | voucher, delete an inventory record, restore an archived catalogue
        | row), the shape of the business (branches, the "Viewing:" picker),
        | and system configuration.
        |
        | Three routes here are deliberately NOT widened to the manager tier
        | even though a sibling of theirs was, because the matrix does not list
        | them and the rule for anything unlisted is deny:
        |
        |   - admin.archived.restore — putting a withdrawn dish back on sale.
        |   - admin.vouchers.issue-reward — minting a points-priced reward
        |     code (its walk-in sibling, vouchers.issue-code, is a counter task
        |     and is shared with staff).
        |   - admin.account.gcash-qr.update — the payment QR the whole shop
        |     collects money through.
        |
        */

        Route::middleware('role:admin')->group(function () {

            // ══════════ ACCOUNT SETTINGS — admin only ══════════
            //
            // The WHOLE account page is admin-only as of 2026-09-01, including
            // "change my password". It used to sit in the admin+staff group
            // above so a staff member could change their own password.
            //
            // The owner's decision: staff have no self-service password path at
            // all. Only the admin sets a staff member's password, through
            // /admin/users (see users.password.update below). The matching half
            // of that decision is in AdminAuthController::resettableRoles(),
            // which no longer issues a reset code for a staff email — otherwise
            // forgot-password would simply be the self-service route by another
            // name.
            //
            // Enforced here by `role:admin` rather than by a check inside the
            // controller, so a staff member who types the URL or crafts a PUT
            // gets exactly the same refusal as every other admin-only page:
            // RoleMiddleware redirects to admin.home with
            // "You don't have permission to access that."
            //
            // The admin's own password features are deliberately untouched. An
            // admin who loses their password with no reset path locks the whole
            // system out.

            Route::get('/account', [AdminController::class, 'showAccount'])
                ->name('account');

            // Auth endpoints stay keyed per IP, deliberately — see the note at
            // the top of RateLimitServiceProvider. Six attempts a minute is far
            // more than a person needs and far fewer than a script wants.
            // Pass 7: named limiter `admin-account-password`. Still 6/min per
            // IP; it simply no longer shares a counter with admin-login, the
            // staff-password reset, or the notification poll.
            Route::put('/account/password', [AdminController::class, 'updateOwnPassword'])
                ->middleware('throttle:admin-account-password')
                ->name('account.password.update');

            Route::post('/account/gcash-qr', [SettingsController::class, 'updateGcashQr'])
                ->name('account.gcash-qr.update');


            // ══════════ ARCHIVED CATALOGUE — restore only ══════════
            //
            // RESTORING changes what the business sells, so it stays with the
            // rest of the destructive catalogue actions in the admin-only
            // group, exactly as the previous round decided. Viewing the
            // archive moved to the role:admin,staff group above (see "ARCHIVED
            // CATALOGUE — view only" near MENU ITEMS) so that seeing it follows
            // the same rule as seeing the main Menu Items list; this route is
            // only the destructive half.

            Route::put('/archived/{type}/{id}/restore', [AdminController::class, 'restoreArchivedCatalogue'])
                ->name('archived.restore');

            Route::delete('/inventory/{id}', [AdminController::class, 'deleteInventory'])
                ->name('inventory.delete');


            // ══════════ VOUCHERS — the owner-only half ══════════
            //
            // The read-only GET /vouchers list is shared with staff, and
            // authoring (create / edit / activate) moved to the manager group
            // above. DELETE is the one voucher action the matrix keeps at
            // Y | N | N and it stays here — UNCHANGED by the branch-scope
            // pass, which deliberately left this row alone. A voucher now has a
            // branch_id, so "it reaches every branch" is no longer the reason;
            // the reason that remains is the one that always mattered more.
            // user_vouchers.voucher_id is ON DELETE CASCADE, so destroying a
            // voucher destroys every customer's unused claim on it, wheel
            // prizes included. That is irreversible and it is not a per-branch
            // act, so no promotionScopeRefusal() is added here: a supervisor is
            // refused outright by this group, exactly as before.

            Route::delete('/vouchers/{id}', [AdminController::class, 'deleteVoucher'])
                ->name('vouchers.delete');

            // The bearer-code issuance route (vouchers.issue-code) moved to the
            // role:admin,staff group above — issuing a walk-in code is a
            // counter task. Its points-reward sibling below stays admin-only:
            // separate endpoint rather than a flag on that one, so the
            // free-form walk-in flow
            // keeps working with no customer and no points ceiling while this
            // one can require both. Shares the same throttle bucket: both mint
            // claim rows against a voucher's max_uses, which is the thing the
            // limit protects.
            Route::post('/vouchers/{id}/issue-reward', [AdminController::class, 'issueRewardCode'])
                ->middleware('throttle:admin-issue-voucher-code')
                ->name('vouchers.issue-reward');


            // ══════════ CUSTOMIZATION ══════════

            Route::post('/customization', [AdminController::class, 'updateCustomization'])
                ->name('customization.update');

            Route::post('/customization/customer', [AdminController::class, 'updateCustomerCustomization'])
                ->name('customization.customer.update');

            Route::post('/game/toggle', [AdminController::class, 'toggleGame'])
                ->name('game.toggle');


            // ══════════ BRANCHES ══════════

            Route::get('/branches', [AdminController::class, 'showBranches'])
                ->name('branches');

            Route::post('/branches', [AdminController::class, 'storeBranch'])
                ->name('branches.store');

            Route::put('/branches/{id}', [AdminController::class, 'updateBranch'])
                ->name('branches.update');

            Route::put('/branches/{id}/toggle', [AdminController::class, 'toggleBranch'])
                ->name('branches.toggle');

            // The branch bar's "Viewing:" picker. A GET on purpose: it only
            // rewrites one key in the admin's OWN session (selected_branch_id)
            // and writes no record, so there is nothing for CSRF to protect —
            // and as a POST it dead-ended the admin on the raw 419 page when a
            // completed-orders tab had sat open past the session lifetime. The
            // {branch} segment is 'all' or a branch id, validated server-side.
            Route::get('/branches/select/{branch}', [AdminController::class, 'selectBranch'])
                ->name('branches.select');

        });

    });

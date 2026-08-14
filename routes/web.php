<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Customer\AuthController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Customer\OrderController;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('customer')->name('customer.')->group(function () {


    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.post');

    Route::get('/terms', [AuthController::class, 'showTerms'])->name('terms');
    Route::get('/menu', [AuthController::class, 'showMenu'])->name('menu');

    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('forgot-password');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password.post');

    Route::get('/verification', [AuthController::class, 'showVerification'])->name('verification');
    Route::post('/verification', [AuthController::class, 'verifyCode'])->name('verification.post');
    Route::get('/verification/resend', [AuthController::class, 'resendCode'])->name('verification.resend');

    Route::get('/new-password', [AuthController::class, 'showNewPassword'])->name('new-password');
    Route::post('/new-password', [AuthController::class, 'updatePassword'])->name('new-password.post');

    Route::get('/dineinqr', [AuthController::class, 'showDineInQr'])->name('dineinqr');
    Route::post('/dineinqr', [AuthController::class, 'processQr'])->name('qr.process');

    Route::get('/cart', [AuthController::class, 'showCart'])->name('cart');
    Route::put('/cart/update/{id}', [AuthController::class, 'updateCart'])->name('cart.update');
    Route::delete('/cart/remove/{id}', [AuthController::class, 'removeFromCart'])->name('cart.remove');
    Route::post('/place-order', [OrderController::class, 'placeOrder'])->name('place-order');
    Route::get('/payment', [AuthController::class, 'showPayment'])->name('payment');
    // 'processPayment' controller method does not exist and the dedicated
    // payment page is not part of the current cart-based flow; redirect
    // safely to the cart instead of throwing a 500.
    Route::post('/payment', function () {
        return redirect()->route('customer.cart');
    })->name('payment.post');

    Route::get('/orders', [AuthController::class, 'showOrders'])->name('orders');

    Route::get('/more', [AuthController::class, 'showMore'])->name('more');

    Route::get('/account', [AuthController::class, 'showAccount'])->name('account');
    Route::put('/account', [AuthController::class, 'updateAccount'])->name('account.update');
    Route::delete('/account', [AuthController::class, 'deleteAccount'])->name('account.delete');

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/item/{id}', [AuthController::class, 'showItem'])->name('item');
    Route::post('/cart/add', [AuthController::class, 'addToCart'])->name('cart.add');
    Route::get('/items/{id}', [AuthController::class, 'showItems'])->name('items');

    // 'scanQr' controller method does not exist; redirect this legacy route
    // to the dine-in QR page so the named route stays safe to reference.
    Route::get('/scan', function () {
        return redirect()->route('customer.dineinqr');
    })->name('scan');
    Route::post('/apply-voucher', [AuthController::class, 'applyVoucher'])->name('apply-voucher');
    Route::get('/game', [AuthController::class, 'showGame'])->name('game');
    Route::post('/select-branch', [AuthController::class, 'selectBranch'])->name('select-branch');


    Route::post('/add-points', [AuthController::class, 'addPoints'])->name('add-points');
    Route::get('/receipt/{id}', [OrderController::class, 'showReceipt'])->name('receipt');
    Route::get('/vouchers', [AuthController::class, 'showVouchers'])->name('vouchers');

    Route::post('/help-request', [AuthController::class, 'submitHelpRequest'])->name('help-request');
});

Route::prefix('admin')->name('admin.')->group(function () {

    // ── Public auth routes (no role required) ───────────────────────────────
    // Public admin/staff registration was removed — staff accounts are now
    // created from the admin User Management page. See AdminBootstrapSeeder
    // for first-time admin setup.
    Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'login'])->name('login.post');

    // ── Authenticated area (admin or staff). Anything below requires login. ─
    Route::middleware(['auth', 'role:admin,staff'])->group(function () {

        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

        // Pages allowed for BOTH admin and staff
        Route::get('/home', [AdminController::class, 'showHome'])->name('home');
        Route::get('/account', [AdminController::class, 'showAccount'])->name('account');
        Route::put('/account', [AdminController::class, 'updateAccount'])->name('account.update');
        Route::get('/completed-orders', [AdminController::class, 'showCompletedOrders'])->name('completed-orders');
        Route::get('/order-detail/{id}', [AdminController::class, 'showOrderDetail'])->name('order-detail');
        Route::get('/receipt/{id}', [AdminController::class, 'showReceipt'])->name('receipt');

        // Read-only inventory & menu-items list (staff needs to see availability)
        Route::get('/inventory', [AdminController::class, 'showInventory'])->name('inventory');
        Route::get('/menu-items', [AdminController::class, 'showMenuItems'])->name('menu-items');

        // Order workflow (staff is locked to their branch via guardOrderBranch)
        Route::put('/orders/{id}/prepare', [AdminController::class, 'prepareOrder'])->name('orders.prepare');
        Route::put('/orders/{id}/serve', [AdminController::class, 'serveOrder'])->name('orders.serve');
        Route::put('/orders/{id}/complete', [AdminController::class, 'completeOrder'])->name('orders.complete');
        Route::put('/orders/{id}/cancel', [AdminController::class, 'cancelOrder'])->name('orders.cancel');
        Route::put('/orders/{id}/apply-discount', [AdminController::class, 'applySeniorPwdDiscount'])->name('orders.apply-discount');
        Route::put('/orders/{id}/remove-discount', [AdminController::class, 'removeSeniorPwdDiscount'])->name('orders.remove-discount');

        // Help requests (staff serves customers)
        Route::put('/help-requests/{id}/assist', [AdminController::class, 'assistHelpRequest'])->name('help-requests.assist');
        Route::put('/help-requests/{id}/resolve', [AdminController::class, 'resolveHelpRequest'])->name('help-requests.resolve');

        // ── Admin-only routes (config, finance, system-wide settings) ────
        Route::middleware('role:admin')->group(function () {

            // Reports
            Route::get('/summary', [AdminController::class, 'showSummary'])->name('summary');
            Route::get('/analytics', [AdminController::class, 'showAnalytics'])->name('analytics');
            Route::get('/export/orders', [AdminController::class, 'exportOrders'])->name('export.orders');

            // Menu Items — mutations
            Route::get('/menu-items/edit/{id}', [AdminController::class, 'editMenuItem'])->name('menu-items.edit');
            Route::put('/menu-items/{id}', [AdminController::class, 'updateMenuItem'])->name('menu-items.update');
            Route::put('/menu-items/toggle/{id}', [AdminController::class, 'toggleMenuItem'])->name('menu-items.toggle');
            Route::delete('/menu-items/{id}', [AdminController::class, 'deleteMenuItem'])->name('menu-items.delete');
            Route::get('/new-menu-item', [AdminController::class, 'showNewMenuItem'])->name('new-menu-item');
            Route::post('/new-menu-item', [AdminController::class, 'storeNewMenuItem'])->name('new-menu-item.post');
            Route::post('/menu-items/{id}/ingredients', [AdminController::class, 'addMenuItemIngredient'])->name('menu-items.ingredients.add');
            Route::delete('/menu-items/{id}/ingredients/{ingredientId}', [AdminController::class, 'deleteMenuItemIngredient'])->name('menu-items.ingredients.delete');

            // Categories
            Route::get('/add-category', [AdminController::class, 'showAddCategory'])->name('add-category');
            Route::post('/add-category', [AdminController::class, 'storeCategory'])->name('add-category.post');
            Route::get('/add-category/edit/{id}', [AdminController::class, 'editCategory'])->name('add-category.edit');
            Route::put('/add-category/{id}', [AdminController::class, 'updateCategory'])->name('add-category.update');
            Route::delete('/add-category/{id}', [AdminController::class, 'deleteCategory'])->name('add-category.delete');

            // Sub Categories
            Route::get('/add-subcategory', function () {
                return redirect()->route('admin.add-category');
            })->name('add-subcategory');
            Route::post('/add-subcategory', [AdminController::class, 'storeSubcategory'])->name('add-subcategory.post');
            Route::delete('/add-subcategory/{id}', [AdminController::class, 'deleteSubcategory'])->name('add-subcategory.delete');

            // Menu Options
            Route::get('/menu-options', [AdminController::class, 'showMenuOptions'])->name('menu-options');
            Route::post('/menu-options', [AdminController::class, 'storeMenuOption'])->name('menu-options.post');
            Route::delete('/menu-options/{id}', [AdminController::class, 'deleteMenuOption'])->name('menu-options.delete');
            Route::post('/menu-options/assign/{menuItemId}', [AdminController::class, 'assignOptions'])->name('menu-options.assign');
            Route::post('/menu-options/{id}/ingredients', [AdminController::class, 'addMenuOptionIngredient'])->name('menu-options.ingredients.add');
            Route::delete('/menu-options/{id}/ingredients/{ingredientId}', [AdminController::class, 'deleteMenuOptionIngredient'])->name('menu-options.ingredients.delete');

            // QR Code
            Route::get('/qr-generator', [AdminController::class, 'showQrGenerator'])->name('qr-generator');

            // Inventory — mutations
            Route::post('/inventory', [AdminController::class, 'storeInventory'])->name('inventory.store');
            Route::get('/inventory/edit/{id}', [AdminController::class, 'editInventory'])->name('inventory.edit');
            Route::put('/inventory/{id}', [AdminController::class, 'updateInventory'])->name('inventory.update');
            Route::post('/inventory/stock-in/{id}', [AdminController::class, 'stockIn'])->name('inventory.stock-in');
            Route::post('/inventory/stock-out/{id}', [AdminController::class, 'stockOut'])->name('inventory.stock-out');
            Route::delete('/inventory/{id}', [AdminController::class, 'deleteInventory'])->name('inventory.delete');

            // Vouchers
            Route::get('/vouchers', [AdminController::class, 'showVouchers'])->name('vouchers');
            Route::post('/vouchers', [AdminController::class, 'storeVoucher'])->name('vouchers.store');
            Route::put('/vouchers/{id}', [AdminController::class, 'updateVoucher'])->name('vouchers.update');
            Route::delete('/vouchers/{id}', [AdminController::class, 'deleteVoucher'])->name('vouchers.delete');
            Route::put('/vouchers/{id}/toggle', [AdminController::class, 'toggleVoucher'])->name('vouchers.toggle');

            // Customization & game
            Route::post('/customization', [AdminController::class, 'updateCustomization'])->name('customization.update');
            Route::post('/customization/customer', [AdminController::class, 'updateCustomerCustomization'])->name('customization.customer.update');
            Route::post('/game/toggle', [AdminController::class, 'toggleGame'])->name('game.toggle');

            // Ads
            Route::get('/ads', [AdminController::class, 'showAds'])->name('ads');
            Route::post('/ads', [AdminController::class, 'storeAd'])->name('ads.store');
            Route::put('/ads/{id}/toggle', [AdminController::class, 'toggleAd'])->name('ads.toggle');
            Route::delete('/ads/{id}', [AdminController::class, 'deleteAd'])->name('ads.delete');

            // Staff Account Management (admin creates staff; role hardcoded server-side)
            Route::get('/users', [AdminController::class, 'showUsers'])->name('users');
            Route::post('/users', [AdminController::class, 'storeStaff'])->name('users.store');
            Route::put('/users/{id}/toggle', [AdminController::class, 'toggleStaffActive'])->name('users.toggle');

            // Branches
            Route::get('/branches', [AdminController::class, 'showBranches'])->name('branches');
            Route::post('/branches', [AdminController::class, 'storeBranch'])->name('branches.store');
            Route::put('/branches/{id}', [AdminController::class, 'updateBranch'])->name('branches.update');
            Route::put('/branches/{id}/toggle', [AdminController::class, 'toggleBranch'])->name('branches.toggle');
            Route::delete('/branches/{id}', [AdminController::class, 'deleteBranch'])->name('branches.delete');
            Route::post('/branches/select', [AdminController::class, 'selectBranch'])->name('branches.select');
        });
    });
});

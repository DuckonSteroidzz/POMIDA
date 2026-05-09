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
    Route::post('/payment', [AuthController::class, 'processPayment'])->name('payment.post');

    Route::get('/orders', [AuthController::class, 'showOrders'])->name('orders');

    Route::get('/more', [AuthController::class, 'showMore'])->name('more');

    Route::get('/account', [AuthController::class, 'showAccount'])->name('account');
    Route::put('/account', [AuthController::class, 'updateAccount'])->name('account.update');
    Route::delete('/account', [AuthController::class, 'deleteAccount'])->name('account.delete');

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/item/{id}', [AuthController::class, 'showItem'])->name('item');
    Route::post('/cart/add', [AuthController::class, 'addToCart'])->name('cart.add');
    Route::get('/items/{id}', [AuthController::class, 'showItems'])->name('items');

    Route::get('/scan', [AuthController::class, 'scanQr'])->name('scan');
    Route::post('/apply-voucher', [AuthController::class, 'applyVoucher'])->name('apply-voucher');
    Route::get('/game', [AuthController::class, 'showGame'])->name('game');
    Route::post('/select-branch', [AuthController::class, 'selectBranch'])->name('select-branch');


    Route::post('/add-points', [AuthController::class, 'addPoints'])->name('add-points');
    Route::get('/receipt/{id}', [OrderController::class, 'showReceipt'])->name('receipt');
    Route::get('/vouchers', [AuthController::class, 'showVouchers'])->name('vouchers');

    Route::post('/help-request', [AuthController::class, 'submitHelpRequest'])->name('help-request');
});

Route::prefix('admin')->name('admin.')->group(function () {

    // Auth
    Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'login'])->name('login.post');
    Route::get('/register', [AdminAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AdminAuthController::class, 'register'])->name('register.post');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

    // Pages
    Route::get('/home', [AdminController::class, 'showHome'])->name('home');
    Route::get('/account', [AdminController::class, 'showAccount'])->name('account');
    Route::put('/account', [AdminController::class, 'updateAccount'])->name('account.update');
    Route::get('/summary', [AdminController::class, 'showSummary'])->name('summary');

    // Menu Items
    Route::get('/menu-items', [AdminController::class, 'showMenuItems'])->name('menu-items');
    Route::get('/menu-items/edit/{id}', [AdminController::class, 'editMenuItem'])->name('menu-items.edit');
    Route::put('/menu-items/{id}', [AdminController::class, 'updateMenuItem'])->name('menu-items.update');
    Route::put('/menu-items/toggle/{id}', [AdminController::class, 'toggleMenuItem'])->name('menu-items.toggle');
    Route::delete('/menu-items/{id}', [AdminController::class, 'deleteMenuItem'])->name('menu-items.delete');
    Route::get('/new-menu-item', [AdminController::class, 'showNewMenuItem'])->name('new-menu-item');
    Route::post('/new-menu-item', [AdminController::class, 'storeNewMenuItem'])->name('new-menu-item.post');


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

    // Completed Orders
    Route::get('/completed-orders', [AdminController::class, 'showCompletedOrders'])->name('completed-orders');
    Route::get('/order-detail/{id}', [AdminController::class, 'showOrderDetail'])->name('order-detail');

    // QR Code
    Route::get('/qr-generator', [AdminController::class, 'showQrGenerator'])->name('qr-generator');

    // Inventory
    Route::get('/inventory', [AdminController::class, 'showInventory'])->name('inventory');
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

    // Orders Management
    Route::put('/orders/{id}/prepare', [AdminController::class, 'prepareOrder'])->name('orders.prepare');
    Route::put('/orders/{id}/serve', [AdminController::class, 'serveOrder'])->name('orders.serve');
    Route::put('/orders/{id}/complete', [AdminController::class, 'completeOrder'])->name('orders.complete');
    Route::put('/orders/{id}/cancel', [AdminController::class, 'cancelOrder'])->name('orders.cancel');

    // Customization
    Route::post('/customization', [AdminController::class, 'updateCustomization'])->name('customization.update');
    Route::post('/customization/customer', [AdminController::class, 'updateCustomerCustomization'])->name('customization.customer.update');

    Route::put('/vouchers/{id}/toggle', [AdminController::class, 'toggleVoucher'])->name('vouchers.toggle');
    Route::post('/game/toggle', [AdminController::class, 'toggleGame'])->name('game.toggle');

    Route::get('/receipt/{id}', [AdminController::class, 'showReceipt'])->name('receipt');

    // Ads
    Route::get('/ads', [AdminController::class, 'showAds'])->name('ads');
    Route::post('/ads', [AdminController::class, 'storeAd'])->name('ads.store');
    Route::put('/ads/{id}/toggle', [AdminController::class, 'toggleAd'])->name('ads.toggle');
    Route::delete('/ads/{id}', [AdminController::class, 'deleteAd'])->name('ads.delete');

    Route::get('/export/orders', [AdminController::class, 'exportOrders'])->name('export.orders');

    // Help Requests
    Route::put('/help-requests/{id}/assist', [AdminController::class, 'assistHelpRequest'])->name('help-requests.assist');
    Route::put('/help-requests/{id}/resolve', [AdminController::class, 'resolveHelpRequest'])->name('help-requests.resolve');



    // Branches
    Route::get('/branches', [AdminController::class, 'showBranches'])->name('branches');
    Route::post('/branches', [AdminController::class, 'storeBranch'])->name('branches.store');
    Route::put('/branches/{id}', [AdminController::class, 'updateBranch'])->name('branches.update');
    Route::delete('/branches/{id}', [AdminController::class, 'deleteBranch'])->name('branches.delete');
    Route::post('/branches/select', [AdminController::class, 'selectBranch'])->name('branches.select');
});

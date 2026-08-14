<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Branch;
use App\Models\Category;
use App\Models\HelpRequest;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\UserVoucher;
use App\Models\Voucher;
use App\Support\Img;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // ══════════ SHOW PAGES ══════════

    public function showLogin()
    {
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
        $branches = Branch::where('is_active', true)->orderBy('id')->get();

        // ── Dine-in via QR ──
        if ($request->has('table') && $request->has('branch_id')) {
            $qrBranch = Branch::find($request->input('branch_id'));

            if (!$qrBranch || !$qrBranch->is_active) {
                // Do not put a closed branch into session.
                session()->forget(['table_number', 'branch_id', 'order_type']);
                return redirect()->route('customer.dineinqr')
                    ->with('error', 'This branch is currently closed and not accepting orders.');
            }

            session()->put('table_number', $request->input('table'));
            session()->put('branch_id', $request->input('branch_id'));
            session()->put('order_type', 'dine_in');
        }

        // ── Stale session pointing at a now-closed branch ──
        if ($sessionBranchId = session('branch_id')) {
            $stillOpen = Branch::where('id', $sessionBranchId)
                ->where('is_active', true)
                ->exists();
            if (!$stillOpen) {
                session()->forget(['cart', 'table_number', 'branch_id', 'order_type']);
                return redirect()->route('customer.menu')
                    ->with('error', 'This branch is currently closed and not accepting orders.');
            }
        }

        $orderType        = session('order_type');        // dine_in | pick_up | null
        $selectedBranchId = session('branch_id');

        // ── Filter categories by branch ──
        $categories = collect();
        if ($selectedBranchId) {
            $branchItemIds = MenuItem::where('is_available', true)
                ->where(function ($q) use ($selectedBranchId) {
                    $q->where('branch_id', $selectedBranchId)
                        ->orWhereNull('branch_id');
                })
                ->pluck('category_id')
                ->unique();

            $categories = Category::where('is_active', true)
                ->whereIn('id', $branchItemIds)
                ->orderBy('display_order')
                ->orderBy('name')
                ->get();
        }

        return view('customer.menu', compact('categories', 'branches', 'selectedBranchId', 'orderType'));
    }

    public function selectBranch(Request $request)
    {
        $branchId = $request->input('branch_id');

        // Closed branches cannot be selected.
        $branch = Branch::find($branchId);
        if (!$branch || !$branch->is_active) {
            return redirect()->route('customer.menu')
                ->with('error', 'This branch is currently closed and not accepting orders.');
        }

        // Kung nagbago ang branch — clear cart para walang mixed items
        if (session('branch_id') && session('branch_id') != $branchId) {
            session()->forget('cart');
        }

        session()->put('branch_id', $branchId);
        session()->put('order_type', 'pick_up');

        return redirect()->route('customer.menu')
            ->with('success', 'Branch selected!');
    }

    public function showForgotPassword()
    {
        return view('customer.forgot-password');
    }

    public function showVerification()
    {
        return view('customer.verification');
    }

    public function showNewPassword()
    {
        return view('customer.new-password');
    }

    public function showDineInQr()
    {
        return view('customer.dineinqr');
    }

    public function showPayment()
    {
        return view('customer.payment');
    }

    public function showMore()
    {
        if (!Auth::check()) {
            return redirect()->route('customer.menu');
        }
        return view('customer.account-settings');
    }

    public function showAccount()
    {
        if (!Auth::check()) {
            return redirect()->route('customer.menu');
        }
        return view('customer.account-settings');
    }

    public function showItem($id)
    {
        $item = MenuItem::with(['category', 'subcategory', 'options'])->findOrFail($id);
        return view('customer.item-details', compact('item'));
    }

    public function showItems($id)
    {
        $selectedBranchId = session('branch_id');
        $orderType = session('order_type');

        // Pickup customer — kailangan ng branch
        if (Auth::check() && $orderType !== 'dine_in' && !$selectedBranchId) {
            return redirect()->route('customer.menu')
                ->with('error', 'Please select a pick-up branch first.');
        }

        $category = Category::findOrFail($id);
        $subcategories = Subcategory::where('category_id', $id)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $itemsQuery = MenuItem::where('category_id', $id)
            ->where('is_available', true);

        // Filter by branch
        if ($selectedBranchId) {
            $itemsQuery->where(function ($q) use ($selectedBranchId) {
                $q->where('branch_id', $selectedBranchId)
                    ->orWhereNull('branch_id');
            });
        }

        $items = $itemsQuery->orderBy('subcategory_id')->orderBy('name')->get();
        $categories = Category::where('is_active', true)->orderBy('name')->get();
        $branches = Branch::where('is_active', true)->orderBy('id')->get();

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
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'contact_number' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'password' => 'nullable|string|min:6',
            'pwd_card_number' => 'nullable|string|max:100',
            'pwd_name' => 'nullable|string|max:255',
            'pwd_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'senior_card_number' => 'nullable|string|max:100',
            'senior_name' => 'nullable|string|max:255',
            'senior_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->contact_number = $validated['contact_number'] ?? $user->contact_number;
        $user->address = $validated['address'] ?? $user->address;

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->pwd_card_number = $validated['pwd_card_number'] ?? $user->pwd_card_number;
        $user->pwd_name = $validated['pwd_name'] ?? $user->pwd_name;
        if ($request->hasFile('pwd_image')) {
            $file = $request->file('pwd_image');
            $filename = time() . '_pwd_' . preg_replace('/[^A-Za-z0-9\.]/', '_', $file->getClientOriginalName());
            Img::delete($user->pwd_image);
            $user->pwd_image = $file->storeAs('uploads/ids', $filename, 'public');
        }

        $user->senior_card_number = $validated['senior_card_number'] ?? $user->senior_card_number;
        $user->senior_name = $validated['senior_name'] ?? $user->senior_name;
        if ($request->hasFile('senior_image')) {
            $file = $request->file('senior_image');
            $filename = time() . '_senior_' . preg_replace('/[^A-Za-z0-9\.]/', '_', $file->getClientOriginalName());
            Img::delete($user->senior_image);
            $user->senior_image = $file->storeAs('uploads/ids', $filename, 'public');
        }

        $user->save();

        return redirect()->back()->with('success', 'Account updated successfully!');
    }

    public function deleteAccount()
    {
        /** @var User $user */
        $user = Auth::user();
        Auth::logout();
        $user->is_active = false;
        $user->save();

        return redirect()->route('customer.login')->with('success', 'Account deactivated.');
    }

    // ══════════ CART (SESSION-BASED) ══════════

    public function addToCart(Request $request)
    {
        $request->validate([
            'item_id' => 'required|exists:menu_items,id',
        ]);

        $selectedBranchId = session('branch_id');

        // Block if the session branch is closed.
        if ($selectedBranchId) {
            $branchOpen = Branch::where('id', $selectedBranchId)
                ->where('is_active', true)
                ->exists();
            if (!$branchOpen) {
                session()->forget(['cart', 'table_number', 'branch_id', 'order_type']);
                return redirect()->route('customer.menu')
                    ->with('error', 'This branch is currently closed and not accepting orders.');
            }
        }

        // Validate na ang item ay para sa selected branch
        if ($selectedBranchId) {
            $item = MenuItem::where('id', $request->input('item_id'))
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
        $selectedOptions = $request->input('options', []);

        $optionDetails = [];
        $optionsTotal = 0;
        if (!empty($selectedOptions)) {
            $options = MenuOption::whereIn('id', $selectedOptions)->get();
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

        if (isset($cart[$cartKey])) {
            $cart[$cartKey]['quantity'] += 1;
        } else {
            $menuItem = MenuItem::findOrFail($itemId);
            $cart[$cartKey] = [
                'menu_item_id' => $itemId,
                'name' => $menuItem->name,
                'price' => $menuItem->price + $optionsTotal,
                'base_price' => $menuItem->price,
                'quantity' => 1,
                'image' => $menuItem->image,
                'options' => $optionDetails,
            ];
        }

        session()->put('cart', $cart);

        return redirect()->back()->with('success', 'Added to cart!');
    }

    public function showCart()
    {
        $cart = session()->get('cart', []);
        $total = 0;
        foreach ($cart as $item) {
            $total += $item['price'] * $item['quantity'];
        }
        return view('customer.cart', compact('cart', 'total'));
    }

    public function updateCart(Request $request, $itemId)
    {
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
        return app(\App\Http\Controllers\Customer\OrderController::class)->showOrders();
    }

    // ══════════ AUTHENTICATION ══════════

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'contact_number' => 'required|numeric|digits_between:10,13',
            'address' => 'nullable|string',
            'terms' => 'required|accepted',
        ], [
            'email.unique' => 'This email is already registered.',
            'password.min' => 'Password must be at least 6 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'contact_number.numeric' => 'Contact number must contain numbers only.',
            'contact_number.digits_between' => 'Contact number must be 10-13 digits.',
            'terms.required' => 'You must accept the Terms and Conditions.',
            'terms.accepted' => 'You must accept the Terms and Conditions.',
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'contact_number' => $validated['contact_number'],
            'address' => $validated['address'] ?? null,
            'role' => 'customer',
            'is_active' => true,
        ]);

        return redirect()->route('customer.login')
            ->with('success', 'Registration successful! Please login to continue.');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $remember = $request->has('remember');

        if (Auth::attempt($credentials, $remember)) {
            $user = Auth::user();

            if (!$user->is_active) {
                Auth::logout();
                return back()->withErrors([
                    'email' => 'Your account has been deactivated. Please contact support.',
                ]);
            }

            if ($user->role !== 'customer') {
                Auth::logout();
                return back()->withErrors([
                    'email' => 'Please use the admin login page.',
                ]);
            }

            $request->session()->regenerate();

            return redirect()->route('customer.menu')
                ->with('success', 'Welcome back, ' . $user->name . '!');
        }

        return back()->withErrors([
            'email' => 'Invalid email or password.',
        ])->withInput($request->only('email'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('customer.login')
            ->with('success', 'You have been logged out.');
    }

    // ══════════ QR DINE-IN (fallback when QR is not the menu URL) ══════════

    public function processQr(Request $request)
    {
        $request->validate([
            'table_data' => 'required|string',
        ]);

        $raw = trim($request->input('table_data'));

        // Try to parse as URL with table + branch_id query params
        $query = parse_url($raw, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $params);
            if (!empty($params['table']) && !empty($params['branch_id'])) {
                return redirect()->route('customer.menu', [
                    'table'     => $params['table'],
                    'branch_id' => $params['branch_id'],
                ]);
            }
        }

        return redirect()->route('customer.dineinqr')
            ->with('error', 'Invalid QR code. Please scan a Peachy table QR.');
    }

    // ══════════ FORGOT PASSWORD / VERIFICATION (session-based placeholders) ══════════
    // NOTE: Email sending not yet wired — flow stores code in session for now.
    //       Real SMTP integration is a Capstone 2 task.

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $validated['email'])
            ->where('role', 'customer')
            ->first();

        if (!$user) {
            return back()->with('error', 'No customer account found with that email.');
        }

        // Generate 6-digit code; store in session until email service is wired.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        session()->put('reset_email', $user->email);
        session()->put('reset_code', $code);

        return redirect()->route('customer.verification')
            ->with('success', 'Verification code sent. (Dev code: ' . $code . ')');
    }

    public function verifyCode(Request $request)
    {
        $otpParts = $request->input('otp', []);
        $code = is_array($otpParts) ? implode('', $otpParts) : (string) $otpParts;

        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return back()->with('error', 'Please enter the full 6-digit code.');
        }

        $expected = session('reset_code');
        if (!$expected || $code !== $expected) {
            return back()->with('error', 'Invalid or expired verification code.');
        }

        session()->put('reset_verified', true);
        return redirect()->route('customer.new-password');
    }

    public function resendCode()
    {
        $email = session('reset_email');
        if (!$email) {
            return redirect()->route('customer.forgot-password')
                ->with('error', 'Please enter your email first.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        session()->put('reset_code', $code);

        return redirect()->route('customer.verification')
            ->with('success', 'A new code has been generated. (Dev code: ' . $code . ')');
    }

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'password' => 'required|string|min:6|confirmed',
        ]);

        if (!session('reset_verified') || !session('reset_email')) {
            return redirect()->route('customer.forgot-password')
                ->with('error', 'Please verify your email first.');
        }

        $user = User::where('email', session('reset_email'))->first();
        if (!$user) {
            return redirect()->route('customer.forgot-password')
                ->with('error', 'Account not found.');
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        session()->forget(['reset_email', 'reset_code', 'reset_verified']);

        return redirect()->route('customer.login')
            ->with('success', 'Password updated. Please login with your new password.');
    }

    // ══════════ VOUCHER ══════════

    /**
     * AJAX preview for the cart's "Apply voucher" button.
     *
     * The subtotal posted by the browser is IGNORED — we compute it from the
     * server-side session cart so a customer cannot forge a higher subtotal
     * to bypass the voucher's minimum_order requirement.
     *
     * The actual voucher application happens server-side in
     * OrderController::placeOrder, which calls the same resolveVoucher() so
     * the preview and the saved order are always in agreement.
     */
    public function applyVoucher(Request $request)
    {
        $code = (string) $request->input('code', '');

        // Recompute subtotal from the session cart — never trust the request.
        $cart     = session()->get('cart', []);
        $subtotal = 0.0;
        foreach ($cart as $ci) {
            $subtotal += (float) ($ci['price'] ?? 0) * (int) ($ci['quantity'] ?? 0);
        }
        $subtotal = round($subtotal, 2);

        $result = app(\App\Http\Controllers\Customer\OrderController::class)
            ->resolveVoucher($code, $subtotal, Auth::id());

        if ($result['error']) {
            return response()->json(['success' => false, 'message' => $result['error']]);
        }

        if (!$result['voucher']) {
            // Empty code — surface a friendly hint instead of silently succeeding.
            return response()->json(['success' => false, 'message' => 'Please enter a voucher code.']);
        }

        return response()->json([
            'success'     => true,
            'message'     => $result['voucher']->description ?? 'Voucher applied!',
            'discount'    => $result['discount'],
            'final_total' => round(max($subtotal - $result['discount'], 0), 2),
        ]);
    }

    // ══════════ GAME ══════════

    public function showVouchers()
    {
        if (!Auth::check()) {
            return redirect()->route('customer.login')
                ->with('error', 'Please login to view your vouchers.');
        }

        $userVouchers = UserVoucher::with('voucher')
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        return view('customer.vouchers', compact('userVouchers'));
    }

    // ══════════ HELP REQUEST ══════════

    public function submitHelpRequest(Request $request)
    {
        $orderType   = session('order_type');
        $branchId    = session('branch_id');
        $tableNumber = session('table_number');

        if ($orderType !== 'dine_in' || !$branchId || !$tableNumber) {
            return back()->with('error', 'Help request is only available for dine-in QR customers.');
        }

        // Check duplicate
        $existing = HelpRequest::where('branch_id', $branchId)
            ->where('table_number', $tableNumber)
            ->whereIn('status', ['pending', 'assisting'])
            ->first();

        if ($existing) {
            return back()->with('error', 'Help is already requested. Please wait for staff. 🙏');
        }

        // Find active order
        $activeOrder = Order::where('branch_id', $branchId)
            ->where('table_number', $tableNumber)
            ->whereIn('status', ['pending', 'preparing', 'serving'])
            ->latest()
            ->first();

        HelpRequest::create([
            'branch_id'    => $branchId,
            'order_id'     => $activeOrder?->id,
            'table_number' => $tableNumber,
            'status'       => 'pending',
            'requested_at' => now(),
        ]);

        return back()->with('success', 'Help requested! Staff will assist you shortly. 🙏');
    }

    public function showGame()
    {
        $vouchers = Voucher::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now()->endOfDay());
            })
            ->where('used_count', '<', DB::raw('max_uses'))
            ->get();

        $gameAds = Ad::where('placement', 'game')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            })
            ->get();

        return view('customer.game', compact('vouchers', 'gameAds'));
    }

    public function addPoints(Request $request)
    {
        $points = (int) $request->input('points', 0);

        // Guest (dine-in) — pwede ring makakuha ng voucher via session
        if (!Auth::check()) {
            $totalPoints = session('guest_points', 0) + $points;
            session()->put('guest_points', $totalPoints);

            // Check kung may maearn na voucher
            $earnedVoucher = Voucher::where('is_active', true)
                ->where('points_required', '>', 0)
                ->where('points_required', '<=', $totalPoints)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->whereNotIn('id', session('guest_used_vouchers', []))
                ->inRandomOrder()
                ->first();

            $voucherData = null;

            if ($earnedVoucher) {
                // Deduct points
                $totalPoints -= $earnedVoucher->points_required;
                session()->put('guest_points', $totalPoints);

                // Valid from = tomorrow
                $validFrom = now()->addDay()->toDateString();

                // I-save sa session para hindi maulit
                $usedVouchers = session('guest_used_vouchers', []);
                $usedVouchers[] = $earnedVoucher->id;
                session()->put('guest_used_vouchers', $usedVouchers);

                // I-save ang voucher code sa session para magamit sa cart
                $guestVouchers = session('guest_vouchers', []);
                $guestVouchers[] = [
                    'code'       => $earnedVoucher->code,
                    'valid_from' => $validFrom,
                    'expires_at' => $earnedVoucher->expires_at?->format('M d, Y'),
                ];
                session()->put('guest_vouchers', $guestVouchers);

                $discountText = $earnedVoucher->discount_type === 'percent'
                    ? $earnedVoucher->discount_value . '% off'
                    : '₱' . number_format($earnedVoucher->discount_value, 2) . ' off';

                $voucherData = [
                    'code'        => $earnedVoucher->code,
                    'description' => $earnedVoucher->description ?? $discountText,
                    'valid_from'  => $validFrom,
                    'expires_at'  => $earnedVoucher->expires_at?->format('M d, Y'),
                    'message'     => 'Valid starting tomorrow — ' . \Carbon\Carbon::parse($validFrom)->format('M d, Y'),
                ];
            }

            // Next voucher to earn
            $nextVoucher = Voucher::where('is_active', true)
                ->where('points_required', '>', $totalPoints)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->orderBy('points_required', 'asc')
                ->first();

            return response()->json([
                'success'       => true,
                'total_points'  => $totalPoints,
                'voucher'       => $voucherData,
                'next_voucher'  => $nextVoucher ? $nextVoucher->description : null,
                'points_needed' => $nextVoucher ? ($nextVoucher->points_required - $totalPoints) : 0,
                'message'       => $voucherData ? null : 'Keep spinning!',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();
        $user->points += $points;
        $user->save();
        $totalPoints = $user->points;

        // Check kung may 2 na vouchers ang user — hindi na pwede kumita pa
        $existingVoucherCount = UserVoucher::where('user_id', $user->id)
            ->where('is_used', false)
            ->count();

        $voucherData = null;

        if ($existingVoucherCount < 2) {
            // Check kung may maearn na voucher base sa points
            $earnedVoucher = Voucher::where('is_active', true)
                ->where('points_required', '>', 0)
                ->where('points_required', '<=', $totalPoints)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->whereNotIn('id', function ($q) use ($user) {
                    // Hindi na mauulit ang voucher na meron na siya
                    $q->select('voucher_id')
                        ->from('user_vouchers')
                        ->where('user_id', $user->id)
                        ->where('is_used', false);
                })
                ->inRandomOrder()
                ->first();

            if ($earnedVoucher) {
                // Deduct points
                $totalPoints -= $earnedVoucher->points_required;
                $user->points = $totalPoints;
                $user->save();

                // Valid from = tomorrow (hindi pwede gamitin ngayon)
                $validFrom = now()->addDay()->toDateString();

                // I-save sa user_vouchers
                UserVoucher::create([
                    'user_id'       => $user->id,
                    'voucher_id'    => $earnedVoucher->id,
                    'acquired_date' => today()->toDateString(),
                    'is_used'       => false,
                ]);

                // I-update ang valid_from ng voucher
                $earnedVoucher->valid_from = $validFrom;
                $earnedVoucher->save();

                $discountText = $earnedVoucher->discount_type === 'percent'
                    ? $earnedVoucher->discount_value . '% off'
                    : '₱' . number_format($earnedVoucher->discount_value, 2) . ' off';

                $voucherData = [
                    'code'        => $earnedVoucher->code,
                    'description' => ($earnedVoucher->description ?? $discountText),
                    'valid_from'  => $validFrom,
                    'expires_at'  => $earnedVoucher->expires_at
                        ? $earnedVoucher->expires_at->format('M d, Y')
                        : null,
                    'message'     => 'Valid starting tomorrow — ' . \Carbon\Carbon::parse($validFrom)->format('M d, Y'),
                ];
            }
        }

        // Next voucher to earn
        $nextVoucher = Voucher::where('is_active', true)
            ->where('points_required', '>', $totalPoints)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('points_required', 'asc')
            ->first();

        return response()->json([
            'success'       => true,
            'total_points'  => $totalPoints,
            'voucher'       => $voucherData,
            'next_voucher'  => $nextVoucher ? $nextVoucher->description : null,
            'points_needed' => $nextVoucher ? ($nextVoucher->points_required - $totalPoints) : 0,
            'voucher_slots' => 2 - $existingVoucherCount, // Ilang slots pa
        ]);
    }
}

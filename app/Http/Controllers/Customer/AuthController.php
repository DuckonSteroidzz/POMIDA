<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $branches = \App\Models\Branch::where('is_active', true)->orderBy('id')->get();

        // ── Dine-in via QR ──
        if ($request->has('table') && $request->has('branch_id')) {
            session()->put('table_number', $request->input('table'));
            session()->put('branch_id', $request->input('branch_id'));
            session()->put('order_type', 'dine_in');
        }

        $orderType        = session('order_type');        // dine_in | pick_up | null
        $selectedBranchId = session('branch_id');

        // ── Filter categories by branch ──
        $categories = collect();
        if ($selectedBranchId) {
            $branchItemIds = \App\Models\MenuItem::where('is_available', true)
                ->where(function ($q) use ($selectedBranchId) {
                    $q->where('branch_id', $selectedBranchId)
                        ->orWhereNull('branch_id');
                })
                ->pluck('category_id')
                ->unique();

            $categories = \App\Models\Category::where('is_active', true)
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
        $item = \App\Models\MenuItem::with(['category', 'subcategory', 'options'])->findOrFail($id);
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

        $category = \App\Models\Category::findOrFail($id);
        $subcategories = \App\Models\Subcategory::where('category_id', $id)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $itemsQuery = \App\Models\MenuItem::where('category_id', $id)
            ->where('is_available', true);

        // Filter by branch
        if ($selectedBranchId) {
            $itemsQuery->where(function ($q) use ($selectedBranchId) {
                $q->where('branch_id', $selectedBranchId)
                    ->orWhereNull('branch_id');
            });
        }

        $items = $itemsQuery->orderBy('subcategory_id')->orderBy('name')->get();
        $categories = \App\Models\Category::where('is_active', true)->orderBy('name')->get();
        $branches = \App\Models\Branch::where('is_active', true)->orderBy('id')->get();

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
            $file->move(public_path('uploads/ids'), $filename);
            $user->pwd_image = 'uploads/ids/' . $filename;
        }

        $user->senior_card_number = $validated['senior_card_number'] ?? $user->senior_card_number;
        $user->senior_name = $validated['senior_name'] ?? $user->senior_name;
        if ($request->hasFile('senior_image')) {
            $file = $request->file('senior_image');
            $filename = time() . '_senior_' . preg_replace('/[^A-Za-z0-9\.]/', '_', $file->getClientOriginalName());
            $file->move(public_path('uploads/ids'), $filename);
            $user->senior_image = 'uploads/ids/' . $filename;
        }

        $user->save();

        return redirect()->back()->with('success', 'Account updated successfully!');
    }

    public function deleteAccount()
    {
        /** @var \App\Models\User $user */
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

        // Validate na ang item ay para sa selected branch
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

        if (isset($cart[$cartKey])) {
            $cart[$cartKey]['quantity'] += 1;
        } else {
            $menuItem = \App\Models\MenuItem::findOrFail($itemId);
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

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'contact_number' => $validated['contact_number'],
            'address' => $validated['address'] ?? null,
            'role' => 'customer',
            'is_active' => true,
        ]);

        Auth::login($user);

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

    // ══════════ VOUCHER ══════════

    public function applyVoucher(Request $request)
    {
        $code = strtoupper(trim($request->input('code')));
        $subtotal = (float) $request->input('subtotal', 0);

        $voucher = \App\Models\Voucher::where('code', $code)->first();

        if (!$voucher) {
            return response()->json(['success' => false, 'message' => 'Invalid voucher code.']);
        }

        if (!$voucher->isValid()) {
            return response()->json(['success' => false, 'message' => 'Voucher is expired or no longer available.']);
        }

        // Check kung valid na gamitin ngayon (hindi sa araw na nakuha)
        if ($voucher->valid_from && today()->lessThan($voucher->valid_from)) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher is not yet valid. Available starting ' .
                    \Carbon\Carbon::parse($voucher->valid_from)->format('M d, Y') . '.'
            ]);
        }

        if ($subtotal < $voucher->minimum_order) {
            return response()->json([
                'success' => false,
                'message' => 'Minimum order of ₱' . number_format($voucher->minimum_order, 2) . ' required.'
            ]);
        }

        if ($voucher->discount_type === 'percent') {
            $discount = $subtotal * ($voucher->discount_value / 100);
        } else {
            $discount = $voucher->discount_value;
        }

        $discount = min($discount, $subtotal);
        $final_total = $subtotal - $discount;

        return response()->json([
            'success'     => true,
            'message'     => $voucher->description ?? 'Voucher applied!',
            'discount'    => round($discount, 2),
            'final_total' => round($final_total, 2),
        ]);
    }

    // ══════════ GAME ══════════

    public function showVouchers()
    {
        if (!Auth::check()) {
            return redirect()->route('customer.login')
                ->with('error', 'Please login to view your vouchers.');
        }

        $userVouchers = \App\Models\UserVoucher::with('voucher')
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
        $existing = \App\Models\HelpRequest::where('branch_id', $branchId)
            ->where('table_number', $tableNumber)
            ->whereIn('status', ['pending', 'assisting'])
            ->first();

        if ($existing) {
            return back()->with('error', 'Help is already requested. Please wait for staff. 🙏');
        }

        // Find active order
        $activeOrder = \App\Models\Order::where('branch_id', $branchId)
            ->where('table_number', $tableNumber)
            ->whereIn('status', ['pending', 'preparing', 'serving'])
            ->latest()
            ->first();

        \App\Models\HelpRequest::create([
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
        $vouchers = \App\Models\Voucher::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now()->endOfDay());
            })
            ->where('used_count', '<', \Illuminate\Support\Facades\DB::raw('max_uses'))
            ->get();

        $gameAds = \App\Models\Ad::where('placement', 'game')
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

        // Guest — points lang sa session, walang voucher
        if (!Auth::check()) {
            $totalPoints = session('guest_points', 0) + $points;
            session()->put('guest_points', $totalPoints);
            return response()->json([
                'success'      => true,
                'total_points' => $totalPoints,
                'voucher'      => null,
                'next_voucher' => null,
                'points_needed' => 0,
                'message'      => 'Login to earn vouchers!',
            ]);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user->points += $points;
        $user->save();
        $totalPoints = $user->points;

        // Check kung may 2 na vouchers ang user — hindi na pwede kumita pa
        $existingVoucherCount = \App\Models\UserVoucher::where('user_id', $user->id)
            ->where('is_used', false)
            ->count();

        $voucherData = null;

        if ($existingVoucherCount < 2) {
            // Check kung may maearn na voucher base sa points
            $earnedVoucher = \App\Models\Voucher::where('is_active', true)
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
                \App\Models\UserVoucher::create([
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
        $nextVoucher = \App\Models\Voucher::where('is_active', true)
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

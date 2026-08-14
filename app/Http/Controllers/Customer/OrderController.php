<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UserVoucher;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OrderController extends Controller
{
    public function placeOrder(Request $request)
    {
        $validated = $request->validate([
            'order_type'             => 'required|in:dine_in,pick_up,walk_in',
            'items'                  => 'required|array|min:1',
            'items.*.cart_key'       => 'nullable|string',
            'items.*.menu_item_id'   => 'required|exists:menu_items,id',
            'items.*.quantity'       => 'required|integer|min:1|max:999',
            'table_number'           => 'nullable|string|max:50',
            'payment_method'         => 'nullable|in:cash,gcash',
            'voucher_code_confirmed' => 'nullable|string|max:50',
        ]);

        // Branch — session is the trusted source (set by QR scan or selectBranch).
        $branchId = session('branch_id') ?? $request->input('branch_id');

        // order_type and table_number are also trusted from the session because the
        // hidden form fields can be tampered with by the customer. We fall back to
        // the request only when the session has not been seeded yet.
        $orderType   = session('order_type')   ?: $validated['order_type'];
        $tableNumber = session('table_number') ?: $request->input('table_number');

        if (!$branchId) {
            return back()->withErrors(['error' => 'No branch selected. Please select a branch first.']);
        }

        // Closed branches cannot accept new orders.
        $branchOpen = Branch::where('id', $branchId)
            ->where('is_active', true)
            ->exists();
        if (!$branchOpen) {
            session()->forget(['cart', 'table_number', 'branch_id', 'order_type']);
            return redirect()->route('customer.menu')
                ->with('error', 'This branch is currently closed and not accepting orders.');
        }

        if ($orderType === 'dine_in' && empty($tableNumber)) {
            return back()->withErrors(['table_number' => 'Table number is required for dine-in']);
        }

        // Pull menu items in one query (avoids N+1 in the loop below).
        $menuItems = MenuItem::with(['recipeIngredients', 'inventoryItem'])
            ->whereIn('id', collect($validated['items'])->pluck('menu_item_id'))
            ->get()->keyBy('id');

        $cart = session()->get('cart', []);
        $total = 0;
        $itemsData = [];

        foreach ($validated['items'] as $item) {
            $menuItem = $menuItems[$item['menu_item_id']] ?? null;
            if (!$menuItem) {
                return back()->withErrors(['items' => 'Invalid menu item']);
            }

            // Match the cart line by its unique cart_key (menu_item + sorted option ids).
            // Two cart lines with the same menu_item_id but different selected options
            // must NOT be collapsed into one — that would charge / deduct the wrong options.
            $cartItem = null;
            $cartKey  = $item['cart_key'] ?? null;
            if ($cartKey !== null && isset($cart[$cartKey])) {
                $cartItem = $cart[$cartKey];
            } else {
                // Legacy fallback: cart_key missing (e.g. stale tab) — match by menu_item_id only.
                foreach ($cart as $ci) {
                    if ((int) ($ci['menu_item_id'] ?? null) === (int) $menuItem->id) {
                        $cartItem = $ci;
                        break;
                    }
                }
            }

            // Snapshot the line price from the cart (set at add-to-cart time from DB).
            // Using ONE source for both the order subtotal and the order_item rows
            // guarantees Order.subtotal == sum(OrderItem.subtotal) exactly.
            $itemPrice         = (float) ($cartItem['price'] ?? $menuItem->price);
            $selectedOptionIds = [];
            $optionDetails     = [];
            foreach (($cartItem['options'] ?? []) as $opt) {
                if (isset($opt['id'])) {
                    $selectedOptionIds[] = (int) $opt['id'];
                    $optionDetails[]     = $opt;
                }
            }

            $itemSubtotal = round($itemPrice * (int) $item['quantity'], 2);
            $total += $itemSubtotal;
            $itemsData[] = [
                'menu_item'           => $menuItem,
                'quantity'            => (int) $item['quantity'],
                'item_price'          => $itemPrice,
                'subtotal'            => $itemSubtotal,
                'selected_option_ids' => $selectedOptionIds,
                'option_details'      => $optionDetails,
            ];
        }
        $subtotal = round($total, 2);

        // Stock validation — checks recipe ingredients + selected option ingredients only.
        $stockErrors = (new \App\Services\InventoryDeductionService())->validateCartLines($itemsData);
        if (!empty($stockErrors)) {
            return back()->withErrors(['items' => $stockErrors]);
        }

        // Resolve voucher (read-only) — full server-side validation: active, not expired,
        // valid_from reached, minimum_order met, max_uses not exhausted, and (for game-
        // earned vouchers) actually owned by this authenticated user.
        $voucherResolution = $this->resolveVoucher(
            $request->input('voucher_code_confirmed'),
            $subtotal,
            Auth::id()
        );
        if ($voucherResolution['error']) {
            return back()->withErrors(['voucher' => $voucherResolution['error']])->withInput();
        }
        $voucher        = $voucherResolution['voucher'];
        $discountAmount = $voucherResolution['discount'];
        $finalTotal     = round(max($subtotal - $discountAmount, 0), 2);

        try {
            $order = DB::transaction(function () use ($validated, $itemsData, $subtotal, $finalTotal, $discountAmount, $branchId, $voucher, $tableNumber, $orderType) {

                // Atomic voucher claim: re-checks max_uses + is_active under a single
                // UPDATE so two parallel orders cannot both consume the last slot.
                // Throws inside the transaction → automatic rollback, no order created.
                if ($voucher) {
                    $claimed = Voucher::where('id', $voucher->id)
                        ->where('is_active', true)
                        ->whereColumn('used_count', '<', 'max_uses')
                        ->update(['used_count' => DB::raw('used_count + 1')]);

                    if (!$claimed) {
                        throw new RuntimeException('This voucher just reached its usage limit. Please try a different code.');
                    }

                    // Burn the per-user voucher slot for game-earned vouchers.
                    if (Auth::id() && (int) $voucher->points_required > 0) {
                        UserVoucher::where('user_id', Auth::id())
                            ->where('voucher_id', $voucher->id)
                            ->where('is_used', false)
                            ->update(['is_used' => true, 'used_at' => now()]);
                    }
                }

                $order = Order::create([
                    'user_id'         => Auth::id(),
                    'branch_id'       => $branchId,
                    'type'            => $orderType,
                    'table_number'    => $tableNumber ?: null,
                    'order_number'    => 'ORD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                    'subtotal'        => $subtotal,
                    'total'           => $finalTotal,
                    'discount_amount' => $discountAmount,
                    'discount_type'   => $voucher ? 'voucher' : null,
                    'voucher_id'      => $voucher?->id,
                    'tax_amount'      => 0,
                    'status'          => 'pending',
                    'payment_method'  => $validated['payment_method'] ?? 'cash',
                    'payment_status'  => 'pending',
                    'amount_paid'     => 0,
                    'change_amount'   => 0,
                ]);

                foreach ($itemsData as $data) {
                    $orderItem = OrderItem::create([
                        'order_id'     => $order->id,
                        'menu_item_id' => $data['menu_item']->id,
                        'item_name'    => $data['menu_item']->name,
                        'quantity'     => $data['quantity'],
                        'item_price'   => $data['item_price'],
                        'subtotal'     => $data['subtotal'],
                    ]);

                    foreach ($data['option_details'] as $opt) {
                        DB::table('order_item_options')->insert([
                            'order_item_id'    => $orderItem->id,
                            'menu_option_id'   => $opt['id'],
                            'option_name'      => $opt['name'],
                            'additional_price' => $opt['price'],
                            'created_at'       => now(),
                            'updated_at'       => now(),
                        ]);
                    }
                }

                return $order;
            });

            // Clear cart
            session()->forget(['cart', 'voucher_code']);

            // Save order ID sa session para ma-track ng guest users
            session()->put('guest_order_id', $order->id);

            return redirect()->route('customer.orders')
                ->with('success', 'Order placed! 🎉 Order #' . $order->order_number);
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to place order: ' . $e->getMessage()]);
        }
    }

    public function showOrders()
    {
        // Guest users (QR scan dine-in) - track via session
        if (!Auth::check()) {
            $guestOrderId = session('guest_order_id');
            $currentOrder = null;
            if ($guestOrderId) {
                $currentOrder = Order::with(['items.menuItem', 'items.options'])
                    ->where('id', $guestOrderId)
                    ->whereIn('status', ['pending', 'preparing', 'serving'])
                    ->first();
            }
            $orderHistory = collect();
            return view('customer.orders', compact('currentOrder', 'orderHistory'));
        }

        $currentOrder = Order::with(['items.menuItem', 'items.options'])
            ->where('user_id', Auth::id())
            ->whereIn('status', ['pending', 'preparing', 'serving'])
            ->latest()
            ->first();

        $orderHistory = Order::withCount('items')
            ->where('user_id', Auth::id())
            ->whereIn('status', ['completed', 'cancelled'])
            ->latest()
            ->get();

        return view('customer.orders', compact('currentOrder', 'orderHistory'));
    }

    public function showReceipt(int $id)
    {
        // ── Guest dine-in customers ──
        // Only the order created in THIS session may be viewed. No branch/table
        // fallback — another customer may reuse the table later.
        if (!Auth::check()) {
            $guestOrderId = session('guest_order_id');
            if (!$guestOrderId || (int) $guestOrderId !== (int) $id) {
                abort(404);
            }

            $order = Order::with(['items.menuItem', 'items.options'])
                ->where('id', $id)
                ->where('type', 'dine_in')
                ->whereNull('user_id')
                ->first();

            if (!$order) {
                abort(404);
            }

            return view('customer.receipt', compact('order'));
        }

        // ── Logged-in customers (pick-up) ──
        // Can only view their own receipts.
        $order = Order::with(['items.menuItem', 'items.options'])
            ->where('user_id', Auth::id())
            ->where('id', $id)
            ->first();

        if (!$order) {
            abort(404);
        }

        return view('customer.receipt', compact('order'));
    }

    /**
     * Server-side voucher validation. Single source of truth used by both
     * placeOrder (authoritative) and the AJAX preview in AuthController.
     *
     * Returns:
     *   ['voucher' => Voucher|null, 'discount' => float, 'error' => string|null]
     *
     * - error is set when the code is non-empty but unusable (invalid, expired,
     *   not yet valid, minimum not met, not owned). Empty/null code is not an error.
     * - discount is already capped at $subtotal so callers can do max(subtotal-discount, 0).
     */
    public function resolveVoucher(?string $code, float $subtotal, ?int $userId): array
    {
        $code = trim((string) $code);
        if ($code === '') {
            return ['voucher' => null, 'discount' => 0.0, 'error' => null];
        }

        $voucher = Voucher::where('code', strtoupper($code))->first();
        if (!$voucher || !$voucher->isValid()) {
            return ['voucher' => null, 'discount' => 0.0, 'error' => 'Invalid or expired voucher code.'];
        }

        // valid_from gate (e.g. game-earned voucher usable starting tomorrow).
        if ($voucher->valid_from && today()->lessThan($voucher->valid_from)) {
            return [
                'voucher'  => null,
                'discount' => 0.0,
                'error'    => 'Voucher not yet valid. Available starting ' . $voucher->valid_from->format('M d, Y') . '.',
            ];
        }

        // Minimum spend — checked against the server-computed subtotal.
        if ($subtotal < (float) $voucher->minimum_order) {
            return [
                'voucher'  => null,
                'discount' => 0.0,
                'error'    => 'Minimum order of ₱' . number_format((float) $voucher->minimum_order, 2) . ' required for this voucher.',
            ];
        }

        // Game-earned vouchers (points_required > 0) must be claimed by THIS user.
        // Public codes (points_required = 0) are usable by anyone with the code.
        if ($userId && (int) $voucher->points_required > 0) {
            $owned = UserVoucher::where('user_id', $userId)
                ->where('voucher_id', $voucher->id)
                ->where('is_used', false)
                ->exists();
            if (!$owned) {
                return ['voucher' => null, 'discount' => 0.0, 'error' => 'This voucher is not available on your account.'];
            }
        }

        $discount = $voucher->discount_type === 'percent'
            ? $subtotal * ((float) $voucher->discount_value / 100)
            : (float) $voucher->discount_value;

        $discount = round(min($discount, $subtotal), 2);

        return ['voucher' => $voucher, 'discount' => $discount, 'error' => null];
    }
}

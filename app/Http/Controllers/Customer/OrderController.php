<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function placeOrder(Request $request)
    {
        $validated = $request->validate([
            'order_type'            => 'required|in:dine_in,pick_up,walk_in',
            'items'                 => 'required|array|min:1',
            'items.*.menu_item_id'  => 'required|exists:menu_items,id',
            'items.*.quantity'      => 'required|integer|min:1',
            'table_number'          => 'nullable|string',
            'payment_method'        => 'nullable|in:cash,gcash,card',
            'voucher_code_confirmed' => 'nullable|string',
        ]);

        // Branch logic — from session only (set from QR or branch selector)
        $branchId = session('branch_id') ?? $request->input('branch_id');

        // Table number — request first, then session fallback
        $tableNumber = $request->input('table_number') ?? session('table_number');

        if (!$branchId) {
            return back()->withErrors(['error' => 'No branch selected. Please select a branch first.']);
        }

        if ($validated['order_type'] === 'dine_in' && empty($tableNumber)) {
            return back()->withErrors(['table_number' => 'Table number is required for dine-in']);
        }
        $menuItems = MenuItem::whereIn(
            'id',
            collect($validated['items'])->pluck('menu_item_id')
        )->get()->keyBy('id');

        $cart = session()->get('cart', []);
        $total = 0;
        $itemsData = [];

        foreach ($validated['items'] as $item) {
            $menuItem = $menuItems[$item['menu_item_id']] ?? null;
            if (!$menuItem) {
                return back()->withErrors(['items' => 'Invalid menu item']);
            }

            $cartPrice = $menuItem->price;
            foreach ($cart as $ci) {
                $cartMenuId = $ci['menu_item_id'] ?? null;
                if ((int)$cartMenuId === (int)$menuItem->id) {
                    $cartPrice = $ci['price'];
                    break;
                }
            }

            $itemSubtotal = $cartPrice * $item['quantity'];
            $total += $itemSubtotal;
            $itemsData[] = [
                'menu_item' => $menuItem,
                'quantity' => $item['quantity'],
                'subtotal' => $itemSubtotal,
            ];
        }

        // Check stock availability
        foreach ($itemsData as $data) {
            $menuItem = $data['menu_item'];
            if ($menuItem->inventory_item_id && $menuItem->inventoryItem) {
                $needed = ($menuItem->inventory_amount_used ?: 1) * $data['quantity'];
                if ($needed > $menuItem->inventoryItem->quantity) {
                    return back()->withErrors(['items' => $menuItem->name . ' is out of stock. Only ' . $menuItem->inventoryItem->quantity . ' ' . $menuItem->inventoryItem->unit . ' available.']);
                }
            }
        }

        // Calculate discount
        $discountAmount = 0;
        $voucherCode = $request->input('voucher_code_confirmed');

        if ($voucherCode) {
            $voucher = Voucher::where('code', $voucherCode)->first();
            if ($voucher && $voucher->isValid()) {
                if ($voucher->discount_type === 'percent') {
                    $discountAmount = $total * ($voucher->discount_value / 100);
                } else {
                    $discountAmount = $voucher->discount_value;
                }
                $discountAmount = min($discountAmount, $total);
            }
        }

        $finalTotal = $total - $discountAmount;

        try {
            $order = DB::transaction(function () use ($validated, $itemsData, $total, $finalTotal, $discountAmount, $branchId, $voucherCode, $cart, $tableNumber) {
                $order = Order::create([
                    'user_id' => Auth::id(),
                    'branch_id' => $branchId,
                    'type' => $validated['order_type'],
                    'table_number' => $tableNumber ?? null,
                    'order_number' => 'ORD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                    'subtotal' => $total,
                    'total' => $finalTotal,
                    'discount_amount' => $discountAmount,
                    'tax_amount' => 0,
                    'status' => 'pending',
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'payment_status' => 'pending',
                    'amount_paid' => 0,
                    'change_amount' => 0,
                ]);

                foreach ($itemsData as $index => $data) {
                    $cartItem = null;
                    foreach ($cart as $key => $ci) {
                        $cartMenuId = $ci['menu_item_id'] ?? $key;
                        if ((int)$cartMenuId === (int)$data['menu_item']->id) {
                            $cartItem = $ci;
                            break;
                        }
                    }

                    $optionsTotal = 0;
                    $optionDetails = [];
                    if ($cartItem && !empty($cartItem['options'])) {
                        foreach ($cartItem['options'] as $opt) {
                            $optionsTotal += $opt['price'];
                            $optionDetails[] = $opt;
                        }
                    }

                    $itemPrice = $data['menu_item']->price + $optionsTotal;
                    $subtotal = $itemPrice * $data['quantity'];

                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'menu_item_id' => $data['menu_item']->id,
                        'item_name' => $data['menu_item']->name,
                        'quantity' => $data['quantity'],
                        'item_price' => $itemPrice,
                        'subtotal' => $subtotal,
                    ]);

                    foreach ($optionDetails as $opt) {
                        DB::table('order_item_options')->insert([
                            'order_item_id' => $orderItem->id,
                            'menu_option_id' => $opt['id'],
                            'option_name' => $opt['name'],
                            'additional_price' => $opt['price'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                if ($voucherCode) {
                    Voucher::where('code', $voucherCode)->increment('used_count');
                }

                return $order;
            });

            // Clear cart
            session()->forget(['cart', 'voucher_code']);

            // Save order ID sa session para ma-track ng guest users
            session()->put('guest_order_id', $order->id);

            // Reset points (only if logged in)
            if (Auth::check()) {
                /** @var \App\Models\User $user */
                $user = Auth::user();
                $user->points = 0;
                $user->save();
            }

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
        // Guest users can view receipt by order ID
        if (!Auth::check()) {
            $order = Order::with(['items.menuItem', 'items.options'])
                ->where('id', $id)
                ->firstOrFail();
            return view('customer.receipt', compact('order'));
        }

        $order = Order::with(['items.menuItem', 'items.options'])
            ->where('user_id', Auth::id())
            ->where('id', $id)
            ->firstOrFail();

        return view('customer.receipt', compact('order'));
    }
}

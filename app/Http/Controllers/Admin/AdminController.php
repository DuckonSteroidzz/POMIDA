<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    // ══════════ Branch Helper ══════════
    private function getSelectedBranch(): int|string
    {
        $user = auth()->user();

        // Staff — locked sa sariling branch
        if ($user->role === 'staff') {
            return $user->branch_id ?? 1;
        }

        // Admin — may filter
        return session('selected_branch_id', 'all');
    }

    // ══════════ Pages ══════════

    public function showAccount()
    {
        return view('admin.account');
    }

    public function updateAccount(Request $request)
    {
        return redirect()->back()->with('success', 'Account updated successfully.');
    }

    public function showSummary(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $selectedBranch = $this->getSelectedBranch();
        $ordersQuery = \App\Models\Order::where('status', 'completed')
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch));
        $itemsQuery = \App\Models\OrderItem::whereHas('order', fn($q) => $q->where('status', 'completed'));

        if ($dateFrom) {
            $ordersQuery->whereDate('created_at', '>=', $dateFrom);
            $itemsQuery->whereHas('order', fn($q) => $q->whereDate('created_at', '>=', $dateFrom));
        }
        if ($dateTo) {
            $ordersQuery->whereDate('created_at', '<=', $dateTo);
            $itemsQuery->whereHas('order', fn($q) => $q->whereDate('created_at', '<=', $dateTo));
        }

        // Total orders today
        $totalOrdersToday = \App\Models\Order::where('status', 'completed')
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch))
            ->whereDate('created_at', today())->count();

        // Total revenue
        $totalRevenue = (clone $ordersQuery)->sum('total');

        // Most & least ordered
        $mostOrdered = (clone $itemsQuery)->select('item_name', \Illuminate\Support\Facades\DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('item_name')->orderByDesc('total_qty')->first();
        $leastOrdered = (clone $itemsQuery)->select('item_name', \Illuminate\Support\Facades\DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('item_name')->orderBy('total_qty')->first();

        $mostOrderedItem = $mostOrdered ? $mostOrdered->item_name . ' (' . $mostOrdered->total_qty . ')' : '-';
        $leastOrderedItem = $leastOrdered ? $leastOrdered->item_name . ' (' . $leastOrdered->total_qty . ')' : '-';

        // Total orders (filtered)
        $totalOrders = (clone $ordersQuery)->count();

        // Peak hours
        $peakHour = \App\Models\Order::where('status', 'completed')
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch))
            ->select(\Illuminate\Support\Facades\DB::raw('HOUR(created_at) as hour'), \Illuminate\Support\Facades\DB::raw('COUNT(*) as count'))
            ->groupBy('hour')->orderByDesc('count')->first();
        if ($peakHour) {
            $h = $peakHour->hour;
            $peakHours = date('g A', mktime($h)) . ' - ' . date('g A', mktime($h + 2));
        } else {
            $peakHours = 'No data yet';
        }

        // Chart data - daily sales (last 7 days)
        $chartLabels = [];
        $chartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $chartLabels[] = $date->format('D');
            $chartData[] = \App\Models\Order::where('status', 'completed')
                ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch))
                ->whereDate('created_at', $date->toDateString())
                ->sum('total');
        }

        // Pie chart - sales by category
        $categoryStats = \App\Models\OrderItem::whereHas('order', fn($q) => $q->where('status', 'completed'))
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->join('categories', 'menu_items.category_id', '=', 'categories.id')
            ->select('categories.name', \Illuminate\Support\Facades\DB::raw('SUM(order_items.quantity) as total_qty'))
            ->groupBy('categories.name')
            ->orderByDesc('total_qty')
            ->get();

        $pieLabels = $categoryStats->pluck('name')->toArray();
        $pieData = $categoryStats->pluck('total_qty')->toArray();

        if (empty($pieLabels)) {
            $pieLabels = ['No data'];
            $pieData = [1];
        }

        return view('admin.summary', compact(
            'totalOrdersToday',
            'totalOrders',
            'totalRevenue',
            'mostOrderedItem',
            'leastOrderedItem',
            'peakHours',
            'chartLabels',
            'chartData',
            'pieLabels',
            'pieData'
        ));
    }

    // ══════════ Menu Items (CRUD WORKING) ══════════

    public function showMenuItems()
    {
        $selectedBranch = $this->getSelectedBranch();

        $menuItems = \App\Models\MenuItem::with(['category', 'subcategory', 'inventoryItem', 'branch'])
            ->when($selectedBranch !== 'all', function ($q) use ($selectedBranch) {
                $q->where('branch_id', $selectedBranch);
            })
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $branches = \App\Models\Branch::where('is_active', true)->orderBy('id')->get();
        $categories = Category::orderBy('name')->get();
        $subcategories = \App\Models\Subcategory::orderBy('name')->get();

        $inventoryItems = \App\Models\Inventory::where('is_active', true)
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch))
            ->orderBy('item_name')
            ->get();

        return view('admin.menu-items', compact(
            'menuItems',
            'categories',
            'subcategories',
            'inventoryItems',
            'branches',
            'selectedBranch'
        ));
    }

    public function showNewMenuItem()
    {
        $selectedBranch = $this->getSelectedBranch();

        if ($selectedBranch === 'all') {
            return redirect()->route('admin.menu-items')
                ->withErrors(['branch' => 'Please select a specific branch before adding a menu item. All Branches is for viewing only.']);
        }

        $categories = Category::orderBy('name')->get();
        $subcategories = \App\Models\Subcategory::orderBy('name')->get();

        $inventoryItems = \App\Models\Inventory::where('is_active', true)
            ->where('branch_id', $selectedBranch)
            ->orderBy('item_name')
            ->get();

        return view('admin.new-menu-items', compact(
            'categories',
            'subcategories',
            'inventoryItems',
            'selectedBranch'
        ));
    }

    public function storeNewMenuItem(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'subcategory_id' => 'nullable|exists:subcategories,id',
            'inventory_item_id' => 'nullable|exists:inventory,id',
            'inventory_amount_used' => 'nullable|numeric|min:0',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'branch_id' => 'nullable|exists:branches,id',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ], [
            'category_id.required' => 'Please select a category.',
            'name.required' => 'Item name is required.',
            'price.required' => 'Price is required.',
            'price.numeric' => 'Price must be a number.',
            'image.mimes' => 'Image must be JPG, PNG, or WEBP.',
            'image.max' => 'Image must be less than 2MB.',
        ]);

        $selectedBranch = $this->getSelectedBranch();

        if ($selectedBranch === 'all') {
            return redirect()->route('admin.menu-items')
                ->withErrors(['branch' => 'Please select a specific branch before adding a menu item.']);
        }

        if (!empty($validated['inventory_item_id'])) {
            $inventory = \App\Models\Inventory::find($validated['inventory_item_id']);

            if (!$inventory || (int)$inventory->branch_id !== (int)$selectedBranch) {
                return back()->withErrors([
                    'inventory_item_id' => 'Selected inventory item must belong to the selected branch.'
                ])->withInput();
            }
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = time() . '_' . preg_replace('/[^A-Za-z0-9\.]/', '_', $file->getClientOriginalName());
            $file->move(public_path('uploads/menu-items'), $filename);
            $imagePath = 'uploads/menu-items/' . $filename;
        }

        \App\Models\MenuItem::create([
            'category_id'           => $validated['category_id'],
            'subcategory_id'        => $validated['subcategory_id'] ?? null,
            'inventory_item_id'     => $validated['inventory_item_id'] ?? null,
            'inventory_amount_used' => $validated['inventory_amount_used'] ?? 0,
            'name'                  => $validated['name'],
            'description'           => $validated['description'] ?? null,
            'price'                 => $validated['price'],
            'cost'                  => $validated['cost'] ?? 0,
            'image'                 => $imagePath,
            'branch_id' => $selectedBranch,
            'is_available'          => true,
            'is_featured'           => false,
            'display_order'         => 0,
            'total_sold'            => 0,
        ]);

        return redirect()->route('admin.menu-items')
            ->with('success', 'Menu item "' . $validated['name'] . '" added successfully!');
    }

    public function editMenuItem(int $id)
    {
        $menuItem = \App\Models\MenuItem::findOrFail($id);
        $categories = Category::orderBy('name')->get();
        $subcategories = \App\Models\Subcategory::orderBy('name')->get();

        $selectedBranch = $this->getSelectedBranch();

        if ($selectedBranch === 'all') {
            return redirect()->route('admin.menu-items')
                ->withErrors(['branch' => 'Please select the item branch before editing inventory-linked menu items.']);
        }

        $inventoryItems = \App\Models\Inventory::where('is_active', true)
            ->where('branch_id', $selectedBranch)
            ->orderBy('item_name')
            ->get();

        return view('admin.new-menu-items', compact(
            'menuItem',
            'categories',
            'subcategories',
            'inventoryItems',
            'selectedBranch'
        ));
    }

    public function updateMenuItem(Request $request, int $id)
    {
        $menuItem = \App\Models\MenuItem::findOrFail($id);

        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'subcategory_id' => 'nullable|exists:subcategories,id',
            'inventory_item_id' => 'nullable|exists:inventory,id',
            'inventory_amount_used' => 'nullable|numeric|min:0',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'branch_id' => 'nullable|exists:branches,id',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        $selectedBranch = $this->getSelectedBranch();

        if ($selectedBranch === 'all') {
            return redirect()->route('admin.menu-items')
                ->withErrors(['branch' => 'Please select a specific branch before editing a menu item.']);
        }

        if (!empty($validated['inventory_item_id'])) {
            $inventory = \App\Models\Inventory::find($validated['inventory_item_id']);

            if (!$inventory || (int)$inventory->branch_id !== (int)$selectedBranch) {
                return back()->withErrors([
                    'inventory_item_id' => 'Selected inventory item must belong to the selected branch.'
                ])->withInput();
            }
        }

        if ($request->hasFile('image')) {
            if ($menuItem->image && file_exists(public_path($menuItem->image))) {
                unlink(public_path($menuItem->image));
            }
            $file = $request->file('image');
            $filename = time() . '_' . preg_replace('/[^A-Za-z0-9\.]/', '_', $file->getClientOriginalName());
            $file->move(public_path('uploads/menu-items'), $filename);
            $menuItem->image = 'uploads/menu-items/' . $filename;
        }

        $menuItem->category_id = $validated['category_id'];
        $menuItem->subcategory_id = $validated['subcategory_id'] ?? null;
        $menuItem->inventory_item_id = $validated['inventory_item_id'] ?? null;
        $menuItem->inventory_amount_used = $validated['inventory_amount_used'] ?? 0;
        $menuItem->name = $validated['name'];
        $menuItem->description = $validated['description'] ?? null;
        $menuItem->price = $validated['price'];
        $menuItem->cost = $validated['cost'] ?? 0;
        $menuItem->branch_id = $selectedBranch;
        $menuItem->save();

        return redirect()->route('admin.menu-items')
            ->with('success', 'Menu item updated successfully!');
    }

    public function toggleMenuItem(int $id)
    {
        $menuItem = \App\Models\MenuItem::findOrFail($id);
        $menuItem->is_available = !$menuItem->is_available;
        $menuItem->save();

        $status = $menuItem->is_available ? 'made available' : 'hidden from menu';
        return redirect()->back()
            ->with('success', 'Item "' . $menuItem->name . '" ' . $status . '.');
    }

    public function deleteMenuItem(int $id)
    {
        $menuItem = \App\Models\MenuItem::findOrFail($id);

        $hasOrders = \App\Models\OrderItem::where('menu_item_id', $id)->exists();
        if ($hasOrders) {
            $menuItem->is_available = false;
            $menuItem->save();
            return redirect()->route('admin.menu-items')
                ->with('success', 'Item "' . $menuItem->name . '" hidden from menu (has existing orders).');
        }

        if ($menuItem->image && file_exists(public_path($menuItem->image))) {
            unlink(public_path($menuItem->image));
        }

        $name = $menuItem->name;
        $menuItem->delete();

        return redirect()->route('admin.menu-items')
            ->with('success', 'Menu item "' . $name . '" deleted successfully!');
    }

    // ══════════ Categories (CRUD WORKING) ══════════

    public function showAddCategory()
    {
        $categories = Category::with('menuItems')->orderBy('display_order')->orderBy('name')->get();
        $subcategories = \App\Models\Subcategory::with('category')->orderBy('name')->get();
        return view('admin.add-category', compact('categories', 'subcategories'));
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ], [
            'name.required' => 'Category name is required.',
            'name.unique' => 'This category already exists.',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = time() . '_cat_' . preg_replace('/[^A-Za-z0-9\.]/', '_', $file->getClientOriginalName());
            $file->move(public_path('uploads/categories'), $filename);
            $imagePath = 'uploads/categories/' . $filename;
        }

        Category::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'image' => $imagePath,
            'is_active' => true,
            'display_order' => 0,
        ]);

        return redirect()->route('admin.add-category')
            ->with('success', 'Category "' . $validated['name'] . '" added successfully!');
    }

    public function editCategory(int $id)
    {
        $category = Category::findOrFail($id);
        $categories = Category::orderBy('display_order')->orderBy('name')->get();
        return view('admin.add-category', compact('categories', 'category'));
    }

    public function updateCategory(Request $request, int $id)
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,' . $id,
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($request->hasFile('image')) {
            if ($category->image && file_exists(public_path($category->image))) {
                unlink(public_path($category->image));
            }
            $file = $request->file('image');
            $filename = time() . '_cat_' . preg_replace('/[^A-Za-z0-9\.]/', '_', $file->getClientOriginalName());
            $file->move(public_path('uploads/categories'), $filename);
            $category->image = 'uploads/categories/' . $filename;
        }

        $category->name = $validated['name'];
        $category->description = $validated['description'] ?? null;
        $category->save();

        return redirect()->route('admin.add-category')
            ->with('success', 'Category updated successfully!');
    }

    public function deleteCategory(int $id)
    {
        $category = Category::findOrFail($id);

        $itemCount = \App\Models\MenuItem::where('category_id', $id)->count();
        if ($itemCount > 0) {
            return redirect()->route('admin.add-category')
                ->withErrors(['error' => 'Cannot delete "' . $category->name . '" — it has ' . $itemCount . ' menu item(s) linked. Remove or reassign them first.']);
        }

        $name = $category->name;
        $category->delete();

        return redirect()->route('admin.add-category')
            ->with('success', 'Category "' . $name . '" deleted successfully!');
    }

    // ══════════ Sub Categories (CRUD WORKING) ══════════

    public function showAddSubcategory()
    {
        $subcategories = \App\Models\Subcategory::with('category')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
        $categories = Category::orderBy('name')->get();
        return view('admin.add-subcategory', compact('subcategories', 'categories'));
    }

    public function storeSubcategory(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ], [
            'category_id.required' => 'Please select a parent category.',
            'category_id.exists' => 'Invalid category selected.',
            'name.required' => 'Subcategory name is required.',
        ]);

        $exists = \App\Models\Subcategory::where('category_id', $validated['category_id'])
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'name' => 'This subcategory already exists in the selected category.',
            ])->withInput();
        }

        \App\Models\Subcategory::create([
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
            'display_order' => 0,
        ]);

        return redirect()->route('admin.add-subcategory')
            ->with('success', 'Subcategory "' . $validated['name'] . '" added successfully!');
    }

    public function editSubcategory(int $id)
    {
        $subcategory = \App\Models\Subcategory::findOrFail($id);
        $subcategories = \App\Models\Subcategory::with('category')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
        $categories = Category::orderBy('name')->get();
        return view('admin.add-subcategory', compact('subcategories', 'categories', 'subcategory'));
    }

    public function updateSubcategory(Request $request, int $id)
    {
        $subcategory = \App\Models\Subcategory::findOrFail($id);

        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $subcategory->update([
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return redirect()->route('admin.add-subcategory')
            ->with('success', 'Subcategory updated successfully!');
    }

    public function deleteSubcategory(int $id)
    {
        $subcategory = \App\Models\Subcategory::findOrFail($id);
        $name = $subcategory->name;
        $subcategory->delete();

        return redirect()->route('admin.add-subcategory')
            ->with('success', 'Subcategory "' . $name . '" deleted successfully!');
    }

    // ══════════ Menu Options ══════════

    public function showMenuOptions()
    {
        $options = \App\Models\MenuOption::orderBy('name')->get();
        $categories = \App\Models\Category::with(['menuItems'])->orderBy('name')->get();
        return view('admin.menu-options', compact('options', 'categories'));
    }

    public function storeMenuOption(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'price'       => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ]);

        \App\Models\MenuOption::create([
            'name'             => $validated['name'],
            'additional_price' => $validated['price'] ?? 0,
            'is_active'        => true,
            'display_order'    => 0,
        ]);

        return redirect()->route('admin.menu-options')->with('success', 'Option added!');
    }

    public function deleteMenuOption(int $id)
    {
        $option = \App\Models\MenuOption::findOrFail($id);
        $option->delete();
        return redirect()->route('admin.menu-options')->with('success', 'Option deleted!');
    }

    public function assignOptions(Request $request, int $menuItemId)
    {
        $menuItem = \App\Models\MenuItem::findOrFail($menuItemId);
        $optionIds = $request->input('option_ids', []);
        $menuItem->options()->sync($optionIds);
        return response()->json(['success' => true]);
    }

    // ══════════ Orders Management ══════════

    public function showHome()
    {
        $selectedBranch = $this->getSelectedBranch();

        $pendingOrders = \App\Models\Order::with('items')
            ->whereIn('status', ['pending', 'preparing', 'serving'])
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch))
            ->orderBy('created_at', 'asc')
            ->get();

        $helpRequests = \App\Models\HelpRequest::with(['branch', 'order'])
            ->whereIn('status', ['pending', 'assisting'])
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch))
            ->orderBy('requested_at', 'asc')
            ->get();

        return view('admin.home', compact('pendingOrders', 'helpRequests'));
    }

    public function showCompletedOrders(Request $request)
    {
        $selectedBranch = $this->getSelectedBranch();

        $query = \App\Models\Order::with(['items', 'customer'])
            ->whereIn('status', ['completed', 'cancelled']);

        if ($selectedBranch !== 'all') {
            $query->where('branch_id', $selectedBranch);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('updated_at', 'desc')->get();
        return view('admin.completed-orders', compact('orders'));
    }

    public function showOrderDetail(int $id)
    {
        $order = \App\Models\Order::with(['items.menuItem', 'user'])->findOrFail($id);
        return view('admin.order-detail', compact('order'));
    }

    public function completeOrder(int $id)
    {
        $order = \App\Models\Order::with('items.menuItem.inventoryItem')->findOrFail($id);

        if (!in_array($order->status, ['pending', 'preparing', 'serving'])) {
            return redirect()->back()->withErrors([
                'order' => 'Order is not pending. Current status: ' . $order->status,
            ]);
        }

        $deductionsLog = [];

        foreach ($order->items as $orderItem) {
            $menuItem = $orderItem->menuItem;

            if (!$menuItem || !$menuItem->inventory_item_id || !$menuItem->inventoryItem) {
                continue;
            }

            $inventoryItem = $menuItem->inventoryItem;
            $amountPerOrder = $menuItem->inventory_amount_used ?: 1;
            $totalDeduct = $amountPerOrder * $orderItem->quantity;

            if ($totalDeduct > $inventoryItem->quantity) {
                continue;
            }

            $inventoryItem->quantity -= $totalDeduct;
            $inventoryItem->save();

            \App\Models\StockMovement::create([
                'inventory_id'  => $inventoryItem->id,
                'movement_type' => 'out',
                'amount'        => $totalDeduct,
                'quantity_after' => $inventoryItem->quantity,
                'reason'        => 'Order #' . $order->order_number . ': ' . $orderItem->quantity . 'x ' . $menuItem->name,
                'source'        => 'order',
                'reference_id'  => $order->id,
                'user_id'       => auth()->id(),
            ]);

            $deductionsLog[] = $totalDeduct . ' ' . $inventoryItem->unit . ' ' . $inventoryItem->item_name;
        }

        $order->status         = 'completed';
        $order->payment_status = 'paid';
        $order->receipt_number = 'RCP-' . now()->format('Ymd') . '-' . str_pad($order->id, 4, '0', STR_PAD_LEFT);
        $order->completed_at   = now();
        $order->save();

        $message = 'Order #' . $order->order_number . ' completed!';
        if (!empty($deductionsLog)) {
            $message .= ' Inventory deducted: ' . implode(', ', $deductionsLog);
        }

        return redirect()->route('admin.home')->with('success', $message);
    }

    public function prepareOrder(int $id)
    {
        $order = \App\Models\Order::findOrFail($id);
        $order->status      = 'preparing';
        $order->preparing_at = now();
        $order->save();

        return redirect()->route('admin.home')
            ->with('success', 'Order #' . $order->order_number . ' is now being prepared!');
    }

    public function serveOrder(int $id)
    {
        $order = \App\Models\Order::findOrFail($id);
        $order->status     = 'serving';
        $order->serving_at = now();
        $order->save();

        return redirect()->route('admin.home')
            ->with('success', 'Order #' . $order->order_number . ' is now being served!');
    }

    public function cancelOrder(int $id)
    {
        $order = \App\Models\Order::findOrFail($id);
        $order->status = 'cancelled';
        $order->save();

        return redirect()->route('admin.home')
            ->with('success', 'Order #' . $order->order_number . ' cancelled.');
    }

    // ══════════ Inventory (CRUD WORKING) ══════════

    public function showInventory()
    {
        $selectedBranch = $this->getSelectedBranch();
        $inventory = \App\Models\Inventory::orderBy('item_name')
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch))
            ->get();
        $categories = Category::orderBy('name')->get();
        $stockMovements = \App\Models\StockMovement::with(['inventory', 'user'])
            ->when($selectedBranch !== 'all', function ($q) use ($selectedBranch) {
                $q->whereHas('inventory', function ($inv) use ($selectedBranch) {
                    $inv->where('branch_id', $selectedBranch);
                });
            })
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();
        return view('admin.inventory', compact('inventory', 'categories', 'stockMovements'));
    }

    public function storeInventory(Request $request)
    {
        $selectedBranch = $this->getSelectedBranch();

        if ($selectedBranch === 'all') {
            return redirect()->route('admin.inventory')
                ->withErrors(['branch' => 'Please select a specific branch before adding inventory.']);
        }

        $validated = $request->validate([
            'item_name'       => 'required|string|max:255',
            'item_code'       => 'nullable|string|max:50',
            'category'        => 'nullable|string|max:100',
            'quantity'        => 'required|numeric|min:0',
            'unit'            => 'required|string|max:20',
            'low_stock_alert' => 'nullable|numeric|min:0',
            'unit_cost'       => 'nullable|numeric|min:0',
            'supplier'        => 'nullable|string|max:255',
        ]);

        \App\Models\Inventory::create([
            'branch_id'       => $selectedBranch,
            'item_name'       => $validated['item_name'],
            'item_code'       => $validated['item_code'] ?: null,
            'category'        => $validated['category'] ?? null,
            'quantity'        => $validated['quantity'],
            'unit'            => $validated['unit'],
            'low_stock_alert' => $validated['low_stock_alert'] ?? 10,
            'unit_cost'       => $validated['unit_cost'] ?? 0,
            'supplier'        => $validated['supplier'] ?? null,
            'is_active'       => true,
        ]);

        return redirect()->route('admin.inventory')
            ->with('success', 'Item "' . $validated['item_name'] . '" added to inventory!');
    }
    public function updateInventory(Request $request, int $id)
    {
        $item = \App\Models\Inventory::findOrFail($id);
        $validated = $request->validate([
            'item_name'       => 'required|string|max:255',
            'item_code'       => 'nullable|string|max:50',
            'category'        => 'nullable|string|max:100',
            'quantity'        => 'required|numeric|min:0',
            'unit'            => 'required|string|max:20',
            'low_stock_alert' => 'nullable|numeric|min:0',
            'unit_cost'       => 'nullable|numeric|min:0',
            'supplier'        => 'nullable|string|max:255',
        ]);
        $item->update($validated);
        return redirect()->route('admin.inventory')->with('success', 'Inventory item updated!');
    }

    public function stockIn(Request $request, int $id)
    {
        $item = \App\Models\Inventory::findOrFail($id);
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note'   => 'nullable|string|max:255',
        ]);
        $item->quantity += $validated['amount'];
        $item->save();

        \App\Models\StockMovement::create([
            'inventory_id'  => $item->id,
            'movement_type' => 'in',
            'amount'        => $validated['amount'],
            'quantity_after' => $item->quantity,
            'reason'        => $validated['note'] ?? 'Manual stock in',
            'source'        => 'manual',
            'user_id'       => auth()->id(),
        ]);

        return redirect()->route('admin.inventory')
            ->with('success', '+' . $validated['amount'] . ' ' . $item->unit . ' added to "' . $item->item_name . '". New stock: ' . $item->quantity);
    }

    public function stockOut(Request $request, int $id)
    {
        $item = \App\Models\Inventory::findOrFail($id);
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note'   => 'nullable|string|max:255',
        ]);
        if ($validated['amount'] > $item->quantity) {
            return back()->withErrors(['amount' => 'Not enough stock. Only ' . $item->quantity . ' ' . $item->unit . ' available.']);
        }
        $item->quantity -= $validated['amount'];
        $item->save();

        \App\Models\StockMovement::create([
            'inventory_id'  => $item->id,
            'movement_type' => 'out',
            'amount'        => $validated['amount'],
            'quantity_after' => $item->quantity,
            'reason'        => $validated['note'] ?? 'Manual stock out',
            'source'        => 'manual',
            'user_id'       => auth()->id(),
        ]);

        return redirect()->route('admin.inventory')
            ->with('success', '-' . $validated['amount'] . ' ' . $item->unit . ' removed from "' . $item->item_name . '". Remaining: ' . $item->quantity);
    }

    public function editInventory(int $id)
    {
        return response()->json(\App\Models\Inventory::findOrFail($id));
    }

    public function deleteInventory(int $id)
    {
        $item = \App\Models\Inventory::findOrFail($id);
        $name = $item->item_name;
        $item->delete();
        return redirect()->route('admin.inventory')->with('success', 'Item "' . $name . '" deleted!');
    }

    // ══════════ Customization ══════════

    public function updateCustomization(Request $request)
    {
        return redirect()->back()->with('success', 'Staff interface updated.');
    }

    public function updateCustomerCustomization(Request $request)
    {
        return redirect()->back()->with('success', 'Customer interface updated.');
    }

    // ══════════ QR Code Generator ══════════

    public function showQrGenerator()
    {
        $branches = \App\Models\Branch::all();
        return view('admin.qr-generator', compact('branches'));
    }

    // ══════════ Vouchers (CRUD) ══════════

    public function showVouchers()
    {
        $vouchers = \App\Models\Voucher::orderBy('created_at', 'desc')->get();
        return view('admin.vouchers', compact('vouchers'));
    }

    public function storeVoucher(Request $request)
    {
        $validated = $request->validate([
            'code'            => 'required|string|max:50|unique:vouchers,code',
            'description'     => 'nullable|string|max:255',
            'discount_type'   => 'required|in:fixed,percent',
            'discount_value'  => 'required|numeric|min:1',
            'max_uses'        => 'required|integer|min:1',
            'minimum_order'   => 'nullable|numeric|min:0',
            'valid_from'      => 'nullable|date',
            'expires_at'      => 'nullable|date',
            'points_required' => 'nullable|integer|min:0',
        ]);

        \App\Models\Voucher::create([
            'code'            => strtoupper($validated['code']),
            'description'     => $validated['description'] ?? null,
            'discount_type'   => $validated['discount_type'],
            'discount_value'  => $validated['discount_value'],
            'max_uses'        => $validated['max_uses'],
            'minimum_order'   => $validated['minimum_order'] ?? 0,
            'valid_from'      => $validated['valid_from'] ?? null,
            'expires_at'      => $validated['expires_at'] ?? null,
            'points_required' => $validated['points_required'] ?? 0,
            'is_active'       => true,
        ]);

        return redirect()->route('admin.vouchers')
            ->with('success', 'Voucher "' . strtoupper($validated['code']) . '" created!');
    }

    public function updateVoucher(Request $request, int $id)
    {
        $voucher = \App\Models\Voucher::findOrFail($id);

        $validated = $request->validate([
            'description'    => 'nullable|string|max:255',
            'discount_type'  => 'required|in:fixed,percent',
            'discount_value' => 'required|numeric|min:1',
            'max_uses'       => 'required|integer|min:1',
            'minimum_order'  => 'nullable|numeric|min:0',
            'valid_from'     => 'nullable|date',
            'expires_at'     => 'nullable|date',
            'is_active'      => 'nullable|boolean',
        ]);

        $voucher->update([
            'description'    => $validated['description'] ?? null,
            'discount_type'  => $validated['discount_type'],
            'discount_value' => $validated['discount_value'],
            'max_uses'       => $validated['max_uses'],
            'minimum_order'  => $validated['minimum_order'] ?? 0,
            'valid_from'     => $validated['valid_from'] ?? null,
            'expires_at'     => $validated['expires_at'] ?? null,
            'is_active'      => $request->has('is_active') ? 1 : 0,
        ]);

        return redirect()->route('admin.vouchers')
            ->with('success', 'Voucher updated!');
    }

    public function deleteVoucher(int $id)
    {
        $voucher = \App\Models\Voucher::findOrFail($id);
        $code = $voucher->code;
        $voucher->delete();

        return redirect()->route('admin.vouchers')
            ->with('success', 'Voucher "' . $code . '" deleted!');
    }

    public function toggleVoucher(int $id)
    {
        $voucher = \App\Models\Voucher::findOrFail($id);
        $voucher->is_active = !$voucher->is_active;
        $voucher->save();

        $status = $voucher->is_active ? 'activated' : 'deactivated';
        return redirect()->route('admin.vouchers')
            ->with('success', 'Voucher "' . $voucher->code . '" ' . $status . '!');
    }

    public function toggleGame(Request $request)
    {
        $current = \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'game_enabled')->value('value');
        $new = $current === '1' ? '0' : '1';
        \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'game_enabled')->update(['value' => $new]);

        $status = $new === '1' ? 'enabled' : 'disabled';
        return redirect()->route('admin.vouchers')
            ->with('success', 'Game ' . $status . '!');
    }

    public function showReceipt(int $id)
    {
        $order = \App\Models\Order::with(['items.menuItem', 'customer'])->findOrFail($id);
        return view('customer.receipt', compact('order'));
    }

    // ══════════ Branches ══════════

    public function showBranches()
    {
        $branches = \App\Models\Branch::orderBy('created_at')->get();
        return view('admin.branches', compact('branches'));
    }

    public function storeBranch(Request $request)
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'code'           => 'required|string|max:20|unique:branches,code',
            'address'        => 'nullable|string|max:500',
            'contact_number' => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:255',
            'opening_time'   => 'nullable',
            'closing_time'   => 'nullable',
        ]);

        \App\Models\Branch::create([
            'name'           => $validated['name'],
            'code'           => strtoupper($validated['code']),
            'address'        => $validated['address'] ?? null,
            'contact_number' => $validated['contact_number'] ?? null,
            'email'          => $validated['email'] ?? null,
            'opening_time'   => $validated['opening_time'] ?? null,
            'closing_time'   => $validated['closing_time'] ?? null,
            'is_active'      => true,
            'is_main_branch' => false,
        ]);

        return redirect()->route('admin.branches')
            ->with('success', 'Branch "' . $validated['name'] . '" created!');
    }

    public function updateBranch(Request $request, int $id)
    {
        $branch = \App\Models\Branch::findOrFail($id);

        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'address'        => 'nullable|string|max:500',
            'contact_number' => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:255',
            'opening_time'   => 'nullable',
            'closing_time'   => 'nullable',
            'is_active'      => 'nullable|boolean',
        ]);

        $branch->update([
            'name'           => $validated['name'],
            'address'        => $validated['address'] ?? null,
            'contact_number' => $validated['contact_number'] ?? null,
            'email'          => $validated['email'] ?? null,
            'opening_time'   => $validated['opening_time'] ?? null,
            'closing_time'   => $validated['closing_time'] ?? null,
            'is_active'      => $request->has('is_active') ? 1 : 0,
        ]);

        return redirect()->route('admin.branches')
            ->with('success', 'Branch updated!');
    }

    public function deleteBranch(int $id)
    {
        $branch = \App\Models\Branch::findOrFail($id);

        if ($branch->is_main_branch) {
            return redirect()->route('admin.branches')
                ->withErrors(['error' => 'Cannot delete the main branch!']);
        }

        $name = $branch->name;
        $branch->delete();

        return redirect()->route('admin.branches')
            ->with('success', 'Branch "' . $name . '" deleted!');
    }

    public function selectBranch(Request $request)
    {
        $branchId = $request->input('branch_id');
        session()->put('selected_branch_id', $branchId);
        return redirect()->back()->with('success', 'Branch filter applied!');
    }

    // ══════════ Help Requests ══════════

    public function assistHelpRequest(int $id)
    {
        $help = \App\Models\HelpRequest::findOrFail($id);
        $help->status       = 'assisting';
        $help->assisting_at = now();
        $help->save();

        return redirect()->route('admin.home')
            ->with('success', 'Now assisting Table ' . $help->table_number . '!');
    }

    public function resolveHelpRequest(int $id)
    {
        $help = \App\Models\HelpRequest::findOrFail($id);
        $help->status      = 'resolved';
        $help->resolved_at = now();
        $help->save();

        return redirect()->route('admin.home')
            ->with('success', 'Help request for Table ' . $help->table_number . ' resolved!');
    }

    // ══════════ Ads (CRUD) ══════════

    public function showAds()
    {
        $ads = \App\Models\Ad::orderBy('display_order')->orderBy('created_at', 'desc')->get();
        return view('admin.ads', compact('ads'));
    }

    public function storeAd(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'image'       => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'link'        => 'nullable|url|max:500',
            'placement'   => 'required|in:game,menu,cart,orders',
            'starts_at'   => 'nullable|date',
            'ends_at'     => 'nullable|date',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = time() . '_ad_' . preg_replace('/[^A-Za-z0-9\.]/', '_', $file->getClientOriginalName());
            $file->move(public_path('uploads/ads'), $filename);
            $imagePath = 'uploads/ads/' . $filename;
        }

        \App\Models\Ad::create([
            'title'         => $validated['title'],
            'description'   => $validated['description'] ?? null,
            'image'         => $imagePath,
            'link'          => $validated['link'] ?? null,
            'placement'     => $validated['placement'],
            'is_active'     => true,
            'display_order' => 0,
            'starts_at'     => $validated['starts_at'] ?? null,
            'ends_at'       => $validated['ends_at'] ?? null,
        ]);

        return redirect()->route('admin.ads')->with('success', 'Ad "' . $validated['title'] . '" created!');
    }

    public function toggleAd(int $id)
    {
        $ad = \App\Models\Ad::findOrFail($id);
        $ad->is_active = !$ad->is_active;
        $ad->save();

        return redirect()->route('admin.ads')
            ->with('success', 'Ad "' . $ad->title . '" ' . ($ad->is_active ? 'activated' : 'deactivated') . '!');
    }

    public function deleteAd(int $id)
    {
        $ad = \App\Models\Ad::findOrFail($id);
        if ($ad->image && file_exists(public_path($ad->image))) {
            unlink(public_path($ad->image));
        }
        $title = $ad->title;
        $ad->delete();

        return redirect()->route('admin.ads')->with('success', 'Ad "' . $title . '" deleted!');
    }

    public function exportOrders(Request $request)
    {
        $selectedBranch = $this->getSelectedBranch();

        $query = \App\Models\Order::with('items')->where('status', 'completed')
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch));

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $orders = $query->orderBy('created_at', 'desc')->get();

        $filename = 'orders_' . now()->format('Y-m-d') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($orders) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Order #', 'Date', 'Type', 'Table', 'Items', 'Subtotal', 'Discount', 'Total', 'Payment', 'Status']);

            foreach ($orders as $order) {
                $itemsList = $order->items->map(fn($i) => $i->quantity . 'x ' . $i->item_name)->implode(', ');
                fputcsv($file, [
                    $order->order_number,
                    $order->created_at->format('Y-m-d H:i'),
                    $order->type,
                    $order->table_number ?? '-',
                    $itemsList,
                    $order->subtotal,
                    $order->discount_amount,
                    $order->total,
                    $order->payment_method,
                    $order->status,
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}

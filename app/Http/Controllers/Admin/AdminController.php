<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Branch;
use App\Models\Category;
use App\Models\HelpRequest;
use App\Models\Inventory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AnalyticsService;
use App\Support\Img;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
        /** @var User $user */
        $user = auth()->user();

        if (!$user) {
            return redirect()->route('admin.login');
        }

        // Only fields that already exist on the admin/account form are accepted.
        $validated = $request->validate([
            'email'    => 'required|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6',
        ]);

        $user->email = $validated['email'];

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return redirect()->back()->with('success', 'Account updated successfully.');
    }

    // ══════════ Analytics & Recommendations ══════════

    public function showAnalytics()
    {
        $selectedBranch = $this->getSelectedBranch();
        $analytics = new AnalyticsService($selectedBranch);

        $today      = $analytics->salesToday();
        $yesterday  = $analytics->salesYesterday();
        $thisWeek   = $analytics->salesThisWeek();
        $lastWeek   = $analytics->salesLastWeek();
        $thisMonth  = $analytics->salesThisMonth();
        $lastMonth  = $analytics->salesLastMonth();

        $deltaToday = $analytics->percentChange($today, $yesterday);
        $deltaWeek  = $analytics->percentChange($thisWeek, $lastWeek);
        $deltaMonth = $analytics->percentChange($thisMonth, $lastMonth);

        $bestSellers     = $analytics->bestSellers(5);
        $leastSellers    = $analytics->leastSellers(5);
        $outOfStock      = $analytics->outOfStock();
        $lowStock        = $analytics->lowStock();
        $slowMovers      = $analytics->slowMovers()->take(10);
        $linkedToBest    = $analytics->inventoryLinkedToBestSellers();
        $dailyTrend      = $analytics->dailyTrend(14);
        $salesPerBranch  = $analytics->salesPerBranch();
        $salesByCategory = $analytics->salesByCategory();
        $recommendations = $analytics->recommendations();

        $branchName = $selectedBranch === 'all'
            ? 'All Branches'
            : (Branch::where('id', $selectedBranch)->value('name') ?? 'Unknown Branch');

        $isAllBranches = $selectedBranch === 'all';

        return view('admin.analytics', compact(
            'today', 'yesterday', 'thisWeek', 'lastWeek', 'thisMonth', 'lastMonth',
            'deltaToday', 'deltaWeek', 'deltaMonth',
            'bestSellers', 'leastSellers',
            'outOfStock', 'lowStock', 'slowMovers', 'linkedToBest',
            'dailyTrend', 'salesPerBranch', 'salesByCategory', 'recommendations',
            'branchName', 'isAllBranches'
        ));
    }

    public function showSummary(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $selectedBranch = $this->getSelectedBranch();
        $ordersQuery = Order::where('status', 'completed')
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch));
        $itemsQuery = OrderItem::whereHas('order', fn($q) => $q->where('status', 'completed'));

        if ($dateFrom) {
            $ordersQuery->whereDate('created_at', '>=', $dateFrom);
            $itemsQuery->whereHas('order', fn($q) => $q->whereDate('created_at', '>=', $dateFrom));
        }
        if ($dateTo) {
            $ordersQuery->whereDate('created_at', '<=', $dateTo);
            $itemsQuery->whereHas('order', fn($q) => $q->whereDate('created_at', '<=', $dateTo));
        }

        // Total orders today
        $totalOrdersToday = Order::where('status', 'completed')
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch))
            ->whereDate('created_at', today())->count();

        // Total revenue
        $totalRevenue = (clone $ordersQuery)->sum('total');

        // Most & least ordered
        $mostOrdered = (clone $itemsQuery)->select('item_name', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('item_name')->orderByDesc('total_qty')->first();
        $leastOrdered = (clone $itemsQuery)->select('item_name', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('item_name')->orderBy('total_qty')->first();

        $mostOrderedItem = $mostOrdered ? $mostOrdered->item_name . ' (' . $mostOrdered->total_qty . ')' : '-';
        $leastOrderedItem = $leastOrdered ? $leastOrdered->item_name . ' (' . $leastOrdered->total_qty . ')' : '-';

        // Total orders (filtered)
        $totalOrders = (clone $ordersQuery)->count();

        // Peak hours
        $peakHour = Order::where('status', 'completed')
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch))
            ->select(DB::raw('HOUR(created_at) as hour'), DB::raw('COUNT(*) as count'))
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
            $chartData[] = Order::where('status', 'completed')
                ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch))
                ->whereDate('created_at', $date->toDateString())
                ->sum('total');
        }

        // Pie chart - sales by category
        $categoryStats = OrderItem::whereHas('order', fn($q) => $q->where('status', 'completed'))
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->join('categories', 'menu_items.category_id', '=', 'categories.id')
            ->select('categories.name', DB::raw('SUM(order_items.quantity) as total_qty'))
            ->groupBy('categories.name')
            ->orderByDesc('total_qty')
            ->get();

        $pieLabels = $categoryStats->pluck('name')->toArray();
        $pieData = $categoryStats->pluck('total_qty')->toArray();

        if (empty($pieLabels)) {
            $pieLabels = ['No data'];
            $pieData = [1];
        }

        // Resolve the human-readable branch name for the printable header.
        if ($selectedBranch === 'all') {
            $selectedBranchName = 'All Branches';
        } else {
            $selectedBranchName = Branch::where('id', $selectedBranch)->value('name') ?? 'Unknown Branch';
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
            'pieData',
            'selectedBranchName',
            'dateFrom',
            'dateTo'
        ));
    }

    // ══════════ Menu Items (CRUD WORKING) ══════════

    public function showMenuItems()
    {
        $selectedBranch = $this->getSelectedBranch();

        $menuItems = MenuItem::with(['category', 'subcategory', 'inventoryItem', 'branch', 'recipeIngredients.inventory'])
            ->when($selectedBranch !== 'all', function ($q) use ($selectedBranch) {
                $q->where('branch_id', $selectedBranch);
            })
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $branches = Branch::where('is_active', true)->orderBy('id')->get();
        $categories = Category::orderBy('name')->get();
        $subcategories = Subcategory::orderBy('name')->get();

        $inventoryItems = Inventory::where('is_active', true)
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
        $subcategories = Subcategory::orderBy('name')->get();

        $inventoryItems = Inventory::where('is_active', true)
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
            $inventory = Inventory::find($validated['inventory_item_id']);

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
            $imagePath = $file->storeAs('uploads/menu-items', $filename, 'public');
        }

        MenuItem::create([
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
        $menuItem = MenuItem::findOrFail($id);
        $categories = Category::orderBy('name')->get();
        $subcategories = Subcategory::orderBy('name')->get();

        $selectedBranch = $this->getSelectedBranch();

        if ($selectedBranch === 'all') {
            return redirect()->route('admin.menu-items')
                ->withErrors(['branch' => 'Please select the item branch before editing inventory-linked menu items.']);
        }

        $inventoryItems = Inventory::where('is_active', true)
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
        $menuItem = MenuItem::findOrFail($id);

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
            $inventory = Inventory::find($validated['inventory_item_id']);

            if (!$inventory || (int)$inventory->branch_id !== (int)$selectedBranch) {
                return back()->withErrors([
                    'inventory_item_id' => 'Selected inventory item must belong to the selected branch.'
                ])->withInput();
            }
        }

        if ($request->hasFile('image')) {
            Img::delete($menuItem->image);
            $file = $request->file('image');
            $filename = time() . '_' . preg_replace('/[^A-Za-z0-9\.]/', '_', $file->getClientOriginalName());
            $menuItem->image = $file->storeAs('uploads/menu-items', $filename, 'public');
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
        $menuItem = MenuItem::findOrFail($id);
        $menuItem->is_available = !$menuItem->is_available;
        $menuItem->save();

        $status = $menuItem->is_available ? 'made available' : 'hidden from menu';
        return redirect()->back()
            ->with('success', 'Item "' . $menuItem->name . '" ' . $status . '.');
    }

    public function deleteMenuItem(int $id)
    {
        $menuItem = MenuItem::findOrFail($id);

        $hasOrders = OrderItem::where('menu_item_id', $id)->exists();
        if ($hasOrders) {
            $menuItem->is_available = false;
            $menuItem->save();
            return redirect()->route('admin.menu-items')
                ->with('success', 'Item "' . $menuItem->name . '" hidden from menu (has existing orders).');
        }

        Img::delete($menuItem->image);

        $name = $menuItem->name;
        $menuItem->delete();

        return redirect()->route('admin.menu-items')
            ->with('success', 'Menu item "' . $name . '" deleted successfully!');
    }

    // ══════════ Categories (CRUD WORKING) ══════════

    public function showAddCategory()
    {
        $categories = Category::with('menuItems')->orderBy('display_order')->orderBy('name')->get();
        $subcategories = Subcategory::with('category')->orderBy('name')->get();
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
            $imagePath = $file->storeAs('uploads/categories', $filename, 'public');
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
            Img::delete($category->image);
            $file = $request->file('image');
            $filename = time() . '_cat_' . preg_replace('/[^A-Za-z0-9\.]/', '_', $file->getClientOriginalName());
            $category->image = $file->storeAs('uploads/categories', $filename, 'public');
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

        $itemCount = MenuItem::where('category_id', $id)->count();
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
        $subcategories = Subcategory::with('category')
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

        $exists = Subcategory::where('category_id', $validated['category_id'])
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'name' => 'This subcategory already exists in the selected category.',
            ])->withInput();
        }

        Subcategory::create([
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
        $subcategory = Subcategory::findOrFail($id);
        $subcategories = Subcategory::with('category')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
        $categories = Category::orderBy('name')->get();
        return view('admin.add-subcategory', compact('subcategories', 'categories', 'subcategory'));
    }

    public function updateSubcategory(Request $request, int $id)
    {
        $subcategory = Subcategory::findOrFail($id);

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
        $subcategory = Subcategory::findOrFail($id);
        $name = $subcategory->name;
        $subcategory->delete();

        return redirect()->route('admin.add-subcategory')
            ->with('success', 'Subcategory "' . $name . '" deleted successfully!');
    }

    // ══════════ Menu Options ══════════

    public function showMenuOptions()
    {
        $options = MenuOption::with('ingredients.inventory')->orderBy('name')->get();
        $categories = Category::with(['menuItems'])->orderBy('name')->get();
        $inventoryItems = Inventory::where('is_active', true)->orderBy('item_name')->get();
        return view('admin.menu-options', compact('options', 'categories', 'inventoryItems'));
    }

    public function storeMenuOption(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'price'       => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ]);

        MenuOption::create([
            'name'             => $validated['name'],
            'additional_price' => $validated['price'] ?? 0,
            'is_active'        => true,
            'display_order'    => 0,
        ]);

        return redirect()->route('admin.menu-options')->with('success', 'Option added!');
    }

    public function deleteMenuOption(int $id)
    {
        $option = MenuOption::findOrFail($id);
        $option->delete();
        return redirect()->route('admin.menu-options')->with('success', 'Option deleted!');
    }

    public function assignOptions(Request $request, int $menuItemId)
    {
        $menuItem = MenuItem::findOrFail($menuItemId);
        $optionIds = $request->input('option_ids', []);
        $menuItem->options()->sync($optionIds);
        return response()->json(['success' => true]);
    }

    // ══════════ Orders Management ══════════

    public function showHome()
    {
        $selectedBranch = $this->getSelectedBranch();

        $pendingOrders = Order::with(['items.options', 'customer'])
            ->whereIn('status', ['pending', 'preparing', 'serving'])
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch))
            ->orderBy('created_at', 'asc')
            ->get();

        $helpRequests = HelpRequest::with(['branch', 'order'])
            ->whereIn('status', ['pending', 'assisting'])
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch))
            ->orderBy('requested_at', 'asc')
            ->get();

        return view('admin.home', compact('pendingOrders', 'helpRequests'));
    }

    public function showCompletedOrders(Request $request)
    {
        $selectedBranch = $this->getSelectedBranch();

        $query = Order::with(['items', 'customer'])
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

        // Human-readable branch name for the printable report header.
        if ($selectedBranch === 'all') {
            $selectedBranchName = 'All Branches';
        } else {
            $selectedBranchName = Branch::where('id', $selectedBranch)->value('name') ?? 'Unknown Branch';
        }

        return view('admin.completed-orders', compact('orders', 'selectedBranchName'));
    }

    public function showOrderDetail(int $id)
    {
        // No dedicated order-detail view exists; the receipt view already
        // presents the full order breakdown and a print action.
        return redirect()->route('admin.receipt', $id);
    }

    /**
     * Ensure the current admin/staff is allowed to act on this order.
     * Staff are locked to their assigned branch; admins viewing a specific
     * branch are limited to that branch. Admins on "all" can act on anything.
     */
    private function guardOrderBranch(Order $order): void
    {
        $selectedBranch = $this->getSelectedBranch();
        if ($selectedBranch === 'all') {
            return;
        }
        if ((int) $order->branch_id !== (int) $selectedBranch) {
            abort(403, 'You are not allowed to modify orders from another branch.');
        }
    }

    public function completeOrder(int $id)
    {
        // Branch guard runs first so a forbidden user gets 403 before we touch DB.
        $order = Order::findOrFail($id);
        $this->guardOrderBranch($order);

        $service = new \App\Services\InventoryDeductionService();

        try {
            $result = DB::transaction(function () use ($id, $service) {
                // Re-fetch with lockForUpdate INSIDE the transaction so two concurrent
                // "complete" clicks on the same order can't both pass the status check.
                $order = Order::with(['items.menuItem.recipeIngredients', 'items.menuItem.inventoryItem', 'items.options'])
                    ->lockForUpdate()
                    ->findOrFail($id);

                if (!in_array($order->status, ['pending', 'preparing', 'serving'])) {
                    // Already completed/cancelled — second click is a no-op.
                    throw new \RuntimeException('Order is not pending. Current status: ' . $order->status);
                }

                // Locks inventory rows, re-validates, deducts, writes stock_movements.
                // Throws RuntimeException on any shortage → transaction rolls back.
                $deductionsLog = $service->deductWithLock($order);

                // Increment total_sold AFTER successful deduction.
                foreach ($order->items as $orderItem) {
                    if ($orderItem->menu_item_id) {
                        MenuItem::where('id', $orderItem->menu_item_id)
                            ->increment('total_sold', (int) $orderItem->quantity);
                    }
                }

                $order->status         = 'completed';
                $order->payment_status = 'paid';
                $order->receipt_number = $order->receipt_number ?? 'RCP-' . now()->format('Ymd') . '-' . str_pad($order->id, 4, '0', STR_PAD_LEFT);
                $order->completed_at   = $order->completed_at ?? now();
                $order->processed_by   = $order->processed_by ?? auth()->id();
                $order->save();

                return ['order' => $order, 'log' => $deductionsLog];
            });
        } catch (\RuntimeException $e) {
            return redirect()->back()->withErrors(['stock' => $e->getMessage()]);
        }

        $message = 'Order #' . $result['order']->order_number . ' completed!';
        if (!empty($result['log'])) {
            $message .= ' Inventory deducted: ' . implode(', ', $result['log']);
        }
        return redirect()->route('admin.home')->with('success', $message);
    }

    public function prepareOrder(int $id)
    {
        $order = Order::findOrFail($id);
        $this->guardOrderBranch($order);
        $order->status       = 'preparing';
        $order->preparing_at = $order->preparing_at ?? now();
        $order->processed_by = $order->processed_by ?? auth()->id();
        $order->save();

        return redirect()->route('admin.home')
            ->with('success', 'Order #' . $order->order_number . ' is now being prepared!');
    }

    public function serveOrder(int $id)
    {
        $order = Order::findOrFail($id);
        $this->guardOrderBranch($order);
        $order->status       = 'serving';
        $order->serving_at   = $order->serving_at ?? now();
        $order->processed_by = $order->processed_by ?? auth()->id();
        $order->save();

        return redirect()->route('admin.home')
            ->with('success', 'Order #' . $order->order_number . ' is now being served!');
    }

    public function cancelOrder(int $id)
    {
        $order = Order::findOrFail($id);
        $this->guardOrderBranch($order);
        $order->status       = 'cancelled';
        $order->cancelled_at = $order->cancelled_at ?? now();
        $order->processed_by = $order->processed_by ?? auth()->id();
        $order->save();

        return redirect()->route('admin.home')
            ->with('success', 'Order #' . $order->order_number . ' cancelled.');
    }

    /**
     * Apply a Senior or PWD discount to ONE selected item of an order.
     * 20% off the chosen item's line amount only — never the whole order.
     * Uses existing columns only (discount_type, discount_amount, total).
     */
    public function applySeniorPwdDiscount(Request $request, int $id)
    {
        $order = Order::with('items')->findOrFail($id);
        $this->guardOrderBranch($order);

        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            return redirect()->route('admin.home')
                ->with('error', 'Cannot change the discount of a completed or cancelled order.');
        }

        // Block stacking on top of an existing voucher discount.
        $hasVoucherDiscount = (float) $order->discount_amount > 0
            && !in_array($order->discount_type, ['senior', 'pwd'], true);
        if ($hasVoucherDiscount) {
            return redirect()->route('admin.home')
                ->with('error', 'This order already has a voucher discount. Remove the voucher discount first before applying Senior/PWD discount.');
        }

        $validated = $request->validate([
            'discount_type' => 'required|in:senior,pwd',
            'order_item_id' => 'required|integer',
        ]);

        $item = $order->items->firstWhere('id', (int) $validated['order_item_id']);
        if (!$item) {
            return redirect()->route('admin.home')
                ->with('error', 'Selected item does not belong to this order.');
        }

        // 20% off the selected item line only.
        $base     = (float) $item->item_price * (int) $item->quantity;
        $discount = round($base * 0.20, 2);
        $discount = min($discount, (float) $order->subtotal); // never exceed subtotal

        $order->discount_type   = $validated['discount_type'];
        $order->discount_amount = $discount;
        $order->total           = max((float) $order->subtotal - $discount, 0);
        $order->processed_by    = $order->processed_by ?? auth()->id();
        $order->save();

        $label = $validated['discount_type'] === 'senior' ? 'Senior' : 'PWD';
        return redirect()->route('admin.home')
            ->with('success', $label . ' discount applied to Order #' . $order->order_number . ' (-₱' . number_format($discount, 2) . ').');
    }

    /**
     * Remove a Senior/PWD discount from an order. Voucher discounts are left intact.
     */
    public function removeSeniorPwdDiscount(int $id)
    {
        $order = Order::findOrFail($id);
        $this->guardOrderBranch($order);

        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            return redirect()->route('admin.home')
                ->with('error', 'Cannot change the discount of a completed or cancelled order.');
        }

        if (!in_array($order->discount_type, ['senior', 'pwd'], true)) {
            return redirect()->route('admin.home')
                ->with('error', 'This order has no Senior/PWD discount to remove.');
        }

        $order->discount_type   = null;
        $order->discount_amount = 0;
        $order->total           = $order->subtotal;
        $order->processed_by    = $order->processed_by ?? auth()->id();
        $order->save();

        return redirect()->route('admin.home')
            ->with('success', 'Senior/PWD discount removed from Order #' . $order->order_number . '.');
    }

    // ══════════ Inventory (CRUD WORKING) ══════════

    public function showInventory()
    {
        $selectedBranch = $this->getSelectedBranch();
        $inventory = Inventory::orderBy('item_name')
            ->when($selectedBranch !== 'all', fn($q) => $q->where('branch_id', $selectedBranch))
            ->get();
        $categories = Category::orderBy('name')->get();
        $stockMovements = StockMovement::with(['inventory', 'user'])
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
            'unit'            => ['required', 'string', \Illuminate\Validation\Rule::in(Inventory::ALLOWED_UNITS)],
            'low_stock_alert' => 'nullable|numeric|min:0',
            'unit_cost'       => 'nullable|numeric|min:0',
            'supplier'        => 'nullable|string|max:255',
        ]);

        Inventory::create([
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
        $item = Inventory::findOrFail($id);
        $validated = $request->validate([
            'item_name'       => 'required|string|max:255',
            'item_code'       => 'nullable|string|max:50',
            'category'        => 'nullable|string|max:100',
            'quantity'        => 'required|numeric|min:0',
            'unit'            => ['required', 'string', \Illuminate\Validation\Rule::in(Inventory::ALLOWED_UNITS)],
            'low_stock_alert' => 'nullable|numeric|min:0',
            'unit_cost'       => 'nullable|numeric|min:0',
            'supplier'        => 'nullable|string|max:255',
        ]);
        $item->update($validated);
        return redirect()->route('admin.inventory')->with('success', 'Inventory item updated!');
    }

    public function stockIn(Request $request, int $id)
    {
        $item = Inventory::findOrFail($id);
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note'   => 'nullable|string|max:255',
        ]);
        $item->quantity += $validated['amount'];
        $item->save();

        StockMovement::create([
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
        $item = Inventory::findOrFail($id);
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note'   => 'nullable|string|max:255',
        ]);
        if ($validated['amount'] > $item->quantity) {
            return back()->withErrors(['amount' => 'Not enough stock. Only ' . $item->quantity . ' ' . $item->unit . ' available.']);
        }
        $item->quantity -= $validated['amount'];
        $item->save();

        StockMovement::create([
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
        return response()->json(Inventory::findOrFail($id));
    }

    public function deleteInventory(int $id)
    {
        $item = Inventory::findOrFail($id);
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
        $branches = Branch::all();
        return view('admin.qr-generator', compact('branches'));
    }

    // ══════════ Vouchers (CRUD) ══════════

    public function showVouchers()
    {
        $vouchers = Voucher::orderBy('created_at', 'desc')->get();
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

        Voucher::create([
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
        $voucher = Voucher::findOrFail($id);

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
        $voucher = Voucher::findOrFail($id);
        $code = $voucher->code;
        $voucher->delete();

        return redirect()->route('admin.vouchers')
            ->with('success', 'Voucher "' . $code . '" deleted!');
    }

    public function toggleVoucher(int $id)
    {
        $voucher = Voucher::findOrFail($id);
        $voucher->is_active = !$voucher->is_active;
        $voucher->save();

        $status = $voucher->is_active ? 'activated' : 'deactivated';
        return redirect()->route('admin.vouchers')
            ->with('success', 'Voucher "' . $voucher->code . '" ' . $status . '!');
    }

    public function toggleGame(Request $request)
    {
        $current = DB::table('settings')
            ->where('key', 'game_enabled')->value('value');
        $new = $current === '1' ? '0' : '1';

        DB::table('settings')->updateOrInsert(
            ['key' => 'game_enabled', 'branch_id' => null],
            [
                'value'      => $new,
                'group'      => 'general',
                'type'       => 'boolean',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $status = $new === '1' ? 'enabled' : 'disabled';
        return redirect()->route('admin.vouchers')
            ->with('success', 'Game ' . $status . '!');
    }

    public function showReceipt(int $id)
    {
        $order = Order::with(['items.menuItem', 'items.options', 'customer'])->findOrFail($id);
        $isAdminView = true;
        return view('customer.receipt', compact('order', 'isAdminView'));
    }

    // ══════════ Branches ══════════

    public function showBranches()
    {
        $branches = Branch::orderBy('created_at')->get();
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

        Branch::create([
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
        $branch = Branch::findOrFail($id);

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
        $branch = Branch::findOrFail($id);

        if ($branch->is_main_branch) {
            return redirect()->route('admin.branches')
                ->withErrors(['error' => 'Cannot delete the main branch!']);
        }

        $name = $branch->name;
        $branch->delete();

        return redirect()->route('admin.branches')
            ->with('success', 'Branch "' . $name . '" deleted!');
    }

    /**
     * Toggle a branch between Open (is_active=1) and Closed (is_active=0).
     * "Closed" is a UI label only — branch data is preserved.
     */
    public function toggleBranch(int $id)
    {
        $branch = Branch::findOrFail($id);
        $branch->is_active = !$branch->is_active;
        $branch->save();

        // If a customer has this branch in session and it is now closed,
        // they'll be redirected by the ordering guards on their next request.

        $status = $branch->is_active ? 'Open' : 'Closed';
        return redirect()->route('admin.branches')
            ->with('success', 'Branch "' . $branch->name . '" is now ' . $status . '.');
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
        $help = HelpRequest::findOrFail($id);
        $help->status       = 'assisting';
        $help->assisting_at = now();
        $help->save();

        return redirect()->route('admin.home')
            ->with('success', 'Now assisting Table ' . $help->table_number . '!');
    }

    public function resolveHelpRequest(int $id)
    {
        $help = HelpRequest::findOrFail($id);
        $help->status      = 'resolved';
        $help->resolved_at = now();
        $help->save();

        return redirect()->route('admin.home')
            ->with('success', 'Help request for Table ' . $help->table_number . ' resolved!');
    }

    // ══════════ Ads (CRUD) ══════════

    public function showAds()
    {
        $ads = Ad::orderBy('display_order')->orderBy('created_at', 'desc')->get();
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
            $imagePath = $file->storeAs('uploads/ads', $filename, 'public');
        }

        Ad::create([
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
        $ad = Ad::findOrFail($id);
        $ad->is_active = !$ad->is_active;
        $ad->save();

        return redirect()->route('admin.ads')
            ->with('success', 'Ad "' . $ad->title . '" ' . ($ad->is_active ? 'activated' : 'deactivated') . '!');
    }

    public function deleteAd(int $id)
    {
        $ad = Ad::findOrFail($id);
        Img::delete($ad->image);
        $title = $ad->title;
        $ad->delete();

        return redirect()->route('admin.ads')->with('success', 'Ad "' . $title . '" deleted!');
    }

    public function exportOrders(Request $request)
    {
        $selectedBranch = $this->getSelectedBranch();

        $query = Order::with('items')->where('status', 'completed')
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

    // ══════════ Staff Account Management (admin-only) ══════════
    //
    // Staff users are NEVER created by self-registration. An admin creates
    // them here. The 'role' column is hardcoded to 'staff' below — it is
    // never read from the request, so a tampered form cannot mint admins.

    public function showUsers()
    {
        $staff = User::where('role', 'staff')
            ->with('branch')
            ->orderBy('name')
            ->get();

        $branches = Branch::orderBy('name')->get();

        return view('admin.users', compact('staff', 'branches'));
    }

    public function storeStaff(Request $request)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|max:255|unique:users,email',
            'password'   => 'required|string|min:8|confirmed',
            'branch_id'  => 'required|integer|exists:branches,id',
        ], [
            'password.min'       => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'branch_id.required' => 'Please assign the staff to a branch.',
        ]);

        User::create([
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'password'   => Hash::make($validated['password']),
            'role'       => 'staff',           // hardcoded — never from request
            'branch_id'  => $validated['branch_id'],
            'is_active'  => true,
        ]);

        return redirect()->route('admin.users')
            ->with('success', 'Staff account "' . $validated['name'] . '" created.');
    }

    public function toggleStaffActive(int $id)
    {
        $user = User::where('role', 'staff')->findOrFail($id);
        $user->is_active = !$user->is_active;
        $user->save();

        $status = $user->is_active ? 'reactivated' : 'deactivated';
        return redirect()->route('admin.users')
            ->with('success', 'Staff "' . $user->name . '" ' . $status . '.');
    }

    // ══════════ Menu Item Recipe Ingredients ══════════

    public function addMenuItemIngredient(Request $request, int $id)
    {
        $menuItem = MenuItem::findOrFail($id);

        $validated = $request->validate([
            'inventory_id'   => 'required|integer|exists:inventory,id',
            'quantity_used'  => 'required|numeric|min:0.001',
        ]);

        \App\Models\MenuItemIngredient::updateOrCreate(
            [
                'menu_item_id' => $menuItem->id,
                'inventory_id' => $validated['inventory_id'],
            ],
            ['quantity_used' => $validated['quantity_used']]
        );

        return redirect()->route('admin.menu-items')
            ->with('success', 'Recipe ingredient saved.')
            ->with('open_recipe_for', $menuItem->id);
    }

    public function deleteMenuItemIngredient(int $id, int $ingredientId)
    {
        $ingredient = \App\Models\MenuItemIngredient::where('menu_item_id', $id)
            ->where('id', $ingredientId)
            ->firstOrFail();
        $ingredient->delete();

        return redirect()->route('admin.menu-items')
            ->with('success', 'Recipe ingredient removed.')
            ->with('open_recipe_for', $id);
    }

    // ══════════ Menu Option Ingredients ══════════

    public function addMenuOptionIngredient(Request $request, int $id)
    {
        $option = MenuOption::findOrFail($id);

        $validated = $request->validate([
            'inventory_id'   => 'required|integer|exists:inventory,id',
            'quantity_used'  => 'required|numeric|min:0.001',
        ]);

        \App\Models\MenuOptionIngredient::updateOrCreate(
            [
                'menu_option_id' => $option->id,
                'inventory_id'   => $validated['inventory_id'],
            ],
            ['quantity_used' => $validated['quantity_used']]
        );

        return redirect()->route('admin.menu-options')
            ->with('success', 'Option ingredient saved.');
    }

    public function deleteMenuOptionIngredient(int $id, int $ingredientId)
    {
        $ingredient = \App\Models\MenuOptionIngredient::where('menu_option_id', $id)
            ->where('id', $ingredientId)
            ->firstOrFail();
        $ingredient->delete();

        return redirect()->route('admin.menu-options')
            ->with('success', 'Option ingredient removed.');
    }
}

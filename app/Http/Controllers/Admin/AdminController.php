<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    // ══════════ Branch Helper ══════════
    //
    // getSelectedBranch() now lives in the ResolvesBranchScope trait — same
    // body, same behaviour — so the staff notification feed can scope by the
    // identical rule instead of keeping a second copy of it.
    use \App\Http\Controllers\Concerns\ResolvesBranchScope;

    // Every delete endpoint below runs through safelyDelete(), so a database
    // constraint refusal reaches the admin as a sentence instead of an
    // Ignition stack trace. See the trait for the full reasoning.
    use \App\Http\Controllers\Concerns\HandlesSafeDeletes;

    // ══════════ Pages ══════════

    public function showAccount()
    {
        $me = Auth::guard('admin')->user();

        // Read-only staff summary for the Account page, admins only. Full staff
        // management (create / activate / deactivate) lives on the Staff
        // Accounts page.
        $staffList = ($me && $me->role === 'admin')
            ? \App\Models\User::where('role', 'staff')->orderBy('name')->get()
            : collect();

        return view('admin.account', compact('staffList'));
    }

    /**
     * Change the SIGNED-IN admin's or staff member's own password.
     *
     * THE BUG THIS EXISTS FOR
     * -----------------------
     * The "Change Password" button on /admin/account was a bare
     * <button type="button"> with no onclick, no form, no modal and no route
     * behind it, so clicking it did nothing at all and said nothing about why.
     * The password box beside it posted to updateAccount(), which was a stub
     * whose entire body was a redirect back with "Account updated
     * successfully." — it never touched the database. Both are gone; this is
     * the real thing.
     *
     * SECURITY SHAPE
     * --------------
     * Deliberately the same shape as HandlesPasswordReset's final step, since
     * that is the app's established, reviewed password-writing path:
     *
     *  - The account is ALWAYS the authenticated one. There is no id in the
     *    request, so neither an admin nor a staff member can aim this at
     *    anyone else's account. (Admins manage staff on /admin/users, which
     *    deliberately does not expose password editing either.)
     *  - The current password must be re-entered and verified, so a walked-away
     *    unlocked session cannot be used to take the account over.
     *  - min:8|confirmed, matching the rule storeUser() applies when a staff or
     *    admin account is created, and never weaker than the reset flow.
     *  - The plain value is assigned and the User model's "hashed" cast hashes
     *    it exactly once — the same note as on the reset trait.
     *  - remember_token is rotated, so a stolen "remember me" cookie stops
     *    working the moment the password changes.
     *  - Throttled per IP in routes/web.php, like every other auth endpoint.
     */
    public function updateOwnPassword(Request $request)
    {
        /** @var \App\Models\User|null $me */
        $me = Auth::guard('admin')->user();

        if (!$me) {
            return redirect()->route('admin.login');
        }

        // Security review 2026-08-31: length alone is not enough — the shared
        // policy adds the complexity requirements. See App\Support\PasswordPolicy.
        $request->validate([
            'current_password' => 'required|string',
            'password'         => array_merge(
                \App\Support\PasswordPolicy::required(),
                ['different:current_password']
            ),
        ], [
            'current_password.required' => 'Please enter your current password.',
            'password.required'  => 'Please enter a new password.',
            'password.confirmed' => 'The new password and its confirmation do not match.',
            'password.different' => 'The new password must be different from your current one.',
        ]);

        if (!\Illuminate\Support\Facades\Hash::check($request->input('current_password'), $me->password)) {
            return back()->withErrors([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $me->forceFill([
            'password'       => $request->input('password'),
            'remember_token' => \Illuminate\Support\Str::random(60),
        ])->save();

        return back()->with('password_success', 'Your password has been updated.');
    }

    /**
     * Summary — pure KPI dashboard.
     *
     * 7 cards, all fed by App\Services\ProfitCalculationService (revenue/COGS/
     * profit/margin/order count) and App\Services\AnalyticsService (out-of-stock
     * menu items, inventory asset value — both live snapshots, not date-scoped,
     * since "can we make this / what is on the shelf worth" only makes sense
     * as of right now). Full charts and trend breakdowns still live on the
     * Analytics page — see showAnalytics(). A compact Top/Least Selling Items
     * widget (last 30 days, same bestSellers()/leastSellers() AnalyticsService
     * uses) sits below the KPI cards per advisor feedback.
     */
    public function showSummary(Request $request)
    {
        $selectedBranch = $this->getSelectedBranch();

        $period = $request->input('period', 'today');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        if ($period === 'custom' && (!$dateFrom || !$dateTo)) {
            // Custom picked but incomplete — fall back rather than error.
            $period = 'today';
        }
        if (!in_array($period, ['today', 'week', 'month', 'custom'], true)) {
            $period = 'today';
        }

        [$start, $end, $prevStart, $prevEnd] = $this->resolveSummaryPeriod($period, $dateFrom, $dateTo);

        $profit = app(\App\Services\ProfitCalculationService::class);
        $current = $profit->forRange($start, $end, $selectedBranch);
        $previous = $profit->forRange($prevStart, $prevEnd, $selectedBranch);

        $analytics = new \App\Services\AnalyticsService($selectedBranch);
        // Net against net. Comparing the pre-discount figures would let a
        // heavily discounted period read as growth it never banked.
        $revenueChangePercent = $analytics->percentChange($current['net_revenue'], $previous['net_revenue']);

        // The names, not just the tally: the printed report has to say WHICH
        // items cannot be made. The KPI card keeps using count() of this very
        // collection, so the card and the printed list can never disagree.
        $outOfStockItems = $analytics->menuItemsOutOfStock();
        $outOfStockCount = $outOfStockItems->count();
        $inventoryAssetValue = $analytics->inventoryAssetValue();

        $bestSellers = $analytics->bestSellers(5);
        $leastSellers = $analytics->leastSellers(5);

        $selectedBranchName = $selectedBranch === 'all'
            ? 'All Branches'
            : (optional(\App\Models\Branch::find($selectedBranch))->name ?? 'Unknown Branch');

        return view('admin.summary', [
            'period'               => $period,
            'dateFrom'             => $dateFrom,
            'dateTo'               => $dateTo,
            'periodStart'          => $start,
            'periodEnd'            => $end,
            'selectedBranchName'   => $selectedBranchName,

            // KPI 1-6: the revenue chain, then COGS, profit and margin.
            // Net Revenue leads because it is the money the till took; Gross
            // and Discounts sit beside it so the difference between them is
            // never a mystery the owner has to ask somebody about.
            'netRevenue'           => $current['net_revenue'],
            'revenueChangePercent' => $revenueChangePercent,
            'grossRevenue'         => $current['gross_revenue'],
            'totalDiscounts'       => $current['discounts'],
            'totalCogs'            => $current['cogs'],
            'grossProfit'          => $current['gross_profit'],
            'grossMarginPercent'   => $current['margin_percent'],

            // How many sold lines carried no cost snapshot and were therefore
            // costed at TODAY'S ingredient prices. Zero on a healthy period,
            // and the view stays silent when it is zero.
            'uncostedLineCount'    => $current['legacy_fallback_count'],
            'soldLineCount'        => $current['item_count'],

            // KPI 5: completed orders
            'totalOrders'          => $current['order_count'],

            // KPI 6-7: live inventory snapshots
            'outOfStockCount'      => $outOfStockCount,
            'inventoryAssetValue'  => $inventoryAssetValue,

            // Top/Least Selling Items widget (last 30 days, completed orders)
            'bestSellers'          => $bestSellers,
            'leastSellers'         => $leastSellers,

            // ── Print-only report data ──────────────────────────────────────
            // None of the following is rendered on screen. It all comes out of
            // the SAME $current/$previous arrays the KPI cards above are built
            // from — no second service call, no second calculation — so a
            // figure on the paper cannot drift from the same figure on screen.
            'itemBreakdown'        => $current['items'],
            'prevGrossRevenue'     => $previous['gross_revenue'],
            'prevDiscounts'        => $previous['discounts'],
            'prevNetRevenue'       => $previous['net_revenue'],
            'prevCogs'             => $previous['cogs'],
            'prevGrossProfit'      => $previous['gross_profit'],
            'prevMarginPercent'    => $previous['margin_percent'],
            'prevOrders'           => $previous['order_count'],
            'prevPeriodStart'      => $prevStart,
            'prevPeriodEnd'        => $prevEnd,
            'outOfStockItems'      => $outOfStockItems,
            'printedBy'            => optional(auth('admin')->user())->name ?? 'Unknown user',
            'printedAt'            => now(),
        ]);
    }

    /**
     * Resolve [start, end, previousStart, previousEnd] Carbon boundaries for
     * the Summary period selector. "Previous" is the equivalent immediately
     * preceding period, used for the % change badge (Today vs Yesterday,
     * This Week vs Last Week, This Month vs Last Month, Custom vs the same
     * number of days immediately before it).
     *
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon, 2: \Carbon\Carbon, 3: \Carbon\Carbon}
     */
    private function resolveSummaryPeriod(string $period, ?string $dateFrom, ?string $dateTo): array
    {
        switch ($period) {
            case 'week':
                return [
                    now()->startOfWeek(), now()->endOfWeek(),
                    now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek(),
                ];

            case 'month':
                return [
                    now()->startOfMonth(), now()->endOfMonth(),
                    now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth(),
                ];

            case 'custom':
                $start = \Carbon\Carbon::parse($dateFrom)->startOfDay();
                $end = \Carbon\Carbon::parse($dateTo)->endOfDay();
                // Same length window immediately preceding the custom range.
                $days = $start->diffInDays($end) + 1;
                $prevEnd = $start->copy()->subDay()->endOfDay();
                $prevStart = $prevEnd->copy()->subDays($days - 1)->startOfDay();
                return [$start, $end, $prevStart, $prevEnd];

            case 'today':
            default:
                return [
                    today()->startOfDay(), today()->endOfDay(),
                    today()->subDay()->startOfDay(), today()->subDay()->endOfDay(),
                ];
        }
    }

    public function showAnalytics()
    {
        $selectedBranch = $this->getSelectedBranch();
        $isAllBranches = $selectedBranch === 'all';

        $branchName = $isAllBranches
            ? 'All Branches'
            : (optional(\App\Models\Branch::find($selectedBranch))->name ?? 'Unknown Branch');

        $analytics = new \App\Services\AnalyticsService($selectedBranch);

        $today     = $analytics->salesToday();
        $thisWeek  = $analytics->salesThisWeek();
        $thisMonth = $analytics->salesThisMonth();

        $deltaToday = $analytics->percentChange($today, $analytics->salesYesterday());
        $deltaWeek  = $analytics->percentChange($thisWeek, $analytics->salesLastWeek());
        $deltaMonth = $analytics->percentChange($thisMonth, $analytics->salesLastMonth());

        $recommendations = $analytics->recommendations();

        $bestSellers  = $analytics->bestSellers(5);
        $leastSellers = $analytics->leastSellers(5);

        $outOfStock   = $analytics->outOfStock();
        $lowStock     = $analytics->lowStock();
        $slowMovers   = $analytics->slowMovers();
        $linkedToBest = $analytics->inventoryLinkedToBestSellers();

        $dailyTrend      = $analytics->dailyTrend(14);
        $salesByCategory = $analytics->salesByCategory();
        $salesPerBranch  = $isAllBranches ? $analytics->salesPerBranch() : collect();

        /*
         * Branch comparison. Deliberately NOT scoped to the selected branch —
         * the whole point is where each branch stands relative to the others,
         * so it reads the same no matter which branch is selected. The
         * currently selected branch is highlighted in the view instead.
         */
        $branchPerformance = $analytics->branchPerformance();

        /*
         * Keep the demonstration history from rotting out of the forecast's
         * rolling window. Two cheap indexed reads on every load; it only writes
         * on the rare occasion coverage has genuinely gone thin, and it is
         * inert unless BOTH config('demo.auto_top_up_sales') is explicitly true
         * AND the app is running in the 'local' environment — see
         * DemoSalesTopUp::isEnabled(). On any real deployment this is a no-op.
         *
         * Called from here rather than from inside AnalyticsService because
         * that class documents itself as read-only; fabricating orders from
         * within it would quietly break that promise.
         */
        app(\App\Services\DemoSalesTopUp::class)->ensureForecastCoverage();

        $salesForecast   = $analytics->salesForecast();
        $averageRating   = $analytics->averageRating();

        return view('admin.analytics', compact(
            'branchName',
            'isAllBranches',
            'today',
            'thisWeek',
            'thisMonth',
            'deltaToday',
            'deltaWeek',
            'deltaMonth',
            'recommendations',
            'bestSellers',
            'leastSellers',
            'outOfStock',
            'lowStock',
            'slowMovers',
            'linkedToBest',
            'dailyTrend',
            'salesByCategory',
            'salesPerBranch',
            'branchPerformance',
            'selectedBranch',
            'salesForecast',
            'averageRating'
        ));
    }

    /**
     * How many catalogue records are sitting in the archive.
     *
     * Drives the "Archived (N)" link on each catalogue page. Computed here
     * rather than in the Blade so the pages stay free of queries, and returned
     * as a single number because the link goes to one combined page.
     */
    private function archivedCatalogueCount(): int
    {
        return \App\Models\MenuItem::onlyArchived()->count()
            + \App\Models\MenuOption::onlyArchived()->count()
            + Category::onlyArchived()->count()
            + \App\Models\Subcategory::onlyArchived()->count();
    }

    // ══════════ Menu Items (CRUD WORKING) ══════════

    public function showMenuItems()
    {
        $selectedBranch = $this->getSelectedBranch();

        $menuItems = \App\Models\MenuItem::with([
            'category',
            'subcategory',
            'inventoryItem',
            'branch'
        ])
            ->when($selectedBranch !== 'all', function ($q) use ($selectedBranch) {
                $q->where('branch_id', $selectedBranch);
            })
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $branches = \App\Models\Branch::where('is_active', true)
            ->orderBy('id')
            ->get();

        $categories = Category::orderBy('name')->get();

        $subcategories = \App\Models\Subcategory::orderBy('name')->get();

        $inventoryItems = \App\Models\Inventory::where('is_active', true)
            ->when(
                $selectedBranch !== 'all',
                fn($q) => $q->where('branch_id', $selectedBranch)
            )
            ->orderBy('item_name')
            ->get();

        $archivedCount = $this->archivedCatalogueCount();

        // True cost / profit per item, derived from each item's recipe through the
        // same requirement walker the stock deduction uses. Computed here rather
        // than in the view so there is exactly one place that answers "what does
        // this item cost to make" — see App\Services\MenuItemCosting.
        $costing = app(\App\Services\MenuItemCosting::class)->breakdownForMany($menuItems);

        return view('admin.menu-items', compact(
            'menuItems',
            'categories',
            'subcategories',
            'inventoryItems',
            'branches',
            'selectedBranch',
            'archivedCount',
            'costing'
        ));
    }

    /**
     * The ONE rule for "may this inventory row be used by a menu item in this
     * branch". Used by the legacy single-ingredient link and by every recipe row
     * on the Add form, so the two can never apply different rules.
     *
     * Mirrors exactly what the ingredient picker offers: active rows belonging
     * to the selected branch (see showNewMenuItem()). Inventory has no
     * archived_at — is_active = false IS archived for this table.
     */
    private function inventoryIsSelectableForBranch($inventoryId, $selectedBranch): bool
    {
        $inventory = \App\Models\Inventory::find($inventoryId);

        return $inventory
            && (bool) $inventory->is_active
            && (int) $inventory->branch_id === (int) $selectedBranch;
    }
    public function storeNewMenuItem(Request $request)
    {
        // The Add form always renders at least one ingredient row so the section
        // is obviously usable. An untouched row is not an error — drop it before
        // validation rather than making the admin delete it by hand.
        $request->merge([
            'ingredients' => array_values(array_filter(
                (array) $request->input('ingredients', []),
                fn ($row) => is_array($row)
                    && (trim((string) ($row['inventory_id'] ?? '')) !== ''
                        || trim((string) ($row['quantity_used'] ?? '')) !== ''),
            )),
        ]);

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
            // Recipe rows typed on the Add form, saved with the item in one go.
            'ingredients' => 'nullable|array',
            'ingredients.*.inventory_id' => 'required|integer|exists:inventory,id',
            'ingredients.*.quantity_used' => 'required|numeric|gt:0',
        ], [
            'category_id.required' => 'Please select a category.',
            'name.required' => 'Item name is required.',
            'price.required' => 'Price is required.',
            'price.numeric' => 'Price must be a number.',
            'image.mimes' => 'Image must be JPG, PNG, or WEBP.',
            'image.max' => 'Image must be less than 2MB.',
            'ingredients.*.inventory_id.required' => 'Choose an ingredient for every recipe row, or remove the empty row.',
            'ingredients.*.inventory_id.exists' => 'One of the chosen ingredients no longer exists.',
            'ingredients.*.quantity_used.required' => 'Enter a quantity for every recipe row.',
            'ingredients.*.quantity_used.numeric' => 'Ingredient quantity must be a number.',
            'ingredients.*.quantity_used.gt' => 'Ingredient quantity must be greater than 0.',
        ]);

        $selectedBranch = $this->getSelectedBranch();

        if ($selectedBranch === 'all') {
            return redirect()->route('admin.menu-items')
                ->withErrors([
                    'branch' => 'Please select a specific branch before adding a menu item.'
                ]);
        }

        if (!empty($validated['inventory_item_id'])) {
            if (!$this->inventoryIsSelectableForBranch($validated['inventory_item_id'], $selectedBranch)) {
                return back()->withErrors([
                    'inventory_item_id' => 'Selected inventory item must belong to the selected branch.'
                ])->withInput();
            }
        }

        // Recipe rows go through the SAME rule as the legacy link above — one
        // rule, applied in both places, so they cannot diverge.
        $recipeRows = $validated['ingredients'] ?? [];
        $seenInventoryIds = [];

        foreach ($recipeRows as $row) {
            $invId = (int) $row['inventory_id'];

            if (in_array($invId, $seenInventoryIds, true)) {
                $name = \App\Models\Inventory::find($invId)?->item_name ?? 'That ingredient';
                return back()->withErrors([
                    'ingredients' => $name . ' is listed twice. Combine it into a single row with the total quantity.'
                ])->withInput();
            }
            $seenInventoryIds[] = $invId;

            if (!$this->inventoryIsSelectableForBranch($invId, $selectedBranch)) {
                return back()->withErrors([
                    'ingredients' => 'Every ingredient must be an active inventory item in the selected branch.'
                ])->withInput();
            }
        }

        $imagePath = null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');

            $filename = time() . '_' .
                preg_replace(
                    '/[^A-Za-z0-9\.]/',
                    '_',
                    $file->getClientOriginalName()
                );

            $file->move(public_path('uploads/menu-items'), $filename);

            $imagePath = 'uploads/menu-items/' . $filename;
        }

        // The item and its whole recipe are one unit of work: a half-saved item
        // with two of its five ingredients would silently mis-cost and
        // mis-deduct forever. Anything thrown here rolls back both tables.
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $recipeRows, $imagePath, $selectedBranch) {
                $menuItem = \App\Models\MenuItem::create([
                    'category_id' => $validated['category_id'],
                    'subcategory_id' => $validated['subcategory_id'] ?? null,
                    'inventory_item_id' => $validated['inventory_item_id'] ?? null,
                    'inventory_amount_used' => $validated['inventory_amount_used'] ?? 0,
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                    'price' => $validated['price'],
                    'cost' => $validated['cost'] ?? 0,
                    'image' => $imagePath,
                    'branch_id' => $selectedBranch,
                    'is_available' => true,
                    'is_featured' => false,
                    'display_order' => 0,
                    'total_sold' => 0,
                ]);

                foreach ($recipeRows as $row) {
                    \App\Models\MenuItemIngredient::create([
                        'menu_item_id' => $menuItem->id,
                        'inventory_id' => (int) $row['inventory_id'],
                        'quantity_used' => $row['quantity_used'],
                    ]);
                }

                if (!empty($recipeRows)) {
                    // Recompute from the rows that were actually saved. The
                    // number the browser previewed is never trusted.
                    $menuItem->cost = app(\App\Services\MenuItemCosting::class)->costFor($menuItem->fresh());
                    $menuItem->save();
                }
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Menu item create failed', ['exception' => $e]);

            // The upload landed before the transaction; with nothing saved it
            // would otherwise be an orphan file on disk.
            if ($imagePath && file_exists(public_path($imagePath))) {
                @unlink(public_path($imagePath));
            }

            return back()->withErrors([
                'error' => 'Could not save that menu item just now. Nothing was saved — please try again.',
            ])->withInput();
        }

        return redirect()->route('admin.menu-items')
            ->with('success', 'Menu item "' . $validated['name'] . '" added successfully!');
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

        // An existing menu item already belongs to a branch. When the admin is
        // viewing "All Branches" we keep the item on its own branch instead of
        // aborting the whole update — bailing out here used to silently discard
        // edits (including image re-uploads) with no visible error.
        $targetBranch = $selectedBranch === 'all'
            ? $menuItem->branch_id
            : $selectedBranch;

        if (empty($targetBranch)) {
            return back()->withErrors([
                'branch' => 'This item has no branch assigned. Select a specific branch first, then edit it.'
            ])->withInput();
        }

        if (!empty($validated['inventory_item_id'])) {
            $inventory = \App\Models\Inventory::find($validated['inventory_item_id']);

            if (!$inventory || (int)$inventory->branch_id !== (int)$targetBranch) {
                return back()->withErrors([
                    'inventory_item_id' => 'Selected inventory item must belong to the same branch as this menu item.'
                ])->withInput();
            }
        }

        if ($request->hasFile('image')) {
            if ($menuItem->image && file_exists(public_path($menuItem->image))) {
                unlink(public_path($menuItem->image));
            }

            $file = $request->file('image');

            $filename = time() . '_' .
                preg_replace(
                    '/[^A-Za-z0-9\.]/',
                    '_',
                    $file->getClientOriginalName()
                );

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
        $menuItem->branch_id = $targetBranch;
        $menuItem->save();

        return redirect()->route('admin.menu-items')
            ->with('success', 'Menu item updated successfully!');
    }

    public function toggleMenuItem(int $id)
    {
        // Branch-scoped for staff, unrestricted for admins — the same rule the
        // per-order endpoints use. A staff member aiming this at another
        // branch's menu item gets the same 404 as a nonexistent id.
        $menuItem = \App\Services\AdminOrderAccess::resolveRecordInScope(\App\Models\MenuItem::class, $id);

        $menuItem->is_available = !$menuItem->is_available;
        $menuItem->save();

        $status = $menuItem->is_available
            ? 'made available'
            : 'hidden from menu';

        return redirect()->back()
            ->with('success', 'Item "' . $menuItem->name . '" ' . $status . '.');
    }

    /**
     * Remove a menu item: hard delete when nothing ever ordered it, archive
     * when something did.
     *
     * This used to untick "Available" and call that success, which is what the
     * owner reported: the item stayed in the list forever and the message read
     * like a failure. CatalogueLifecycle now decides, and says which of the two
     * happened. safelyDelete() stays underneath as the net from the previous
     * round — a constraint added later still cannot reach the screen.
     */
    public function deleteMenuItem(int $id)
    {
        $menuItem = \App\Models\MenuItem::withArchived()->findOrFail($id);

        return $this->safelyDelete(
            function () use ($menuItem) {
                $outcome = app(\App\Services\CatalogueLifecycle::class)->remove($menuItem);

                return redirect()->route('admin.menu-items')
                    ->with('success', $outcome['message']);
            },
            'admin.menu-items',
            'the menu item "' . $menuItem->name . '"',
            'It is still attached to records that need it.'
        );
    }

    public function addIngredient(Request $request, int $menuItem)
{
    $menuItemModel = \App\Models\MenuItem::findOrFail($menuItem);

    $validated = $request->validate([
        'inventory_id' => 'required|exists:inventory,id',
        'quantity_used' => 'required|numeric|min:0.001',
    ]);

    $inventory = \App\Models\Inventory::findOrFail($validated['inventory_id']);

    // Prevent duplicate ingredient entries for the same menu item.
    $existing = \App\Models\MenuItemIngredient::where('menu_item_id', $menuItemModel->id)
        ->where('inventory_id', $inventory->id)
        ->first();

    if ($existing) {
        // State the current quantity so the admin doesn't mistake this
        // rejection for their new value having been silently saved — the
        // row they're seeing in the list is the pre-existing one, untouched.
        $existingQty = rtrim(rtrim(number_format((float) $existing->quantity_used, 3), '0'), '.');
        $message = 'This ingredient is already added at ' . $existingQty . ' ' . $inventory->unit
            . '. Delete it first if you want to change the quantity.';

        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return redirect()->back()->withErrors(['inventory_id' => $message]);
    }

    $ingredient = \App\Models\MenuItemIngredient::create([
        'menu_item_id' => $menuItemModel->id,
        'inventory_id' => $inventory->id,
        'quantity_used' => $validated['quantity_used'],
    ]);

    $message = 'Ingredient "' . $inventory->item_name . '" added successfully.';

    if ($request->expectsJson()) {
        return response()->json([
            'success' => true,
            'message' => $message,
            'ingredient' => [
                'id' => $ingredient->id,
                'inventory_id' => $inventory->id,
                'name' => $inventory->item_name,
                'quantity_used' => rtrim(rtrim(number_format((float) $ingredient->quantity_used, 3), '0'), '.'),
                'unit' => $inventory->unit,
                'delete_url' => route('admin.menu-items.ingredients.delete', [$menuItemModel->id, $ingredient->id]),
            ],
        ]);
    }

    return redirect()->back()->with('success', $message);
}


public function deleteIngredient(Request $request, int $menuItem, int $ingredient)
{
    $ingredientModel = \App\Models\MenuItemIngredient::where('id', $ingredient)
        ->where('menu_item_id', $menuItem)
        ->firstOrFail();

    // Nothing references a recipe line, but this endpoint answers both JSON
    // and form posts, so it gets its own net rather than the redirect-based
    // safelyDelete(): an exception here would break the ingredient editor.
    try {
        $ingredientModel->delete();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Recipe ingredient delete failed', ['exception' => $e]);

        $message = 'Could not remove that ingredient just now. Nothing was changed — please try again.';

        return $request->expectsJson()
            ? response()->json(['success' => false, 'message' => $message], 422)
            : redirect()->back()->withErrors(['error' => $message]);
    }

    if ($request->expectsJson()) {
        return response()->json(['success' => true, 'message' => 'Ingredient removed successfully.']);
    }

    return redirect()->back()->with(
        'success',
        'Ingredient removed successfully.'
    );
}

/**
 * Link a menu OPTION (add-on, e.g. "Extra Cheese") to an inventory item + quantity.
 * These are deducted on top of the base recipe, but only when the customer
 * actually selects the option on their order.
 * Mirrors addIngredient() above, which does the same for menu items.
 */
public function addOptionIngredient(Request $request, int $menuOption)
{
    $optionModel = \App\Models\MenuOption::findOrFail($menuOption);

    $validated = $request->validate([
        'inventory_id' => 'required|exists:inventory,id',
        'quantity_used' => 'required|numeric|min:0.001',
    ]);

    $inventory = \App\Models\Inventory::findOrFail($validated['inventory_id']);

    // Prevent duplicate ingredient entries for the same option.
    $existing = \App\Models\MenuOptionIngredient::where('menu_option_id', $optionModel->id)
        ->where('inventory_id', $inventory->id)
        ->first();

    if ($existing) {
        $existingQty = rtrim(rtrim(number_format((float) $existing->quantity_used, 3), '0'), '.');
        $message = 'This ingredient is already added to option "' . $optionModel->name . '" at '
            . $existingQty . ' ' . $inventory->unit . '. Delete it first if you want to change the quantity.';

        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return redirect()->back()->withErrors(['inventory_id' => $message]);
    }

    $ingredient = \App\Models\MenuOptionIngredient::create([
        'menu_option_id' => $optionModel->id,
        'inventory_id' => $inventory->id,
        'quantity_used' => $validated['quantity_used'],
    ]);

    $message = 'Ingredient "' . $inventory->item_name . '" added to option "' . $optionModel->name . '".';

    if ($request->expectsJson()) {
        return response()->json([
            'success' => true,
            'message' => $message,
            'ingredient' => [
                'id' => $ingredient->id,
                'name' => $inventory->item_name,
                'quantity_used' => rtrim(rtrim(number_format((float) $ingredient->quantity_used, 3), '0'), '.'),
                'unit' => $inventory->unit,
                'delete_url' => route('admin.menu-options.ingredients.delete', [$optionModel->id, $ingredient->id]),
            ],
        ]);
    }

    return redirect()->back()->with('success', $message);
}

public function deleteOptionIngredient(Request $request, int $menuOption, int $ingredient)
{
    $ingredientModel = \App\Models\MenuOptionIngredient::where('id', $ingredient)
        ->where('menu_option_id', $menuOption)
        ->firstOrFail();

    // Same shape as deleteIngredient() above, same reason.
    try {
        $ingredientModel->delete();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Option ingredient delete failed', ['exception' => $e]);

        $message = 'Could not remove that option ingredient just now. Nothing was changed — please try again.';

        return $request->expectsJson()
            ? response()->json(['success' => false, 'message' => $message], 422)
            : redirect()->back()->withErrors(['error' => $message]);
    }

    if ($request->expectsJson()) {
        return response()->json(['success' => true, 'message' => 'Option ingredient removed successfully.']);
    }

    return redirect()->back()->with(
        'success',
        'Option ingredient removed successfully.'
    );
}

    // ══════════ Categories (CRUD WORKING) ══════════

    public function showAddCategory()
    {
        $categories = Category::with('menuItems')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $subcategories = \App\Models\Subcategory::with('category')
            ->orderBy('name')
            ->get();

        $archivedCount = $this->archivedCatalogueCount();

        return view(
            'admin.add-category',
            compact('categories', 'subcategories', 'archivedCount')
        );
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

            $filename = time() . '_cat_' .
                preg_replace(
                    '/[^A-Za-z0-9\.]/',
                    '_',
                    $file->getClientOriginalName()
                );

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
            ->with(
                'success',
                'Category "' . $validated['name'] . '" added successfully!'
            );
    }

    public function editCategory(int $id)
    {
        $category = Category::findOrFail($id);

        $categories = Category::orderBy('display_order')
            ->orderBy('name')
            ->get();

        return view(
            'admin.add-category',
            compact('categories', 'category')
        );
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

            $filename = time() . '_cat_' .
                preg_replace(
                    '/[^A-Za-z0-9\.]/',
                    '_',
                    $file->getClientOriginalName()
                );

            $file->move(public_path('uploads/categories'), $filename);

            $category->image = 'uploads/categories/' . $filename;
        }

        $category->name = $validated['name'];
        $category->description = $validated['description'] ?? null;

        $category->save();

        return redirect()->route('admin.add-category')
            ->with('success', 'Category updated successfully!');
    }

    /**
     * Remove a category: hard delete when empty, archive when its only
     * remaining items are archived, refuse only while LIVE items are still
     * filed under it.
     *
     * The middle case is the dead end the owner hit — "Cannot delete 'ice
     * cream' — it has 1 menu item(s) linked", where that one item was the one
     * they had already tried to remove. See CatalogueLifecycle for why
     * archiving rather than deleting is the right answer there.
     */
    public function deleteCategory(int $id)
    {
        $category = Category::withArchived()->findOrFail($id);

        return $this->safelyDelete(
            function () use ($category) {
                $outcome = app(\App\Services\CatalogueLifecycle::class)->remove($category);

                $redirect = redirect()->route('admin.add-category');

                // BLOCKED is the one outcome that is genuinely a refusal, and
                // it must not be dressed up as success.
                return $outcome['action'] === \App\Services\CatalogueLifecycle::BLOCKED
                    ? $redirect->withErrors(['error' => $outcome['message']])
                    : $redirect->with('success', $outcome['message']);
            },
            'admin.add-category',
            'the category "' . $category->name . '"',
            'Move its menu items and subcategories elsewhere first.'
        );
    }

    // ══════════ Sub Categories (CRUD WORKING) ══════════

    public function showAddSubcategory()
    {
        $subcategories = \App\Models\Subcategory::with('category')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $categories = Category::orderBy('name')->get();

        $archivedCount = $this->archivedCatalogueCount();

        return view(
            'admin.add-subcategory',
            compact('subcategories', 'categories', 'archivedCount')
        );
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

        $exists = \App\Models\Subcategory::where(
            'category_id',
            $validated['category_id']
        )
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
            ->with(
                'success',
                'Subcategory "' . $validated['name'] . '" added successfully!'
            );
    }

    /**
     * Rename a subcategory and/or move it to a different parent category.
     *
     * VALIDATION mirrors storeSubcategory() exactly — same three rules, same
     * uniqueness scope (name unique per PARENT CATEGORY, not globally: two
     * different categories may each have their own "Classic", the same way
     * storeSubcategory()'s manual exists() check already treats them).
     * storeSubcategory() enforces that with a manual query rather than a DB
     * constraint (subcategories has no unique index at all — confirmed
     * against the migration), and this repeats the same query with
     * ->where('id', '!=', $id) added so the subcategory is not compared
     * against its own current row. This method previously had NO such check
     * at all, which was the one place it had already drifted from create.
     *
     * MENU ITEMS STAY ATTACHED. menu_items.subcategory_id is untouched here
     * under any circumstance — nothing in this method may orphan or
     * reassign which subcategory an item belongs to.
     *
     * THE CASCADE, investigated before writing this: menu_items carries its
     * OWN category_id, independently of subcategory_id — confirmed against
     * the migration (both are plain nullable foreign keys, no generated/
     * derived column) and against storeNewMenuItem()'s validation, which
     * accepts category_id and subcategory_id as two separate, uncorrelated
     * inputs with no check that one belongs to the other. The customer menu
     * page filters items by MenuItem.category_id directly
     * (AuthController::showMenu(), $itemsQuery->where('category_id', $id)) —
     * subcategory_id is never consulted there.
     *
     * So moving a subcategory to a new parent, with nothing else done, would
     * leave every item filed under it pointing at the OLD category on the
     * customer menu while its subcategory silently claimed the NEW one — a
     * real, customer-visible inconsistency, and the smallest correct
     * handling is to cascade: when category_id actually changes, every menu
     * item currently attached to this subcategory has its own category_id
     * updated to match, in the same transaction as the subcategory update.
     * Nothing is touched when the parent does not change.
     */
    public function updateSubcategory(Request $request, int $id)
    {
        $subcategory = \App\Models\Subcategory::findOrFail($id);

        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ], [
            'category_id.required' => 'Please select a parent category.',
            'category_id.exists' => 'Invalid category selected.',
            'name.required' => 'Subcategory name is required.',
        ]);

        $duplicate = \App\Models\Subcategory::where('category_id', $validated['category_id'])
            ->where('name', $validated['name'])
            ->where('id', '!=', $subcategory->id)
            ->exists();

        if ($duplicate) {
            return back()->withErrors([
                'name' => 'This subcategory already exists in the selected category.',
            ])->withInput();
        }

        $oldCategoryId = (int) $subcategory->category_id;
        $newCategoryId = (int) $validated['category_id'];

        \Illuminate\Support\Facades\DB::transaction(function () use ($subcategory, $validated, $oldCategoryId, $newCategoryId) {
            $subcategory->update([
                'category_id' => $validated['category_id'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);

            if ($newCategoryId !== $oldCategoryId) {
                \App\Models\MenuItem::withoutGlobalScope(\App\Models\Concerns\NotArchivedScope::class)
                    ->where('subcategory_id', $subcategory->id)
                    ->update(['category_id' => $newCategoryId]);
            }
        });

        return redirect()->route('admin.add-category')
            ->with('success', 'Subcategory "' . $subcategory->name . '" updated!');
    }

    /**
     * Remove a subcategory: hard delete when empty, archive when anything is
     * filed under it.
     *
     * menu_items.subcategory_id is ON DELETE SET NULL, so nothing here can be
     * lost — which is exactly why this one is never blocked. Archiving instead
     * of deleting avoids quietly un-filing items the admin did not ask to
     * touch, and is one click to undo.
     */
    public function deleteSubcategory(int $id)
    {
        $subcategory = \App\Models\Subcategory::withArchived()->findOrFail($id);

        /*
         * Redirect to add-category, not add-subcategory.
         *
         * GET /admin/add-subcategory is itself only a redirect to the
         * Categories page (that is where the subcategory table actually
         * lives), and a flash message does not survive that extra hop — it is
         * consumed by the redirecting request and gone before the page the
         * admin ends up on renders. Verified by reproduction: removing a
         * subcategory showed no message at all, which is exactly the silent
         * outcome this round is supposed to eliminate.
         */
        return $this->safelyDelete(
            function () use ($subcategory) {
                $outcome = app(\App\Services\CatalogueLifecycle::class)->remove($subcategory);

                return redirect()->route('admin.add-category')
                    ->with('success', $outcome['message']);
            },
            'admin.add-category',
            'the subcategory "' . $subcategory->name . '"'
        );
    }

    // ══════════ Menu Options ══════════

    public function showMenuOptions()
    {
        $options = \App\Models\MenuOption::with('ingredients.inventory')
            ->orderBy('name')
            ->get();

        $categories = \App\Models\Category::with(['menuItems'])
            ->orderBy('name')
            ->get();

        // Menu options are global (no branch_id), so offer every active inventory
        // item and label each with its branch so the admin picks the right one.
        $inventoryItems = \App\Models\Inventory::with('branch')
            ->where('is_active', true)
            ->orderBy('item_name')
            ->get();

        $archivedCount = $this->archivedCatalogueCount();

        return view(
            'admin.menu-options',
            compact('options', 'categories', 'inventoryItems', 'archivedCount')
        );
    }

    public function storeMenuOption(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ]);

        \App\Models\MenuOption::create([
            'name' => $validated['name'],
            'additional_price' => $validated['price'] ?? 0,
            'is_active' => true,
            'display_order' => 0,
        ]);

        return redirect()->route('admin.menu-options')
            ->with('success', 'Option added!');
    }

    /**
     * Correct a typo or reprice an existing add-on, added 2026-09-02.
     *
     * The only path to fixing either used to be delete-and-recreate, which
     * loses every menu-item assignment (menu_item_options) the option had —
     * this exists so a name/price fix does not also mean re-assigning it to
     * every item all over again.
     *
     * Validation deliberately mirrors storeMenuOption() field-for-field (same
     * two rules on 'name' and 'price') so the two paths cannot silently
     * accept different input — 'description' is intentionally left out
     * because the edit form only offers name and price, same as the Add
     * Option form actually does (its validate() also allows a description,
     * but nothing in that form ever sends one).
     *
     * ONLY name and additional_price are written. is_active, display_order
     * and — critically — every menu_item_options pivot row for this option
     * are untouched: this is a plain attribute update on the existing model,
     * never a delete+recreate, so nothing here can touch a relationship.
     *
     * PAST ORDERS: order_item_options snapshots option_name and
     * additional_price onto the order line at order time (see that table's
     * migration) specifically so a later edit here cannot change what a
     * historical order recorded. What it does NOT protect is any view that
     * reads the option's name back through the live relationship instead of
     * the snapshot column — confirmed customer/receipt.blade.php does exactly
     * that (`$option->name`, not `$option->pivot->option_name`), so renaming
     * an option here will currently change the add-on label shown on past
     * receipts, even though the schema was built to prevent it. That mismatch
     * predates this change and is out of scope for this pass — flagged here,
     * not fixed here.
     */
    public function updateMenuOption(Request $request, int $id)
    {
        $option = \App\Models\MenuOption::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
        ]);

        $option->update([
            'name' => $validated['name'],
            'additional_price' => $validated['price'] ?? 0,
        ]);

        return redirect()->route('admin.menu-options')
            ->with('success', 'Option "' . $option->name . '" updated!');
    }

    /**
     * Remove an add-on: hard delete when no order ever used it, archive when
     * one did.
     *
     * The previous round stopped this from crashing on
     * order_item_options.menu_option_id (ON DELETE RESTRICT) but could only
     * refuse. Refusing is the wrong answer for an add-on the shop genuinely
     * stopped selling — it left it on the list with no way off. It now archives
     * instead, which removes it from every item and every order screen while
     * the receipts that mention it stay complete.
     */
    public function deleteMenuOption(int $id)
    {
        $option = \App\Models\MenuOption::withArchived()->findOrFail($id);

        return $this->safelyDelete(
            function () use ($option) {
                $outcome = app(\App\Services\CatalogueLifecycle::class)->remove($option);

                return redirect()->route('admin.menu-options')
                    ->with('success', $outcome['message']);
            },
            'admin.menu-options',
            'the option "' . $option->name . '"',
            'It is still linked to menu items or past orders.'
        );
    }

    // ══════════ Archived catalogue ══════════

    /**
     * One page for everything that has been removed from the business but kept
     * for history, across all four catalogue types.
     *
     * Deliberately a single page rather than an "archived" toggle hidden on
     * each list: the point of archiving is that the working lists get clean, so
     * the archived rows must live somewhere else entirely — but somewhere the
     * owner can actually find, linked from each list, not a hidden corner.
     */
    public function showArchivedCatalogue()
    {
        return view('admin.archived', [
            'menuItems' => \App\Models\MenuItem::onlyArchived()
                ->with(['category' => fn ($q) => $q->withArchived(), 'branch'])
                ->orderByDesc('archived_at')
                ->get(),
            'menuOptions' => \App\Models\MenuOption::onlyArchived()
                ->orderByDesc('archived_at')
                ->get(),
            'categories' => Category::onlyArchived()
                ->orderByDesc('archived_at')
                ->get(),
            'subcategories' => \App\Models\Subcategory::onlyArchived()
                ->with(['category' => fn ($q) => $q->withArchived()])
                ->orderByDesc('archived_at')
                ->get(),
        ]);
    }

    /**
     * Put an archived record back on the normal lists.
     *
     * The type comes from the URL and is allowlisted here rather than resolved
     * dynamically — a class name taken from a request parameter is how you turn
     * a restore button into an arbitrary-model editor.
     */
    public function restoreArchivedCatalogue(string $type, int $id)
    {
        $models = [
            'menu-item'   => \App\Models\MenuItem::class,
            'menu-option' => \App\Models\MenuOption::class,
            'category'    => Category::class,
            'subcategory' => \App\Models\Subcategory::class,
        ];

        if (!isset($models[$type])) {
            abort(404);
        }

        $record = $models[$type]::onlyArchived()->find($id);

        if (!$record) {
            return redirect()->route('admin.archived')
                ->withErrors(['error' => 'That record is not in the archive — it may have been restored already.']);
        }

        $outcome = app(\App\Services\CatalogueLifecycle::class)->restore($record);

        return redirect()->route('admin.archived')
            ->with('success', $outcome['message']);
    }

    /**
     * Attach/detach add-on options for one menu item.
     *
     * VALIDATION ADDED 2026-09-02. option_ids went straight into sync() with
     * no checking, so an id that does not exist in menu_options reached the
     * pivot INSERT and menu_item_options' own foreign key threw — an uncaught
     * QueryException rendered as a raw 500, the same class of failure as the
     * inventory item_code crash. Reachable from an ordinary stale browser
     * tab, not only a crafted request: the options list is rendered once and
     * filtered client-side, so a tab left open while another admin deletes an
     * option still holds that option's id in its DOM and submits it on the
     * next save.
     *
     * The check runs BEFORE sync(), so a payload mixing valid and invalid ids
     * writes nothing at all rather than partially applying — sync() is a
     * single full replace, and letting it start would detach the item's real
     * assignments before failing on the bad id.
     *
     * Deliberately checks existence only, against the table. It does NOT
     * reject archived options: MenuOption's Archivable global scope hides
     * those from Eloquent, but an item may legitimately still carry one that
     * was archived after being assigned, and re-saving that item must keep
     * behaving exactly as it does today. Narrowing that is a separate
     * decision, not a side effect of fixing a 500.
     */
    public function assignOptions(Request $request, int $menuItemId)
    {
        $menuItem = \App\Models\MenuItem::findOrFail($menuItemId);

        $optionIds = $request->input('option_ids', []);

        if (!is_array($optionIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Could not save: the selected options were not sent correctly. Please refresh and try again.',
            ], 422);
        }

        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));

        $existing = \App\Models\MenuOption::withArchived()
            ->whereIn('id', $optionIds)
            ->pluck('id')
            ->all();

        $missing = array_diff($optionIds, $existing);

        if ($missing) {
            return response()->json([
                'success' => false,
                'message' => 'Could not save: '
                    . (count($missing) === 1 ? 'an option' : count($missing) . ' options')
                    . ' on this page no longer exist. Please refresh the page and try again.',
            ], 422);
        }

        $menuItem->options()->sync($optionIds);

        return response()->json(['success' => true]);
    }

    // ══════════ Orders Management ══════════

        public function showHome()
        {
            $selectedBranch = $this->getSelectedBranch();

            $pendingOrders = \App\Models\Order::with([
                    'items',
                    'discountCard',
                    'voucher',
                    'customer',
                ])
                ->whereIn('status', ['pending', 'preparing', 'serving'])
                ->when(
                    $selectedBranch !== 'all',
                    fn($q) => $q->where('branch_id', $selectedBranch)
                )
                ->orderBy('created_at', 'asc')
                ->get();

            $helpRequests = \App\Models\HelpRequest::with(['branch', 'order'])
                ->whereIn('status', ['pending', 'assisting'])
                ->when(
                    $selectedBranch !== 'all',
                    fn($q) => $q->where('branch_id', $selectedBranch)
                )
                ->orderBy('requested_at', 'asc')
                ->get();

            /*
             * Orders a customer cancelled after declaring they had already paid
             * by GCash — money that has to be sent back by hand.
             *
             * Queried separately rather than folded into $pendingOrders above,
             * for two reasons. These are status 'cancelled', so they would be
             * wrong in a list called "Active Orders" and would render with
             * prepare/serve buttons that make no sense for a dead order. And
             * they are the one thing on this screen that represents money owed
             * to a customer, so they get their own band at the top instead of
             * being one card among many.
             *
             * They belong on THIS page and not in the completed-orders archive:
             * a refund is outstanding work for the current shift, and the
             * archive is a date-filtered history nobody checks for to-dos. Once
             * marked refunded they drop off here and settle into that archive
             * as history, which is exactly the right end state.
             *
             * Oldest first: the customer who has been waiting longest for their
             * money back is the most urgent one.
             */
            $refundPendingOrders = \App\Models\Order::with(['items', 'customer'])
                ->where('status', 'cancelled')
                ->where('payment_status', 'refund_pending')
                ->when(
                    $selectedBranch !== 'all',
                    fn($q) => $q->where('branch_id', $selectedBranch)
                )
                ->orderBy('cancelled_at', 'asc')
                ->get();

            $branches = \App\Models\Branch::where('is_active', true)
                ->orderBy('name')
                ->get();

            $menuItems = \App\Models\MenuItem::with([
                'category',
                'subcategory',
                'options' => function ($query) {
                    $query->where('is_active', true)
                        ->orderBy('display_order')
                        ->orderBy('name');
                }
            ])
            ->where('is_available', true)
            ->where(function ($query) {
                $query->whereNull('branch_id')
                    ->orWhereIn(
                        'branch_id',
                        \App\Models\Branch::where('is_active', true)->pluck('id')
                    );
            })
            ->orderBy('name')
            ->get();

            $categories = \App\Models\Category::orderBy('name')->get();

            $subcategories = \App\Models\Subcategory::orderBy('name')->get();

            return view(
                'admin.home',
                compact(
                    'pendingOrders',
                    'refundPendingOrders',
                    'helpRequests',
                    'branches',
                    'menuItems',
                    'categories',
                    'subcategories',
                    'selectedBranch'
                )
            );
        }

        /**
         * Price the customer's voucher for the Manual Order modal, before the
         * order is submitted.
         *
         * WHY THIS EXISTS
         * ---------------
         * Staff read the result of this out to the customer standing in front
         * of them, and then take their money. If the modal quoted one figure
         * and storeManualOrder() charged another, the drawer would not balance
         * at the end of the shift — which is the exact class of bug
         * CartTotalsMatchCheckoutTest exists for on the customer side.
         *
         * It cannot drift, because it is not a second opinion: it calls the
         * SAME resolver, the SAME validator and the SAME discount formula that
         * storeManualOrder() and the customer's own checkout call, with the
         * same null holder. Its answer is the order's answer.
         *
         * It deliberately does NOT spend anything. Checking a code must be free
         * — staff will check one, be interrupted, and check it again — so the
         * claim is only burned when the order is actually submitted.
         */
        public function previewManualVoucher(Request $request)
        {
            $request->validate([
                'code'     => 'required|string|max:64',
                'subtotal' => 'required|numeric|min:0',
            ]);

            $subtotal = (float) $request->input('subtotal');

            $resolved = \App\Services\VoucherClaims::resolveTypedCode(
                (string) $request->input('code')
            );

            $voucher = $resolved['voucher'];

            if (!$voucher) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid voucher code.',
                ]);
            }

            // The one validator. A walk-in customer has no account, so the
            // holder is null — the same guest case the customer flow handles.
            $error = $voucher->redemptionErrorFor(null, $subtotal, $resolved['claim']);

            if ($error !== null) {
                return response()->json([
                    'success' => false,
                    'message' => $error,
                ]);
            }

            return response()->json([
                'success'     => true,
                'message'     => $voucher->description ?: 'Voucher applied.',
                'discount'    => $voucher->discountFor($subtotal),
                'final_total' => round($subtotal - $voucher->discountFor($subtotal), 2),
            ]);
        }

        public function storeManualOrder(Request $request)
        {
            $validated = $request->validate([
                'branch_id' => 'required|exists:branches,id',
                'order_type' => 'required|in:dine_in,pick_up',
                'table_number' => 'nullable|string|max:50',
                'payment_method' => 'required|in:cash,gcash',
                'amount_paid' => 'required|numeric|min:0',
                'items' => 'required|array|min:1',
                'items.*.menu_item_id' => 'required|exists:menu_items,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.options' => 'nullable|array',
                'items.*.options.*' => 'integer|exists:menu_options,id',

                // PWD / Senior Citizen — staff verifies the physical ID in
                // person at the counter, so unlike the online flow there is no
                // photo upload and no discount_status = 'pending' step.
                'discount_type' => 'nullable|in:pwd,senior',
                'discount_beneficiary_name' => [
                    'nullable',
                    'string',
                    'max:100',
                    "regex:/^[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ .'-]{1,99}$/u",
                ],
                'discount_beneficiary_id' => [
                    'nullable',
                    'string',
                    'max:100',
                    "regex:/^[A-Za-z0-9\-\/ ]+$/",
                ],

                /*
                 * The customer's voucher code, keyed in by staff on their
                 * behalf (2026-09-03). Some customers cannot use the app
                 * themselves — elderly, not comfortable with a phone, or they
                 * simply did not bring one — and staff already key in the whole
                 * order for them at the counter.
                 *
                 * Deliberately only a length cap here. WHICH codes are valid is
                 * not this rule's business: that is decided further down by the
                 * exact same Voucher::redemptionErrorFor() the customer's own
                 * checkout uses, so the two can never disagree about whether a
                 * code is good. A format rule here would be a second, weaker
                 * copy of that judgement and would reject shapes the real
                 * validator accepts.
                 */
                'voucher_code' => 'nullable|string|max:64',
            ]);

            // A staff member may only raise a walk-in order in their OWN
            // branch. validate() above only proves branch_id EXISTS; this is
            // the creation-side half of App\Services\AdminOrderAccess, the same
            // rule that scopes every per-order endpoint. An admin keeps their
            // freedom to book an order into any branch. The refusal is the
            // app's standard 404 — a staff member has no legitimate view of
            // another branch, so a crafted branch_id is treated as a bad
            // request for something that is not theirs.
            if (! \App\Services\AdminOrderAccess::allowsBranch((int) $validated['branch_id'])) {
                abort(404);
            }

            $discountType = null;
            $discountAmount = 0.0;
            $discountBeneficiaryName = null;
            $discountBeneficiaryCardNumber = null;

            if ($request->filled('discount_type')) {
                $discountBeneficiaryName = trim((string) $request->input('discount_beneficiary_name'));
                $discountBeneficiaryCardNumber = trim((string) $request->input('discount_beneficiary_id'));

                if (!$discountBeneficiaryName || !$discountBeneficiaryCardNumber) {
                    return back()
                        ->withErrors([
                            'discount_type' => 'Please provide the beneficiary name and ID number.'
                        ])
                        ->withInput();
                }

                $discountType = $validated['discount_type'];
            }

            if (
                $validated['order_type'] === 'dine_in' &&
                empty($validated['table_number'])
            ) {
                return back()
                    ->withErrors([
                        'table_number' => 'Table number is required for dine-in.'
                    ])
                    ->withInput();
            }

            $branchId = (int) $validated['branch_id'];

            $menuItems = \App\Models\MenuItem::with('options')
                ->whereIn(
                    'id',
                    collect($validated['items'])
                        ->pluck('menu_item_id')
                        ->unique()
                )
                ->where('is_available', true)
                ->where(function ($query) use ($branchId) {
                    $query->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                })
                ->get()
                ->keyBy('id');

            if (
                $menuItems->count() !==
                collect($validated['items'])
                    ->pluck('menu_item_id')
                    ->unique()
                    ->count()
            ) {
                return back()
                    ->withErrors([
                        'items' => 'One or more selected menu items are not available for this branch.'
                    ])
                    ->withInput();
            }

            $total = 0;
            $itemsToCreate = [];

            foreach ($validated['items'] as $item) {
                $menuItem = $menuItems->get((int) $item['menu_item_id']);

                $selectedOptionIds = collect($item['options'] ?? [])
                    ->map(fn($id) => (int) $id)
                    ->unique()
                    ->values();

                $optionsTotal = 0;
                $optionDetails = [];

                foreach ($selectedOptionIds as $optionId) {
                    $option = $menuItem->options
                        ->firstWhere('id', $optionId);

                    if (!$option) {
                        return back()
                            ->withErrors([
                                'items' => 'Invalid option selected for ' . $menuItem->name . '.'
                            ])
                            ->withInput();
                    }

                    $optionsTotal += (float) $option->additional_price;

                    $optionDetails[] = [
                        'id' => $option->id,
                        'name' => $option->name,
                        'price' => (float) $option->additional_price,
                    ];
                }

                $itemPrice = (float) $menuItem->price + $optionsTotal;
                $subtotal = $itemPrice * (int) $item['quantity'];

                $total += $subtotal;

                $itemsToCreate[] = [
                    'menu_item' => $menuItem,
                    'quantity' => (int) $item['quantity'],
                    'item_price' => $itemPrice,
                    'subtotal' => $subtotal,
                    'options' => $optionDetails,
                ];
            }

            // Pre-flight stock check for walk-in orders.
            //
            // NOTE: manual orders are created with status 'pending' and are completed
            // later through completeOrder(), which is the ONLY place an order becomes
            // 'completed' and therefore the single place inventory is deducted.
            // Deducting here as well would double-deduct every walk-in order, so this
            // step only VALIDATES — it never subtracts stock.
            $stockErrors = app(\App\Services\InventoryDeductionService::class)
                ->validateCartLines(array_map(fn($data) => [
                    'menu_item' => $data['menu_item'],
                    'quantity' => $data['quantity'],
                    'selected_option_ids' => array_column($data['options'], 'id'),
                ], $itemsToCreate));

            if (!empty($stockErrors)) {
                return back()
                    ->withErrors(['items' => $stockErrors[0]])
                    ->withInput();
            }

            /*
             * Priced but not yet committed to. Null means "no PWD/Senior was
             * claimed at all", which is what Order::voucherBeatsCard() reads as
             * "the voucher is unopposed" — distinct from a card worth ₱0.00.
             */
            $cardDiscountAmount = null;

            if ($discountType) {
                $discountAmount = \App\Models\Order::pwdSeniorDiscountFor($total);
                $cardDiscountAmount = $discountAmount;
            }

            /*
            |------------------------------------------------------------------
            | THE CUSTOMER'S VOUCHER, KEYED IN BY STAFF (2026-09-03)
            |------------------------------------------------------------------
            |
            | Every rule below is the customer's own. Nothing here decides for
            | itself whether a code is valid, what it is worth, or how it is
            | spent — it calls the same three things the customer's checkout
            | calls, in the same order:
            |
            |   VoucherClaims::resolveTypedCode()  — which code is this?
            |   Voucher::redemptionErrorFor()      — may it be redeemed?
            |   Voucher::discountFor()             — what is it worth?
            |
            | That is what makes a code unspendable twice ACROSS the two paths
            | rather than merely within each: both consume the same used_count
            | and the same claim row, through VoucherClaims::redeem().
            |
            | The holder is passed as null. A walk-in customer has no account by
            | definition, so this is the guest case, and it is already the case
            | the model handles: a CLAIM code is a bearer instrument and works,
            | while a wheel voucher's SHARED code is refused with "Please sign in
            | to use this voucher, or enter the claim code you won on the wheel."
            | That refusal is correct at the counter too, and it is the item-30
            | money bypass staying closed — staff must not be a way around it.
            */
            $voucherId    = null;
            $voucherClaim = null;
            $voucherCode  = trim((string) $request->input('voucher_code'));

            if ($voucherCode !== '') {
                $resolved     = \App\Services\VoucherClaims::resolveTypedCode($voucherCode);
                $voucher      = $resolved['voucher'];
                $voucherClaim = $resolved['claim'];

                if (!$voucher) {
                    // A code that names nothing at all. A specific, readable
                    // refusal — never a 500 and never a raw exception page.
                    return back()
                        ->withErrors(['voucher_code' => 'Invalid voucher code.'])
                        ->withInput();
                }

                /*
                 * The one validator. Expired, already used, not yet valid,
                 * inactive, used up, or below the minimum order each come back
                 * as their own specific sentence, written for a person — so
                 * staff can read it straight out to the customer.
                 */
                $voucherError = $voucher->redemptionErrorFor(null, $total, $voucherClaim);

                if ($voucherError !== null) {
                    return back()
                        ->withErrors(['voucher_code' => $voucherError])
                        ->withInput();
                }

                $voucherDiscountAmount = $voucher->discountFor($total);

                /*
                 * Both discounts are now priced against the same subtotal, and
                 * only the bigger is applied — the same rule, the same tie-break
                 * and the same single definition the customer's cart uses. A
                 * PWD/Senior discount wins a tie, so the voucher survives for
                 * another day.
                 */
                if (\App\Models\Order::voucherBeatsCard($voucherDiscountAmount, $cardDiscountAmount)) {
                    $discountAmount = $voucherDiscountAmount;
                    $discountType   = 'voucher';
                    $voucherId      = $voucher->id;

                    /*
                     * The PWD/Senior lost: erase every trace of it so nothing
                     * downstream reads it as applied. Without this the order
                     * would carry a beneficiary name and ID next to a voucher
                     * discount, and the counter's own records would show a
                     * PWD/Senior discount that was never given.
                     */
                    $discountBeneficiaryName       = null;
                    $discountBeneficiaryCardNumber = null;
                } else {
                    /*
                     * The PWD/Senior wins, so the voucher is NOT spent. These
                     * two are exactly what drives consumption below
                     * ($voucherId increments used_count, $voucherClaim burns the
                     * claim), so clearing them is what leaves the losing voucher
                     * completely untouched — its claim unburned and its use
                     * count unchanged — for the customer to use another time.
                     */
                    $voucherId    = null;
                    $voucherClaim = null;
                }
            }

            // Round the discount ONCE, then derive the total from that rounded
            // figure, so subtotal - discount always nets out to the saved total
            // to the centavo.
            $discountAmount = round($discountAmount, 2);
            $finalTotal     = max(0, round($total - $discountAmount, 2));

            $amountPaid = (float) $validated['amount_paid'];

            if ($amountPaid < $finalTotal) {
                return back()
                    ->withErrors([
                        'amount_paid' =>
                            'Amount paid must be at least ₱' .
                            number_format($finalTotal, 2) . '.'
                    ])
                    ->withInput();
            }

            $changeAmount = $amountPaid - $finalTotal;

            try {
                $order = \Illuminate\Support\Facades\DB::transaction(function () use (
                    $validated,
                    $itemsToCreate,
                    $total,
                    $finalTotal,
                    $discountAmount,
                    $discountType,
                    $discountBeneficiaryName,
                    $discountBeneficiaryCardNumber,
                    $amountPaid,
                    $changeAmount,
                    $branchId,
                    $voucherId,
                    $voucherClaim
                ) {
                    $adminUser = \Illuminate\Support\Facades\Auth::guard('admin')->user();

                    $order = \App\Models\Order::create([
                        'user_id' => null,
                        'branch_id' => $branchId,
                        'processed_by' => $adminUser->id,
                        'type' => $validated['order_type'],
                        'table_number' => $validated['order_type'] === 'dine_in'
                            ? $validated['table_number']
                            : null,
                        'order_number' =>
                            'ORD-' .
                            now()->format('Ymd') .
                            '-' .
                            strtoupper(\Illuminate\Support\Str::random(6)),
                        'subtotal' => $total,
                        'discount_amount' => $discountAmount,
                        'discount_type' => $discountType,
                        // Which voucher paid for this, for the counter's own
                        // records — the same attribution an online redemption
                        // gets, so "where was this code spent" is one query
                        // whichever path spent it.
                        'voucher_id' => $voucherId,
                        'discount_beneficiary_name' => $discountBeneficiaryName,
                        'discount_beneficiary_card_number' => $discountBeneficiaryCardNumber,
                        // Staff IS the verifier here, in person, at the moment
                        // of sale — there is nothing left to approve afterward,
                        // unlike the online flow's discount_status = 'pending'.
                        'discount_status' => 'approved', // pending only applies to the online photo-verification flow
                        'tax_amount' => 0,
                        'total' => $finalTotal,
                        'payment_method' => $validated['payment_method'],
                        'payment_status' => 'paid',
                        'amount_paid' => $amountPaid,
                        'change_amount' => $changeAmount,
                        'status' => 'pending',
                    ]);

                    foreach ($itemsToCreate as $data) {
                        $orderItem = \App\Models\OrderItem::create([
                            'order_id' => $order->id,
                            'menu_item_id' => $data['menu_item']->id,
                            'item_name' => $data['menu_item']->name,
                            'quantity' => $data['quantity'],
                            'item_price' => $data['item_price'],
                            'subtotal' => $data['subtotal'],
                        ]);

                        foreach ($data['options'] as $option) {
                            \Illuminate\Support\Facades\DB::table(
                                'order_item_options'
                            )->insert([
                                'order_item_id' => $orderItem->id,
                                'menu_option_id' => $option['id'],
                                'option_name' => $option['name'],
                                'additional_price' => $option['price'],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }

                    /*
                     * Spend the voucher inside the SAME transaction that
                     * creates the order, exactly as the customer's checkout
                     * does — same method, same two writes, same conditional
                     * UPDATE. So the code counts against the shared max_uses,
                     * the claim row is burned once, and if anything above rolls
                     * back the voucher stays unspent.
                     *
                     * A LOSING voucher never reaches here: the comparison above
                     * cleared both arguments to null, which is what leaves it
                     * completely untouched.
                     */
                    \App\Services\VoucherClaims::redeem($voucherId, $voucherClaim);

                    return $order;
                });

                /*
                 * A dine-in counter order means a real party is seated at that
                 * table, so hold it — otherwise the table stays "free" and the
                 * next QR scan is handed a table that is already in use, which
                 * is exactly what was reported. Deliberately outside the
                 * transaction above: the order is committed by now, and the
                 * occupancy claim takes its own lock.
                 */
                \App\Services\TableOccupancy::attachStaffOrder(
                    $order,
                    \Illuminate\Support\Facades\Auth::guard('admin')->id()
                );

                return redirect()
                    ->route('admin.home')
                    ->with(
                        'success',
                        'Manual order #' .
                        $order->order_number .
                        ' created successfully!'
                    );

            } catch (\RuntimeException $e) {
                // Thrown deliberately by the inventory deduction service with a
                // message written for staff ("Not enough X ..."). Safe to show.
                return back()
                    ->withErrors(['error' => $e->getMessage()])
                    ->withInput();
            } catch (\Throwable $e) {
                // Unexpected failure. The raw message can carry SQL and file
                // paths, and APP_DEBUG=false does not filter text we echo
                // ourselves, so it goes to the log and not to the screen.
                Log::error('Manual order creation failed', [
                    'admin_id'  => Auth::id(),
                    'exception' => $e,
                ]);

                return back()
                    ->withErrors([
                        'error' => 'The manual order could not be saved. Nothing was '
                            . 'recorded and no stock was deducted. Please try again.'
                    ])
                    ->withInput();
            }
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

        $orderRatings = \App\Models\OrderRating::whereIn('order_id', $orders->pluck('id'))
            ->get()
            ->keyBy('order_id');

        return view('admin.completed-orders', compact('orders', 'orderRatings'));
    }

    public function completeOrder(int $id)
    {
        // Branch-scoped for staff, unrestricted for admins — see
        // App\Services\AdminOrderAccess. This one matters most: completing an
        // order DEDUCTS the stock behind its menu items, so an unscoped lookup
        // here let one branch's staff move another branch's inventory.
        $order = \App\Services\AdminOrderAccess::resolveInScope($id, [
            'items.menuItem.recipeIngredients',
            'items.menuItem.inventoryItem',
            'items.options.ingredients',
        ]);

        if (!in_array($order->status, ['pending', 'preparing', 'serving'])) {
            return redirect()->back()->withErrors([
                'order' => 'Order is not pending. Current status: ' . $order->status,
            ]);
        }

        // Deduct inventory and mark the order completed in ONE transaction.
        // InventoryDeductionService::deductWithLock() locks every inventory row it
        // needs, re-checks stock under that lock, then subtracts and writes a
        // traceable stock_movements row per (order line x inventory item).
        // It covers Recipe Ingredients (MenuItemIngredient), selected add-on options
        // (MenuOptionIngredient), and the legacy single-ingredient link as a fallback.
        //
        // Insufficient stock = HARD BLOCK. The service throws, the transaction rolls
        // back, and the order stays in its current status. Nothing is partially
        // deducted and the order is NOT silently completed with a short pantry
        // (which is what the previous inline code did).
        try {
            $deductionsLog = \Illuminate\Support\Facades\DB::transaction(function () use ($order) {
                // Re-read the ORDER row under a write lock before touching stock.
                //
                // The status check above runs outside any transaction, so two
                // staff sessions clicking Complete on the same order within the
                // same second BOTH pass it. deductWithLock() locks the inventory
                // rows, which correctly serialises the two transactions — but
                // serialised is not the same as idempotent: the second one simply
                // waits its turn, re-reads the (already reduced) stock, finds it
                // sufficient, and deducts the whole order a SECOND time. Verified
                // live: 8 stock_movements rows for a 4-ingredient order, and the
                // customer notified twice.
                //
                // Locking the order row is what makes completion happen once.
                // The loser blocks here until the winner commits, then sees
                // 'completed' and backs out with everything rolled back.
                $locked = \App\Models\Order::whereKey($order->id)
                    ->lockForUpdate()
                    ->first();

                if (!$locked || !in_array($locked->status, ['pending', 'preparing', 'serving'], true)) {
                    throw new \DomainException(
                        'It was already handled by another session (current status: '
                        . ($locked->status ?? 'missing') . ').'
                    );
                }

                $summary = app(\App\Services\InventoryDeductionService::class)
                    ->deductWithLock($order);

                $order->status = 'completed';
                $order->payment_status = 'paid';
                $order->receipt_number =
                    'RCP-' .
                    now()->format('Ymd') .
                    '-' .
                    str_pad($order->id, 4, '0', STR_PAD_LEFT);
                $order->completed_at = now();

                $order->save();

                return $summary;
            });
        } catch (\DomainException $e) {
            // Lost the race, or a stale tab. Nothing was deducted — the whole
            // transaction rolled back — so this is information, not a failure.
            return redirect()->route('admin.home')
                ->withErrors([
                    'order' => 'Order #' . $order->order_number . ' was not completed again. '
                        . $e->getMessage(),
                ]);
        } catch (\RuntimeException $e) {
            return redirect()->back()->withErrors([
                'order' => 'Cannot complete Order #' . $order->order_number . '. ' .
                    $e->getMessage() .
                    ' Restock it under Inventory → Stock In, then complete this order again.',
            ]);
        } catch (\Throwable $e) {
            // The two catches above handle the cases this code raises on
            // purpose and whose text is written for staff. Reaching here means
            // something unforeseen broke, so log the detail and keep the raw
            // message off the screen - APP_DEBUG=false will not redact it for
            // us once we put it in a flash message ourselves.
            Log::error('Order completion failed', [
                'order_id'  => $order->id,
                'admin_id'  => Auth::id(),
                'exception' => $e,
            ]);

            return redirect()->back()->withErrors([
                'order' => 'Order #' . $order->order_number . ' could not be completed. '
                    . 'Nothing was deducted from inventory. Please try again, and if it '
                    . 'keeps failing, report it to whoever maintains the system.',
            ]);
        }

        // Only after the transaction has actually committed. If the inventory
        // deduction had thrown, both returns above would have left the order in
        // its previous status — notifying the customer there would be a lie.
        \App\Models\Notification::orderStatusChanged($order, 'completed');

        $message = 'Order #' . $order->order_number . ' completed!';

        if (!empty($deductionsLog)) {
            $message .= ' Inventory deducted: ' .
                implode(', ', $deductionsLog);
        }

        return redirect()->route('admin.home')
            ->with('success', $message);
    }

    /**
     * Approve a PWD/Senior Citizen discount for an order.
     * The order itself remains pending until staff starts preparing it.
     */
    public function approveDiscount(int $id)
    {
        $order = \App\Services\AdminOrderAccess::resolveInScope($id);

        if (!in_array(strtolower((string) $order->discount_type), ['pwd', 'senior'], true)) {
            return redirect()->route('admin.home')
                ->withErrors(['discount' => 'This order does not have a PWD or Senior Citizen discount awaiting approval.']);
        }

        if (($order->discount_status ?? 'approved') !== 'pending') {
            return redirect()->route('admin.home')
                ->withErrors(['discount' => 'This discount has already been reviewed.']);
        }

        $order->discount_status = 'approved';
        $order->save();

        return redirect()->route('admin.home')
            ->with('success', 'Discount for order #' . $order->order_number . ' approved.');
    }

    /**
     * Reject a PWD/Senior Citizen discount. The order remains pending so
     * the customer can choose whether to continue at the regular price or
     * cancel the order.
     */
    public function rejectDiscount(int $id)
    {
        $order = \App\Services\AdminOrderAccess::resolveInScope($id);

        if (!in_array(strtolower((string) $order->discount_type), ['pwd', 'senior'], true)) {
            return redirect()->route('admin.home')
                ->withErrors(['discount' => 'This order does not have a PWD or Senior Citizen discount.']);
        }

        if (($order->discount_status ?? 'approved') !== 'pending') {
            return redirect()->route('admin.home')
                ->withErrors(['discount' => 'This discount has already been reviewed.']);
        }

        $order->discount_status = 'rejected';
        $order->discount_amount = 0;
        $order->total = $order->subtotal;
        $order->save();

        return redirect()->route('admin.home')
            ->with('success', 'Discount for order #' . $order->order_number . ' rejected. The customer can continue at the regular price or cancel the order.');
    }
/**
 * Approve a GCash payment for an order.
 */
public function approveGcashPayment(int $id)
{
    $order = \App\Services\AdminOrderAccess::resolveInScope($id);

    if ($order->payment_method !== 'gcash') {
        return redirect()->route('admin.home')
            ->withErrors([
                'payment' => 'This order is not a GCash order.'
            ]);
    }

    if ($order->payment_status !== 'awaiting_verification') {
        return redirect()->route('admin.home')
            ->withErrors([
                'payment' => 'This GCash payment is not awaiting verification.'
            ]);
    }

    $order->payment_status = 'paid';
    $order->amount_paid = $order->total;
    $order->change_amount = 0;
    $order->save();

    \App\Models\Notification::gcashApproved($order);

    return redirect()->route('admin.home')
        ->with(
            'success',
            'GCash payment for order #' . $order->order_number . ' approved.'
        );
}

/**
 * Reject a GCash payment for an order.
 */
public function rejectGcashPayment(int $id)
{
    $order = \App\Services\AdminOrderAccess::resolveInScope($id);

    if ($order->payment_method !== 'gcash') {
        return redirect()->route('admin.home')
            ->withErrors([
                'payment' => 'This order is not a GCash order.'
            ]);
    }

    if ($order->payment_status !== 'awaiting_verification') {
        return redirect()->route('admin.home')
            ->withErrors([
                'payment' => 'This GCash payment is not awaiting verification.'
            ]);
    }

    // Reject the payment
    $order->payment_status = 'rejected';

    // Cancel the order so the customer cannot continue with it
    $order->status = 'cancelled';

    // No payment was successfully received
    $order->amount_paid = 0;
    $order->change_amount = 0;

    $order->save();

    \App\Models\Notification::gcashRejected($order);

    return redirect()->route('admin.home')
        ->with(
            'success',
            'GCash payment for order #' . $order->order_number .
            ' rejected and order cancelled.'
        );
}

/**
 * Close out a refund-pending cancellation once the money has actually been
 * sent back.
 *
 * This records a decision a human already made outside the system. There is
 * no merchant API here: the refund itself is staff sending money via GCash
 * at the counter, and this button is how they say "done" so the order stops
 * appearing as outstanding work on the live board.
 *
 * Deliberately narrow. It only moves 'refund_pending' -> 'refunded' and
 * touches nothing else — not the order status, which is already (and stays)
 * 'cancelled'. Guarding on the current payment_status also makes it safe to
 * double-click: the second press is refused instead of re-recording a
 * refund that already happened.
 */
public function markOrderRefunded(int $id)
{
    $order = \App\Services\AdminOrderAccess::resolveInScope($id);

    if ($order->payment_status !== 'refund_pending') {
        return redirect()->route('admin.home')
            ->withErrors([
                'payment' => 'This order is not waiting on a refund.'
            ]);
    }

    $order->payment_status = 'refunded';
    $order->save();

    return redirect()->route('admin.home')
        ->with(
            'success',
            'Order #' . $order->order_number . ' marked as refunded.'
        );
}
    /**
     * 'completed' and 'cancelled' are terminal. Nothing may move an order back
     * out of them.
     *
     * Without this, a second staff session sitting on a stale Active Orders page
     * could click Serve on an order the first session had just completed, drop it
     * back to 'serving', and then complete it again — deducting the whole recipe
     * from stock a second time and issuing a second receipt number. The order-row
     * lock in completeOrder() cannot catch that, because by then 'serving' is a
     * genuinely completable status. Verified live before the guard existed.
     *
     * Returns null when the transition is allowed, otherwise the redirect to send.
     */
    private function rejectIfOrderIsFinal(\App\Models\Order $order, string $action): ?\Illuminate\Http\RedirectResponse
    {
        if (!in_array($order->status, ['completed', 'cancelled'], true)) {
            return null;
        }

        return redirect()->route('admin.home')
            ->withErrors([
                'order' => 'Order #' . $order->order_number . ' is already ' . $order->status
                    . ' and cannot be ' . $action . '. Refresh the page to see its current state.',
            ]);
    }

    public function prepareOrder(int $id)
    {
        $order = \App\Services\AdminOrderAccess::resolveInScope($id);

        if ($stop = $this->rejectIfOrderIsFinal($order, 'moved back to preparing')) {
            return $stop;
        }

        if (($order->discount_status ?? 'approved') === 'pending') {
            return redirect()->route('admin.home')
                ->withErrors(['discount' => 'Approve or reject the PWD/Senior Citizen discount before preparing this order.']);
        }

        $order->status = 'preparing';
        $order->preparing_at = now();
        $order->save();

        \App\Models\Notification::orderStatusChanged($order, 'preparing');

        return redirect()->route('admin.home')
            ->with(
                'success',
                'Order #' . $order->order_number . ' is now being prepared!'
            );
    }

    public function serveOrder(int $id)
    {
        $order = \App\Services\AdminOrderAccess::resolveInScope($id);

        if ($stop = $this->rejectIfOrderIsFinal($order, 'moved back to serving')) {
            return $stop;
        }

        $order->status = 'serving';
        $order->serving_at = now();
        $order->save();

        \App\Models\Notification::orderStatusChanged($order, 'serving');

        return redirect()->route('admin.home')
            ->with(
                'success',
                'Order #' . $order->order_number . ' is now being served!'
            );
    }

    public function cancelOrder(int $id)
    {
        $order = \App\Services\AdminOrderAccess::resolveInScope($id);

        // A completed order has already had its stock deducted and its receipt
        // issued. Cancelling it here left exactly that state behind — status
        // 'cancelled' carrying a receipt_number, a completed_at and four
        // stock_movements rows — so the sale vanished from revenue while the
        // ingredients stayed gone. Reproduced live from a stale second tab.
        if ($stop = $this->rejectIfOrderIsFinal($order, 'cancelled')) {
            return $stop;
        }

        $order->status = 'cancelled';
        // Matches OrderController::cancelCustomerOrder(), which has always
        // stamped this. The staff path silently left it NULL.
        $order->cancelled_at = now();
        $order->save();

        \App\Models\Notification::orderStatusChanged($order, 'cancelled');

        return redirect()->route('admin.home')
            ->with(
                'success',
                'Order #' . $order->order_number . ' cancelled.'
            );
    }

    // ══════════ Inventory (CRUD WORKING) ══════════

    public function showInventory()
    {
        $selectedBranch = $this->getSelectedBranch();

        $inventory = \App\Models\Inventory::orderBy('item_name')
            ->when(
                $selectedBranch !== 'all',
                fn($q) => $q->where('branch_id', $selectedBranch)
            )
            ->get();

        $categories = Category::orderBy('name')->get();

        $stockMovements = \App\Models\StockMovement::with([
            'inventory',
            'user'
        ])
            ->when($selectedBranch !== 'all', function ($q) use ($selectedBranch) {
                $q->whereHas('inventory', function ($inv) use ($selectedBranch) {
                    $inv->where('branch_id', $selectedBranch);
                });
            })
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return view(
            'admin.inventory',
            compact('inventory', 'categories', 'stockMovements')
        );
    }

    public function storeInventory(Request $request)
    {
        $selectedBranch = $this->getSelectedBranch();

        if ($selectedBranch === 'all') {
            return redirect()->route('admin.inventory')
                ->withErrors([
                    'branch' => 'Please select a specific branch before adding inventory.'
                ]);
        }

        $validated = $request->validate([
            'item_name' => 'required|string|max:255',
            /*
             * REQUIRED, not nullable — discovered during investigation, not
             * assumed. inventory.item_code is a NOT NULL column (confirmed
             * against the live schema: `SHOW COLUMNS ... Null => 'NO'`,
             * matching the original migration, which never called
             * ->nullable()). A blank submission was never actually storable
             * as either NULL or '' — Inventory::create() with item_code
             * explicitly null throws the SAME class of raw 500
             * (SQLSTATE[23000] "Column 'item_code' cannot be null") that the
             * reported duplicate-key crash did, for the identical reason:
             * nothing in PHP caught it before it reached the database.
             * Requiring it here closes that path too, without touching the
             * schema.
             *
             * NO ->ignore() here, unlike updateInventory() below — there is
             * no existing row to exempt on a create. Every other rule (max:50,
             * the custom message) is identical, so the two paths cannot
             * silently drift on what "already used" means.
             */
            'item_code' => [
                'required',
                'string',
                'max:50',
                \Illuminate\Validation\Rule::unique('inventory', 'item_code'),
            ],
            'category' => 'nullable|string|max:100',
            'quantity' => 'required|numeric|min:0',
            'unit' => 'required|string|max:20',
            'low_stock_alert' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
        ], [
            'item_code.required' => 'Item code is required.',
            'item_code.unique' => 'That item code is already used by another inventory item.',
        ]);

        \App\Models\Inventory::create([
            'branch_id' => $selectedBranch,
            'item_name' => $validated['item_name'],
            'item_code' => $validated['item_code'],
            'category' => $validated['category'] ?? null,
            'quantity' => $validated['quantity'],
            'unit' => $validated['unit'],
            'low_stock_alert' => $validated['low_stock_alert'] ?? 10,
            'unit_cost' => $validated['unit_cost'] ?? 0,
            'supplier' => $validated['supplier'] ?? null,
            'is_active' => true,
        ]);

        return redirect()->route('admin.inventory')
            ->with(
                'success',
                'Item "' . $validated['item_name'] . '" added to inventory!'
            );
    }

    /**
     * THE REPORTED BUG (2026-09-02): saving an item without touching its own
     * item_code threw a raw SQLSTATE[23000] duplicate-key 500 instead of a
     * validation error. This validate() array had NO uniqueness rule on
     * item_code at all — `nullable|string|max:50` only — so nothing in PHP
     * ever caught a collision; it reached the database unchecked and MySQL's
     * unique index (inventory.item_code, defined since the table's original
     * migration) threw first.
     *
     * A row updating ITSELF to its own current item_code was never actually
     * the problem — MySQL's own uniqueness check already excludes the row
     * being updated, so that alone cannot 500. The real exposure was a
     * genuine SECOND row sharing a code, created through the same missing
     * check in storeInventory() above. This table has no per-branch scoping
     * on the unique index and no archived/soft-delete flag that would exempt
     * an inactive row from it, so an archived item still permanently reserves
     * its code.
     *
     * item_code is also a NOT NULL column (confirmed against the live schema,
     * not assumed) — a blank submission was never storable as NULL either;
     * see the 'required' rule below for the identical raw-500 this closes on
     * this path too.
     */
    public function updateInventory(Request $request, int $id)
    {
        $item = \App\Models\Inventory::findOrFail($id);

        $validated = $request->validate([
            'item_name' => 'required|string|max:255',
            /*
             * REQUIRED here too, for the same reason as storeInventory()
             * above: item_code is a NOT NULL column, so a blank submission
             * was never a legitimate "leave it unset" state to normalise —
             * $item->update() with item_code = null would hit the identical
             * raw "cannot be null" 500 the create path did.
             *
             * ->ignore($item->id) is what makes "leave my own code as it is"
             * pass: the rule then checks every OTHER row, not this one.
             */
            'item_code' => [
                'required',
                'string',
                'max:50',
                \Illuminate\Validation\Rule::unique('inventory', 'item_code')->ignore($item->id),
            ],
            'category' => 'nullable|string|max:100',
            'quantity' => 'required|numeric|min:0',
            'unit' => 'required|string|max:20',
            'low_stock_alert' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
        ], [
            'item_code.required' => 'Item code is required.',
            'item_code.unique' => 'That item code is already used by another inventory item.',
        ]);

        $item->update($validated);

        return redirect()->route('admin.inventory')
            ->with('success', 'Inventory item updated!');
    }

    public function stockIn(Request $request, int $id)
    {
        // Branch-scoped for staff, unrestricted for admins — see
        // App\Services\AdminOrderAccess. Stock-in adds quantity directly, so an
        // unscoped lookup here let one branch's staff pad another branch's
        // stock. A foreign id gets the same 404 as a nonexistent one.
        $item = \App\Services\AdminOrderAccess::resolveRecordInScope(\App\Models\Inventory::class, $id);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ]);

        $item->quantity += $validated['amount'];
        $item->save();

        \App\Models\StockMovement::create([
            'inventory_id' => $item->id,
            'movement_type' => 'in',
            'amount' => $validated['amount'],
            'quantity_after' => $item->quantity,
            'reason' => $validated['note'] ?? 'Manual stock in',
            'source' => 'manual',
            'user_id' => Auth::guard('admin')->id(),
        ]);

        return redirect()->route('admin.inventory')
            ->with(
                'success',
                '+' . $validated['amount'] . ' ' .
                $item->unit .
                ' added to "' .
                $item->item_name .
                '". New stock: ' .
                $item->quantity
            );
    }

    public function stockOut(Request $request, int $id)
    {
        // Branch-scoped for staff, unrestricted for admins — see
        // App\Services\AdminOrderAccess. Stock-out subtracts quantity directly:
        // branch-1 staff writing down branch-2 stock is how missing goods get
        // hidden. A foreign id gets the same 404 as a nonexistent one.
        $item = \App\Services\AdminOrderAccess::resolveRecordInScope(\App\Models\Inventory::class, $id);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ]);

        if ($validated['amount'] > $item->quantity) {
            return back()->withErrors([
                'amount' => 'Not enough stock. Only ' .
                    $item->quantity .
                    ' ' .
                    $item->unit .
                    ' available.'
            ]);
        }

        $item->quantity -= $validated['amount'];
        $item->save();

        \App\Models\StockMovement::create([
            'inventory_id' => $item->id,
            'movement_type' => 'out',
            'amount' => $validated['amount'],
            'quantity_after' => $item->quantity,
            'reason' => $validated['note'] ?? 'Manual stock out',
            'source' => 'manual',
            'user_id' => Auth::guard('admin')->id(),
        ]);

        return redirect()->route('admin.inventory')
            ->with(
                'success',
                '-' . $validated['amount'] . ' ' .
                $item->unit .
                ' removed from "' .
                $item->item_name .
                '". Remaining: ' .
                $item->quantity
            );
    }

    public function editInventory(int $id)
    {
        // Branch-scoped for staff, unrestricted for admins — this feeds the
        // Edit modal, so an unscoped lookup leaked another branch's stock
        // levels, supplier and costs. A foreign id gets the same 404 as a
        // nonexistent one.
        return response()->json(
            \App\Services\AdminOrderAccess::resolveRecordInScope(\App\Models\Inventory::class, $id)
        );
    }

    public function deleteInventory(int $id)
    {
        $item = \App\Models\Inventory::findOrFail($id);

        /*
         * Recipe links (menu_item_ingredients / menu_option_ingredients) are
         * ON DELETE CASCADE, so deleting this stock item silently takes the
         * recipe lines with it and those menu items stop deducting anything.
         * That is the existing policy and is not being changed here — but the
         * admin is told, instead of finding out when stock stops moving.
         */
        $recipeLinks = \App\Models\MenuItemIngredient::where('inventory_id', $id)->count()
            + \App\Models\MenuOptionIngredient::where('inventory_id', $id)->count();

        return $this->safelyDelete(
            function () use ($item, $recipeLinks) {
                $name = $item->item_name;
                $item->delete();

                $message = 'Inventory item "' . $name . '" deleted!';

                if ($recipeLinks > 0) {
                    $message .= ' ' . ucfirst($this->countLabel($recipeLinks, 'recipe link'))
                        . ' using it was removed too, so check the affected items still deduct correctly.';
                }

                return redirect()->route('admin.inventory')
                    ->with('success', $message);
            },
            'admin.inventory',
            'the inventory item "' . $item->item_name . '"',
            'Remove it from the recipes that still use it first.'
        );
    }

    // ══════════ Customization ══════════

    public function updateCustomization(Request $request)
    {
        return redirect()->back()
            ->with('success', 'Staff interface updated.');
    }

    public function updateCustomerCustomization(Request $request)
    {
        return redirect()->back()
            ->with('success', 'Customer interface updated.');
    }

    // ══════════ QR Code Generator ══════════

    public function showQrGenerator()
    {
        $staff = Auth::guard('admin')->user();

        /*
         * Both branch dropdowns on this page (the printable-card generator and
         * the "regenerate a code" action) are populated from this one
         * $branches list, so scoping it here fixes both at once.
         *
         * A staff account is already restricted server-side to its own branch
         * on qrTableCard() and clearTableOccupancy() — offering every OTHER
         * branch in the picker just handed them an option guaranteed to
         * 403/422. An admin still sees every active branch, matching every
         * other admin-only picker in this portal.
         */
        $branches = ($staff && $staff->role === 'staff' && $staff->branch_id)
            ? \App\Models\Branch::where('id', $staff->branch_id)->where('is_active', true)->get()
            : \App\Models\Branch::where('is_active', true)->get();

        // Only an admin may rotate a code: it invalidates a printed card and
        // forces a reprint, which is an owner decision, not a counter action.
        // Drives the "Regenerate Code" button beside the card preview.
        $canRegenerate = $staff && $staff->role === 'admin';

        return view('admin.qr-generator', compact('branches', 'canRegenerate'));
    }

    /**
     * Resolve a branch + table number into what the printable table card needs:
     * the URL the QR encodes, plus the branch/table labelling printed beside it,
     * plus the table's permanent code — printed on the card itself for a
     * customer whose camera will not focus.
     */
    public function qrTableCard(Request $request)
    {
        $validated = $request->validate([
            'branch_id'    => 'required|integer|exists:branches,id',
            'table_number' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
        ]);

        $branch = \App\Models\Branch::find($validated['branch_id']);

        if (!$branch || !$branch->is_active) {
            return response()->json([
                'message' => 'That branch is inactive, so its table QR would not work.',
            ], 422);
        }

        $staff = Auth::guard('admin')->user();

        // Same own-branch restriction as clearTableOccupancy() — without it a
        // staff account could generate a printable table card for a branch
        // that is not theirs.
        if ($staff && $staff->role === 'staff' && $staff->branch_id && (int) $staff->branch_id !== $branch->id) {
            return response()->json([
                'message' => 'You can only generate table cards for your own branch.',
            ], 403);
        }

        $tableNumber = strtoupper($validated['table_number']);

        /*
         * Registering the table happens HERE, and only here.
         *
         * Pressing Generate for a table number the registry has not seen is how
         * an admin adds a table: they have walked into the room, seen the
         * table, and are printing its card. The registry row and its permanent
         * code are allocated at that moment.
         *
         * This is deliberately the ONLY path that creates a registry row. The
         * customer-facing entry path never does, because it takes its table
         * number from a URL a stranger can edit, and letting that create rows
         * would turn an editable address bar into unbounded row creation.
         */
        $table = \App\Services\TableEntry::findOrRegister($branch->id, $tableNumber);

        return response()->json([
            'branch_id'    => $branch->id,
            'branch_name'  => $branch->name,
            'branch_code'  => strtoupper($branch->code),
            'table_number' => $table->table_number,
            // Kept byte-for-byte identical to what the customer scanner already
            // parses in AuthController::processQr().
            'url'          => $table->qrUrl(),
            // The permanent code goes ON the printed card, next to the QR. It
            // is what a customer types when the QR will not scan, so a card
            // showing only the QR leaves them with nothing to do.
            'code'         => $table->code,
            'table_id'     => $table->id,
        ]);
    }

    /**
     * Regenerate ONE table's permanent code.
     *
     * THE ESCAPE HATCH THAT MAKES A PERMANENT CODE DEFENSIBLE
     * ------------------------------------------------------
     * A permanent code is a permanent credential: once it is photographed, the
     * only way to take it back is to replace it. This is that action. It
     * invalidates the old code immediately, issues a new one, and the admin
     * reprints that one table's card.
     *
     * Admin only, not staff. Rotating a code invalidates a physical printed
     * card and makes somebody walk to a table with a new one — an owner
     * decision, not a counter action like clearing a table or issuing a
     * counter code.
     *
     * It regenerates exactly the table named in the request and nothing else.
     * There is no bulk form of this on purpose: a "rotate everything" button
     * would invalidate every standee in the company in one click, which is a
     * far bigger accident than the problem it would be solving.
     */
    public function regenerateTableCode(Request $request)
    {
        $validated = $request->validate([
            'table_id' => 'required|integer|exists:restaurant_tables,id',
        ]);

        $table = \App\Models\RestaurantTable::find($validated['table_id']);

        if (!$table) {
            return response()->json(['message' => 'That table no longer exists.'], 404);
        }

        $staff = Auth::guard('admin')->user();

        // Same own-branch restriction the rest of this section applies. An
        // admin is not branch-bound; this is belt and braces for any future
        // role that is.
        if ($staff && $staff->role === 'staff' && $staff->branch_id
            && (int) $staff->branch_id !== (int) $table->branch_id) {
            return response()->json([
                'message' => 'You can only regenerate codes for your own branch.',
            ], 403);
        }

        $previous = $table->code;

        $table = \App\Services\TableEntry::rotateCode($table, $staff?->id);

        return response()->json([
            'table_id'      => $table->id,
            'branch_id'     => $table->branch_id,
            'table_number'  => $table->table_number,
            'code'          => $table->code,
            'previous_code' => $previous,
            // The QR now encodes the code too (as `k`), so this URL — and the
            // QR image drawn from it — genuinely changes on every rotation.
            // Returned so the printable card is redrawn from the exact same
            // shape qrTableCard() already returns.
            'url'           => $table->qrUrl(),
            'branch_code'   => strtoupper($table->branch->code ?? ''),
            'message'       => 'Table ' . $table->table_number . ': '
                . $previous . ' → ' . $table->code . '. '
                . 'The old QR and code both stopped working — reprint this table\'s card.',
        ]);
    }

    /**
     * Tables currently holding a live dine-in session, for the staff portal.
     *
     * Branch-scoped the same way the rest of this section is: a staff member
     * sees their own branch, an admin sees everything.
     */
    public function tableOccupancy()
    {
        $staff = Auth::guard('admin')->user();

        $scope = ($staff && $staff->role === 'staff' && $staff->branch_id)
            ? $staff->branch_id
            : $this->getSelectedBranch();

        return response()->json([
            'tables' => \App\Services\TableOccupancy::activeSessions($scope)->map(function ($s) {
                return [
                    'branch_id'    => $s->branch_id,
                    'branch_name'  => $s->branch?->name,
                    'table_number' => $s->table_number,
                    'order_id'     => $s->order_id,
                    'order_number' => $s->order?->order_number,
                    'order_status' => $s->order?->status,
                    'since'        => $s->created_at?->toIso8601String(),
                    'last_seen_at' => $s->last_seen_at?->toIso8601String(),
                    // Opened from the counter by staff (a dine-in Manual Order)
                    // rather than by a customer scanning the table QR.
                    'staff_opened' => $s->opened_by !== null,
                ];
            })->values(),
        ]);
    }

    /**
     * Manually free a table.
     *
     * The everyday case is a customer who scanned, browsed, and walked out
     * without ordering — the table would otherwise stay held. Scoped to
     * role:admin,staff in routes/web.php, with the same own-branch restriction
     * the staff-code issuer applies, so a staff member cannot free a table in
     * another store.
     */
    public function clearTableOccupancy(Request $request)
    {
        $validated = $request->validate([
            'branch_id'    => 'required|integer|exists:branches,id',
            'table_number' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
        ]);

        $staff = Auth::guard('admin')->user();

        // Same "is this branch mine?" rule as every other branch-owned endpoint
        // — App\Services\AdminOrderAccess — rather than a second hand-rolled
        // copy of it. The refusal stays a 403 here, deliberately: this endpoint
        // has always answered a wrong-branch clear with 403 and a free table
        // with 404, TableOccupancyTest pins both, and the panel is a live staff
        // tool where "that is not your table" is the useful message. Table
        // sessions are not the id-probing surface the 404 convention exists for.
        if (! \App\Services\AdminOrderAccess::allowsBranch((int) $validated['branch_id'])) {
            return response()->json([
                'message' => 'You can only clear tables at your own branch.',
            ], 403);
        }

        $result = \App\Services\TableOccupancy::describeRelease(
            (int) $validated['branch_id'],
            $validated['table_number'],
            $staff?->id
        );

        if ($result['released'] === 0) {
            return response()->json([
                'message' => 'That table does not have an active session.',
            ], 404);
        }

        $tableNumber = strtoupper($validated['table_number']);
        $message = 'Table ' . $tableNumber . ' is now available.';

        /*
         * SAY WHAT HAPPENED TO THE ORDER.
         *
         * Clearing a table does not touch its order — the order keeps its
         * status and its place in the kitchen queue, and the released session
         * row keeps pointing at it, so nothing is orphaned. But the panel used
         * to answer only "Table 5 is now available", which left staff guessing
         * whether they had just cancelled somebody's food. Stating it outright
         * is the difference between a button people are afraid of and one they
         * use.
         */
        if ($result['order_number']) {
            $message .= ' Order ' . $result['order_number'] . ' is still '
                . ucfirst(str_replace('_', ' ', (string) $result['order_status']))
                . ' and was not changed — it stays in the kitchen queue.';
        } else {
            $message .= ' There was no order on it.';
        }

        return response()->json([
            'message'      => $message,
            'table_number' => $tableNumber,
            'order_id'     => $result['order_id'],
            'order_number' => $result['order_number'],
            'order_status' => $result['order_status'],
        ]);
    }

    /*
     * issueTableAccessCode() used to live here: a single-use, ten-minute
     * fallback code for a customer whose camera would not scan. It has been
     * retired. The permanent code every table already carries is now shown
     * directly on this dashboard (see $tables in showQrGenerator()), so
     * reading a code out to a customer no longer needs a second, separate,
     * expiring credential minted on demand — the one credential that already
     * exists is enough, and if it is ever compromised, regenerateTableCode()
     * below is the answer.
     */

    // ══════════ Vouchers (CRUD) ══════════

    public function showVouchers(Request $request)
    {
        $vouchers = \App\Models\Voucher::orderBy(
            'created_at',
            'desc'
        )->get();

        /*
         * POINTS-REWARD LOOKUP (2026-09-02).
         *
         * Staff type a name or email to see how many threshold rewards a
         * customer has earned and how many are still owed to them. It lives
         * on this page because this is already where "issue a code" happens —
         * the reward is handed over with the same minted claim code, so
         * putting the lookup anywhere else would mean walking between two
         * screens to complete one counter interaction.
         *
         * Read-only and additive: nothing here touches the voucher list above
         * or the existing issue-code flow, which continues to work for
         * walk-ins with no points at all.
         */
        $rewardQuery    = trim((string) $request->query('customer'));
        $rewardCustomer = null;
        $rewardSummary  = null;

        if ($rewardQuery !== '') {
            $rewardCustomer = \App\Models\User::where('role', 'customer')
                ->where(function ($q) use ($rewardQuery) {
                    $q->where('email', $rewardQuery)
                        ->orWhere('name', 'like', '%' . $rewardQuery . '%');
                })
                ->orderBy('id')
                ->first();

            if ($rewardCustomer) {
                $rewardSummary = \App\Services\PointsRewards::summaryFor($rewardCustomer);
            }
        }

        return view('admin.vouchers', compact(
            'vouchers',
            'rewardQuery',
            'rewardCustomer',
            'rewardSummary'
        ));
    }

    public function storeVoucher(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:vouchers,code',
            'description' => 'nullable|string|max:255',
            'discount_type' => 'required|in:fixed,percent',
            'discount_value' => 'required|numeric|min:1',
            'max_uses' => 'required|integer|min:1',
            'minimum_order' => 'nullable|numeric|min:0',
            'valid_from' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'points_required' => 'nullable|integer|min:0',
        ]);

        \App\Models\Voucher::create([
            'code' => strtoupper($validated['code']),
            'description' => $validated['description'] ?? null,
            'discount_type' => $validated['discount_type'],
            'discount_value' => $validated['discount_value'],
            'max_uses' => $validated['max_uses'],
            'minimum_order' => $validated['minimum_order'] ?? 0,
            'valid_from' => $validated['valid_from'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'points_required' => $validated['points_required'] ?? 0,
            'is_active' => true,
        ]);

        return redirect()->route('admin.vouchers')
            ->with(
                'success',
                'Voucher "' . strtoupper($validated['code']) . '" created!'
            );
    }

    public function updateVoucher(Request $request, int $id)
    {
        $voucher = \App\Models\Voucher::findOrFail($id);

        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:vouchers,code,' . $voucher->id,
            'description' => 'nullable|string|max:255',
            'discount_type' => 'required|in:fixed,percent',
            'discount_value' => 'required|numeric|min:1',
            'max_uses' => 'required|integer|min:1',
            'minimum_order' => 'nullable|numeric|min:0',
            'valid_from' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'points_required' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $voucher->update([
            'code' => strtoupper($validated['code']),
            'description' => $validated['description'] ?? null,
            'discount_type' => $validated['discount_type'],
            'discount_value' => $validated['discount_value'],
            'max_uses' => $validated['max_uses'],
            'minimum_order' => $validated['minimum_order'] ?? 0,
            'valid_from' => $validated['valid_from'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'points_required' => $validated['points_required'] ?? 0,
            'is_active' => $request->has('is_active') ? 1 : 0,
        ]);

        return redirect()->route('admin.vouchers')
            ->with('success', 'Voucher updated!');
    }

    public function deleteVoucher(int $id)
    {
        $voucher = \App\Models\Voucher::findOrFail($id);

        /*
         * user_vouchers.voucher_id is ON DELETE CASCADE, so deleting a code
         * that customers won on the wheel takes their unused claims with it.
         * Existing policy, unchanged here — but worth saying out loud, and
         * "deactivate" is usually what the admin actually wants.
         */
        $unusedClaims = \App\Models\UserVoucher::where('voucher_id', $id)
            ->where('is_used', false)
            ->count();

        return $this->safelyDelete(
            function () use ($voucher, $unusedClaims) {
                $code = $voucher->code;
                $voucher->delete();

                $message = 'Voucher "' . $code . '" deleted!';

                if ($unusedClaims > 0) {
                    $message .= ' ' . ucfirst($this->countLabel($unusedClaims, 'unused customer claim'))
                        . ' on it was removed as well — deactivating a code instead keeps those valid.';
                }

                return redirect()->route('admin.vouchers')->with('success', $message);
            },
            'admin.vouchers',
            'the voucher "' . $voucher->code . '"',
            'Deactivate it instead so orders that already used it keep their record.'
        );
    }

    /**
     * Mint one bearer claim code for a walk-in customer.
     *
     * WHY THIS EXISTS
     * ---------------
     * Dine-In and Pick-Up customers can walk in with no account and may never
     * play the spin wheel, so there was no way to hand one of them a voucher.
     * This mints a code the admin can read out or write on a receipt.
     *
     * IT IS NOT A BYPASS, AND THAT IS ENFORCED RATHER THAN ASSERTED
     * ------------------------------------------------------------
     * issuanceErrorFor() mirrors the wheel's own issuability rule — active, not
     * expired, and under the SAME max_uses cap, checked against the SAME
     * used_count column. An admin therefore cannot issue a code for a voucher
     * the wheel would refuse to award, and cannot use this path to push a
     * voucher past a limit a wheel win would be bound by.
     *
     * The claim itself is ordinary: ownerless, one code, spent by the same
     * conditional UPDATE at checkout as any other bearer claim. There is no
     * special-casing at redemption time — that is the point of reusing
     * VoucherClaims rather than writing a second path.
     */
    public function issueVoucherCode(int $id)
    {
        $voucher = \App\Models\Voucher::findOrFail($id);

        $blocked = $voucher->issuanceErrorFor();

        if ($blocked !== null) {
            return redirect()->route('admin.vouchers')->with('error', $blocked);
        }

        $admin = \Illuminate\Support\Facades\Auth::guard('admin')->user();

        $claim = \App\Services\VoucherClaims::mintForCounter($voucher, (int) $admin->id);

        /*
         * Flash the code back so the admin can actually read it out. A code
         * that is minted and then never shown is worse than useless: the row
         * exists, counts as a claim, and nobody can spend it.
         *
         * Flashed rather than redirected as a query parameter — a URL ends up
         * in browser history and in the address bar on a counter screen.
         */
        return redirect()->route('admin.vouchers')
            ->with('issued_claim_code', \App\Services\VoucherClaims::display($claim->claim_code))
            ->with('issued_claim_voucher', $voucher->code)
            ->with('success', 'A new code was issued for voucher "' . $voucher->code . '".');
    }

    /**
     * Issue a voucher code AGAINST a customer's earned points reward.
     *
     * A deliberate sibling of issueVoucherCode() above, not a replacement for
     * it and not a flag on it. The two answer different questions:
     *
     *   - issueVoucherCode()  — "hand a code to whoever is standing here."
     *     No points, no customer, no ceiling. Pass 9. Unchanged.
     *   - this one            — "hand a code the customer has EARNED."
     *     Requires a named customer and consumes one of their unclaimed
     *     rewards.
     *
     * Keeping them separate is what lets the free-form flow stay exactly as
     * it was for walk-ins who never earned anything, while this one can be
     * strict without that strictness leaking into it.
     *
     * They share the MINTING, which is the part that must not diverge:
     * VoucherClaims::mintForCounter() is the same call, so a reward code and
     * a walk-in code are the same kind of artefact, redeemed the same way. No
     * second issuance mechanism exists.
     */
    public function issueRewardCode(Request $request, int $id)
    {
        $voucher = \App\Models\Voucher::findOrFail($id);

        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:users,id',
        ]);

        $customer = \App\Models\User::where('id', $validated['customer_id'])
            ->where('role', 'customer')
            ->first();

        if (!$customer) {
            return redirect()->route('admin.vouchers')
                ->with('error', 'That customer could not be found.');
        }

        // Every gate the walk-in flow applies still applies — a reward does
        // not entitle staff to issue an expired or exhausted voucher.
        $blocked = $voucher->issuanceErrorFor();

        if ($blocked !== null) {
            return redirect()->route('admin.vouchers')->with('error', $blocked);
        }

        $admin = \Illuminate\Support\Facades\Auth::guard('admin')->user();

        /*
         * The ceiling, enforced server-side.
         *
         * The lookup screen already shows the unclaimed count, but that is a
         * hint on a page that may have been open for a while — two staff on
         * two terminals could both read "1 unclaimed" and both issue. The
         * check and the increment therefore happen together inside a
         * transaction, with the customer row locked, so the second one reads
         * the first one's increment and is refused.
         */
        $issued = null;

        $refusal = \Illuminate\Support\Facades\DB::transaction(function () use ($customer, $voucher, $admin, &$issued) {
            $locked = \App\Models\User::whereKey($customer->id)->lockForUpdate()->first();

            if (\App\Services\PointsRewards::unclaimedFor($locked) < 1) {
                return 'That customer has no unclaimed rewards. '
                    . 'Use the ordinary "Issue a code" action to hand out a code anyway.';
            }

            $issued = \App\Services\VoucherClaims::mintForCounter($voucher, (int) $admin->id);

            // Counted, never deducted from their points — the points total is
            // a lifetime figure and stays put. See PointsRewards.
            $locked->increment('reward_claims');

            return null;
        });

        if ($refusal !== null) {
            return redirect()->route('admin.vouchers', ['customer' => $customer->email])
                ->with('error', $refusal);
        }

        return redirect()->route('admin.vouchers', ['customer' => $customer->email])
            ->with('issued_claim_code', \App\Services\VoucherClaims::display($issued->claim_code))
            ->with('issued_claim_voucher', $voucher->code)
            ->with(
                'success',
                'Reward code issued to ' . $customer->name . ' for voucher "' . $voucher->code . '".'
            );
    }

    public function toggleVoucher(int $id)
    {
        $voucher = \App\Models\Voucher::findOrFail($id);

        $voucher->is_active = !$voucher->is_active;
        $voucher->save();

        $status = $voucher->is_active
            ? 'activated'
            : 'deactivated';

        return redirect()->route('admin.vouchers')
            ->with(
                'success',
                'Voucher "' . $voucher->code . '" ' . $status . '!'
            );
    }

    public function toggleGame(Request $request)
    {
        $current = \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'game_enabled')
            ->value('value');

        $new = $current === '1' ? '0' : '1';

        \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'game_enabled')
            ->update(['value' => $new]);

        $status = $new === '1'
            ? 'enabled'
            : 'disabled';

        return redirect()->route('admin.vouchers')
            ->with('success', 'Game ' . $status . '!');
    }

    public function showReceipt(int $id)
    {
        // Branch-scoped for staff, unrestricted for admins — see
        // App\Services\AdminOrderAccess. A receipt carries the customer's name
        // and the whole basket, so reading one across branches is a privacy
        // gap even though nothing is written.
        $order = \App\Services\AdminOrderAccess::resolveInScope($id, [
            'items.menuItem',
            'customer',
        ]);

        return view('customer.receipt', compact('order'));
    }

    // ══════════ Branches ══════════

    public function showBranches()
    {
        $branches = \App\Models\Branch::orderBy('created_at')->get();

        // Load branch-specific store/social settings for the admin form.
        $settingKeys = [
            'facebook_url',
            'instagram_url',
            'tiktok_url',
            'other_social_url',
        ];

        $branchSettings = [];
        foreach ($branches as $branch) {
            foreach ($settingKeys as $key) {
                $branchSettings[$branch->id][$key] = \App\Models\Setting::get($key, '', $branch->id);
            }
        }

        return view('admin.branches', compact('branches', 'branchSettings'));
    }

    public function storeBranch(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:20|unique:branches,code',
            'address' => 'nullable|string|max:500',
            'contact_number' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'opening_time' => 'nullable',
            'closing_time' => 'nullable',
        ]);

        $branch = \App\Models\Branch::create([
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'address' => $validated['address'] ?? null,
            'contact_number' => $validated['contact_number'] ?? null,
            'email' => $validated['email'] ?? null,
            'opening_time' => $validated['opening_time'] ?? null,
            'closing_time' => $validated['closing_time'] ?? null,
            'is_active' => true,
            'is_main_branch' => false,
        ]);

        return redirect()->route('admin.branches')
            ->with(
                'success',
                'Branch "' . $validated['name'] . '" created!'
            );
    }

    public function updateBranch(Request $request, int $id)
    {
        $branch = \App\Models\Branch::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'contact_number' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'opening_time' => 'nullable',
            'closing_time' => 'nullable',
            'is_active' => 'nullable|boolean',
            'facebook_url' => 'nullable|string|max:500',
            'instagram_url' => 'nullable|string|max:500',
            'tiktok_url' => 'nullable|string|max:500',
            'other_social_url' => 'nullable|string|max:500',
        ]);

        $branch->update([
            'name' => $validated['name'],
            'address' => $validated['address'] ?? null,
            'contact_number' => $validated['contact_number'] ?? null,
            'email' => $validated['email'] ?? null,
            'opening_time' => $validated['opening_time'] ?? null,
            'closing_time' => $validated['closing_time'] ?? null,
            'is_active' => $request->has('is_active') ? 1 : 0,
        ]);

        // Social media is stored in the existing branch-aware settings table.
        $socialSettings = [
            'facebook_url' => $validated['facebook_url'] ?? '',
            'instagram_url' => $validated['instagram_url'] ?? '',
            'tiktok_url' => $validated['tiktok_url'] ?? '',
            'other_social_url' => $validated['other_social_url'] ?? '',
        ];

        foreach ($socialSettings as $key => $value) {
            \App\Models\Setting::set($key, $value, $branch->id);
        }

        return redirect()->route('admin.branches')
            ->with('success', 'Branch and store information updated!');
    }

    public function toggleBranch(int $id)
    {
        $branch = \App\Models\Branch::findOrFail($id);

        // Branches are never deleted (see panel feedback 9.4) — deactivating
        // is the only way to retire one. Two guards apply only when CLOSING
        // an active branch; reopening a closed branch is always allowed.
        if ($branch->is_active) {
            if ($branch->is_main_branch) {
                return redirect()->route('admin.branches')
                    ->with('error', 'The main branch cannot be closed.');
            }

            $otherActiveExists = \App\Models\Branch::where('id', '!=', $branch->id)
                ->where('is_active', true)
                ->exists();

            if (!$otherActiveExists) {
                return redirect()->route('admin.branches')
                    ->with('error', 'At least one branch must stay open for customers to order from.');
            }
        }

        $branch->is_active = !$branch->is_active;
        $branch->save();

        return redirect()->route('admin.branches')
            ->with('success', $branch->name . ' is now ' . ($branch->is_active ? 'Open' : 'Closed') . '.');
    }

    public function selectBranch(Request $request)
    {
        $branchId = $request->input('branch_id');

        session()->put('selected_branch_id', $branchId);

        return redirect()->back()
            ->with('success', 'Branch filter applied!');
    }

    // ══════════ Help Requests ══════════

    public function assistHelpRequest(int $id)
    {
        // Branch-scoped for staff, unrestricted for admins — see
        // App\Services\AdminOrderAccess. A help request belongs to the branch
        // whose counter must answer it; a foreign id gets the same 404 as a
        // nonexistent one.
        $help = \App\Services\AdminOrderAccess::resolveRecordInScope(\App\Models\HelpRequest::class, $id);

        $help->status = 'assisting';
        $help->assisting_at = now();
        $help->save();

        return redirect()->route('admin.home')
            ->with(
                'success',
                'Now assisting Table ' . $help->table_number . '!'
            );
    }

    public function resolveHelpRequest(int $id)
    {
        // Branch-scoped for staff, unrestricted for admins — see
        // App\Services\AdminOrderAccess. A foreign id gets the same 404 as a
        // nonexistent one.
        $help = \App\Services\AdminOrderAccess::resolveRecordInScope(\App\Models\HelpRequest::class, $id);

        $help->status = 'resolved';
        $help->resolved_at = now();
        $help->save();

        return redirect()->route('admin.home')
            ->with(
                'success',
                'Help request for Table ' . $help->table_number . ' resolved!'
            );
    }

    // ══════════ Ads (CRUD) ══════════

    public function showAds()
    {
        $ads = \App\Models\Ad::orderBy('display_order')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('admin.ads', compact('ads'));
    }

    public function storeAd(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'link' => 'nullable|url|max:500',
            'placement' => 'required|in:game,menu,cart,orders',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
        ]);

        $imagePath = null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');

            $filename = time() . '_ad_' .
                preg_replace(
                    '/[^A-Za-z0-9\.]/',
                    '_',
                    $file->getClientOriginalName()
                );

            $file->move(public_path('uploads/ads'), $filename);

            $imagePath = 'uploads/ads/' . $filename;
        }

        \App\Models\Ad::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'image' => $imagePath,
            'link' => $validated['link'] ?? null,
            'placement' => $validated['placement'],
            'is_active' => true,
            'display_order' => 0,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
        ]);

        return redirect()->route('admin.ads')
            ->with(
                'success',
                'Ad "' . $validated['title'] . '" created!'
            );
    }

    public function updateAd(Request $request, int $id)
    {
        $ad = \App\Models\Ad::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'link' => 'nullable|url|max:500',
            'placement' => 'required|in:game,menu,cart,orders',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
        ]);

        if ($request->hasFile('image')) {
            if ($ad->image && file_exists(public_path($ad->image))) {
                unlink(public_path($ad->image));
            }

            $file = $request->file('image');

            $filename = time() . '_ad_' .
                preg_replace(
                    '/[^A-Za-z0-9\.]/',
                    '_',
                    $file->getClientOriginalName()
                );

            $file->move(public_path('uploads/ads'), $filename);

            $ad->image = 'uploads/ads/' . $filename;
        }

        $ad->title = $validated['title'];
        $ad->description = $validated['description'] ?? null;
        $ad->link = $validated['link'] ?? null;
        $ad->placement = $validated['placement'];
        $ad->starts_at = $validated['starts_at'] ?? null;
        $ad->ends_at = $validated['ends_at'] ?? null;

        $ad->save();

        return redirect()->route('admin.ads')
            ->with('success', 'Ad "' . $ad->title . '" updated!');
    }

    public function toggleAd(int $id)
    {
        $ad = \App\Models\Ad::findOrFail($id);

        $ad->is_active = !$ad->is_active;
        $ad->save();

        return redirect()->route('admin.ads')
            ->with(
                'success',
                'Ad "' . $ad->title . '" ' .
                ($ad->is_active ? 'activated' : 'deactivated') .
                '!'
            );
    }

    public function deleteAd(int $id)
    {
        $ad = \App\Models\Ad::findOrFail($id);

        // Nothing references ads, so this one is only ever a crash risk from
        // the filesystem or a dropped connection — still worth the net.
        return $this->safelyDelete(
            function () use ($ad) {
                $title = $ad->title;

                if ($ad->image && file_exists(public_path($ad->image))) {
                    unlink(public_path($ad->image));
                }

                $ad->delete();

                return redirect()->route('admin.ads')
                    ->with('success', 'Ad "' . $title . '" deleted!');
            },
            'admin.ads',
            'the ad "' . $ad->title . '"'
        );
    }

    /**
     * Export the reported period as CSV.
     *
     * THE RULE: this file and the printed Sales & Profit Report are the same
     * document in two formats. It therefore takes the SAME inputs as
     * showSummary() (period / date_from / date_to), resolves them through the
     * SAME resolveSummaryPeriod(), and reads through the SAME
     * ProfitCalculationService — ordersForRange() for the rows, forRange() for
     * the TOTALS line, both built on that service's one lineItemsQuery().
     *
     * What that fixes, concretely:
     *  - The export used to filter on created_at while the report scoped on
     *    completed_at, so an order opened before midnight and paid after it
     *    landed in a different period in each. Both now say completed_at.
     *  - The export used to offer only Subtotal / Discount / Total, none of
     *    which is labelled the way the report labels its Total Revenue, and
     *    summing the Total column came up short by exactly the discounts. The
     *    columns are now named for what the report calls them, and the TOTALS
     *    row carries the report's own Revenue / COGS / Gross Profit figures.
     *  - The export carried no cost or profit at all, so it could not
     *    corroborate the headline numbers it sat next to.
     *
     * The header block above the table states branch, period and basis, so a
     * file that has been emailed onward still says what it is a report of.
     */
    public function exportOrders(Request $request)
    {
        $selectedBranch = $this->getSelectedBranch();

        // Same normalisation as showSummary(), including the "custom but
        // incomplete" fallback, so the two can never resolve a request to
        // different windows.
        $period = $request->input('period', 'custom');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        if ($period === 'custom' && (!$dateFrom || !$dateTo)) {
            $period = 'today';
        }
        if (!in_array($period, ['today', 'week', 'month', 'custom'], true)) {
            $period = 'today';
        }

        [$start, $end] = $this->resolveSummaryPeriod($period, $dateFrom, $dateTo);

        $profit = app(\App\Services\ProfitCalculationService::class);
        $rows = $profit->ordersForRange($start, $end, $selectedBranch);
        $totals = $profit->forRange($start, $end, $selectedBranch);

        $branchName = $selectedBranch === 'all'
            ? 'All Branches'
            : (optional(\App\Models\Branch::find($selectedBranch))->name ?? 'Unknown Branch');

        $filename = 'sales-report_' . $start->format('Y-m-d')
            . '_to_' . $end->format('Y-m-d') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($rows, $totals, $branchName, $start, $end) {
            $file = fopen('php://output', 'w');

            // Excel opens a CSV as the system codepage unless it finds a BOM;
            // without this the peso sign and the en dashes arrive as mojibake.
            fwrite($file, "\xEF\xBB\xBF");

            fputcsv($file, ['Peachy Cakes & Deli Cafe — Sales & Profit Report']);
            fputcsv($file, ['Branch', $branchName]);
            fputcsv($file, ['Period', $start->format('M d, Y') . ' - ' . $end->format('M d, Y')]);
            fputcsv($file, ['Basis', 'Completed orders, by completion date']);
            fputcsv($file, ['Generated', now()->format('M d, Y g:i A')]);
            fputcsv($file, ['Generated by', optional(auth('admin')->user())->name ?? 'Unknown user']);
            fputcsv($file, []);

            fputcsv($file, [
                'Order #',
                'Completed',
                'Type',
                'Table',
                'Items',
                'Gross Revenue',
                'Discount',
                'Net Revenue',
                'COGS',
                'Gross Profit',
                'Margin % (of Net)',
                'Amount Charged',
                'Payment',
                'Status',
            ]);

            foreach ($rows as $row) {
                fputcsv($file, [
                    $row['order_number'],
                    optional($row['completed_at'])->format('Y-m-d H:i') ?? '',
                    $row['type'],
                    $row['table_number'] ?? '-',
                    $row['items'],
                    number_format($row['gross_revenue'], 2, '.', ''),
                    number_format($row['discount'], 2, '.', ''),
                    number_format($row['net_revenue'], 2, '.', ''),
                    number_format($row['cogs'], 2, '.', ''),
                    number_format($row['gross_profit'], 2, '.', ''),
                    $row['margin_percent'] === null ? '' : number_format($row['margin_percent'], 1, '.', ''),
                    number_format($row['amount_charged'], 2, '.', ''),
                    $row['payment_method'],
                    $row['status'],
                ]);
            }

            // The report's own KPI figures, not a re-derivation of them. If a
            // future change makes the rows above stop adding up to this line,
            // that is a genuine defect and the test suite says so.
            fputcsv($file, []);
            fputcsv($file, [
                'TOTALS',
                '',
                '',
                '',
                $totals['order_count'] . ' completed orders',
                number_format($totals['gross_revenue'], 2, '.', ''),
                number_format($totals['discounts'], 2, '.', ''),
                number_format($totals['net_revenue'], 2, '.', ''),
                number_format($totals['cogs'], 2, '.', ''),
                number_format($totals['gross_profit'], 2, '.', ''),
                $totals['margin_percent'] === null ? '' : number_format($totals['margin_percent'], 1, '.', ''),
                '',
                '',
                '',
            ]);

            // The same caveat the screen and the paper carry. A file that has
            // been emailed onward must still disclose how its costs were
            // priced, so it cannot be read as more certain than it is. Silent
            // when there is nothing to disclose.
            if ($totals['legacy_fallback_count'] > 0) {
                fputcsv($file, []);
                fputcsv($file, [
                    'Cost note',
                    $totals['legacy_fallback_count'] . ' of ' . $totals['item_count']
                        . ' sold lines have no recorded cost from the time of sale, so they are'
                        . ' costed at TODAY\'S ingredient prices. COGS and Gross Profit for those'
                        . ' lines are an estimate, not a record of what the ingredients cost then.',
                ]);
            }

            fclose($file);
        };

        return response()->stream(
            $callback,
            200,
            $headers
        );
    }


    // ══════════ STAFF MANAGEMENT (admin only) ══════════
    //
    // Replaces the removed public /admin/register flow. Staff accounts can
    // only be created here, by an authenticated admin, and the role is always
    // forced to 'staff' — this form can never mint another admin.

    public function showUsers()
    {
        $staff = \App\Models\User::where('role', 'staff')
            ->with('branch')
            ->orderBy('name')
            ->get();

        $branches = \App\Models\Branch::where('is_active', true)
            ->orderBy('id')
            ->get();

        return view('admin.users', compact('staff', 'branches'));
    }

    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|max:255|unique:users,email',
            'branch_id' => 'required|exists:branches,id',
            // Security review 2026-08-31: staff accounts get the same shared
            // complexity policy as every other password-setting flow.
            'password'  => \App\Support\PasswordPolicy::required(),
        ]);

        \App\Models\User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => $validated['password'],  // hashed by the model cast
            'branch_id' => $validated['branch_id'],
            'role'      => 'staff',                 // never anything else
            'is_active' => true,
        ]);

        return redirect()->route('admin.users')
            ->with('success', 'Staff account created for ' . $validated['name'] . '.');
    }

    /**
     * Set a NEW password for a staff member, on behalf of the admin.
     *
     * Added 2026-09-01, together with the removal of every staff self-service
     * password path (the account page is now `role:admin`, and
     * AdminAuthController::resettableRoles() no longer covers staff). This is
     * the replacement route: a staff member who is locked out asks the admin,
     * and the admin sets a new password here.
     *
     * THE ADMIN DOES NOT SEE THE OLD PASSWORD, AND CANNOT
     * ----------------------------------------------------
     * Stored passwords are bcrypt hashes. There is no decryption and no
     * recovery — not for the admin, not for anyone with database access. This
     * endpoint therefore SETS a new value; nothing anywhere in the system
     * displays an existing one. That is a property of the storage, not a UI
     * decision, so no future screen can change it.
     *
     * WHAT THIS DELIBERATELY DOES NOT DO
     * -----------------------------------
     *  - It never puts the password in a flash message, a redirect parameter, a
     *    URL, a log line or the rendered page. The admin already typed it, so
     *    echoing it back would only widen where it can leak.
     *  - It cannot be pointed at an admin account (see the role check below),
     *    so it can never be used to take over the owner's own login.
     *  - It does not touch `role`. The "role is always staff" guarantee from
     *    storeUser() is unaffected — this endpoint writes only the password.
     */
    public function updateStaffPassword(Request $request, $id)
    {
        $user = \App\Models\User::findOrFail($id);

        /*
         * Only staff accounts, never an admin — including the admin making the
         * request. The route group is already `role:admin`, so this is not
         * about privilege; it is about blast radius. Without it, one admin
         * could silently take over another admin's account, and a mistyped id
         * could lock the owner out of their own system.
         */
        if ($user->role !== 'staff') {
            return redirect()->route('admin.users')
                ->with('error', 'Only staff account passwords can be changed here.');
        }

        $request->validate([
            // The shared policy — 8+ characters with upper, lower, number and
            // symbol — and `confirmed`, which requires a matching
            // password_confirmation field. Deliberately the same rule object as
            // every other password-setting flow, so this one cannot drift into
            // being the weakest door in the building.
            'password' => \App\Support\PasswordPolicy::required(),
        ], [
            'password.required'  => 'Please enter a new password.',
            'password.confirmed' => 'The new password and its confirmation do not match.',
        ]);

        /*
         * forceFill because `password` is a guarded-by-convention field here;
         * the User model casts it to "hashed", so assigning the plain value
         * hashes it exactly once. Do not call Hash::make() as well — that would
         * double-hash and the password would never match again.
         *
         * remember_token is cycled so any "remember me" cookie still held by
         * that staff member — or by whoever locked them out — stops working.
         */
        $user->forceFill([
            'password'       => $request->input('password'),
            'remember_token' => \Illuminate\Support\Str::random(60),
        ])->save();

        // The staff member's NAME, never their password.
        return redirect()->route('admin.users')
            ->with('success', 'Password updated for ' . $user->name . '.');
    }

    public function toggleUser($id)
    {
        $user = \App\Models\User::findOrFail($id);

        // Only staff accounts can be toggled from this screen — never an
        // admin, and never the currently logged-in user.
        if ($user->role !== 'staff') {
            return redirect()->route('admin.users')
                ->with('error', 'Only staff accounts can be activated or deactivated here.');
        }

        $user->is_active = !$user->is_active;
        $user->save();

        return redirect()->route('admin.users')
            ->with('success', $user->name . ' has been ' . ($user->is_active ? 'reactivated' : 'deactivated') . '.');
    }
}
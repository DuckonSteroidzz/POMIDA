<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

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

    // The four report endpoints that accept a raw date_from/date_to off the
    // querystring (analytics, analytics.print, summary, export.orders) all
    // validate it through this ONE trait, so a date the Analytics page refuses
    // cannot be the same date the CSV happily crashes on. See the trait for
    // what each rejected shape used to do.
    use \App\Http\Controllers\Concerns\ResolvesReportDateRange;

    // ══════════ Pages ══════════

    public function showAccount()
    {
        $me = Auth::guard('admin')->user();

        // Read-only staff summary for the Account page, admins only. Full staff
        // management (create / activate / deactivate) lives on the Staff
        // Accounts page.
        $staffList = ($me && $me->role === 'admin')
            ? \App\Models\User::whereIn('role', \App\Models\User::ADMIN_MANAGEABLE_ROLES)
                ->orderBy('name')
                ->get()
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

        if (!in_array($period, ['today', 'week', 'month', 'custom'], true)) {
            $period = 'today';
        }

        // The "custom but incomplete -> today" fallback that used to live here
        // now lives inside normaliseCustomRange(), together with the three
        // failure modes it never covered (unparseable, 0000-00-00, inverted).
        // resolveSummaryPeriod() does the demotion itself and reports back
        // through $period, so this method and exportOrders() cannot drift on
        // what a bad range resolves to.
        $dateNotice = null;
        [$start, $end, $prevStart, $prevEnd] = $this->resolveSummaryPeriod(
            $period,
            $dateFrom,
            $dateTo,
            $dateNotice
        );

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

            // Refilled from the range actually reported on whenever the
            // submitted one had to be corrected or discarded — same reasoning
            // as showAnalytics(): a rejected string must not sit in the date
            // box above figures for a different period. Unchanged on an
            // ordinary valid request.
            'dateFrom'             => $dateNotice === null ? $dateFrom : $start->toDateString(),
            'dateTo'               => $dateNotice === null ? $dateTo : $end->toDateString(),
            'periodStart'          => $start,
            'periodEnd'            => $end,
            'selectedBranchName'   => $selectedBranchName,

            // Set only when the submitted custom range had to be corrected or
            // discarded. The page reports on $start..$end either way, so this
            // is what stops a fallback period being read as the one that was
            // asked for. Null on every ordinary request and the view is silent.
            'dateNotice'           => $dateNotice,

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
     * $period is taken BY REFERENCE: a 'custom' range that does not survive
     * normaliseCustomRange() is demoted to 'today' here, and the caller needs
     * to see that demotion so the period selector it re-renders agrees with the
     * figures underneath it. Doing the demotion inside this one method is what
     * keeps showSummary() and exportOrders() — which must resolve any given
     * request to the SAME window, or the CSV stops being the report — from
     * each keeping their own copy of the rule.
     *
     * @param  string|null  $notice  plain-language text when the supplied range
     *                               was corrected or discarded; null otherwise.
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon, 2: \Carbon\Carbon, 3: \Carbon\Carbon}
     */
    private function resolveSummaryPeriod(
        string &$period,
        ?string $dateFrom,
        ?string $dateTo,
        ?string &$notice = null
    ): array {
        if ($period === 'custom') {
            $range = $this->normaliseCustomRange($dateFrom, $dateTo, $notice);

            if ($range === null) {
                // Incomplete, unparseable, impossible or out-of-bounds — fall
                // back to the safe default rather than erroring. $notice is
                // already set for everything except "still filling the form in".
                $period = 'today';
            }
        }

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
                // $range is guaranteed non-null and guaranteed start <= end by
                // the block above — a range that failed validation demoted
                // $period away from 'custom' and never reaches this arm. That
                // ordering is what makes the arithmetic below safe: Carbon 3's
                // diffInDays() is SIGNED, so before the swap an inverted range
                // produced a negative $days, and subDays() on a negative count
                // ADDS days — the "previous period" the % change badge compares
                // against used to land in the FUTURE on an inverted input.
                [$start, $end] = $range;

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

    /**
     * EVERY figure the Analytics module reports, resolved once.
     *
     * Phase 2d. The screen, the print sheet and the CSV export are three
     * renderings of the array this method returns — not three calculations.
     * Before this existed, showAnalytics() and printAnalytics() each repeated
     * the same eleven service calls and the export would have been a third
     * copy; the property that "the CSV matches the screen" was maintained by
     * two (soon three) code paths happening to stay in step. Now it is
     * structural: there is one code path, and a divergence would have to be
     * written deliberately.
     *
     * $period is passed by reference all the way down because a custom range
     * that fails validation is DEMOTED to the default preset, and the caller
     * re-renders the period selector from that variable — see
     * resolveAnalyticsPeriod().
     *
     * BRANCH SCOPE is taken from getSelectedBranch() here and nowhere else, so
     * every consumer inherits the one rule. There is no branch, scope or
     * selected_branch_id parameter anywhere in this method for a request to
     * tamper with: a branch-locked supervisor is locked by
     * AdminOrderAccess::lockedBranchId() before the session is consulted, and
     * a forged querystring has nothing to bind to.
     *
     * @return array<string, mixed>
     */
    private function analyticsContext(\Illuminate\Http\Request $request, ?string &$period = null, ?string &$dateNotice = null): array
    {
        $selectedBranch = $this->getSelectedBranch();
        $isAllBranches = $selectedBranch === 'all';

        $branchName = $isAllBranches
            ? 'All Branches'
            : (optional(\App\Models\Branch::find($selectedBranch))->name ?? 'Unknown Branch');

        $period = $request->query('period', 'last30');
        $dateNotice = null;
        [$start, $end] = $this->resolveAnalyticsPeriod(
            $period,
            $request->query('date_from'),
            $request->query('date_to'),
            $dateNotice
        );

        $analytics = new \App\Services\AnalyticsService($selectedBranch);

        $totalSales        = $analytics->salesForRange($start, $end);
        $totalOrders       = $analytics->ordersCountForRange($start, $end);
        $averageOrderValue = $analytics->averageOrderValueForRange($start, $end);
        $averageRating     = $analytics->averageRatingForRange($start, $end);

        $dailySales = $analytics->dailySalesSeriesForRange($start, $end);

        // Menu Production Capacity (Phase 2b). Deliberately NOT date-scoped:
        // "how many more of this can we make" is a question about the shelf as
        // it stands right now, the same way the out-of-stock KPI on Summary is
        // a live snapshot rather than a figure for the chosen period. The date
        // filter therefore does not — and must not — move these numbers.
        $productionCapacity = app(\App\Services\ProductionCapacityService::class)
            ->forBranchScope($selectedBranch);

        // Demand forecast (Phase 2c). Moving average over the SAME daily series
        // the chart is already drawing, so the forecast and the history it is
        // projected from can never be computed from two different queries — and
        // so the KPI, the chart's forecast segment and the weekday table cost
        // ZERO additional queries between them. Only the menu-level forecast
        // goes back to the database, and it does so once for every item.
        //
        // Unlike production capacity above, this one IS range-scoped: it is a
        // projection FROM the selected historical window, and the forecast
        // period begins the day after $end rather than after today, so the two
        // periods on the chart meet exactly once.
        //
        // UNSLICED, since Phase 2d. The screen's Menu Demand Forecast table
        // still shows the top MENU_FORECAST_DISPLAY_LIMIT rows, but the risk
        // join below needs a forecast for EVERY item that sold, not just the
        // five that happen to rank highest — otherwise the sixth item's risk
        // would read "Insufficient Data" purely because the display table was
        // short. Same ONE query either way; only the array slice moved.
        $forecastService = app(\App\Services\DemandForecastService::class);
        $forecast        = $forecastService->forDailySeries($dailySales['values'], $start, $end);
        $weekdayDemand   = $forecastService->byDayOfWeek($dailySales['values'], $start);
        $menuForecastAll = $forecastService->forMenuItems($selectedBranch, $start, $end, PHP_INT_MAX);

        $menuForecast = $menuForecastAll;
        $menuForecast['rows'] = array_slice($menuForecastAll['rows'], 0, self::MENU_FORECAST_DISPLAY_LIMIT);

        // The financial chain. ProfitCalculationService is the ONE source of
        // money in this project — Phase 1 found the old Analytics Top-5 widget
        // aggregating its own revenue beside a Summary screen that computed a
        // different one, and this is that finding closed on this page: the
        // Menu Performance table, the insights and the CSV all read from here.
        $profit = app(\App\Services\ProfitCalculationService::class)
            ->forRange($start, $end, $selectedBranch);

        // Phase 2d: the join. No new revenue, forecast, capacity or bottleneck
        // is calculated here — see the service's class docblock.
        $intelligence = app(\App\Services\AnalyticsIntelligenceService::class)->build(
            $selectedBranch,
            $productionCapacity,
            $menuForecastAll,
            $profit,
            $weekdayDemand,
            $forecast
        );

        return [
            'selectedBranch'       => $selectedBranch,
            'isAllBranches'        => $isAllBranches,
            'branchName'           => $branchName,
            'period'               => $period,
            'dateNotice'           => $dateNotice,
            'periodStart'          => $start,
            'periodEnd'            => $end,

            'totalSales'           => $totalSales,
            'totalOrders'          => $totalOrders,
            'averageOrderValue'    => $averageOrderValue,
            'averageRating'        => $averageRating,
            'dailySales'           => $dailySales,

            'productionCapacity'   => $productionCapacity,
            'lowCapacityThreshold' => (int) config('inventory.low_stock_threshold', 3),

            'forecast'             => $forecast,
            'weekdayDemand'        => $weekdayDemand,
            'menuForecast'         => $menuForecast,

            'profit'               => $profit,
            'intel'                => $intelligence,
        ];
    }

    /** How many rows the screen's Menu Demand Forecast table shows. */
    private const MENU_FORECAST_DISPLAY_LIMIT = 5;

    /** How many rows the screen's Menu Performance table shows. */
    private const MENU_PERFORMANCE_DISPLAY_LIMIT = 5;

    public function showAnalytics(\Illuminate\Http\Request $request)
    {
        $period = null;
        $dateNotice = null;
        $context = $this->analyticsContext($request, $period, $dateNotice);

        return view('admin.analytics', $context + [
            // The two date boxes are refilled from the range that was actually
            // REPORTED ON, not from the raw querystring, whenever the submitted
            // range had to be corrected or discarded. Echoing the raw value
            // back would leave "banana" sitting in a date input above figures
            // for the last 30 days. On an ordinary valid custom request these
            // are the submitted strings, so the normal UX is unchanged.
            'dateFrom' => $dateNotice === null
                ? $request->query('date_from', $context['periodStart']->toDateString())
                : $context['periodStart']->toDateString(),
            'dateTo'   => $dateNotice === null
                ? $request->query('date_to', $context['periodEnd']->toDateString())
                : $context['periodEnd']->toDateString(),

            'performanceRows' => array_slice(
                $context['intel']['performance_rows'],
                0,
                self::MENU_PERFORMANCE_DISPLAY_LIMIT
            ),
        ]);
    }

    /**
     * "Print" for Analytics — the same printInFrame() shape as
     * printCompletedOrders(): re-resolves the SAME branch scope and
     * date range from the querystring, unbounded, and hands it to a
     * print-only view. Nothing here re-derives a figure differently than
     * showAnalytics() did — same AnalyticsService methods, same arguments,
     * and (Phase 2b.1) the same ProductionCapacityService::forBranchScope()
     * call for the live capacity snapshot. No calculation is duplicated here.
     */
    public function printAnalytics(\Illuminate\Http\Request $request)
    {
        // The SAME analyticsContext() the screen renders from, so "the print
        // sheet matches the screen" stops being a property anyone has to
        // maintain and becomes one code path.
        $context = $this->analyticsContext($request);

        return view('admin.analytics-print', $context + [
            'performanceRows' => array_slice(
                $context['intel']['performance_rows'],
                0,
                self::MENU_PERFORMANCE_DISPLAY_LIMIT
            ),

            // A printed sheet outlives the screen it was requested from, so a
            // range that fell back says so ON THE PAPER rather than only in the
            // browser the request came from. The Period line beneath the
            // heading already states the window used; 'dateNotice' (carried in
            // $context) names the reason.
            'printedBy' => optional(auth('admin')->user())->name ?? 'Unknown user',
            'printedAt' => now(),
        ]);
    }

    /**
     * "Export CSV" for Analytics — Phase 2d.
     *
     * SAME SOURCE OF TRUTH AS THE SCREEN, structurally. This action calls
     * analyticsContext() and nothing else: the identical branch scope, the
     * identical date resolution (including the fallback a rubbish custom range
     * triggers), the identical services, and the identical
     * AnalyticsIntelligenceService result the page just rendered. There is no
     * second revenue figure, no second forecast, no second capacity and no
     * second bottleneck in this method — only formatting.
     *
     * The file follows the export convention the Inventory and Sales CSVs
     * already established: response()->stream(), a UTF-8 BOM so Excel renders
     * the peso sign, a header block naming the report / branch / period /
     * basis / who generated it, the data grid, then a summary block. No
     * generic export framework was introduced for it.
     *
     * AUTHORIZATION is the route's, plus getSelectedBranch()'s, and nothing
     * this method does can widen either. The route sits in the
     * role:admin,supervisor group alongside admin.analytics itself, so staff
     * cannot reach it at all; the scope comes from getSelectedBranch(), which
     * answers a branch-locked supervisor from AdminOrderAccess::lockedBranchId()
     * BEFORE the session is consulted. There is no branch_id, scope or
     * selected_branch_id input read anywhere in this path, so there is nothing
     * for a forged parameter to bind to — the only querystring this action
     * reads at all is the date range, through the same validator the screen
     * uses.
     *
     * FORMULA INJECTION. Menu item, ingredient and bottleneck names are
     * user-entered, so every free-text cell goes through App\Support\Csv::cell()
     * on the way out — see that class for why fputcsv()'s quoting is not
     * sufficient on its own.
     */
    public function exportAnalytics(\Illuminate\Http\Request $request)
    {
        $context = $this->analyticsContext($request);

        $intel      = $context['intel'];
        $start      = $context['periodStart'];
        $end        = $context['periodEnd'];
        $branchName = $context['branchName'];
        $isAll      = $context['isAllBranches'];

        $filenameBranch = Str::slug($branchName) ?: 'all-branches';
        $filename = "analytics_{$filenameBranch}_" . $start->format('Y-m-d')
            . '_to_' . $end->format('Y-m-d') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $generatedBy = optional(auth('admin')->user())->name ?? 'Unknown user';
        $generatedAt = now();

        $callback = function () use ($intel, $branchName, $isAll, $start, $end, $generatedBy, $generatedAt) {
            $file = fopen('php://output', 'w');

            // Excel opens a CSV as the system codepage unless it finds a BOM;
            // without this the peso sign and the en dashes arrive as mojibake.
            fwrite($file, "\xEF\xBB\xBF");

            $safe = fn ($v) => \App\Support\Csv::cell($v);

            // ── header block ──────────────────────────────────────────────
            fputcsv($file, ['Peachy Cakes & Deli Cafe — Analytics Report']);
            fputcsv($file, ['Branch', $safe($branchName)]);
            fputcsv($file, ['Period', $start->format('M d, Y') . ' - ' . $end->format('M d, Y')]);
            fputcsv($file, ['Sales basis', 'Completed orders, by completion date']);
            // The one distinction a printed or emailed copy of this file must
            // not lose: three of its columns are about three different points
            // in time. Stated here, once, above the grid.
            fputcsv($file, ['Capacity basis', 'Live stock snapshot as of ' . $generatedAt->format('M d, Y g:i A')
                . ' — NOT the report period above']);
            fputcsv($file, ['Forecast basis', 'Moving average of the last '
                . \App\Services\DemandForecastService::MOVING_AVERAGE_WINDOW_DAYS
                . ' days of the report period, projected '
                . $intel['horizon_days'] . ' days ahead. An estimate, not a guaranteed figure.']);
            fputcsv($file, ['Generated', $generatedAt->format('M d, Y g:i A')]);
            fputcsv($file, ['Generated by', $safe($generatedBy)]);
            fputcsv($file, []);

            // ── menu grid ─────────────────────────────────────────────────
            $columns = ['Menu Item'];
            if ($isAll) {
                // Branch distinction, not branch comparison: under All Branches
                // two branches may carry a menu item of the same name and the
                // rows would otherwise be indistinguishable. A branch-scoped
                // viewer already knows which branch they exported.
                $columns[] = 'Branch';
            }
            array_push(
                $columns,
                'Quantity Sold',
                'Revenue (before order discounts)',
                'COGS',
                'Gross Profit',
                'Gross Margin %',
                'Current Production Capacity',
                'Forecasted Demand (next ' . $intel['horizon_days'] . ' days)',
                'Potential Shortage',
                'Days of Coverage',
                'Bottleneck Ingredient',
                'Risk Status'
            );
            fputcsv($file, $columns);

            foreach ($intel['rows'] as $row) {
                $line = [$safe($row['menu_item_name'])];

                if ($isAll) {
                    $line[] = $safe($row['branch_name'] ?? 'Unassigned');
                }

                // Money and quantities are only written when the item actually
                // sold in the period. An item with no sales has no revenue to
                // report, and a 0.00 there would read as "sold nothing for
                // nothing" rather than "not sold".
                if ($row['has_sales']) {
                    array_push(
                        $line,
                        $row['quantity_sold'],
                        number_format($row['revenue'], 2, '.', ''),
                        number_format($row['cogs'], 2, '.', ''),
                        number_format($row['gross_profit'], 2, '.', ''),
                        $row['margin_percent'] === null
                            ? 'Not Applicable'
                            : number_format($row['margin_percent'], 1, '.', '')
                    );
                } else {
                    array_push($line, 'No Sales', 'No Sales', 'No Sales', 'No Sales', 'No Sales');
                }

                // Capacity. "Unavailable" is not zero — see
                // AnalyticsIntelligenceService's class docblock.
                $line[] = ($row['has_capacity_row'] && $row['is_measurable'])
                    ? $row['capacity']
                    : 'Unavailable';

                // Forecast, shortage and coverage. Every one of these is an
                // honest word rather than a fake zero when it cannot be
                // calculated.
                $line[] = $row['forecast_sufficient']
                    ? number_format($row['forecast_qty_next_7_days'], 2, '.', '')
                    : 'Insufficient Data';

                $line[] = $this->analyticsCsvShortage($row);
                $line[] = $this->analyticsCsvCoverage($row);

                $line[] = $safe(
                    ($row['has_capacity_row'] && $row['is_measurable'] && $row['bottleneck_name'] !== null)
                        ? $row['bottleneck_name']
                        : 'Not Applicable'
                );

                $line[] = $row['risk_label'];

                fputcsv($file, $line);
            }

            // ── inventory intelligence grid ───────────────────────────────
            fputcsv($file, []);
            fputcsv($file, ['INVENTORY INTELLIGENCE (live stock snapshot)']);

            $ingredientColumns = ['Ingredient'];
            if ($isAll) {
                $ingredientColumns[] = 'Branch';
            }
            array_push(
                $ingredientColumns,
                'Available Quantity',
                'Unit',
                'Average Daily Usage',
                'Days of Stock',
                'Menu Items Affected',
                'Limiting Production For',
                'Affected Menu Items'
            );
            fputcsv($file, $ingredientColumns);

            if (empty($intel['ingredients'])) {
                fputcsv($file, ['No recipe ingredients are tracked for this branch.']);
            }

            foreach ($intel['ingredients'] as $ing) {
                $line = [$safe($ing['name'] ?? 'Ingredient #' . $ing['inventory_id'])];

                if ($isAll) {
                    $line[] = $safe($ing['branch_name'] ?? 'Unassigned');
                }

                $line[] = $ing['available'] === null
                    ? 'Not Tracked'
                    : rtrim(rtrim(number_format((float) $ing['available'], 3, '.', ''), '0'), '.');
                $line[] = $safe($ing['unit'] ?? '');

                // Usage is a FLOOR when only some of the menu items that use
                // this ingredient have a forecast; the file says so in the cell
                // rather than presenting an understatement as complete.
                $line[] = $ing['average_daily_usage'] === null
                    ? 'Insufficient Data'
                    : rtrim(rtrim(number_format($ing['average_daily_usage'], 3, '.', ''), '0'), '.')
                        . ($ing['usage_is_partial'] ? ' (partial — some items have no forecast)' : '');

                $line[] = $ing['days_of_stock'] === null
                    ? ($ing['days_of_stock_reason'] === \App\Services\AnalyticsIntelligenceService::COVERAGE_UNAVAILABLE
                        ? 'Unavailable'
                        : 'Insufficient Data')
                    : number_format($ing['days_of_stock'], 1, '.', '');

                $line[] = $ing['menu_items_affected'];
                $line[] = $ing['bottleneck_for'];
                $line[] = $safe(implode('; ', array_map(
                    fn ($m) => $isAll && $m['branch_name'] !== null
                        ? $m['menu_item_name'] . ' (' . $m['branch_name'] . ')'
                        : $m['menu_item_name'],
                    $ing['affected_menu_items']
                )));

                fputcsv($file, $line);
            }

            // ── automated insights ────────────────────────────────────────
            fputcsv($file, []);
            fputcsv($file, ['AUTOMATED INSIGHTS & DATA-DRIVEN RECOMMENDATIONS']);
            fputcsv($file, ['Priority', 'Insight', 'Detail']);

            if (empty($intel['insights'])) {
                fputcsv($file, ['', 'No insights', 'Nothing in the current data meets an alert condition.']);
            }

            foreach ($intel['insights'] as $insight) {
                fputcsv($file, [
                    ucfirst($insight['level']),
                    $safe($insight['title']),
                    $safe($insight['message']),
                ]);
            }

            // ── summary block ─────────────────────────────────────────────
            //
            // The report's OWN figures, quoted from the one result the grid
            // above was written from — not a second sum over the rows. The
            // period totals come straight out of ProfitCalculationService, so
            // Net Revenue here is net of order-level discounts even though the
            // per-item Revenue column above cannot be: an order discount
            // belongs to the order, and the database records no allocation of
            // it to individual lines. The two column labels say which is which.
            $summary     = $intel['summary'];
            $financials  = $intel['financials'];
            $riskCounts  = $summary['risk_counts'];

            fputcsv($file, []);
            fputcsv($file, ['SUMMARY']);
            fputcsv($file, ['Gross Revenue (before discounts)', number_format($financials['gross_revenue'], 2, '.', '')]);
            fputcsv($file, ['Discounts', number_format($financials['discounts'], 2, '.', '')]);
            fputcsv($file, ['Net Revenue', number_format($financials['net_revenue'], 2, '.', '')]);
            fputcsv($file, ['COGS', number_format($financials['cogs'], 2, '.', '')]);
            fputcsv($file, ['Gross Profit', number_format($financials['gross_profit'], 2, '.', '')]);
            fputcsv($file, ['Gross Margin % (of Net Revenue)', $financials['margin_percent'] === null
                ? 'Not Applicable'
                : number_format($financials['margin_percent'], 1, '.', '')]);
            fputcsv($file, ['Completed Orders', $financials['order_count']]);
            fputcsv($file, []);
            fputcsv($file, ['Menu Items Reported', $summary['menu_items']]);
            fputcsv($file, ['Items Requiring Attention (Out of Stock + Critical)', $summary['items_requiring_attention']]);
            fputcsv($file, ['Risk — Out of Stock', $riskCounts[\App\Services\AnalyticsIntelligenceService::RISK_OUT_OF_STOCK]]);
            fputcsv($file, ['Risk — Critical', $riskCounts[\App\Services\AnalyticsIntelligenceService::RISK_CRITICAL]]);
            fputcsv($file, ['Risk — Low', $riskCounts[\App\Services\AnalyticsIntelligenceService::RISK_LOW]]);
            fputcsv($file, ['Risk — Good', $riskCounts[\App\Services\AnalyticsIntelligenceService::RISK_GOOD]]);
            fputcsv($file, ['Risk — Insufficient Data', $riskCounts[\App\Services\AnalyticsIntelligenceService::RISK_INSUFFICIENT_DATA]]);
            fputcsv($file, ['Risk — Unavailable', $riskCounts[\App\Services\AnalyticsIntelligenceService::RISK_UNAVAILABLE]]);
            fputcsv($file, ['Items with a Potential Shortage', $summary['items_with_shortage']]);
            fputcsv($file, ['Total Potential Shortage (orders)', $summary['total_potential_shortage'] === null
                ? 'None'
                : number_format($summary['total_potential_shortage'], 2, '.', '')]);
            fputcsv($file, ['Ingredients Tracked', $summary['ingredients_tracked']]);
            fputcsv($file, ['Ingredients Out of Stock', $summary['ingredients_out_of_stock']]);
            fputcsv($file, ['Ingredients Below 1 Day of Projected Demand', $summary['ingredients_below_one_day']]);

            // ── method + caveats ──────────────────────────────────────────
            fputcsv($file, []);
            fputcsv($file, ['METHOD']);
            fputcsv($file, ['Forecast method', 'Moving average — the mean of the most recent daily'
                . ' observations in the report period. No machine learning or AI model is used.']);
            fputcsv($file, ['Risk thresholds', 'Critical below '
                . \App\Services\AnalyticsIntelligenceService::COVERAGE_CRITICAL_DAYS
                . ' day of coverage; Low below '
                . \App\Services\AnalyticsIntelligenceService::COVERAGE_LOW_DAYS
                . ' days of coverage, or capacity at or below the inventory low-stock threshold of '
                . $intel['low_capacity_threshold'] . '.']);
            fputcsv($file, ['Unavailable figures', 'Where a figure could not be calculated it is written as'
                . ' Insufficient Data, Unavailable, Not Applicable or No Sales. It is never written as 0.']);

            if ($financials['legacy_fallback_count'] > 0) {
                fputcsv($file, ['Cost note', $financials['legacy_fallback_count'] . ' of '
                    . $financials['item_count'] . ' sold lines have no recorded cost from the time of sale,'
                    . ' so they are costed at TODAY\'S ingredient prices. COGS and Gross Profit for those'
                    . ' lines are an estimate, not a record of what the ingredients cost then.']);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * The Potential Shortage cell.
     *
     * Three distinct outcomes, three distinct words — because "0" would have
     * to stand for all three and a reader could not tell them apart:
     * the item is not producible or forecastable at all (Unavailable /
     * Insufficient Data), or it IS both and capacity covers the projection
     * (None), or there is a genuine shortfall (the number).
     */
    private function analyticsCsvShortage(array $row): string
    {
        if (!$row['has_capacity_row'] || !$row['is_measurable']) {
            return 'Unavailable';
        }

        if (!$row['forecast_sufficient']) {
            return 'Insufficient Data';
        }

        return $row['has_shortage']
            ? number_format($row['potential_shortage'], 2, '.', '')
            : 'None';
    }

    /** The Days of Coverage cell, on the same principle as the shortage cell. */
    private function analyticsCsvCoverage(array $row): string
    {
        if ($row['coverage_days'] !== null) {
            return number_format($row['coverage_days'], 1, '.', '');
        }

        return match ($row['coverage_reason']) {
            \App\Services\AnalyticsIntelligenceService::COVERAGE_UNAVAILABLE   => 'Unavailable',
            \App\Services\AnalyticsIntelligenceService::COVERAGE_NO_DEMAND     => 'No Projected Demand',
            default                                                            => 'Insufficient Data',
        };
    }

    /**
     * Resolve [start, end] Carbon boundaries for the Analytics date-range
     * dropdown. Presets are deliberately different from Summary's (Today /
     * Last 7 Days / Last 30 Days / This Month / Custom) — Analytics has no
     * "This Week" or previous-period comparison, so there is nothing to
     * mirror from resolveSummaryPeriod() beyond the shared 'custom' shape —
     * and, since this pass, the shared custom-range VALIDATION in
     * ResolvesReportDateRange. The presets differ; what counts as a usable
     * pair of dates must not.
     *
     * $period is by reference for the same reason it is in
     * resolveSummaryPeriod(): a custom range that fails validation is demoted
     * here, and the caller re-renders the period selector from that variable.
     *
     * @param  string|null  $notice  plain-language text when the supplied range
     *                               was corrected or discarded; null otherwise.
     */
    private function resolveAnalyticsPeriod(
        string &$period,
        ?string $dateFrom,
        ?string $dateTo,
        ?string &$notice = null
    ): array {
        // Whitelist first, the way showSummary() already did for its own
        // presets. Without this an unrecognised ?period= fell through the
        // switch to the last30 range but left $period alone, so the page
        // reported 30 days while the dropdown — which re-renders from $period —
        // matched none of its options and showed nothing selected. Harmless but
        // confusing, and the same class of "the selector disagrees with the
        // figures" problem the demotion below exists to prevent.
        if (!in_array($period, ['today', 'last7', 'last30', 'month', 'custom'], true)) {
            $period = 'last30';
        }

        if ($period === 'custom') {
            $range = $this->normaliseCustomRange($dateFrom, $dateTo, $notice);

            if ($range !== null) {
                return $range;
            }

            // Incomplete, unparseable, impossible or out-of-bounds. Same
            // reasoning as the unrecognised-period case above: fall back to the
            // default preset rather than erroring, and demote $period so the
            // dropdown the view re-renders agrees with the figures beneath it.
            $period = 'last30';
        }

        switch ($period) {
            case 'today':
                return [today()->startOfDay(), today()->endOfDay()];

            case 'last7':
                return [now()->subDays(6)->startOfDay(), now()->endOfDay()];

            case 'month':
                return [now()->startOfMonth(), now()->endOfMonth()];

            // No 'custom' arm here any more: a usable custom range returns
            // early above, and an unusable one has already been demoted to
            // 'last30'. The old arm called Carbon::parse() on the raw input,
            // which is what answered 500 to a typo.
            case 'last30':
            default:
                return [now()->subDays(29)->startOfDay(), now()->endOfDay()];
        }
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
            'branch',
            // Phase 3b F3: without this, breakdownForMany() below and this
            // page's own per-item "Edit" recipe block (which reads
            // $mi->recipeIngredients and each row's ->inventory) each pay a
            // fresh menu_item_ingredients query per item, and every recipe
            // row an ADDITIONAL single-row inventory lookup — three
            // uncoordinated N+1s from one missing eager-load. See
            // MenuItemCosting::breakdownForMany()'s docblock for the query
            // budget this closes.
            'recipeIngredients.inventory',
        ])
            ->when($selectedBranch !== 'all', function ($q) use ($selectedBranch) {
                $q->where('branch_id', $selectedBranch);
            })
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        // One query, two views of it. $branches is the active-only list this
        // page has always used; $branchNames covers EVERY branch (an item under
        // "All Branches" can belong to a deactivated one) so the recipe picker
        // can name each ingredient's branch without an extra lookup per option.
        $allBranches = \App\Models\Branch::orderBy('id')->get();
        $branches = $allBranches->where('is_active', true)->values();
        $branchNames = $allBranches->pluck('name', 'id');

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
            'branchNames',
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

    /**
     * Menu-item photos render at most ~550px wide (item-details hero image on a
     * 1152px container); 800px on the long edge covers that at ~1.45x pixel
     * density with headroom to spare. Resize failures (corrupt/unsupported
     * image data) fall back to the original file rather than breaking the
     * upload — GD already accepted it via the `image` validation rule.
     */
    private function resizeMenuImage(string $absolutePath): void
    {
        try {
            $manager = new ImageManager(new Driver());
            $manager->read($absolutePath)
                ->scaleDown(width: 800, height: 800)
                ->save($absolutePath, quality: 80);
        } catch (\Throwable $e) {
            Log::warning('Menu image resize failed; storing original upload as-is.', [
                'path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);
        }
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

            $this->resizeMenuImage(public_path($imagePath));
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
        // Branch-scoped for supervisor, unrestricted for admins — see
        // App\Services\AdminOrderAccess. Without this, a branch-locked
        // supervisor could open ANY menu item id: getSelectedBranch() is
        // always their own branch (never 'all'), so $targetBranch below
        // would silently reassign a far-branch item's branch_id to their
        // own branch on save. A foreign id gets the same 404 as a
        // nonexistent one.
        $menuItem = \App\Services\AdminOrderAccess::resolveRecordInScope(\App\Models\MenuItem::class, $id);

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

            $this->resizeMenuImage(public_path($menuItem->image));
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

        /*
         * "Delete Menu Items" is Y | LIMITED | N — a manager may delete only
         * items scoped to their OWN branch.
         *
         * Two things are refused here, and the second is the one that is easy
         * to miss. menu_items.branch_id is NULLABLE, and a NULL means the item
         * is SHARED across every branch rather than owned by one. A plain
         * `branch_id === myBranch` test refuses that correctly, but only by
         * accident of NULL never equalling an integer; stating it explicitly
         * means a later refactor that coalesces the null (`?? 1`, the way
         * lockedBranchId() does for staff) cannot quietly hand one branch's
         * manager the power to withdraw a dish from all of them.
         *
         * The owner is unaffected: lockedBranchId() is null for an admin, so
         * neither arm runs and every item stays deletable, shared ones
         * included.
         *
         * The refusal is a flash on the list rather than a 403, matching how
         * safelyDelete() reports a delete that cannot proceed — from the
         * caller's side "you may not" and "it is still referenced" land the
         * same way, on the same page.
         */
        $lockedBranchId = \App\Services\AdminOrderAccess::lockedBranchId();

        if ($lockedBranchId !== null) {
            if ($menuItem->branch_id === null) {
                return redirect()->route('admin.menu-items')
                    ->with('error', 'Shared menu items can only be deleted by the owner.');
            }

            if ((int) $menuItem->branch_id !== $lockedBranchId) {
                return redirect()->route('admin.menu-items')
                    ->with('error', 'You can only delete menu items belonging to your own branch.');
            }
        }

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
    // Branch-scoped for supervisor, unrestricted for admins — see
    // App\Services\AdminOrderAccess. A recipe row follows "Edit Menu Items"
    // (Y | Y | N), so a foreign-branch menu item id gets the same 404 as a
    // nonexistent one rather than accepting a recipe write onto it.
    $menuItemModel = \App\Services\AdminOrderAccess::resolveRecordInScope(\App\Models\MenuItem::class, $menuItem);

    $validated = $request->validate([
        'inventory_id' => 'required|exists:inventory,id',
        'quantity_used' => 'required|numeric|min:0.001',
    ]);

    $inventory = \App\Models\Inventory::findOrFail($validated['inventory_id']);

    // Same rule storeNewMenuItem()/updateMenuItem() already apply to every
    // recipe row: an ingredient must belong to the item's own branch. Only
    // checked when the item HAS a branch — a shared (NULL branch_id) item
    // is unaffected, matching how the rest of this rule already treats it.
    if ($menuItemModel->branch_id !== null
        && !$this->inventoryIsSelectableForBranch($inventory->id, $menuItemModel->branch_id)) {
        $message = 'Selected inventory item must belong to the same branch as this menu item.';

        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return redirect()->back()->withErrors(['inventory_id' => $message]);
    }

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
    // Branch-scoped for supervisor, unrestricted for admins — see
    // App\Services\AdminOrderAccess. Same rule addIngredient() applies:
    // a foreign-branch menu item id gets the same 404 as a nonexistent one
    // rather than accepting a recipe deletion against it.
    $menuItemModel = \App\Services\AdminOrderAccess::resolveRecordInScope(\App\Models\MenuItem::class, $menuItem);

    $ingredientModel = \App\Models\MenuItemIngredient::where('id', $ingredient)
        ->where('menu_item_id', $menuItemModel->id)
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
 *
 * BRANCH-LOCKED SUPERVISOR GUARD (Phase 3 audit, Finding #3, Sept 2026).
 * addIngredient() checks a recipe row against inventoryIsSelectableForBranch(),
 * which compares the inventory item's branch to the OWNING MENU ITEM's branch.
 * An option has no owning menu item — it is a global row that can be assigned
 * to items in several branches — so there is nothing to compare the inventory
 * item to except the ACTOR's own locked branch. A branch-locked supervisor may
 * only link ingredients from their own branch's inventory; an admin stays
 * unrestricted, the same split AdminOrderAccess draws everywhere else.
 */
public function addOptionIngredient(Request $request, int $menuOption)
{
    $optionModel = \App\Models\MenuOption::findOrFail($menuOption);

    $validated = $request->validate([
        'inventory_id' => 'required|exists:inventory,id',
        // max = the largest value menu_option_ingredients.quantity_used
        // (decimal(10,3)) can hold. Without it an oversized figure passed
        // validation and died in the INSERT (SQLSTATE 22003) as a 500 with a
        // logged server error, instead of the 422 the form can show.
        'quantity_used' => 'required|numeric|min:0.001|max:9999999.999',
    ]);

    $inventory = \App\Models\Inventory::findOrFail($validated['inventory_id']);

    $lockedBranchId = \App\Services\AdminOrderAccess::lockedBranchId();

    if ($lockedBranchId !== null && (int) $inventory->branch_id !== $lockedBranchId) {
        $message = "You can only link ingredients from your own branch's inventory.";

        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return redirect()->back()->withErrors(['inventory_id' => $message]);
    }

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
                // Additive keys (branch pass): an option is global but each
                // link points at ONE branch's inventory, so the row the page
                // appends has to say which — branch_name for the label,
                // branch_id so the page can re-derive the per-branch
                // Mapped/Unmapped badges without a reload. Both are null for
                // an inventory row with no branch (inventory.branch_id is
                // nullable, ON DELETE SET NULL).
                'branch_id' => $inventory->branch_id !== null ? (int) $inventory->branch_id : null,
                'branch_name' => $inventory->branch?->name,
                'delete_url' => route('admin.menu-options.ingredients.delete', [$optionModel->id, $ingredient->id]),
            ],
        ]);
    }

    return redirect()->back()->with('success', $message);
}

public function deleteOptionIngredient(Request $request, int $menuOption, int $ingredient)
{
    $ingredientModel = \App\Models\MenuOptionIngredient::with('inventory')
        ->where('id', $ingredient)
        ->where('menu_option_id', $menuOption)
        ->firstOrFail();

    // Phase 3b F6: menu options are global (no branch_id of their own), so
    // there is no parent record to scope through the way deleteIngredient()
    // scopes via the menu item's own branch_id. The same rule
    // addOptionIngredient() already applies on the way IN — a branch-locked
    // supervisor may only touch a recipe line whose INVENTORY belongs to
    // their own branch — applies on the way OUT too. Without this, a
    // supervisor could delete a recipe line linking another branch's
    // inventory to a company-wide add-on, silently stopping that branch's
    // stock deduction for it with no visible sign anything changed.
    $lockedBranchId = \App\Services\AdminOrderAccess::lockedBranchId();

    // optional()->branch_id rather than assuming the relation is loaded: a
    // branch-locked caller must be refused, not waved through, on the
    // (FK-prevented, but never assumed) chance the linked inventory row is
    // missing — fail closed, the same way every other branch check in this
    // file does.
    if ($lockedBranchId !== null
        && (int) optional($ingredientModel->inventory)->branch_id !== $lockedBranchId) {
        $message = "You can only remove ingredients from your own branch's inventory.";

        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return redirect()->back()->withErrors(['inventory_id' => $message]);
    }

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
            ->withCount('menuItems')
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
        // 'menuItems:id,branch_id' so the view can list, per option, which
        // branches it is actually assigned to and whether each has its own
        // ingredient mapping — see MenuOption::isMappedForBranch() (Phase 3
        // audit, Finding #3).
        $options = \App\Models\MenuOption::with(['ingredients.inventory', 'menuItems:id,branch_id'])
            ->orderBy('name')
            ->get();

        $categories = \App\Models\Category::with(['menuItems'])
            ->orderBy('name')
            ->get();

        $lockedBranchId = \App\Services\AdminOrderAccess::lockedBranchId();

        // Menu options are global (no branch_id), so offer every active inventory
        // item and label each with its branch so the admin picks the right one.
        // A branch-locked supervisor sees (and addOptionIngredient() only
        // accepts) their OWN branch's inventory — mirrors the restriction
        // inventoryIsSelectableForBranch() already applies to recipe rows.
        $inventoryItems = \App\Models\Inventory::with('branch')
            ->where('is_active', true)
            ->when($lockedBranchId !== null, fn($q) => $q->where('branch_id', $lockedBranchId))
            ->orderBy('item_name')
            ->get();

        // Every branch, for the per-branch mapped/unmapped indicator next to
        // each option — keyed by id so the view can look one up by the
        // option's assigned menu-item branch ids without another query. The
        // same lookup names the branch on each saved ingredient row, which is
        // why no `ingredients.inventory.branch` eager-load is needed.
        $branches = \App\Models\Branch::orderBy('id')->get()->keyBy('id');

        // Branches whose mapping is blocked because they have NO active
        // inventory to link to — the badge shows an "add inventory first"
        // hint for these. Derived from $inventoryItems (already loaded), so
        // it costs no query. That list is the actor's VISIBLE inventory: an
        // admin sees every branch's, so every branch can be judged; a
        // branch-locked supervisor sees only their own, so only their own
        // branch can be — another branch showing "none" there would be a
        // claim this page cannot back, and they could not act on it anyway.
        $emptyInventoryBranchIds = ($lockedBranchId === null ? $branches->keys() : collect([$lockedBranchId]))
            ->diff($inventoryItems->pluck('branch_id')->filter()->unique())
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $archivedCount = $this->archivedCatalogueCount();

        return view(
            'admin.menu-options',
            compact(
                'options',
                'categories',
                'inventoryItems',
                'branches',
                'archivedCount',
                'lockedBranchId',
                'emptyInventoryBranchIds'
            )
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
     * historical order recorded. Phase 3b F9 (2026-09-20) closed the one gap
     * in that protection: customer/receipt.blade.php was reading the option's
     * name back through the live relationship instead of the snapshot column
     * — it now reads `$option->pivot->option_name`, so renaming an option
     * here no longer changes the add-on label on past receipts.
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
        // Branch-scoped for supervisor, unrestricted for admins — see
        // App\Services\AdminOrderAccess. Options themselves are global (no
        // branch_id — see showMenuOptions()), so only the menu item being
        // assigned needs the branch check. A foreign id gets the same 404
        // as a nonexistent one.
        $menuItem = \App\Services\AdminOrderAccess::resolveRecordInScope(\App\Models\MenuItem::class, $menuItemId);

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

            // The manual-order form's own branch picker. A branch-locked
            // staff/supervisor may only ever submit into their own branch
            // (storeManualOrder() enforces this via AdminOrderAccess::
            // allowsBranch()), so the dropdown must not offer branches that
            // submission would just 404 on.
            $lockedBranchIdForManualOrder = \App\Services\AdminOrderAccess::lockedBranchId();

            $branches = ($lockedBranchIdForManualOrder !== null)
                ? \App\Models\Branch::where('id', $lockedBranchIdForManualOrder)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get()
                : \App\Models\Branch::where('is_active', true)
                    ->orderBy('name')
                    ->get();

            $menuItems = \App\Models\MenuItem::with([
                'category',
                'subcategory',
                'recipeIngredients',
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
                // The branch the manual order is being written for. Sent by the
                // modal so this preview judges the branch rule against the same
                // branch storeManualOrder() will validate and save — without it
                // the counter would be told a branch voucher is fine and then
                // refused on submit, which is the preview/charge disagreement
                // Voucher's own header comment exists to prevent.
                'branch_id' => 'nullable|integer|exists:branches,id',
            ]);

            $subtotal = (float) $request->input('subtotal');
            $branchId = $request->input('branch_id');

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
            $error = $voucher->redemptionErrorFor(
                null,
                $subtotal,
                $resolved['claim'],
                $branchId ? (int) $branchId : null
            );

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

        /**
         * Seconds the storeManualOrder() duplicate-submit lock is held for at
         * most.
         *
         * A ceiling, not the normal hold time: the lock is released in a
         * finally block the instant the request finishes, which in practice is
         * well under a second. The ceiling only matters if PHP were killed
         * mid-request in a way that skips finally (a fatal crash rather than a
         * thrown exception, which finally still runs for), so it just needs to
         * be comfortably longer than any real request takes. Same value and
         * same reasoning as the customer checkout's
         * OrderController::DUPLICATE_SUBMIT_LOCK_SECONDS, deliberately, so the
         * two doors into the same pantry behave identically.
         */
        private const MANUAL_ORDER_LOCK_SECONDS = 20;

        /**
         * The cache key the walk-in counter's duplicate-submit mutex is held
         * on.
         *
         * SCOPE: one signed-in staff account IN one browser session — i.e. one
         * till. Both halves matter and neither is redundant:
         *
         *   - the SESSION half is what makes two tills independent. Staff
         *     commonly share one counter account, so keying on the user alone
         *     would let till A's in-flight order block till B's unrelated one,
         *     which is a real refusal of real business. Two browsers are two
         *     sessions, so they never share a key;
         *   - the USER half costs nothing (a session belongs to exactly one
         *     signed-in account; Laravel regenerates the id on login) and
         *     guarantees two different staff can never land on one key even if
         *     a session id were ever reused. This path writes
         *     payment_status = 'paid' immediately, so it is worth the belt.
         *
         * Session granularity misses none of the duplicate shapes F8 is about,
         * because every one of them originates in the SAME browser session:
         * a double click, two tabs open on the dashboard, and a client or
         * proxy retrying the POST all carry the same session cookie.
         *
         * Deliberately NOT keyed on branch_id (two tills in one branch would
         * block each other — far too coarse), and deliberately NOT reusing
         * RateLimitServiceProvider::visitorKey(): that function answers
         * "which customer/visitor is this" and returns customer:{id} first, so
         * a staff member who also happened to be signed in as a customer in
         * the same browser would collide with their own checkout lock.
         */
        private function manualOrderLockKey(Request $request): string
        {
            $staffId = \Illuminate\Support\Facades\Auth::guard('admin')->id();

            $session = $request->hasSession()
                ? $request->session()->getId()
                : 'no-session:' . $request->ip();

            return 'manual-order-inflight:admin:' . $staffId . ':' . $session;
        }

        public function storeManualOrder(Request $request)
        {
            $validated = $request->validate([
                'branch_id' => 'required|exists:branches,id',
                'order_type' => 'required|in:dine_in,pick_up',
                'table_number' => 'nullable|string|max:50',
                'payment_method' => 'required|in:cash,gcash',
                // Upper bound matches the decimal(10,2) the column actually
                // is. Without it a tampered or fat-fingered amount_paid was
                // only caught by MySQL strict mode at INSERT time, which
                // surfaced as the generic "could not be saved" catch plus a
                // logged QueryException instead of a readable field error.
                'amount_paid' => 'required|numeric|min:0|max:99999999.99',
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

            /*
             * DUPLICATE-SUBMIT LOCK FOR THE WALK-IN COUNTER
             * (Phase 3a audit, Finding F8).
             *
             * The customer checkout has had this since 2026-09-01; the counter
             * — the other door into the same pantry and the one that writes
             * payment_status = 'paid' on the spot — had neither half of the
             * guard. The submit handler in admin/home.blade.php now disables
             * the button synchronously, which is airtight against a literal
             * double-click on the SAME rendered page and does nothing for a
             * request that reaches the server twice some OTHER way: two tabs
             * open on the dashboard, or a flaky connection causing the browser
             * to retry the POST. That is what this is for.
             *
             * WHAT IT IS, AND IS NOT. This is a true mutex held only for the
             * duration of THIS request and ALWAYS released in the finally
             * block at the foot of the method — not an idempotency window. So:
             *
             *   - it blocks a second request that genuinely OVERLAPS the first
             *     for the same till, which is the actual shape of a
             *     double-submit;
             *   - it does NOT block the next customer's order from the same
             *     till: by the time that arrives the first request has long
             *     since finished and released;
             *   - it does NOT hold through a refusal, so staff who fix a
             *     rejected field and resubmit immediately are never wrongly
             *     blocked — every return below, success or refusal, is inside
             *     the try and releases in finally;
             *   - it does NOT make the endpoint idempotent AFTER a successful
             *     order has committed and the lock has been released. A retry
             *     that arrives then is indistinguishable, with what the
             *     request carries today, from staff ringing up the next
             *     customer. Closing that would need a persisted per-submission
             *     token, which is a schema change and deliberately not part of
             *     F8. In practice every response from this method is a
             *     redirect, so the browser's own back button cannot re-POST it.
             *
             * Validation failures never reach here at all: $request->validate()
             * above throws before the lock is taken.
             */
            $duplicateSubmitLock = \Illuminate\Support\Facades\Cache::lock(
                $this->manualOrderLockKey($request),
                self::MANUAL_ORDER_LOCK_SECONDS
            );

            if (! $duplicateSubmitLock->get()) {
                /*
                 * Lost the race to the request already in flight. Degrade the
                 * way every other refusal in this method does — back() with a
                 * readable error and withInput(), which is what makes
                 * reopenRejectedManualOrder() put the modal back on screen
                 * with the keyed-in cart intact — never a raw error, never a
                 * 500, and above all never a second order.
                 */
                return back()
                    ->withErrors([
                        'error' => 'This order is already being submitted. '
                            . 'Please wait a moment before trying again.',
                    ])
                    ->withInput();
            }

            try {

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

            $menuItems = \App\Models\MenuItem::with(['options.ingredients.inventory', 'recipeIngredients'])
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

                // No-recipe guard (Phase 3 audit, Finding #9): mirrors
                // MenuItem::orderBlockedReason() on the customer checkout —
                // an item with no recipe lines and no legacy inventory_item_id
                // link has no bill of materials, so completing this order
                // later would deduct nothing and the kitchen would have no
                // instructions. Staff-facing wording since this is the
                // counter flow, not the customer's.
                if ($menuItem->isMissingRecipe()) {
                    return back()
                        ->withErrors([
                            'items' => $menuItem->name . ' has no recipe set and cannot be added to an order yet.'
                        ])
                        ->withInput();
                }

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

                    // Branch-aware add-on guard (Phase 3 audit, Finding #3):
                    // a global option needs its own ingredient link for THIS
                    // walk-in order's branch — branches never share stock.
                    // Mirrors the same refusal the online checkout applies in
                    // OrderController::placeOrder().
                    if (! $option->isMappedForBranch($branchId)) {
                        return back()
                            ->withErrors([
                                'items' => 'The "' . $option->name . '" add-on is not available for this branch.'
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
            //
            // THE GUARD IS cartShortfalls(), the same one the customer checkout
            // uses — not validateCartLines(), which this path used to call.
            // The counter is the other door into the same pantry, and that
            // older check measured against the RAW inventory.quantity: since
            // stock only leaves at completion, an online order that had
            // already spoken for the last serving was invisible to it, so
            // staff were waved through to sell it a second time. Because a
            // manual order is written payment_status = 'paid', the shortage
            // then surfaced at completeOrder() — on an order the customer had
            // already paid for, which is precisely the harm the oversell fix
            // was built to prevent. Reproduced end to end in
            // InventoryDeductionEndToEndTest.
            //
            // This unlocked pass exists only to fail fast and phrase the
            // refusal the way customers already see it ("Only N left"); the
            // authoritative, locked re-check runs inside the order
            // transaction below.
            $stockLines = array_map(fn($data) => [
                'menu_item' => $data['menu_item'],
                'quantity' => $data['quantity'],
                'selected_option_ids' => array_column($data['options'], 'id'),
            ], $itemsToCreate);

            $stockErrors = app(\App\Services\InventoryDeductionService::class)
                ->cartShortfalls($stockLines, (int) $branchId);

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
                // Including the branch rule: a manual order is an order placed
                // AT a branch like any other, and $validated['branch_id'] is
                // already required and existence-checked above.
                $voucherError = $voucher->redemptionErrorFor(
                    null,
                    $total,
                    $voucherClaim,
                    (int) $validated['branch_id']
                );

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
                    $voucherClaim,
                    $stockLines
                ) {
                    /*
                     * AUTHORITATIVE stock gate, and deliberately the first
                     * statement in the transaction — the same position and the
                     * same call the customer checkout makes
                     * (OrderController::placeOrder). It takes SELECT … FOR
                     * UPDATE on every inventory row these lines touch, so a
                     * checkout or a second terminal wanting the same rows
                     * blocks here until this order has committed and is then
                     * counted as committed demand, instead of both being waved
                     * through on the same last serving.
                     *
                     * Throwing rolls the whole transaction back: no order row,
                     * no order_items, no spent voucher. RuntimeException is
                     * what the catch below already renders to staff verbatim.
                     */
                    $lockedShortfalls = app(\App\Services\InventoryDeductionService::class)
                        ->cartShortfalls($stockLines, (int) $branchId, true);

                    if (!empty($lockedShortfalls)) {
                        throw new \RuntimeException($lockedShortfalls[0]);
                    }

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
                // Auth::id() reads the DEFAULT guard ("web"), which nobody in
                // this application ever signs into — staff use the "admin"
                // guard — so this field logged null on every manual-order
                // failure and the one line that says WHO was at the till was
                // always blank.
                Log::error('Manual order creation failed', [
                    'admin_id'  => Auth::guard('admin')->id(),
                    'exception' => $e,
                ]);

                return back()
                    ->withErrors([
                        'error' => 'The manual order could not be saved. Nothing was '
                            . 'recorded and no stock was deducted. Please try again.'
                    ])
                    ->withInput();
            }

            } finally {
                /*
                 * Always released here, success or refusal — see the long
                 * comment on the acquisition above for why this is a
                 * short-lived mutex and not a hold-after-success debounce
                 * window. A throwable escaping the inner catch arms releases
                 * through here too. (The cross-branch abort(404) never needs
                 * it: that guard sits ABOVE the acquisition, so a refused
                 * branch takes no lock in the first place.)
                 */
                $duplicateSubmitLock->release();
            }
        }

    /**
     * The filtered, branch-scoped completed/cancelled orders query, shared by
     * the paginated on-screen list and the unbounded "Print Filtered" report
     * below — one place that decides which rows match, so the two can never
     * silently disagree about what "filtered" means.
     */
    private function completedOrdersQuery(Request $request)
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

        return $query->orderBy('updated_at', 'desc');
    }

    /**
     * Allow-listed rows-per-page, same 4 choices the old client-side "Rows"
     * selector offered — this just makes the server actually apply the
     * choice instead of the browser hiding the rest of an unbounded result.
     */
    private function paginationPerPage(Request $request, int $default = 25): int
    {
        $requested = (int) $request->input('per_page', $default);

        return in_array($requested, [15, 25, 50, 100], true) ? $requested : $default;
    }

    public function showCompletedOrders(Request $request)
    {
        // Real server-side pagination (2026-09-14): this page used to load
        // EVERY matching order and hide the rest with CSS, which meant the
        // full history — and every branch's data transferred over it — grew
        // heavier on every mobile page load as the order history grew. Only
        // the current page's rows are ever fetched or rendered now.
        $orders = $this->completedOrdersQuery($request)
            ->paginate($this->paginationPerPage($request))
            ->withQueryString();

        $orderRatings = \App\Models\OrderRating::whereIn('order_id', $orders->pluck('id'))
            ->get()
            ->keyBy('order_id');

        return view('admin.completed-orders', compact('orders', 'orderRatings'));
    }

    /**
     * "Print Filtered" — every order matching the current filters, not just
     * the on-screen page. Deliberately its own unbounded query rather than
     * reusing whatever the paginated list happens to hold: since pagination
     * was added, the Blade variable on the list page only ever has one
     * page's worth of rows, so printing "the current list" would silently
     * print one page instead of the filtered set the button promises. This
     * is a standalone print document (own <html>, no admin chrome to hide),
     * opened in a new tab and printed immediately — the same pattern
     * printReceipt() already uses for a single order.
     */
    public function printCompletedOrders(Request $request)
    {
        $orders = $this->completedOrdersQuery($request)->get();

        $orderRatings = \App\Models\OrderRating::whereIn('order_id', $orders->pluck('id'))
            ->get()
            ->keyBy('order_id');

        $selectedBranch = $this->getSelectedBranch();
        $selectedBranchName = $selectedBranch !== 'all'
            ? \App\Models\Branch::find($selectedBranch)?->name
            : null;

        return view('admin.completed-orders-print', compact('orders', 'orderRatings', 'selectedBranchName'));
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

    /**
     * The rows the Inventory page is ABOUT, for one branch scope.
     *
     * Three surfaces render this same list — the screen (showInventory()), the
     * CSV (exportInventory()) and the printed report (printInventory()) — and
     * all three are meant to be the same list in three formats. They used to
     * each carry their own copy of this query; the copies agreed, but nothing
     * made them agree, so the next change to one of them was free to leave the
     * other two behind.
     *
     * notArchived(): a soft-deleted item is not "gone", it is off THIS list —
     * see Inventory::archive() and the "Deleted Items" page
     * (showDeletedInventory() below). Nothing here re-decides that rule; it is
     * the model's, and this is the single place the reports opt into it.
     */
    private function inventoryRowsForScope(int|string $selectedBranch)
    {
        return \App\Models\Inventory::notArchived()
            ->orderBy('item_name')
            ->when(
                $selectedBranch !== 'all',
                fn($q) => $q->where('branch_id', $selectedBranch)
            )
            ->get();
    }

    /**
     * "All Branches" or the one branch's name, for a report heading.
     *
     * Same expression the CSV header and the Summary/Analytics reports use;
     * lifted here so the printed inventory sheet cannot name a branch
     * differently from the CSV of the same scope.
     */
    private function branchScopeName(int|string $selectedBranch): string
    {
        return $selectedBranch === 'all'
            ? 'All Branches'
            : (optional(\App\Models\Branch::find($selectedBranch))->name ?? 'Unknown Branch');
    }

    public function showInventory()
    {
        $selectedBranch = $this->getSelectedBranch();

        $inventory = $this->inventoryRowsForScope($selectedBranch);

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

        // "Deleted Items (N)" link — admin-only feature, but cheap enough to
        // always compute so the count is never stale on this page.
        $deletedInventoryCount = \App\Models\Inventory::onlyArchived()->count();

        return view(
            'admin.inventory',
            compact('inventory', 'categories', 'stockMovements', 'deletedInventoryCount')
        );
    }

    /**
     * The owner asked for a plain CSV/spreadsheet download of the Inventory
     * table, not a browser print view. No spreadsheet library (e.g.
     * maatwebsite/excel) is installed in this project, and this close to
     * the defense pulling one in for a single table is not worth the risk
     * — Excel opens a UTF-8 CSV natively, so a streamed CSV with a BOM
     * satisfies "Excel or notepad-style export" without a new dependency.
     * Same branch scope, same row order, and the same columns as the
     * on-screen table (showInventory() above) — this is a export of what
     * is already visible, not a separate report.
     */
    public function exportInventory()
    {
        $selectedBranch = $this->getSelectedBranch();

        // Same notArchived() filter as the on-screen table, because it is
        // literally the same query now — see inventoryRowsForScope(). This
        // export is a download of what is already visible, not a separate
        // report, and that must include staying in sync on which rows a soft
        // delete removed from both.
        $inventory = $this->inventoryRowsForScope($selectedBranch);

        $branchName = $this->branchScopeName($selectedBranch);

        $filenameBranch = Str::slug($branchName) ?: 'all-branches';
        $filename = "inventory_{$filenameBranch}_" . now()->format('Y-m-d') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($inventory, $branchName) {
            $file = fopen('php://output', 'w');

            // Excel opens a CSV as the system codepage unless it finds a BOM;
            // without this the peso sign arrives as mojibake.
            fwrite($file, "\xEF\xBB\xBF");

            fputcsv($file, ['Peachy Cakes & Deli Cafe — Inventory']);
            // Branch name and account name are both user-entered free text
            // (storeBranch / storeUser), so they go through the same
            // formula-injection guard as every data row below — see
            // App\Support\Csv. This header block was the one place in this
            // export that had been missed.
            fputcsv($file, ['Branch', \App\Support\Csv::cell($branchName)]);
            fputcsv($file, ['Generated', now()->format('M d, Y g:i A')]);
            fputcsv($file, ['Generated by', \App\Support\Csv::cell(optional(auth('admin')->user())->name ?? 'Unknown user')]);
            fputcsv($file, []);

            fputcsv($file, [
                'Item Name',
                'Quantity',
                'Unit',
                'Low Stock Alert',
                'Stock Value',
                'Status',
            ]);

            // Same three-way split and the same running total the stat tiles
            // at the top of the Inventory page compute (admin.inventory's own
            // @php block) — out first, then low, so a row can only land in
            // one bucket, matching quantity <= 0 / quantity <= low_stock_alert
            // / else exactly. Accumulated in this same loop rather than a
            // second pass, so it can never drift from the rows above it.
            $countOut = 0;
            $countLow = 0;
            $countOk = 0;
            $totalStockValue = 0;

            foreach ($inventory as $item) {
                $isOut = $item->quantity <= 0;
                $isLow = !$isOut && $item->quantity <= $item->low_stock_alert;
                $status = $isOut ? 'Out of Stock' : ($isLow ? 'Low Stock' : 'In Stock');

                if ($isOut) { $countOut++; }
                elseif ($isLow) { $countLow++; }
                else { $countOk++; }
                $totalStockValue += (float) $item->quantity * (float) $item->unit_cost;

                fputcsv($file, [
                    // User-entered free text, so it goes through the
                    // formula-injection guard on the way out: an item saved as
                    // "=cmd|..." must arrive in the spreadsheet as a label, not as
                    // something the spreadsheet evaluates. See App\Support\Csv.
                    \App\Support\Csv::cell($item->item_name),
                    // 3 decimals to match the column (decimal(12,3)) and the
                    // on-screen table — exporting at 2 rounded fractional
                    // stock away, same as the page used to.
                    rtrim(rtrim(number_format($item->quantity, 3, '.', ''), '0'), '.'),
                    \App\Support\Csv::cell($item->unit),
                    rtrim(rtrim(number_format($item->low_stock_alert, 3, '.', ''), '0'), '.'),
                    number_format($item->quantity * $item->unit_cost, 2, '.', ''),
                    $status,
                ]);
            }

            // The same figures as the page's stat strip, in the same order —
            // Total / In Stock / Low Stock / Out of Stock / Total Stock Value.
            fputcsv($file, []);
            fputcsv($file, ['SUMMARY']);
            fputcsv($file, ['Total Items', count($inventory)]);
            fputcsv($file, ['In Stock', $countOk]);
            fputcsv($file, ['Low Stock', $countLow]);
            fputcsv($file, ['Out of Stock', $countOut]);
            fputcsv($file, ['Total Stock Value', number_format($totalStockValue, 2, '.', '')]);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * "Print" for Inventory — the printInFrame() shape already established by
     * printAnalytics() and printCompletedOrders().
     *
     * Deliberately NOT a second reporting architecture. It reuses, in order:
     *
     *  - the same branch scope, from getSelectedBranch(), so a branch-locked
     *    supervisor prints their own branch and has no request they can make
     *    that widens it — the scope is never read from the querystring;
     *  - the same ROWS as the screen and the CSV, through
     *    inventoryRowsForScope(), which is where notArchived() is applied. The
     *    printed sheet therefore lists exactly the rows the Inventory page
     *    lists, and a soft delete removes an item from all three at once;
     *  - the same STATUS RULE as the screen and the CSV — quantity <= 0 is Out
     *    of Stock, then quantity <= low_stock_alert is Low — computed in the
     *    view from the same two columns, in the same out-before-low order, so
     *    a row lands in exactly one bucket. No second rule is invented here,
     *    and no Inventory business logic was changed to make this printable;
     *  - the same print-only view shape and visual language as
     *    admin.analytics-print.
     *
     * The totals are computed in the Blade from this same collection, exactly
     * as admin.inventory's own @php block computes the on-screen stat strip —
     * one pass, accumulated alongside the rows, so the summary cannot disagree
     * with the list above it.
     */
    public function printInventory()
    {
        $selectedBranch = $this->getSelectedBranch();

        return view('admin.inventory-print', [
            'inventory'  => $this->inventoryRowsForScope($selectedBranch),
            'branchName' => $this->branchScopeName($selectedBranch),
            'printedBy'  => optional(auth('admin')->user())->name ?? 'Unknown user',
            'printedAt'  => now(),
        ]);
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
        // Branch-scoped for supervisor, unrestricted for admins — see
        // App\Services\AdminOrderAccess. Same rule stockIn()/stockOut()/
        // editInventory() already apply; this definition edit endpoint had
        // been missed. A foreign id gets the same 404 as a nonexistent one.
        $item = \App\Services\AdminOrderAccess::resolveRecordInScope(\App\Models\Inventory::class, $id);

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

    /**
     * "Delete" is now the FIRST of two stages: this moves the item off the
     * normal Inventory list and into Deleted Items, recoverable at any time.
     * The previous single, irreversible delete is now forceDeleteInventory()
     * below, reachable only from the Deleted Items page.
     *
     * Deliberately does NOT touch stock_movements or the recipe links
     * (menu_item_ingredients / menu_option_ingredients) — archiving only
     * stamps archived_at (and mangles item_code, see Inventory::archive()),
     * so an archived item keeps deducting normally for any recipe still
     * pointing at it until it is either restored or permanently deleted. That
     * is exactly what makes this stage safe to use freely.
     */
    public function deleteInventory(int $id)
    {
        $item = \App\Models\Inventory::notArchived()->findOrFail($id);

        $item->archive();

        return redirect()->route('admin.inventory')
            ->with(
                'success',
                'Inventory item "' . $item->item_name . '" was moved to Deleted Items. '
                . 'Restore it any time, or permanently delete it from there.'
            );
    }

    /**
     * Deleted Items — admin-only, matching "Delete Inventory Records" (Y | N | N).
     */
    public function showDeletedInventory()
    {
        $selectedBranch = $this->getSelectedBranch();

        $deletedItems = \App\Models\Inventory::onlyArchived()
            ->when(
                $selectedBranch !== 'all',
                fn($q) => $q->where('branch_id', $selectedBranch)
            )
            ->orderByDesc('archived_at')
            ->get();

        /*
         * What a Permanent Delete would do to each row, computed up front so
         * the page can say so BEFORE the admin clicks — not as a surprise in
         * the success message afterward. Mirrors the two checks
         * forceDeleteInventory() itself makes.
         */
        $recipeCounts = [];
        $movementCounts = [];
        foreach ($deletedItems as $item) {
            $recipeCounts[$item->id] = \App\Models\MenuItemIngredient::where('inventory_id', $item->id)->count()
                + \App\Models\MenuOptionIngredient::where('inventory_id', $item->id)->count();
            $movementCounts[$item->id] = \App\Models\StockMovement::where('inventory_id', $item->id)->count();
        }

        return view('admin.inventory-deleted', compact('deletedItems', 'recipeCounts', 'movementCounts'));
    }

    /** Bring an archived item back to the normal Inventory list. */
    public function restoreInventory(int $id)
    {
        $item = \App\Models\Inventory::onlyArchived()->find($id);

        if (!$item) {
            return redirect()->route('admin.inventory.deleted')
                ->withErrors(['error' => 'That item is not in Deleted Items — it may have been restored or permanently deleted already.']);
        }

        $item->unarchive();

        $message = 'Inventory item "' . $item->item_name . '" was restored.';

        // unarchive() keeps the mangled item_code when the original was
        // claimed by something else in the meantime — told explicitly here
        // rather than left for the admin to notice a strange code later.
        if (str_ends_with((string) $item->item_code, '-DEL-' . $item->id)) {
            $message .= ' Its original item code was taken by another item in the meantime, '
                . 'so it kept "' . $item->item_code . '" — update it if you want a different one.';
        }

        return redirect()->route('admin.inventory')->with('success', $message);
    }

    /**
     * THE actual, irreversible delete — only reachable from Deleted Items, so
     * only ever called on a row that has already been through the recoverable
     * stage above.
     *
     * Two references are checked, exactly as investigated for this feature:
     *
     *   menu_item_ingredients / menu_option_ingredients (recipe lines) are
     *   ON DELETE CASCADE — unchanged, existing policy (see deleteInventory()'s
     *   previous version): the admin is told, not blocked, because by the time
     *   an item reaches this second stage it has already been off the live
     *   Inventory list for a while and any recipe still pointing at it was
     *   already visibly broken (see Inventory::archive()'s docblock — an
     *   archived row still deducts normally, so nothing silently stopped
     *   working until THIS click).
     *
     *   stock_movements.inventory_id is ON DELETE SET NULL (migration
     *   2026_09_16_000001) — the movement rows survive with inventory_id
     *   NULL. deleted_item_name is stamped onto them first so the Stock
     *   Movements Log keeps saying what they were for instead of showing
     *   "N/A" for history that used to have a name.
     */
    public function forceDeleteInventory(int $id)
    {
        $item = \App\Models\Inventory::onlyArchived()->find($id);

        if (!$item) {
            return redirect()->route('admin.inventory.deleted')
                ->withErrors(['error' => 'That item is not in Deleted Items — it may have been restored or permanently deleted already.']);
        }

        $recipeLinks = \App\Models\MenuItemIngredient::where('inventory_id', $id)->count()
            + \App\Models\MenuOptionIngredient::where('inventory_id', $id)->count();
        $movementCount = \App\Models\StockMovement::where('inventory_id', $id)->count();

        return $this->safelyDelete(
            function () use ($item, $recipeLinks, $movementCount) {
                $name = $item->item_name;

                if ($movementCount > 0) {
                    \App\Models\StockMovement::where('inventory_id', $item->id)
                        ->update(['deleted_item_name' => $name]);
                }

                $item->delete();

                $message = 'Inventory item "' . $name . '" was permanently deleted.';

                if ($recipeLinks > 0) {
                    $message .= ' ' . ucfirst($this->countLabel($recipeLinks, 'recipe link'))
                        . ' using it was removed too, so check the affected items still deduct correctly.';
                }

                if ($movementCount > 0) {
                    $message .= ' ' . ucfirst($this->countLabel($movementCount, 'past stock movement'))
                        . ' for it stayed in the Stock Movements Log for history.';
                }

                return redirect()->route('admin.inventory.deleted')
                    ->with('success', $message);
            },
            'admin.inventory.deleted',
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
        // Was `$staff->role === 'staff' && $staff->branch_id` — a second,
        // hand-rolled copy of the branch lock that the Sept 2026 role pass
        // found had already drifted from the real one in AdminOrderAccess. A
        // supervisor would have matched neither arm of that comparison and been
        // offered EVERY branch's picker. Asking lockedBranchId() means this
        // section can never again disagree with the lists and the per-record
        // endpoints about who is branch-bound.
        $lockedBranchId = \App\Services\AdminOrderAccess::lockedBranchId();

        $branches = ($lockedBranchId !== null)
            ? \App\Models\Branch::where('id', $lockedBranchId)->where('is_active', true)->get()
            : \App\Models\Branch::where('is_active', true)->get();

        /*
         * Who may rotate a table code — it invalidates a printed card and
         * forces a reprint, so it is a management call, not a counter action.
         *
         * "QR & Table Codes" is Y | Y | VIEW-ONLY, so this is isManager()
         * rather than the `role === 'admin'` it read before: a supervisor gets
         * the capability, staff get this page without the button. Spelled from
         * the model helper so it cannot disagree with the matching
         * `role:admin,supervisor` gate on admin.qr-generator.regenerate-code.
         *
         * A supervisor is still refused ANOTHER branch's table by
         * regenerateTableCode() itself, which asks AdminOrderAccess. This flag
         * is the role half only; it is not the branch half and never was.
         */
        $canRegenerate = $staff && $staff->isManager();

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
        // allowsBranch() is the one rule (true for an admin, "is this mine?"
        // for any branch-locked role), replacing the hand-rolled comparison
        // this line used to carry. A supervisor is now refused another
        // branch's card exactly as staff are.
        if (! \App\Services\AdminOrderAccess::allowsBranch((int) $branch->id)) {
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
        // role that is — and as of the Sept 2026 role pass it actually IS,
        // because it asks AdminOrderAccess rather than naming 'staff' itself.
        // The previous spelling made that comment untrue for supervisor: a
        // supervisor failed the `role === 'staff'` test and rotated any
        // branch's code, invalidating another branch's printed cards.
        if (! \App\Services\AdminOrderAccess::allowsBranch((int) $table->branch_id)) {
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

        // getSelectedBranch() already delegates to lockedBranchId(), so for a
        // branch-locked role both arms of this now produce the same value —
        // which is the point. Kept explicit because the fallback arm reads the
        // admin's "Viewing:" picker, and only an admin has one.
        $scope = \App\Services\AdminOrderAccess::lockedBranchId()
            ?? $this->getSelectedBranch();

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
                    // How many devices are CURRENTLY at this table — see
                    // TableOccupancy::activeDeviceCount(). Always 0 for a
                    // staff-opened counter order: no customer browser ever
                    // claimed it, so there is no device to count.
                    'device_count' => \App\Services\TableOccupancy::activeDeviceCount($s),
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

    /*
    |--------------------------------------------------------------------------
    | PROMOTION BRANCH SCOPE — vouchers and ads
    |--------------------------------------------------------------------------
    |
    | "Create/Edit/Activate Vouchers" and "Manage Advertisements" are Y | Y | N
    | in the matrix, but a supervisor's Y is a LIMITED Y: their reach stops at
    | their OWN branch. vouchers.branch_id and ads.branch_id (both nullable,
    | NULL = global) exist for exactly that, and the three methods below are the
    | one definition of the rule for both tables.
    |
    | THIS IS THE deleteMenuItem() PATTERN, NOT A NEW ONE. Same
    | AdminOrderAccess::lockedBranchId(), same two explicit refusals (a global
    | row first, then a foreign branch), same flash-redirect shape rather than a
    | 403. It is factored into methods only because SIX endpoints need it —
    | store/update/toggle for vouchers, store/update/toggle/delete for ads — and
    | six hand-rolled copies is precisely how one of them ends up being the weak
    | one. Same argument AdminOrderAccess itself was created on.
    |
    | WHY THE GLOBAL ARM IS SPELLED OUT SEPARATELY
    | --------------------------------------------
    | AdminOrderAccess::allowsBranch(null) looks like it would answer this, and
    | for a staff member it does. It does NOT for a supervisor with no branch
    | assigned: lockedBranchId() returns the deny-by-default sentinel 0 for that
    | account, `(int) null` is also 0, and the two would compare equal — handing
    | the one account that is supposed to see nothing the power to edit every
    | company-wide promotion. The NULL case is therefore tested before any
    | integer comparison happens, exactly as deleteMenuItem() argues.
    */

    /**
     * The branch a NEWLY created voucher/ad belongs to.
     *
     *  - Supervisor: their own branch, always. Their form has no branch field
     *    and a crafted branch_id in the request body is ignored rather than
     *    refused — there is only one answer they are allowed, so taking it from
     *    the account instead of the payload means there is nothing to forge.
     *  - Owner: whatever they picked, and NULL (global) when they picked
     *    nothing. Global stays the default: it is what every promotion in the
     *    system is today, and an owner who wants one branch says so explicitly.
     */
    private function promotionBranchIdFor(Request $request): ?int
    {
        $lockedBranchId = \App\Services\AdminOrderAccess::lockedBranchId();

        if ($lockedBranchId !== null) {
            return $lockedBranchId;
        }

        $chosen = $request->input('branch_id');

        return ($chosen === null || $chosen === '') ? null : (int) $chosen;
    }

    /**
     * A redirect refusing this action, or null when the actor may proceed.
     *
     * $record is any voucher/ad (anything carrying a nullable branch_id).
     * Returns null for the owner unconditionally — lockedBranchId() is null for
     * them, so neither arm below runs and nothing about their existing reach
     * changes.
     */
    private function promotionScopeRefusal($record, string $route, string $noun)
    {
        $lockedBranchId = \App\Services\AdminOrderAccess::lockedBranchId();

        if ($lockedBranchId === null) {
            return null;
        }

        // Global row. Checked FIRST and on its own — see the block comment.
        if ($record->branch_id === null) {
            return redirect()->route($route)
                ->with('error', 'Company-wide ' . $noun . 's can only be managed by the owner.');
        }

        if ((int) $record->branch_id !== $lockedBranchId) {
            return redirect()->route($route)
                ->with('error', 'You can only manage ' . $noun . 's belonging to your own branch.');
        }

        return null;
    }

    /**
     * Narrow a voucher/ad listing to what this viewer may SEE.
     *
     * A branch-locked viewer gets their own branch's rows PLUS the global ones.
     * The globals are deliberately included and deliberately read-only in the
     * blade: staff already read every voucher on this page ("View Vouchers" is
     * Y | Y | Y, so they can quote a code at the counter), and showing a
     * supervisor LESS than the staff they manage would be the real surprise.
     * What they must not get is another BRANCH's promotion, which is what the
     * whereNull/orWhere pair excludes.
     */
    private function scopePromotionListing($query)
    {
        $lockedBranchId = \App\Services\AdminOrderAccess::lockedBranchId();

        if ($lockedBranchId !== null) {
            $query->where(function ($q) use ($lockedBranchId) {
                $q->whereNull('branch_id')
                    ->orWhere('branch_id', $lockedBranchId);
            });
        }

        return $query;
    }
    public function showVouchers(Request $request)
    {
        // Own branch + global only for a supervisor; unchanged (every
        // row) for the owner and for staff's read-only counter list.
        //
        // Real server-side pagination (2026-09-14) for the same reason as
        // Completed Orders: this used to load every voucher ever created —
        // every code minted by the Spin Wheel and every walk-in issue-code
        // counts against this table forever, so it only grows.
        $vouchers = $this->scopePromotionListing(
            \App\Models\Voucher::with('branch')
        )
            ->orderBy('created_at', 'desc')
            ->paginate($this->paginationPerPage($request))
            ->withQueryString();

        // The owner's branch-scope picker on the Create form. A
        // branch-locked viewer never sees the control, so it is not
        // built for them.
        $branches = \App\Services\AdminOrderAccess::lockedBranchId() === null
            ? \App\Models\Branch::orderBy('id')->get()
            : collect();

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
            'branches',
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
            // Owner-supplied scope. Ignored for a supervisor — see
            // promotionBranchIdFor() — but still validated so a bad id from the
            // owner's own picker is a 422 rather than a foreign-key crash.
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        \App\Models\Voucher::create([
            'branch_id' => $this->promotionBranchIdFor($request),
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

        /*
         * The LIMITED half of "Edit Vouchers". A supervisor may edit only a
         * voucher scoped to their own branch — never a company-wide one, never
         * another branch's — and this is what stops a hand-crafted PUT, not the
         * absence of the button on the page.
         */
        if ($refusal = $this->promotionScopeRefusal($voucher, 'admin.vouchers', 'voucher')) {
            return $refusal;
        }

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
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $voucher->update([
            // Re-derived rather than carried over: for a supervisor this can
            // only ever be their own branch, so an edit cannot be used to push
            // their voucher global or into someone else's branch. For the owner
            // it is whatever the form said.
            'branch_id' => $this->promotionBranchIdFor($request),
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

        // "Activate/Deactivate Vouchers", same LIMITED rule as editing one:
        // switching a company-wide promotion off is a company-wide act.
        if ($refusal = $this->promotionScopeRefusal($voucher, 'admin.vouchers', 'voucher')) {
            return $refusal;
        }

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
            ->updateOrInsert(
                ['key' => 'game_enabled', 'branch_id' => null],
                ['value' => $new, 'group' => 'business', 'label' => 'Spin & Win Enabled', 'type' => 'text']
            );

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

        // Render the shared receipt template in its admin-scoped mode: the
        // customer navbar, mobile bottom nav and pickup-contact block are
        // dropped and the "Back" link points at Order History, so an admin
        // printing an order never lands on customer-facing chrome.
        return view('customer.receipt', ['order' => $order, 'isAdminView' => true]);
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

    public function selectBranch(string $branch)
    {
        // 'all', or the id of a branch that actually exists (closed ones
        // included — an admin manages a closed branch's menu from here). Any
        // other value is ignored and the current view is kept: this is a
        // view-only filter on the admin's own session, not a place to surface
        // input errors.
        if ($branch !== 'all' && ! \App\Models\Branch::whereKey($branch)->exists()) {
            return redirect()->back();
        }

        session()->put('selected_branch_id', $branch === 'all' ? 'all' : (int) $branch);

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
        // Own branch + global for a supervisor, everything for the owner.
        // Same rule and same reasoning as the voucher listing.
        $ads = $this->scopePromotionListing(\App\Models\Ad::with('branch'))
            ->orderBy('display_order')
            ->orderBy('created_at', 'desc')
            ->get();

        $branches = \App\Services\AdminOrderAccess::lockedBranchId() === null
            ? \App\Models\Branch::orderBy('id')->get()
            : collect();

        return view('admin.ads', compact('ads', 'branches'));
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
            'branch_id' => 'nullable|exists:branches,id',
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
            'branch_id' => $this->promotionBranchIdFor($request),
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

        // "Manage Advertisements" is the whole CRUD for a supervisor, but only
        // within their own branch. A company-wide ad is the owner's.
        if ($refusal = $this->promotionScopeRefusal($ad, 'admin.ads', 'ad')) {
            return $refusal;
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'link' => 'nullable|url|max:500',
            'placement' => 'required|in:game,menu,cart,orders',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'branch_id' => 'nullable|exists:branches,id',
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

        // See updateVoucher(): re-derived, so a supervisor's edit cannot move
        // the ad out of their own branch.
        $ad->branch_id = $this->promotionBranchIdFor($request);
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

        if ($refusal = $this->promotionScopeRefusal($ad, 'admin.ads', 'ad')) {
            return $refusal;
        }

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

        /*
         * Deleting an ad is part of "Manage Advertisements" (Y | Y | N) rather
         * than a separate owner-only row, so a supervisor keeps it — bounded by
         * the same branch rule as every other write on this record. Deleting
         * VOUCHERS stays Y | N | N and is unchanged: that route never leaves
         * the owner-only group, and nothing here touches it.
         */
        if ($refusal = $this->promotionScopeRefusal($ad, 'admin.ads', 'ad')) {
            return $refusal;
        }

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

        // Same normalisation as showSummary(), through the same
        // resolveSummaryPeriod(), so the two can never resolve a request to
        // different windows — including when the range is rubbish. A CSV has
        // nowhere to render a notice, but it does not need one: the "Period"
        // line it already writes into its own header block states the window
        // that was actually reported on, so a file that fell back still says
        // what it is a report of.
        $period = $request->input('period', 'custom');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

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
            // Branch name and account name are both user-entered free text
            // (storeBranch / storeUser), so they go through the same
            // formula-injection guard as every data row below — see
            // App\Support\Csv. This header block was the one place in this
            // export that had been missed.
            fputcsv($file, ['Branch', \App\Support\Csv::cell($branchName)]);
            fputcsv($file, ['Period', $start->format('M d, Y') . ' - ' . $end->format('M d, Y')]);
            fputcsv($file, ['Basis', 'Completed orders, by completion date']);
            fputcsv($file, ['Generated', now()->format('M d, Y g:i A')]);
            fputcsv($file, ['Generated by', \App\Support\Csv::cell(optional(auth('admin')->user())->name ?? 'Unknown user')]);
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
                    // Menu item names ride along inside the Items cell, and a
                    // table label is whatever the admin typed, so both pass the
                    // formula-injection guard. See App\Support\Csv.
                    $row['table_number'] !== null ? \App\Support\Csv::cell($row['table_number']) : '-',
                    \App\Support\Csv::cell($row['items']),
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

    /**
     * Refuse a staff-management action the current user may not take on
     * $target, or null to proceed.
     *
     * The ONE place the matrix's LIMITED rule for staff management is turned
     * into a response, shared by updateUser(), updateStaffPassword(),
     * toggleUser() and destroyUser() so those four cannot drift apart. The
     * rule itself lives in User::canManageAccount(); this only decides what a
     * refusal looks like.
     *
     * A refusal is deliberately INDISTINGUISHABLE from "no such account": both
     * a missing id and another branch's staff member come back as the same
     * flash on the same page. A supervisor probing ids must not be able to
     * read the difference and map out the other branches' rosters — the same
     * argument AdminOrderAccess makes for answering 404 rather than 403.
     */
    private function denyUnlessManageable(?\App\Models\User $target)
    {
        $me = Auth::guard('admin')->user();

        if ($me && $me->canManageAccount($target)) {
            return null;
        }

        return redirect()->route('admin.users')
            ->with('error', 'That account is not available for you to manage.');
    }

    /**
     * The portal accounts the current user may manage, as a query.
     *
     * The list and the per-row endpoints have to agree about who is visible,
     * or the screen shows rows whose buttons 404 — so both are derived from
     * the same two constants that canManageAccount() checks.
     */
    private function manageableUsersQuery()
    {
        $me = Auth::guard('admin')->user();

        // The owner sees every manageable portal account, in every branch.
        // ADMIN_MANAGEABLE_ROLES never contains 'admin', so the owner's own
        // account can never be listed, let alone acted on.
        if ($me && $me->isAdmin()) {
            return \App\Models\User::whereIn('role', \App\Models\User::ADMIN_MANAGEABLE_ROLES);
        }

        /*
         * A supervisor sees the staff of their OWN branch and nothing else —
         * not peer supervisors, not the owner, not another branch's staff.
         *
         * lockedBranchId() is the same branch value the rest of their portal
         * uses, and it fails closed at 0 for a branchless supervisor, so this
         * degrades to an empty list rather than to Main Branch's roster.
         */
        $branchId = \App\Services\AdminOrderAccess::lockedBranchId();

        return \App\Models\User::whereIn('role', \App\Models\User::MANAGER_MANAGEABLE_ROLES)
            ->where('branch_id', $branchId ?? 0);
    }

    public function showUsers()
    {
        // Ordered by role first so the two tiers read as two blocks rather
        // than interleaved. What the query CONTAINS is decided by
        // manageableUsersQuery() — the owner gets staff AND supervisors across
        // every branch, a supervisor gets their own branch's staff only.
        $staff = $this->manageableUsersQuery()
            ->with('branch')
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        $me = Auth::guard('admin')->user();

        /*
         * The branch choices offered by the create/edit form.
         *
         * A supervisor gets exactly their own branch. Their accounts are
         * forced to it server-side by storeUser()/updateUser() regardless of
         * what is posted, so this is the form agreeing with the enforcement
         * rather than being the enforcement — but offering a branch that will
         * be silently overridden would be a worse form than offering one.
         */
        $branches = \App\Models\Branch::where('is_active', true)
            ->when(
                $me && ! $me->isAdmin(),
                fn ($q) => $q->where('id', \App\Services\AdminOrderAccess::lockedBranchId() ?? 0)
            )
            ->orderBy('id')
            ->get();

        // The role dropdown's options come from the SAME method the validator
        // uses, so the form cannot offer a role the server would refuse — a
        // supervisor is offered 'staff' alone.
        $assignableRoles = $this->assignableRoles();

        return view('admin.users', compact('staff', 'branches', 'assignableRoles'));
    }

    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|max:255|unique:users,email',
            // A branch is REQUIRED for both roles this endpoint can create.
            // Both are branch-locked, and an account with no branch is not a
            // wider account, it is a broken one — see the deny-by-default arm
            // of AdminOrderAccess::lockedBranchId().
            'branch_id' => 'required|exists:branches,id',
            /*
             * The role, constrained by Rule::in(ADMIN_MANAGEABLE_ROLES).
             *
             * This used to be hard-coded 'staff', with the comment "never
             * anything else". The guarantee that mattered in that comment was
             * never "staff specifically" — it was "NEVER admin", because this
             * form is how a portal account is minted and an admin account
             * minted here would be a privilege-escalation route straight past
             * the owner. That guarantee is now enforced by the validator
             * against a constant which cannot contain 'admin', instead of by a
             * literal, so an unexpected or absent value is a 422 rather than a
             * silent grant. Compare MassAssignmentEscalationTest, which makes
             * the same argument about `role` reaching User::create() from
             * request input — note the create() call below still names every
             * column explicitly and never spreads $request->all().
             */
            'role'      => ['required', \Illuminate\Validation\Rule::in($this->assignableRoles())],
            // Security review 2026-08-31: staff accounts get the same shared
            // complexity policy as every other password-setting flow.
            'password'  => \App\Support\PasswordPolicy::required(),
        ], [
            'role.required' => 'Please choose a role for this account.',
            'role.in'       => 'That is not a role you can assign here.',
        ]);

        /*
         * THE SUPERVISOR OVERRIDE — the escalation this endpoint has to stop.
         *
         * "Create Staff Accounts" is Y for a manager, but "Change Staff Role"
         * is Y | N | N and a supervisor is branch-locked. Left as the
         * validated input alone, a supervisor admitted to this endpoint could
         * post role=supervisor and branch_id=<some other branch> and mint
         * themselves a peer with a foothold in a branch they cannot even read
         * — defeating both the role tier and the branch lock in one request.
         *
         * assignableRoles() already narrows the VALIDATOR to ['staff'] for a
         * supervisor, so a posted role=supervisor is a 422. The branch is
         * FORCED rather than validated: the form only ever offers their own
         * branch, so overriding a posted one silently is correcting a request
         * that could not have come from the real form anyway.
         *
         * The owner is untouched by both lines — isAdmin() short-circuits
         * assignableRoles(), and lockedBranchId() is null for them.
         */
        $branchId = \App\Services\AdminOrderAccess::lockedBranchId() ?? $validated['branch_id'];

        \App\Models\User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => $validated['password'],  // hashed by the model cast
            'branch_id' => $branchId,               // forced to own branch for a manager
            'role'      => $validated['role'],      // validated above; never admin
            'is_active' => true,
        ]);

        return redirect()->route('admin.users')
            ->with('success', ucfirst($validated['role']) . ' account created for ' . $validated['name'] . '.');
    }

    /**
     * The roles the CURRENT user may assign when creating or editing an
     * account.
     *
     *  - the OWNER may assign either manageable role (staff or supervisor),
     *    and never admin — ADMIN_MANAGEABLE_ROLES has never contained it.
     *  - a SUPERVISOR may assign `staff` and nothing else. "Change Staff Role"
     *    is Y | N | N, so a supervisor has no say in what tier an account
     *    sits at; they may only create accounts at the tier below their own.
     *
     * Returned as a list for Rule::in(), so an unexpected value is a 422 at
     * the validator rather than a silent grant further down. The same list
     * drives the role dropdown in admin.users, so the form and the enforcement
     * are the same statement made twice rather than two statements.
     */
    private function assignableRoles(): array
    {
        $me = Auth::guard('admin')->user();

        return ($me && $me->isAdmin())
            ? \App\Models\User::ADMIN_MANAGEABLE_ROLES
            : \App\Models\User::MANAGER_MANAGEABLE_ROLES;
    }

    /**
     * Edit an existing portal account's details.
     *
     * "Edit Staff Information" is Y | Y | N. A supervisor reaches this only
     * for a `staff` account in their own branch (canManageAccount), and even
     * then may not change its ROLE or move it to another BRANCH — those two
     * fields are the escalation surface, and they are the two the matrix keeps
     * at Y | N | N as "Change Staff Role" / branch management.
     *
     * The password is deliberately NOT editable here. Setting one has its own
     * endpoint, its own throttle and its own policy rule; folding it in would
     * put a password write behind a form that is otherwise harmless.
     */
    public function updateUser(Request $request, $id)
    {
        $user = \App\Models\User::find($id);

        if ($stop = $this->denyUnlessManageable($user)) {
            return $stop;
        }

        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            // Unique EXCEPT against this row, or saving an unchanged email
            // would fail its own uniqueness check.
            'email'          => [
                'required', 'email', 'max:255',
                \Illuminate\Validation\Rule::unique('users', 'email')->ignore($user->id),
            ],
            'contact_number' => 'nullable|string|max:30',
            'branch_id'      => 'required|exists:branches,id',
            'role'           => ['required', \Illuminate\Validation\Rule::in($this->assignableRoles())],
        ], [
            'role.in' => 'That is not a role you can assign here.',
        ]);

        // Same forced branch as storeUser(), for the same reason: a manager
        // cannot move an account out of their own branch, which would be both
        // a branch-lock bypass and a way to hide the account from themselves.
        $branchId = \App\Services\AdminOrderAccess::lockedBranchId() ?? $validated['branch_id'];

        $user->forceFill([
            'name'           => $validated['name'],
            'email'          => $validated['email'],
            'contact_number' => $validated['contact_number'] ?? null,
            'branch_id'      => $branchId,
            'role'           => $validated['role'],
        ])->save();

        return redirect()->route('admin.users')
            ->with('success', 'Account details updated for ' . $user->name . '.');
    }

    /**
     * Delete a portal account outright — the matrix's LIMITED row.
     *
     * WHO may call is the route's `role:admin,supervisor`. WHICH ACCOUNT is
     * canManageAccount(), which for a supervisor means a `staff` row in their
     * own branch: never a peer supervisor, never the owner, and never another
     * branch's staff even when that row's id is typed straight into the URL.
     *
     * Routed through safelyDelete() like every other destructive action in
     * this controller, because a portal account is referenced by orders and
     * stock_movements — an account that has done a day's work will be refused
     * by a foreign key rather than taking its history with it, and the caller
     * is told to deactivate it instead of being shown a 500.
     */
    public function destroyUser($id)
    {
        $user = \App\Models\User::find($id);

        if ($stop = $this->denyUnlessManageable($user)) {
            return $stop;
        }

        return $this->safelyDelete(
            function () use ($user) {
                $name = $user->name;
                $user->delete();

                return redirect()->route('admin.users')
                    ->with('success', 'Account for ' . $name . ' has been deleted.');
            },
            'admin.users',
            'the account for ' . $user->name,
            'It is still attached to orders or stock records. Deactivate it instead.'
        );
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
        $user = \App\Models\User::find($id);

        /*
         * Never an admin, never yourself — and, since the matrix widened this
         * endpoint to the manager tier, never a target outside what the CALLER
         * may manage.
         *
         * The role group is `role:admin,supervisor`, so this is no longer only
         * about blast radius: for a supervisor it is the privilege boundary
         * itself. Resetting a password IS taking over an account, so without
         * this a supervisor admitted to the endpoint could take over a peer
         * supervisor's login, or any other branch's staff, by id. The owner's
         * original guarantee is unchanged and now stated in one place —
         * canManageAccount() excludes admin for everyone.
         */
        if ($stop = $this->denyUnlessManageable($user)) {
            return $stop;
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
        $user = \App\Models\User::find($id);

        // Never an admin, never yourself, and — for a supervisor — never
        // anything but a staff account in their own branch. Deactivating an
        // account is locking a colleague out of the portal mid-shift, which
        // is why it gets the same target rule as deleting one rather than a
        // looser check of its own.
        if ($stop = $this->denyUnlessManageable($user)) {
            return $stop;
        }

        $user->is_active = !$user->is_active;
        $user->save();

        return redirect()->route('admin.users')
            ->with('success', $user->name . ' has been ' . ($user->is_active ? 'reactivated' : 'deactivated') . '.');
    }
}
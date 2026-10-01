# Analytics Methodology

This document explains the statistical and mathematical tools used by the
Analytics page (`/admin/analytics`) and the reports around it.

> **Important wording note.**
> The system uses **descriptive analytics** and **rule-based thresholds**.
> It does **not** use machine learning or predictive AI. The phrase "AI"
> is not used anywhere in the UI unless an actual machine-learning model is
> integrated.

> **Scope note (kept accurate deliberately).**
> §1–§6 describe what the Analytics page **actually renders today**.
> §9 describes calculations that exist in `AnalyticsService` but are **not
> currently rendered on any page**. That separation is the point of this
> document: an earlier revision described a dashboard of recommendation
> cards, a branch-performance table, a sales-by-category breakdown and a
> forecast chart as though they were on screen. None of them are. They were
> removed from the page in the Sept 2026 redesign; the service methods
> behind them were left in place for a later phase. Anything in §9 should be
> read as "available, not live".

---

## 1. What the Analytics page shows

The live page has exactly three sections, plus a date-range filter and a
Print button.

| Section | What it is | Source |
|---|---|---|
| KPI row — Total Sales | `SUM(orders.total)` for completed orders in range | `salesForRange()` |
| KPI row — Total Orders | `COUNT(*)` of completed orders in range | `ordersCountForRange()` |
| KPI row — Average Order Value | Total Sales ÷ Total Orders, `null` when no orders | `averageOrderValueForRange()` |
| KPI row — Average Rating | `AVG(order_ratings.rating)` in range, `null` when unrated | `averageRatingForRange()` |
| Sales per Day (bar chart) | One bar per calendar day in range | `dailySalesSeriesForRange()` |
| Top 5 Products | Top items by quantity sold in range | `topProductsForRange()` |

Everything is scoped to **completed orders**, dated by **`completed_at`**,
and filtered to the selected branch.

---

## 2. Data Sources

All analytics read from existing tables — no new columns were added.

| Purpose | Tables |
|---|---|
| Sales totals & the daily series | `orders` (`status = 'completed'`, `completed_at`) |
| Top products | `order_items` ⋈ `orders` ⋈ `menu_items` |
| Average rating | `order_ratings` ⋈ `orders` |
| Branch scope | `branches` (`id`, `is_active`) |

Branch scope is governed by the existing `getSelectedBranch()` helper
(`ResolvesBranchScope`): staff and supervisors are locked to their own
branch, and an admin can pick a branch or "All Branches". The scope is
**never read from the request**, so there is no querystring that widens it.

Note that `inventory` and `stock_movements` are *not* Analytics sources.
The inventory threshold rules in §5 belong to the Inventory page, its CSV
export and its printed report — not to this page.

---

## 3. Date range and its validation

The range selector offers **Today / Last 7 Days / Last 30 Days / This Month /
Custom Range**, resolved by `AdminController::resolveAnalyticsPeriod()`.
Summary (`/admin/summary`) has its own presets — Today / This Week / This
Month / Custom — resolved by `resolveSummaryPeriod()`.

Both resolvers validate a custom range through the **one** shared rule in
`App\Http\Controllers\Concerns\ResolvesReportDateRange`, which is also used
by `/admin/analytics/print` and the sales CSV at `/admin/export/orders`.
A date is accepted only if it is exactly `YYYY-MM-DD`, spells itself back
out identically after parsing, and names a year between 2000 and 2100.

| Input | Behaviour |
|---|---|
| Valid range | Used as given |
| Start after end | **Swapped**, with a notice — an unambiguous slip, not worth refusing |
| Unparseable / impossible / `0000-00-00` / out of bounds | **Discarded**; the endpoint falls back to its default preset and shows a notice |
| One bound blank | Falls back **silently** — someone is still filling the form in |

The round-trip check is what rejects dates that *parse* but are not the date
written: `Carbon::parse('0000-00-00')` yields year −1, and
`createFromFormat('Y-m-d', '2026-02-31')` rolls forward to March 3. Neither
throws, so a `try`/`catch` alone would let both through.

---

## 4. Aggregation Functions

Standard SQL aggregations, exposed as Eloquent / Query Builder calls in
`app/Services/AnalyticsService.php`:

- `SUM(orders.total)` — revenue per period and per day.
- `COUNT(*)` — completed orders in the period.
- `SUM(order_items.quantity)` — units sold per menu item.
- `AVG(order_ratings.rating)` — average rating in the period.
- `GROUP BY menu_item_id`, `GROUP BY DATE(completed_at)` — the two groupings
  the live page uses.

### 4.1 The daily series is one query, not one per day

`dailySalesSeriesForRange()` aggregates in SQL and zero-fills in PHP:

```sql
SELECT SUM(total) AS day_total, DATE(completed_at) AS day
FROM orders
WHERE status = 'completed'
  AND completed_at BETWEEN :start_of_first_day AND :end_of_last_day
  -- optional: AND branch_id = :branch
GROUP BY DATE(completed_at);
```

The grouped query returns only days that had sales; the PHP loop then walks
every calendar day in the range so an empty day still gets its labelled zero
bar.

This replaced a loop that ran `whereDate(...)->sum()` **once per calendar
day**. Measured before the change: 39 queries for a 30-day range, 374 for a
year, and **4,289 for an 11-year range** — one page view. Combined with the
unvalidated `0000-00-00` described in §3, which spanned 740,277 days, a
single querystring was a denial-of-service rather than an error.

Two details make the rewrite exactly equivalent rather than approximately so:

- the range is bounded by `startOfDay()`/`endOfDay()` of the first and last
  days, because the old `whereDate()` matched a whole calendar day whatever
  time the caller's `$start` carried;
- `AnalyticsDailySeriesParityTest` keeps a verbatim copy of the deleted
  per-day loop and asserts the two produce identical output, so the
  equivalence is re-proved on every test run rather than asserted in a
  comment.

### 4.2 Supporting index

`orders(branch_id, status, completed_at)` — equality columns first, range
column last, so MySQL can use the whole index. See the migration
`2026_09_20_000000_add_reporting_index_to_orders_table.php` for the full
reasoning, the measured `EXPLAIN` before/after, and what it deliberately
does **not** fix (the `GROUP BY DATE(...)` still needs a temporary table and
filesort, because grouping on a *function of* a column cannot use index
ordering).

---

## 5. Inventory threshold rules

**Not on the Analytics page.** These are the rules the Inventory page, its
CSV export (`/admin/inventory/export`) and its printed report
(`/admin/inventory/print`) share. They are documented here because they are
the system's threshold definitions and there is nowhere better; all three
surfaces compute them from the same two columns, evaluated **out before
low**, so a row lands in exactly one bucket.

### 5.1 Out-of-stock rule

```
quantity <= 0
```

### 5.2 Low-stock rule

```
quantity > 0  AND  quantity <= low_stock_alert
```

(`low_stock_alert` is a per-item threshold configured on the inventory
record.) A row with `quantity = 0` and `low_stock_alert = 10` satisfies both
conditions as written; evaluating out-of-stock first is what makes the
buckets exclusive.

### 5.3 In-stock

Everything else.

All three surfaces also share one row scope —
`AdminController::inventoryRowsForScope()`, which applies
`Inventory::notArchived()`. Archiving an item removes it from the page, the
CSV and the printed report together.

---

## 6. Percentage Change Formula

Used by Summary for its period-over-period badge (Today vs Yesterday, This
Week vs Last Week, This Month vs Last Month, and a custom range vs the same
number of days immediately before it).

```
delta_percent = ((current - previous) / previous) × 100
```

Implementation: `AnalyticsService::percentChange()`. Returns `null` when
`previous = 0` (undefined growth — division-by-zero avoidance). Values are
rounded to one decimal place.

Net is compared against net. Comparing pre-discount figures would let a
heavily discounted period read as growth it never banked.

---

## 7. Limitations & Honesty Statement

- This is a **descriptive** analytics layer. It is not machine learning.
- Trends are computed by **comparing fixed time windows** with the
  percent-change formula in §6.
- All thresholds are configurable constants in `AnalyticsService` — they are
  tuned for café scale, not derived from training data.
- The "All Branches" scope does not filter `branch_id`, so it cannot use the
  index in §4.2 and still scans the table. Acceptable at current table size;
  worth revisiting when it is not.
- Possible Capstone-2 future enhancements (require schema changes — keep for
  after a DB backup):
  - `lead_time_days` and `safety_stock` columns on `inventory` to enable the
    formal Reorder Point formula:
    `ROP = (Avg Daily Usage × Lead Time) + Safety Stock`.
  - Inventory turnover ratio per item: `COGS_period ÷ Average Inventory Value`.
  - ABC / Pareto classification of menu items (top 20 % contributing 80 % of
    sales).
  - A stored/generated date column on `orders` to remove the daily series'
    remaining filesort.

---

## 8. Files Involved

- `app/Services/AnalyticsService.php` — all read-only queries.
- `app/Services/DemandForecastService.php` — the moving-average demand
  forecast, day-of-week analysis and menu-level forecast (§10).
- `app/Services/ProductionCapacityService.php` — the live capacity snapshot.
- `tests/Feature/DemandForecastTest.php` — the forecasting guarantees,
  including that insufficient data never renders as ₱0.
- `app/Http/Controllers/Admin/AdminController.php` — `showAnalytics()`,
  `printAnalytics()`, `showSummary()`, `exportOrders()`, and the two period
  resolvers.
- `app/Http/Controllers/Concerns/ResolvesReportDateRange.php` — the one
  custom-range validation rule shared by all four report endpoints (§3).
- `app/Http/Controllers/Concerns/ResolvesBranchScope.php` — the one branch
  scope rule.
- `resources/views/admin/analytics.blade.php` — the dashboard.
- `resources/views/admin/analytics-print.blade.php` — the printed report.
- `resources/views/admin/partials/date-range-notice.blade.php` — the shared
  "that range was not usable" notice.
- `database/migrations/2026_09_20_000000_add_reporting_index_to_orders_table.php`
- `routes/web.php` — the `role:admin,supervisor` reports group.
- `docs/ANALYTICS_METHODOLOGY.md` — this document.

No database migrations beyond the index in §4.2 were introduced for this
feature.

---

## 9. Implemented but NOT currently rendered

Everything below exists in `AnalyticsService` and is reachable from PHP, but
**no live controller or view calls it**. It was built for the pre-Sept-2026
Analytics dashboard and kept for a later phase. It is documented so the
methodology is not lost, and flagged so nobody looks for it on screen.

Verified by grep at the time of writing: `recommendations()`,
`salesForecast()`, `branchPerformance()`, `salesPerBranch()`,
`salesByCategory()`, `slowMovers()`, `inventoryLinkedToBestSellers()` and
`dailyTrend()` have no caller outside the service itself (`dailyTrend()` is
referenced only by a test fixture).

Re-verified in Phase 2c for `salesForecast()` specifically: still no caller.
Its two constants ARE live, however — `DemoSalesTopUp` and `DemoSalesSeeder`
measure themselves against `FORECAST_MIN_DAYS_WITH_SALES`, and §10's moving
average now aliases it as its own sufficiency threshold. The method was
therefore left intact rather than deleted: nothing calls it, its methodology
below is worth keeping, and removing it would have gained nothing.

### 9.1 Best / least sellers (30-day window)

`bestSellers()` / `leastSellers()` are still used — but by the **Summary**
page's Top/Least Selling Items widget, not by Analytics.

```sql
SELECT menu_item_id, SUM(quantity) AS total_qty
FROM order_items
JOIN orders ON order_items.order_id = orders.id
WHERE orders.status = 'completed'
  AND orders.completed_at >= NOW() - INTERVAL 30 DAY
  -- optional: AND orders.branch_id = :branch
GROUP BY menu_item_id
ORDER BY total_qty DESC   -- ASC for least sellers
LIMIT 5;
```

Least-seller queries additionally filter `menu_items.is_available = TRUE` so
retired menu items don't pollute the list.

### 9.2 Slow-moving / dead stock rule

```
quantity > 0
AND inventory.id NOT IN (
    SELECT inventory_id FROM stock_movements
    WHERE created_at >= NOW() - INTERVAL 30 DAY
)
```

In words: the item has stock on hand but no `in`/`out` movement recorded in
the last 30 days.

### 9.3 Branch performance rule

```
branch_avg = AVG(sales_30d_per_branch)
```

A branch is flagged a "stronger sales" leader when
`branch.sales_30d > 1.5 × branch_avg`.

### 9.4 Rule-based recommendation logic

`recommendations()` returns an array of cards shaped
`['level' => 'critical'|'warning'|'success'|'info', 'icon' => …, 'title' => …,
'message' => …]`, in this order:

1. Out-of-stock for every inventory item where `quantity <= 0`.
2. Low-stock for every item where `0 < quantity <= low_stock_alert`.
3. Linked-to-best-seller escalation — rules 1 and 2 upgrade to *critical*
   when the item links (via `menu_items.inventory_item_id`) to a top-selling
   menu item.
4. Slow / dead stock, per §9.2 (first 5).
5. Best sellers — top 3 by 30-day quantity sold.
6. Least sellers — bottom 3 available menu items by 30-day quantity sold.
7. Today vs Yesterday warning when the drop ≥ 20 %.
8. This Week vs Last Week warning when the drop ≥ 15 %.
9. Branch leader (All Branches scope only), per §9.3.

### 9.5 Sales forecasting (Simple Linear Regression) — DORMANT, NOT THE LIVE METHOD

> **This section documents unused code.** The live Analytics forecast is the
> flat trailing 7-day moving average in §10 (`DemandForecastService`).
> `salesForecast()` has no caller and is not rendered anywhere.

`salesForecast(int $lookbackDays = 30, int $forecastDays = 7)`.

Simple Linear Regression fits the straight line that best matches a series of
past points, then extends that line forward. Here the points are each day's
total sales for completed orders.

```
x = sequential day index within the lookback window (0, 1, 2, … n-1)
y = that day's total sales (completed orders only)

b (slope)     = (nΣxy - ΣxΣy) / (nΣx² - (Σx)²)
a (intercept) = (Σy - bΣx) / n

forecast(x) = a + b·x
```

Derived outputs:

- **Trend label** (`increasing` / `decreasing` / `flat`) from the sign of `b`,
  with a relative epsilon (≈0.5 % of the window's average daily sales) around
  zero so ordinary noise doesn't flip the label. Relative rather than a fixed
  peso amount so the rule works whether a branch does ₱2,000/day or
  ₱50,000/day.
- **Low-sales alert** — `true` when tomorrow's forecast is more than 20 %
  below the trailing 7-day *actual* average.
- **Insufficient-data guard** — fewer than 5 days with any completed orders in
  the lookback window returns `insufficient_data: true` instead of a forecast.

Why SLR was originally chosen (historical rationale; superseded by §10): explainable by hand to a non-technical panel, no ML infrastructure,
and the smallest step up from descriptive that still fits the rest of this
codebase.

Limitations: assumes a linear trend (no seasonality or weekday/weekend
cycles); sensitive to outliers; unstable on sparse history — which is why the
insufficient-data guard exists; and it estimates aggregate peso sales, not
per-item demand, so it does not replace the inventory thresholds in §5.

Note that `salesForecast()` and `dailyTrend()` both still use a per-day loop.
Unlike the daily series in §4.1 this is **not** a scaling problem: their
windows are fixed (30 days and 14 days), so the query count is constant and
does not track any user-supplied range. They were deliberately left alone.

---

## 10. Demand forecasting — Moving Average (Phase 2c)

The user-facing forecast on the Analytics page. §9.5's Simple Linear
Regression remains in the codebase and remains **dormant**; it is not rendered
anywhere, and it must not be shown alongside this one — two forecasts on one
page is two different numbers with nothing to choose between them.

Implemented in `app/Services/DemandForecastService.php`.

### 10.1 Why a moving average

The owner has to be able to check the number by hand. A moving average is one
division; a regression is not. The dataset also does not support anything
more: Phase 1 measured Main Branch at 11 days with sales in the recent 30, and
Branches 1–3 at none at all. Fitting a trend line to that mostly models noise
while sounding authoritative.

```
forecast = (sum of the recent daily observations) / (number of observations)
```

Worked example — observations 150, 170, 190, 200:

```
(150 + 170 + 190 + 200) / 4 = 177.5
```

### 10.2 The observation window

**7 calendar days**, taken from the END of the selected historical range.

Seven because the window is then exactly one week, so every weekday
contributes exactly once. A window that is not a whole number of weeks
silently weights whichever weekdays happen to fall at the end of the range — a
5-day window ending on a Sunday carries two weekend days out of five and reads
high for a café. Seven also matches the forecast horizon, so "the last week"
predicts "the next week".

Not longer, because with ~11 selling days in 30 a 14- or 28-day window would
mostly average in stretches with no trading and drag every forecast toward
zero without being better evidenced.

A range narrower than 7 days uses every day it has and reports how many in
`observations_used`. It never pads itself out with days that did not happen.

### 10.3 How days are treated

| Case | Treatment |
| --- | --- |
| A day with completed sales | its total, as an observation |
| A day with **no** sales inside the range | a real `0.0` observation |
| A calendar day outside the range | does not exist; never invented |

Zero-sales days are **included**. The forecast answers "how much per *day*",
so it must divide by every calendar day. Averaging only the days that happened
to sell answers the different question "how much on a day that sells", and
runs high every time there is a quiet day. This follows the same zero-filling
`dailySalesSeriesForRange()` already does for the chart (§4.1).

### 10.4 Forecast window

- **Next-day forecast** — the moving average itself.
- **Next-7-day forecast** — seven days, each carrying that same value; the KPI
  shows their total as *Projected Next 7 Days*.

Every projected day is the same figure because a moving average has **no trend
term**. That flatness is the honest shape of the method. A slope here would be
a claim the arithmetic does not make.

The forecast period starts the day **after the selected range ends**, not
after *today*, so the historical and forecast periods always meet exactly once
and never overlap — including when the owner is looking at a past range.

### 10.5 Data sufficiency — the load-bearing rule

**Insufficient is not zero.** `₱0` asserts that demand is expected to be
nothing; *insufficient* says the evidence cannot support any expectation at
all. The service returns `sufficient => false` with `next_day` and
`total_next_7_days` set to `null` — never `0.0` — and the UI prints
"Insufficient Data", never a peso figure.

This matters beyond this phase. Phase 2d combines forecast with capacity and
inventory into shortage risk, where a fabricated `₱0` would read as "no
shortage possible" for exactly the branches nothing is known about.

Three refusal reasons:

| Reason | Meaning |
| --- | --- |
| `no_history` | no completed sales at all in the range |
| `too_few_days` | fewer than 5 days with sales in the range |
| `stale_history` | enough selling days overall, but none inside the 7-day window |

`stale_history` exists because without it the arithmetic would average seven
consecutive empty days and return a perfectly confident `0.00`. A week with no
trading at all is far likelier to mean the shop was shut, or that sales
stopped being recorded, than that demand has gone to nothing.

The threshold is **5 days with sales**, and it is not a new number:
`DemandForecastService::MIN_DAYS_WITH_SALES` aliases
`AnalyticsService::FORECAST_MIN_DAYS_WITH_SALES`, the bar §9.5 has always
used and the one `DemoSalesTopUp` and `DemoSalesSeeder` already measure
themselves against. `MENU_MIN_DAYS_WITH_SALES` aliases it again, so "enough
data" means one thing across the module. There is no second literal anywhere.

### 10.6 Day-of-week analysis

`byDayOfWeek()` groups the same daily series by weekday; `forWeekday()`
returns one weekday's row, or `null` when that weekday never occurred.

**Descriptive evidence, not a seasonal model.** Wording is always "historical
Sunday sales have averaged ₱X across N Sundays" and never "Sunday will be
higher". With a handful of observations per weekday, a gap between two
weekdays is as likely to be which weeks happened to be busy as it is to be
anything about the weekday. Nothing here feeds back into the forecast: the
projection stays flat and this table sits beside it as context.

Each row carries `observations` (how many of that weekday the range contained)
and `days_with_sales` alongside `average`, so the thinness of the evidence is
on screen rather than something the reader has to take on trust. Every
occurrence counts toward the average, including days that took nothing. Only
weekdays the range actually contains are listed.

### 10.7 Menu-level demand forecast

`forMenuItems()` — the same moving average per item, in **units per day**.

Quantities rather than pesos, because Phase 2d joins this to production
capacity and inventory, both denominated in units.

- Items that **never sold** in the range are **not listed at all**. A zero row
  would assert there is no demand, when the item may simply be new, seasonal,
  or added last week.
- Items that sold but on fewer than 5 days are listed with
  `sufficient => false` and "Insufficient historical data for menu-level
  forecasting." — for those, "not enough yet" is the honest answer rather than
  silence.
- The item name comes from `order_items.item_name`, the order line's own
  snapshot, so a renamed or deleted item still reports under the name it sold
  as — the convention the receipt and the sales export already follow.

### 10.8 Branch scope

Scope comes from `ResolvesBranchScope::getSelectedBranch()` and nowhere else;
the service never reads a request, so there is no branch parameter for a
branch-locked supervisor to tamper with. One branch id scopes to that branch;
`'all'` is the existing consolidated behaviour. Branches with no usable
history show the insufficient state, not a zero forecast. No branch-comparison
widget was added — §9.3's Branch Performance and Sales per Branch stay
removed.

### 10.9 Performance

The sales forecast and the weekday analysis are **pure arithmetic** over the
daily series the page has already fetched for the chart, and cost **zero**
additional queries. The menu-level forecast adds exactly **one** grouped query
(`GROUP BY menu_item_id, DATE(completed_at)`) covering every item and every
day at once, pivoted in PHP — no per-day loop, no per-item N+1.

Measured on `/admin/analytics`: **17–19 queries**, and **16–18** on the print
view, flat from a 1-day range to an 11-year one. The page budget asserted by
`AnalyticsDailySeriesParityTest` (24) was not raised.

### 10.10 The chart

One graph, `Sales Trend & Forecast` — still the page's only one. Historical
actuals are a **solid** line; the projection continues it as a **dashed** line
over the forecast dates. When the forecast is insufficient the forecast
dataset is an empty array and no second line is built at all; it is never
filled with zeroes to square the chart off into next week.

### 10.11 Honesty constraints

No confidence percentages, accuracy percentages or prediction probabilities
are displayed, because none are calculated — the project has no validated
statistical method to back such a number. Output is labelled *Forecast*,
*Estimated* or *Projected*, and the page states that it is a projection from
past sales and not a guaranteed figure. `DemandForecastTest` asserts the
absence of those claims on the rendered page.

### 10.12 Limitations

- No trend and no seasonality: the projection is flat by construction.
- Sensitive to a single outlier day inside a 7-day window.
- Weekday averages rest on very few observations each and are descriptive only.
- Forecasts aggregate peso sales and per-item units; it does not forecast
  COGS, gross profit, or shortage risk. Phase 2d combines demand with capacity
  and costing.

---

## 11. Phase 2d — Inventory Intelligence, Risk & the Analytics CSV

Phase 2d adds no new calculation. It **joins** three results that already
existed — `ProductionCapacityService::forBranchScope()`,
`DemandForecastService::forMenuItems()` and
`ProfitCalculationService::forRange()` — in
`App\Services\AnalyticsIntelligenceService`, and renders that one result three
ways: the screen, the print sheet and a CSV export. No revenue, forecast,
capacity or bottleneck is recomputed anywhere in the phase.

The forecasting method is **unchanged**: still the Phase 2c moving average
described in §10, still flat, still refusing to forecast where the history
cannot support one. Nothing in this phase feeds back into it — the weekday
table remains descriptive context beside the forecast, not an input to it.

### 11.1 The three formulas

```
Potential Shortage = forecast demand over the horizon - current capacity
                     ONLY when that difference is positive

Days of Coverage   = current production capacity / average daily demand
                     (the item's moving-average forecast, in units per day)

Ingredient Daily   = sum over the menu items whose recipe uses it of
Usage                (that item's forecast units/day x its required
                      quantity per order)
```

Capacity at or above the forecast is reported as the **absence** of a shortage
("None"), never as a shortage of 0 orders. The two statements mean different
things and a reader cannot tell them apart from a bare `0`.

Ingredient usage counts each menu item **once**: the walk is over one
deduplicated requirement map per item, so an ingredient shared by three items
sums three distinct demands rather than counting one of them three times.
Where only *some* of the items using an ingredient have a forecast, the figure
is a **floor** on real usage and is flagged `usage_is_partial`; where none do,
it is `null`, never `0`.

### 11.2 The risk ladder

Six states, evaluated strictly top to bottom, **first match wins**. Because the
ladder short-circuits, two conditions cannot produce contradictory statuses.

| # | State | Condition |
|---|-------|-----------|
| 1 | `UNAVAILABLE` | Capacity cannot be calculated — no measurable recipe/ingredient configuration, or a cross-branch ingredient. |
| 2 | `OUT OF STOCK` | `capacity <= 0`: a required ingredient's available quantity is at or below zero. |
| 3 | `INSUFFICIENT DATA` | Producible (`capacity > 0`) but no menu-level forecast, so demand-vs-capacity cannot be assessed. |
| 4 | `CRITICAL` | Producible and forecastable, coverage below `COVERAGE_CRITICAL_DAYS`. |
| 5 | `LOW` | Coverage below `COVERAGE_LOW_DAYS`, **or** capacity at/below the inventory low-stock threshold. |
| 6 | `GOOD` | Capacity covers the whole projected horizon and no low-stock condition applies. |

**OUT OF STOCK is checked before INSUFFICIENT DATA, deliberately.** An empty
shelf is a fact about today and does not depend on sales history. Phase 1
measured Branches 1-3 with no recent selling days at all; if this sat below
INSUFFICIENT DATA, a genuinely unproducible item in those branches would be
masked by the absence of a forecast.

### 11.3 The exact thresholds

- **`COVERAGE_CRITICAL_DAYS = 1.0` day.** Below one day of projected demand
  the item is expected to run out before the next trading day ends — where
  "restock soon" becomes "restock today". It is also the threshold the
  inventory insight is worded against.
- **`COVERAGE_LOW_DAYS = DemandForecastService::FORECAST_HORIZON_DAYS = 7`
  days.** Aliased, not invented: LOW means exactly "current capacity does not
  cover the period this page is projecting". That equivalence is what keeps
  the Risk and Potential Shortage columns from disagreeing:
  `shortage > 0` iff `7 x perDay > capacity` iff `capacity/perDay < 7`.
- **The low-stock floor reuses `config('inventory.low_stock_threshold')`** (3
  by default) — the same line the Menu Production Capacity table has always
  drawn. Without it, a slow-selling item with 2 units of capacity and 20 days
  of coverage would read "Low Stock" in one column and "GOOD" in the next on
  the same row. The floor can only raise GOOD to LOW; it never lowers a
  CRITICAL and is never consulted for the three states above it.

### 11.4 Affected menu items

The reverse of the recipe relationship, derived from the requirement map
`ProductionCapacityService` already walked — **zero additional queries**, and
structurally incapable of disagreeing with the capacity figures it comes from.
It is the real recipe link (`menu_item_ingredients`), never a guess. Branch
scope is inherited: a branch-scoped view whose recipe reached into another
branch was already refused upstream with an empty ingredient list.

### 11.5 Automated insights — what was reused from the dormant engine

`AnalyticsService::recommendations()` had never been wired to a screen. Its
rules were **triaged**, not switched on wholesale:

- **Reused** (re-expressed against Phase 2d's data): out-of-stock alert,
  low-stock alert, best-seller / strong performer, least-seller / low demand.
  The conditions were sound; what they read was not — live 30-day aggregates
  regardless of the range the owner had selected, so the card would contradict
  the table beside it.
- **Obsolete, not carried over**: the "no recent movement" slow-stock rule (a
  `stock_movements` question already served by the Inventory screen), and the
  today-vs-yesterday / week-vs-week drop rules (both ignore the selected date
  range and would state a comparison the page is not reporting on).
- **Branch-unsafe, deliberately left dormant**: the branch-leader rule, which
  ranks every branch's 30-day sales against the average. That is the
  cross-branch comparison the Phase 3 audit removed from this page; surfacing
  it even under `'all'` would put it one session variable away from a
  branch-locked supervisor.
- **New, impossible before this phase**: shortage, coverage, bottleneck,
  capacity, profitability and the weekday demand pattern.

Recommendation language is decision support — *Consider…*, *Monitor…*,
*Review…*. Nothing says "buy exactly N units" (the database records no
supplier, lead time or pack size that could support it) and nothing tells the
owner to withdraw a product.

### 11.6 Not artificial intelligence

There is no model here, trained or otherwise — no ML, no inference, no
probability. Every sentence the page emits can be reproduced by hand from a
figure in the table above it. The vocabulary used is *Predictive Analytics*,
*Automated Insights*, *Data-Driven Recommendation*, *Inventory Intelligence*
and *Decision Support*. `AnalyticsIntelligenceAndExportTest` asserts that
neither the screen, the print sheet nor the CSV claims otherwise.

### 11.7 Menu Performance and the Phase 1 revenue finding

The "Top 5 Products (by quantity sold)" card is replaced by **Menu
Performance**, whose money columns come from `ProfitCalculationService` — the
project's one source of money — rather than from a separate page-level
aggregation. Per-item revenue is necessarily **before order-level discounts**:
a discount belongs to the order and the database records no allocation of it
to individual lines. The column heading says so, and the discounted chain
(gross -> discounts -> net -> COGS -> gross profit -> margin) is reported
once, in the Gross Profit KPI and the CSV summary block.

### 11.8 The CSV export

`GET /admin/analytics/export`, in the same `role:admin,supervisor` group as
the page it exports. It calls `AdminController::analyticsContext()` and
nothing else, so the branch scope, the date resolution (including the fallback
a rubbish custom range triggers) and every figure are the page's own.

Follows the convention the Inventory and Sales exports already set:
`response()->stream()`, a UTF-8 BOM, a header block naming report / branch /
period / basis / who generated it, the data grids, then a summary block. No
generic export framework was introduced.

Where a value cannot be calculated the cell says **Insufficient Data**,
**Unavailable**, **Not Applicable**, **No Sales** or **None**. It is never
written as `0`.

**Formula injection.** Menu item, ingredient and bottleneck names are
user-entered, and Excel, LibreOffice Calc and Google Sheets all treat a cell
beginning `=`, `+`, `-`, `@`, tab or carriage return as a formula.
`fputcsv()`'s quoting does not help — that is about the CSV grammar, and a
correctly quoted `"=cmd"` is still evaluated once the reader strips the
quotes. Every free-text cell passes through `App\Support\Csv::cell()`, which
prefixes a single quote (the OWASP-documented neutralisation; spreadsheets
read it as "this cell is literal text" and do not display it). The Inventory
and Sales CSVs were given the same guard in this pass.

### 11.9 Branch scope

Unchanged and inherited. `getSelectedBranch()` is the only source of scope on
every one of the three surfaces, and none of them reads a `branch_id`,
`branch`, `scope` or `selected_branch_id` parameter — a branch-locked
supervisor is locked by `AdminOrderAccess::lockedBranchId()` before the
session is consulted, so a forged querystring has nothing to bind to. Under
`'all'` rows stay per menu item, each labelled with its own branch so two
same-named items are told apart — a **distinction**, not a comparison. No
per-branch total, ranking or comparison was added.

### 11.10 Performance

The join itself is pure arithmetic over results already in memory. It takes at
most two queries of its own, only under `'all'`, and only to label menu items
that sold but are no longer in the capacity list.

Phase 2d put `ProfitCalculationService` on this page and the first measurement
was **112 queries** for an 11-year range — a pre-existing N+1 this page had
never been in a position to trigger: the service's legacy-cost fallback called
`MenuItemCosting::costFor()` per sold **line**, and each of those takes a
recipe query plus an inventory query.

It was **fixed rather than budgeted for**. Lines now arrive with
`menuItem.recipeIngredients` eager-loaded and are priced through
`MenuItemCosting::costForMany()`, which takes one inventory query for the
whole set. The Summary screen and the sales CSV, which had the same shape, got
the fix with it.

Measured after: the page is **17** for a 1-day range and **21** for an
11-year one, the print view **20**, and the CSV export **16-20**. The page
budget asserted by `AnalyticsDailySeriesParityTest` (24) was **not raised**,
and the export is now held to it too.

### 11.11 Limitations

- Per-item revenue is before order-level discounts, and per-item margin with
  it — see §11.7. Only the period totals are net.
- Ingredient daily usage is a floor wherever some dependent items lack a
  forecast. It is flagged, but the true figure is not knowable from the data.
- Capacity counts the **base recipe only**; add-on options are chosen per
  order and are not part of an item's standing capacity.
- Days of coverage assumes today's capacity against a flat projection. It
  models no replenishment, no lead time and no trend, because the database
  records none of those.
- An item that never sold carries no demand figure at all. It appears with its
  capacity and recipe analysis and is reported INSUFFICIENT DATA — it is never
  presented as a proven zero-demand item.

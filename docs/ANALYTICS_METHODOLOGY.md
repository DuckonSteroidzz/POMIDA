# Analytics Methodology

This document explains the statistical and mathematical tools used by the
**Data Analytics & Business Insights** page (`/admin/analytics`).

> **Important wording note.**
> The system uses **descriptive analytics** and **rule-based recommendations**.
> It does **not** use machine learning or predictive AI. The terms used in
> the UI are *Data Analytics*, *Business Insights*, *Rule-Based
> Recommendations*, and *Analytics-Based Recommendations*. The phrase "AI"
> is not used unless an actual machine-learning model is integrated.

---

## 1. Data Sources

All analytics read from existing tables — no new columns were added.

| Purpose | Tables |
|---|---|
| Sales totals & trends | `orders` (filtered by `status = 'completed'` and `completed_at`) |
| Best / least sellers | `order_items` ⋈ `orders` ⋈ `menu_items` |
| Sales by category | `order_items` ⋈ `orders` ⋈ `menu_items` ⋈ `categories` |
| Inventory status | `inventory` (`quantity`, `low_stock_alert`, `is_active`, `branch_id`) |
| Movement / dead stock | `stock_movements` (`created_at`, `inventory_id`) |
| Ingredient → menu linkage | `menu_items.inventory_item_id` |
| Branch scope | `branches` (`id`, `is_active`) |

Branch scope is governed by the existing `getSelectedBranch()` helper:
staff are locked to their own branch; admins can pick a branch or
"All Branches".

---

## 2. Aggregation Functions

Standard SQL aggregations are used throughout:

- `SUM(quantity)` — total units sold per menu item.
- `SUM(total)` — total revenue per branch / per day / per period.
- `COUNT(*)` — count of completed orders, count of recommendations.
- `AVG(sales_30d)` — average 30-day sales across branches (used for the
  "branch leader" rule).
- `GROUP BY menu_item_id`, `GROUP BY categories.name`, `GROUP BY branch_id` —
  the standard grouping for sales rankings.

These are exposed as Eloquent / Query Builder calls in
`app/Services/AnalyticsService.php`.

---

## 3. Percentage Change Formula

Used for *today vs yesterday*, *this week vs last week*, and *this month vs
last month* comparisons.

```
delta_percent = ((current - previous) / previous) × 100
```

Implementation: `AnalyticsService::percentChange()`. Returns `null` when
`previous = 0` (undefined growth — division-by-zero avoidance).
Values are rounded to one decimal place.

---

## 4. Best / Least Seller Calculation

Both compute over **completed orders in the last 30 days**, scoped to the
selected branch when applicable.

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

Least-seller queries additionally filter `menu_items.is_available = TRUE`
so retired menu items don't pollute the list.

---

## 5. Inventory Threshold Rules

### 5.1 Out-of-stock rule

```
quantity <= 0
```

### 5.2 Low-stock rule

```
quantity > 0  AND  quantity <= low_stock_alert
```

(`low_stock_alert` is a per-item threshold configured on the inventory
record.)

### 5.3 Slow-moving / dead stock rule

```
quantity > 0
AND  inventory.id NOT IN (
    SELECT inventory_id FROM stock_movements
    WHERE created_at >= NOW() - INTERVAL 30 DAY
)
```

In words: the item has stock on hand but no `in`/`out` movement recorded
in the last 30 days.

### 5.4 Restock priority rule (cross-reference)

If an out-of-stock or low-stock item is **linked** (via
`menu_items.inventory_item_id`) to one of the top-10 best-selling menu
items, its recommendation level is escalated from *warning* to *critical*
("linked to a high-selling menu item — restock immediately").

This is the simple **rule-based reorder recommendation**.

---

## 6. Sales Trend Comparison

The daily-trend chart plots the last 14 days of completed-order sales,
giving a visual moving picture without a separate moving-average
calculation. Period-over-period deltas are computed with the
percent-change formula in §3.

### Rule thresholds that fire warnings

| Comparison | Warning threshold |
|---|---|
| Today vs Yesterday | drop ≥ 20 % |
| This Week vs Last Week | drop ≥ 15 % |

These thresholds were chosen as round, conservative defaults to avoid
recommendation noise on small daily fluctuations. They are easy to tune
in `AnalyticsService::recommendations()`.

---

## 7. Branch Performance Rule

When the user is viewing **All Branches**, the engine computes:

```
branch_avg = AVG(sales_30d_per_branch)
```

A branch is flagged as a "stronger sales" leader when:

```
branch.sales_30d > 1.5 × branch_avg
```

Implementation in `salesPerBranch()` + the branch-leader rule inside
`recommendations()`.

---

## 8. Rule-Based Recommendation Logic

`AnalyticsService::recommendations()` returns an array of cards. Each
card has the shape:

```php
[
    'level'   => 'critical' | 'warning' | 'success' | 'info',
    'icon'    => '<bootstrap-icon-class>',
    'title'   => 'Short label',
    'message' => 'Plain-language recommendation.',
]
```

The full ordered rule set:

1. **Out-of-stock** for every inventory item where `quantity <= 0`.
2. **Low-stock** for every inventory item where
   `0 < quantity <= low_stock_alert`.
3. **Linked-to-best-seller escalation** — rules 1 and 2 are upgraded to
   *critical* and messaged as
   *"linked to a high-selling menu item and should be monitored closely"*
   when the item links to a top-selling menu item.
4. **Slow / dead stock** for items with no movement in 30 days (first 5).
5. **Best sellers** — top 3 menu items by 30-day quantity sold.
6. **Least sellers** — bottom 3 available menu items by 30-day quantity sold.
7. **Today vs Yesterday warning** when the drop ≥ 20 %.
8. **This Week vs Last Week warning** when the drop ≥ 15 %.
9. **Branch leader** (only when scope = All Branches) when a branch's
   30-day sales exceed 1.5× the across-branch average.

The recommendation messages follow the panel's preferred phrasing
(*"… is a best seller. Consider increasing ingredient stock."*,
*"This product has low sales. Consider a promotion or menu review."*, etc.).

---

## 9. Sales Forecasting (Simple Linear Regression)

This section satisfies the panel's requirement to "focus on inventory/sales
analysis (forecasting)" and to document the statistical tool used.

### What SLR is, in plain language

Simple Linear Regression fits the straight line that best matches a series
of past points, then extends that same line forward to predict future
points. Here, the "points" are each day's total sales for completed orders.
If sales have been trending up or down fairly steadily, the line captures
that direction and projects it a few days ahead.

### The formula

```
x = sequential day index within the lookback window (0, 1, 2, … n-1)
y = that day's total sales (completed orders only)

b (slope)     = (nΣxy - ΣxΣy) / (nΣx² - (Σx)²)
a (intercept) = (Σy - bΣx) / n

forecast(x) = a + b·x
```

- `n` = number of days in the lookback window (default 30).
- The slope `b` is the average change in sales per day — positive means
  trending up, negative means trending down.
- The intercept `a` is where the fitted line would cross day 0.
- To forecast day `n`, `n+1`, … `n+6` (the next 7 days by default), those
  `x` values are plugged into `forecast(x) = a + b·x`.

Implementation: `AnalyticsService::salesForecast(int $lookbackDays = 30, int $forecastDays = 7)`.

### Derived outputs

- **Trend label** (`increasing` / `decreasing` / `flat`) — based on the
  sign of `b`, with a small epsilon (≈0.5% of the window's average daily
  sales) around zero so ordinary day-to-day noise doesn't flip the label
  back and forth. A relative epsilon was chosen over a fixed peso amount
  so the same rule works sensibly whether a branch does ₱2,000/day or
  ₱50,000/day.
- **Low-sales alert** — `true` when tomorrow's forecast is more than 20%
  below the trailing 7-day *actual* average, reusing the same 20%
  threshold convention already used for the Today-vs-Yesterday rule in §6.
- **Insufficient-data guard** — if fewer than 5 days in the lookback
  window had any completed orders, `salesForecast()` returns
  `insufficient_data: true` instead of a forecast. A line fit through
  mostly-empty days would be misleading rather than useful, so the view
  shows a plain "not enough data yet" message in that case instead of a
  chart.

### Why Simple Linear Regression

- **Simple and explainable to a non-technical panel** — the formula fits
  on one line and can be walked through by hand, unlike black-box ML
  models.
- **No ML infrastructure required** — it's arithmetic over existing
  `orders` rows, computed in PHP at request time. No training pipeline,
  no model file, no external service.
- **Matches the rest of this codebase's philosophy** (§1–§8): every other
  number on this page is a plain SQL aggregation or a rule threshold. SLR
  is the smallest step up from "descriptive" to "forecasting" that still
  fits that philosophy.

### Limitations

- **Assumes a linear trend.** Sales that curve, seasonally cycle (e.g.
  weekday vs. weekend spikes), or plateau will not be captured accurately
  by a straight line.
- **Sensitive to outliers.** One unusually large or small day (a promo,
  a branch closure) can swing the slope more than it should.
- **Unreliable with sparse data.** A short or gappy history produces an
  unstable line — this is exactly why the insufficient-data guard exists
  (§ above) and why the default lookback window is a full 30 days rather
  than the 14-day window used for the trend chart in §6.
- **Not a substitute for demand planning.** This forecast estimates
  aggregate peso sales, not per-item demand — it does not replace the
  inventory threshold rules in §5.

---

## 10. Limitations & Honesty Statement

- This is primarily a **descriptive** analytics layer, with one **statistical
  forecasting** component (§9, Simple Linear Regression) added specifically
  to satisfy the panel's forecasting requirement. It is not machine learning.
- Most trends are computed by **comparing fixed time windows** with the
  percent-change formula in §3. The sales forecast in §9 is the one
  exception — it projects forward using linear regression, not a
  fixed-window comparison.
- Reorder recommendations are **threshold-based**, not optimisation-based
  (no Economic Order Quantity, no probabilistic safety stock).
- All recommendation thresholds (20 %, 15 %, 1.5×, 30-day windows) are
  configurable constants in `AnalyticsService` — they're tuned for café
  scale, not derived from training data.
- Possible Capstone-2 future enhancements (require schema changes — keep
  for after a DB backup):
  - `lead_time_days` and `safety_stock` columns on `inventory` to enable
    the formal Reorder Point formula:
    `ROP = (Avg Daily Usage × Lead Time) + Safety Stock`.
  - Inventory turnover ratio per item:
    `COGS_period ÷ Average Inventory Value`.
  - ABC / Pareto classification of menu items (top 20 % contributing
    80 % of sales).
  - Notification dispatcher (email / Slack) when critical recommendations
    are generated.

---

## 11. Files Involved

- `app/Services/AnalyticsService.php` — all read-only queries and the
  rule engine, including `salesForecast()` (§9).
- `app/Http/Controllers/Admin/AdminController.php::showAnalytics()` —
  thin controller; passes the service output (including `$salesForecast`)
  to the view.
- `resources/views/admin/analytics.blade.php` — the dashboard, including
  the Sales Forecast chart card and low-sales alert banner.
- `routes/web.php` — `Route::get('/analytics', …)->name('analytics')`.
- `resources/views/admin/layout.blade.php` — sidebar link and branch-filter
  route list.
- `docs/ANALYTICS_METHODOLOGY.md` — this document.

No database migrations were introduced for this feature.

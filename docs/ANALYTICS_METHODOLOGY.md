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

## 9. Limitations & Honesty Statement

- This is a **descriptive** analytics layer, not a predictive one.
- Trends are computed by **comparing fixed time windows** with the
  percent-change formula in §3 — no forecasting, no regression.
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

## 10. Files Involved

- `app/Services/AnalyticsService.php` — all read-only queries and the
  rule engine.
- `app/Http/Controllers/Admin/AdminController.php::showAnalytics()` —
  thin controller; passes the service output to the view.
- `resources/views/admin/analytics.blade.php` — the dashboard.
- `routes/web.php` — `Route::get('/analytics', …)->name('analytics')`.
- `resources/views/admin/layout.blade.php` — sidebar link and branch-filter
  route list.

No database migrations were introduced for this feature.

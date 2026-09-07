# POMIDA — Concurrent load & capacity test

**Run date:** 29 August 2026
**Target:** the real running application (`php artisan serve`, real MySQL
`pomida_db`, real menu, real cart, real orders). Nothing stubbed, nothing
mocked, no test doubles.
**Harness:** [`tests/Load/load-test.mjs`](../tests/Load/load-test.mjs) (order
placement) and [`tests/Load/complete-race.mjs`](../tests/Load/complete-race.mjs)
(order completion / stock deduction), driven by
[`tests/Load/run-tiers.sh`](../tests/Load/run-tiers.sh).
**Raw output:** every figure quoted below comes from
[`tests/Load/results/`](../tests/Load/results/), which holds the unedited JSON
and the per-tier database-integrity queries.

---

## 0. The headline

> **The system was verified handling 100 simultaneous customer order placements
> with 100 % success and zero data corruption — 100 orders, 100 order lines,
> 100 distinct order numbers, no duplicates, no losses — at an average response
> time of 4,079 ms (worst case 8,283 ms).**
>
> Pushed to 150 simultaneous placements, it did not fail: 122 orders were
> accepted and the remaining 28 were **correctly refused by the rate limiter**,
> with the cart preserved and a plain-language retry message. Data integrity was
> perfect at every tier including that one.

The limit found is therefore **a deliberate rate-limiting policy, not a
breaking point**. No tier produced a single server error, lost order, duplicate
order or double stock deduction.

---

## 1. What "a customer places an order" actually means here

Every simulated customer is a genuine guest browser session that walks the real
flow before it is measured:

1. `GET /customer/menu` — real page, real session cookie, real CSRF token.
2. `POST /customer/select-branch` — puts `branch_id` into the session, exactly
   as the branch selector does.
3. `POST /customer/cart/add` — real cart with a real menu item (**Cheesy Pizza**,
   ID 33, ₱200, which has a real four-ingredient recipe).
4. `GET /customer/cart` — confirms the cart really holds the item; a session
   that fails this is not counted as ready.

Only then, and only at a barrier where every session fires together, comes the
measured request:

5. `POST /customer/place-order`

Steps 1–4 are setup and are **not** included in any timing. This matters because
`OrderController::placeOrder()` builds the order from the **session cart**, not
from the posted `items[]` array — a test that skipped the cart would not be
testing the real code path at all.

### Why this is not a re-run of the "Simultaneous Sessions" test

The earlier simultaneous-sessions work proved *correctness*: a handful of real
browsers acting at the same time reached the right answers. This test asks a
different question — *capacity*: how many order placements can arrive at once
before the system slows unacceptably or starts refusing, and does the data stay
correct when they do. Different question, different evidence.

---

## 2. Two server configurations, and why both are reported

`php artisan serve` runs PHP's built-in development server, which on Windows
handles **one request at a time** (`PHP_CLI_SERVER_WORKERS` is POSIX-only). If
every virtual customer is pointed at a single `artisan serve` process, the
requests queue politely and never actually collide — which measures throughput,
but cannot exercise the concurrency guarantees at all.

So the suite was run twice:

| Suite | Server | What it tells you |
|---|---|---|
| **A** | one `artisan serve` process | The floor. What the development server alone can absorb, and how latency behaves under pure queueing. |
| **B** | seven `artisan serve` processes on ports 8000–8006, same code, **same MySQL database**, virtual customers spread round-robin | The realistic figure. Requests genuinely execute in parallel and genuinely collide in the database — which is the condition the anti-duplication guarantees have to survive. This is also closer to the deployment target: Hostinger serves PHP with a multi-process web server, not `artisan serve` (see [`DEPLOYMENT.md`](DEPLOYMENT.md)). |

Suite B is the one to quote. Suite A is reported because omitting it would
overstate what a single dev-server process can do.

---

## 3. Results — Suite A (single `artisan serve` process)

| Concurrency | Accepted | Rate-limited | Errors | Avg ms | p50 | p95 | Max | Throughput | Integrity |
|---|---|---|---|---|---|---|---|---|---|
| 10 | 10 | 0 | 0 | 1,846 | 1,665 | 3,261 | 3,261 | 3.1 req/s | ✅ clean |
| 25 | 25 | 0 | 0 | 4,830 | 4,907 | 8,602 | 8,981 | 2.8 req/s | ✅ clean |
| 50 | 50 | 0 | 0 | 8,936 | 8,815 | 16,372 | 16,994 | 2.9 req/s | ✅ clean |
| 100 | 100 | 0 | 0 | 23,323 | 23,513 | 39,865 | 41,719 | 2.4 req/s | ✅ clean |
| 150 | 120 | **30** | 0 | 28,113 | 27,931 | 50,454 | 52,724 | 2.8 req/s | ✅ clean |

Throughput is flat at roughly **3 requests per second** regardless of load —
the signature of a single-threaded server. Every request still succeeds; they
simply wait. At 100 concurrent, the last customer in the queue waits 42 seconds,
which is unusable in a café even though nothing is technically broken.

**Honest reading of Suite A: the usable ceiling of a single `artisan serve`
process is around 10 concurrent placements**, where the worst customer waits
about 3 seconds. Beyond that, latency degrades linearly with no loss of
correctness. `artisan serve` is a development tool and this is exactly the
limitation it is documented to have — it is not evidence about the application.

---

## 4. Results — Suite B (seven parallel workers, one database)

| Concurrency | Accepted | Rate-limited | Errors | Avg ms | p50 | p95 | Max | Throughput | Integrity |
|---|---|---|---|---|---|---|---|---|---|
| 10 | 10 | 0 | 0 | 674 | 538 | 1,003 | 1,003 | 9.9 req/s | ✅ clean |
| 25 | 25 | 0 | 0 | 1,160 | 1,036 | 1,942 | 1,968 | 12.7 req/s | ✅ clean |
| 50 | 50 | 0 | 0 | 1,881 | 1,871 | 3,196 | 3,500 | 14.3 req/s | ✅ clean |
| **100** | **100** | 0 | 0 | **4,079** | 4,101 | 7,053 | **8,283** | 12.1 req/s | ✅ **clean** |
| 150 | 122 | **28** | 0 | 2,705 | 2,675 | 4,916 | 5,148 | 29.1 req/s | ✅ clean |

"Integrity ✅ clean" is defined precisely in §6 — it is a set of queries run
against the real tables after each tier, not an inference from HTTP status
codes.

**100 concurrent order placements is the honest maximum verified with zero data
corruption and zero refusals.** At that level the whole burst clears in 8.3
seconds and the median customer is answered in 4.1 seconds.

For scale: a single-branch café that took 100 orders in one *hour* would be
extremely busy. This test placed 100 in **8.3 seconds**.

---

## 5. What happens past the limit — and why 150 is not a failure

At 150 concurrent, 28–30 placements were refused. These are **not errors**. They
are the per-IP rate limit in
[`RateLimitServiceProvider`](../app/Providers/RateLimitServiceProvider.php)
doing exactly what it was written to do:

```php
public const PLACE_ORDER_PER_SESSION = 20;   // one ordering party, per minute
public const PLACE_ORDER_PER_IP      = 120;  // the whole café, per minute
```

Every virtual customer in this test originates from `127.0.0.1`, so the whole
run counts against one IP allowance of 120/minute. 120 accepted in Suite A and
122 in Suite B is that ceiling, measured.

### The refusals were verified as refusals, not guessed

A confirmation run at 150 concurrent classified each response by the
`X-RateLimit-Rejected: 1` header that
[`FriendlyThrottleResponse`](../app/Http/Middleware/FriendlyThrottleResponse.php)
stamps on every rate-limit rejection:

| | Count |
|---|---|
| Placements accepted | 122 |
| Responses carrying `X-RateLimit-Rejected: 1` | **28** |
| Accepted responses carrying that header | **0** |
| HTTP 5xx | **0** |
| Raw 429 error pages shown to a customer | **0** |

Every one of the 28 refusals was a `302` back to `/customer/cart` — the friendly
throttle path, which keeps the customer's cart and table intact and shows a
"please wait a moment and tap Place Order again" message, rather than the
full-page 429 that used to replace the cart. The refusal is graceful by design
and was observed to be graceful in practice.

**This is what "correctly rate-limited" means in the tables above, and why
those 28 are counted separately from failures. There were no failures.**

A real café would not hit this: 120 orders per minute from one shop is far
beyond any real service rate, which is precisely how the ceiling was sized.

---

## 6. Data integrity — the part that actually matters

An HTTP `302` proves nothing about the database. After **every** tier the real
tables were queried directly:

| Check | Result at every tier, both suites |
|---|---|
| Orders created == placements accepted | ✅ exact match, every tier |
| Distinct `order_number` values == orders created | ✅ no duplicate order numbers |
| `order_items` rows == orders created | ✅ exactly one line per order |
| Orders with a line count other than 1 | **0** |
| Orders with a wrong subtotal or total (≠ ₱200.00) | **0** |
| `stock_movements` rows added by placement | **0** (correct — see below) |
| Net change in total inventory quantity | **0.00** |

Cumulatively across all eleven tier runs (Suite A 305 accepted + Suite B 307 +
the 150-concurrency confirmation run 122): **734 orders placed under concurrency,
734 order lines, 734 distinct order numbers, 0 duplicates, 0 lost orders.** The
price check in the table above covered the ten tiered runs (612 orders); the
confirmation run was checked for order, line and order-number counts only.

### Why placement writes no stock movements

Placing an order *validates* stock but does not deduct it —
`InventoryDeductionService::deductWithLock()` runs when **staff completes** the
order (`AdminController::completeOrder`). So the "no double-charged / no
double-deducted stock" guarantee lives on a **different endpoint** from the one
above, and needed its own concurrent test.

### 6.1 Concurrent order-completion test (the stock-deduction race)

Four authenticated staff sessions, spread across four parallel workers, clicked
**Complete** on the *same* order at the same instant. Repeated for three orders.
Twelve simultaneous completion attempts in total.

Each order's item has a four-ingredient recipe (Pizza Dough, cheese, Pizza
Dough2, Whole Chicken), so one correct completion must write exactly **four**
`stock_movements` rows. A double deduction would write eight.

| Order | Simultaneous Complete clicks | Movement rows written | Distinct ingredients | Any `(order, ingredient)` pair written twice |
|---|---|---|---|---|
| 5785 | 4 | **4** | 4 | none |
| 5786 | 4 | **4** | 4 | none |
| 5787 | 4 | **4** | 4 | none |

Inventory after the race matched the single-deduction prediction to the decimal:

| Ingredient | Before | Predicted (one deduction × 3 orders) | Actual after |
|---|---|---|---|
| Whole Chicken | 973.00 | 967.00 | **967.00** |
| Pizza Dough | 4,988.00 | 4,985.00 | **4,985.00** |
| cheese | 9,989.00 | 9,986.00 | **9,986.00** |
| Pizza Dough2 | 9,700.00 | 9,400.00 | **9,400.00** |

Exactly one `order_status_changed` notification per order — the previously
reported "customer notified twice" symptom did not recur.

The three losing clicks per order each hit the `lockForUpdate()` guard in
`completeOrder()`, found the order already `completed`, and rolled back
everything. **The anti-duplication guarantee holds under real concurrent load,
not only under real concurrent sessions.**

---

## 7. Limitations — read these before quoting the number

1. **This is one machine.** App server, MySQL and the load generator all ran on
   the same Windows host, competing for the same CPU. On separate hosts the
   numbers would differ; on Hostinger shared hosting, so would the CPU budget.
2. **Suite B is seven `artisan serve` processes, not a production web server.**
   It produces genuine parallelism and genuine database contention, which is what
   the integrity claims rest on, but it is not Apache/LiteSpeed with PHP-FPM.
   The correctness findings transfer; the exact millisecond figures do not.
3. **Every request came from one IP.** That is what makes the per-IP ceiling the
   binding limit at 150. A real café's traffic also shares one public IP, so this
   is realistic — but it means this test does not distinguish "the app's capacity"
   from "the rate-limit policy" above 120/minute.
4. **One order shape.** One item, quantity 1, cash, pickup, no voucher, no
   PWD/Senior discount and no ID upload. A discount order with a 1 MB image
   upload is a heavier request and was not part of the tiers.
5. **Cold-cache effects were not controlled for.** The first tier of each suite
   paid Laravel's view-compilation cost.

---

## 8. Test data clean-up

Everything this test created was removed, and the removal was bounded by id
high-water marks captured *before* the run, so nothing pre-existing could be
touched.

| Table | Baseline before | After clean-up |
|---|---|---|
| `orders` | 117 | **117** |
| `order_items` | 183 | **183** |
| `order_item_options` | 24 | **24** |
| `stock_movements` | 52 | **52** |
| `notifications` | 61 | **61** |
| `inventory` | 10 rows, 50,736.00 total qty | **10 rows, 50,736.00 total qty** |
| `users` | 4 | **4** (temporary test admin deleted) |
| *all other business tables* | — | **identical** |

All **31 business tables** match their pre-test row counts exactly, and the four
inventory quantities touched by the completion race were restored to their exact
pre-test values.

Two tables do not, and cannot, return to an exact number:

* **`sessions`** (was 4) and **`cache`** (was 10). Both are Laravel's own
  transient stores. Every row this test created was deleted explicitly — 838
  harness sessions and 1,654 rate-limiter counters. What is left is not test
  residue: Laravel's *own* garbage collection pruned the pre-existing expired
  session and rate-limiter rows during the run, and both tables immediately
  begin refilling from ordinary use, so their counts move on their own between
  any two readings. Neither holds business data.

The temporary admin account created for the completion race
(`loadtest-temp-admin@invalid.local`) was deleted. The six extra `artisan serve`
worker processes were stopped; the original server on port 8000 was left running
and untouched.

---

## 9. Reproducing this

```bash
# one worker (Suite A)
POMIDA_BASE=http://127.0.0.1:8000 \
  node tests/Load/load-test.mjs 100 tier-100

# several workers (Suite B) — start extras first:
#   php artisan serve --port=8001   ... through 8006
POMIDA_BASE=http://127.0.0.1:8000,http://127.0.0.1:8001,http://127.0.0.1:8002 \
  node tests/Load/load-test.mjs 100 tier-100

# the whole tiered suite, with per-tier integrity queries
OUT=results TIERS="10 25 50 100 150" bash tests/Load/run-tiers.sh
```

`run-tiers.sh` spaces the tiers 75 seconds apart on purpose: the per-IP ceiling
is 120 placements per minute, so back-to-back tiers would be refused by design
and the earlier tiers' figures would be unreadable.

**Clean up afterwards.** `tests/Load/cleanup.sh` takes the id high-water marks
captured before the run and deletes only rows above them. Restoring inventory
quantities after a completion race is manual — record them first.

---

## 10. Related documents

* [`SECURITY_TESTING_SUMMARY.md`](SECURITY_TESTING_SUMMARY.md) — the rate limits
  exercised here were added by that work.
* [`RECOVERY_PLAN.md`](RECOVERY_PLAN.md) — backup and restore, tested.
* [`DEPLOYMENT.md`](DEPLOYMENT.md) — the production server this test is a proxy
  for.

---

## 11. Follow-up audit — "are the 734 orders still in the database?"

**Date of audit:** 2026-08-31

### 11.1 The concern

The Admin **Active Orders** board was reported to be showing **734 pending
orders**, matching the "734 orders" figure in §6 of this report exactly. The
worry was that `tests/Load/cleanup.sh` had failed to remove what it created and
that the load test's synthetic orders were sitting on the live dashboard as fake
pending work.

The audit was run as a *disproof-first* investigation: no row was to be deleted
until the data itself proved what those rows were.

### 11.2 What the database actually shows

The Active Orders board is populated by `AdminController::showHome()`, which
selects orders with `status IN ('pending','preparing','serving')`. Running that
exact predicate against the live database:

```sql
SELECT COUNT(*) FROM orders WHERE status IN ('pending','preparing','serving');
-- 0
```

**Zero.** The board is empty. The full status breakdown of the `orders` table:

| Status | Rows | Id range | `created_at` range |
|---|---|---|---|
| `completed` | 96 | 115 – 4497 | 2026-05-08 → 2026-08-26 |
| `cancelled` | 21 | 118 – 1988 | 2026-05-08 → 2026-08-25 |
| **any active status** | **0** | — | — |

`orders` holds **117 rows in total** — and there is no `pending`, `preparing` or
`serving` row anywhere in the table. `pomida_db` is also the only database on
this server that has an `orders` table at all, so the board cannot be reading
from somewhere else.

### 11.3 The 734 figure is a throughput total, not a residue

734 is the *cumulative number of placements accepted across eleven tier runs*
(Suite A 305 + Suite B 307 + the 150-concurrency confirmation 122 = 734). It
counts orders **created and then deleted** over the course of the test. It was
never a count of rows left behind. §8 of this report already records the
post-cleanup state, and every figure in it still holds today:

| Table | §8 "after clean-up" | Live value, 2026-08-31 | Match |
|---|---|---|---|
| `orders` | 117 | 117 | ✅ |
| `order_items` | 183 | 183 | ✅ |
| `order_item_options` | 24 | 24 | ✅ |
| `stock_movements` | 52 | 52 | ✅ |
| `notifications` | 61 | 61 | ✅ |
| `inventory` | 10 rows / 50,736.00 | 10 rows / 50,736.00 | ✅ |
| `users` | 4 | 4 | ✅ |

Orphan checks are also clean: **0** `order_items` without a parent order, **0**
`order_item_options` without a parent line, and **0** remaining
`loadtest-temp-admin@invalid.local` accounts.

Independent corroboration comes from the auto-increment counters, which do not
roll back when rows are deleted:

| Table | `AUTO_INCREMENT` | Max surviving id |
|---|---|---|
| `orders` | 5788 | 4497 |

≈1,291 `orders` ids were consumed *after* the newest surviving order and are now
absent from the table. That gap is the load-test data — created, then removed.

### 11.4 Conclusion of the audit

**No leftover load-test orders exist. Nothing was deleted, because there was
nothing to delete.** `cleanup.sh` did remove everything it created. The reported
"734 pending orders" could not be reproduced against the database; the figure
appears to have been read from §6 of this report (a cumulative placement count)
rather than from a live board query.

### 11.5 What the audit *did* find — and fix

Although the cleanup succeeded in fact, the script had no way of *proving* it
had, and two real gaps that could have caused a silent failure on a later run:

1. **It never verified its own work.** The old script ran its `DELETE`
   statements and then unconditionally printed `cleanup done` and exited `0`. A
   wrong high-water mark, or a table nobody had listed, would leave rows behind
   and still be reported as success — precisely the failure that was suspected
   here.
2. **It did not cover every table that references an order.** `orders` cascades
   to `order_items`, `order_item_options`, `notifications`, `order_ratings` and
   `games_played`, but `help_requests.order_id` and `table_sessions.order_id`
   are `ON DELETE SET NULL`. Test-created rows in those two tables would have
   survived the cascade as orphans pointing at nothing, and the script deleted
   neither.
3. High-water marks were interpolated into SQL without being validated as
   integers, and the deletes were not wrapped in a transaction.

**The fix** (`tests/Load/cleanup.sh`): every mark is validated as a
non-negative integer before it reaches SQL; the deletes run inside a single
transaction and now include `help_requests`, `table_sessions`, `order_ratings`
and `games_played`, deleted *before* `orders` so the `SET NULL` tables cannot be
orphaned; and the script then **re-reads every table and requires zero rows
above each high-water mark**, plus zero orphaned `order_items` /
`order_item_options`. If any check fails it prints `CLEANUP FAILED: <table> -> N
row(s) still present`, a loud "do not treat this run as cleaned up" banner, and
**exits non-zero**.

**Both paths were proved on a throwaway database**, by deleting the
`DELETE FROM orders` statement to simulate the exact "cleaned some tables but
not `orders`" bug, with five orders sitting above the mark:

```
# old script                    # hardened script
cleanup done                    CLEANUP FAILED: orders > 0 -> 5 row(s) still present
EXIT=0                          CLEANUP DID NOT COMPLETE. Test data is still in the database.
rows still there: 5             EXIT=1
```

Re-run standalone against the live database at its current high-water marks, the
hardened script reports `0 remaining` on all twelve checks and exits `0`.

### 11.6 Standing rule for future test runs

Any test that writes to this database must capture id high-water marks *before*
the run and clean up through `tests/Load/cleanup.sh` (or the same
capture → bounded-delete → **verify zero** pattern) afterwards. A cleanup that
cannot prove it removed everything must be treated as a failed cleanup. This
pattern is what [`NETWORK_RESILIENCE_TEST_REPORT.md`](NETWORK_RESILIENCE_TEST_REPORT.md)
uses for its own test data.

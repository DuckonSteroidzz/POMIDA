# POMIDA — Network Resilience Test Report

**Date:** 2026-08-31
**Question that prompted it:** *"What happens if the internet suddenly
disconnects?"*

That question has three genuinely different answers depending on *whose*
connection drops, so it was split into three scenarios and each was tested
against the running application and the real MySQL database. Nothing here is
reasoned from the code alone: every claim below has a query, an HTTP response
or a log line behind it.

Related documents — read rather than repeated here:

* [`LOAD_TEST_REPORT.md`](LOAD_TEST_REPORT.md) — concurrency and data-integrity
  behaviour, and §11 the bounded-cleanup pattern this report reuses.
* [`SECURITY_TESTING_SUMMARY.md`](SECURITY_TESTING_SUMMARY.md) — rate limiting
  and abuse protection.
* [`RECOVERY_PLAN.md`](RECOVERY_PLAN.md) — backup and restore.
* [`DEPLOYMENT.md`](DEPLOYMENT.md) — the production topology this models.

---

## 1. Method

All tests ran against the live application on `http://127.0.0.1:8000` and the
`pomida_db` MySQL database — real HTTP requests, real guest sessions, real rows.
The connection drops are real TCP aborts (`curl --max-time`, which cuts the
socket while PHP is still executing the request it already received), not mocks.

Test data was bounded exactly as [`LOAD_TEST_REPORT.md`
§11.6](LOAD_TEST_REPORT.md) now requires: id high-water marks captured before
anything ran (`tests/Network/capture-marks.sh`), deletion afterwards restricted
to rows above those marks, and a verification pass that requires **zero**
remaining rows before it will report success. See §7.

Scripts live in `tests/Network/`, raw output in `tests/Network/results/`.

---

## 2. Step 0 — what in this app actually needs the internet

This determines which of the three scenarios are even meaningful, so it was
settled first.

### 2.1 GCash is **not** a third-party API call

`OrderController::showGcashPayment()` reads two rows out of `settings`
(`gcash_qr`, `gcash_phone`) and renders them. `markGcashAsPaid()` sets
`payment_status = 'awaiting_verification'` and raises a staff notification.
Staff then approve or reject manually.

There is **no HTTP client, no SDK and no API credential anywhere in the GCash
path**. The customer scans a QR image the admin uploaded, pays inside their own
GCash app on their own phone, and presses "I have paid". The payment itself
never touches this application.

There is also **no customer-side proof-of-payment upload** in the GCash flow —
the only GCash file upload in the codebase is the *admin* uploading the QR image
(`SettingsController::updateGcashQr`). The brief's "connection drops during the
proof-of-payment upload" test therefore does not apply as written; §3.4 tests
the customer upload that *does* exist in the order flow instead.

### 2.2 The server makes no outbound calls during ordering

The only outbound network call in the whole of `app/` is `Mail::to()` in the
password-reset flow, and:

```
MAIL_MAILER=log            SESSION_DRIVER=database    QUEUE_CONNECTION=database
BROADCAST_CONNECTION=log   CACHE_STORE=database       FILESYSTEM_DISK=local
```

Every driver is local — MySQL on `127.0.0.1` and the local filesystem. Mail is
written to a log file, not sent. Confirmed empirically in §4.1: three order
placements produced **no change in the count of external TCP connections**.

### 2.3 What *did* depend on the internet: the entire front-end

Every customer page loaded its styling and scripts from public CDNs:

| Asset | Host | Consequence if unreachable |
|---|---|---|
| `@tailwindcss/browser@4` | jsDelivr | **every customer page renders unstyled** |
| `bootstrap-icons`, `bootstrap` | jsDelivr | missing icons / layout |
| `chart.js`, `jsQR`, `qrcodejs` | jsDelivr | analytics, QR scanning, QR generation break |
| Fraunces / Karla / Poppins | Google Fonts | fallback typefaces |

32 Blade views referenced them. This was the one real internet dependency in the
system, and it is fixed in §4.3.

---

## 3. Scenario A — the customer's own device loses connection

### 3.1 Connection cut after the request is sent, before the response arrives

`tests/Network/scenario-a-abort.sh`. The socket is cut 0.15 s into a
`POST /customer/place-order`, so the payload is fully delivered but the client
never reads a byte of the reply.

```
cut at 0.15s -> curl exit 28 (timeout/abort), orders MAX(id)=5788
RESULT after abort: curl exit=28; orders created=1
5788  ORD-20260831-AOUXCD  pending  pending  200.00  2026-08-31 00:40:31
```

**The order is created anyway, and it is complete — not half-written:**

```
id    order_number         status   line_items  lines_sum  notification
5788  ORD-20260831-AOUXCD  pending  1           200.00     new_order
```

PHP finishes the request it has already received; the client hanging up does not
abort server-side execution. The order has its line item, correct totals, and the
staff notification fired. This is the correct outcome — the customer's order is
not lost because their phone dropped Wi-Fi.

### 3.2 The customer, seeing no confirmation, presses the button again

Same session, same request repeated:

```
resubmit response: 302 http://127.0.0.1:8000/customer/cart
orders above baseline after resubmit: 1
VERDICT: no duplicate order. Double-submit was refused.
```

**No duplicate.** The protection is that `placeOrder()` calls
`session()->forget(['cart'])` immediately after the transaction commits, so the
retry arrives at a checkout with an empty cart and is bounced back to the cart
page. Since the commit and the cart-clear both happened server-side while the
customer was offline, the retry is refused *even though the customer never saw
the response*.

**A precise caveat, because it matters for an honest defence answer.** This is
not an idempotency key. `tests/Network/scenario-a-mechanism.sh` isolates it: if
the cart is deliberately re-filled and the order re-submitted, a second order
*is* created —

```
--- REFILL the cart, then retry (isolates the guard) ---
  -> 302 http://127.0.0.1:8000/customer/orders
  orders above baseline: 2
```

That is **deliberate**, not a bug. `CustomerOrderAccess::mayOrderAlongside()`
returns `true` for `pick_up` by design ("nothing else in the app assumes a
pick-up customer has at most one order open"), so a pick-up customer may place a
follow-up order. What is guaranteed is the thing the scenario actually asks
about: *an interrupted submission followed by a retry cannot double-charge*,
because the retry has nothing to submit.

### 3.3 GCash, with the connection dropping at each step

`tests/Network/scenario-a-gcash.sh`:

| Where the connection dies | Result |
|---|---|
| Loading the GCash payment page (GET) | `payment_status` stays `pending` — a GET changes nothing |
| During "I have paid" (POST) | server still processed it, so `awaiting_verification` |
| After reconnecting | payment page reachable again (HTTP 200) |

```
### connection drops DURING 'I have paid' (mark-as-paid POST)
  curl exit=28 (28 = cut)
  payment_status after the cut: awaiting_verification
### does the order remain visible + actionable (not stranded)?
  on the staff Active Orders board: 1
  in the customer's own order history: 1
  reachable payment page after reconnect: HTTP 200
```

**Nothing gets stranded.** At every point the order is still `pending` on the
staff Active Orders board and still in the customer's order history, so it can
always be completed or cancelled by a human. There is no state the order can
reach where neither side can act on it.

### 3.4 Connection dropped mid file-upload

The order flow's one customer-supplied file is the PWD/Senior ID image on
`place-order`. `tests/Network/scenario-a-upload.sh` sends a real 405 KB PNG,
rate-limited so the cut lands inside the body, then repeats the identical request
uninterrupted as a control:

```
=== A: upload CUT mid-transfer ===
curl exit=28
orders created: 0   (expect 0)
new files:      0   (expect 0)

=== B: CONTROL, identical request allowed to finish ===
curl exit=0 response=302
orders created: 1   (expect 1)
new files:      1   (expect 1)
5794  ORD-20260831-PVIFZT  pending  pending  discount_ids/v3UBjY....png
```

**A truncated upload is never accepted.** No partial file is written, no order
row is created, and the control proves the request would otherwise have
succeeded — so the zero is caused by the interruption, not by an invalid payload.

### 3.5 Does the session and cart survive the reconnection?

```
cart BEFORE drop -> empty marker count: 0  (0 = has items)
curl exit=28
cart AFTER reconnect -> empty marker count: 1  (1 = cart correctly cleared)
session still usable: HTTP 200
order history shows the order placed while offline: 1
```

The session survives (`SESSION_DRIVER=database`, so it is not held in a process
that a dropped connection could kill). The cart is empty — correctly, because the
order it held *did* commit. And the customer can see that order in their history
when they come back, so they learn what happened rather than being left guessing.

---

## 4. Scenario B — the café's internet drops, LAN and MySQL stay up

This is the scenario that actually matters for this deployment: customers order
over the shop's LAN, and the public uplink is the thing that fails.

### 4.1 The server side was already fine

`tests/Network/scenario-b-lan.sh`, three real cash orders placed while watching
non-loopback TCP endpoints:

```
before order traffic: 25
  order 1 -> 302 /customer/orders ; external sockets now: 25
  order 2 -> 302 /customer/orders ; external sockets now: 25
  order 3 -> 302 /customer/orders ; external sockets now: 25
  orders created: 3
```

Not one external connection was opened to place an order. Combined with §2.2
(no HTTP client in the code, every driver local) the server side needs no
internet at all.

### 4.2 The bug: the front-end did need it

With the CDN and font hosts pointed at a dead IP, they failed as expected —
and *before the fix* the customer menu page was still asking for five external
assets, including the Tailwind engine that styles the entire page. Orders would
still have gone through, but customers would have been ordering from an unstyled
page.

### 4.3 The fix — all assets self-hosted

Every CDN and Google Fonts asset was downloaded into `public/vendor/` and all 32
Blade views rewritten to reference the local copies:

| Local asset | Size |
|---|---|
| `tailwindcss-browser-4.js` | 282 KB |
| `bootstrap.min.css` / `bootstrap.bundle.min.js` | 233 KB / 80 KB |
| `bootstrap-icons.css` + 2 font files | 98 KB + 307 KB |
| `chart.umd.min.js`, `jsQR.min.js`, `qrcode.min.js` | 205 KB / 131 KB / 20 KB |
| `gfonts.css` + 20 `woff2` files (Fraunces, Karla, Poppins) | 472 KB |

`gfonts.css` was rewritten so every `url()` points at `/vendor/gfonts/...`; the
now-useless `preconnect` hints to `fonts.googleapis.com` / `fonts.gstatic.com`
were removed. No external host remains in any view except the three social-media
links in the footer, which are user-clicked and block nothing.

**Regression check:** the full suite — **371 tests, 1867 assertions — passes.**

### 4.4 Proof the app is now fully offline-capable

`tests/Network/scenario-b-offline-proof.sh` resolves `cdn.jsdelivr.net`,
`fonts.googleapis.com` and `fonts.gstatic.com` to `203.0.113.1` (RFC 5737
TEST-NET-3, guaranteed unroutable) for every request:

```
### confirming the block actually blocks
  cdn.jsdelivr.net         HTTP 000   unreachable (expected)
  fonts.googleapis.com     HTTP 000   unreachable (expected)

### customer loads the menu with the internet 'down'
  page: 47345 bytes, CSRF token: present
  external asset references on the page: 0  (0 = nothing to fail)

### every asset the page asks for, fetched with the internet down
  23 assets fetched, all HTTP 200: YES

### and the order still goes through
  place-order -> 302 http://127.0.0.1:8000/customer/orders
  orders created: 1
  5963  ORD-20260831-CC4TZG  pending  200.00
```

With the internet unreachable, the menu renders with all 23 of its assets and a
cash order completes normally. **The application is now entirely self-contained
on the LAN.**

---

## 5. Scenario C — interruption part-way through the order transaction

`placeOrder()` writes the order, its `order_items`, their `order_item_options`
and the voucher counters inside a single `DB::transaction()`. The guarantee is
that a failure anywhere in there leaves nothing behind. That was proved directly
rather than argued.

A temporary hook was added at the **end** of the transaction closure — after
every one of those rows had been written — throwing a real exception:

```php
if (env('POMIDA_FORCE_ROLLBACK_TEST')) {
    throw new \Exception('forced rollback: simulated mid-transaction failure');
}
```

`tests/Network/scenario-c-rollback.sh`:

```
before: orders|items|options|notifs|voucher_uses|stock_moves = 129|195|24|74|1|52
### placing an order that will fail mid-transaction
  response: 302
after : orders|items|options|notifs|voucher_uses|stock_moves = 129|195|24|74|1|52
  orders above baseline      : 0   (expect 0)
  order_items with no parent : 0   (expect 0)
  options with no parent line: 0   (expect 0)

VERDICT: every counter identical - the transaction rolled back completely.
```

The hook genuinely fired, inside the transaction — from `storage/logs/laravel.log`:

```
local.ERROR: Order placement failed ... Exception: forced rollback: simulated
mid-transaction failure at .../OrderController.php:533
#0 .../Illuminate/Database/Concerns/ManagesTransactions.php(32):
   OrderController->{closure}(Object(Illuminate\Database\MySqlConnection))
```

So rows *were* written and then discarded. All six counters are unchanged and
there are no orphans. **The rollback guarantee holds.**

The hook and its `.env` flag were removed afterwards; `OrderController.php` was
diffed against its pre-hook copy and is **byte-identical**. A control placement
after removal succeeded normally (order `5965`), confirming the endpoint was
left working.

---

## 6. Bugs found and fixed

| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | Whole customer UI (Tailwind, Bootstrap, icons, fonts, Chart.js, QR libs) loaded from public CDNs — a café internet outage leaves customers ordering from an unstyled page | **High** for a LAN-deployed café POS | **Fixed** (§4.3), 371 tests still pass |
| 2 | `cleanup.sh` reported success without verifying, and did not cover `help_requests` / `table_sessions` (`ON DELETE SET NULL`, so they orphan instead of cascading) | Medium — test data could silently persist | **Fixed** — see [`LOAD_TEST_REPORT.md` §11.5](LOAD_TEST_REPORT.md) |

### Not fixed — flagged for the team

**`phpunit.xml` runs the test suite against the live `pomida_db`.** The
`DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` lines are commented out. Live
data survives today only because 24 of the 26 feature test files use the
`DatabaseTransactions` trait and roll back. A single new test that forgets that
trait would write permanently into live business data. This was left alone
deliberately: switching the suite to SQLite mid-defence-week risks breaking
tests that rely on MySQL-specific behaviour, and the change belongs with a full
suite re-validation rather than in a resilience-testing pass. The one-line
recommendation is to uncomment those two lines and re-run the suite when there
is time to fix any fallout.

---

## 7. Test data clean-up

Marks captured before anything ran (`tests/Network/results/marks.env`):

```
ORD_MAX=4497  OI_MAX=2897  OIO_MAX=123  SM_MAX=265  NOTIF_MAX=642
HR_MAX=10     TS_MAX=1702  OR_MAX=273   GP_MAX=213
```

The tests created **13 orders, 13 order_items, 14 notifications** and **1
uploaded ID image**. The image was deleted from
`storage/app/public/discount_ids/` first (while the DB still held its path), then
`tests/Load/cleanup.sh` removed the rows and verified its own work:

```
cleanup done - verified zero rows above every high-water mark   EXIT=0
```

Every business table is back to its documented baseline:

| Table | Baseline | After |
|---|---|---|
| `orders` | 117 | **117** |
| `order_items` | 183 | **183** |
| `order_item_options` | 24 | **24** |
| `stock_movements` | 52 | **52** |
| `notifications` | 61 | **61** |
| `inventory` total qty | 50,736.00 | **50,736.00** |
| `users` | 4 | **4** |
| uploaded ID images on disk | 60 | **60** |

**Active Orders board: 0.** Orphaned `order_items`: 0. Orphaned
`order_item_options`: 0. Orders referencing a missing image: 0.

No machine-level change was left in place: the hosts file is byte-identical to
its backup (it was never modified — the CDN block used per-request
`curl --resolve`, which needs no admin rights and persists nothing), no firewall
rules were added, the `.env` flag is gone and the temporary controller hook is
gone.

---

## 8. Limitations

* **The CDN outage was simulated per-request, not machine-wide.** `curl
  --resolve` blocks those hostnames for the test's own requests. Editing the
  Windows hosts file needs Administrator rights, which this environment does not
  have. The simulation is sound for what is being proved — the app serves every
  asset it needs from `127.0.0.1` and references no external host at all
  (§4.4) — but it is not the same as pulling the shop's uplink.
* **Only loopback was exercised.** Every request came from `127.0.0.1`, not from
  a phone on the shop Wi-Fi. Wi-Fi-specific failures — weak signal, roaming
  between access points, captive portals, NAT timeouts — are not covered.
* **"Connection dropped" means a TCP abort**, which is the common case (device
  sleeps, Wi-Fi drops, user closes the tab). It is not a half-open connection
  that stays open until an OS-level timeout; those behave differently and were
  not tested.
* **The abort timing is empirical, not deterministic.** The cut at 0.15 s lands
  after the request is delivered and while PHP is still working, verified by the
  order appearing. It is not a guaranteed instruction-level interrupt point.
* **Scenario C forces the failure at one point** — the end of the transaction,
  which is the strongest case since every row has been written by then. Failures
  at other points inside the closure were not individually enumerated; the
  guarantee they rely on is the same single `DB::transaction()`.
* **MySQL was never actually killed mid-write.** Scenario C proves the
  application-level rollback path. It does not prove InnoDB crash recovery,
  which is a database property, not an application one.
* **Staff/admin flows were tested only for asset self-containment**, not for
  connection drops mid-action.

---

## 9. Reproducing this

```bash
# 0. capture high-water marks FIRST - nothing below is safe without this
bash tests/Network/capture-marks.sh tests/Network/results/marks.env

# 1. Scenario A - aborted placement, resubmit, session/cart, GCash, upload
bash tests/Network/scenario-a-abort.sh
bash tests/Network/scenario-a-mechanism.sh
bash tests/Network/scenario-a-session.sh
bash tests/Network/scenario-a-gcash.sh
bash tests/Network/scenario-a-upload.sh

# 2. Scenario B - LAN-only operation, and the offline proof
bash tests/Network/scenario-b-lan.sh
bash tests/Network/scenario-b-offline-proof.sh

# 3. Scenario C - needs the temporary hook (see section 5) and:
#      echo 'POMIDA_FORCE_ROLLBACK_TEST=1' >> .env && php artisan config:clear
bash tests/Network/scenario-c-rollback.sh
#    then REMOVE the hook and the flag, and diff the controller to confirm.

# 4. clean up - deletes only rows above the marks, and verifies it
source tests/Network/results/marks.env
bash tests/Load/cleanup.sh     # exits non-zero if anything is left behind
```

`tests/Network/fixtures/valid_id.png` is the real PNG the upload test posts.
Note that `curl` on Windows cannot read a `/tmp` path — fixtures must be given
as project-relative paths.

---

## 10. The short answer

* **A customer's phone drops mid-order** — the order still commits, complete and
  visible to staff; pressing "place order" again cannot duplicate it, because the
  cart was already cleared server-side; the session and order history are intact
  on reconnect.
* **The café's internet drops** — nothing happens. The server never needed the
  internet, and since the asset fix the browser does not either: menu, cart,
  checkout and receipts all serve from the LAN and orders complete normally.
* **The order fails part-way through writing to the database** — every row is
  rolled back. No partial orders, no orphaned line items, no wrongly-spent
  vouchers.

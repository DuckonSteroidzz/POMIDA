# Security Testing Summary — POMIDA

**Date:** 23 August 2026
**Scope:** First formal security pass on the POMIDA ordering system.
**Method:** Live black-box testing against a running instance (`http://127.0.0.1:8000`)
using real authenticated sessions and real HTTP requests, backed by source review.
Every finding below was reproduced live before being called a vulnerability, and every
fix was re-tested live against the same attack.

**Test accounts:** Six disposable accounts (two customers, one staff, one admin, two
registration probes) plus two disposable orders. All test data was deleted afterwards;
final row counts for users, orders, order items, vouchers, ratings and categories match
the pre-test baseline exactly.

---

## Summary

| # | Area | Result |
|---|------|--------|
| 1 | GCash payment tampering | **1 issue found — fixed** |
| 2 | Order/receipt access control (IDOR) | **1 issue found — fixed** |
| 3 | Role separation (staff vs admin vs customer) | Passed |
| 4 | Mass assignment / privilege escalation via forms | **1 issue found — fixed** |
| 5 | XSS / raw Blade output | Passed |
| 6 | Rate limiting on auth and money endpoints | **1 issue found — fixed** |

Four issues were found. All four were fixed and re-verified during this pass.

---

## 1. GCash payment tampering

The GCash flow is: customer picks GCash → sees a QR code → self-declares "I paid" →
staff manually verifies before the order is marked paid.

**Tested and passed:**

- A customer **cannot** set their own order to `paid`. `markGcashAsPaid()` takes no
  input from the request body — it hard-codes the transition to
  `awaiting_verification`. Posting `payment_status=paid`, `status=completed`,
  `total=1` and `amount_paid=80` alongside the request changed nothing but the
  intended status field.
- The only endpoint that can set `payment_status = 'paid'` is
  `PUT /admin/orders/{id}/payment/approve`. It sits behind the `admin` guard **and**
  `role:admin,staff`, and it reads nothing from the request body. A logged-in customer
  hitting it directly is redirected to `/admin/login` with no state change. Verified
  that a staff session *can* approve, so the manual-verification path still works.
- **Order totals cannot be manipulated after placement.** Posting `total`,
  `subtotal`, `discount_amount` and `payment_status` to the two customer-reachable
  order-update endpoints (`/orders/{id}/cancel` and
  `/orders/{id}/continue-without-discount`) left every monetary field unchanged.
  Neither endpoint reads pricing from the request.
- Order pricing at checkout is re-derived server-side in `placeOrder()` from current
  database prices and the session cart, not from the posted `items[]` array.

**Found — IDOR on the GCash endpoints (fixed):**

`showGcashPayment()` and `markGcashAsPaid()` both resolved the order with a bare
`findOrFail($id)` — no ownership check at all.

Reproduced live: a **logged-out guest** and a **second logged-in customer** could each
(a) read another customer's GCash payment page including the order total, and (b) flip
that stranger's pending order into `awaiting_verification` — the exact state staff are
asked to approve. This is not a direct payment bypass, but it lets anyone inject false
"customer says they paid" claims into the staff verification queue for orders they do
not own, which is a plausible route to a staff member approving an unpaid order.

**Fix:** both endpoints now resolve the order through a new `resolveOwnedOrder()` helper
(see §2) and return 404 when the visitor has no claim to it.

**Verified after fix:** guest → 404, other customer → 404, order state unchanged. Owner
still gets 200 and can still submit their own payment declaration; staff approval still
works end to end.

---

## 2. Order/receipt access control (IDOR)

**Found — guest could read any customer's receipt (fixed):**

`showReceipt()` treated "not logged in" as "guest checkout" and looked the order up by
ID alone. Reproduced live: a logged-out visitor requesting
`/customer/receipt/170` — an order belonging to a real registered customer — received
**HTTP 200 and the full receipt**: order number, date, every line item with quantities
and add-ons, subtotal, discount amount, total, payment method, and the dine-in table
number. Any order was readable by incrementing the ID.

This was a real gap rather than a deliberate guest-checkout allowance. Guest checkout
is genuinely used (17 orders in the database have `user_id = NULL`), so the fix had to
preserve it.

**Fix:** a single `resolveOwnedOrder()` helper in `OrderController` now backs
`showReceipt()`, `showGcashPayment()` and `markGcashAsPaid()`:

- Logged-in customer → the order must match their `user_id`.
- Guest → the order must be the one **this session** placed
  (`session('guest_order_id')`) **and** must be a genuine guest order
  (`user_id IS NULL`). The second condition means a guest can never read an order
  belonging to a registered account, even with a correct ID guess.

**Verified after fix:**

- Guest → registered customer's receipt: 404 (was 200).
- Guest → another guest's receipt: 404.
- Full guest checkout replayed end to end on a fresh session — select branch, add to
  cart, place GCash order, view payment page (200), declare paid (state advanced), view
  own receipt (200), poll status (200). Guest checkout is unaffected.

**Tested and passed:** one logged-in customer could **not** read or modify another
logged-in customer's order or receipt — the existing `user_id` scoping already returned
404 in both directions, before and after the fix.

### 2a. Follow-up: the rule was slightly too narrow (fixed, without widening the IDOR)

**Found in live use.** Staff completed an order, the customer tapped "View Receipt", and
`/customer/receipt/1985` returned 404 for the customer's **own** order. Two causes, both
of which made a genuine owner look like a stranger:

- The order was placed as a guest and the visitor had since signed in. The logged-in
  branch matched on `user_id` only, and a guest order has `user_id IS NULL`.
- A new dine-in visit had started in the same browser. Continuing as a guest from the QR
  calls `GuestOrders::forget()`, which clears the claim set so the next party at the
  table inherits nothing — correct, but it also revoked the previous party's receipts.

**Fix:** the logged-in branch now falls through to the **unchanged** guest rules when the
order is not on the account, and `forget()` moves the ids to a read-only archive
(`GuestOrders::pastIds()`) that **only** `showReceipt()` consults, via an explicit
`$includePastVisits` argument. Nothing that can change an order — cancel, rate,
continue-without-discount, GCash — sees the archive.

**Verified after fix** (`tests/Feature/ReceiptAccessTest.php`, 10 tests): a guest and a
logged-in customer can both open their receipt immediately after completion, including
after signing in and after a new visit starts; while a stranger, a guest holding a
planted id for a registered customer's order, and a second customer all still get 404 —
and an archived id is proven to grant reading the receipt and nothing else.

---

## 3. Role separation — staff vs admin vs customer

Re-run formally rather than relying on the earlier result. The application defines
**52 admin-only routes** and **21 shared admin+staff routes**.

All 17 statically-addressable gated routes were swept against three live sessions:

| Route class | Staff session | Customer session | Logged-out guest |
|---|---|---|---|
| 12 admin-only GET routes | 302 → `/admin/home` (never the real page) | 302 → `/admin/login` | 302 → `/admin/login` |
| 5 shared admin+staff GET routes | 200 (correct) | 302 → `/admin/login` | 302 → `/admin/login` |

**Privilege escalation via direct POST, using a valid CSRF token taken from a real
staff page** — all rejected, all with zero database side effects:

- Staff creating an admin account (`POST /admin/users`, `role=admin`) → bounced; no user created.
- Staff creating a voucher (`POST /admin/vouchers`, ₱9999) → bounced; no voucher created.
- Staff toggling their own account (`PUT /admin/users/{id}/toggle`) → bounced; unchanged.

**Guard isolation** — the `admin` and `customer` guards do not leak into each other:

- Customer credentials at the admin login form are rejected, and `/admin/home` remains
  inaccessible afterwards.
- Staff credentials at the customer login form are rejected.
- A staff session cannot reach customer-only account pages.
- A customer session cannot reach the staff receipt view.

No issues found. Role separation holds.

---

## 4. Mass assignment / privilege escalation via forms

`User::$fillable` does contain sensitive fields (`role`, `is_active`, `points`), so the
public-facing controllers were checked against actual smuggling attempts.

**Tested and passed.** No controller in the codebase uses `$request->all()`,
`$request->except()`, `->fill($request…)`, `::create($request…)` or
`->update($request…)`; every write assigns validated fields explicitly.

- Registration with `role=admin`, `points=99999`, `is_active=1`, `verified_at` smuggled
  into the POST → account created as `role=customer`, `points=0`.
- Profile update with `role=admin`, `points=99999` smuggled in → unchanged.

**Found — unvalidated points award (fixed):**

The real escalation path was not mass assignment but `POST /customer/add-points`, the
spin-the-wheel game endpoint. It read `points` straight from the request with no
validation and no cap.

Reproduced live: a single request with `points=99999` credited the account with 99,979
points **and immediately minted a real, redeemable discount voucher**. That is a direct
path from one crafted request to a money-off voucher.

**Fix:** the awarded value is now validated against a server-side allowlist of the only
values the wheel can actually produce (`0, 3, 5, 8` — mirroring the `segments` array in
`game.blade.php`), declared as `AuthController::GAME_POINT_AWARDS`. The endpoint was
also rate limited (§6).

**Verified after fix:** `99999`, `1000`, `9`, `4` and `-5` all rejected with HTTP 422;
`0`, `3`, `5`, `8` all accepted with HTTP 200 and a correct running total (16). The game
still works normally.

---

## 5. XSS / raw Blade output

The whole `resources/views` tree was scanned for Blade's raw, non-escaping `{!! !!}`
syntax. **12 instances**, all in two admin-only reporting views
(`admin/analytics.blade.php`, `admin/summary.blade.php`), and all of the same shape:
`json_encode(...)` injected into a Chart.js configuration.

**No user-supplied text reaches any of them.** Eleven carry dates and numbers. The
twelfth carries `categories.name`, which is authored only through
`/admin/add-category` — an admin-only route. No customer-controllable field (customer
name, beneficiary name, order notes, voucher description) reaches a raw sink.

Tested rather than assumed: a category was created with the payload
`SECTESTXSS</script><script>window.SECTEST_XSS=1</script>` and the admin pages were
re-rendered. Result — **no script breakout, zero literal payload occurrences**. Two
independent reasons:

1. The payload never reached the chart sink at all (chart labels only include
   categories that have completed order items).
2. Even if it had, PHP's `json_encode` escapes `/` as `\/` by default, so `</script>`
   becomes `<\/script>` and does not terminate the script block.

In the HTML contexts the name renders through `{{ }}` and comes back fully entity-escaped.

No XSS found. The test category was deleted.

---

## 6. Rate limiting

**Found — no throttle on login (fixed).** Before this pass, `POST /customer/login` and
`POST /admin/login` had no rate limiting whatsoever. Reproduced live: **25 consecutive
wrong-password attempts, 25 accepted, zero blocked** — unlimited password guessing
against both customer and staff/admin accounts. `POST /new-password`, the final step of
the password-reset flow, was likewise unthrottled, as were `apply-voucher` (voucher-code
enumeration) and `add-points`.

**Fix:** throttle middleware added. Full coverage of auth-adjacent and money-touching
endpoints is now:

| Endpoint | Limit |
|---|---|
| `POST /customer/login`, `POST /admin/login` | 10/min |
| `POST /customer/register` | 10/min |
| `POST /customer/forgot-password`, `POST /admin/forgot-password` | 6/min |
| `POST /customer/new-password`, `POST /admin/new-password` | 6/min |
| `POST /customer/verification`, `POST /admin/verification` | 10/min |
| `GET /customer/verification/resend`, `GET /admin/verification/resend` | 3/min |
| `POST /customer/apply-voucher` | 20/min |
| `POST /customer/add-points` | 30/min |
| `POST /customer/help-request` | 10/min |

**Verified after fix:** the same 20-attempt brute force is now blocked from attempt 11
onward (HTTP 429) on the customer login, and the admin login rate-limits as well.
Legitimate customer, staff and admin logins all still succeed, and the game still awards
points normally — the limits are set well above real usage.

### 6a. Follow-up: those limits counted the wrong thing (fixed)

**Found in live use.** The limits above are all keyed on IP address, which is Laravel's
`throttle` default and is exactly right for a login form. It is wrong for ordering.

In a café **every customer is on the shop's wi-fi and shares one public IP**, so
`throttle:10,1` on `POST /customer/place-order` was not "ten orders a minute per
customer" — it was ten orders a minute for the whole room. The eleventh customer to tap
Place Order in a busy minute was refused for a stranger's activity. The same effect made
the branded 429 page appear constantly during demo and testing, where every tab is
`127.0.0.1`.

**Fix:** named limiters in `App\Providers\RateLimitServiceProvider`, each applying two
limits at once — one per ordering party (logged-in customer id, otherwise session id)
and a much higher per-IP ceiling underneath, so a cookie-discarding script is still
capped while a queue of real customers is not.

| Endpoint | Per visitor | Per IP (whole café) |
|---|---|---|
| `POST /customer/place-order` | 20/min | 120/min |
| `POST /customer/gcash-payment/{id}/paid` | 20/min | 120/min |
| `POST /customer/orders/{id}/rating` | 20/min | 120/min |
| `GET /customer/orders/{id}/rating` | 60/min | 300/min |
| `POST /customer/apply-voucher` | 15/min | 60/min |
| `POST /customer/help-request` | 10/min | 60/min |

**Auth endpoints are deliberately unchanged and stay purely per IP** — login,
registration, password reset and verification are precisely the cases where "many
attempts from one address" IS the attack, and where a shared address being throttled
together is the intended behaviour. `add-points` also stays per IP: it is already gated
by requiring a real active order.

A rejection on the customer money endpoints is now returned by
`App\Http\Middleware\FriendlyThrottleResponse` as a redirect back to the page the
customer was on, carrying a plain-language message, instead of a full-page 429 whose only
exit was the landing page. The cart and dine-in table context survive untouched (the
limiter rejects the request before the controller runs, so nothing was ever lost — the
customer simply had no way to know that). Responses still carry `X-RateLimit-Rejected`
so a refusal remains identifiable in logs.

**Verified after fix** (`tests/Feature/ThrottleHardeningTest.php`, 11 tests): each
endpoint still refuses one visitor at their own limit; a second customer on the same IP
is never blocked by the first; a session-hopping caller still hits the IP ceiling at
request 121; login still blocks a guesser who rotates sessions; and hitting the
place-order limit produces a redirect with a readable message and an intact cart.

---

## Explicitly out of scope

These were deliberate decisions for a capstone timebox, not oversights.

- **SQL injection** — not separately fuzzed, but every raw-SQL usage in `app/` was
  enumerated and read. The application uses Eloquent and the query builder throughout.
  All six `DB::raw` fragments are constant aggregate expressions
  (`COUNT(*)`, `SUM(order_items.quantity)`, `HOUR(created_at)`, …) with no interpolated
  variables. The single raw fragment that touches user input —
  `whereRaw('LOWER(email) = ?', [...])` in `HandlesPasswordReset` — uses a bound
  parameter placeholder rather than string concatenation. No string-built SQL,
  `DB::statement` or `DB::select` calls exist. Reviewed by reading, not by fuzzing.
- **CSRF** — Laravel's `VerifyCsrfToken` is active on the whole `web` group and was
  observed working during testing (a request with a stale token returned HTTP 419).
  We relied on the framework default and did not attempt a bypass.
- **File-upload security** (discount ID images) — validated by Laravel's `image` and
  `mimes:jpeg,jpg,png,webp` rules with a 5 MB cap. We did not attempt polyglot files,
  image-borne payloads, or path traversal on the storage disk.
- **Password policy** — the minimum is 6 characters, which is weak by modern standards.
  Left as-is because changing it would invalidate existing demo accounts; rate limiting
  is now the compensating control. Flagged, not fixed.
- **Session/cookie hardening** (`SESSION_SECURE_COOKIE`, `SameSite`, HSTS) — deployment
  concerns. The system runs over HTTP on localhost for the defence; these belong to a
  production deployment checklist.
- **Denial of service, and network/infrastructure testing** — out of scope for an
  application-level review of a locally-hosted capstone system.
- **Dependency CVE audit** (`composer audit` / `npm audit`) — not run as part of this
  pass.
- **`APP_DEBUG=true`** is set in the local `.env`. That is correct for development but
  would leak stack traces in production; it must be `false` on any real deployment.
  Noted rather than changed, since the defence runs on the local configuration.

---

## Files changed in this pass

| File | Change |
|---|---|
| `app/Http/Controllers/Customer/OrderController.php` | Added `resolveOwnedOrder()`; applied it to `showReceipt()`, `showGcashPayment()`, `markGcashAsPaid()` |
| `app/Http/Controllers/Customer/AuthController.php` | Added `GAME_POINT_AWARDS` allowlist and validation in `addPoints()` |
| `routes/web.php` | Added throttle middleware to 8 auth/money endpoints |

---

# Pass 2 — Manual security review, 2026-08-31

A hands-on review of the running system ahead of the Hostinger deployment, the
beneficiary/TA testing period (to 15 Sept) and the 4 Oct defence. Items 1 and 2
were verified by hand by the reviewer and needed no change. Items 3 and 4 were
real findings and were fixed and re-verified.

| # | Area | Result |
|---|------|--------|
| 1 | Admin pages by direct URL while logged out | Passed — verified manually |
| 2 | Another customer's order/receipt by ID (IDOR) | Passed — verified manually |
| 3 | Admin/staff login brute-force limit | **Too permissive — fixed** |
| 4a | Reset code printed on screen | **Hardened + documented** |
| 4b | No password strength requirement anywhere | **Real issue — fixed** |
| 4c | Verification code lifetime | **Tightened** |

---

## 1. Admin pages by direct URL while logged out (verified manually — passed)

**Method.** The reviewer requested protected admin URLs directly in the browser
while not authenticated, rather than navigating to them through the UI.

**Result.** Every attempt redirected to the admin login page. No protected
content, and no fragment of a protected page, was served to an unauthenticated
visitor.

This is the `admin` middleware alias (`App\Http\Middleware\AdminMiddleware`)
applied to the protected route group in `routes/web.php` — see also §3 of Pass 1,
which tested the staff-versus-admin half of the same boundary.

**No code change was needed.** Recorded here because "we checked, and here is
what we did" is the answer the defence needs, not "it should be fine."

## 2. Another customer's order/receipt by ID (verified manually — passed)

**Method.** From an unrelated browser session, the reviewer requested real order
IDs taken from the live database by editing the numeric ID in the URL
(`/customer/receipt/{id}` and the related order endpoints). Two cases were
covered deliberately, because they fail differently: a **freshly placed pending
order** and an **old completed order**.

**Result.** Both returned **HTTP 404** from the unrelated session. No order
number, line items, totals, payment method or table number were disclosed.

This is `resolveOwnedOrder()` doing what Pass 1 §2 added it for: a logged-in
customer must match on `user_id`, and a guest must have placed that exact order
in that exact session *and* the order must be a genuine guest order. Returning
404 rather than 403 is deliberate — it does not confirm that the ID exists.

**No code change was needed.** The Pass 1 fix is holding under manual retesting
against live data.

---

## 3. Admin/staff login brute-force limit (fixed)

**Found.** The reviewer hit `/admin/login` with a nonexistent email address and
repeated wrong passwords, and got roughly **10 attempts** before the friendly
429 appeared. Ten guesses a minute against the portal that owns the admin and
staff accounts is far more headroom than a real person mistyping needs.

The route carried `throttle:10,1`. Note this single route is the login for
**both staff and admin** — there is no separate staff limiter to change.

**Fix.** `routes/web.php` — `throttle:10,1` becomes `throttle:3,1` on
`admin.login.post`.

Two deliberate non-changes, both worth being able to defend:

* **The keying is untouched, as instructed.** Worth stating precisely, because
  it is stricter than it may look: Laravel keys an unauthenticated throttle on
  the **client IP alone**, not on IP+email. So the 3 attempts are per address
  across *every* email an attacker tries. Keying on IP+email would actually be
  weaker — it hands an attacker a fresh allowance of 3 for each new address they
  guess.
* **The customer login stays at `throttle:10,1`.** Every customer in the café
  shares one public IP, so 3/min there would lock the whole shop out as soon as
  one person mistyped their password three times. The privileged portal is the
  one that needs the tight limit. Pass 1 §6a covers the same reasoning for the
  ordering endpoints.

**The 429 wording is unchanged**, as requested — it still reads:

> **Too many attempts.** That was tried too many times in a row. Please wait a
> minute and try again — this limit is what keeps the account safe from
> guessing. Nothing was charged and nothing was lost. If you were ordering, your
> cart and your table are still exactly as you left them.

**Verified live** against the running app (`tests/Security/throttle-admin-login.sh`),
with the limiter counter cleared first so the count starts from zero:

```
attempt 1 -> HTTP 302  (allowed)
attempt 2 -> HTTP 302  (allowed)
attempt 3 -> HTTP 302  (allowed)
attempt 4 -> HTTP 429  (refused)
attempt 5 -> HTTP 429  (refused)
```

**And the limit does not break legitimate login** — the lockout is temporary,
not a lockout of the account. `tests/Security/login-recovers.sh` waits out the
real 60-second window with no cache tampering and then signs in normally:

```
locked out at 11:21:02; waiting 65s for the throttle window to expire...
window should be open at 11:22:07
correct login -> 302|http://127.0.0.1:8000/admin/home
authenticated /admin/home -> HTTP 200
VERDICT: legitimate login still works after the window resets.
```

Pinned against regression by `tests/Feature/AdminLoginThrottleTest.php` (4
tests), which asserts the route middleware, that the 4th attempt is refused,
that a correct password works once the window clears, and that the customer
login is *deliberately* still at 10.

---

## 4. Password reset flow

### 4a. The verification code is printed on screen (hardened)

**Found.** The verification page renders a yellow banner containing the 6-digit
code in plain text:

> **Development mode:** email is not really being sent (`MAIL_MAILER=log`), so
> your code is **409560**.

Locally this is a convenience — with `MAIL_MAILER=log` nothing is delivered, so
without it the reset flow cannot be demonstrated at all. On a public server it
would be a **complete account takeover**: anyone who knows a staff or admin
email could request a reset and read the code straight off the page, never
touching that person's inbox. The email step would be verifying nothing.

**What the gate actually was.** Investigated as instructed rather than assumed.
The Blade views (`admin/partials/auth-layout.blade.php`,
`customer/partials/auth-shell.blade.php`) only check
`@if (session('dev_reset_code'))` — they carry no environment logic at all. The
real decision is in `HandlesPasswordReset::devVisibleCode()`, and it was
**already the two-condition check**:

```php
if (app()->environment('local') && config('mail.default') === 'log') {
```

So the requested hardening was already in place. Rather than reporting "nothing
to do", the review took three concrete steps:

1. **Proved the gate actually holds**, including the half-misconfigured cases,
   instead of trusting the code read (evidence below).
2. **Documented why it is a double check** in a comment at the gate itself, so a
   future edit does not "simplify" it into a single condition. It explains that
   either condition alone is treated as unsafe, that the point is for a
   half-misconfigured server to still be safe, and that it must not be swapped
   for `config('app.debug')`.
3. **Added the pre-launch checklist item to `DEPLOYMENT.md`** as a prominent
   warning callout, because the code cannot defend against both settings being
   wrong at once — only the deployment check can.

**Proof — the full truth table**, not a code read
(`tests/Feature/ResetCodeNotLeakedTest.php`, 5 tests, 26 assertions):

| `APP_ENV` | mailer | code on screen? | test |
|---|---|---|---|
| `local` | `log` | **yes** (dev convenience preserved) | passes |
| `local` | `smtp` | no | passes |
| `production` | `log` | no — *forgot `MAIL_MAILER`* | passes |
| `production` | `smtp` | no | passes |
| `staging` | `log` | no | passes |
| `testing` | `log` | no | passes |

Four of those drive the real `forgot-password` endpoint end to end and assert on
the rendered HTML (`Development mode` absent, `so your code is` absent); the
fifth calls `devVisibleCode()` directly to pin the whole table unambiguously.

Two traps were found and closed while writing that test, both of which would
have made it pass for the wrong reason:

* Overriding the environment away from `testing` **switches CSRF verification
  back on**, because `ValidateCsrfToken` skips itself via
  `$app->runningUnitTests()`, which is just `environment('testing')`. Every POST
  was returning 419, so "no code on the page" was proving nothing. The test now
  disables that middleware explicitly **and asserts the reset was actually
  accepted** (`assertRedirect(admin.verification)`) before it looks for the code.
* With `mail.default = smtp` the mailer tried to reach a real SMTP server and
  failed delivery, aborting the flow before the page rendered. The transport is
  now faked, so what is under test is the config value the gate reads.

**Local behaviour is unchanged** — confirmed live, not just in tests:

```
=== step 1: request a reset code for secreview-temp@invalid.local ===
  dev banner rendered: 1  (1 = shown; local only)
  code read straight off the page: 409560
```

### 4b. No password strength requirement anywhere (fixed)

**Found.** The reviewer set a real account's password to `123456` and to
`simon123` through the reset flow. Both were accepted, and both worked to sign
in immediately afterwards. There was no complexity requirement anywhere in the
application, and the rules had drifted apart between flows:

| Where | Rule before |
|---|---|
| Password reset (admin, staff **and** customer) | `min:6` |
| Customer registration | `min:6` |
| Customer account settings | `nullable`, `min:6` |
| Admin creating a staff account | `min:8` |
| Admin changing their own password | `min:8` |

The reset flow is the worst of these, because it is reachable **without being
logged in** and it covers admin and staff accounts.

**Fix.** One shared definition — `app/Support/PasswordPolicy.php` — used by all
five call sites, so the policy can only ever be changed in one place and no
future flow can quietly ship a weaker one. It wraps Laravel's own rule rather
than a hand-written regex:

```php
Password::min(8)->mixedCase()->numbers()->symbols()
```

`required()` is for flows where a password must be supplied; `optional()` is for
the customer account-settings form, where blank still means "keep my current
password" and only a non-empty value is validated.

**The messages name what is missing**, which was the point of using the built-in
rule. Actual output:

```
'123456'     -> must be at least 8 characters. | must contain at least one
                uppercase and one lowercase letter. | must contain at least one symbol.
'simon123'   -> must contain at least one uppercase and one lowercase letter.
                | must contain at least one symbol.
'12345678'   -> must contain at least one uppercase and one lowercase letter.
                | must contain at least one symbol.
'abcdefgh'   -> ... uppercase and lowercase ... | ... symbol. | ... number.
'Abcdefgh'   -> must contain at least one symbol. | must contain at least one number.
'Abcdefg1'   -> must contain at least one symbol.
'Staff123!'  -> ACCEPTED
```

**Verified live end to end**, reproducing exactly what the reviewer did by hand —
request a code, read it off the banner, verify it, then try to set a weak
password on a real admin account (`tests/Security/reset-flow-e2e.sh`):

```
=== step 3: the passwords the reviewer got accepted BEFORE the fix ===
  '123456'     password UNCHANGED
  'simon123'   password UNCHANGED
  '12345678'   password UNCHANGED
  'abcdefgh'   password UNCHANGED

=== step 4: a compliant password (Reset123!) ===
  -> HTTP 302 http://127.0.0.1:8000/admin/login
  hash changed: YES

NEW password Reset123! -> HTTP 302 http://127.0.0.1:8000/admin/home
authenticated /admin/home -> HTTP 200
```

So the weak passwords are refused *and the stored hash is untouched*, the
compliant one is accepted, and it genuinely works to sign in afterwards.

**Every one of the five flows is covered** by
`tests/Feature/PasswordPolicyTest.php` (13 tests, 47 assertions) — each checked
twice, weak rejected and compliant accepted-and-usable:

| Flow | weak rejected | compliant accepted |
|---|---|---|
| Customer registration | ✅ (incl. `123456`, `simon123`) | ✅ hash verified |
| Customer account settings | ✅ old password survives | ✅ |
| Customer account settings, left blank | — | ✅ still means "keep current" |
| Admin creating a staff account | ✅ no account created | ✅ hash verified |
| Admin changing own password | ✅ old password survives | ✅ |
| Password reset — admin | ✅ all three reviewer passwords | ✅ |
| Password reset — customer | ✅ | — |

### 4c. Verification code lifetime (tightened)

**Changed.** `HandlesPasswordReset::$resetCodeLifetime` from **15 to 10
minutes**. Still generous for someone switching to their mail app and back, and
it shortens the window in which an intercepted or shoulder-surfed code is
usable. The email template renders this value (`{{ $minutes }}`), so the user-facing
wording followed automatically with no second place to update.

**Verified** by `tests/Feature/ResetCodeLifetimeTest.php` (7 tests, 34
assertions), which pins the boundary from both sides by ageing the token row
rather than sleeping:

* the configured lifetime is 10;
* a code **9 minutes** old is still accepted and reaches the new-password step;
* a code **11 minutes** old is refused;
* a code **12 minutes** old — which *would* have worked under the old 15-minute
  window — is now refused, which is the actual behaviour change;
* an expired token row is deleted rather than left lying around;
* **Resend** issues a different code, the stale one is dead, and the fresh one
  works on its own window;
* the email says "expires in 10 minutes" and no longer says 15.

---

## Verification and test data

The full suite passes: **401 tests, 1991 assertions** (371 before this pass, plus
the 30 added here). No existing test regressed under the stricter password rules.

Test data used the bounded high-water-mark pattern from
[`LOAD_TEST_REPORT.md` §11.6](LOAD_TEST_REPORT.md). Marks were captured before
anything ran (`users` max id 13, `password_reset_tokens` 0); the one temporary
account created for the live login and reset tests
(`secreview-temp@invalid.local`) was removed afterwards by
`tests/Security/cleanup.sh`, which verifies its own work and exits non-zero if
anything is left:

```
deleting accounts created above id 13 ...
  - id=59 secreview-temp@invalid.local (admin)
verifying...
  ok  users > 13                               0 remaining
  ok  test accounts (*.invalid.local)          0 remaining
  ok  reset tokens for test accounts           0 remaining
  ok  orphaned reset tokens                    0 remaining
cleanup done - verified zero rows above the high-water mark
```

The four pre-existing accounts are untouched and `password_reset_tokens` is back
to 0. The feature tests all use `DatabaseTransactions`, so they roll back.

**One note on live data:** the database also contains order `5966`
(`ORD-20260831-UJYGAE`, dine-in, cash, "roasted chicken", pending), placed about
two hours before this review session began. It is **not** test data from this
pass — this pass created no orders at all — and it has deliberately been left
alone. It is presumably from the reviewer's own manual testing that morning, and
it is currently the one order showing on the Active Orders board.

---

## Files changed in this pass

| File | Change |
|---|---|
| `app/Support/PasswordPolicy.php` | **New.** Single shared password rule (8+, mixed case, number, symbol) |
| `app/Http/Controllers/Concerns/HandlesPasswordReset.php` | Reset now uses `PasswordPolicy`; code lifetime 15 → 10; `devVisibleCode()` documented as a deliberate double check |
| `app/Http/Controllers/Admin/AdminController.php` | `PasswordPolicy` on staff creation and on admin's own password change |
| `app/Http/Controllers/Customer/AuthController.php` | `PasswordPolicy` on registration and account settings |
| `routes/web.php` | Admin/staff login `throttle:10,1` → `throttle:3,1`, with the keying rationale recorded |
| `docs/DEPLOYMENT.md` | Pre-launch warning callout for `APP_ENV` / `APP_DEBUG` / `MAIL_MAILER` |
| `tests/Feature/PasswordPolicyTest.php` | **New.** 13 tests across all five password-setting flows |
| `tests/Feature/ResetCodeNotLeakedTest.php` | **New.** 5 tests pinning the dev-banner truth table |
| `tests/Feature/ResetCodeLifetimeTest.php` | **New.** 7 tests pinning the 10-minute window |
| `tests/Feature/AdminLoginThrottleTest.php` | **New.** 4 tests pinning the 3/min login limit |
| `tests/Security/*.sh` | **New.** Live HTTP scripts for the throttle, login recovery and reset flow, plus bounded cleanup |

---

# Pass 3 — Manual security review, 2026-08-31

Two items, both carried over from Pass 2. Item 1 is a browser-console crash
found *during* the Pass 2 IDOR retest (§2 of Pass 2) rather than by the IDOR
test itself. Item 2 is the question Pass 2 explicitly left open: Pass 2 proved
the reset code is never *exposed*, which says nothing about whether it can be
*guessed*.

| # | Area | Result |
|---|------|--------|
| 1 | `switchTab` TypeError on the customer Orders page | **Real bug — fixed** |
| 2 | Reset code brute-force resistance | **Real gap under rotating IPs — fixed** |

---

## 1. `switchTab` threw a TypeError for every guest (fixed)

**Reported.** Clicking the "Current Order" tab on `/customer/orders` produced,
in the browser console:

```
Uncaught TypeError: Cannot read properties of null (reading 'style')
    at switchTab (orders:1407:50)
```

### Root cause, established before any change

`switchTab()` in `resources/views/customer/orders.blade.php` dereferenced both
tab panels unconditionally:

```js
document.getElementById('statusTab').style.display  = index === 0 ? 'block' : 'none';
document.getElementById('historyTab').style.display = index === 1 ? 'block' : 'none';
```

**Which of the two lines?** V8 reports the column of the `.` in a member
access — confirmed by running a minimal reproduction under node, which reported
the dot's column, not the expression's. In the source those dots sit at column
**49** (`statusTab`) and column **50** (`historyTab`). The reported column 50
therefore pins the failure on the `#historyTab` line specifically.

`#historyTab` is rendered conditionally, wrapped in
`@if(Auth::guard('customer')->check())`, because a guest has no order history —
`OrderController::showOrders()` says so outright in its guest branch ("A guest
has no History tab"). But the History **button** that targets that panel was
gated on an unrelated condition, `@if(session('order_type') !== 'dine_in')`.

The two conditions disagree for one very common state:

| State | `#historyTab` panel | History button | Result |
|---|---|---|---|
| Guest, no `order_type` in session | not rendered | **rendered** | **crash** |
| Guest, pick-up | not rendered | **rendered** | **crash** |
| Guest, dine-in | not rendered | not rendered | fine |
| Signed-in customer | rendered | rendered | fine |

Because the function touched `#historyTab` on *every* call, this broke **both**
tabs rather than only History: clicking "Current Order" set `#statusTab`
correctly on the line before, then threw — exactly as reported.

### The fix, in two layers

1. The History button is now gated on the same condition as the panel it
   reveals, so it is never offered without its panel.
2. `switchTab()` resolves both panels first, only assigns `.style` on panels
   that exist, and falls back to the first rendered panel — moving the active
   highlight with it via a new `data-tab-index` attribute — if the requested one
   is missing. It still switches and still shows the right thing rather than
   silently doing nothing.

### Verification

This page has no JS test framework (no jest, vitest or jsdom in
`package.json`), so the fix was verified two ways.

**a) The real function, executed against real rendered pages.** The six page
states were rendered through the actual application and dumped to HTML. A node
harness then extracted `switchTab` **verbatim from each dumped page** and ran
it against the tab elements that page actually contains — clicking every button
the page really rendered.

Against the pre-fix code:

```
=== guest-none.html ===   panels: [statusTab]   buttons: [switchTab(0), switchTab(1)]
    click switchTab(0) -> THREW: TypeError: Cannot read properties of null (reading 'style')
    click switchTab(1) -> THREW: TypeError: Cannot read properties of null (reading 'style')
=== guest-one.html ===    (same)
=== guest-many.html ===   (same)
6 FAILURE(S)
```

The harness reproduces the reported error message exactly, in all three
order-count states, on the "Current Order" click.

Against the fixed code:

```
=== guest-none.html ===   panels: [statusTab]   buttons: [switchTab(0)]
    click switchTab(0) -> no error; visible=[statusTab] activeButtons=1 OK
=== guest-one.html ===    (same)   === guest-many.html === (same)
=== cust-none/one/many.html ===  panels: [statusTab, historyTab]
    click switchTab(0) -> no error; visible=[statusTab]  activeButtons=1 OK
    click switchTab(1) -> no error; visible=[historyTab] activeButtons=1 OK
ALL OK
```

The defensive layer was then probed independently, by calling `switchTab(1)`
on a page where `#historyTab` genuinely does not exist:

```
FIXED code:     no error thrown; statusTab.display "block" (fell back);
                ghost button active? false; tab-0 button active? true
ORIGINAL code:  THREW: TypeError: Cannot read properties of null (reading 'style')
```

**b) A permanent regression test.** `tests/Feature/CustomerOrdersTabSwitchTest.php`
(10 tests) renders the page in all six states and pins the invariant that
actually prevents the crash: **a History button is present if and only if the
`#historyTab` panel is present.**

**One false positive was found and corrected in the test itself.** The first
version detected the History button by the `data-tab-index="1"` attribute the
fix introduced. Run against the pre-fix blade, every guest assertion passed —
vacuously, because the old markup carried no such attribute, so "no History
button" was true for the wrong reason. The detector now matches on
`switchTab(1,`, which is present in both the broken and the fixed markup. After
that correction, the pre-fix blade fails exactly the four broken states and
still passes `guest / dine-in`, which was never broken.

---

## 2. Reset code brute-force resistance (real gap — fixed)

The verification code is 6 digits: 1,000,000 possibilities, submitted through
an ordinary form. Pass 2 §4a proved it is never *displayed* in production. This
item asks whether it can simply be worked through.

### 2.1 The throttle actually in force

Identical on both portals, in `routes/web.php`:

| Portal | Route | Middleware |
|---|---|---|
| Customer | `POST /customer/verification` | `throttle:10,1` |
| Admin/staff | `POST /admin/verification` | `throttle:10,1` |

**Keyed by IP alone — not IP + session, and not the code.** Laravel's
`ThrottleRequests::resolveRequestSignature()` returns `sha1(domain|IP)` for an
unauthenticated request. Read from the vendor source, not assumed.

A second detail, found by measurement rather than by reading: the first 429
arrives on the **10th** verification attempt, not the 11th. The reason is that
`ThrottleRequests::handle()` uses an **empty key prefix** for unnamed limiters,
so every plain `throttle:X,Y` route shares one counter per IP — the
`POST /forgot-password` that starts the flow spends one of the same ten slots.
This makes the limit stricter in practice than its number suggests.

### 2.2 The math

* Code space: **1,000,000**
* Code lifetime: **10 minutes** (`$resetCodeLifetime`, unchanged since Pass 2 §4c)
* Single-IP ceiling: 10 requests/minute × 10 minutes = **≤ 100 guesses**, and
  in practice fewer because the counter is shared with the other reset steps

100 / 1,000,000 = **0.01%** of the search space, i.e. a 1-in-10,000 chance
against one issued code. Negligible **for one address**.

### 2.3 No information leaks from a wrong guess

Confirmed by submitting guesses at every distance from the real code — one
digit off, last two transposed, first digit off, same digits rotated, nothing in
common. All five produced the byte-identical response:

```
distinct wrong-code messages: ["That verification code is invalid or has expired. Please request a new one."]
```

One generic message, one status code, no partial-match hint, and deliberately
no distinction between "invalid" and "expired". Also confirmed: a wrong code and
a code that was never issued are indistinguishable, so the endpoint cannot be
used to discover whether a reset is in flight.

### 2.4 The gap: only the IP was limited, never the code

**This is the finding.** Nothing was recorded against the code itself. Wrong
guesses did not consume anything and did not invalidate anything, so an
attacker with a pool of addresses simply out-waited a limit that was never
counting them.

Measured against the real endpoint before the fix:

```
one IP, wrong codes         -> first 429 at attempt 10; token row still there: YES
rotating IPs, wrong codes   -> 60 of 60 guesses accepted, no 429 at all
                               token row still valid: YES
```

Sixty for sixty, with the code still live. Inside a 10-minute window that is
effectively unlimited guessing, and it needs no sophistication — just proxies.

### 2.5 The fix

`HandlesPasswordReset` now counts wrong guesses against the **email the code was
issued for** (`$resetCodeMaxAttempts = 5`) and **deletes the token row** once
that budget is spent. Because the code itself is destroyed, changing IP,
clearing cookies or starting a new session buys the attacker nothing.

|  | before | after |
|---|---|---|
| Single IP | ≤ 100 guesses per code | ≤ 5 guesses per code |
| Rotating IPs | **unbounded** | **≤ 5 guesses per code** |
| Odds against one code | approached certainty with enough IPs | **5 in 1,000,000** |

Design points, all deliberate:

* **Keyed on the email, hashed** (`sha1`), so the cache store — which is the
  database on this deployment — does not become a list of accounts mid-reset.
* **Burning the budget destroys the code, not the account.** This cannot be
  used to lock a real user out; they request a new code.
* **A new code restores a full budget**, because each code is an independent
  random draw, so guesses never accumulate across codes. Every re-issue also
  puts another email in the real owner's inbox, which makes a sustained attack
  loud.
* **The message is unchanged** whether the code was wrong, expired, or has just
  been burnt. Otherwise exhausting the budget would itself signal that the
  address is real and a code is live.
* **A malformed submission is not charged** as a guess, so a user fumbling the
  six input boxes is not penalised for something that reveals nothing.

### 2.6 Verification

`tests/Feature/ResetCodeBruteForceTest.php` — 12 tests, both portals. It pins
the throttle strings and the 10-minute lifetime, hammers the real endpoints
until they refuse, asserts the message-uniformity results in §2.3, and pins the
attempt budget and its key.

**Checked for false positives before being trusted**, in two ways:

1. **The lockout is proved by the correct code, not by a missing row.** "Wrong
   guesses kill the code" means nothing unless the *right* code is then
   rejected — and that in turn means nothing unless the right code is *accepted*
   within budget. Both directions are asserted, the second by an explicit
   control test. Without it, a change that simply broke the reset flow entirely
   would have passed.
2. **The suite was re-run against a simulated pre-fix state** (attempt budget
   raised to 1,000,000, leaving only the IP throttle). Exactly the four
   fix-dependent tests failed and the eight that describe unchanged behaviour —
   the IP throttle and all the message-uniformity checks — still passed.

### 2.7 Remaining decision point, stated rather than left silent

Five guesses per code is a per-code limit, not a per-account one. An attacker
who repeatedly triggers `POST /forgot-password` gets a fresh code and a fresh
budget of 5 each time. That is the correct trade — the alternative locks real
users out of their own reset — and it is not quiet: every attempt sends another
email to the real owner's inbox, and `forgot-password` is itself `throttle:6,1`.
Sustained abuse would be visible to the account owner as a flood of reset
emails. **If that is ever reported, treat it as an attack in progress**, not as
a mail bug.

---

## Verification and test data

The full suite passes: **442 tests, 2147 assertions** (401 tests / 1991
assertions at the end of Pass 2, plus the 41 tests added here). Zero failures.
No existing test regressed.

| New test file | Tests |
|---|---|
| `tests/Feature/CustomerOrdersTabSwitchTest.php` | 10 |
| `tests/Feature/ResetCodeBruteForceTest.php` | 12 |
| `tests/Feature/DeployCheckTest.php` | 19 |

Test data used the bounded high-water-mark pattern. Marks were captured before
anything ran and re-verified afterwards:

```
  ok   orders           above mark 5966   = 0     max_id=5966   count=118
  ok   order_items      above mark 3957   = 0     max_id=3957   count=184
  ok   users            above mark 13     = 0     max_id=13     count=4
  ok   notifications    above mark 1614   = 0     max_id=1614   count=62
  ok   order_ratings    above mark 273    = 0     max_id=273    count=7
  ok   reset tokens     0 rows
  ok   order 5966 preserved: ORD-20260831-UJYGAE / pending / dine_in

CLEANUP VERIFIED - zero rows above every high-water mark
```

Every row count is identical to the pre-work snapshot. All feature tests use
`DatabaseTransactions`, so they roll back; nothing needed manual deletion.

Order `5966` (`ORD-20260831-UJYGAE`) is the reviewer's own manual test order
from Pass 2 and was again deliberately left alone. No `.env` file was modified:
the production-config run of `deploy:check` was done with shell environment
variables, leaving the file byte-for-byte untouched.

---

## Files changed in this pass

| File | Change |
|---|---|
| `resources/views/customer/orders.blade.php` | `switchTab()` made null-safe with a fallback; History button re-gated to match its panel; `data-tab-index` added to both tab buttons |
| `app/Http/Controllers/Concerns/HandlesPasswordReset.php` | **New per-code wrong-guess budget** (`$resetCodeMaxAttempts = 5`) keyed on the hashed email; burnt codes are deleted; budget cleared on success, on re-issue and on reset completion |
| `app/Console/Commands/DeployCheck.php` | **New.** `php artisan deploy:check` — PASS/FAIL per deploy-critical setting, ending in `READY TO GO LIVE` or `NOT READY` |
| `.env.production.example` | **New.** Every production setting with a placeholder and an inline comment saying where the value comes from |
| `docs/DEPLOYMENT.md` | Project-context block; AI/secret-sharing warning; deploy day rewritten as a literal numbered sequence gated on `deploy:check`; new "Updating the system after it is already live" section; storage-placeholder status corrected (see below) |
| `tests/Feature/CustomerOrdersTabSwitchTest.php` | **New.** 10 tests pinning the tab button/panel invariant |
| `tests/Feature/ResetCodeBruteForceTest.php` | **New.** 12 tests across both portals |
| `tests/Feature/DeployCheckTest.php` | **New.** 19 tests, including every check broken individually |

### Also found while verifying the deployment documents

Two things were re-measured rather than taken from the existing text, and one
did not hold:

* **`DEMO_SALES_AUTO_TOPUP=true` is set in the working `.env`.** `deploy:check`
  flags it. It already fails closed (it additionally requires `APP_ENV=local`),
  so it is not a live risk, but it must be `false` on the server — as Pass 2's
  §7d already said.
* **The `storage/` placeholder fix recorded in `DEPLOYMENT.md` §4 was never
  actually committed.** That section states "Verified `git ls-files storage`
  now lists them." Re-measured: `git ls-files storage` returns **0 files** and
  `git status --porcelain storage` returns `?? storage/`. The nine placeholder
  files exist on disk and are not being excluded — `git check-ignore` confirms
  the ignore rules are correct — they are simply still untracked. **A fresh
  clone on the server would therefore still fail on its first request** with
  "Please provide a valid cache path". This affects the first deploy only, not
  updates to a working install. `DEPLOYMENT.md` §4 has been corrected to say so
  and now carries both ways to close it; it is **still open** and needs a commit
  before deploy day.

---

# Pass 4 — Manual security review, 2026-08-31

One item, from the manual checklist: **#10, customer identity documents served
from a public path**. It was already named in `DEPLOYMENT.md` §7a, which
recommended the fix and explicitly did not action it.

| # | Area | Result |
|---|------|--------|
| 10 | PWD/Senior ID uploads readable without authentication | **Confirmed live — fixed** |
| 10b | 60 orphaned ID uploads, ~70 MB | **Removed, with one genuinely sensitive document found** |

---

## 10. Identity documents were served to anyone, with no authentication

### Proven before anything was changed

A dev server was started and the exposure reproduced from a session that was
not logged in at all — no cookies, no session, no credentials:

```
$ curl -s -D- http://127.0.0.1:8123/storage/discount_ids/<40-char-name>.png
HTTP/1.1 200 OK
Content-Type: image/png
Content-Length: 1299756
```

The body was byte-identical to the file on disk — same MD5,
`a2bc7bf94568fe082d3d60c39f9bcddc`. `public/uploads/ids/` was confirmed
identically exposed (HTTP 200, matching MD5).

**That is the finding.** There was no authorisation of any kind on a customer's
identity document.

### Why Laravel never even ran

`OrderController::placeOrder()` stored the upload on the **`public` disk**,
which `config/filesystems.php` roots at `storage/app/public` and exposes
through the `public/storage` symlink. `public/.htaccess` then sends a request
straight to any file that exists:

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```

An existing file fails that condition, so the request never reaches
`index.php`. No middleware, no guard, no controller — Apache simply handed over
the file.

### What made it reachable in practice

Filenames are 40 random characters, so they cannot be brute-forced, and
directory listing is off (verified: `/storage/discount_ids/` returns 404). That
is the one mitigating factor and it is worth stating honestly.

But the admin order board rendered the URL straight into the page:

```php
$modalDiscountImage = asset('storage/' . $order->discount_id_image);
```

which put it into `data-image="..."` in the HTML. Every such URL therefore
reached page source, browser history, and any screenshot of the order board.
**The URL was the credential**: once one leaked, it granted permanent,
unauthenticated access to that person's document, with no way to revoke it
short of deleting the file.

### The fix

Three changes, and the third is the one that matters most:

1. **Uploads moved to the `local` disk** (`storage/app`), which is not
   web-reachable at all.
2. **Served only through `/discount-id/{order}`**, a new route backed by
   `App\Http\Controllers\DiscountIdController`, which authorises every request
   through `App\Services\DiscountIdAccess`.
3. **The URL carries an order id, never a filename.** This is deliberate. A
   leaked URL is now worthless on its own, because every request is
   re-authorised against the session making it — and path traversal becomes
   structurally impossible rather than filtered, since the client never
   supplies a path.

The admin order board now calls `route('discount-id.show', $order->id)`.

### Who may view an ID document, and why

Stated plainly, because it is a judgement call rather than an obvious one:

| Viewer | Allowed | Reasoning |
|---|---|---|
| **Staff and admin** on the `admin` guard, `is_active` | **Yes** | Verifying the document *is* the feature. The order board's discount modal exists so staff can approve or reject a claimed PWD/Senior discount, and they cannot do that without seeing the ID. Admin-only would break the job staff are there to do, so both roles are allowed — matching the `role:admin,staff` gate on the order board itself. |
| **The customer who uploaded it** | **Yes** | It is that person's own identity document. Allowing staff to read it while refusing the person it belongs to is hard to justify on any data-protection footing. It adds no new exposure either: the same ownership rule already governs their receipt, which carries the beneficiary name and card number — comparable personal data about the same person. |
| **The guest session that placed the order** | **Yes** | Same rule, already handles guests: the order must be one *this session* placed AND a genuine guest order. |
| A different customer | No | |
| An unauthenticated visitor | No | |
| A deactivated staff account | No | `is_active` is re-checked at request time, not trusted from the session — a staff account can be deactivated while its session is still alive. |

Ownership resolves through `CustomerOrderAccess::resolveOwnedOrder()` rather
than a second hand-rolled rule. That is the specific mistake
`CustomerOrderAccess` was created to prevent: the rating endpoint previously
hand-rolled its own weaker check and ended up locking guests out entirely.

### Every refusal is a 404, never a 403

Matching the precedent set by `resolveOwnedOrder()` in Pass 1 §2. A 403 would
confirm that the order exists *and* that it carries a discount ID document —
which is itself information about a stranger, since it says a real person
claimed a PWD or Senior discount on that order. A 404 makes "you may not see
this", "there is no such order" and "that order has no ID attached"
indistinguishable from outside.

The same 404 covers a row whose file has been deleted (a hand-cleared storage
directory, a partial restore), which must never surface as a 500.

### Verification

`tests/Feature/DiscountIdAccessTest.php` — 15 tests, 50 assertions, covering
each allowed viewer, each refused viewer, a missing order, an order with no
document, a row pointing at a deleted file, four traversal payloads planted
directly in the database, and the cache headers.

Confirmed live after the change:

```
/discount-id/5966      -> HTTP 404   (anonymous)
/discount-id/999999    -> HTTP 404   (anonymous)
/discount-id/../.env   -> HTTP 404   (anonymous)
```

**False-positive discipline.** "The file was not served" passes just as happily
when the route is broken, misspelled, or 404s for everybody. So every refusal
assertion is paired with a positive control proving the *same order* and the
*same file* are served to someone who is allowed. That was then tested by
disabling the route and re-running: **8 of 15 tests failed**, including both
refusal tests — because their embedded controls failed — and the dedicated
`test_the_authorised_cases_actually_work_control`. A broken route cannot pass
this file.

One more trap worth recording: the fixture originally used
`UploadedFile::fake()->image()`, which needs the GD extension. GD is not
enabled here, so it threw. It was replaced with the real bytes of a 1×1 PNG
rather than skipping — a test that silently skips when an unrelated extension
is missing is a test that has stopped guarding anything.

---

## 10b. The 60 orphaned uploads — and one that was not test data

`storage/app/public/discount_ids/` held **60 files, ~70 MB**. Re-verified
before deleting anything: **zero** database rows referenced any of them, cross
-checking every filename on disk against both `orders.discount_id_image` and
`discount_cards.id_image`. In fact no row in either table held any discount
image path at all.

They were opened and looked at rather than assumed harmless — which turned out
to matter. Only **nine unique images** across the 60 files:

| Copies | What it is |
|---|---|
| 55 | Stardew Valley game screenshots (five farm layouts) |
| 2 | A blank 1×1 PNG, 70 bytes |
| 1 | A gym advertising poster |
| 1 | An anime screenshot |
| **1** | **A real notarised Secretary's Certificate** |

### The one real document

One file was a genuine, notarised legal document for a named Philippine
company, carrying two individuals' full names and handwritten signatures, a
company address, and the notary's full name, roll number, PTR number, MCLE
compliance number and residential address.

It is not a customer's document and not a PWD or Senior ID — it was almost
certainly a team member testing the discount upload with a document they had to
hand. But it is real personal data about at least three identifiable people,
one of whom (the notary) has no connection to this project, and it was sitting
behind an unauthenticated URL.

**Action needed: tell the team member who uploaded it.** They should know it was
published, and it is worth saying plainly that real documents must not be used
to test an upload form.

This also corrects the record. `DEPLOYMENT.md` §7a previously described these
60 as ID photographs and the five in `public/uploads/ids/` as "PWD/Senior ID
card photographs". Both were wrong in the same direction — §7a has been
rewritten to say what is actually there, because overstating a finding is not a
safe error either: it buries the one file that genuinely mattered in a pile of
Stardew Valley screenshots.

### Cleanup

Bounded, with the manifest recorded before deletion (filenames, sizes and MD5s
— not content):

```
files before: 60          size before: 70M
files after:   0          size after:   0
directory still exists: yes
rows still pointing at a discount image: 0
```

And the previously-exposed URL, re-requested live after the deletion:

```
GET /storage/discount_ids/<name>.png  -> HTTP 404
```

Worth stating explicitly: **the code fix alone did not close the exposure.** It
stops new uploads reaching the public disk, but the 60 existing files stayed
readable until they were deleted — confirmed by re-running the original curl
after the code change and still getting HTTP 200. The cleanup was part of the
fix, not housekeeping.

Only the GCash QR codes remain on the public disk, which is correct — customers
scan them to pay.

---

## Verification and test data

The full suite passes: **457 tests, 2197 assertions** (442 / 2147 at the end of
Pass 3, plus the 15 added here). Zero failures.

Test data used the bounded high-water-mark pattern. Marks captured before, and
re-verified after:

```
  ok   orders           above mark 5966   = 0     max_id=5966   count=118
  ok   order_items      above mark 3957   = 0     max_id=3957   count=184
  ok   users            above mark 13     = 0     max_id=13     count=4
  ok   notifications    above mark 1614   = 0     max_id=1614   count=62
  ok   order_ratings    above mark 273    = 0     max_id=273    count=7
  ok   order 5966 preserved: ORD-20260831-UJYGAE / pending / dine_in

CLEANUP VERIFIED - zero rows above every high-water mark
```

The feature tests use `DatabaseTransactions`, so their rows roll back. The
fixture images they write to `storage/app/discount_ids/` are deleted in
`tearDown()`, which only ever removes a path it created and only inside that
directory.

Order `5966` was again left untouched.

---

## Files changed in this pass

| File | Change |
|---|---|
| `app/Services/DiscountIdAccess.php` | **New.** The single rule for who may view an ID document, and why each refusal is a 404 |
| `app/Http/Controllers/DiscountIdController.php` | **New.** Serves the document by order id, never by filename; prefix allowlist, image-type pin, no-store cache headers |
| `routes/web.php` | **New** `GET /discount-id/{order}`, throttled, outside both guard groups because two different guards may legitimately view |
| `app/Http/Controllers/Customer/OrderController.php` | Discount ID uploads now go to the `local` disk instead of `public` |
| `resources/views/admin/home.blade.php` | Order board points at the authorising route instead of a direct public storage URL |
| `storage/app/public/discount_ids/` | Emptied — 60 orphaned files, ~70 MB, zero referenced |
| `tests/Feature/DiscountIdAccessTest.php` | **New.** 15 tests, every refusal paired with a positive control |
| `tests/Feature/CartTotalsMatchCheckoutTest.php` | `Storage::fake()` now covers the `local` disk too — see below |
| `docs/DEPLOYMENT.md` | §7a rewritten to what was actually found; §0 row 8 corrected |
| `docs/BLANK_SLATE_RESET.md` | Inventory corrected — `discount_ids/` now empty; ids/ described accurately |
| `docs/RECOVERY_PLAN.md` | The orphaned-ID finding marked closed |

### A regression this pass caused, and caught

`CartTotalsMatchCheckoutTest` places a real order with a real upload, and it
already called `Storage::fake('public')` precisely so the suite would not leave
a file behind on every run. Moving the upload to the `local` disk silently
walked around that fake, and the suite started leaking real files again —
noticed as two stray 78-byte PNGs in `storage/app/discount_ids/` after a full
run, not by any assertion.

Both disks are faked now, and a full run afterwards left **zero** files in
either directory. This is also the most likely explanation for how the 60
orphans accumulated in the first place: the suite had been writing a real file
per run into the public directory before that fake was added.

### Still open

1. **`public/uploads/ids/` — five files, still on disk and still publicly
   served.** Test uploads, not real IDs, zero database references. Recommended
   for deletion; left in place because removing them was outside this item's
   scope.
2. **`DuckonSteroidzz/POMIDA` is still public** and still contains those five
   files in its single commit. Outside this team's control — the owner of that
   repository has to make it private.
3. **Tell whoever uploaded the notarised certificate.**

---

# Pass 5 — Manual security review, 2026-09-01

Four checklist items that cannot be tested meaningfully from a browser and
needed source inspection: **#12 mass assignment**, **#13 session fixation**,
**#20 SQL injection**, **#21 CSRF**. Items #11 and #14–19 are being tested
manually by the owner and are not covered here.

**Three of the four came back clean.** The one finding is not in the
application at all — it is that two of the tests written in this pass were
initially green for the wrong reason, and would have certified protections that
were not being measured.

| # | Area | Result |
|---|------|--------|
| 12 | Mass assignment / privilege escalation | **Clean** — Pass 1 §4 confirmed still holding, now pinned by tests |
| 13 | Session fixation | **Clean** — all five transitions regenerate |
| 20 | SQL injection | **Clean** — no injection path exists |
| 21 | CSRF | **Clean** — nothing has switched it off |

---

## 12. Mass assignment — clean, confirming Pass 1 §4

### Relationship to Pass 1

Pass 1 §4 tested this by hand against a running server and found no mass
assignment issue. **That result still holds.** What Pass 1 did not leave behind
was any standing test, so a regression would have been silent. Pass 5 adds
those.

### The model audit — every privileged column is mass-assignable

All 23 Eloquent models declare `$fillable`; none is unprotected. But `$fillable`
is **not** what protects this application, and it is worth being explicit about
that:

| Model | Privileged columns that ARE in `$fillable` |
|---|---|
| `User` | `role`, `is_active`, `points`, `verified_at` |
| `Order` | `status`, `payment_status`, `total`, `discount_amount`, `discount_status`, `processed_by`, `receipt_number` |
| `DiscountCard` | `is_verified`, `verified_by`, `is_active` |
| `Voucher` | `is_active`, `max_uses` (`used_count` is in `$fillable` but is never written from a request) |
| `UserVoucher` | `is_used` |

Every column that grants power or value is mass-assignable. If a raw request
array ever reached one of these models, all of it would be settable from a form.

### What actually protects it

Re-verified across `app/` with fresh eyes and a wider pattern set than Pass 1
recorded:

* **zero** occurrences of `$request->all()`
* **zero** occurrences of `$request->except()`
* **zero** occurrences of `create()`, `update()`, `fill()`, `forceFill()`,
  `updateOrCreate()`, `firstOrCreate()` or `insert()` taking a request array
* all **eight** `forceFill()` call sites use hardcoded literal keys
* the one `update($validated)` (`AdminController::updateInventory`) passes an
  array whose keys are exactly the eight inventory fields that have validation
  rules — `branch_id` and `is_active` have no rule, so `validate()` drops them

Writes assign either explicit literals or individually-named validated fields.
The high-value ones:

```php
// AdminController::storeUser — cannot mint an admin
'role'      => 'staff',   // never anything else
'is_active' => true,

// AuthController::register — cannot grant itself anything
'role' => 'customer',  'is_active' => true,

// OrderController::placeOrder — money computed server-side
'total' => $finalTotal,  'status' => 'pending',
```

**That is a convention, not a mechanism.** One future
`->update($request->all())` re-opens all of it and no model would stop it.
`test_no_controller_hands_a_raw_request_array_to_a_model()` is the thing that
would notice.

### The escalation attempts

`tests/Feature/MassAssignmentEscalationTest.php` — 9 tests. Each smuggles
privileged fields into a real request and asserts **both** that the field did
not change **and** that the request otherwise succeeded:

| Attempt | Smuggled | Result |
|---|---|---|
| Registration | `role=admin`, `is_active=1`, `points=99999`, `verified_at` | account created as `customer`, 0 points, unverified |
| Account settings | `role=admin`, `points=99999`, `is_active=0` | name updated, role and points unchanged |
| Place order | `total=1`, `discount_amount=9999`, `status=completed`, `payment_status=paid`, `receipt_number`, `processed_by` | charged the real price, `pending`, unpaid, `processed_by` null |
| Place order with a real PWD claim | `discount_status=approved` | server still said `pending` — staff verification not skippable |
| Staff creating an admin | `role=admin` | no user created at all |
| **Admin** creating staff | `role=admin`, `points=99999` | created as `staff`, 0 points |
| Wheel points endpoint (Pass 1 §4's real finding) | `points=99999` | HTTP 422, balance unchanged |

The controls earned their place immediately: the first run failed three tests
because the account route is `PUT` not `POST`, the staff form needs
`password_confirmation`, and `place-order` needs a session cart. Each failure
said "the request did not succeed, so this proves nothing" rather than passing
silently.

### One thing deliberately not asserted

`discount_status` comes back `approved` on an order with **no** discount, and
that is correct rather than smuggled: `placeOrder()` initialises
`$discountStatus = 'approved'` as the default when there is nothing to verify,
and `discount_amount` is 0. The field only carries authority on an order that
actually claims a discount — which is why there is a separate test that posts a
real PWD claim with `discount_status=approved` smuggled and asserts the server
still says `pending`.

---

## 13. Session fixation — clean

### The five transitions

All confirmed by reading the source, not assumed:

| Transition | Call | Location |
|---|---|---|
| Customer login | `session()->regenerate()` | `AuthController` ~970 |
| Customer registration | `session()->regenerate()` | `AuthController` ~886 |
| Admin / staff login | `session()->regenerate()` | `AdminAuthController` ~105 |
| Customer logout | `invalidate()` + `regenerateToken()` | `AuthController` ~1010 |
| Admin logout | `invalidate()` + `regenerateToken()` | `AdminAuthController` ~136 |

Registration was *believed* to regenerate already. It does, and it is now
asserted rather than trusted.

### THE FINDING OF THIS PASS: the first version of these tests was worthless

The obvious test — call `session()->getId()` before and after a login, assert it
changed — was written, ran green on all six transitions, and **proved nothing**.

`phpunit.xml` sets `SESSION_DRIVER=array`. With the array driver the test
harness begins a **brand new session on every request**, so the id always
differs, whether or not the application regenerated anything. Measured:

```
id after request 1: fu5ePWsa7beEHZ8vAidMrbJintaHX218oTG4Nlkl
id after request 2: ibBHqI74pXVXyguCdtJwCB4vrXgUfPLOUOfWW5jc
same? NO        <- on two identical, non-authenticating GETs
```

Those six tests would have passed against an application with no fixation
protection at all. **The negative control is the only reason this was caught**
— it asserted the id was STABLE across an ordinary GET, and failed.

The tests were rebuilt to drive the session the way a browser does: the driver
is switched to `database` (which is also what production uses, and whose rows
`DatabaseTransactions` rolls back), and the session cookie is read out of each
response, decrypted, and sent back on the next request with `withCookie()` —
which re-encrypts it, so encryption stays on in both directions exactly as in
production. With that harness:

```
ordinary GET, cookie replayed -> id STABLE   (negative control passes)
login,        cookie replayed -> id CHANGED
```

### Checking the rebuilt tests for false positives

Removing `session()->regenerate()` from the customer login and registration
controllers did **not** break the tests. That is not a test failure — it is a
real finding about the application:

`SessionGuard::updateSession()` calls `$this->session->migrate(true)`
internally on every successful `attempt()` and `login()`. So regeneration on
login is **two independent layers**: Laravel's own guard, and the controllers'
explicit call. Removing the application-level one leaves the framework-level one
still doing the job.

Logout is different — `SessionGuard::logout()` does not migrate, so the
controller's `invalidate()` is the load-bearing call there. Removing it made
`test_customer_logout_...` fail, and only that one, while the admin logout test
kept passing (only the customer controller was sabotaged). That confirms the
measurement is real and specific.

For the login tests, the negative control is the false-positive proof: the same
measurement method reports "unchanged" for a non-login and "changed" for a
login, so the assertion is not tautological.

### Session cookie configuration

| Setting | Value | Assessment for a café POS |
|---|---|---|
| `http_only` | **true** | Correct. Injected JavaScript cannot read the session cookie. |
| `same_site` | **lax** | Correct. A cross-site form POST will not carry the session cookie — a second layer underneath the CSRF token. |
| `secure` | unset locally, **true** in production | Correct as configured. `.env.production.example` sets `SESSION_SECURE_COOKIE=true` and `php artisan deploy:check` FAILs if it is not — the right place for an environment-dependent setting. |
| `encrypt` | false | Acceptable. Session payloads live in the `sessions` table, which is not web-reachable. |
| `driver` | database | Correct for Hostinger shared hosting — no Redis, no persistent worker. |
| `lifetime` | 120 minutes | **Not changed, as instructed** — the team has an open decision on idle timeout. Worth noting for that decision: 120 minutes of inactivity on a terminal that sits on a public counter is a long time for a signed-in staff session to stay usable to anyone who walks behind it. `expire_on_close` is false, so closing the browser does not end it either. |

---

## 20. SQL injection — clean, with evidence

### Every raw construct, enumerated

25 occurrences across `app/`, `database/` and `routes/`:

* **23 are constant literal SQL with no variable content at all** —
  `DB::raw('SUM(quantity) as total_qty')`, `whereRaw('1 = 0')`,
  `whereRaw('0 = 1')`, `orderByRaw('LENGTH(table_number), table_number')`,
  `selectRaw('DATE(completed_at) as d')`, `DB::raw('max_uses')`,
  `DB::raw('HOUR(created_at) as hour')`, and the DDL `DB::statement()` calls in
  two migrations. Nothing user-supplied can reach them because nothing at all
  is substituted into them.

* **2 receive user input**, both using a `?` placeholder with a bindings array:

  ```php
  // HandlesPasswordReset::resolveResettableUser()
  User::whereRaw('LOWER(email) = ?', [strtolower(trim($email))])

  // TableOccupancy — dine-in table matching
  ->whereRaw('UPPER(TRIM(table_number)) = ?', [strtoupper(trim(...))])
  ```

### The worked example: is that binding real, or does it only look real?

Settled by capturing what Laravel actually sends, with `DB::listen`, for four
different inputs to `resolveResettableUser()`:

```
input: pedro@gmail.com
input: x' OR '1'='1
input: x'; DROP TABLE users; --
input: x' UNION SELECT * FROM users --

SQL for ALL FOUR — byte-identical:
  select * from `users` where LOWER(email) = ? and `role` in (?)
    and `is_active` = ? limit 1

BINDINGS: ["x'; drop table users; --", "customer", true]     (etc.)
```

The payload never enters the SQL text. It appears only in the bindings array,
which PDO transmits separately as a prepared-statement parameter, so the
database never parses it as SQL. **That is genuine parameterisation, not
cosmetic.** The `users` table was still present afterwards (4 rows, unchanged).

### Column names and sort direction — what binding does NOT protect

Checked separately, because a placeholder cannot stand in for an identifier:

* no `orderBy($variable)` anywhere; every `orderBy` names a literal column
* no request value is used as a column name or a sort direction
* **every raw SQL string in the codebase is single-quoted**, so PHP string
  interpolation cannot occur inside one
* **zero** occurrences of concatenation building a raw SQL string

### Verification

`tests/Feature/SqlInjectionTest.php` — 7 tests, 82 assertions. Seven payloads
(`x' OR '1'='1`, `x'; DROP TABLE users; --`, `x' UNION SELECT * FROM users --`,
`' OR 1=1 --`, `admin'--`, an escaped variant, and a stacked `DELETE`) driven
through `/customer/forgot-password`, `/admin/forgot-password`, both login
forms, and the dine-in table lookup. Tables intact, no 500s, no tautology ever
resolved an account or authenticated a session.

Plus two structural guards, which are the ones that matter long-term:
`test_no_raw_sql_string_is_built_from_a_variable()` and
`test_no_order_by_takes_a_variable_column_or_direction()`.

**False-positive check.** A deliberately vulnerable file was planted:

```php
DB::table('users')->whereRaw("email = '" . $email . "'")->orderBy($sort)
```

Both structural guards failed and named the file. Removed afterwards.

---

## 21. CSRF — clean

### Nothing has switched it off

* `bootstrap/app.php`'s `withMiddleware()` registers three aliases and one
  priority-list tweak. **No CSRF configuration of any kind.**
* No `$except` array, no `validateCsrfTokens(except: ...)`, no
  `withoutMiddleware()`, no `VerifyCsrfToken`/`ValidateCsrfToken` subclass
  anywhere in `app/`, `bootstrap/`, `routes/` or `config/`.
* All 85 state-changing routes live in `routes/web.php`, which
  `->withRouting(web: ...)` places in the `web` group.
* 87 Blade `<form>` tags: 85 emit `@csrf`. The two that do not are both
  `method="GET"` (the `admin/completed-orders` and `admin/summary` filter
  forms), where CSRF does not apply.
* 18 `fetch()` calls use a mutating method; **all 18** send a CSRF token.

One measurement worth recording as a caution: `php artisan route:list --json`
reports the middleware **group name** (`web`), not its contents, and
`getMiddlewareGroups()` is empty under `artisan tinker` because tinker boots
the console kernel, where the web group is never registered. Both look, at a
glance, like "85 state-changing routes with no CSRF middleware". Neither is
evidence of anything. The question was settled with a real HTTP request
instead.

### The trap, and how it was handled

`VerifyCsrfToken::handle()` short-circuits on `runningUnitTests()`, which is

```php
$this->app->runningInConsole() && $this->app->runningUnitTests()
```

and `Application::runningUnitTests()` is simply `$this['env'] === 'testing'`.
`phpunit.xml` sets `APP_ENV=testing`, so **CSRF validation is skipped for every
test in this suite by default.**

Each test here therefore sets `$this->app['env'] = 'production'` first, and
asserts `runningUnitTests()` is now false before proceeding. Measured to
confirm the lever does something:

```
POST /customer/login, no token,    env=testing    -> HTTP 302  (skipped)
POST /customer/login, no token,    env=production -> HTTP 419  (enforced)
POST /customer/login, valid token, env=production -> HTTP 302  (accepted)
```

The third line is the control that matters: without it, a wall of 419s could
simply mean nothing can get through at all.

### Verification

`tests/Feature/CsrfProtectionTest.php` — 25 tests. Nineteen consequential
endpoints are posted to with no token and must return 419: place-order, GCash
mark-as-paid, both password resets, both new-password steps, verification,
admin user creation, voucher creation, staff account toggle, both logins, both
logouts, registration, the wheel points endpoint, and four order-status
transitions (complete, cancel, discount approve, payment approve).

Paired with four controls: a valid token is not refused; a genuine login
actually succeeds with a token; a token from a **different** session is refused
(the property that actually stops the attack); and GET requests are unaffected.

**False-positive check.** `enforceCsrf()` was neutered to a no-op. All 19
endpoint tests failed immediately, confirming they measure real enforcement
rather than something else about the request.

---

## Verification and test data

The full suite passes: **505 tests, 2396 assertions** (457 / 2197 at the end of
Pass 4, plus the 48 added here). Zero failures.

| New test file | Tests |
|---|---|
| `tests/Feature/MassAssignmentEscalationTest.php` | 9 |
| `tests/Feature/SessionFixationTest.php` | 7 |
| `tests/Feature/SqlInjectionTest.php` | 7 |
| `tests/Feature/CsrfProtectionTest.php` | 25 |

Bounded high-water-mark cleanup, marks captured before and re-verified after:

```
  ok   orders           above mark 5966   = 0    max_id=5966   count=118
  ok   order_items      above mark 3957   = 0    max_id=3957   count=184
  ok   users            above mark 13     = 0    max_id=13     count=4
  ok   notifications    above mark 1614   = 0    max_id=1614   count=62
  ok   order_ratings    above mark 273    = 0    max_id=273    count=7
  ok   help_requests    above mark 10     = 0    max_id=10     count=10
  ok   vouchers         above mark 23     = 0    max_id=23     count=2
  ok   inventory        above mark 29     = 0    max_id=29     count=10
  ok   sessions rows = 29 (unchanged)
  ok   password_reset_tokens = 0
  ok   leftover test accounts (*.invalid.local) = 0
  ok   order 5966: ORD-20260831-UJYGAE / pending / dine_in

CLEANUP VERIFIED - zero rows above every high-water mark
```

`SessionFixationTest` writes real rows to the `sessions` table by design — the
count is back to 29, unchanged, because `DatabaseTransactions` rolls them back.

`MassAssignmentEscalationTest` places a real order with a real ID upload, so it
fakes both the `local` and `public` disks — the same leak Pass 4 found in
`CartTotalsMatchCheckoutTest`. A full run afterwards left **zero** files in
`storage/app/discount_ids/` and `storage/app/public/discount_ids/`.

Order `5966` was again left untouched. Nothing was committed or pushed.

---

## Files changed in this pass

**No application code was changed.** All four items came back clean, and
inventing a fix would have meant changing working code to have something to
report.

| File | Change |
|---|---|
| `tests/Feature/MassAssignmentEscalationTest.php` | **New.** 9 escalation attempts, each paired with a success control |
| `tests/Feature/SessionFixationTest.php` | **New.** 7 tests; browser-like session harness, negative control |
| `tests/Feature/SqlInjectionTest.php` | **New.** 7 tests; 7 payloads plus two structural guards |
| `tests/Feature/CsrfProtectionTest.php` | **New.** 25 tests; 19 endpoints, 4 controls, 2 config guards |

### Worth carrying forward

1. **`SESSION_LIFETIME=120` with `expire_on_close=false`** — untouched as
   instructed, but this pass looked at it closely enough to have an opinion:
   for a terminal on a public counter, two hours of idle time before a staff
   session expires is generous. Input for the team's open decision, not a
   finding.

2. **The `_ignition/*` routes** (`POST _ignition/execute-solution`,
   `POST _ignition/update-config`) exist because `laravel/ignition` ships as a
   dev dependency. They are only registered when the package is installed, and
   `composer install --no-dev` on the server removes it — which
   `DEPLOYMENT.md` §7b already requires and `deploy:check` does not currently
   verify. Not a finding today; worth a line in the deploy checklist if anyone
   ever deploys without `--no-dev`.

3. **Mass assignment protection is a convention, not a mechanism.** Every
   privileged column is in `$fillable`. The standing test added here is the
   only thing that would catch a future `->update($request->all())`.

---

# Pass 6 — Staff password control (admin-only), 2026-09-01

Not a security review this time but a **deliberate change of behaviour**,
decided by the system owner: **staff must have no self-service password path at
all.** Only the admin/owner sets a staff member's password.

The admin's own password features are untouched. That is not an oversight — an
admin who loses their password with no reset path locks the entire system out.

| # | Change | Result |
|---|---|---|
| 1 | Staff self-service password change removed | **Done** — account page is `role:admin` |
| 2 | Staff forgot-password removed | **Done** — without leaking which emails are staff |
| 3 | Admin can set a staff password | **Done** — new admin-only endpoint |
| 4 | Does the admin's own change-password work? | **Yes — proved end to end** |
| 5 | Show/hide password toggle | **Done** on all four forms |

---

## THE POINT THAT MATTERS FOR THE DEFENCE

**Passwords are stored as bcrypt hashes. They cannot be displayed — to the
admin, to a developer, or to anyone with full database access — because a hash
cannot be reversed.**

So the admin's control over a staff account is **not** "the admin can see the
staff password". It is:

> **the admin can SET a new staff password.**

That distinction is a property of the storage, not a user-interface choice, so
no future screen can change it. There is no endpoint that returns a stored
password, and `test_a_stored_password_is_a_hash_and_cannot_be_recovered()`
asserts both that the stored value is a bcrypt hash containing none of the
plaintext, and that no route named `admin.users.password.show` exists.

If asked "why can't the admin just look up the staff password?", the answer is
that nobody can, and that this is the correct design — a system that could show
you a stored password would be one that had stored it recoverably.

---

## 1. What was there before (investigated before changing anything)

| Question | Finding |
|---|---|
| Which files implement `/admin/account`? | Route `admin.account` (GET) and `admin.account.password.update` (PUT) in `routes/web.php`; `AdminController::showAccount()` and `AdminController::updateOwnPassword()`; view `resources/views/admin/account.blade.php`. Both routes sat in the `role:admin,staff` group. |
| What protects admin-only routes? | `App\Http\Middleware\RoleMiddleware`, used as `->middleware('role:admin')`. On a wrong role it redirects to `admin.home` with flash `error` = "You don't have permission to access that." It runs inside the `admin` group, so `AdminMiddleware` has already confirmed guard + active status. |
| Did staff get reset codes? | **Yes.** `AdminAuthController::resettableRoles()` returned `['admin','staff']`, and `HandlesPasswordReset::resolveResettableUser()` gates all three reset steps on it — `forgotPassword()` (issue), `resendCode()` (re-issue), `updatePassword()` (write). A staff member could reset their own password entirely from their own inbox. |
| Password actions on `/admin/users`? | **None besides creation.** Only `showUsers` (GET), `storeUser` (POST — role hardcoded to `staff`), `toggleUser` (PUT — activate/deactivate). |
| Does admin change-password work? | **Yes** — see section 4. Proved, not assumed. |

---

## 2. Staff self-service removed

Both account routes moved from the `role:admin,staff` group into the
`role:admin` group, and the sidebar "Account" link is now inside the existing
`@if($adminUser && $adminUser->role === 'admin')` block.

The refusal deliberately reuses `RoleMiddleware` rather than a new check in the
controller, so a staff member who types the URL or crafts a PUT gets **exactly**
the same redirect and the same message as every other admin-only page. That
sameness is asserted, not assumed:

```php
$response->assertRedirect(route('admin.home'));
$this->assertSame("You don't have permission to access that.", session('error'));
```

Hiding the sidebar link is convenience, not control — the route is what refuses.

---

## 3. Staff forgot-password removed, without creating an enumeration oracle

`AdminAuthController::resettableRoles()` is now `['admin']`.

**Why that one-line change was the right place.** `resolveResettableUser()` is
the single gate all three reset steps pass through, so narrowing the role list
closes issue, re-issue and the final write at once — including for a staff
member holding a reset session started before the change, because
`updatePassword()` re-resolves the user.

**And why it does not leak which emails belong to staff.** A staff email now
returns `null` from `resolveResettableUser()` and falls into the *same*
`if (! $user)` arm an unknown email already took. Not a parallel branch that
looks similar — literally the same code path. Asserted across four observable
channels:

```
status code    staff email == unknown email
redirect       staff email == unknown email
error message  staff email == unknown email
session state  staff email == unknown email
```

plus an assertion that the message is still the generic "could not find an
active …" wording and contains no "staff cannot" phrasing.
`resetAccountLabel()` was deliberately left as "admin or staff account" for the
same reason: the wording a stranger sees must not change.

`ResetCodeBruteForceTest` (12 tests) still passes unchanged, so the existing
message-uniformity guarantees are intact.

**Admin forgot-password still works**, asserted as the positive control in the
same test.

---

## 4. Does the admin's own change-password work? YES

The owner asked directly. `AdminOwnPasswordChangeTest` answers it with
end-to-end proof rather than a controller reading:

* changes the password, then **actually signs in with the new one** through the
  real `/admin/login` form, and confirms the **old one no longer logs in**
* confirms the stored value is a bcrypt hash containing none of the plaintext
* wrong current password → refused, password unchanged
* too short → refused, unchanged
* mismatched confirmation → refused, unchanged
* reusing the current password, and a long-but-simple password → refused

"The endpoint flashed success" would not have settled it — a controller can
flash success and write nothing, write to the wrong row, or double-hash so that
nothing can ever log in again. `Auth` with the new credentials is the only
assertion that does.

---

## 5. Admin sets a staff password

New endpoint `PUT /admin/users/{id}/password` →
`AdminController::updateStaffPassword()`, inside the `role:admin` group,
throttled `6,1`, CSRF-protected like every other state-changing route (added to
`CsrfProtectionTest`'s endpoint list, which now covers 20).

Guarantees, each asserted:

* **admin-only** — a staff member gets the standard admin-only refusal, and
  neither their own nor the victim's password changes
* **cannot target an admin row** — the controller re-checks `role === 'staff'`,
  so one admin cannot take over another's account and a mistyped id cannot lock
  the owner out
* **role is never touched** — posting `role=admin` and `is_active=0` alongside
  the password changes neither. The "role is always staff" guarantee from
  `storeUser()` is unaffected
* **validation reuses `PasswordPolicy::required()`** — the same rule object as
  every other password-setting flow, so this cannot become the weakest door.
  That is 8+ characters with upper, lower, number and symbol, plus `confirmed`;
  stricter than the "minimum 8, must match" the brief asked for, and chosen
  because the brief also said to reuse existing conventions rather than
  hand-roll
* **hashing is the model's `hashed` cast**, not a hand-rolled `Hash::make()` —
  doing both would double-hash and the password would never match again
* **`remember_token` is cycled**, so any "remember me" cookie held by that staff
  member stops working
* **the password never leaks** — asserted absent from the response body, the
  redirect `Location`, the flash message, and the rendered `/admin/users` page.
  The staff list is also asserted to contain no bcrypt hash at all

---

## 6. Show/hide password toggle

`resources/views/admin/partials/password-toggle.blade.php`, included once from
`admin/layout.blade.php`, covering the create-staff form, the new set-password
form, and the admin account change-password form.

The admin **login** page already had its own complete, accessible toggle
(`aria-label`, `aria-pressed`, real `<button type="button">`). The include was
removed from that page rather than shipping a second listener alongside it.

It flips the input's `type` and nothing else. It cannot fetch a stored password
— there is no endpoint that returns one. Keyboard-accessible by construction
(a real button), `aria-pressed` and `aria-label` both update, focus ring kept,
and the caret position is preserved across the flip.

---

## Sabotage check

Each guard was neutered one at a time and the suite re-run.

| Sabotage | Tests that failed | Verdict |
|---|---|---|
| **1.** Account routes back to `role:admin,staff` | `staff_cannot_open_the_account_page`, `staff_cannot_change_their_own_password_by_posting_directly` | correct and specific |
| **2.** `resettableRoles()` back to `['admin','staff']` | `a_staff_email_gets_no_reset_code`, `a_staff_email_is_indistinguishable_from_an_unknown_email`, `a_pre_existing_staff_reset_session_cannot_complete` | correct and specific |
| **3.** Remove the `role !== 'staff'` target check | `the_endpoint_cannot_target_an_admin_account` | correct and specific |
| **4.** Ungate the sidebar Account link | `the_account_link_is_hidden_from_staff_and_shown_to_admins` | correct and specific |

All four restored byte-identically afterwards (verified with `diff`), suite
green again.

**The first attempt at sabotage 1 was itself wrong, and is worth recording.**
It wrapped the account routes in a nested `Route::middleware('role:admin,staff')`
group *inside* the existing `role:admin` group and reported no failures. That
looked like the tests were not measuring the guard. They were: Laravel **merges**
nested group middleware, so the route carried `role:admin | role:admin,staff`
and `role:admin` still refused staff — confirmed by dumping
`gatherMiddleware()`. The sabotage was ineffective, not the tests. The real
sabotage moved the routes physically back into the other group.

That is the same lesson this project keeps relearning: check that the thing you
changed actually changed before concluding anything from the result.

---

## A pre-existing test that encoded the old behaviour

`AdminAccountPasswordTest` had a `roles()` data provider of
`['admin', 'staff']`, driving four tests that asserted a staff member *could*
change their own password. Those tests were correct when written; the owner's
decision reverses the behaviour they pin.

They were **narrowed, not deleted** — the admin half is still exactly right —
and the reason is recorded on the provider itself so nobody "fixes" it back.
The staff side did not lose coverage: it moved into
`StaffPasswordControlTest` and got stronger.

## A flaky failure, diagnosed rather than ignored

One full-suite run showed a single failure that four subsequent runs did not
reproduce. Rather than shrug at it: `/admin/account/password` is `throttle:6,1`,
and `AdminAccountPasswordTest::setUp()` called
`RateLimiter::clear('throttle:6,1')` — **which clears nothing**.
`throttle:6,1` is the middleware *argument*, not a limiter name;
`ThrottleRequests::resolveRequestSignature()` keys on `sha1(domain|ip)` with an
empty prefix. That counter is shared by every plain `throttle:X,Y` route for the
same IP and, under the array cache store, persists between tests in a process.

Adding Pass 6's tests against the same endpoint pushed it over the limit
intermittently. `Cache::flush()` — what the other throttle-aware classes already
use — was added to that `setUp()`, with the no-op line kept and commented so it
is not re-added. Four consecutive clean full runs since.

---

## Verification and test data

Full suite: **523 tests, 2482 assertions**, zero failures, confirmed over four
consecutive runs. (505 / 2396 before this pass.)

| New test file | Tests |
|---|---|
| `tests/Feature/StaffPasswordControlTest.php` | 14 |
| `tests/Feature/AdminOwnPasswordChangeTest.php` | 6 |

Every "staff is refused" assertion is paired with a positive control proving the
same action works for an admin — a refusal test is worthless if the endpoint is
broken for everyone.

`Storage::fake()` is not relevant to this pass: nothing here touches file I/O.
The new test classes create user rows only.

Bounded high-water-mark cleanup, marks captured before and re-verified after:

```
  ok   users            above mark 13     = 0    max_id=13     count=4
  ok   orders           above mark 8076   = 0    max_id=8076   count=119
  ok   order_items      above mark 4661   = 0    max_id=4661   count=185
  ok   notifications    above mark 1937   = 0    max_id=1937   count=67
  ok   order_ratings    above mark 364    = 0    max_id=364    count=8
  ok   leftover test accounts (*.invalid.local) = 0
  ok   password_reset_tokens = 0
```

All four seeded accounts still hold their original bcrypt hashes — every test
account is created by the test and rolled back by `DatabaseTransactions`, and
no real password was set outside a transaction.

---

## Files changed in this pass

| File | Change |
|---|---|
| `routes/web.php` | Account GET+PUT moved from `role:admin,staff` to `role:admin`; new `PUT /admin/users/{id}/password` |
| `app/Http/Controllers/Admin/AdminAuthController.php` | `resettableRoles()` → `['admin']`, with the enumeration reasoning recorded |
| `app/Http/Controllers/Admin/AdminController.php` | **New** `updateStaffPassword()` |
| `app/Http/Controllers/Concerns/HandlesPasswordReset.php` | Docblock notes staff are no longer resettable and why |
| `resources/views/admin/layout.blade.php` | Account sidebar link gated to admin; includes the toggle partial |
| `resources/views/admin/users.blade.php` | Per-staff "Set Password" row; toggles on the create-staff form |
| `resources/views/admin/account.blade.php` | Toggles on all three password fields |
| `resources/views/admin/partials/password-toggle.blade.php` | **New** |
| `app/Console/Commands/DeployCheck.php` | Stale "staff or admin" reset claim corrected to admin-only |
| `docs/DEPLOYMENT.md` | Same stale claim corrected in three places |
| `tests/Feature/StaffPasswordControlTest.php` | **New**, 14 tests |
| `tests/Feature/AdminOwnPasswordChangeTest.php` | **New**, 6 tests |
| `tests/Feature/AdminAccountPasswordTest.php` | Provider narrowed to admin; flaky throttle fixed |
| `tests/Feature/CsrfProtectionTest.php` | New endpoint added to the CSRF endpoint list |

### Stale documentation corrected

Three places in `docs/DEPLOYMENT.md`, plus `DeployCheck.php` and
`HandlesPasswordReset.php`, warned that the dev reset-code banner meant "anyone
who knows a **staff or admin** email address can take over that account". After
this change a staff email cannot request a reset at all, so that claim was
overstated. All now say **admin**. The underlying warning is unchanged and still
correct — and still enforced by `deploy:check`.

### Out of scope, flagged not fixed

1. **`SESSION_LIFETIME=120` with `expire_on_close=false`** — still open from
   Pass 5. Two hours of idle time before a signed-in staff session expires is
   generous for a terminal on a public counter. The owner has an open decision
   on this.
2. **There is no audit trail for a staff password reset.** Nothing records that
   admin X reset staff Y's password at time T. For a café this is probably fine,
   but if the system ever needs to answer "who changed this account", it cannot.
   Adding it would be a small, self-contained change.
3. **A staff member who is locked out now depends entirely on the admin being
   reachable.** That is the owner's explicit decision and the right one for this
   business, but it is worth stating: if the admin is unavailable, a locked-out
   staff member cannot work. The mitigation is that the admin can set a new
   password in seconds from a phone.

---

# Pass 7 — Rate-limit collision hitting ordinary admin use, 2026-09-01

Not an attack scenario. **A legitimate user locked out of their own system by
doing nothing wrong**, reported during the owner's own testing and dangerous
specifically because it would have happened live in front of the thesis panel.

| # | Item | Result |
|---|---|---|
| 1 | Is this the Pass 3 shared-counter bug again? | **Yes — confirmed, and wider than expected** |
| 2 | Reproduced as a real HTTP test? | **Yes — the owner's exact sequence 429s** |
| 3 | Fixed by isolating counters? | **Yes — no limit was loosened** |
| 4 | Brute force still blocked? | **Yes — still 3/min on login** |

---

## What the owner experienced

Signed in as admin. Browsed a couple of admin pages. Used the Pass 6
"Set Password" feature once to change a staff member's password. Logged out.
Tried to log back in — **429 Too many attempts.**

No failed guesses. No rapid resubmission. One pass through an ordinary
workflow.

---

## Part 1 — the limiter map, before

Every throttled route under the admin guard:

| Route name | Method | URI | Throttle | Kind |
|---|---|---|---|---|
| `admin.login.post` | POST | `admin/login` | `throttle:3,1` | raw |
| `admin.forgot-password.post` | POST | `admin/forgot-password` | `throttle:6,1` | raw |
| `admin.verification.post` | POST | `admin/verification` | `throttle:10,1` | raw |
| `admin.verification.resend` | GET | `admin/verification/resend` | `throttle:3,1` | raw |
| `admin.new-password.post` | POST | `admin/new-password` | `throttle:6,1` | raw |
| `admin.qr-generator.table-code` | POST | `admin/qr-generator/table-code` | `throttle:30,1` | raw |
| `admin.tables.occupancy` | GET | `admin/tables/occupancy` | `throttle:120,1` | raw |
| `admin.tables.clear` | POST | `admin/tables/clear` | `throttle:60,1` | raw |
| `admin.notifications.index` | GET | `admin/notifications` | `throttle:60,1` | raw |
| `admin.notifications.unread-count` | GET | `admin/notifications/unread-count` | `throttle:60,1` | raw |
| `admin.notifications.read` | POST | `admin/notifications/read` | `throttle:60,1` | raw |
| `admin.account.password.update` | PUT | `admin/account/password` | `throttle:6,1` | raw |
| `admin.users.password.update` | PUT | `admin/users/{id}/password` | `throttle:6,1` | raw |

Every one raw. No admin route used a named limiter.

`App\Providers\RateLimitServiceProvider` is where the codebase records the
deliberate pattern, and it said:

> *"Auth endpoints are deliberately NOT changed and stay pure per-IP in
> routes/web.php: login, registration, password reset and verification are the
> cases where 'one address, many attempts' IS the attack, and where a shared
> café IP being throttled together is the correct, intended behaviour."*

That comment is about **what the counter is keyed on** — the IP — and it is
correct. What it did not say, because nobody realised it, is what raw throttles
do to **which actions share** that counter.

### Do admin login and the staff-password reset share a bucket?

**Yes — and so does everything else.** The brief guessed the two might collide
because they had matching `N,M`. They do collide, but not for that reason:
login is `3,1` and the staff reset is `6,1`, and **N and M are not part of the
key at all**.

`ThrottleRequests::handle()`:

```php
'key' => $prefix.$this->resolveRequestSignature($request),   // $prefix = ''
```

Empty prefix, and `resolveRequestSignature()` returns `sha1(domain|IP)` for an
unauthenticated request. `handleRequest()` then compares that one shared count
against each route's own `maxAttempts`.

Resolved keys, dumped in tinker rather than assumed:

```
admin.login.post                  5c785c036466adea360111aa28563bfd556b5fba
admin.users.password.update       5c785c036466adea360111aa28563bfd556b5fba
admin.account.password.update     5c785c036466adea360111aa28563bfd556b5fba
admin.notifications.unread-count  5c785c036466adea360111aa28563bfd556b5fba
admin.forgot-password.post        5c785c036466adea360111aa28563bfd556b5fba

distinct keys among those 5 routes: 1
```

Byte-identical.

### The line that actually explains the lockout

The fourth one. `admin.notifications.unread-count` is `throttle:60,1`, and the
notification bell is `@include`d in the admin **layout** — so it is on every
admin page — and polls it every 20 seconds:

```js
setInterval(poll, 20000);   // resources/views/admin/partials/notification-bell.blade.php:422
```

Those polls increment the same counter that `admin.login.post` checks against
**3**. About a minute on any admin screen spends the entire login budget with
no user action whatsoever. Add one Set Password and a logout, and the next
login is refused before the password is even read.

Across the whole application, **24 routes shared that one counter per IP**.

---

## Part 1 — the reproduction

`AdminThrottleIsolationTest::test_an_ordinary_admin_workflow_does_not_trip_a_rate_limit()`
drives the owner's sequence as real HTTP from one IP, well inside a minute:
correct login → two admin pages → three bell polls → one legitimate Set
Password → logout → correct login.

**Before the fix it failed**, exactly as reported:

```
⨯ an ordinary admin workflow does not trip a rate limit
⨯ the notification poll cannot spend the login budget
⨯ the two password actions do not share a counter
⨯ each sensitive admin action has its own limiter
✓ rapid wrong password guesses are still blocked
✓ the staff password endpoint still has its own limit
✓ one address being blocked does not block another
```

Note which ones passed even before the fix: the brute-force controls. The
protection was never broken — only misdirected. That is the distinction this
pass turns on, and it is why "is brute force blocked" was never going to find
this.

---

## Part 2 — the fix

Three named limiters in `RateLimitServiceProvider`, and the three routes
pointed at them:

| Action | Was | Now | Limit | Keyed on |
|---|---|---|---|---|
| Admin login | `throttle:3,1` | `throttle:admin-login` | **3/min** | IP |
| Admin's own password | `throttle:6,1` | `throttle:admin-account-password` | **6/min** | IP |
| Staff password reset | `throttle:6,1` | `throttle:admin-staff-password` | **6/min** | IP |

`handleRequestUsingNamedLimiter()` keys on `md5($limiterName . $limit->key)`,
so the name is part of the key. Resolved after the change:

```
admin-login              limit=3   key=549b8cc631b67777cbb1caa66e45e270
admin-account-password   limit=6   key=c99581cd9673d482143efae5335bfd93
admin-staff-password     limit=6   key=6ad88d09aff2afaba61b12d1d79538c2
distinct: 3 of 3
```

**Every number is unchanged, and every limiter is still keyed on the IP.** This
separates two things the old code conflated: *what* the counter is keyed on
(the IP — deliberate, correct, untouched) and *which actions* share it (all of
them — accidental, now fixed). The `RateLimitServiceProvider` docblock has been
rewritten to state that distinction so the next person does not re-merge them.

### Was a more generous limit needed as well?

**No, and none was applied.** The brief allowed loosening a specific limit only
if isolation alone proved insufficient. It was sufficient: the reproduction
passes with all three limits at their original values. Loosening anything would
have been an unnecessary reduction in protection, so nothing was.

Worth stating for the demo: after the fix, the admin has 3 login attempts per
minute *for logging in alone*. A typo, a back button and one retry is 3 — it
fits, but not with much room. If the panel demo involves repeated
log-out/log-in cycles, that is the number to watch. It was left at 3 because
that is what the security review chose and this pass was not asked to revisit
it.

---

## Testing

### Sabotage check

Reverting `routes/web.php` to the raw `throttle:N,M` (leaving the named
limiters registered but unused) failed **exactly the four** tests that measure
isolation, and left the three brute-force controls passing:

```
⨯ an ordinary admin workflow does not trip a rate limit
⨯ the notification poll cannot spend the login budget
⨯ the two password actions do not share a counter
⨯ each sensitive admin action has its own limiter
✓ rapid wrong password guesses are still blocked
✓ the staff password endpoint still has its own limit
✓ one address being blocked does not block another
```

Restored byte-identically (`diff`-verified), green again.

### Positive controls — nothing was loosened

* **Brute force still blocked:** 20 wrong passwords in a row against the admin
  login are refused after **≤3**, and none authenticates.
* **Staff-password endpoint keeps its own 6/min.**
* **Per-IP keying preserved:** burning one address's budget does not block a
  different address, which is the property that proves the limiter is still
  keyed on the IP as `RateLimitServiceProvider` intends.

### Full suite

**530 tests, 2522 assertions**, zero failures, across three consecutive runs.
(523 / 2482 before this pass.)

### Two pre-existing tests this change broke, and why

Both were in `AdminLoginThrottleTest`, both failed consistently rather than
flakily, and both were **pinning the implementation rather than the property**:

1. `test_the_admin_login_route_is_limited_to_three_a_minute()` asserted the
   literal string `'throttle:3,1'` was in the route's middleware. The route now
   uses a named limiter with the identical 3-per-minute limit, so the
   protection did not change but the string did. It now reads `maxAttempts` and
   `decaySeconds` out of the registered limiter — the thing that actually
   governs the route — and additionally asserts the route uses the named
   limiter at all.

2. `test_a_correct_password_still_works_once_the_window_clears()` cleared the
   limiter using a hand-computed `sha1('|127.0.0.1')` — the old unnamed key.
   That key is now the wrong one, so the clear was a no-op and the test 429'd.
   The helper now resolves the key through the registered limiter rather than
   reimplementing framework internals, so it cannot drift again.

Neither was weakened: `test_the_fourth_wrong_attempt_in_a_minute_is_refused()`
still passes untouched.

---

## Bounded cleanup — nothing was deleted, and that is the correct outcome

Marks captured before the work: `users` 1492, `orders` 10913, `order_items`
5638, `notifications` 2391, `order_ratings` 364.

Afterwards there **were** rows above those marks — 3 orders, 4 order items, 14
notifications, 1 rating. They were inspected individually before anything was
touched, and they are **the owner's real activity**, not test residue:

```
id=11458  ORD-20260901-7WL4UA  completed  pick_up  50.00   06:48:56
id=12358  ORD-20260901-ZV3W7B  pending    pick_up  140.00  06:53:51
id=12428  ORD-20260901-6UTFJJ  pending    pick_up  200.00  06:54:18
```

Real `ORD-` numbers, real totals, GCash payment notifications and a customer
rating, timestamped 06:48–06:54 while this pass was running. Verified further:
**zero** rows above the mark carry a test order-number prefix (`TAB-`, `DID-`,
`MSP-`), and every test class that writes uses `DatabaseTransactions`
(`DeployCheckTest` is the only one without, and it is read-only).

So this pass created no persistent rows, and **deleting "everything above the
mark" would have destroyed three real customer orders.** The high-water-mark
rule exists to remove *this session's* residue, not everything newer than a
timestamp — the check is the manifest, not the arithmetic.

Order `5966` untouched. Zero `*.invalid.local` accounts remain.

Re-baselined for the next pass: `orders` 12428, `order_items` 6162,
`notifications` 2653, `order_ratings` 504, `users` 1492.

---

## Files changed

| File | Change |
|---|---|
| `app/Providers/RateLimitServiceProvider.php` | Three named per-IP limiters (`admin-login`, `admin-account-password`, `admin-staff-password`); new `perIp()` helper; docblock rewritten to separate "keyed on" from "shared with" |
| `routes/web.php` | Those three routes moved from raw `throttle:N,M` to their named limiters |
| `tests/Feature/AdminThrottleIsolationTest.php` | **New**, 7 tests: the reproduction, the poll-vs-login case, cross-action isolation, three brute-force controls, and a structural guard |
| `tests/Feature/AdminLoginThrottleTest.php` | Two assertions re-pointed from the middleware string / old key to the registered limiter |

---

## Out of scope — found, listed, deliberately NOT fixed

**21 routes still share one counter per IP.** The same bug, on routes this
brief did not authorise touching. Listed here so the decision is the owner's:

| Throttle | Routes still sharing the raw bucket |
|---|---|
| `60,1` | `discount-id.show`, `customer.notifications.index`, `customer.notifications.unread-count`, `customer.notifications.read`, `admin.tables.clear`, `admin.notifications.index`, `admin.notifications.unread-count`, `admin.notifications.read` |
| `10,1` | `customer.login.post`, `customer.register.post`, `customer.verification.post`, `admin.verification.post` |
| `6,1` | `customer.forgot-password.post`, `customer.new-password.post`, `admin.forgot-password.post`, `admin.new-password.post` |
| `3,1` | `customer.verification.resend`, `admin.verification.resend` |
| `30,1` | `customer.add-points`, `admin.qr-generator.table-code` |
| `120,1` | `admin.tables.occupancy` |

**The one most likely to bite next** is the customer side. The customer
notification bell polls on customer pages the same way, at `60,1`, sharing a
counter with `customer.login.post` (`10,1`) and
`customer.verification.resend` (`3,1`). On a café's single shared public IP,
several customers with the menu open could plausibly spend the login or
resend budget for the whole room. That is the same failure the
`place-order` limiter was named for in an earlier pass — this is simply the
part that was not converted at the time.

It is not fixed here because the brief scoped this pass to the three admin
actions. The conversion is mechanical and the pattern is now established
(`perIp()` in `RateLimitServiceProvider`), so it is a small follow-up whenever
the owner wants it.

Also still open from earlier passes: `SESSION_LIFETIME=120` with
`expire_on_close=false`; no audit trail for staff password resets.

---

# Pass 9 — Admin-issued voucher codes, 2026-09-01

A feature, not a review. Recorded here only because it changes an invariant the
previous pass relied on in its safety argument.

## The invariant that changed

Pass 8 made a claim code a bearer instrument, and part of why that was safe was
stated as:

> *"a claim code names ONE row, minted only by a real winning spin"*

**That is no longer the only source.** An admin can now mint one from the
Vouchers page, for a walk-in Dine-In or Pick-Up customer who has no account and
may never play the wheel. The two code comments carrying the old wording
(`VoucherClaims` and `Voucher::availabilityErrorFor`) have been corrected rather
than left to mislead.

## Why the safety argument still holds

The bearer argument never depended on claims being *rare* — it depended on each
claim being worth exactly one prize that was genuinely granted. That is
unchanged. What matters is that the new source cannot manufacture supply:

* `Voucher::issuanceErrorFor()` mirrors the wheel's own issuability filter from
  `AuthController::winnableVoucherFor()` — active, not expired, and under the
  **same `max_uses` cap read from the same `used_count` column** — plus one
  stricter condition (a voucher whose `valid_from` has not arrived, which the
  wheel does not check).
* So an admin cannot issue a code for a voucher the wheel would refuse to
  award, and cannot push a voucher past its cap. Asserted directly by
  `test_issued_codes_count_against_the_same_max_uses_cap`, which issues,
  redeems, and then finds the second issue refused at a cap of 1.
* The endpoint is `role:admin` — the same rule every other voucher action
  already used. Staff, customers and signed-out visitors are all refused, each
  asserted with an admin success as the positive control.
* Redemption is unchanged. A counter-issued claim is ownerless, carries one
  code, and is spent by the same conditional UPDATE at checkout. There is no
  special-casing at redemption time, which is why the test redeems it through
  the real cart and checkout rather than asserting anything about how it was
  made.

## One deliberate difference from a wheel win

A wheel claim opens the **next day**; a counter-issued claim opens **today**.
That window exists to stop a self-service play-win-redeem loop where the
customer controls both ends. An admin handing a code to someone standing at the
counter is not that loop, and making them return tomorrow would defeat the
feature. This widens *when* a claim opens, never *whether* one may exist — every
gate on existence is unchanged and checked first. Pinned by
`test_an_issued_code_is_usable_immediately`.

## Audit

`user_vouchers.issued_by` records the admin, reusing the existing
`table_access_codes.issued_by` convention rather than building a second audit
system; `created_at` supplies the time. NULL means "not issued by a person",
i.e. every wheel win.

## Verification

15 new tests in `AdminIssueVoucherCodeTest`. Sabotage: removing the
`issuanceErrorFor()` gate failed 5 tests, including all four refusal cases and
the max_uses cap test. Restored byte-identically, green again.

Full suite **555 tests / 2625 assertions**, green across three consecutive runs
(540 / 2563 before).

---

# Pass 10 — First-run admin bootstrap, 2026-09-01

A feature, recorded here because it adds an **unauthenticated account-creation
endpoint** — normally the last thing you would want — and the reasoning for why
that is safe belongs on the record.

## The problem

A brand-new deployment has no admin, so nobody can sign in to create one. The
only route in was `php artisan db:seed --class=AdminBootstrapSeeder`, which
needs terminal access to the server. A new owner installing this fresh may not
have that, and should not be editing the database by hand.

**Worth correcting one premise:** the existing seeder never used a hardcoded
default password. It requires `ADMIN_BOOTSTRAP_EMAIL` / `ADMIN_BOOTSTRAP_PASSWORD`
from `.env` and refuses to run without them, so there was no shipped default
credential to change. The gap was reachability, not a weak default.

## What was added

A "Create Administrator Account" panel on the staff portal login page, shown
**only** while zero admins exist, backed by `GET/POST /admin/bootstrap`.

### Why an unauthenticated create endpoint is acceptable here

It is gated at three depths, and each covers a case the one above it cannot:

| Layer | Stops |
|---|---|
| Login page hides the link | Nothing. Convenience only, and treated as such. |
| Controller re-checks on GET **and** POST | A direct request, and a form loaded before the system was configured. |
| `AdminBootstrap::create()` re-checks **inside the transaction**, behind a UNIQUE index | Two simultaneous requests that both passed the layer above. |

The availability rule requires **both** conditions:

1. no user with `role = 'admin'` exists, **and**
2. the `admin_bootstrap` row does not exist.

(1) alone would reopen the path if every admin were later deleted — precisely
the state an attacker would try to engineer. (2) alone would not cover an
installation whose first admin came from the seeder and so never wrote that row.
Requiring both means the path opens on a genuinely fresh install and never
reopens. Pinned by `test_removing_every_admin_does_not_reopen_the_path`.

### The race

Check-then-act is not safe under concurrency, so the guarantee is handed to the
database rather than to PHP ordering. The `admin_bootstrap` insert happens in
the same transaction as the user insert, and its `singleton` column is UNIQUE
with every insert writing the same value — so the losing transaction fails on
the index and rolls its user back with it.

`settings` was considered for the record instead of a new table and rejected:
its unique index is on `(branch_id, key)` and MySQL permits multiple NULLs
there, so two concurrent inserts would both have succeeded and the guarantee
would silently not have held.

### No weaker rules on this path

Validation is `PasswordPolicy::required()` — the same rule object as staff
creation, the admin's own password change, the reset flow and registration.
Hashing is the model's `hashed` cast, exactly as `AdminController::storeUser()`
does it. Asserted by `test_the_rules_reuse_the_shared_password_policy`, and by
seven data sets covering short / no-symbol / no-number / no-uppercase /
mismatched.

**The seeder was tightened to match.** It used a bare `strlen($password) < 8`,
which meant the most privileged account on a new installation could be created
with the weakest password in the system. It now runs the same `PasswordPolicy`.

### Rate limiting

Named limiter `admin-bootstrap`, 5/min, IP-keyed — there is no account to key
on yet. Verified with the same rigour as Pass 7: it uses a named limiter rather
than a raw `throttle:N,M` (which would share one counter per IP with every
other raw-throttled route), its resolved key differs from `admin-login`,
`admin-account-password` and `admin-staff-password`, it actually refuses after
5, and a different address is unaffected.

## Sabotage check — and what it revealed

Three rounds, and the first two are worth recording because they came back
green:

* **Controller guard removed** → all 19 passed. `create()` re-checks and returns
  null, so the outcome is identical.
* **Service guards removed** → all 19 passed. The controller catches it.
* **Both removed** → `test_a_direct_request_is_refused_when_an_admin_already_exists`
  fails.

That is defence in depth behaving as intended — no single layer is individually
load-bearing — but it also decomposes the responsibilities cleanly:

* the **UNIQUE index** enforces "only one bootstrap can ever complete", and
* the **PHP guards** enforce "closed on a system whose admin came from the
  seeder", where no `admin_bootstrap` row exists for the index to reject.

One process note: the second sabotage attempt reported green because the string
replacement had silently not matched. It was caught by grepping the file for the
sabotage marker before trusting the result — the same class of mistake as Pass 7's
ineffective nested-middleware sabotage.

## Verification

19 new tests in `AdminBootstrapTest`, including a real round trip — the created
account signs in through the actual login form — and the closure asserted in the
same test case immediately afterwards.

Full suite **584 tests / 2848 assertions**, green across three consecutive runs
(565 / 2705 before).

# Pass 11 — Customer self-service order cancellation, 2026-09-02

Feature work rather than a security review, recorded here because it lets a
customer change order state and can create an obligation to move real money.

## What was added

A "Cancel Order" button on the customer's current-order card (Pick Up and
Dine In), plus the server-side rules behind it.

The endpoint itself was **not** new. `cancelCustomerOrder()` already existed
and was already correctly scoped — the only thing reaching it was the
PWD/Senior discount-rejection popup, so a customer with an ordinary cash order
never saw a cancel affordance anywhere. The endpoint is now shared by both
callers, which is why the tests exercise the route directly rather than either
UI path.

## The rules, all enforced server-side

* **Pending only.** Once an order is `preparing`, `serving` or `completed` the
  customer cannot withdraw it — food and staff time have been spent. This also
  makes the endpoint safely repeatable: a second cancel is refused rather than
  re-firing the refund notification below.
* **Own order only.** Signed-in customers are scoped by `user_id`; guests by
  the order-id set their session actually placed. Order ids are sequential
  integers, so without this, counting upward would cancel strangers' orders.
* **Not-yours and not-found return the same 404**, so the endpoint cannot be
  used to enumerate which ids exist.

The button being hidden for a non-pending order is a UI convenience and is
explicitly not the control; every test posts straight at the route.

## Money

A GCash order cancelled **after** the customer tapped "I have paid"
(`payment_status = awaiting_verification`) may involve money that genuinely
moved. There is no merchant API in this system, so nothing here can reverse
it — the refund is a person at the counter sending money back.

Such a cancellation therefore lands in a distinct `refund_pending` state
(new value on the existing `payment_status` enum; widening only, no row
changed) and raises an immediate **staff** notification worded as an
instruction rather than an event. Cancelled *before* that tap, nothing moved,
so it is an ordinary cancellation and deliberately raises nothing — staff must
not be sent chasing money that was never sent.

`refund_pending -> refunded` mirrors the shape of the existing
`awaiting_verification -> paid` staff decision.

## Where staff see it

The live order board (`admin.home`), in its own "Refunds Owed" band above
Active Orders — **not** the completed-orders archive. A refund-pending order
is status `cancelled`, so it is excluded from the Active Orders query and
would otherwise appear nowhere staff actually work: the archive is a
date-filtered history nobody scans for to-dos. Money owed back to a customer
should not be discoverable only by going looking for it. Marking it refunded
drops it from the band and leaves it in the archive as ordinary history.

The resolve action is `role:admin,staff` — the same scope as the existing
GCash approve/reject, since it is a counter action during a shift — and
guards on the current `payment_status`, so it cannot rewrite the money state
of an ordinary paid order and is safe to double-click.

## Sabotage check

Four rounds, each failing exactly the tests that name the broken rule and
nothing else:

* **Refund detection forced false** → 3 failed (the refund state, the staff
  notification, the no-duplicate-notification case).
* **Pending-only rule neutralised** → 4 failed (preparing/serving/completed,
  plus the refusal-is-reported case).
* **Ownership scoping removed** → 2 failed (other customer's order, guest with
  no claim).
* **Admin resolve guard neutralised** → 1 failed (refuses an order that owes
  nothing).

Every file restored byte-identically afterwards, verified with `diff -q`, and
reconfirmed green. One process note repeated from Pass 10: two substitutions
silently failed to match and reported green. Both were caught by grepping for
the sabotage marker before trusting the result — worth continuing to treat a
green sabotage run as suspect until the marker is confirmed present.

## Verification

19 new tests in `CustomerCancelOrderTest`, covering all six required cases
plus dine-in, the guest happy path (the mirror of the guest refusal, so the
rule is not merely refusing everyone), no-duplicate-notification, and the
button's own visibility window.

Full suite **616 tests / 2942 assertions**, green across three consecutive
runs (597 / 2902 before).

# Pass 12 — Points-threshold voucher rewards, 2026-09-02

Feature work, recorded here because it hands out vouchers and therefore needs
a ceiling that holds server-side.

## The rule

Every `PointsRewards::THRESHOLD` (30) lifetime points earns one voucher
reward, handed over at the counter. `unclaimed = floor(lifetime / 30) -
reward_claims`.

## The subtle part: which "points"

`users.points` is a SPENDABLE BALANCE — `addPoints()` credits it on a spin and
then subtracts `points_required` when the wheel awards a voucher, so it goes
down. Measuring a threshold against it would let a customer cross the same
milestone repeatedly by spending points and re-earning them, paying out again
for points already rewarded.

Rewards are therefore measured against `games_played` (append-only spin
ledger, `SUM(points_awarded)`), which only ever grows, and claims are counted
in a new `users.reward_claims` column. Neither number is ever reduced, so the
unclaimed count is monotonic. `unclaimedFor()` floors at zero so a future
THRESHOLD change cannot produce a negative.

`reward_claims` is deliberately NOT in `User::$fillable` — it is incremented
server-side via `increment()` only, so no request payload can move it. That
matters because it is the denominator of the issuance ceiling.

## The ceiling

`issueRewardCode()` is a SEPARATE endpoint from Pass 9's `issueVoucherCode()`,
not a flag on it. The walk-in flow has no customer and no points requirement
and is unchanged; keeping them apart is what lets this one be strict without
that strictness leaking into a flow that must keep serving customers who
earned nothing.

They share the minting (`VoucherClaims::mintForCounter`), so there is no
second issuance mechanism — a reward code and a walk-in code are the same
artefact, redeemed the same way.

The check and the increment run inside one transaction with the customer row
locked (`lockForUpdate`). The UI count is a hint on a page that may have been
open a while; two terminals could both read "1 unclaimed" and both submit, so
the second must read the first's increment and be refused. Every gate the
walk-in flow applies (`Voucher::issuanceErrorFor()`) still applies — a reward
does not entitle staff to issue an expired or exhausted voucher.

## Sabotage check

Three rounds, each failing exactly the tests naming the broken rule:

* **Crossing comparison removed** (notify on every spin) → 5 failed.
* **Lifetime read pointed at `users.points`** instead of the ledger → 7
  failed, including
  `test_spending_points_on_a_wheel_voucher_does_not_re_arm_the_reward`, which
  exists for precisely that bug.
* **Issuance ceiling removed** → 2 failed.

All three files restored byte-identically (`diff -q`) and reconfirmed green.

## Verification

13 new tests in `PointsRewardThresholdTest`. Full suite **629 tests / 3016
assertions**, green across three consecutive runs (616 / 2942 before).

# POMIDA — Hostinger deployment guide

Status of this document: written during a **pre-deploy review round**. Nothing
here has been run against a live server. Every step marked **[HUMAN]** needs a
real decision, a real credential or a real domain before deploy day.

---

## Project context

Read this first if you are new to the project — or if you are an AI assistant
being asked for help with it. Advice that is correct for Laravel in general is
often wrong for this specific application, and these are the facts that decide
the difference.

| | |
|---|---|
| **What it is** | POMIDA — a point-of-sale and online ordering system for Peachy Cakes and Deli Cafe: customers order at a table by scanning a QR code or order for pick-up, and staff run the order queue from an admin dashboard. |
| **Framework** | Laravel **11.51** (PHP framework). Not Laravel 12, and not Laravel 10 — answers that assume a different major version will be wrong about file locations. |
| **Language** | PHP **8.2** minimum (`composer.json` requires `^8.2`). Use **PHP 8.3** on the server. **Do not select PHP 8.5** — Laravel 11 does not support it. |
| **Database** | **MySQL**. Not SQLite, not PostgreSQL. |
| **Front end** | Blade templates rendered by the server, with Tailwind loaded from a CDN. **There is no build step** — `npm run build` is not needed and Node.js is not needed on the server. |
| **Hosting** | Hostinger **shared hosting**. This matters: there is no Redis, no Docker, no root access, and no ability to run a permanent background process. Sessions, cache and queues therefore all use the MySQL database. |
| **Background jobs** | None run continuously. Do not add advice that requires `php artisan queue:work` running as a daemon. |

### Folder layout

```
POMIDA/
├── app/          PHP application code (controllers, models, console commands)
├── bootstrap/    Laravel start-up files and the bootstrap/cache folder
├── config/       Configuration files, which read their values from .env
├── database/     Migrations (schema definitions) and seeders
├── docs/         This file and the other project documents
├── public/       ← the ONLY folder the public internet may reach
│   ├── index.php     the single entry point for every request
│   ├── uploads/      menu, category and ad images (tracked in git)
│   └── storage/      a symlink to storage/app/public (created by a command)
├── resources/    Blade view templates
├── routes/       web.php — the URL map for the whole application
├── storage/      logs, compiled views, sessions, cache, and some uploads
├── tests/        the automated test suite
├── vendor/       third-party PHP libraries, installed by Composer
├── artisan       the command-line tool: everything `php artisan ...`
└── .env          server-specific settings — SECRET, never in git
```

**Three terms used throughout this document, explained once:**

* **Document root** — the single folder your web address points at. Everything
  inside it can be downloaded by anyone on the internet; everything outside it
  cannot. For this project it must be set to `public/`, so that `.env`, which
  sits one level above, is unreachable from a browser.
* **Symlink** — a shortcut file that makes one folder appear at a second
  location. `php artisan storage:link` creates `public/storage` as a shortcut
  to `storage/app/public`, so that uploaded images stored outside the document
  root can still be shown on a web page.
* **Migration** — a PHP file that describes one change to the database
  structure (adding a table, adding a column). Running `php artisan migrate`
  applies every migration that has not been applied yet, in order. It changes
  the shape of the database, not the data in it.

---

## 🔐 Before you ask anyone — human or AI — for help with this

You will probably paste parts of this document into an AI assistant, or into a
message to a friend, while following it. That is fine and often useful. This
section is about the one thing that must never go with it.

> ### Never share the contents of the `.env` file
>
> **Do not paste the `.env` file, or any real password, API key, database
> password, or `APP_KEY`, into an AI assistant, a chat message, a forum post, a
> support ticket, or a screenshot.**
>
> Why: those values are the keys to the system. Anyone who reads them can log
> in as an administrator, read every customer's personal details, and change or
> delete the café's order and sales records. Once a secret has been pasted
> somewhere you do not control, you have to assume it is public — deleting the
> message afterwards does not undo it, because it may already have been stored,
> logged or indexed.
>
> **What IS safe to share:**
>
> * The **names** of settings — `MAIL_MAILER`, `DB_PASSWORD`, `APP_KEY`. Names
>   are not secrets.
> * The whole of **`.env.production.example`**, which contains only
>   placeholders like `FILL_IN_DATABASE_PASSWORD`.
> * The whole of **this document**, and any other file in `docs/`.
> * The **output of `php artisan deploy:check`**, which is written to report
>   pass/fail without printing any secret value.
> * Error messages — but **read them first** and replace any real password or
>   key you find inside with the word `REDACTED`.
>
> "My `MAIL_PASSWORD` is not working" is a completely answerable question. It
> does not require anyone to know what your mail password actually is.

If a secret has already been shared somewhere public, treat it as compromised
and replace it: change the database password in hPanel, change the mailbox
password, and run `php artisan key:generate` for a new `APP_KEY`. Regenerating
`APP_KEY` is safe for this project — no database column is encrypted with it,
so the only effect is that everyone currently signed in is signed out.

---

## 0. What still needs a human before you can deploy

> **Start here on deploy day:** copy `.env.production.example` (in the project
> root) to a file named `.env` on the server and fill in every `FILL_IN_...`
> value. Every item in the table below is one of those values. Then run
> `php artisan deploy:check`, which tells you whether anything is still wrong.
> The full numbered sequence is section 6 of this document.

| # | Item | Where | Why it cannot be decided in code |
|---|------|-------|----------------------------------|
| 1 | The real domain | `APP_URL` | Password-reset links, `asset()` URLs and the **printed table QR codes** are all built from it. |
| 2 | Real SMTP credentials | `MAIL_*` | Without them "Forgot Password" silently never delivers. See §2. |
| 3 | Hostinger MySQL name / user / password | `DB_*` | Created in hPanel → Databases. |
| 4 | A fresh `APP_KEY` | `APP_KEY` | Generate on the server; do not reuse the development key. Safe to regenerate — the app encrypts no database columns, so a new key only invalidates existing sessions. |
| 5 | First admin email + password | Browser form, or `ADMIN_BOOTSTRAP_*` | Real password, chosen by the leader. Either create it from the login page on first run (see step 8b) or set these and run the seeder. Only needed for the seeder route. |
| 6 | Whether to keep the 4 seeded branches | `branches` table | Business decision — see `BLANK_SLATE_RESET.md`. |
| 7 | Whether to keep the 6 wheel vouchers | `vouchers` table | Business decision — these are the game's prize list. |
| 8 | The 5 files in `public/uploads/ids/` | `public/uploads/ids/` | Checked in Pass 4: test uploads, not real IDs — but still publicly served and one holds two team members names. **See §7a.** |

> ### 🚨 The one that must not be missed
>
> **Before the site is reachable by anyone, run this on the live server:**
>
> ```bash
> php artisan deploy:check
> ```
>
> **Proceed only if its last line reads `READY TO GO LIVE`.** It checks
> `APP_ENV=production`, `APP_DEBUG=false`, and that `MAIL_MAILER` is a real
> mail driver rather than `log`, along with everything else that must be right.
>
> If `APP_ENV` is left as `local` *and* `MAIL_MAILER` is left as `log`, the
> password-reset page prints the 6-digit verification code on screen, and any
> admin account can be taken over by anyone who knows the email
> address. Full explanation and the one-line check are in **§2**.

---

## 1. Platform / PHP version

Hostinger shared hosting currently offers **PHP 8.2 – 8.5**, defaulting to
**8.3**. Versions below 8.2 are no longer selectable.

`composer.json` requires `"php": "^8.2"`, which permits 8.2 through 8.5.

**Set Hostinger to PHP 8.3.** Reasons:

* It is Hostinger's default, so no change is needed.
* Laravel 11.51 officially supports 8.2 and 8.3. PHP 8.4 works from Laravel
  11.31 onward, but 8.3 is the better-trodden path.
* **PHP 8.5 is not a supported Laravel 11 target — do not select it.**
* Hostinger does not let you downgrade the PHP version after upgrading, so
  choosing 8.3 and staying there avoids a one-way door.

hPanel → Websites → Dashboard → **PHP Configuration**.

### Extensions

The hard requirement set, from `composer check-platform-reqs --no-dev`:

```
ctype  dom  fileinfo  filter  hash  iconv  json
libxml  mbstring  openssl  pcre  session  tokenizer
```

plus `pdo_mysql` for the database. All of these are standard on Hostinger
shared hosting. Nothing exotic is required — there is no Redis, no S3, no
image-manipulation library, and no `intl` dependency.

### Node / npm is NOT needed

No Blade view uses `@vite`. All CSS and JS is inline or loaded from a CDN, and
`public/build/` does not exist and is not referenced. **Do not run `npm run
build` on deploy** — there is nothing to build.

### Queue worker / cron are NOT needed

* No class implements `ShouldQueue` and nothing calls `dispatch()`.
* Password-reset mail is sent synchronously via `Mail::to(...)->send(...)`.
* `routes/console.php` schedules only Laravel's stock `inspire` command.

So `QUEUE_CONNECTION=database` never has a worker, and that is fine. No cron
entry is required.

---

## 2. Email / SMTP

### Current state — verified

`config/mail.php` is stock Laravel and correct. Every value it needs comes from
`.env`; **no code change is required to switch to real SMTP.**

Verified in this review:

* Sent a real `PasswordResetCode` mailable through the configured driver with
  `MAIL_MAILER=log`. The rendered HTML — subject, recipient, and the 6-digit
  code — landed in `storage/logs/laravel.log`. The mailable and its Blade view
  work.
* Built the `smtp` mailer from environment variables only. Laravel produced
  `Symfony\...\Smtp\EsmtpTransport` pointing at `smtp://smtp.gmail.com:587`,
  with no code change. The config path is correct end to end. No connection was
  attempted and no real credentials were used.

### Keys that still need real values

```
MAIL_MAILER=smtp              # currently "log" — log DELIVERS NOTHING
MAIL_HOST=                    # smtp.gmail.com  OR  smtp.hostinger.com
MAIL_PORT=                    # 587 (Gmail/TLS)  OR  465 (Hostinger/SSL)
MAIL_ENCRYPTION=              # tls              OR  ssl
MAIL_USERNAME=                # [HUMAN] the real mailbox
MAIL_PASSWORD=                # [HUMAN] Gmail App Password, or hPanel mailbox password
MAIL_FROM_ADDRESS=            # [HUMAN] normally the same address as MAIL_USERNAME
```

**`.env.production.example` in the project root already contains all of these
lines with placeholders and an inline comment on each saying where the value
comes from, including step-by-step instructions for generating a Gmail App
Password.** Copy that file to `.env` on the server and fill it in rather than
typing these keys from scratch.

### After filling them in

```bash
php artisan config:clear
```

Then use the app's own **Forgot Password** form once with a real address and
confirm the code arrives. If it does not, the failure is already handled
gracefully: `HandlesPasswordReset::deliverResetCode()` catches the exception,
logs it, and returns `false` so the user sees a readable message rather than a
500. Check `storage/logs/laravel.log` for the real reason.

> ### ⚠️ STOP — verify this before the site is reachable by anyone
>
> While `APP_ENV=local` **and** `MAIL_MAILER=log`, the password-reset
> verification page prints the 6-digit code **directly on screen** in a yellow
> "Development mode" banner, so the flow can be demonstrated without SMTP.
>
> **If that ever renders on a public server it is a full account takeover.**
> Anyone who knows an admin email address can request a reset and read
> the code straight off the page, without ever touching that person's inbox.
> The email verification step stops verifying anything.
>
> **Before deploying to Hostinger, run this on the live server:**
>
> ```bash
> php artisan deploy:check
> ```
>
> It prints a PASS or FAIL line for each setting below and ends with either
> `READY TO GO LIVE` or `NOT READY — fix the items marked FAIL above`. There is
> nothing to interpret: do not open the site to customers unless it says READY.
>
> Its output contains no passwords or keys, so it is safe to share when asking
> anyone for help.
>
> | Setting | Required value | Why |
> |---|---|---|
> | `APP_ENV` | `production` | anything else can enable the banner |
> | `APP_DEBUG` | `false` | see §3 — stack traces otherwise |
> | `MAIL_MAILER` | a real driver (`smtp`), **not** `log` | `log` delivers nothing *and* is half of the banner condition |
>
> The code requires **both** `APP_ENV=local` and `MAIL_MAILER=log` before it
> will show the banner (`HandlesPasswordReset::devVisibleCode()`, proven by
> `tests/Feature/ResetCodeNotLeakedTest.php`), so getting **one** of them wrong
> is still safe. This check exists because getting **both** wrong is not, and no
> amount of application code can protect against that — only this check can.
>
> There is a second reason to get `MAIL_MAILER` right: with `log` on a live
> server, no reset email is ever delivered, and the user is still told one was
> sent. Password reset would appear to work and silently never arrive.
>
> Added by the manual security review of 2026-08-31 — see
> [`SECURITY_TESTING_SUMMARY.md`](SECURITY_TESTING_SUMMARY.md) Pass 2 §4a.

---

## 3. Debug / error handling

### What was verified

`APP_DEBUG=false` was tested for real, not assumed: the app was switched to
`APP_ENV=production` + `APP_DEBUG=false`, served over HTTP, and then the
database was pointed at a non-existent schema to force a genuine
`QueryException` on a live request.

Result: every page returned a branded **500** page. Status codes were correct
(404 → 404, 500 → 500). Grepping the response bodies for `SQLSTATE`, the
database name, `Unknown database`, `DB_PASSWORD` and `vendor/laravel` found
**zero** leaks. JSON requests get `{"message":"Server Error"}`.

### What was fixed

**1. There were no error views at all.** `resources/views/errors/` did not
exist, so production would have shown Laravel's unstyled grey "Server Error"
page. Added branded, self-contained pages for **403, 404, 419, 429, 500 and
503**, sharing `errors/layout.blade.php`. They deliberately do not extend the
customer or admin layout and touch neither the database nor the session — an
error page that depends on app state can fail while rendering, which is how you
get the blank white page these exist to prevent.

The 419 page matters more than it looks: it is what a customer sees when their
session expires mid-order, and Laravel's default wording ("Page Expired") does
not tell them nothing was charged.

**2. `SettingsServiceProvider` could take down every artisan command.** It
called `Schema::hasTable('settings')` during provider boot with no
`try`/`catch`. With wrong database credentials — the single most likely
deploy-day mistake — `php artisan --version`, `config:clear` and `route:list`
all died with a raw stack trace, so the operator would see a database error
from an unrelated command and have no idea the credentials were the cause. It
now catches, reports, returns an empty collection, and skips console runs
entirely. Re-verified: with an unreachable database, artisan commands run
normally and web requests render the friendly 500.

**3. Three `catch` blocks printed raw exception messages to the user.**
`APP_DEBUG=false` does not redact text the application echoes itself, so these
leaked regardless of the debug setting. Each was split so that *deliberate*
messages still reach the user and *unexpected* ones go to the log:

| File | What the user used to see | What they see now |
|------|---------------------------|-------------------|
| `Customer/OrderController::placeOrder` | `Failed to place order: <raw>` | Voucher/stock refusals unchanged; anything else → generic line, detail logged |
| `Admin/AdminController::storeManualOrder` | `Failed to create manual order: <raw>` | Same split |
| `Admin/AdminController` complete-order | `Failed to complete Order #N: <raw>` | Same split |

Deliberate `RuntimeException` / `DomainException` messages ("This voucher has
already been used.", "Not enough Milk (need 0.5 L, have 0.2)") are
**preserved** — those are written for the user and must keep showing.

**4.** `abort()` is called only as `abort(404)` with no custom message
anywhere, so no user-facing text is lost by turning debug off.

### Still needs a human

* Set `APP_DEBUG=false` and `APP_ENV=production` in the production `.env`.
* Set `LOG_LEVEL=error` (currently `debug`) and consider `LOG_STACK=daily` so
  the log rotates instead of growing into one endless file.

---

## 4. Storage and uploaded files

### How uploads actually work — two different mechanisms

| What | Written by | Lands in | DB stores | Needs `storage:link`? |
|------|-----------|----------|-----------|----------------------|
| Menu item photos | `->move(public_path('uploads/menu-items'))` | `public/uploads/menu-items/` | `uploads/menu-items/x.jpg` | No |
| Category images | `->move(public_path('uploads/categories'))` | `public/uploads/categories/` | `uploads/categories/x.jpg` | No |
| Ad images | `->move(public_path('uploads/ads'))` | `public/uploads/ads/` | `uploads/ads/x.jpg` | No |
| GCash QR | `->store('settings/gcash','public')` | `storage/app/public/settings/gcash/` | `settings/gcash/x.png` | **Yes** |
| PWD/Senior ID at checkout | `->store('discount_ids','public')` | `storage/app/public/discount_ids/` | `discount_ids/x.png` | **Yes** |

`App\Support\Img::url()` resolves both: it strips any `public/` or `storage/`
prefix, checks `storage/app/public/` first and returns `asset('storage/...')`
if the file is there, otherwise falls back to `asset(...)`. Verified against
live rows — all 20 image references in the database resolve to files that exist.

`config/filesystems.php` declares the standard link
`public/storage → storage/app/public`, so **`php artisan storage:link` is
required on the server.** The GCash QR and the checkout ID uploads are
invisible without it.

### Fixed: a fresh clone had no `storage/` directory at all

This was the most likely thing to break the actual deployment.

`git ls-files storage` returned **zero files**. Git cannot commit empty
directories, and the stock Laravel `.gitignore` placeholders inside `storage/`
were missing. A fresh `git clone` on Hostinger would therefore produce **no**
`storage/framework/views`, `storage/framework/sessions`,
`storage/framework/cache` or `storage/logs`, and the app would fatal on the
very first request with "Please provide a valid cache path".

All nine standard placeholder files were restored **on disk**, and the
`.gitignore` rules were written so they are not excluded (each placeholder ends
with a `!.gitignore` line that un-ignores itself).

> ### ⚠ STILL OPEN as of 2026-08-31 — re-checked during Pass 3
>
> **The nine placeholders exist on disk but have never been committed.**
> Re-measured, not assumed:
>
> ```bash
> git ls-files storage          # -> 0 files
> git status --porcelain storage # -> ?? storage/
> ```
>
> `git check-ignore` confirms they are *not* being excluded, so nothing is
> wrong with the ignore rules — the files are simply still untracked. Until
> they are committed, **a fresh `git clone` on the server still produces no
> `storage/framework/views`, `storage/framework/sessions`,
> `storage/framework/cache` or `storage/logs`, and the app still fatals on the
> very first request** with "Please provide a valid cache path".
>
> This does not affect an update to an already-working install (`git pull` into
> an existing folder leaves the server's `storage/` alone). It affects the
> **first** deploy, which is a clone.
>
> **Two ways to close it — do one of them before deploy day:**
>
> 1. Commit them, so a clone brings them with it:
>    ```bash
>    git add -f storage/app/.gitignore storage/app/public/.gitignore \
>              storage/framework/.gitignore storage/framework/cache/.gitignore \
>              storage/framework/cache/data/.gitignore \
>              storage/framework/sessions/.gitignore \
>              storage/framework/testing/.gitignore \
>              storage/framework/views/.gitignore storage/logs/.gitignore
>    git commit -m "Add storage placeholders so a fresh clone boots"
>    git ls-files storage        # must now list 9 files
>    ```
> 2. Or, on the server immediately after cloning and before the first page load:
>    ```bash
>    mkdir -p storage/framework/views storage/framework/sessions \
>             storage/framework/cache/data storage/logs bootstrap/cache
>    chmod -R 755 storage bootstrap/cache
>    ```
>
> Option 1 is better, because it cannot be forgotten.

The uploaded ID files under `storage/app/public/` were verified to be correctly
ignored, which is unchanged and correct.

### Flag: `public/uploads/` will not survive every deploy method

`public/uploads/` is **tracked in git** (52 files). That means:

* Deploying by `git pull` into an existing directory — **safe**, uploads made
  on the server are untouched.
* Deploying by fresh clone, or by re-uploading a ZIP over the top — **every
  image the café uploaded since launch is lost**, because those files exist
  only on the server and are not in git.

There is no code fix for this that is safe to make now; moving menu/category/ad
uploads onto the `public` disk would orphan the existing rows. **Decide a
deploy method and stick to it:** `git pull` in place, and back up
`public/uploads/` plus `storage/app/public/` before any redeploy.

### Hostinger specifics — verify on deploy day

* Hostinger serves from `public_html`. Point the domain's document root at the
  project's `public/` directory (hPanel → Websites → **Website root**) rather
  than moving `index.php` up a level. Moving it breaks the relative
  `require __DIR__.'/../vendor/autoload.php'` paths and the storage symlink.
* Run `php artisan storage:link` **after** the document root is set. If the
  host refuses symlinks, use `php artisan storage:link --relative`.
* Confirm `storage/` and `bootstrap/cache/` are writable. 755 is normally
  enough on Hostinger; do not use 777.

### Cosmetic

`public/uploads/uploads/menu-items/1779602503_cheezy.jpg` is an orphan — a
doubled path from an old bug, referenced by zero database rows. Harmless.

---

## 5. HTTPS / SSL

Real SSL cannot be tested from this development machine. What follows is the
code-level result plus the list to verify once the certificate is live.

### Code-level findings — clean

* **No hardcoded `http://` anywhere** in `app/`, `resources/`, `config/`,
  `routes/` or `database/`. The only match is Laravel's stock fallback
  `env('APP_URL', 'http://localhost')` in `config/app.php`, which `APP_URL`
  overrides.
* **No mixed content.** Every external resource is already `https://` —
  Google Fonts, `cdn.jsdelivr.net`, and the three social links.
* All internal links use `url()`, `route()` and `asset()`, so they follow
  whatever scheme the app is told to use.

### Added: `FORCE_HTTPS`

New `config('app.force_https')`, driven by `FORCE_HTTPS` in `.env`, applied in
`AppServiceProvider::boot()` via `URL::forceScheme('https')`. Default **false**.

Verified: default off leaves URLs as `http://...`; with `FORCE_HTTPS=true`,
`url()`, `route()` and `asset()` all emit `https://`.

It is env-driven rather than tied to `APP_ENV` deliberately — turning it on
before a certificate exists produces links the browser cannot load, so it needs
to be flippable without a code edit.

### The high-consequence one: printed table QR codes

`AdminController::qrTableCard()` builds the QR payload with:

```php
$url = url('/customer/menu') . '?branch_id=' . $branch->id . '&table=' . rawurlencode($tableNumber);
```

`url()` takes its scheme from the incoming request. **If anyone generates and
prints table cards before HTTPS is confirmed and `FORCE_HTTPS=true` is set,
`http://` gets baked into physical printed cards** and fixing it means
reprinting every table tent.

**Do not print any table cards until the verification below passes.**

### Verify once actually live behind HTTPS

1. `https://your-domain` loads with a valid padlock, and `http://` redirects to
   it (enable **Force HTTPS** in hPanel → Security → SSL).
2. Set `APP_URL=https://your-real-domain` (no trailing slash), then
   `FORCE_HTTPS=true`, then `SESSION_SECURE_COOKIE=true`, then
   `php artisan config:clear`.
3. Open DevTools → Console on the menu, cart, GCash payment and admin dashboard
   pages. Zero mixed-content warnings.
4. DevTools → Application → Cookies: the session cookie and `XSRF-TOKEN` both
   show **Secure ✓**, **HttpOnly ✓** (session cookie), **SameSite = Lax**.
5. Log in, place a test order, and upload an image in admin — confirm the
   session survives and images load over `https://`.
6. **Only then** generate one table card, scan it with a phone, and confirm the
   URL it opens starts with `https://`. Print the rest after that check passes.
7. If cookies still do not come back marked Secure, the site is likely behind a
   TLS-terminating proxy. In that case add `$middleware->trustProxies(at: '*')`
   in `bootstrap/app.php`. This was **deliberately not enabled** here —
   trusting all proxies when there is no proxy lets clients spoof their
   apparent IP, which would undermine the rate limiting covered by
   `ThrottleHardeningTest`.

---

## 6. Deploy day — the literal sequence

Do these steps in this order. Do not skip one because it looks unnecessary.

Every command below is run **in the project folder** — the folder that contains
the file named `artisan`. On Hostinger you get there through hPanel → Advanced →
**SSH Access** (or hPanel → Files → **Terminal**), then `cd` into the folder.

Steps 1–4 need no terminal. **Step 0 and steps 5 onwards all require
SSH/terminal access.** If you cannot get a terminal, stop here and get one
before continuing — this application cannot be installed without running the
commands below.

---

### Step 0 — Get the code onto the server *(terminal)*

```bash
git clone https://github.com/DuckonSteroidzz/POMIDA.git
cd POMIDA
```

Then immediately confirm the `storage` folders arrived, because a clone that is
missing them makes the site fail on its very first page load with "Please
provide a valid cache path":

```bash
ls storage/framework/views storage/framework/sessions storage/logs
```

If any of those say "No such file or directory", run this once:

```bash
mkdir -p storage/framework/views storage/framework/sessions \
         storage/framework/cache/data storage/logs bootstrap/cache
chmod -R 755 storage bootstrap/cache
```

(The reason this can happen, and the permanent fix, are in section 4 of this
document under "Fixed: a fresh clone had no `storage/` directory at all".)

### Step 1 — Set the document root

hPanel → Websites → your site → **Website root**.

Set it to the project's **`public`** folder. (Document root = the only folder
the internet can reach. See the "Project context" section at the top of this
file.)

Do **not** move `index.php` up a level instead. It breaks the paths inside it
and breaks the image symlink created in step 9.

### Step 2 — Set the PHP version

hPanel → Websites → Dashboard → **PHP Configuration** → select **PHP 8.3**.

Not 8.1 or lower (the app will not run). Not 8.5 (Laravel 11 does not support
it). Hostinger does not allow downgrading afterwards, so choose 8.3 and leave
it.

### Step 3 — Create the database

hPanel → **Databases** → create a database and a database user.

Write down these three values; step 4 asks for them:

* database name (looks like `u123456789_pomida`)
* database user (looks like `u123456789_pomidauser`)
* the password you chose for that user

### Step 4 — Create the `.env` file

In the project folder there is a file called **`.env.production.example`**.

1. Make a copy of it named exactly **`.env`** (a dot, then `env`, with no
   extension) in the same folder.
2. Open `.env` and replace every value that starts with `FILL_IN_` with a real
   value. The comment on each of those lines says where to get it.
3. Leave `APP_KEY=` empty — step 6 fills it in for you.
4. Set `FORCE_HTTPS=false` and `SESSION_SECURE_COOKIE=false` **for now**. Step
   11 turns them on once the padlock works.

Do not copy your development `.env` to the server. It contains the wrong
database, the wrong web address, and settings that would expose password reset
codes on a public site.

### Step 5 — Install the third-party libraries *(terminal)*

```bash
composer install --no-dev --optimize-autoloader
```

`--no-dev` is not optional. Without it, developer-only tools are installed on a
public server.

### Step 6 — Generate the security key *(terminal)*

```bash
php artisan key:generate
```

This writes a fresh `APP_KEY` into `.env` for you.

### Step 7 — Create the database tables *(terminal)*

```bash
php artisan migrate --force
```

(A migration is one described change to the database structure. This applies
all of them. `--force` only means "yes, this is a live server" — it does not
delete anything.)

### Step 8 — Add the starting data *(terminal)*

```bash
php artisan db:seed --force
```

This adds baseline settings rows. It is safe to re-run and creates no test
accounts.

### Step 8b — Create the first administrator

There are **two ways**, and you only need one. Both create exactly the same
kind of account, with the same password rules.

#### Option A — from the browser (recommended)

Open `https://your-domain/admin/login`. Because the system has no
administrator yet, the page shows a **"Create Administrator Account"** panel
that is not there on a configured system. Follow it, fill in a name, an email
and a password, and submit.

> **This happens once.** The moment that account exists, the panel disappears
> and the `/admin/bootstrap` page refuses every request for the life of this
> installation — including a direct one typed into the address bar. There is no
> way to reopen it, and deleting every admin afterwards does **not** bring it
> back. Choose a password you will not lose.

The password must meet the same rule as every other account in the system: at
least 8 characters with an uppercase letter, a lowercase letter, a number and a
symbol.

If you get "This system has already been set up", an administrator already
exists — sign in, or ask whoever set it up to create your account from
**Staff Accounts**.

#### Option B — from the terminal

For a scripted or headless deploy, or if you would rather not expose the form
at all:

```bash
php artisan db:seed --class=AdminBootstrapSeeder --force
```

This reads `ADMIN_BOOTSTRAP_EMAIL` and `ADMIN_BOOTSTRAP_PASSWORD` from `.env`
(step 4). It applies the same password rule as Option A and will refuse a weak
one with a message naming what is missing.

Using Option B also closes Option A, because Option A only appears while there
are zero administrators.

> **Why there is no default password.** This system ships with no built-in
> administrator account and no default credentials. That is deliberate: a
> shipped default that nobody changes is a way in for anyone who has ever read
> the source. The first account is always one you create.

### Step 9 — Make uploaded images visible *(terminal)*

```bash
php artisan storage:link
```

This creates the shortcut (symlink) that lets images stored outside the public
folder appear on web pages. Without it, the GCash QR code and customer ID
uploads show as broken images.

If Hostinger refuses, run `php artisan storage:link --relative` instead.

### Step 10 — Build the caches *(terminal)*

```bash
php artisan optimize
```

### Step 11 — Turn on HTTPS, after checking it works

1. In hPanel → Security → **SSL**, make sure the certificate is active and
   **Force HTTPS** is on.
2. Open `https://your-domain` in a browser. You must see a **padlock** in the
   address bar. If you do not, stop and fix that first.
3. Only then, edit `.env` and set:
   ```
   FORCE_HTTPS=true
   SESSION_SECURE_COOKIE=true
   ```
4. *(terminal)*
   ```bash
   php artisan config:clear
   php artisan optimize
   ```

### Step 12 — THE GATE. Run this and read the last line *(terminal)*

```bash
php artisan deploy:check
```

This prints one PASS or FAIL line per setting, and then one final line.

* If the last line is **`READY TO GO LIVE`** — continue to step 13.
* If the last line is **`NOT READY — fix the items marked FAIL above`** — the
  site is **not** safe to open to customers. Each FAIL line tells you the
  setting to change and the command to run afterwards. Fix them, then run
  `php artisan deploy:check` again. Repeat until it prints READY.

Do not let anyone use the site while this command says NOT READY. The single
worst case it guards against is `APP_ENV=local` together with `MAIL_MAILER=log`,
which makes the password-reset page print the 6-digit code on screen — meaning
anyone who knows an admin email address can take over that account
without ever seeing that person's inbox.

The output of this command is safe to share when asking for help. It reports
pass or fail without printing any password or key.

### Step 13 — Two things the command cannot check for you

`deploy:check` reads settings. It cannot open a browser or read an inbox, so
confirm these two by hand:

1. **A real reset email arrives.** Use the site's own "Forgot Password" form
   with a real address and confirm the code arrives in that inbox. While you are
   on the verification page, confirm there is **no** yellow "Development mode …
   your code is NNNNNN" banner. If that banner appears, stop — the site is not
   safe to expose, even if step 12 passed.
2. **The QR codes point at `https://`.** Generate **one** table card, scan it
   with a phone, and confirm the address it opens starts with `https://`. Only
   then print the rest. The web address is baked into the printed card, so
   getting this wrong means reprinting every table tent.

### Step 14 — Remove the bootstrap password

Once the first admin has logged in successfully, edit `.env` and blank both
lines so they read:

```
ADMIN_BOOTSTRAP_EMAIL=
ADMIN_BOOTSTRAP_PASSWORD=
```

Then *(terminal)*:

```bash
php artisan config:clear
```

This stops a real password sitting in a file on the server after it is no
longer needed.

---

A note on step 8, for anyone comparing this with older Laravel projects:
`DatabaseSeeder` was changed during this review. It used to create a
`test@example.com` user, which would have put a fake account on the live site.
It now seeds only `SettingsSeeder`.

---

## 6b. Updating the system after it is already live

This section is for the day *after* launch, when the site is running and real
customers are using it, and something needs to change.

It assumes you have never deployed a Laravel application before. Terms used
here — document root, symlink, migration — are explained in the "Project
context" section at the top of this file.

---

### 1. The rule: change it on your computer, then upload. Never edit the server.

**All code changes are made and tested on a developer's own computer first,
then uploaded to the live server. Nobody edits files directly on the live
server.**

That includes "just a quick fix" and "it's only one word". There are three
reasons, and any one of them is enough:

* **There is no undo on the server.** Your computer has the project's full
  history, so a bad change can be reversed in seconds. A file edited through a
  hosting file manager has no history at all. If you break it, the only way
  back is to remember exactly what it used to say.

* **You cannot test on the server without customers seeing it.** There is one
  live site. A change made there is live the instant you save it — including a
  typo that takes down every page for everyone mid-service. On your own
  computer you can break things, see the error, and fix it with nobody
  affected.

* **The next upload will silently erase your edit.** Uploads copy the
  developer's version of a file over the server's version. A change that only
  ever existed on the server is not in that copy, so it is overwritten and
  gone — with no warning and no error message. The classic version of this is
  a fix applied on the server, working fine for two weeks, and then vanishing
  the next time anyone deploys, with nobody able to explain why the old bug is
  back.

If you are not the developer and something needs changing, ask the developer.
Do not open the file manager.

---

### 2. The exception: settings that live only on the server

One file is different. **`.env` lives only on the server, is never uploaded,
and is never taken from a developer's computer.**

`.env` holds the settings that are specific to this one server. Editing it
there is correct and expected. After changing anything in it you must run
`php artisan config:clear`, or the app keeps using the old value.

**Settings that legitimately live only in the server's `.env`:**

| Key | What it is |
|---|---|
| `APP_KEY` | This server's own encryption key |
| `APP_URL` | The real public web address |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_HOST`, `DB_PORT` | The live database and its login |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS` | The real mailbox that sends password-reset emails |
| `ADMIN_BOOTSTRAP_EMAIL`, `ADMIN_BOOTSTRAP_PASSWORD` | The first admin login (blank these once it exists) |
| `FORCE_HTTPS`, `SESSION_SECURE_COOKIE` | On, once the certificate works |
| `APP_ENV`, `APP_DEBUG` | `production` and `false` on this server |

**Of those, these must NEVER be copied from a developer's `.env`, because the
development value is actively dangerous on a live server:**

| Key | Development value | Why copying it is dangerous |
|---|---|---|
| `APP_ENV` | `local` | Half of the condition that prints password-reset codes on screen |
| `APP_DEBUG` | `true` | Error pages would show the database password to whoever triggered the error |
| `MAIL_MAILER` | `log` | Delivers no email at all, and is the other half of the reset-code exposure |
| `APP_KEY` | the developer's key | A key that has been on someone's laptop and possibly in a chat message is not a secret |
| `DB_*` | a local test database | Points the live site at a database that does not exist there |
| `APP_URL` | `http://127.0.0.1:8000` | Password-reset links and printed QR codes would point at a laptop |
| `DEMO_SALES_AUTO_TOPUP` | `true` | Invents fake sales figures in the analytics reports |

The safe habit: never copy a `.env` file in either direction. When a new
setting is needed, add it to the server's `.env` by hand, using
`.env.production.example` as the reference for what it should look like.

---

### 3. How to actually upload an update

This project is deployed by **`git pull` into the existing folder**. That choice
matters: images the café uploads after launch live in `public/uploads/` on the
server only, and a fresh clone or a ZIP-over-the-top would delete every one of
them. Pulling in place leaves them alone.

**Before you start:** take a backup. Database and uploaded files. The procedure
is in [`RECOVERY_PLAN.md`](RECOVERY_PLAN.md) §3.

#### What gets uploaded

If you use `git pull`, git decides this for you correctly and you do not have to
think about it. The list below is for anyone uploading by hand instead.

**Upload these:**

```
app/  bootstrap/  config/  database/  docs/  public/  resources/  routes/
artisan   composer.json   composer.lock
```

**NEVER upload these:**

| Folder / file | Why not |
|---|---|
| **`.env`** | It is the server's own settings file. Uploading yours replaces the live database and mail credentials with development ones, and switches the site into development mode — the reset-code exposure described above. This is the single most damaging file to upload by accident. |
| **`vendor/`** | Third-party libraries. Tens of thousands of files, and the correct set depends on the server's PHP version. Do not upload it — recreate it on the server with `composer install --no-dev --optimize-autoloader`. |
| **`node_modules/`** | Build-tool packages for the developer's machine. This project has no build step and the server never reads this folder. |
| **`storage/` contents** | The server's own logs, sessions, compiled views, and some uploaded images. Overwriting it logs every user out and can delete customer uploads. The *folder structure* must exist; its *contents* are the server's. |
| **`public/storage`** | A symlink created on the server by a command. Copying it from another machine produces a shortcut pointing at a folder that does not exist there. |
| **`.git/`** | Not harmful to the app, but large and unnecessary if you are copying files by hand. |

#### The commands to run after an upload

**All of these require SSH or terminal access** (hPanel → Advanced → SSH
Access, or hPanel → Files → Terminal). Run them in the project folder — the one
containing the file named `artisan` — in this exact order:

```bash
# 1. Get the new code
git pull origin main

# 2. Update third-party libraries, in case the update added one
composer install --no-dev --optimize-autoloader

# 3. Apply any new database structure changes
php artisan migrate --force

# 4. Throw away the old cached settings and views, then rebuild them.
#    Skipping this is the usual reason an update "did not do anything".
php artisan config:clear
php artisan optimize

# 5. Confirm the server is still configured safely
php artisan deploy:check
```

Step 5 must still end with **`READY TO GO LIVE`**. If it does not, the update
changed something it should not have — fix the FAIL lines before telling anyone
the update is done.

Then open the site in a browser and check the thing that was supposed to
change, plus one ordinary customer action (view the menu, add to cart) to
confirm nothing else broke.

#### Two cautions specific to updates

* **Never run `php artisan migrate:fresh` or `migrate:refresh` on the live
  server.** They delete every table and rebuild it empty. That is every order,
  every customer and every sale, gone. Only `php artisan migrate --force` is
  ever correct on a live server.
* **Do not run `php artisan test` on the live server.** This project's tests run
  against whatever database is configured, which on the server is the real one.

---

### 4. If something breaks after an update

Stop. Do not try a second change on top of the first.

Go to **[`RECOVERY_PLAN.md`](RECOVERY_PLAN.md)** — it covers taking backups
(§3), what to check before restoring (§4), and the restore procedure itself
(§5).

The short version: the fastest recovery from a bad code update is `git` on the
server, returning the code to the previous version. Restoring the *database*
from a backup is a much bigger decision, because any orders taken since that
backup are lost — read §4 of that document before doing it.

---

## 7. Security items that need a decision

### 7a. Identity-document handling — what was actually found, and what is fixed

An earlier version of this section said `public/uploads/ids/` contained "five
PWD/Senior ID card photographs" and that ~60 more real ID images sat in
`storage/app/public/discount_ids/`. **Both files were opened and checked during
Pass 4. The characterisation was wrong**, and the corrected version is below —
it matters, because it changes how urgently the team has to react.

#### What the files actually were

`public/uploads/ids/` — five files, and **none of them is a member of the
public's identity document**:

| File | What it actually is |
|---|---|
| `1778000732_pwd_download.jpg` | A stock sample PWD card image found online. Name, address and signature fields are **already blacked out**; the photo is a cartoon avatar and it carries a "PIC·COLLAGE" watermark. |
| `1778000732_senior_download.jpg` | **Byte-identical to the file above** (same MD5) — the same sample reused for the "senior" field. |
| `1778000750_pwd_….jpg` | A project flowchart of the admin dashboard modules. Not an ID. |
| `1778001118_pwd_….jpg` | A screenshot of a voucher test-plan table. Not an ID, but it **does contain two team members' real names**. |
| `1779370542_pwd_….jpg` | A photograph of a motorcycle suspension fork. Not an ID. |

`storage/app/public/discount_ids/` — 60 files, ~70 MB, but only **nine unique
images** (the rest are repeat uploads of the same handful during testing):

| Copies | What it is |
|---|---|
| 55 | Stardew Valley game screenshots (five different farm layouts) |
| 2 | A blank 1×1 pixel PNG, 70 bytes — a minimal test upload |
| 1 | A gym advertising poster |
| 1 | An anime screenshot |
| **1** | **A real, notarised Secretary's Certificate** — see below |

#### The one that is genuinely sensitive

One of the sixty was **a real notarised legal document**: a Secretary's
Certificate for a named Philippine company, carrying two individuals' full
names and handwritten signatures, a company address, and the notary's full
name, roll number, PTR number, MCLE compliance number and residential address.

It was almost certainly uploaded by a team member testing the discount flow
with a document they had to hand. It is not a customer's document, and it is
not a PWD or Senior ID — but it is real personal data about at least three
identifiable people, one of whom (the notary) has no connection to this
project at all.

**It has been deleted** along with the rest of the orphaned uploads (Pass 4
cleanup — see `SECURITY_TESTING_SUMMARY.md`). **The team member who uploaded it
should be told**, both so they know it was published and so nobody uploads a
real document into a test system again.

#### The architectural point, which was correct and is now fixed

The characterisation was wrong; **the underlying finding was right**, and it
was worse than "these files are in git". Identity documents were being written
to the `public` disk, which is exposed through the `public/storage` symlink,
and `public/.htaccess` serves an existing file directly
(`RewriteCond %{REQUEST_FILENAME} !-f`) — so Laravel never ran for those URLs
at all. Proven live in Pass 4 against a running server: an anonymous request
with no cookies and no session returned HTTP 200 and the complete,
byte-identical image.

Had a real customer ever uploaded a real PWD or Senior ID, it would have been
readable by anyone holding the URL, forever, with no authentication — and the
admin order board rendered that URL into the page as `data-image="..."`, so
every such URL reached page source, browser history and screenshots.

**Fixed in Pass 4:**

* Uploads now go to the **`local` disk** (`storage/app`), which is not
  web-reachable.
* They are served only through `/discount-id/{order}`, which authorises every
  request — see `App\Services\DiscountIdAccess`. The URL carries an order id
  and **never a filename**, so a leaked URL is no longer a credential.
* All 60 orphaned files were deleted after verifying that zero database rows
  referenced any of them.
* `public/uploads/ids/` and `storage/app/public/discount_ids/` are both in
  `.gitignore`, so identity documents cannot be committed again.

#### Still open — decisions for the team

1. **The five files in `public/uploads/ids/` are still on disk and still
   publicly served.** They are test junk rather than anyone's ID, so this is
   not urgent, but one of them carries two team members' names and none of
   them is referenced by any database row. **Recommendation: delete them.**
   Nothing in the application will miss them.

2. **The teammate's repository at `https://github.com/DuckonSteroidzz/POMIDA`
   is still public, and this team cannot fix it.** Its single commit still
   contains all five `public/uploads/ids/` files. Verified during Pass 3: an
   anonymous `git ls-remote` against it succeeds with no credentials.

   The owner's repository is now a separate, private one with a fresh history
   that never contained those files, so nothing further can be done from this
   side. **Someone has to ask the owner of that repository to make it
   private.** Given that the files turned out to be test images rather than
   real IDs, this is a tidy-up rather than an emergency — but the screenshot
   containing two team members' names is theirs to decide about.

3. **Longer term**, `discount_cards.id_image` has no upload path in the
   codebase today (the table holds zero rows). If saved discount cards are
   ever built out, their images must go to the `local` disk under
   `discount_cards/`, which `DiscountIdController` already allows for. Do not
   reintroduce the `public` disk for anything holding identity documents.

### 7b. `composer install --no-dev` is not optional

`spatie/laravel-ignition` is a dev dependency, and its `_ignition/*` routes are
currently registered. Ignition has a history of remote-code-execution
advisories. If you upload the development `vendor/` directory instead of
running `composer install --no-dev` on the server, those routes ship to
production. Verify after deploy that
`https://your-domain/_ignition/health-check` returns 404.

### 7c. `php artisan test` runs against the real database

`phpunit.xml` does not override `DB_CONNECTION` or `DB_DATABASE`, so the test
suite runs against whatever `.env` points at. Never run it on the production
server. Consider uncommenting the sqlite in-memory lines in `phpunit.xml`.

### 7d. `DEMO_SALES_AUTO_TOPUP` must stay false

The local `.env` has `DEMO_SALES_AUTO_TOPUP=true`, which fabricates sales
history. It already fails closed — `DemoSalesTopUp::isEnabled()` additionally
requires `APP_ENV=local` — so `APP_ENV=production` alone disables it. Set it to
`false` in the production `.env` anyway.

### 7e. Stray files in the repository

`.bak` (a copy of `AdminAuthController`) and `Exit` (captured `less` help text)
are both committed and both junk. Harmless, but worth deleting before hand-off.

---

## 8. Not changed, on purpose

* `bootstrap/app.php` `withExceptions()` was left empty. The new error views are
  picked up by Laravel's default renderer; no custom handler is needed.
* `trustProxies` — see §5 step 7.
* Nothing was deployed, no live credential was touched, and no data was
  deleted. The local `.env` was temporarily modified for the debug-off test in
  §3 and restored byte-for-byte identical afterwards.

# POMIDA — Backup & Recovery Plan

**Status: the restore procedure in §5 has actually been run.** A real
`mysqldump` of the live `pomida_db` was restored into a separate test database
and compared table by table, and a real archive of the uploaded files was
extracted and checksum-compared against the originals. The evidence is in §7.
The test database and the temporary backup copies were deleted afterwards.

This document answers one question: **the data is gone or wrong — how do we get
the café running again?**

It is deliberately *not* about the two neighbouring documents:

| Document | Question it answers | Relationship to this one |
|---|---|---|
| [`BLANK_SLATE_RESET.md`](BLANK_SLATE_RESET.md) | "How do we *deliberately* wipe demo and test data before hand-off?" | The opposite operation. That one destroys data on purpose; this one puts data back after an accident. **Take a backup using §3 before running anything in that document** — it is the single most likely moment for this plan to be needed. |
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | "How do we get the app onto Hostinger the first time?" | It owns the upload-path map (§7 of that file) and the `git pull` vs. fresh-clone warning. This document does not repeat either; it cites them. |

---

## 1. What has to be backed up

Restoring the database alone does **not** restore the system. Laravel
regenerates caches, compiled views and sessions on its own, but it cannot
reconstruct anything below.

| # | What | Where it lives | Rebuildable? | Why it matters |
|---|---|---|---|---|
| 1 | **The database** `pomida_db` | MySQL | No | Orders, order lines, inventory, stock movements, menu, customers, vouchers, ratings, settings. Everything the café earns, and everything the analytics read. |
| 2 | **Storage-disk uploads** `storage/app/public/` | `discount_ids/` (PWD & Senior ID scans), `settings/gcash/` (the GCash QR customers scan to pay) | No | Not in git. Lose the GCash QR and GCash payment stops working; lose the ID scans and every PWD/Senior discount loses its supporting document. |
| 3 | **Public uploads** `public/uploads/` | `menu-items/`, `categories/`, `ads/`, `ids/` | No | Every menu photo, category tile and ad banner. Rows in `menu_items.image` point at these paths — restoring the database without them leaves a menu of broken images. |
| 4 | **`.env`** | Project root | No | `APP_KEY`, database credentials, SMTP credentials. **Backed up separately and secured — see §3.3.** |

> **Two upload roots, not one.** This is the easy thing to get wrong.
> `storage/app/public/` and `public/uploads/` are *both* live upload
> destinations, written by different code paths in the same app.
> `DEPLOYMENT.md` §7 has the full table of which controller writes where. A
> backup that copies only one of them is an incomplete backup.

### What is not worth backing up

`vendor/`, `node_modules/`, `storage/framework/*` (cache, sessions, compiled
views) and `bootstrap/cache/` are all rebuilt by `composer install` and by the
app itself. Excluding them is what keeps the backup small enough to actually be
taken every day.

---

## 2. How often, and why

| Item | Frequency | Reasoning |
|---|---|---|
| Database | **Daily, automated, after close** | Orders, inventory quantities and `stock_movements` change on every single transaction. A week-old restore means a week of sales gone *and* an inventory screen that no longer matches the shelf. Daily is the point where the worst case is "we lose today", which staff can reconstruct from the printed receipts (§5, step 9). |
| `storage/app/public/` and `public/uploads/` | **Weekly, and always immediately before a redeploy** | These change only when staff upload a new menu photo, replace the GCash QR, or a customer submits an ID at checkout — days apart, not minutes. The *before a redeploy* half is the important one: `DEPLOYMENT.md` §7 documents that a fresh clone, or a ZIP unpacked over the top, destroys `public/uploads/` outright. |
| `.env` | **Whenever it changes** | Roughly once a year. |

**Retention: keep 7 daily database dumps, plus one dump per month.** Seven days
covers the realistic case — damage noticed a few days late. A dump of this
database is around 140 KB, so storage cost is never the reason to keep fewer.

Keep at least one copy **off the server**. A backup that only exists on the
machine that failed is not a backup.

---

## 3. Taking a backup

Run these from the project root (`.../POMIDA`). On the local XAMPP machine the
MySQL tools live in `C:\xampp\mysql\bin\`; on Hostinger they are on the `PATH`.

### 3.1 Database

```bash
mysqldump -u root -p \
  --single-transaction --routines --triggers --events \
  --default-character-set=utf8mb4 \
  pomida_db > backups/pomida_db_$(date +%F).sql
```

`--single-transaction` is what makes this safe to run while the café is open:
it dumps a consistent snapshot without locking the tables, so customers can keep
placing orders during the backup.

### 3.2 Uploaded files

```bash
tar -czf backups/uploads_$(date +%F).tar.gz \
  storage/app/public public/uploads
```

A plain folder copy works just as well where `tar` is not available (on Windows:
select both directories, right-click → *Send to* → *Compressed folder*). What
matters is that **both** directories are in it.

### 3.3 `.env` — separately, and not in the archive

Do **not** put `.env` into the shared backup archive. It holds `APP_KEY`, the
database password and the mail password, and the backup archive is the file most
likely to end up on a USB stick or in a group chat.

Copy it by hand into the team's password manager, or onto an encrypted drive
only the project leader can open.

### 3.4 Where the backups go

`backups/` must not be tracked by git. Add it to `.gitignore` before the first
run:

```
/backups
```

The daily dump should then be copied off the machine — Google Drive, an external
drive, anywhere that is not the server.

> **Privacy note.** A database dump plus `storage/app/public/` together contain
> real customers' names and photographs of their PWD and Senior Citizen ID
> cards. Treat a backup archive with exactly the care you would give the ID
> cards themselves: encrypted or access-controlled storage, and delete old
> copies once they pass the retention window in §2. `DEPLOYMENT.md` §7a covers
> the separate, still-open problem of those files sitting under a publicly
> served path.

### 3.5 Automating the daily dump

On Hostinger: hPanel → **Advanced → Cron Jobs**, once a day at closing time.

```
0 23 * * * cd ~/domains/YOURDOMAIN/public_html && mysqldump -u USER -pPASSWORD --single-transaction DBNAME > backups/pomida_db_$(date +\%F).sql && find backups -name 'pomida_db_*.sql' -mtime +7 -delete
```

The `find ... -delete` half enforces the 7-day retention from §2 so the
directory does not grow without limit. **[HUMAN]** — the credentials, database
name and domain path have to be filled in on deploy day.

Hostinger also takes its own weekly account backups (hPanel → **Files →
Backups**). Those are a useful safety net but are **not** a substitute: weekly
is far coarser than §2 requires, and restoring one is a support-ticket
operation, not something the café can do at 8am on a Tuesday.

---

## 4. Before you restore — read this first

**Restoring overwrites.** Everything currently in the database is replaced by
what was in the backup. If the site is merely *slow*, or showing an error page,
that is not a restore situation — restoring would throw away today's real orders
to fix a problem the backup does not fix.

Restore when, and only when:

* data is **missing or wrong** (records deleted, a reset run by mistake), or
* the database will not start, or is corrupt, or
* a redeploy overwrote the uploads directories.

**Always take a fresh backup of the broken state first (§3.1).** It costs one
minute and it is the only way back if the restore turns out to have been the
wrong call — a broken database still contains today's orders; the backup you are
about to restore does not.

---

## 5. Restore procedure

Written to be followed by a team member who is not a developer. Do the steps in
order. **Do not skip step 2.**

### Step 1 — Put the site into maintenance mode

Stops customers placing orders into a database you are about to replace.

```bash
php artisan down
```

The site now shows a maintenance page; `php artisan up` in step 8 brings it
back. If the app is too broken for `artisan` to run at all, skip this and
instead tell staff to stop taking online orders until step 8.

### Step 2 — Back up the current, broken state

```bash
mysqldump -u root -p --single-transaction pomida_db > backups/BEFORE_RESTORE_$(date +%F_%H%M).sql
```

If this fails because the database is unreadable, write that down and continue.

### Step 3 — Choose which backup you are restoring

```bash
ls -l backups/
```

Pick the **newest dump from before the problem started**. If orders went missing
this morning, yesterday's closing dump is the right one — not one taken an hour
ago, which already contains the damage.

Write the filename down. Every step below uses it.

### Step 4 — Restore the database

```bash
mysql -u root -p pomida_db < backups/pomida_db_2026-08-28.sql
```

(Substitute your filename.) The dump recreates each table before refilling it,
so this replaces the contents wholesale. On a database this size it takes about
a second. **Wait until the command returns to the prompt with no error message.**
If it prints an error, stop and get a developer — do not run it again.

If the database itself is gone or unusable, create an empty one first and
restore into that:

```bash
mysql -u root -p -e "CREATE DATABASE pomida_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p pomida_db < backups/pomida_db_2026-08-28.sql
```

### Step 5 — Restore the uploaded files

Only if images or the GCash QR are missing too. If only data was lost, skip this
step.

```bash
tar -xzf backups/uploads_2026-08-28.tar.gz -C .
```

This puts `storage/app/public/` and `public/uploads/` back. It overwrites files
of the same name and leaves newer, unrelated files alone.

### Step 6 — Clear the caches

The app caches settings and compiled pages. After a restore, those describe the
old data.

```bash
php artisan optimize:clear
```

If uploaded images 404 while menu images load, the storage symlink is missing —
recreate it:

```bash
php artisan storage:link
```

### Step 7 — Check it worked, before letting customers back in

Do all five. It takes two minutes.

1. **Row counts** — they should look like the café's real history, not zeros:
   ```bash
   mysql -u root -p pomida_db -e "SELECT
     (SELECT COUNT(*) FROM orders)      AS orders,
     (SELECT COUNT(*) FROM order_items) AS order_items,
     (SELECT COUNT(*) FROM inventory)   AS inventory,
     (SELECT COUNT(*) FROM menu_items)  AS menu_items;"
   ```
2. **Log in as admin.** The dashboard loads and lists orders.
3. **Open the customer menu.** Every item shows its photo, not a broken-image
   icon. Broken images mean step 5, or the symlink in step 6, was needed.
4. **Open Settings → GCash.** The QR image is there. Without it, GCash customers
   cannot pay.
5. **Open Inventory.** Quantities match the shelf, allowing for whatever was
   sold between the backup and the failure.

### Step 8 — Bring the site back

```bash
php artisan up
```

### Step 9 — Reconcile the gap

Everything that happened between the backup and the failure is gone. Have staff
re-enter it from the printed receipts and a physical stock count:

* orders taken during the gap → key in as **walk-in** orders,
* stock received during the gap → re-enter under **Inventory → Stock In**,
* then recount and correct the on-hand quantities.

This is the real cost of the backup interval, and it is why §2 says daily.

---

## 6. Recovery targets

| | Target | Basis |
|---|---|---|
| **RPO** — how much data a failure can cost | **Up to 24 hours** | Daily dump (§2). The gap is recoverable by hand from receipts (§5 step 9), which is what makes 24h acceptable for a single café. |
| **RTO** — how long the café is down | **Under 30 minutes** | Measured against the real database in §7: the restore itself took 484 ms on a 138 KB dump; the remainder of §5 is checking and cache clearing. Add whatever time it takes to reach the machine and get credentials. |

---

## 7. Evidence — the restore test that was actually run

Run on **29 August 2026** against the live development database, using the exact
commands printed in §3 and §5 of this document. The script is
[`tests/Load/restore-test.sh`](../tests/Load/restore-test.sh).

Restoring over the live database in order to test it would be reckless, so the
dump was restored into a **separate, newly created database** and compared with
the original. That still proves the part of the procedure that can silently be
wrong: whether the dump is complete and whether it loads without error.

### 7.1 Database — restored and compared table by table

1. Took a real dump with the §3.1 command — **137,868 bytes**, no warnings.
2. Created an empty `pomida_db_restore_test`.
3. Restored the dump into it with the §5 step 4 command — no errors, **484 ms**.
4. Compared **all 33 tables**.

**Row counts: 33 tables compared, 0 mismatches.**

| Table | Live | Restored | | Table | Live | Restored |
|---|---|---|---|---|---|---|
| `ads` | 2 | 2 | | `order_item_options` | 24 | 24 |
| `branches` | 4 | 4 | | `order_ratings` | 7 | 7 |
| `cache` | 2 | 2 | | `password_reset_tokens` | 0 | 0 |
| `cache_locks` | 0 | 0 | | `sessions` | 1 | 1 |
| `categories` | 4 | 4 | | `settings` | 16 | 16 |
| `discount_cards` | 0 | 0 | | `stock_movements` | 52 | 52 |
| `failed_jobs` | 0 | 0 | | `subcategories` | 5 | 5 |
| `games_played` | 6 | 6 | | `table_access_codes` | 15 | 15 |
| `help_requests` | 10 | 10 | | `table_sessions` | 10 | 10 |
| `inventory` | 10 | 10 | | `users` | 4 | 4 |
| `jobs` | 0 | 0 | | `user_vouchers` | 0 | 0 |
| `job_batches` | 0 | 0 | | `vouchers` | 2 | 2 |
| `menu_items` | 10 | 10 | | `menu_item_ingredients` | 6 | 6 |
| `menu_item_options` | 10 | 10 | | `menu_options` | 4 | 4 |
| `menu_option_ingredients` | 1 | 1 | | `migrations` | 44 | 44 |
| `notifications` | 61 | 61 | | `orders` | 117 | 117 |
| `order_items` | 183 | 183 | | | | |

Matching row counts alone would not rule out corrupted *contents*, so the
business-critical tables were also compared with `CHECKSUM TABLE ... EXTENDED`,
which hashes every row:

| Table | Live checksum | Restored checksum | |
|---|---|---|---|
| `orders` | 1292385238 | 1292385238 | ✅ identical |
| `order_items` | 1314739525 | 1314739525 | ✅ identical |
| `inventory` | 2589087672 | 2589087672 | ✅ identical |
| `stock_movements` | 1566005161 | 1566005161 | ✅ identical |
| `users` | 4270480147 | 4270480147 | ✅ identical |
| `menu_items` | 1328989275 | 1328989275 | ✅ identical |
| `settings` | 3865837747 | 3865837747 | ✅ identical |

### 7.2 Uploaded files — restored and checksum-compared

1. Archived `storage/app/public` and `public/uploads` with the §3.2 command —
   **76 MB** compressed, 122 files.
2. Extracted the archive to a separate directory.
3. Compared **SHA-256 checksums of all 122 files** against the originals:
   **122/122 byte-identical, 0 differences, 0 missing.**
4. Spot-checked that restored images actually decode, rather than merely
   existing — one file from each upload category:

| What | Restored file decodes as |
|---|---|
| PWD/Senior ID scan | PNG, 1280×1040, 1,299,756 bytes |
| PWD/Senior ID scan (second sample) | PNG, 1280×1040, 1,267,765 bytes |
| GCash QR (`settings/gcash/`) | PNG, 1280×1040, 1,292,338 bytes |
| Menu item image | JPEG, 254×199 |
| Category image | PNG, 555×843 |
| Ad image | PNG, 1378×818 |
| Committed ID scan (`public/uploads/ids/`) | JPEG, 225×225 |

5. Finally, every image path stored in the database was resolved against the
   backup — `orders.discount_id_image`, `menu_items.image`, `categories.image`,
   `ads.image` and the image-valued `settings` rows: **17 references checked, 17
   found in the backup, 0 missing.** A restore from this archive leaves no broken
   image on any page.

### 7.3 Clean-up after the test

The test database was dropped and the temporary dump and archive were deleted,
so no second copy of real customer data or of the ID-card photographs was left
on disk. Only the plain-text checksum lists were kept as evidence.

### 7.4 What the test also found

`storage/app/public/discount_ids/` holds **60 ID-card images that no order
references** — `SELECT COUNT(*) FROM orders WHERE discount_id_image IS NOT NULL`
returns **0**. Every one is an orphan left behind by deleted test orders, because
deleting an order does not delete its uploaded ID file and there is no cleanup
path. They are 70 MB of the 76 MB archive, and they are photographs of identity
documents.

This was the same finding as `DEPLOYMENT.md` §7a. **It was closed in Pass 4:**
all 60 orphans were deleted after verifying zero database rows referenced them,
and new uploads now go to the non-public `local` disk. The note below is kept
because the backup sizing it describes has changed accordingly. It is
repeated here because it bears directly on backups: it inflates every file
backup roughly twelvefold, and it copies that data to every location a backup is
stored. Clearing the orphans would make the file backup ~6 MB and would remove
the main privacy hazard from the archive.

---

## 8. What this plan does not cover

* **Point-in-time recovery.** Restoring to "3:47pm, just before the mistake"
  needs MySQL binary logging, which is not enabled. With daily dumps, the finest
  granularity is one day.
* **Automatic off-site copying.** §3.5 writes the dump to the server. Getting it
  *off* the server is currently a manual step, and depends on which storage the
  team chooses. **[HUMAN]**
* **Failover.** There is one server and one database. A hardware failure means
  downtime while a new host is provisioned and this procedure is run on it.

# Blank-slate reset — plan only, NOT executed

**Nothing in this document has been run.** No row was deleted and no file was
removed during the review that produced it. This is the map you need in order
to do the reset deliberately, as the last step before hand-off, rather than
discovering the entanglements halfway through.

Goal: hand the leader a system with empty menus, empty inventory and no test
orders, while keeping the schema, the game/voucher/analytics machinery, and the
settings intact.

---

## 1. The single most important structural fact

The database has 42 foreign keys. Two of them are `ON DELETE RESTRICT`:

```
order_items.menu_item_id        -> menu_items.id        ON DELETE RESTRICT
order_item_options.menu_option_id -> menu_options.id    ON DELETE RESTRICT
```

**You cannot delete a menu item while any order line references it.** A reset
that starts by clearing `menu_items` fails immediately with a foreign-key
error. Orders must go first.

Everything else cascades or nulls, which cuts both ways — it means one `DELETE`
quietly removes far more than the table you named. The cascade map is in §3.

`TRUNCATE` does not work on any of these tables while the constraints exist.
Use `DELETE`, in the order given in §4.

---

## 2. Where the analytics actually live

There is **no analytics table**. `AnalyticsService` reads three tables live:

```
orders        order_items        order_ratings
```

So "wiping the test data" and "wiping the analytics history" are the same
action. There is no separate analytics schema to protect, and equally no way to
keep the sales history while clearing the orders. That is expected — the leader
wants a clean slate — but say it out loud before you run it so nobody expects
last term's charts to survive.

`games_played` is likewise pure history; the game *configuration* lives in
`vouchers` and in the `game_enabled` settings row.

---

## 3. Table-by-table verdict

Row counts are as of this review.

### Clear — transactional and test data

| Table | Rows | Note |
|-------|------|------|
| `orders` | 101 | 58 of these are fabricated demo sales (`notes = 'SEEDED_DEMO_SALES'`), 43 are real test orders |
| `order_items` | 163 | cascades from `orders` |
| `order_item_options` | 19 | cascades from `order_items` |
| `order_ratings` | 4 | cascades from `orders` |
| `games_played` | 0 | cascades from `orders` |
| `notifications` | 16 | cascades from `orders`; branch/user-scoped rows need an explicit delete |
| `help_requests` | 10 | `order_id` is SET NULL, so rows **survive** an order wipe — delete explicitly |
| `stock_movements` | 33 | cascades from `inventory` |
| `table_sessions` | 0 | `order_id` SET NULL — survives, delete explicitly |
| `table_access_codes` | 1 | regenerate after go-live |
| `user_vouchers` | 3 | customer voucher claims |
| `discount_cards` | 0 | already empty |
| `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | — | runtime scratch, always safe to clear |

### Clear — catalogue the leader will re-enter

| Table | Rows |
|-------|------|
| `menu_items` | 10 |
| `menu_item_options` | 10 |
| `menu_item_ingredients` | 6 |
| `menu_options` | 5 |
| `menu_option_ingredients` | 1 |
| `subcategories` | 6 |
| `categories` | 4 |
| `inventory` | 10 |
| `ads` | 6 |

### Do NOT clear

| Table | Rows | Why |
|-------|------|-----|
| `migrations` | 41 | Clearing it makes Laravel try to re-run every migration. Never touch. |
| `settings` | 14 | Business name, colours, socials, `game_enabled`, GCash config. Keep and edit. |
| `users` | 4 | Keep the real admin. Delete the 2 test customers and 1 test staff. |

### Needs a decision

| Table | Rows | The question |
|-------|------|--------------|
| `branches` | 4 | "Main Branch", "Branch 1", "Branch 2", "Branch 3". Does the café actually have four? Deleting a branch **cascades to its orders, settings, help requests, table codes and sessions**, and SET NULLs its categories, menu items and inventory. Decide before, not after, you enter the real menu. |
| `vouchers` | 6 | This is the spin-wheel prize list, i.e. configuration, not test data. Keep it if the prizes are real; clear it only if the leader wants to define their own. |

---

## 4. The three entanglements that will bite you

These are the reason this needs to be deliberate.

### 4a. `users.points` is a denormalised counter

It is **not** derived from `games_played`. The admin account currently holds
47 points. Wiping orders cascades away `games_played`, but the points balance
stays behind — so accounts start life with phantom points that no history
explains.

**Must be reset explicitly:**

```sql
UPDATE users SET points = 0;
```

### 4b. `vouchers.used_count` is a denormalised counter

Same shape of problem. Clearing `user_vouchers` removes the claims but leaves
`used_count` where it was, so a voucher can read as exhausted (`used_count >=
max_uses`) with no claims to show for it.

**Must be reset explicitly if you keep the voucher rows:**

```sql
UPDATE vouchers SET used_count = 0;
```

### 4c. Deleting rows never deletes the uploaded files

There is no file cleanup anywhere in the order or discount flow. Proof: the
`orders` table has **zero** rows with a `discount_id_image`, yet
`storage/app/public/discount_ids/` holds **60 ID images totalling ~72 MB**.
Those are already orphans.

The reset therefore has a filesystem half (§6) that no SQL will do for you.

---

## 5. Deletion order (SQL)

Take a full backup first — hPanel → Databases → phpMyAdmin → Export, plus a
copy of `public/uploads/` and `storage/app/public/`.

```sql
START TRANSACTION;

-- 1. Orders first. RESTRICT on order_items.menu_item_id means menu items
--    cannot be touched until these are gone.
--    Cascades: order_items -> order_item_options, order_ratings, games_played,
--              and the order-linked notifications.
DELETE FROM orders;

-- 2. Rows that only SET NULL on an order delete, so they survived step 1.
DELETE FROM help_requests;
DELETE FROM table_sessions;
DELETE FROM table_access_codes;
DELETE FROM notifications;

-- 3. Voucher claims. Keep or clear the `vouchers` rows per §3.
DELETE FROM user_vouchers;
DELETE FROM discount_cards;

-- 4. Catalogue. Safe now that no order line references it.
--    categories cascades to subcategories and menu_items;
--    menu_items cascades to menu_item_options and menu_item_ingredients.
DELETE FROM menu_items;
DELETE FROM menu_options;      -- cascades menu_item_options, menu_option_ingredients
DELETE FROM subcategories;
DELETE FROM categories;
DELETE FROM ads;

-- 5. Inventory. Cascades stock_movements and the ingredient link tables;
--    SET NULLs menu_items.inventory_item_id (already empty by now).
DELETE FROM inventory;

-- 6. Test accounts. Keep the real admin — check the id first.
SELECT id, name, email, role FROM users;
-- DELETE FROM users WHERE id <> <the real admin id>;

-- 7. The denormalised counters from §4. Do not skip these.
UPDATE users SET points = 0;
UPDATE vouchers SET used_count = 0;

-- 8. Runtime scratch.
DELETE FROM sessions;
DELETE FROM password_reset_tokens;
DELETE FROM cache;
DELETE FROM cache_locks;
DELETE FROM jobs;
DELETE FROM job_batches;
DELETE FROM failed_jobs;

-- Check the counts look right, then:
COMMIT;
-- or ROLLBACK; if anything is off.
```

Run it inside the transaction and check `SELECT COUNT(*)` on a few tables
before committing. If a foreign-key error appears, `ROLLBACK` and re-read §1 —
it means the order was changed.

---

## 6. The filesystem half

SQL does not touch these. Back them up first.

```
public/uploads/menu-items/     31 files   menu photos
public/uploads/categories/     20 files   category images
public/uploads/ads/             6 files   ad images
public/uploads/ids/             5 files   NOT real IDs (checked Pass 4) — see DEPLOYMENT.md §7a
public/uploads/uploads/         1 file    orphan from an old path bug
storage/app/public/discount_ids/  0 files   emptied in Pass 4 (60 orphans, ~70 MB, removed)
storage/app/public/settings/gcash/          keep unless replacing the QR
```

`public/uploads/` is tracked in git, so deleting those files is a commit, not
just a filesystem change. `storage/app/public/` is not tracked.

`public/uploads/ids/` is the privacy item — read `DEPLOYMENT.md` §7a first.
Pass 4 established these five are test uploads rather than real identity
documents, so deleting them is safe and recommended. Note that removing the
working-tree copies does **not** remove them from the teammate repository at
DuckonSteroidzz/POMIDA, which is still public and outside this team's control.

---

## 7. The alternative: `migrate:fresh`

If the reset happens **before** go-live and there is genuinely nothing worth
keeping, this is cleaner than the SQL above because it cannot leave a stale
counter behind:

```bash
php artisan migrate:fresh --force
php artisan db:seed --force                                  # settings only
php artisan db:seed --class=AdminBootstrapSeeder --force     # the real admin
```

It drops and recreates every table, so all three entanglements in §4 disappear
by construction. `DatabaseSeeder` was changed during this review to seed only
`SettingsSeeder`, so it no longer creates a `test@example.com` account.

You still have to do the filesystem half in §6 yourself.

**This is irreversible and destroys everything, including branches and
vouchers.** Never run it once the café has taken a real order. Treat it as a
pre-launch-only option.

---

## 8. Suggested sequencing

1. Full backup — database export plus `public/uploads/` and
   `storage/app/public/`.
2. Settle the two decisions in §3: how many branches, and keep or clear the
   vouchers.
3. Settle the ID-scan question in `DEPLOYMENT.md` §7a — independently of this
   reset, and ideally sooner.
4. Run the reset (§5 or §7) on a copy first if you can.
5. Filesystem cleanup (§6).
6. Leader enters the real branches, categories, menu items and inventory.
7. Re-issue table access codes and, **only after HTTPS is verified**
   (`DEPLOYMENT.md` §5), generate and print the table QR cards.

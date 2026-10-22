# POMIDA database ERD

Entity Relationship Diagrams and a data dictionary for the POMIDA database, **generated from the database itself**
(nothing is hand-drawn).

> **As of:** the local `pomida_db` (MariaDB 10.4.32) on branch `fix/missing-foreign-keys` (`main` @ `d905de5` plus
> migration `2026_10_22_000000_add_missing_foreign_keys_for_branches_and_vouchers`), read on 2026-10-22.
> All 79 migrations were applied (none pending) at that moment. The live site matches this ERD only after the
> leader has run all migrations there.

## Files

| Path | What it is |
|---|---|
| `POMIDA-ERD.pdf` | One PDF, 6 pages: Full ERD first, then one page per module. Every page has a title and the date. Vector, so zoom as far as you like. |
| `data-dictionary.html` | Self-contained data dictionary (open in any browser): every table, one-line purpose, and a column table (name, type, null, key, default, references / ON DELETE). Grouped by module, with appendices. |
| `png/*.png` | The same 6 diagrams as high-resolution PNGs (full ERD is 15000 x 11765 px). |
| `svg/*.svg` | The same 6 diagrams as SVG. |
| `mmd/*.mmd` | The Mermaid sources (paste into mermaid.live to edit). In the sources `decimal(10_2)` stands for `decimal(10,2)`; Mermaid cannot parse a comma inside a type, and the build restores the comma in the SVG text. |
| `schema-snapshot.json` | Everything read from `information_schema`: tables, columns, indexes, foreign keys. |
| `relationships-snapshot.json` | Every Eloquent relationship method found on the models (via reflection). |
| `cross-check.json` | Result of the automatic completeness check (see below). |
| `tools/` | The scripts that produced all of the above (see "Regenerating"). |

Diagrams: `00-full-erd`, `01-users-branches`, `02-menu`, `03-orders-payments`, `04-inventory`, `05-marketing-loyalty`.

## How to read a diagram

* **Solid line** = a real database foreign key. **Dotted line** = a relationship declared in an Eloquent model that has **no** database foreign key.
* Line ends: `||` exactly one (NOT NULL FK, parent side) · `|o` zero or one (nullable FK, parent side; or a UNIQUE FK on the child side, i.e. one-to-one) · `o{` zero or many.
* Labels read parent -> child: "branches receives orders".
* Columns show type and `PK` / `FK` / `UK`. `UK with x` means unique only together with column x. Types are shown without `UNSIGNED` and integer display widths; enum values and decimal precision are in the data dictionary.
* In a module diagram a line is drawn **in the module that owns the FK column**. A parent table from another module appears as a grey dashed stub (just its `id`). Links pointing *into* a module from other modules are listed in the panel under the diagram instead of being drawn, because hub tables such as `users` and `branches` are referenced by almost everything. The full ERD draws every line.

## Modules

| Module | Tables |
|---|---|
| Users & Branches (3) | users, branches, admin_bootstrap |
| Menu (6) | categories, subcategories, menu_items, menu_item_sizes, menu_options, menu_item_options |
| Orders & Payments (11) | orders, order_items, order_item_options, order_item_size_ingredients, order_discount_beneficiaries, discount_cards, restaurant_tables, table_access_codes, table_sessions, table_session_devices, help_requests |
| Inventory (5) | inventory, menu_item_ingredients, menu_item_size_ingredients, menu_option_ingredients, stock_movements |
| Marketing & Loyalty (10) | vouchers, user_vouchers, ads, games_played, loyalty_awards, free_dessert_rewards, free_dessert_ratings, order_ratings, notifications, settings |

Left out of the diagrams (Laravel framework tables, listed in the dictionary appendix): `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`, `migrations`, `password_reset_tokens`, `sessions`. (`personal_access_tokens` does not exist in this database.)

## Counts

| | |
|---|---|
| Tables in the database | 43 = **35 application** + 8 framework |
| Columns | 448 = **406 in the application tables** + 42 framework |
| Declared foreign keys | **70** (all single-column; all ON UPDATE RESTRICT) |
| Model-only relationships (dotted) | **0** |
| Lines in the full ERD | 70, all solid |
| Eloquent relationship methods inspected | 84, on 31 models (62 distinct links) |

## Relationships that are not plain foreign keys

**Model-only (drawn dotted):** none. The three the first ERD found were given real foreign keys by migration
`2026_10_22_000000_add_missing_foreign_keys_for_branches_and_vouchers`:
`users.branch_id -> branches` (ON DELETE RESTRICT), `orders.voucher_id -> vouchers` (ON DELETE SET NULL; the order keeps
its own discount type and amounts), `free_dessert_rewards.branch_id -> branches` (ON DELETE RESTRICT; SET NULL would make
a one-branch reward usable at every branch). The dotted style stays in the legend for any future model-only link.

**Declared in a model but impossible to use:** none. `Branch::categories()` and `User::vouchers()` pointed at columns
that do not exist and nothing called them, so they were removed from the models.

**Implied links (values match, but there is neither an FK nor a model relationship; not drawn):**
`orders`, `table_sessions`, `help_requests` and `table_access_codes` all point at a table by `(branch_id, table_number)` text, not by `restaurant_tables.id`;
`free_dessert_rewards.used_branch_id` (a branch id) and `stock_movements.reference_id` (an order id when `source = 'order'`) have no FK.

**Real FKs that no model declares as a relationship (still drawn solid):** 8. They are listed in the dictionary appendix.

## Cross-check (automatic, in `tools/build.mjs`)

* every one of the 35 application tables is an entity in the full ERD, and nothing else is;
* every one of the 70 database FKs has a solid line between its child and parent table, and every model-only link (now none) is dotted;
* the rendered full-ERD SVG contains exactly 70 relationship paths;
* each of the 70 links is drawn in exactly one module diagram, and the module diagrams hold 35 tables between them;
* `tools/check-render.mjs` loads each SVG in Chrome: no two text boxes overlap and nothing is cut off;
* `tools/pdf-raster.mjs` re-reads the PDF with pdf.js: 6 pages, real selectable text, title and date on each page;
* the dictionary has 35 table sections and 406 column rows (= the database), and no broken internal links.

## Regenerating (after a migration changes the schema)

The two extractors only **read** (`information_schema`, and model reflection with a hook that aborts any SQL statement). They never write to the database. Start MySQL first, then:

```powershell
# one-time: tool install OUTSIDE the repo (uses your installed Chrome, no browser download)
$env:PUPPETEER_SKIP_DOWNLOAD = "1"
npm install --prefix $env:TEMP\erd-tools @mermaid-js/mermaid-cli@12.0.0 pdfjs-dist@4.10.38

php docs/erd/tools/extract_schema.php    $PWD.Path docs/erd/schema-snapshot.json
php docs/erd/tools/extract_relations.php $PWD.Path docs/erd/relationships-snapshot.json

cd docs/erd
node tools/build.mjs --schema schema-snapshot.json --relations relationships-snapshot.json --out . `
     --tools $env:TEMP\erd-tools --source "main @ <commit>"
node tools/check-render.mjs svg $env:TEMP\erd-tools
```

Built with `@mermaid-js/mermaid-cli` 12.0.0 (mermaid 12.2.0, ELK layout, which the CLI bundles) in Chrome, `puppeteer-core` 25.15.0.
The authored parts (module grouping, relationship labels, one-line table purposes) live at the top of `tools/erd-model.mjs`; the build stops with an error if a table has no module or purpose, or a link has no label.

// Shared model: reads the two JSON snapshots and derives everything the diagrams and the
// data dictionary need. Nothing here is hand-drawn: tables, columns, keys and FKs come from
// information_schema (schema.json); model relationships come from Eloquent reflection
// (relations.json). Only the human-facing words (module grouping, line labels, one-line
// table purposes) are authored below.
import fs from 'node:fs';

// ---------------------------------------------------------------------------------------
// Authored: Laravel framework tables (excluded from the diagrams, listed in the appendix)
// ---------------------------------------------------------------------------------------
export const FRAMEWORK = {
  migrations: 'Laravel: which migration files have run.',
  cache: 'Laravel cache store (database driver).',
  cache_locks: 'Laravel cache atomic locks.',
  jobs: 'Laravel queue: pending jobs.',
  job_batches: 'Laravel queue: job batch tracking.',
  failed_jobs: 'Laravel queue: failed jobs.',
  sessions: 'Laravel database session store (browser sessions).',
  password_reset_tokens: 'Laravel password-reset tokens.',
  personal_access_tokens: 'Laravel Sanctum API tokens.',
};

// ---------------------------------------------------------------------------------------
// Authored: modules (every application table in exactly one module)
// ---------------------------------------------------------------------------------------
export const MODULES = [
  { key: 'users', slug: '01-users-branches', title: 'Users & Branches', short: 'Users and Branches',
    fill: '#dbeafe', stroke: '#2563eb',
    tables: ['users', 'branches', 'admin_bootstrap'] },
  { key: 'menu', slug: '02-menu', title: 'Menu', short: 'Menu',
    fill: '#fef3c7', stroke: '#d97706',
    tables: ['categories', 'subcategories', 'menu_items', 'menu_item_sizes', 'menu_options', 'menu_item_options'] },
  { key: 'orders', slug: '03-orders-payments', title: 'Orders & Payments', short: 'Orders and Payments',
    fill: '#dcfce7', stroke: '#16a34a',
    tables: ['orders', 'order_items', 'order_item_options', 'order_item_size_ingredients', 'order_discount_beneficiaries',
             'discount_cards', 'restaurant_tables', 'table_access_codes', 'table_sessions', 'table_session_devices', 'help_requests'] },
  { key: 'inventory', slug: '04-inventory', title: 'Inventory', short: 'Inventory',
    fill: '#fce7f3', stroke: '#db2777',
    tables: ['inventory', 'menu_item_ingredients', 'menu_item_size_ingredients', 'menu_option_ingredients', 'stock_movements'] },
  { key: 'marketing', slug: '05-marketing-loyalty', title: 'Marketing & Loyalty', short: 'Marketing and Loyalty',
    fill: '#ede9fe', stroke: '#7c3aed',
    tables: ['vouchers', 'user_vouchers', 'ads', 'games_played', 'loyalty_awards', 'free_dessert_rewards',
             'free_dessert_ratings', 'order_ratings', 'notifications', 'settings'] },
];

// ---------------------------------------------------------------------------------------
// Authored: relationship labels, read parent -> child ("branches receives orders").
// Key = "<child_table>.<fk_column>".
// ---------------------------------------------------------------------------------------
export const LABELS = {
  'admin_bootstrap.user_id': 'is recorded as first admin in',
  'ads.branch_id': 'runs',
  'discount_cards.user_id': 'owns',
  'discount_cards.verified_by': 'verifies',
  'free_dessert_ratings.order_id': 'receives',
  'free_dessert_ratings.order_item_id': 'has dessert rated in',
  'free_dessert_ratings.user_id': 'writes',
  'free_dessert_rewards.loyalty_award_id': 'unlocks',
  'free_dessert_rewards.menu_item_id': 'is given as',
  'free_dessert_rewards.order_id': 'is redeemed on',
  'free_dessert_rewards.order_item_id': 'is redeemed as',
  'free_dessert_rewards.user_id': 'earns',
  'free_dessert_rewards.voucher_id': 'defines',
  'free_dessert_rewards.branch_id': 'issues',
  'games_played.order_id': 'unlocks spin',
  'games_played.user_id': 'spins',
  'help_requests.branch_id': 'receives',
  'help_requests.order_id': 'is linked to',
  'inventory.branch_id': 'stocks',
  'loyalty_awards.order_id': 'triggers',
  'loyalty_awards.user_id': 'receives',
  'menu_items.branch_id': 'offers',
  'menu_items.category_id': 'groups',
  'menu_items.inventory_item_id': 'is stock source of',
  'menu_items.subcategory_id': 'groups',
  'menu_item_ingredients.inventory_id': 'is used in',
  'menu_item_ingredients.menu_item_id': 'has recipe line',
  'menu_item_options.menu_item_id': 'offers option',
  'menu_item_options.menu_option_id': 'is offered on',
  'menu_item_sizes.menu_item_id': 'comes in',
  'menu_item_size_ingredients.inventory_id': 'is used in',
  'menu_item_size_ingredients.menu_item_size_id': 'has recipe line',
  'menu_option_ingredients.inventory_id': 'is used in',
  'menu_option_ingredients.menu_option_id': 'has recipe line',
  'notifications.branch_id': 'is audience of',
  'notifications.order_id': 'triggers',
  'notifications.user_id': 'receives',
  'orders.branch_id': 'receives',
  'orders.discount_card_id': 'discounts',
  'orders.processed_by': 'processes',
  'orders.user_id': 'places',
  'orders.voucher_id': 'is applied to',
  'order_discount_beneficiaries.order_id': 'lists',
  'order_items.menu_item_id': 'is sold as',
  'order_items.menu_item_size_id': 'is sold as',
  'order_items.order_id': 'contains',
  'order_item_options.menu_option_id': 'is chosen as',
  'order_item_options.order_item_id': 'has add-on',
  'order_item_size_ingredients.inventory_id': 'is deducted via',
  'order_item_size_ingredients.order_item_id': 'freezes recipe line',
  'order_ratings.order_id': 'receives',
  'order_ratings.user_id': 'writes',
  'restaurant_tables.branch_id': 'has table',
  'restaurant_tables.code_rotated_by': 'rotates code of',
  'settings.branch_id': 'configures',
  'stock_movements.inventory_id': 'logs',
  'stock_movements.user_id': 'records',
  'subcategories.category_id': 'contains',
  'table_access_codes.branch_id': 'issues code for',
  'table_access_codes.issued_by': 'issues',
  'table_sessions.branch_id': 'hosts',
  'table_sessions.opened_by': 'opens',
  'table_sessions.order_id': 'is placed in',
  'table_sessions.released_by': 'releases',
  'table_session_devices.table_session_id': 'has device',
  'user_vouchers.issued_by': 'issues',
  'user_vouchers.user_id': 'claims',
  'user_vouchers.voucher_id': 'is claimed as',
  'users.branch_id': 'employs',
  'vouchers.branch_id': 'scopes',
};

// ---------------------------------------------------------------------------------------
// Authored: one-line purpose per table (data dictionary)
// ---------------------------------------------------------------------------------------
export const PURPOSE = {
  admin_bootstrap: 'One-row guard (singleton flag) recording that the first admin account was created from the web, so that one-time setup can never run twice.',
  ads: 'Promotional adverts shown on customer pages (game, menu, cart, orders), optionally limited to one branch and to a start/end window.',
  branches: 'The restaurant branches: name, unique code, address, contact, opening hours and active / main-branch flags.',
  categories: 'Top-level menu categories (shared by all branches); can be archived.',
  discount_cards: 'PWD / Senior Citizen ID cards held by a customer account, with staff verification and expiry.',
  free_dessert_ratings: 'The rating and comment a customer leaves for the free dessert (stamp-card reward) received on an order; one per order.',
  free_dessert_rewards: 'Stamp-card rewards earned by a customer: a free menu item or voucher terms, an owner-bound RWD code, issuing branch, status and redemption details.',
  games_played: 'The server-side ledger of Spin & Win spins: one row per accepted spin for an order, with the points awarded.',
  help_requests: 'Dine-In "Request Assistance" calls raised from a table, tracked pending, assisting, resolved.',
  inventory: 'Ingredient / stock items per branch with quantity, unit, low-stock alert level and unit cost; supports archive / restore.',
  loyalty_awards: 'Ledger of loyalty bonuses and spin reversals paid to a customer (welcome, order bonus, stamp reward, ...); unique per user, type and award key.',
  menu_items: 'Items on the menu: price, cost, category / sub-category, branch scope, stock flags and free-dessert eligibility; can be archived.',
  menu_item_ingredients: 'Recipe lines: how much of each inventory item one menu item consumes.',
  menu_item_options: 'Link table: which add-on options can be chosen on which menu item (many-to-many).',
  menu_item_sizes: 'Size variants (e.g. Regular / Large) of a menu item, each with its own price.',
  menu_item_size_ingredients: 'Recipe lines for one specific size of a menu item.',
  menu_options: 'Add-ons / options with an extra price, reusable across menu items; can be archived.',
  menu_option_ingredients: 'Inventory consumed by one add-on option.',
  notifications: 'In-app notifications for customers and for staff / admin (audience, type, read and dismissed state).',
  orders: 'Customer orders (Dine-In, Pick-Up, Walk-In): totals, discount, voucher / reward code, payment, status and timestamps.',
  order_discount_beneficiaries: 'The PWD / Senior beneficiaries (name, ID number) behind the discount on an order.',
  order_items: 'Lines of an order: a snapshot of item name, size, price, quantity, free-dessert flag and ingredient cost.',
  order_item_options: 'Add-ons chosen on an order line, with a snapshot of the option name and price.',
  order_item_size_ingredients: 'Per-order-line frozen copy of the size recipe, used to deduct stock when the order completes.',
  order_ratings: 'The rating a customer gives an order; one per order.',
  restaurant_tables: 'Physical Dine-In tables per branch, with the permanent access code printed on the table QR and a code-rotation audit.',
  settings: 'Key / value application settings, global (branch NULL) or per branch.',
  stock_movements: 'Audit trail of every stock increase or decrease (type, amount, quantity after, source, user).',
  subcategories: 'Sub-groups inside a category; can be archived.',
  table_access_codes: 'Short-lived one-time table access codes issued by staff for a branch table, with expiry and claim details.',
  table_sessions: 'One Dine-In occupancy of a physical table: session token, live lock, activity times, linked order and release details.',
  table_session_devices: 'Devices (browser tokens) that joined a shared table session.',
  users: 'All accounts: customers, staff, supervisors and the admin (role), with points, ID-card details and home branch.',
  user_vouchers: 'Voucher claims: a voucher held by an account or by a guest claim code, issued at the counter or won on the wheel, with used state.',
  vouchers: 'Voucher definitions: code, fixed / percent discount, minimum order, validity window, use limits, branch scope and wheel flag.',
};

// Authored: implicit links (value matches only; no FK and no Eloquent relationship)
export const IMPLICIT = [
  { from: 'orders (branch_id, table_number)', to: 'restaurant_tables (branch_id, table_number)', note: 'A Dine-In order stores the table number as text, not the table id.' },
  { from: 'table_sessions (branch_id, table_number)', to: 'restaurant_tables (branch_id, table_number)', note: 'Same text match; table_sessions has no restaurant_tables FK.' },
  { from: 'help_requests (branch_id, table_number)', to: 'restaurant_tables (branch_id, table_number)', note: 'Same text match.' },
  { from: 'table_access_codes (branch_id, table_number)', to: 'restaurant_tables (branch_id, table_number)', note: 'Same text match.' },
  { from: 'free_dessert_rewards.used_branch_id', to: 'branches.id', note: 'Branch where the reward was redeemed; plain column, no FK and no model relationship.' },
  { from: 'stock_movements.reference_id', to: 'orders.id', note: 'Holds the order id when source = "order" (InventoryDeductionService); not a declared FK.' },
];

// ---------------------------------------------------------------------------------------
export function loadModel(schemaPath, relationsPath) {
  const schema = JSON.parse(fs.readFileSync(schemaPath, 'utf8'));
  const relations = JSON.parse(fs.readFileSync(relationsPath, 'utf8'));

  const allNames = Object.keys(schema.tables).sort();
  const frameworkInDb = allNames.filter((n) => n in FRAMEWORK);
  const appNames = allNames.filter((n) => !(n in FRAMEWORK));

  // ---- integrity of the authored parts against the database
  const moduleOf = {};
  for (const m of MODULES) for (const t of m.tables) {
    if (moduleOf[t]) throw new Error(`table ${t} is in two modules`);
    moduleOf[t] = m;
  }
  const missing = appNames.filter((n) => !moduleOf[n]);
  const ghost = Object.keys(moduleOf).filter((n) => !schema.tables[n]);
  if (missing.length || ghost.length) throw new Error(`module map mismatch. unassigned: ${missing}; not in db: ${ghost}`);
  const noPurpose = appNames.filter((n) => !PURPOSE[n]);
  if (noPurpose.length) throw new Error(`no purpose text for: ${noPurpose}`);

  // ---- index helpers
  const col = (t, c) => schema.tables[t].columns.find((x) => x.name === c);
  for (const t of appNames) {
    const T = schema.tables[t];
    T.pkCols = (T.indexes.find((i) => i.primary) || { columns: [] }).columns;
    T.uniqueSingle = new Set(T.indexes.filter((i) => i.unique && !i.primary && i.columns.length === 1).map((i) => i.columns[0]));
    T.uniqueComposite = T.indexes.filter((i) => i.unique && !i.primary && i.columns.length > 1);
    T.fkCols = new Set(T.fks.flatMap((f) => f.columns));
  }

  // ---- links: real foreign keys
  const links = [];
  for (const t of appNames) for (const f of schema.tables[t].fks) {
    if (f.columns.length !== 1) throw new Error(`composite FK on ${t}: not supported by this builder`);
    const c = col(t, f.columns[0]);
    const unique = schema.tables[t].uniqueSingle.has(f.columns[0]);
    links.push({ kind: 'fk', child: t, col: f.columns[0], parent: f.ref_table, parentCol: f.ref_columns[0],
      nullable: c.null, unique, onDelete: f.on_delete, onUpdate: f.on_update, fkName: f.name });
  }
  const fkKey = new Set(links.map((l) => `${l.child}.${l.col}->${l.parent}`));

  // ---- links: what the Eloquent models declare
  const codeLinks = new Map();
  const addCode = (child, c, parent, via) => {
    const k = `${child}.${c}->${parent}`;
    if (!codeLinks.has(k)) codeLinks.set(k, { child, col: c, parent, via: [] });
    codeLinks.get(k).via.push(via);
  };
  for (const r of relations) {
    const via = `${r.model}::${r.method}()`;
    if (r.kind === 'BelongsToMany') {
      addCode(r.pivot_table, r.pivot_col_to_this, r.this_table, via);
      addCode(r.pivot_table, r.pivot_col_to_related, r.related_table, via);
    } else addCode(r.child_table, r.child_col, r.parent_table, via);
  }
  const modelOnly = [];
  const deadModel = [];
  for (const [k, l] of codeLinks) {
    if (fkKey.has(k)) continue;
    const T = schema.tables[l.child];
    const c = T && T.columns.find((x) => x.name === l.col);
    if (!c) { deadModel.push(l); continue; }
    modelOnly.push({ kind: 'model', child: l.child, col: l.col, parent: l.parent, parentCol: 'id',
      nullable: c.null, unique: schema.tables[l.child].uniqueSingle.has(l.col), via: l.via });
  }
  links.push(...modelOnly);
  const fksWithoutModel = [...fkKey].filter((k) => !codeLinks.has(k));

  for (const l of links) {
    l.label = LABELS[`${l.child}.${l.col}`];
    if (!l.label) throw new Error(`no label for ${l.child}.${l.col}`);
  }
  const unusedLabels = Object.keys(LABELS).filter((k) => !links.some((l) => `${l.child}.${l.col}` === k));
  if (unusedLabels.length) throw new Error(`labels for non-existent links: ${unusedLabels}`);

  return { schema, appNames, frameworkInDb, moduleOf, links, modelOnly, deadModel, fksWithoutModel, relations, col };
}

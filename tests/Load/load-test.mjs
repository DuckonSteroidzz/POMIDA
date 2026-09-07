/**
 * POMIDA — concurrent order-placement load test.
 *
 * Each simulated customer is a REAL guest browser session against the running
 * app: it fetches the menu (session cookie + CSRF token), selects a branch,
 * adds a real menu item to its real session cart, and then — at a barrier, all
 * at once — POSTs /customer/place-order.
 *
 * Nothing is stubbed or mocked. The requests go over HTTP to a real Laravel
 * server and hit the real MySQL database.
 *
 * POMIDA_BASE may list SEVERAL origins, comma separated. This matters: the PHP
 * built-in server behind `php artisan serve` handles ONE request at a time on
 * Windows (PHP_CLI_SERVER_WORKERS is POSIX-only), so pointing every virtual
 * customer at a single origin measures queueing, not parallelism. Listing
 * several `artisan serve` workers — same code, same MySQL database — is what
 * puts genuinely simultaneous writes on the database, which is the condition
 * the anti-duplication guarantees actually have to survive.
 *
 * Usage: node tests/Load/load-test.mjs <concurrency> <label>
 */

const ORIGINS = (process.env.POMIDA_BASE ?? 'http://127.0.0.1:8000')
  .split(',')
  .map(s => s.trim())
  .filter(Boolean);

const BRANCH_ID = Number(process.env.POMIDA_BRANCH ?? 1);
const MENU_ITEM_ID = Number(process.env.POMIDA_ITEM ?? 33); // Cheesy Pizza — real 4-ingredient recipe
const CONCURRENCY = Number(process.argv[2] ?? 10);
const LABEL = process.argv[3] ?? `tier-${CONCURRENCY}`;

/** One isolated cookie jar = one customer's browser. */
class Jar {
  constructor() { this.c = new Map(); }
  absorb(res) {
    const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
    for (const line of raw) {
      const [pair] = line.split(';');
      const i = pair.indexOf('=');
      if (i > 0) this.c.set(pair.slice(0, i).trim(), pair.slice(i + 1).trim());
    }
  }
  header() { return [...this.c].map(([k, v]) => `${k}=${v}`).join('; '); }
}

async function get(base, jar, path) {
  const res = await fetch(base + path, {
    headers: { Cookie: jar.header(), 'User-Agent': 'POMIDA-LoadTest' },
    redirect: 'manual',
  });
  jar.absorb(res);
  return res;
}

async function post(base, jar, path, fields) {
  const res = await fetch(base + path, {
    method: 'POST',
    headers: {
      Cookie: jar.header(),
      'Content-Type': 'application/x-www-form-urlencoded',
      'User-Agent': 'POMIDA-LoadTest',
    },
    body: new URLSearchParams(fields).toString(),
    redirect: 'manual',
  });
  jar.absorb(res);
  return res;
}

const tokenFrom = html => (html.match(/name="_token"\s+value="([^"]+)"/) ?? [])[1] ?? null;

/** Steps 1-3: get this customer to a ready-to-checkout cart. Not measured. */
async function prepare(id) {
  const base = ORIGINS[id % ORIGINS.length];
  const jar = new Jar();

  const menu = await get(base, jar, '/customer/menu');
  const token = tokenFrom(await menu.text());
  if (!token) throw new Error(`vu${id}: no CSRF token on /customer/menu`);

  await post(base, jar, '/customer/select-branch', { _token: token, branch_id: BRANCH_ID });
  await post(base, jar, '/customer/cart/add', { _token: token, item_id: MENU_ITEM_ID, quantity: 1 });

  // Confirm the cart really holds the item before this VU counts as ready.
  const cartHtml = await (await get(base, jar, '/customer/cart')).text();

  return { id, base, jar, token: tokenFrom(cartHtml) ?? token, ready: cartHtml.includes('mainOrderForm') };
}

/** Step 4: the measured request. */
async function placeOrder(vu) {
  const started = performance.now();
  try {
    const res = await post(vu.base, vu.jar, '/customer/place-order', {
      _token: vu.token,
      order_type: 'pick_up',
      payment_method: 'cash',
      branch_id: BRANCH_ID,
      'items[0][menu_item_id]': MENU_ITEM_ID,
      'items[0][quantity]': 1,
    });
    const ms = performance.now() - started;
    const location = res.headers.get('location') ?? '';
    // FriendlyThrottleResponse stamps this header on every rate-limit refusal,
    // whether it answers with a 429 or with the friendly redirect back to the
    // cart. It is the unambiguous signal that a request was refused BY THE RATE
    // LIMITER, on purpose, rather than having failed — so throttled traffic is
    // never miscounted as an error.
    const throttled = res.headers.get('x-ratelimit-rejected') === '1';
    let outcome;

    if (throttled || res.status === 429) outcome = 'rate_limited';
    else if (res.status >= 500) outcome = 'server_error';
    else if (res.status === 302 && /\/customer\/orders/.test(location)) outcome = 'success';
    // throttle.friendly turns a 429 into a 302 back to the cart the customer
    // was on, so a cart redirect is a refusal, not a placement.
    else if (res.status === 302 && /\/customer\/cart/.test(location)) outcome = 'rejected_to_cart';
    else if (res.status === 302) outcome = 'redirect_other';
    else outcome = 'other';

    return { vu: vu.id, base: vu.base, ms, status: res.status, location, throttled, outcome };
  } catch (e) {
    return {
      vu: vu.id, base: vu.base, ms: performance.now() - started,
      status: 0, location: '', outcome: 'network_error', error: String(e),
    };
  }
}

const pct = (sorted, p) => sorted[Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1)];

(async () => {
  process.stderr.write(`[${LABEL}] preparing ${CONCURRENCY} customer sessions across ${ORIGINS.length} worker(s)...\n`);
  const vus = await Promise.all(Array.from({ length: CONCURRENCY }, (_, i) => prepare(i + 1)));
  const notReady = vus.filter(v => !v.ready).length;
  process.stderr.write(`[${LABEL}] ready ${vus.length - notReady}/${vus.length}; firing all at once...\n`);

  const wallStart = performance.now();
  const results = await Promise.all(vus.map(placeOrder)); // the barrier: all in flight together
  const wallMs = performance.now() - wallStart;

  const times = results.map(r => r.ms).sort((a, b) => a - b);
  const tally = {};
  for (const r of results) tally[r.outcome] = (tally[r.outcome] ?? 0) + 1;

  console.log(JSON.stringify({
    label: LABEL,
    concurrency: CONCURRENCY,
    workers: ORIGINS.length,
    sessions_not_ready: notReady,
    wall_ms: Math.round(wallMs),
    throughput_rps: +(CONCURRENCY / (wallMs / 1000)).toFixed(2),
    outcomes: tally,
    response_ms: {
      min: Math.round(times[0]),
      avg: Math.round(times.reduce((a, b) => a + b, 0) / times.length),
      p50: Math.round(pct(times, 50)),
      p95: Math.round(pct(times, 95)),
      max: Math.round(times[times.length - 1]),
    },
    detail: results.map(r => ({ vu: r.vu, ms: Math.round(r.ms), status: r.status, throttled: !!r.throttled, outcome: r.outcome, location: r.location })),
  }, null, 2));
})();

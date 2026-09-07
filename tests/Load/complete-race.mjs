/**
 * POMIDA — concurrent order-COMPLETION test.
 *
 * Placing an order does not touch inventory; stock is deducted when staff
 * completes the order (AdminController::completeOrder). So the "no
 * double-deducted stock" guarantee lives on a different endpoint from the one
 * load-test.mjs exercises, and it needs its own concurrent test.
 *
 * The shape being tested: several staff sessions clicking Complete on the SAME
 * order at the same instant. The guarantee is that exactly ONE of them wins,
 * one set of stock_movements rows is written, and the losers are refused
 * gracefully rather than deducting the order a second time.
 *
 * Usage: node tests/Load/complete-race.mjs <orderId,orderId,...> <clicksPerOrder>
 */

const ORIGINS = (process.env.POMIDA_BASE ?? 'http://127.0.0.1:8000')
  .split(',').map(s => s.trim()).filter(Boolean);
const EMAIL = process.env.POMIDA_ADMIN_EMAIL;
const PASSWORD = process.env.POMIDA_ADMIN_PASSWORD;
const ORDER_IDS = (process.argv[2] ?? '').split(',').filter(Boolean).map(Number);
const CLICKS = Number(process.argv[3] ?? 4);

class Jar {
  constructor() { this.c = new Map(); }
  absorb(res) {
    for (const line of (res.headers.getSetCookie ? res.headers.getSetCookie() : [])) {
      const [pair] = line.split(';');
      const i = pair.indexOf('=');
      if (i > 0) this.c.set(pair.slice(0, i).trim(), pair.slice(i + 1).trim());
    }
  }
  header() { return [...this.c].map(([k, v]) => `${k}=${v}`).join('; '); }
}

const tokenFrom = h => (h.match(/name="_token"\s+value="([^"]+)"/) ?? [])[1] ?? null;

async function req(base, jar, path, method = 'GET', fields = null) {
  const res = await fetch(base + path, {
    method,
    headers: {
      Cookie: jar.header(),
      'User-Agent': 'POMIDA-LoadTest',
      ...(fields ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}),
    },
    body: fields ? new URLSearchParams(fields).toString() : undefined,
    redirect: 'manual',
  });
  jar.absorb(res);
  return res;
}

/** One logged-in staff browser. */
async function staffSession(i) {
  const base = ORIGINS[i % ORIGINS.length];
  const jar = new Jar();
  const token = tokenFrom(await (await req(base, jar, '/admin/login')).text());
  const res = await req(base, jar, '/admin/login', 'POST', { _token: token, email: EMAIL, password: PASSWORD });
  const homeRes = await req(base, jar, '/admin/home');
  const home = await homeRes.text();
  // A 200 on /admin/home is the only proof the admin guard accepted this
  // session. Checking the body alone is not enough: an unauthenticated request
  // is REDIRECTED to the login page, and a 302 has an empty body, which would
  // read as "no password field, therefore logged in".
  const loggedIn = homeRes.status === 200;
  return {
    base, jar, loggedIn,
    token: tokenFrom(home) ?? token,
    loginStatus: res.status,
    loginLocation: res.headers.get('location') ?? '',
    homeStatus: homeRes.status,
  };
}

(async () => {
  // Logged in one at a time, on purpose: /admin/login is throttled 10/min per
  // IP (routes/web.php), and every session here comes from 127.0.0.1. Firing
  // them together spends the whole allowance at once and some sessions come
  // back unauthenticated, which would silently turn the race below into a race
  // between anonymous visitors.
  const sessions = [];
  for (let i = 0; i < CLICKS; i++) sessions.push(await staffSession(i + 1));

  const bad = sessions.filter(s => !s.loggedIn);
  if (bad.length) {
    console.error(JSON.stringify({
      error: 'staff login failed',
      detail: bad.map(b => ({ loginStatus: b.loginStatus, loginLocation: b.loginLocation, homeStatus: b.homeStatus })),
    }));
    process.exit(1);
  }

  const out = [];

  for (const orderId of ORDER_IDS) {
    const started = performance.now();
    // Every session clicks Complete on the SAME order, simultaneously.
    const results = await Promise.all(sessions.map(async (s, i) => {
      const res = await req(s.base, s.jar, `/admin/orders/${orderId}/complete`, 'POST', {
        _token: s.token, _method: 'PUT',
      });
      return { click: i + 1, status: res.status, location: res.headers.get('location') ?? '' };
    }));
    out.push({ order_id: orderId, clicks: CLICKS, wall_ms: Math.round(performance.now() - started), results });
  }

  console.log(JSON.stringify(out, null, 2));
})();

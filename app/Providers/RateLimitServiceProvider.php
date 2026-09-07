<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named rate limiters for the customer-facing endpoints.
 *
 * Why these are not plain `throttle:10,1`
 * ---------------------------------------
 * Laravel's default throttle key is the client IP. For a login form that is
 * exactly right — one attacker, one address, and the whole point is to stop
 * them guessing. For ORDERING it is wrong in a way that only shows up once the
 * app is in a real café:
 *
 *   Every customer on the shop's wi-fi shares ONE public IP.
 *
 * So `throttle:10,1` on POST /customer/place-order does not mean "ten orders a
 * minute per customer", it means "ten orders a minute for the entire café".
 * The eleventh customer to tap Place Order in a busy minute is refused, and
 * they have done nothing wrong. The same applies during a demo, where every
 * tab is 127.0.0.1 and one tester's clicking blocks their own next order —
 * which is exactly what was reported: 429 "lagi" while just placing orders.
 *
 * The fix is not to drop the limit (these are money endpoints and the
 * protection is real), it is to key it on the thing that actually identifies
 * one ordering party — their session — and to keep a much higher per-IP ceiling
 * underneath as the anti-automation backstop, since a script that discards
 * cookies gets a fresh session per request and would otherwise be unlimited.
 * Laravel applies every Limit returned from an array, so a request has to pass
 * both.
 *
 * Auth endpoints stay PURE PER-IP: login, registration, password reset and
 * verification are the cases where "one address, many attempts" IS the attack,
 * and where a shared café IP being throttled together is the correct, intended
 * behaviour. That has not changed.
 *
 * What DID change — 2026-09-01, Pass 7
 * ------------------------------------
 * The sentence above used to end "...and stay pure per-IP in routes/web.php",
 * i.e. written as raw `throttle:N,M`. That conflated two separate things, and
 * the second one was never a decision at all:
 *
 *   1. WHAT the counter is keyed on — the IP. Deliberate, correct, unchanged.
 *   2. WHICH ACTIONS share that counter — all of them. An accident.
 *
 * Laravel's unnamed `throttle:N,M` builds its key as
 * `$prefix . resolveRequestSignature($request)` with `$prefix = ''`
 * (ThrottleRequests::handle). N and M are NOT part of the key. So every raw
 * throttled route on one IP increments ONE shared counter, and each route only
 * compares that shared total against its own limit.
 *
 * Measured: admin.login.post, admin.users.password.update,
 * admin.account.password.update and admin.notifications.unread-count all
 * resolved to the byte-identical key 5c785c03…5fba.
 *
 * The consequence the owner actually hit: the notification bell is in the admin
 * layout and polls unread-count every 20 seconds. Those polls are throttle:60,1
 * but they spend the SAME counter admin.login.post checks against 3. About a
 * minute on any admin page, one Set Password, a logout — and the next login was
 * refused with 429 before the password was even looked at.
 *
 * The three sensitive admin actions below are therefore now NAMED limiters.
 * handleRequestUsingNamedLimiter() keys on md5($limiterName . $limit->key), so
 * the name is part of the key and each action gets its own counter. Each keeps
 * exactly the limit it had before, and each is still keyed on the IP — this
 * isolates counters, it does not loosen anything.
 *
 * STILL RAW, AND STILL SHARING ONE BUCKET (not in this pass's scope):
 * the customer auth routes, both verification steps, both forgot-password /
 * new-password steps, the notification polls, and the table/QR endpoints —
 * 21 routes in total. They are listed in docs/SECURITY_TESTING_SUMMARY.md
 * Pass 7. Anything added here in future should be a named limiter.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Per-session limits: how often one ordering party may act in a minute.
     * Generous enough that no real person meets them, low enough that a single
     * runaway client cannot flood the kitchen queue.
     */
    public const PLACE_ORDER_PER_SESSION = 20;
    public const GCASH_PAID_PER_SESSION = 20;
    public const RATING_POST_PER_SESSION = 20;
    public const RATING_GET_PER_SESSION = 60;
    public const VOUCHER_PER_SESSION = 15;
    public const HELP_REQUEST_PER_SESSION = 10;

    /**
     * Opening a dine-in session from the table QR or the permanent table code.
     *
     * WHY THIS LIMITER EXISTS AT ALL
     * ------------------------------
     * The code printed on a table standee is now PERMANENT — it does not expire
     * and it is not consumed by being used. That is what the business needs, and
     * it means anyone who has ever photographed a standee holds a working
     * credential indefinitely. The single most damaging thing they could do with
     * it is not one fake session, it is a loop: thousands of sessions opened
     * against real tables, filling the staff Occupied Tables panel with noise
     * until it is useless during service. This is the limiter that makes that
     * loop pointless.
     *
     * WHY 30 PER SESSION AND 600 PER IP
     * ---------------------------------
     * Per party (30): a real customer opens a dine-in session once. Thirty a
     * minute allows for a shaky camera re-scanning, tapping back and forth
     * between Login and Guest, and a few mistyped codes — every plausible
     * fumble, several times over — while a script gets nowhere.
     *
     * Per address (600): the ceiling has to be per-CAFE, not per-person,
     * because every customer on the shop wi-fi shares one public IP. A hundred
     * seats each re-scanning a few times in the same minute is a real Saturday;
     * six hundred is comfortably above that and still four orders of magnitude
     * below what a loop would want. Setting this tight would refuse a room full
     * of paying customers to inconvenience one attacker, which is the wrong
     * trade every time.
     *
     * Both limits apply — Laravel checks every Limit returned — so a script that
     * discards its cookies to dodge the per-party count still meets the per-IP
     * ceiling.
     */
    public const TABLE_SESSION_PER_SESSION = 30;

    /**
     * Per-IP ceilings: the whole café together. Sized for a busy shop (a
     * hundred-plus orders in one minute is far beyond any real service rate)
     * so they only ever catch automation, never a queue of customers.
     */
    public const PLACE_ORDER_PER_IP = 120;
    public const GCASH_PAID_PER_IP = 120;
    public const RATING_POST_PER_IP = 120;
    public const RATING_GET_PER_IP = 300;
    public const VOUCHER_PER_IP = 60;
    public const HELP_REQUEST_PER_IP = 60;
    public const TABLE_SESSION_PER_IP = 600;

    /**
     * Sensitive ADMIN actions — each its own counter, each per IP.
     *
     * These numbers are unchanged from the raw `throttle:N,M` they replace.
     * The point of the change is isolation, not strictness: before, all three
     * shared one bucket with each other AND with the notification poll. See
     * the class docblock.
     */
    public const ADMIN_LOGIN_PER_IP = 3;
    public const ADMIN_ACCOUNT_PASSWORD_PER_IP = 6;
    public const ADMIN_STAFF_PASSWORD_PER_IP = 6;

    /**
     * Issuing a voucher code at the counter. Generous — an admin serving a
     * queue might legitimately issue several in a row — but bounded, because
     * each one mints a row and draws against that voucher's max_uses supply.
     */
    public const ADMIN_ISSUE_VOUCHER_CODE_PER_IP = 30;

    /**
     * First-run admin bootstrap. Keyed on IP because there is, by definition,
     * no account to key on yet.
     *
     * Five a minute: this is a once-per-installation action, so a legitimate
     * owner needs only one success and a little room for a rejected password or
     * a mistyped confirmation. Anything beyond that on a system with zero
     * admins is either a script or a person who should stop and read the
     * error.
     */
    public const ADMIN_BOOTSTRAP_PER_IP = 5;

    public function boot(): void
    {
        /*
         * Keyed on the IP, exactly as before — for a login form "one address,
         * many attempts" is the attack, so per-IP is correct and deliberate.
         * What changed is that each of these now has its OWN counter instead
         * of all three sharing one with every other raw-throttled route.
         *
         * The limiter name is prepended to the key as well as being hashed
         * into it by handleRequestUsingNamedLimiter(). That is belt and
         * braces: it keeps these readable in a cache dump and means the
         * isolation does not depend solely on framework internals.
         */
        $this->perIp('admin-login', self::ADMIN_LOGIN_PER_IP);
        $this->perIp('admin-account-password', self::ADMIN_ACCOUNT_PASSWORD_PER_IP);
        $this->perIp('admin-staff-password', self::ADMIN_STAFF_PASSWORD_PER_IP);
        $this->perIp('admin-issue-voucher-code', self::ADMIN_ISSUE_VOUCHER_CODE_PER_IP);
        $this->perIp('admin-bootstrap', self::ADMIN_BOOTSTRAP_PER_IP);

        $this->pair('place-order', self::PLACE_ORDER_PER_SESSION, self::PLACE_ORDER_PER_IP);
        $this->pair('gcash-paid', self::GCASH_PAID_PER_SESSION, self::GCASH_PAID_PER_IP);
        $this->pair('order-rating', self::RATING_POST_PER_SESSION, self::RATING_POST_PER_IP);
        $this->pair('order-rating-read', self::RATING_GET_PER_SESSION, self::RATING_GET_PER_IP);
        $this->pair('apply-voucher', self::VOUCHER_PER_SESSION, self::VOUCHER_PER_IP);
        $this->pair('help-request', self::HELP_REQUEST_PER_SESSION, self::HELP_REQUEST_PER_IP);
        $this->pair('table-session', self::TABLE_SESSION_PER_SESSION, self::TABLE_SESSION_PER_IP);
    }

    /**
     * One limiter, one limit, keyed on the address.
     *
     * The `$name . '|ip:'` prefix is what stops this counter being shared with
     * any other action — which is the entire bug this exists to fix.
     */
    private function perIp(string $name, int $perMinute): void
    {
        RateLimiter::for($name, function (Request $request) use ($name, $perMinute) {
            return Limit::perMinute($perMinute)->by($name . '|ip:' . $request->ip());
        });
    }

    /**
     * One limiter, two limits: this visitor, and this address.
     */
    private function pair(string $name, int $perSession, int $perIp): void
    {
        RateLimiter::for($name, function (Request $request) use ($name, $perSession, $perIp) {
            return [
                Limit::perMinute($perSession)->by($name . '|visitor:' . self::visitorKey($request)),
                Limit::perMinute($perIp)->by($name . '|ip:' . $request->ip()),
            ];
        });
    }

    /**
     * Who this request is, as far as throttling is concerned.
     *
     * A logged-in customer is keyed by account, so signing in on a second
     * device does not double their allowance. Everyone else is keyed by
     * session id, which is one browser on one phone — the closest thing a
     * guest has to an identity, and the thing that actually separates two
     * customers sitting at different tables on the same wi-fi.
     *
     * Falls back to the IP if there is somehow no session on the request, so
     * the limiter can never silently become unlimited.
     */
    public static function visitorKey(Request $request): string
    {
        $customer = $request->user('customer');

        if ($customer) {
            return 'customer:' . $customer->getAuthIdentifier();
        }

        try {
            if ($request->hasSession()) {
                return 'session:' . $request->session()->getId();
            }
        } catch (\Throwable $e) {
            // No session store bound (console, stateless call) — fall through.
        }

        return 'ip:' . $request->ip();
    }
}

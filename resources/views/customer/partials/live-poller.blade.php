{{--
    THE customer page's one background poll — window.PomidaLive.

    Included by the notification bell (which every customer page carries, via
    desktop-nav or directly), so it exists on every page without each page
    asking for it. Pages that care about an order SUBSCRIBE to it instead of
    running their own timer:

        PomidaLive.subscribe({
            orderIds: function () { return [12, 13]; },   // orders this page still watches
            onData:   function (d) { ... }                // every answer, see below
        });

    ONE request answers everyone: GET customer.live-status →
        { unread, latest_id, orders: [ {order_id, status, ...} ],
          session_order_id, active, completed_popups: [ {order_id, ...} ] }
    That is the bell's unread count + newest id AND the status of the orders any
    subscriber asked about — the work that used to be /notifications/unread-count
    (6s) + /order-status (3s) + /orders-status (3s) on three separate timers.
    Those endpoints still exist and still work.

    Cadence (see partials/poll-chain for the machinery — one guarded setTimeout
    chain, never stacking):
        an order is open   5s, 5s, then 10s        — a status change reaches the
                                                     customer within about 10s
        nothing open       5s, 10s, 20s, then 30s  — only the bell is listening
    Any change (a status, a new notification) snaps back to 5s; so does the
    customer touching the screen, and so does the tab becoming visible again (one
    request fires at once). The tab being hidden stops it. A 401/403/419 stops it
    for good (the session-guard partial handles the redirect/reload for 401/419).

    "Only ask about an order while it is open": the page lists the ids it is
    still watching; once an answer shows one completed/cancelled the page
    drops it, and it is never asked about again. With nothing to watch the
    request carries no order ids and the server skips the orders query entirely.
--}}
@include('partials.poll-chain')
@once
<script>
(function () {
    'use strict';
    if (window.PomidaLive || !window.PomidaPollChain) { return; }

    var URL_LIVE = '{{ route('customer.live-status') }}';
    var TERMINAL = { completed: 1, cancelled: 1 };

    // First value is the "reaction" speed the toast layer was tuned to (5-6s,
    // OrderStatusToastTest); the later values are the back-off.
    var LADDER_ACTIVE = [5000, 5000, 10000];
    var LADDER_IDLE = [5000, 10000, 20000, 30000];

    var subs = [];
    var active = false;       // does the last answer show an open order?
    var discovered = false;   // has any answer arrived yet on this page load?
    var lastSig = null;

    function wantedIds() {
        var seen = {}, ids = [];
        subs.forEach(function (s) {
            var list = [];
            try { list = s.orderIds ? s.orderIds() : []; } catch (e) {}
            (list || []).forEach(function (id) {
                id = Number(id);
                if (id > 0 && !seen[id]) { seen[id] = 1; ids.push(id); }
            });
        });
        return ids;
    }

    function signature(d) {
        return [d.unread, d.latest_id].concat((d.orders || []).map(function (o) {
            return [o.order_id, o.status, o.payment_status, o.discount_status, o.updated_at].join(':');
        })).concat((d.completed_popups || []).map(function (o) {
            return 'popup' + o.order_id;
        })).join('|');
    }

    function run() {
        var q = wantedIds().map(function (id) { return 'order_ids[]=' + encodeURIComponent(id); });
        // First answer of a page load also covers the session's order whatever
        // its status, so one that finished before the page opened still gets
        // its one-time "completed" popup.
        if (!discovered) { q.push('discover=1'); }

        return PomidaPollChain.getJson(URL_LIVE + (q.length ? '?' + q.join('&') : '')).then(function (res) {
            var failed = PomidaPollChain.failure(res);
            if (failed) { return failed; }

            var d = res.data || {};
            discovered = true;
            active = (d.orders || []).some(function (o) { return !TERMINAL[o.status]; });

            subs.slice().forEach(function (s) {
                try { if (s.onData) { s.onData(d); } } catch (e) { /* one subscriber must not starve the others */ }
            });

            var sig = signature(d);
            var changed = sig !== lastSig;
            lastSig = sig;
            return { changed: changed };
        });
    }

    var chain = PomidaPollChain({
        schedule: function () { return active ? LADDER_ACTIVE : LADDER_IDLE; },
        run: run
    });

    window.PomidaLive = {
        subscribe: function (sub) { subs.push(sub); },
        // Ask now and go back to the fast cadence (after an action that changes an order).
        refresh: function () { chain.now(); },
        nudge: function () { chain.nudge(); }
    };

    // Start once the page's own scripts have had the chance to subscribe, so
    // the very first request already carries their order ids.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { chain.start(); });
    } else {
        chain.start();
    }
})();
</script>
@endonce

{{--
    ONE background poller, written once — window.PomidaPollChain.

    Why this exists (Oct 2026, fix/polling-requests)
    ------------------------------------------------
    Every customer page used to carry several hand-rolled
    setInterval(fn, 3000 | 6000 | 10000) loops that: never stopped when there was
    nothing left to watch (an order that had already completed was still asked
    about every 3 seconds, for as long as the page stayed open), never slowed
    down when nothing changed, kept firing in a hidden tab, and retried a failed
    request forever. setInterval also does not wait for the previous request, so
    a slow server gets a pile of overlapping calls.

    This helper replaces all of that with a single guarded setTimeout CHAIN: the
    next request is only scheduled after the previous one has finished, so there
    is never more than one timer and never more than one request in flight per
    chain, however many times start() is called.

        var chain = PomidaPollChain({
            schedule: [5000, 10000, 20000, 30000],  // delay after 0,1,2,3+ unchanged polls
            run: function () { return Promise.resolve({ changed: true|false }); }
        });
        chain.start();

    run() answers one of:
        { changed: true }     something changed  -> back to the fastest delay
        { changed: false }    nothing changed    -> next, slower delay in the schedule
        { stop: true }        stop for good (401 / 403 / 419, or nothing left to watch)
        { delay: ms }         override the next delay (e.g. after a 429 / 5xx)
    A run() that rejects counts as an error: the next delay is the schedule's
    delay, but never shorter than ERROR_FLOOR_MS, so an outage is not hammered.

    Built-in behaviour
        - pauses while the tab is hidden; when it becomes visible again, polls
          ONCE immediately and starts from the fastest delay again;
        - the same on a bfcache restore (pageshow with persisted);
        - a tap / click / key press while the chain is in a slow stretch snaps it
          back to the fastest delay ("reset to fast when the customer acts");
        - schedule may be a function returning the array, so a caller can pick a
          different ladder depending on its own state.

    Idempotent: including this partial twice is harmless.
--}}
@once
<script>
(function () {
    'use strict';
    if (window.PomidaPollChain) { return; }

    var ERROR_FLOOR_MS = 15000;
    var STOP_STATUSES = [401, 403, 419];

    function PomidaPollChain(opts) {
        var timer = null;
        var idx = 0;
        var inFlight = false;
        var running = false;
        var lastRunAt = 0;

        function ladder() {
            var s = typeof opts.schedule === 'function' ? opts.schedule() : opts.schedule;
            return (s && s.length) ? s : [5000];
        }

        function delayFor() {
            var s = ladder();
            return s[Math.min(idx, s.length - 1)];
        }

        function clear() {
            if (timer) { clearTimeout(timer); timer = null; }
        }

        function arm(ms) {
            clear();
            if (!running || document.hidden) { return; }
            timer = setTimeout(tick, ms);
        }

        function tick() {
            timer = null;
            if (!running || document.hidden || inFlight) { return; }
            inFlight = true;
            lastRunAt = Date.now();

            var p;
            try { p = Promise.resolve(opts.run()); } catch (e) { p = Promise.reject(e); }

            p.then(function (res) {
                inFlight = false;
                res = res || {};
                if (res.stop) { running = false; clear(); return; }
                if (res.changed) { idx = 0; } else { idx++; }
                arm(res.delay || delayFor());
            }, function () {
                inFlight = false;
                idx++;
                arm(Math.max(delayFor(), ERROR_FLOOR_MS));
            });
        }

        // Back to the fastest delay. Only re-arms when the chain is idle-waiting;
        // an in-flight request re-arms itself when it lands.
        function nudge() {
            if (!running || idx === 0) { return; }
            idx = 0;
            if (inFlight || document.hidden) { return; }
            arm(Math.max(0, ladder()[0] - (Date.now() - lastRunAt)));
        }

        function resumeNow() {
            if (!running) { return; }
            idx = 0;
            clear();
            tick();
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { clear(); } else { resumeNow(); }
        });
        window.addEventListener('pageshow', function (e) { if (e.persisted) { resumeNow(); } });
        window.addEventListener('pagehide', clear);

        if (opts.nudgeOnActivity !== false) {
            ['pointerdown', 'keydown', 'touchstart'].forEach(function (t) {
                window.addEventListener(t, nudge, { passive: true });
            });
        }

        return {
            // Idempotent: a second start() while running does nothing.
            start: function () {
                if (running) { return; }
                running = true;
                idx = 0;
                // startDelay: a page that was only just rendered has nothing
                // to ask yet, so it waits one delay instead of asking at once.
                if (!document.hidden) {
                    if (opts.startDelay) { arm(opts.startDelay); } else { tick(); }
                }
            },
            stop: function () { running = false; clear(); },
            nudge: nudge,
            now: resumeNow,
            isRunning: function () { return running; }
        };
    }

    // GET JSON, never throws on an HTTP error: { ok, status, data }.
    PomidaPollChain.getJson = function (url) {
        return fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            if (!r.ok) { return { ok: false, status: r.status, data: null }; }
            return r.json().then(function (d) { return { ok: true, status: r.status, data: d }; });
        });
    };

    // The answer to give the chain for a failed response, or null if it was fine.
    PomidaPollChain.failure = function (res) {
        if (res && res.ok) { return null; }
        if (res && STOP_STATUSES.indexOf(res.status) !== -1) { return { stop: true }; }
        return { changed: false, delay: ERROR_FLOOR_MS };
    };

    window.PomidaPollChain = PomidaPollChain;
})();
</script>
@endonce

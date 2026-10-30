{{--
    THE DINE-IN GUEST'S SESSION GUARD.

    Lifted verbatim out of menu.blade.php, where it used to live inline, so the
    cart and item-details pages can carry the identical behaviour instead of a
    second hand-rolled copy of it. Nothing about the menu's own behaviour
    changed in the move: menu includes it with $pingsActivity = true, which is
    exactly what the inline block did.

    Include once, inside the body, on any full-document customer page a dine-in
    guest can actually be sitting on. Guarded internally by
    session('order_type') === 'dine_in', so a pick-up customer's page is
    byte-identical to what it was and there is nothing here for admin, staff or
    kitchen pages to pick up.

    ─────────────────────────────────────────────────────────────────────────────
    TWO LISTENERS, DELIBERATELY DIFFERENT JOBS
    ─────────────────────────────────────────────────────────────────────────────

      PING   scroll / click / touchstart / keydown, throttled to at most one
             request per sixty seconds of continuous activity no matter how many
             events fire. This is the ONLY thing that extends the fifteen-minute
             window, and it is opt-in per page:

                 $pingsActivity = true   the menu, and only the menu.

             Left off everywhere else ON PURPOSE. The cart and item-details
             pages have never pinged, so switching them on here would quietly
             lengthen the inactivity window for anyone who parks on their cart —
             a change to the fifteen-minute clock, which is a different feature
             with its own tests. This partial propagates cancellations; it does
             not retune that clock.

      CHECK  read-only on the server. Fires on load, then on a guarded
             setTimeout chain (partials/poll-chain) starting at POLL_EVERY_MS,
             and whenever the tab becomes visible or regains focus.

    WHY THE INTERVAL EXISTS
    -----------------------
    check() used to run on load / visibilitychange / focus and nothing else. For
    the idle clock that was enough — a customer idle long enough to be swept is
    by definition not looking at the tab, so the next focus event caught them.
    It is NOT enough for a staff cancellation, which is the case this partial
    was extended for: a customer reading the menu with the tab in the foreground
    the whole time fires no focus event, ever, and would sit on a dead session
    until they happened to navigate. Hence a real timer.

    10s, matching the admin board's own background-refresh cadence rather than
    inventing a third number — the staff panel polls occupancy every 5s, so the
    round trip from pressing Clear to the customer's phone moving is a handful
    of seconds either way. The endpoint is throttled per IP at
    customer-table-clock (120/min), which one page polling every 10s sits far
    beneath even with a whole room of phones sharing the café's one address.

    BACK-OFF (Oct 2026): the chain asks every 10s, then 10s, 15s and finally
    every 20s while the table stays valid; a tap or key press, or the tab
    coming back to the front, snaps it back to 10s. The ceiling is deliberately
    lower than the other pollers' 30s: a staff Clear / Move / cancellation
    should still reach a guest's phone within about twenty seconds. It pauses
    while the tab is hidden and stops on a 401/403/419.

    Nothing here draws anything. On any failure the server has already flashed
    the message, so the page simply goes to the code-entry page and the existing
    alert renders it — the same alert, in the same place, as every other dine-in
    refusal.
--}}
@if(session('order_type') === 'dine_in')
@include('partials.poll-chain')
<script>
(function () {
    'use strict';

    var PING_URL  = '{{ route('customer.table-activity') }}';
    var CHECK_URL = '{{ route('customer.table-session-status') }}';
    var ENTRY_URL = '{{ route('customer.dineinqr') }}';

    var PINGS_ACTIVITY = {{ ($pingsActivity ?? false) ? 'true' : 'false' }};

    // One ping per sixty seconds of activity. A customer reading a long menu
    // generates thousands of scroll events; the server needs one of them.
    var PING_EVERY_MS = 60000;

    // How often the page asks whether its table is still its own. See the
    // docblock above for why this is a timer and not only a focus handler.
    var POLL_EVERY_MS = 10000;

    var lastPingAt = 0;
    var pingInFlight = false;
    var leaving = false;
    var chain = null;

    // X-CSRF-TOKEN is put on by partials/session-guard's fetch wrapper, which
    // reads it live from the meta tag rather than from a literal baked in here.
    function ping() {
        var now = Date.now();

        if (now - lastPingAt < PING_EVERY_MS || pingInFlight) { return; }

        lastPingAt = now;
        pingInFlight = true;

        fetch(PING_URL, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        })
        .catch(function () { /* Next interaction tries again. */ })
        .then(function () { pingInFlight = false; });
    }

    if (PINGS_ACTIVITY) {
        ['scroll', 'click', 'touchstart', 'keydown'].forEach(function (type) {
            window.addEventListener(type, ping, { passive: true });
        });
    }

    // One read-only check, answering the chain: valid → unchanged (slower next
    // time); invalid → leave (latched, once); an HTTP refusal → the chain's own
    // error handling (stop on 401/403/419, otherwise a slower retry).
    function check() {
        if (leaving) { return Promise.resolve({ stop: true }); }

        return PomidaPollChain.getJson(CHECK_URL).then(function (res) {
            var failed = PomidaPollChain.failure(res);
            if (failed) { return failed; }

            var data = res.data;
            if (data && data.valid === false) {
                // Latched: a burst of focus events, or the chain landing on
                // top of one, must not fire a burst of navigations.
                leaving = true;
                window.location.href = data.redirect || ENTRY_URL;
                return { stop: true };
            }

            return { changed: false };
        });
    }

    // Visibility (pause while hidden, one immediate check when the tab comes
    // back) is the chain's. A window that regains focus without the tab ever
    // having been hidden still asks at once.
    chain = PomidaPollChain({
        schedule: [POLL_EVERY_MS, POLL_EVERY_MS, 15000, 20000],
        run: check
    });
    chain.start();

    window.addEventListener('focus', function () { chain.now(); });
})();
</script>
@endif

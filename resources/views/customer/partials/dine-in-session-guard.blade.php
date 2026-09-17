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

      CHECK  read-only on the server. Fires on load, on a POLL_EVERY_MS
             interval, and whenever the tab becomes visible or regains focus.

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

    Nothing here draws anything. On any failure the server has already flashed
    the message, so the page simply goes to the code-entry page and the existing
    alert renders it — the same alert, in the same place, as every other dine-in
    refusal.
--}}
@if(session('order_type') === 'dine_in')
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
    var checkInFlight = false;
    var leaving = false;
    var pollTimer = null;

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

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function check() {
        if (checkInFlight || leaving) { return; }

        checkInFlight = true;

        fetch(CHECK_URL, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        })
        .then(function (response) {
            if (!response.ok) { return null; }
            return response.json();
        })
        .then(function (data) {
            if (data && data.valid === false) {
                // Latched: a burst of focus events, or the interval landing on
                // top of one, must not fire a burst of navigations.
                leaving = true;
                stopPolling();
                window.location.href = data.redirect || ENTRY_URL;
            }
        })
        .catch(function () { /* Offline or mid-navigation. Ask again next time. */ })
        .then(function () { checkInFlight = false; });
    }

    check();

    pollTimer = setInterval(check, POLL_EVERY_MS);

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { check(); }
    });

    window.addEventListener('focus', check);

    // Stop the timer on the way out, the same way menu.blade's order-status
    // poller already does — a navigation that is already under way has no use
    // for one more in-flight request.
    window.addEventListener('beforeunload', stopPolling);
})();
</script>
@endif

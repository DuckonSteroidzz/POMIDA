{{--
    "Are you still there?" idle prompt for an ACTIVE customer session — Pickup
    or Dine-In, guest or logged-in. Include once, anywhere in the body, on any
    full-document customer page that represents a session already in progress
    (menu, item details, cart, orders, receipt, vouchers, account settings,
    more, the spin game). Deliberately NOT included on:
      - login/register/forgot-password/dineinqr — there is no session yet to
        time out on those, and dineinqr already owns its own code-entry-page
        behaviour.
      - gcash-payment — the customer is expected to leave this tab to pay in
        a separate app and come back; that is a legitimate long pause with no
        interaction on THIS page, exactly the false-positive this feature is
        meant to avoid, so this page is left out rather than tuned around.

    THRESHOLDS (tune here — nothing else needs to change):
      IDLE_MS  10 minutes of no click/tap/scroll/key before the prompt shows.
               Picked to comfortably survive a customer just reading the menu
               (scrolling resets it) while still catching a phone that has
               genuinely been put down. On the orders/receipt pages a customer
               who is simply waiting for food to finish preparing can
               plausibly go untouched for longer than this — if that proves
               disruptive in practice, raise IDLE_MS (or drop the include from
               those two pages) rather than shortening it elsewhere.
      GRACE_MS 60 seconds to tap "Yes, I'm here" before the session is ended
               for real.
    Chosen so the worst case (10m + 60s ≈ 11 minutes idle) still lands inside
    the Dine-In guest's own structural 15-minute clock — see
    App\Services\TableOccupancy::GUEST_IDLE_MINUTES. That clock is a separate,
    unaffected backstop (also unaffected: the 90-minute sweepIdle table
    release); this prompt is a friendlier, earlier layer in front of it, not a
    replacement.

    Tapping "Yes" only clears the prompt and rearms this timer — it is a real
    click/touchstart event like any other, so on a Dine-In page it also
    reaches menu.blade.php's own table-activity ping listener and keeps that
    clock in sync for free.

    On timeout this calls POST /customer/idle-logout, which invalidates the
    session server-side (and logs the customer guard out if one is signed in)
    before this redirects — see AuthController::idleLogout(). A client-side
    redirect with no server call would leave a logged-in session, or a guest's
    table_session_token, technically still alive.
--}}
<div id="idleTimeoutModal" role="alertdialog" aria-modal="true" aria-labelledby="idleTimeoutTitle" hidden
     style="position:fixed;inset:0;z-index:10002;background:rgba(59,35,32,0.55);display:flex;align-items:center;justify-content:center;padding:1rem;">
    <div style="width:100%;max-width:316px;background:#FFFDF9;border:1px solid #F2DDD4;border-radius:16px;padding:24px 20px 20px;text-align:center;box-shadow:0 18px 45px rgba(59,35,32,0.22);">
        <div style="width:52px;height:52px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;background:#FDE8DE;font-size:24px;">
            ⏳
        </div>
        <h3 id="idleTimeoutTitle" style="margin:0 0 8px;color:#3b2320;font-family:'Fraunces',Georgia,serif;font-size:19px;line-height:1.25;font-weight:700;">
            Are you still there?
        </h3>
        <p style="max-width:260px;margin:0 auto;color:#8A6A61;font-size:12px;line-height:1.55;">
            We haven't seen any activity in a while. For your security we'll end this session in
            <span id="idleTimeoutCountdown">60</span> seconds unless you tell us you're still here.
        </p>
        <button type="button" id="idleTimeoutYesBtn"
                style="margin-top:16px;height:36px;padding:0 22px;border-radius:999px;background:#EF8585;color:#fff;border:1px solid #EF8585;font-size:12px;font-weight:700;cursor:pointer;">
            Yes, I'm here
        </button>
    </div>
</div>
<script>
(function () {
    'use strict';

    if (window.__peachyIdleTimeout) { return; }
    window.__peachyIdleTimeout = true;

    var IDLE_MS = 10 * 60 * 1000;
    var GRACE_MS = 60 * 1000;

    var END_SESSION_URL = '{{ route('customer.idle-logout') }}';
    var FALLBACK_URL = '{{ route('home') }}';

    var modal = document.getElementById('idleTimeoutModal');
    var yesBtn = document.getElementById('idleTimeoutYesBtn');
    var countdownEl = document.getElementById('idleTimeoutCountdown');

    var idleTimer = null;
    var graceDeadline = null;
    var countdownInterval = null;
    var ending = false;

    function promptVisible() {
        return modal && !modal.hidden;
    }

    function endSession() {
        if (ending) { return; }
        ending = true;

        clearInterval(countdownInterval);

        fetch(END_SESSION_URL, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (data) {
            window.location.href = (data && data.redirect) || FALLBACK_URL;
        })
        .catch(function () {
            window.location.href = FALLBACK_URL;
        });
    }

    function tickCountdown() {
        var secondsLeft = Math.max(0, Math.ceil((graceDeadline - Date.now()) / 1000));
        if (countdownEl) { countdownEl.textContent = String(secondsLeft); }
        if (secondsLeft <= 0) {
            clearInterval(countdownInterval);
            endSession();
        }
    }

    function showPrompt() {
        if (ending || promptVisible()) { return; }

        modal.hidden = false;
        graceDeadline = Date.now() + GRACE_MS;
        tickCountdown();
        countdownInterval = setInterval(tickCountdown, 250);
    }

    function armIdleTimer() {
        // Only real interaction may cancel a prompt already on screen — a
        // background scroll or an unrelated tap must not silently dismiss it.
        if (promptVisible() || ending) { return; }

        clearTimeout(idleTimer);
        idleTimer = setTimeout(showPrompt, IDLE_MS);
    }

    function dismissPrompt() {
        clearInterval(countdownInterval);
        modal.hidden = true;
        armIdleTimer();
    }

    if (yesBtn) {
        yesBtn.addEventListener('click', dismissPrompt);
    }

    ['click', 'touchstart', 'scroll', 'keydown'].forEach(function (type) {
        window.addEventListener(type, armIdleTimer, { passive: true });
    });

    armIdleTimer();
})();
</script>

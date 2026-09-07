{{--
    Shared customer notification bell.

    Lives in the page HEADER rather than the bottom nav on purpose: the mobile
    bottom nav already has its four slots filled (Orders / Menu / More / Cart)
    and a fifth would cramp it on a narrow phone. The header sits in the same
    place on mobile and desktop, so one include covers both.

    Primary case is a customer on their phone inside the restaurant waiting for
    an order, so the panel is a full-width sheet under the header on small
    screens and a normal dropdown from sm: upward.

    CALLER REQUIREMENT (learned 2026-09-01): this include has no positioning
    of its own (its wrapper below is just `relative shrink-0`) — wherever it
    lands is entirely up to the page including it. If the header's outer
    container is a 2-column CSS grid (grid-cols-[minmax(0,1fr)_auto], as most
    customer headers use), do NOT include this as a direct sibling of the
    <nav>. Grid auto-placement will wrap it onto its own implicit row using
    the same 2-column template, landing the bell alone instead of grouped
    with the nav links. It must sit with the <nav> and the mobile cart icon
    inside ONE "flex items-center justify-end" wrapper so they share a single
    grid cell.

    The standard customer header now gets all of that from
    customer/partials/desktop-nav.blade.php — include THAT in the grid's
    second cell rather than wiring the bell up by hand. A page with a bespoke
    header (receipt, account-settings, gcash-payment) still has to honour the
    wrapper rule itself.
--}}
<div class="relative shrink-0" data-notif-root>

    <button
        type="button"
        data-notif-toggle
        aria-label="Notifications"
        aria-expanded="false"
        class="relative grid h-10 w-10 place-items-center rounded-full border border-peach-soft bg-white text-peach-deep no-underline transition hover:bg-peach-soft"
    >
        <i class="bi bi-bell text-lg leading-none"></i>

        {{-- Badge. Hidden until the poll reports a non-zero count. --}}
        <span
            data-notif-badge
            hidden
            class="absolute -right-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full border-2 border-white bg-peach-red px-1 text-[0.6rem] font-black leading-none text-white"
        >0</span>
    </button>

    {{-- Panel --}}
    <div
        data-notif-panel
        hidden
        class="fixed inset-x-3 top-16 z-[110] max-h-[70vh] overflow-hidden rounded-2xl border border-peach-soft bg-white shadow-xl
               sm:absolute sm:inset-x-auto sm:right-0 sm:top-12 sm:w-80"
    >
        <div class="flex items-center justify-between border-b border-peach-soft px-4 py-3">
            <span class="text-sm font-black text-peach-deep">Notifications</span>
            <button
                type="button"
                data-notif-markread
                class="rounded-full px-2 py-1 text-[0.7rem] font-bold text-peach-red transition hover:bg-peach-soft"
            >Mark all read</button>
        </div>

        <div data-notif-list class="max-h-[55vh] overflow-y-auto overscroll-contain">
            <p class="px-4 py-6 text-center text-xs text-peach-deep/50">Loading…</p>
        </div>
    </div>
</div>

{{--
    Transient toast stack.

    Sits BELOW the header on mobile (top-16) so it never covers the header's
    bell and cart buttons, and clears the fixed bottom nav entirely. New toasts
    are prepended, so the newest is always on top and the older ones slide down
    to make room.
--}}
<div
    data-toast-stack
    class="pointer-events-none fixed inset-x-3 top-16 z-[120] flex flex-col items-stretch
           sm:inset-x-auto sm:right-4 sm:top-20 sm:w-80"
    aria-live="polite"
    aria-atomic="false"
></div>

<style>
    /* Collapsed state: zero height so the toasts below sit flush; expanding it
       is what pushes the rest of the stack smoothly downward. */
    [data-toast] {
        max-height: 0;
        margin-bottom: 0;
        opacity: 0;
        transform: translateY(-8px) scale(0.97);
        transition: max-height .35s ease, margin-bottom .35s ease,
                    opacity .3s ease, transform .35s cubic-bezier(.16,1,.3,1);
        overflow: hidden;
    }
    [data-toast].is-in {
        max-height: 12rem;
        margin-bottom: 0.5rem;
        opacity: 1;
        transform: none;
    }
    [data-toast].is-out {
        max-height: 0;
        margin-bottom: 0;
        opacity: 0;
        transform: translateX(12px);
    }
    @media (prefers-reduced-motion: reduce) {
        [data-toast] { transition-duration: .01ms; }
    }

    /*
       Toast colours are plain CSS, not Tailwind utilities, on purpose: this
       page builds Tailwind in the BROWSER (@tailwindcss/browser), and these
       classes are injected by JS after load. Hard-coding them here means the
       accent colour can never depend on the JIT noticing a DOM mutation.
       Palette follows the app's existing language — peach red for arrivals,
       green for confirmed, amber for "needs staff action", deep red for a
       rejection.
    */
    .toast-card {
        display: flex;
        align-items: stretch;
        overflow: hidden;
        border-radius: 12px;
        border: 1px solid #FDE8DE;
        background: #fff;
        box-shadow: 0 8px 24px rgba(139, 26, 26, 0.14);
    }
    .toast-bar { width: 6px; flex: 0 0 auto; background: #C0392B; }
    .toast-ico { margin-top: 2px; font-size: 0.85rem; color: #C0392B; }

    [data-toast-type="gcash_approved"]              .toast-bar { background: #16A34A; }
    [data-toast-type="gcash_approved"]              .toast-ico { color: #16A34A; }
    [data-toast-type="gcash_rejected"]              .toast-bar { background: #B91C1C; }
    [data-toast-type="gcash_rejected"]              .toast-ico { color: #B91C1C; }
    [data-toast-type="gcash_awaiting_verification"] .toast-bar { background: #D97706; }
    [data-toast-type="gcash_awaiting_verification"] .toast-ico { color: #D97706; }
    [data-toast-type="new_order"]                   .toast-bar { background: #C0392B; }
    [data-toast-type="new_order"]                   .toast-ico { color: #C0392B; }
    [data-toast-type="order_status_changed"]        .toast-bar { background: #8B1A1A; }
    [data-toast-type="order_status_changed"]        .toast-ico { color: #8B1A1A; }
    /* A reward earned is good news and the only celebratory toast here, so it
       gets the warm peach rather than an order colour. */
    [data-toast-type="points_reward_earned"]        .toast-bar { background: #F4845F; }
    [data-toast-type="points_reward_earned"]        .toast-ico { color: #F4845F; }

    .toast-body { display: flex; min-width: 0; flex: 1; align-items: flex-start; gap: 0.5rem; padding: 0.6rem 0.7rem; }
    .toast-title { margin: 0; font-size: 0.78rem; font-weight: 900; line-height: 1.3; color: #8B1A1A; }
    .toast-msg { margin: 2px 0 0; font-size: 0.72rem; line-height: 1.35; color: rgba(139, 26, 26, 0.72); }

    /*
       Order reference chip. A customer can have several orders in flight at
       once (item 43 / Round 3A), so "Order is being prepared" alone does not
       say which one — the order number is in the message text, but it is at
       the end of a sentence and easy to skim past on a 5-second toast. This
       puts it where the eye lands first. Plain CSS for the same reason as the
       colours above: these nodes are injected by JS and the browser-side
       Tailwind build cannot be relied on to notice them.
    */
    .toast-ref {
        display: inline-block;
        margin: 3px 0 0;
        padding: 1px 6px;
        border-radius: 999px;
        background: #FDE8DE;
        color: #8B1A1A;
        font-size: 0.62rem;
        font-weight: 800;
        letter-spacing: 0.02em;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .toast-x { margin-left: 4px; flex: 0 0 auto; border: 0; background: transparent; color: rgba(139,26,26,0.4); cursor: pointer; padding: 0 2px; }
    .toast-x:hover { color: #8B1A1A; }
</style>

@once
<script>
(function () {
    var root = document.querySelector('[data-notif-root]');
    if (!root) return;

    var toggle   = root.querySelector('[data-notif-toggle]');
    var panel    = root.querySelector('[data-notif-panel]');
    var badge    = root.querySelector('[data-notif-badge]');
    var list     = root.querySelector('[data-notif-list]');
    var markBtn  = root.querySelector('[data-notif-markread]');

    var URL_COUNT = @json(route('customer.notifications.unread-count'));
    var URL_LIST  = @json(route('customer.notifications.index'));
    var URL_READ  = @json(route('customer.notifications.read'));
    var CSRF      = @json(csrf_token());

    function setBadge(n) {
        if (n > 0) {
            badge.textContent = n > 99 ? '99+' : n;
            badge.hidden = false;
        } else {
            badge.hidden = true;
        }
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML;
    }

    function render(items) {
        if (!items.length) {
            list.innerHTML = '<p class="px-4 py-6 text-center text-xs text-peach-deep/50">' +
                             'No notifications yet.</p>';
            return;
        }

        list.innerHTML = items.map(function (n) {
            // Unread rows get a tinted background and a dot; read rows stay plain.
            return '' +
              '<div class="flex gap-2.5 border-b border-peach-soft/60 px-4 py-3 ' +
                   (n.is_read ? '' : 'bg-peach-soft/40') + '">' +
                '<span class="mt-1.5 h-2 w-2 shrink-0 rounded-full ' +
                   (n.is_read ? 'bg-transparent' : 'bg-peach-red') + '"></span>' +
                '<div class="min-w-0 flex-1">' +
                  '<p class="text-[0.8rem] leading-snug ' +
                     (n.is_read ? 'font-semibold text-peach-deep/70' : 'font-black text-peach-deep') +
                     '">' + esc(n.title) + '</p>' +
                  '<p class="mt-0.5 text-[0.75rem] leading-snug text-peach-deep/70">' +
                     esc(n.message) + '</p>' +
                  '<p class="mt-1 text-[0.65rem] font-semibold uppercase tracking-wide text-peach-deep/40">' +
                     esc(n.ago) + '</p>' +
                '</div>' +
              '</div>';
        }).join('');
    }

    /* ── Toast layer ───────────────────────────────────────────────────────
       Driven by the SAME poll as the badge — no second polling loop. The poll
       reports the highest notification id this viewer can see; when that rises
       above what we last saw, we pull the list once and toast only the rows
       that are actually new.                                                 */

    var stack = document.querySelector('[data-toast-stack]');
    /*
     * The high-water mark is kept in sessionStorage, not just memory.
     *
     * admin/home does a full location.reload() every 5 seconds (its "live
     * order board"), which is far more often than the 20s poll — an in-memory
     * baseline would be wiped before it was ever compared, so a toast could
     * never fire on the single page that needs them most. Persisting it per
     * tab means the one poll that runs on each page load can still tell what
     * is new. A brand-new tab has no stored value and adopts the current mark
     * silently, so history is never replayed.
     */
    var SEEN_KEY = 'pomida_seen_customer';

    function loadSeen() {
        try {
            var v = sessionStorage.getItem(SEEN_KEY);
            return v === null ? null : parseInt(v, 10);
        } catch (e) { return null; }
    }

    function saveSeen(v) {
        try { sessionStorage.setItem(SEEN_KEY, String(v)); } catch (e) {}
    }

    var lastSeenId = loadSeen();   // null until the first poll establishes a baseline
    var TOAST_MS = 7000;
    var MAX_TOASTS = 4;      // keep the stack from running off a phone screen

    /*
     * HOW OFTEN THE POLL RUNS — and why it moved from 20s to 6s.
     *
     * The reported problem was that a customer whose order silently became
     * "Serving" was told nothing and went hunting around the app. On the pages
     * that carry this partial the toast layer was in fact firing correctly for
     * preparing and serving (verified live), but up to 20 seconds after the
     * fact — long enough that the moment has passed and the customer has
     * already started looking for someone to ask.
     *
     * 6s is one request per 10s per open tab against
     * /customer/notifications/unread-count, which carries throttle:60,1 — so a
     * tab uses 10 of its 60, leaving the rest for manual bell opens and for the
     * one /customer/notifications call that only fires when something is
     * genuinely new. This deliberately does NOT add a second polling loop: the
     * badge and the toasts still share this one poll, and the separate 3s
     * order-status poll on the menu and orders pages keeps its own, different
     * job (the terminal full-screen overlay) and is untouched.
     */
    var POLL_MS = 6000;

    // Colour per notification type, reusing the app's existing language:
    // peach-red for "something arrived", green for confirmed, amber for
    // "staff action needed", deeper red for a rejection.
    var TOAST_ICON = {
        gcash_approved:              'bi-check-circle-fill',
        gcash_rejected:              'bi-exclamation-octagon-fill',
        gcash_awaiting_verification: 'bi-hourglass-split',
        new_order:                   'bi-bag-check-fill',
        points_reward_earned:        'bi-gift-fill',
        order_status_changed:        'bi-cup-hot-fill'
    };

    function dismissToast(el) {
        if (!el || el.dataset.closing) return;
        el.dataset.closing = '1';
        el.classList.remove('is-in');
        el.classList.add('is-out');
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 400);
    }

    function showToast(n) {
        if (!stack) return;
        var icon = TOAST_ICON[n.type] || TOAST_ICON.order_status_changed;

        var el = document.createElement('div');
        el.setAttribute('data-toast', '');
        el.setAttribute('data-toast-type', n.type || 'order_status_changed');
        el.style.pointerEvents = 'auto';
        el.innerHTML =
            '<div class="toast-card">' +
              '<span class="toast-bar"></span>' +
              '<div class="toast-body">' +
                '<i class="bi ' + icon + ' toast-ico"></i>' +
                '<div style="min-width:0;flex:1;">' +
                  '<p class="toast-title">' + esc(n.title) + '</p>' +
                  '<p class="toast-msg">' + esc(n.message) + '</p>' +
                  (n.order_number
                    ? '<span class="toast-ref">#' + esc(n.order_number) + '</span>'
                    : '') +
                '</div>' +
                '<button type="button" data-toast-close aria-label="Dismiss" class="toast-x">' +
                  '<i class="bi bi-x-lg" style="font-size:0.7rem;"></i></button>' +
              '</div>' +
            '</div>';

        // Newest goes on TOP; expanding its height pushes the rest down.
        stack.insertBefore(el, stack.firstChild);
        requestAnimationFrame(function () { el.classList.add('is-in'); });

        el.querySelector('[data-toast-close]').addEventListener('click', function () { dismissToast(el); });
        setTimeout(function () { dismissToast(el); }, TOAST_MS);

        while (stack.children.length > MAX_TOASTS) {
            dismissToast(stack.children[stack.children.length - 1]);
            break;
        }
    }

    function toastNewSince(sinceId) {
        fetch(URL_LIST, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                // Oldest first, so the newest ends up on top of the stack.
                d.notifications
                    .filter(function (n) { return n.id > sinceId; })
                    .sort(function (a, b) { return a.id - b.id; })
                    .forEach(showToast);
            })
            .catch(function () {});
    }

    function poll() {
        fetch(URL_COUNT, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                setBadge(d.unread);

                var latest = d.latest_id || 0;

                if (lastSeenId === null) {
                    // First poll of the page load: adopt the current high-water
                    // mark silently, so opening a page never replays history.
                    lastSeenId = latest;
                    saveSeen(latest);
                    return;
                }

                if (latest > lastSeenId) {
                    toastNewSince(lastSeenId);
                    lastSeenId = latest;
                    saveSeen(latest);
                }
            })
            .catch(function () { /* offline / throttled — try again next tick */ });
    }

    function loadList() {
        fetch(URL_LIST, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                render(d.notifications);
                setBadge(d.unread);
            })
            .catch(function () {
                list.innerHTML = '<p class="px-4 py-6 text-center text-xs text-peach-deep/50">' +
                                 'Could not load notifications.</p>';
            });
    }

    function markAllRead() {
        fetch(URL_READ, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function () { setBadge(0); loadList(); })
        .catch(function () {});
    }

    function openPanel() {
        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        loadList();
    }

    function closePanel() {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.hidden ? openPanel() : closePanel();
    });

    markBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        markAllRead();
    });

    // Tapping anywhere else closes the sheet — important on a phone where the
    // panel covers most of the screen.
    document.addEventListener('click', function (e) {
        if (!panel.hidden && !root.contains(e.target)) closePanel();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePanel();
    });

    poll();
    setInterval(poll, POLL_MS);
})();
</script>
@endonce

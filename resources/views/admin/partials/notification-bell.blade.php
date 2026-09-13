{{--
    Staff/admin notification bell.

    Rendered inside .pc-topline in admin/layout.blade.php — the row at the top
    of .main-content that also holds the branch bar. Laying both out in one
    flex row is what keeps them from overlapping; the bell was previously
    position:fixed and floated over the branch selector.

    .main-content is present at every breakpoint (unlike the sidebar, which
    hides behind a burger on mobile, and .pc-topbar, which is display:none on
    desktop), so one placement covers both. On pages with no branch bar the
    bell simply sits alone at the right of the same row.

    The counter is the real audience here — the GCash "needs verifying" alert is
    the one that previously had no signal at all.
--}}
<div class="pc-notif" data-anotif-root>

    <button type="button" class="pc-notif-btn" data-anotif-toggle
            aria-label="Notifications" aria-expanded="false">
        <i class="bi bi-bell"></i>
        <span class="pc-notif-badge" data-anotif-badge hidden>0</span>
    </button>

    <div class="pc-notif-panel" data-anotif-panel hidden>
        <div class="pc-notif-head">
            <span>Notifications</span>
            <span class="pc-notif-head-actions">
                <button type="button" data-anotif-markread class="pc-notif-mark">Mark all read</button>
                <button type="button" data-anotif-close class="pc-notif-x" aria-label="Close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </span>
        </div>
        <div class="pc-notif-list" data-anotif-list>
            <p class="pc-notif-empty">Loading…</p>
        </div>
    </div>
</div>

{{-- Transient toast stack (newest on top, older ones slide down). --}}
<div class="pc-toast-stack" data-atoast-stack aria-live="polite" aria-atomic="false"></div>

<style>
    /*
       In-flow, not fixed. The bell is a normal flex item on .pc-topline
       alongside the branch bar, so the two can never overlap. It stays
       position:relative only so the dropdown panel can anchor to it.

       The button is 40px tall and the branch bar is taller, so a small top
       nudge lines the bell up with the bar's first row of text.
    */
    .pc-notif { position: relative; z-index: 1040; flex: 0 0 auto; margin-top: 0.15rem; }

    .pc-notif-btn {
        position: relative;
        display: grid; place-items: center;
        width: 40px; height: 40px;
        border-radius: 999px;
        border: 1px solid rgba(138, 106, 97, 0.22);
        background: #fff;
        color: var(--pc-maroon);
        font-size: 1.05rem;
        cursor: pointer;
        box-shadow: 0 2px 10px rgba(139, 26, 26, 0.08);
    }
    .pc-notif-btn:hover { background: var(--pc-cream, #fffdf9); }

    .pc-notif-badge {
        position: absolute; top: -3px; right: -3px;
        min-width: 19px; height: 19px;
        display: grid; place-items: center;
        padding: 0 5px;
        border-radius: 999px;
        border: 2px solid #fff;
        background: var(--pc-red);
        color: #fff;
        font-size: 0.6rem; font-weight: 800; line-height: 1;
    }

    .pc-notif-panel {
        position: absolute; top: 48px; right: 0;
        width: min(21rem, calc(100vw - 1.8rem));
        max-height: 70vh;
        overflow: hidden;
        background: #fff;
        border: 1px solid rgba(138, 106, 97, 0.18);
        border-radius: 14px;
        box-shadow: 0 12px 32px rgba(139, 26, 26, 0.14);
    }

    .pc-notif-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 0.5rem;
        padding: 0.7rem 0.9rem;
        border-bottom: 1px solid rgba(138, 106, 97, 0.14);
        font-weight: 800; font-size: 0.85rem;
        color: var(--pc-maroon);
    }

    .pc-notif-mark {
        border: 0; background: transparent;
        color: var(--pc-red);
        font-size: 0.7rem; font-weight: 700;
        cursor: pointer; padding: 0.2rem 0.4rem; border-radius: 999px;
    }
    .pc-notif-mark:hover { background: rgba(139, 26, 26, 0.07); }

    /* Close (X) — same borderless treatment as .pc-notif-mark and the toast's
       .pc-toast-x, using the shared bi-x-lg icon already used by the admin
       modal close buttons. */
    .pc-notif-head-actions { display: inline-flex; align-items: center; gap: 0.15rem; }
    .pc-notif-x {
        border: 0; background: transparent;
        color: rgba(90, 62, 54, 0.5);
        font-size: 0.8rem; line-height: 1;
        cursor: pointer; padding: 0.25rem 0.35rem; border-radius: 999px;
    }
    .pc-notif-x:hover { background: rgba(139, 26, 26, 0.07); color: var(--pc-maroon); }

    .pc-notif-list { max-height: 55vh; overflow-y: auto; }

    .pc-notif-item {
        display: flex; gap: 0.6rem;
        padding: 0.7rem 0.9rem;
        border-bottom: 1px solid rgba(138, 106, 97, 0.1);
    }
    .pc-notif-item.unread { background: rgba(255, 214, 190, 0.22); }

    .pc-notif-dot {
        margin-top: 6px; width: 8px; height: 8px; flex: 0 0 auto;
        border-radius: 999px; background: transparent;
    }
    .pc-notif-item.unread .pc-notif-dot { background: var(--pc-red); }

    /* Per-card dismiss (X). Borderless + transparent like .pc-notif-mark,
       .pc-notif-x and .pc-toast-x, using the shared bi-x-lg icon. flex:0 0 auto
       so a long title never squeezes it off the card, and it stays inside the
       55vh scroll container — no new overflow at any width. */
    .pc-notif-dismiss {
        align-self: flex-start;
        flex: 0 0 auto;
        margin: -0.15rem -0.15rem 0 0;
        border: 0; background: transparent;
        color: rgba(90, 62, 54, 0.4);
        font-size: 0.72rem; line-height: 1;
        cursor: pointer; padding: 0.25rem 0.3rem; border-radius: 999px;
    }
    .pc-notif-dismiss:hover { background: rgba(139, 26, 26, 0.07); color: var(--pc-maroon); }

    .pc-notif-title { margin: 0; font-size: 0.8rem; font-weight: 700; color: var(--pc-maroon); }
    .pc-notif-item.unread .pc-notif-title { font-weight: 900; }
    .pc-notif-msg { margin: 0.15rem 0 0; font-size: 0.75rem; line-height: 1.35; color: rgba(90, 62, 54, 0.85); }
    .pc-notif-ago { margin: 0.25rem 0 0; font-size: 0.63rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: rgba(90, 62, 54, 0.45); }
    .pc-notif-empty { padding: 1.4rem 0.9rem; text-align: center; font-size: 0.75rem; color: rgba(90, 62, 54, 0.5); margin: 0; }

    /* ── Toast stack ──
       Fixed top-right on desktop. On mobile it drops below the sticky
       .pc-topbar so it never covers the burger, and stays inset from both
       edges so the text has room. */
    .pc-toast-stack {
        position: fixed;
        /* Below the .pc-topline so a toast never sits on top of the bell. */
        top: 5rem;
        right: 1rem;
        width: min(21rem, calc(100vw - 2rem));
        z-index: 1060;
        display: flex;
        flex-direction: column;
        align-items: stretch;
        pointer-events: none;
    }

    /* Collapsed to zero height; expanding is what pushes the stack down. */
    [data-atoast] {
        max-height: 0;
        margin-bottom: 0;
        opacity: 0;
        transform: translateY(-8px) scale(0.97);
        overflow: hidden;
        pointer-events: auto;
        transition: max-height .35s ease, margin-bottom .35s ease,
                    opacity .3s ease, transform .35s cubic-bezier(.16,1,.3,1);
    }
    [data-atoast].is-in  { max-height: 12rem; margin-bottom: 0.5rem; opacity: 1; transform: none; }
    [data-atoast].is-out { max-height: 0; margin-bottom: 0; opacity: 0; transform: translateX(12px); }

    @media (prefers-reduced-motion: reduce) {
        [data-atoast] { transition-duration: .01ms; }
    }

    .pc-toast-card {
        display: flex;
        align-items: stretch;
        overflow: hidden;
        border-radius: 12px;
        border: 1px solid rgba(138, 106, 97, 0.18);
        background: #fff;
        box-shadow: 0 10px 28px rgba(139, 26, 26, 0.16);
    }
    .pc-toast-bar { width: 6px; flex: 0 0 auto; background: var(--pc-red); }
    .pc-toast-ico { margin-top: 2px; font-size: 0.85rem; color: var(--pc-red); }

    [data-atoast-type="gcash_approved"]              .pc-toast-bar { background: #16A34A; }
    [data-atoast-type="gcash_approved"]              .pc-toast-ico { color: #16A34A; }
    [data-atoast-type="gcash_rejected"]              .pc-toast-bar { background: #B91C1C; }
    [data-atoast-type="gcash_rejected"]              .pc-toast-ico { color: #B91C1C; }
    [data-atoast-type="gcash_awaiting_verification"] .pc-toast-bar { background: #D97706; }
    [data-atoast-type="gcash_awaiting_verification"] .pc-toast-ico { color: #D97706; }
    [data-atoast-type="new_order"]                   .pc-toast-bar { background: var(--pc-red); }
    [data-atoast-type="new_order"]                   .pc-toast-ico { color: var(--pc-red); }
    [data-atoast-type="order_status_changed"]        .pc-toast-bar { background: var(--pc-maroon); }
    [data-atoast-type="order_status_changed"]        .pc-toast-ico { color: var(--pc-maroon); }
    /* Refund pending is the one staff toast that costs a customer real money
       if it is missed, so it gets its own alarm colour rather than sharing
       the generic order-status maroon. */
    [data-atoast-type="refund_pending"]              .pc-toast-bar { background: #7C2D12; }
    [data-atoast-type="refund_pending"]              .pc-toast-ico { color: #7C2D12; }

    .pc-toast-body { display: flex; min-width: 0; flex: 1; align-items: flex-start; gap: 0.5rem; padding: 0.6rem 0.7rem; }
    .pc-toast-title { margin: 0; font-size: 0.78rem; font-weight: 800; line-height: 1.3; color: var(--pc-maroon); }
    .pc-toast-msg { margin: 2px 0 0; font-size: 0.72rem; line-height: 1.35; color: rgba(90, 62, 54, 0.8); }
    .pc-toast-x { margin-left: 4px; flex: 0 0 auto; border: 0; background: transparent; color: rgba(90,62,54,0.4); cursor: pointer; padding: 0 2px; }
    .pc-toast-x:hover { color: var(--pc-maroon); }

    /*
       On mobile the bell sits in the same top line, below the sticky
       .pc-topbar, so it needs no offset of its own. Only the panel changes:
       it becomes a near-full-width sheet so the text stays readable, pinned
       under the topbar rather than to the button.
    */
    @media (max-width: 768px) {
        .pc-notif-panel {
            position: fixed;
            top: 3.6rem;
            right: 0.7rem;
            left: 0.7rem;
            width: auto;
        }

        /* Clear the sticky topbar so toasts never cover the burger menu. */
        .pc-toast-stack {
            top: 5.75rem;
            right: 0.7rem;
            left: 0.7rem;
            width: auto;
        }
    }
</style>

<script>
(function () {
    var root = document.querySelector('[data-anotif-root]');
    if (!root) return;

    var toggle  = root.querySelector('[data-anotif-toggle]');
    var panel   = root.querySelector('[data-anotif-panel]');
    var badge   = root.querySelector('[data-anotif-badge]');
    var list    = root.querySelector('[data-anotif-list]');
    var markBtn = root.querySelector('[data-anotif-markread]');
    var closeBtn = root.querySelector('[data-anotif-close]');

    function closePanel() {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    }

    var URL_COUNT = @json(route('admin.notifications.unread-count'));
    var URL_LIST  = @json(route('admin.notifications.index'));
    var URL_READ  = @json(route('admin.notifications.read'));
    // {notification} placeholder swapped per click — one X per card.
    var URL_DISMISS = @json(route('admin.notifications.dismiss', ['notification' => '__ID__']));
    var CSRF      = @json(csrf_token());

    function setBadge(n) {
        if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.hidden = false; }
        else { badge.hidden = true; }
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML;
    }

    function render(items) {
        if (!items.length) {
            list.innerHTML = '<p class="pc-notif-empty">No notifications yet.</p>';
            return;
        }
        list.innerHTML = items.map(function (n) {
            return '<div class="pc-notif-item' + (n.is_read ? '' : ' unread') + '">' +
                     '<span class="pc-notif-dot"></span>' +
                     '<div style="min-width:0;flex:1;">' +
                       '<p class="pc-notif-title">' + esc(n.title) + '</p>' +
                       '<p class="pc-notif-msg">' + esc(n.message) + '</p>' +
                       '<p class="pc-notif-ago">' + esc(n.ago) + '</p>' +
                     '</div>' +
                     '<button type="button" class="pc-notif-dismiss" data-anotif-dismiss ' +
                       'data-id="' + esc(n.id) + '" aria-label="Dismiss">' +
                       '<i class="bi bi-x-lg"></i></button>' +
                   '</div>';
        }).join('');
    }

    /* ── Toast layer ──
       Driven by the SAME poll as the badge. latest_id rising above the last
       value we saw is what marks a genuinely new arrival. */

    var stack = document.querySelector('[data-atoast-stack]');
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
    var SEEN_KEY = 'pomida_seen_staff';

    function loadSeen() {
        try {
            var v = sessionStorage.getItem(SEEN_KEY);
            return v === null ? null : parseInt(v, 10);
        } catch (e) { return null; }
    }

    function saveSeen(v) {
        try { sessionStorage.setItem(SEEN_KEY, String(v)); } catch (e) {}
    }

    var lastSeenId = loadSeen();
    // Deliberately under the 5s location.reload() on admin/home so a toast
    // completes its life instead of being cut off mid-animation by the reload.
    var TOAST_MS = 4200;
    var MAX_TOASTS = 4;

    var TOAST_ICON = {
        gcash_approved:              'bi-check-circle-fill',
        gcash_rejected:              'bi-exclamation-octagon-fill',
        gcash_awaiting_verification: 'bi-hourglass-split',
        new_order:                   'bi-bag-check-fill',
        refund_pending:              'bi-cash-stack',
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
        var icon = TOAST_ICON[n.type] || TOAST_ICON.new_order;

        var el = document.createElement('div');
        el.setAttribute('data-atoast', '');
        el.setAttribute('data-atoast-type', n.type || 'new_order');
        el.innerHTML =
            '<div class="pc-toast-card">' +
              '<span class="pc-toast-bar"></span>' +
              '<div class="pc-toast-body">' +
                '<i class="bi ' + icon + ' pc-toast-ico"></i>' +
                '<div style="min-width:0;flex:1;">' +
                  '<p class="pc-toast-title">' + esc(n.title) + '</p>' +
                  '<p class="pc-toast-msg">' + esc(n.message) + '</p>' +
                '</div>' +
                '<button type="button" data-atoast-close aria-label="Dismiss" class="pc-toast-x">' +
                  '<i class="bi bi-x-lg" style="font-size:0.7rem;"></i></button>' +
              '</div>' +
            '</div>';

        // Newest on top; its expanding height pushes the older ones down.
        stack.insertBefore(el, stack.firstChild);
        requestAnimationFrame(function () { el.classList.add('is-in'); });

        el.querySelector('[data-atoast-close]').addEventListener('click', function () { dismissToast(el); });
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
                    // Adopt the high-water mark silently on first poll so a
                    // page load never replays old notifications.
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
            .catch(function () {});
    }

    function loadList() {
        fetch(URL_LIST, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d) { render(d.notifications); setBadge(d.unread); } })
            .catch(function () {
                list.innerHTML = '<p class="pc-notif-empty">Could not load notifications.</p>';
            });
    }

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        if (panel.hidden) {
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            loadList();
        } else {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            closePanel();
        });
    }

    markBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        fetch(URL_READ, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function () { setBadge(0); loadList(); })
            .catch(function () {});
    });

    // Per-card dismiss (X). Delegated so it survives every list re-render.
    list.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-anotif-dismiss]');
        if (!btn) return;
        e.stopPropagation();

        var id = btn.getAttribute('data-id');
        if (!id || btn.disabled) return;
        btn.disabled = true;

        fetch(URL_DISMISS.replace('__ID__', encodeURIComponent(id)), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) { btn.disabled = false; return; }
                // Drop the row immediately; keep the badge honest.
                var item = btn.closest('.pc-notif-item');
                if (item && item.parentNode) item.parentNode.removeChild(item);
                if (typeof d.unread === 'number') setBadge(d.unread);
                if (!list.querySelector('.pc-notif-item')) {
                    list.innerHTML = '<p class="pc-notif-empty">No notifications yet.</p>';
                }
            })
            .catch(function () { btn.disabled = false; });
    });

    document.addEventListener('click', function (e) {
        if (!panel.hidden && !root.contains(e.target)) {
            closePanel();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !panel.hidden) { closePanel(); }
    });

    poll();
    setInterval(poll, 20000);
})();
</script>

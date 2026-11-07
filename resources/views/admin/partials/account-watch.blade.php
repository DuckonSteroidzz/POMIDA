{{--
    Account watch — one browser has one staff login, and every open staff tab
    has to know when it changes. QA, 6 Nov 2026.

    THE REPORT
    ----------
    QA opened two windows of the same browser profile, signed in as Admin in one
    and Supervisor in the other, refreshed, and the Admin window had become the
    Supervisor. That is how a browser works: both windows share one cookie jar,
    so they share one staff session. What was wrong is that it happened without
    a word, and the Admin window kept looking like Admin until it was refreshed.

    WHAT THIS DOES (it decides nothing: the server still decides everything)
    ------------------------------------------------------------------------
    1. Records which staff account THIS page was drawn for (the meta tag; empty
       on a login page with nobody signed in).
    2. Tells the other tabs of this browser which account it was drawn for, the
       moment it loads (BroadcastChannel, or the storage event where a browser
       has no BroadcastChannel). A tab that was drawn for a different account
       shows the notice at once and reloads: "This browser is now signed in as
       NAME (ROLE). The page will reload." A tab left on a page when the account
       logs out goes to the login page. The login page counts too: a login page
       that was open when somebody signed in becomes the "you are already
       signed in" page.
    3. Asks the server the same question on a slow, guarded timer
       (PomidaPollChain, the app's one poller, so it pauses in a hidden tab and
       asks once when the tab comes back). That covers a tab that missed the
       signal. The focus-time token refresh in partials/session-guard asks too.
    4. Stamps every state-changing form and fetch with the account the page was
       drawn for. If the browser has been signed in as someone else since, the
       server refuses it with a plain sentence and changes nothing (see
       App\Support\StaffAccount and AdminMiddleware), instead of letting a
       stale Admin window act through the Supervisor's session.

    The notice is a full-screen layer on purpose: for the second or two before
    the reload, a stale page cannot be clicked.

    Idempotent: including this twice is harmless. No setInterval, no remember-me,
    nothing about tokens or sessions is changed here.
--}}
@php
    $watchAccount = \App\Support\StaffAccount::describe(\App\Support\StaffAccount::current());
@endphp
<meta name="staff-account" content="{{ $watchAccount ? json_encode($watchAccount) : '' }}">

@include('partials.poll-chain')
<script>
(function () {
    'use strict';

    if (window.__peachyStaffWatch) { return; }
    window.__peachyStaffWatch = true;

    var STATUS_URL = @json(route('admin.account-status'));
    var LOGIN_URL  = @json(route('admin.login'));
    var CHANNEL    = 'peachy-staff-account';
    var FIELD      = @json(\App\Support\StaffAccount::STAMP_FIELD);
    var HEADER     = @json(\App\Support\StaffAccount::STAMP_HEADER);
    var CHANGED    = @json(\App\Support\StaffAccount::CHANGED_HEADER);
    var RELOAD_MS  = 2500;

    // This tab, so it does not react to its own announcement.
    var TAB = String(Date.now()) + '-' + Math.random().toString(36).slice(2);

    function readMine() {
        var meta = document.querySelector('meta[name="staff-account"]');
        var raw = meta ? meta.getAttribute('content') : '';
        if (!raw) { return null; }
        try {
            var account = JSON.parse(raw);
            return account && account.id ? account : null;
        } catch (e) {
            return null;
        }
    }

    // The account this page was drawn for: { id, name, role, label } or null.
    var mine = readMine();

    function same(a, b) {
        return (a ? String(a.id) : '') === (b ? String(b.id) : '');
    }

    // ── The notice, then the reload ─────────────────────────────────────────

    var switching = false;
    var chain = null;

    function showNotice(text) {
        function draw() {
            var layer = document.createElement('div');
            layer.setAttribute('role', 'alertdialog');
            layer.setAttribute('aria-modal', 'true');
            layer.setAttribute('aria-live', 'assertive');
            layer.setAttribute('data-testid', 'staff-account-notice');
            layer.style.cssText = 'position:fixed;top:0;right:0;bottom:0;left:0;z-index:100000;display:flex;'
                + 'align-items:center;justify-content:center;padding:1rem;background:rgba(59,35,32,0.6);';

            var card = document.createElement('div');
            card.style.cssText = 'width:100%;max-width:360px;box-sizing:border-box;padding:1.4rem 1.3rem;'
                + 'border-radius:16px;background:#FFFDF9;border:1px solid #F2DDD4;text-align:center;'
                + 'box-shadow:0 18px 45px rgba(59,35,32,0.28);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;';

            var message = document.createElement('p');
            message.style.cssText = 'margin:0;color:#3b2320;font-size:1rem;line-height:1.5;font-weight:600;';
            message.textContent = text;

            card.appendChild(message);
            layer.appendChild(card);
            document.body.appendChild(layer);
        }

        if (document.body) { draw(); } else { document.addEventListener('DOMContentLoaded', draw); }
    }

    // The browser's staff login is not what this page was drawn for. Say so,
    // then follow it: reload as the new account, or go to the login page.
    function follow(account) {
        if (switching) { return; }
        switching = true;
        if (chain) { chain.stop(); }

        if (account) {
            showNotice('This browser is now signed in as ' + account.name + ' (' + account.label + '). The page will reload.');
            setTimeout(function () { window.location.reload(); }, RELOAD_MS);
        } else {
            showNotice('You were signed out in another tab. Taking you to the login page.');
            setTimeout(function () { window.location.href = LOGIN_URL; }, RELOAD_MS);
        }
    }

    // ── Same-browser tab signal ─────────────────────────────────────────────

    var channel = null;
    try {
        if (typeof window.BroadcastChannel === 'function') { channel = new window.BroadcastChannel(CHANNEL); }
    } catch (e) { channel = null; }

    function onSignal(message) {
        if (!message || message.t !== 'staff-account' || message.from === TAB) { return; }
        var theirs = message.account && message.account.id ? message.account : null;
        if (!same(theirs, mine)) { follow(theirs); }
    }

    if (channel) {
        channel.addEventListener('message', function (event) { onSignal(event.data); });
    }

    // Browsers without BroadcastChannel: the storage event reaches every OTHER
    // tab. The value is removed again at once, so no name is left on disk.
    window.addEventListener('storage', function (event) {
        if (event.key !== CHANNEL || !event.newValue) { return; }
        try { onSignal(JSON.parse(event.newValue)); } catch (e) { /* not ours */ }
    });

    function announce() {
        var message = { t: 'staff-account', from: TAB, account: mine, at: Date.now() };
        try {
            if (channel) {
                channel.postMessage(message);
            } else if (window.localStorage) {
                window.localStorage.setItem(CHANNEL, JSON.stringify(message));
                window.localStorage.removeItem(CHANNEL);
            }
        } catch (e) { /* private mode: the slow poll below still catches it */ }
    }

    announce();

    // ── The slow safety net ─────────────────────────────────────────────────

    function check() {
        return PomidaPollChain.getJson(STATUS_URL).then(function (res) {
            var failed = PomidaPollChain.failure(res);
            if (failed) { return failed; }

            var current = res.data && res.data.signed_in && res.data.account ? res.data.account : null;
            if (!same(current, mine)) {
                follow(current);
                return { stop: true };
            }
            return { changed: false };
        });
    }

    if (typeof window.PomidaPollChain === 'function') {
        chain = PomidaPollChain({
            // The signal above is the fast path; this is for a tab that missed
            // it. It waits one beat before its first question (the page was only
            // just drawn), pauses while hidden and asks at once when visible.
            schedule: [8000, 8000, 15000],
            startDelay: 8000,
            run: check
        });
        chain.start();
    }

    // session-guard's focus-time token refresh found the account changed.
    window.addEventListener('peachy:staff-account-changed', function (event) {
        follow(event.detail || null);
    });

    // ── Stamp what this page sends ──────────────────────────────────────────

    function sameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function stamp(form) {
        if (!mine || !form || form.nodeType !== 1) { return; }

        var method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method === 'get' || method === 'dialog') { return; }
        if (!sameOrigin(form.getAttribute('action') || window.location.href)) { return; }

        // Element.prototype methods, not form.querySelector: a control named
        // like a form method would shadow it.
        var field = Element.prototype.querySelector.call(form, 'input[name="' + FIELD + '"]');
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = FIELD;
            form.appendChild(field);
        }
        field.value = String(mine.id);
    }

    document.addEventListener('submit', function (event) { stamp(event.target); }, true);

    // form.submit() fires no submit event.
    if (window.HTMLFormElement && HTMLFormElement.prototype.submit) {
        var nativeSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () {
            stamp(this);
            return nativeSubmit.apply(this, arguments);
        };
    }

    // fetch(): the header, and the answer to a refusal. Wraps the wrapper in
    // partials/session-guard, so the live CSRF token is still put on first.
    if (mine && typeof window.fetch === 'function' && typeof window.Headers === 'function') {
        var innerFetch = window.fetch.bind(window);

        window.fetch = function (input, init) {
            var url = typeof input === 'string' ? input
                    : (input && input.url) ? input.url
                    : String(input);
            var isRequest = typeof Request === 'function' && input instanceof Request;
            var method = String((init && init.method) || (isRequest ? input.method : 'GET')).toUpperCase();
            var options = init || {};
            var stampIt = method !== 'GET' && method !== 'HEAD' && sameOrigin(url);

            if (stampIt) {
                var headers = new Headers(options.headers || (isRequest ? input.headers : undefined));
                headers.set(HEADER, String(mine.id));
                options = Object.assign({}, options, { headers: headers });
            }

            return innerFetch(input, options).then(function (response) {
                if (stampIt && response && response.status === 409 && response.headers.get(CHANGED) === '1') {
                    response.clone().json().then(
                        function (data) { follow(data && data.account ? data.account : null); },
                        function () { if (chain) { chain.now(); } }
                    );
                }
                return response;
            });
        };
    }
})();
</script>

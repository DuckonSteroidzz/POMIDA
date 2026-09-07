{{--
    Session guard — the CSRF token every background request should be using,
    and one civilised answer when the session has gone anyway.

    Include this ONCE, in <head>, on any page that talks to the server from JS.

    THE TWO PROBLEMS THIS SOLVES
    ----------------------------
    1. STALE TOKENS. Nearly every fetch() in this app was written as

           headers: { 'X-CSRF-TOKEN': '{{ '{{' }} csrf_token() {{ '}}' }}' }

       which bakes the token into the HTML at render time. That is correct for
       the instant the page is served and wrong forever after: the token is
       rotated whenever the session is regenerated (every login, and
       session()->regenerate() is called on both guards), and it dies outright
       when the session expires. A counter screen left open across a shift was
       therefore posting a token the server had already forgotten — the request
       came back 419 and the feature simply stopped working, with nothing on
       screen to say why.

       The wrapper below overrides X-CSRF-TOKEN on every same-origin request
       with the value read LIVE from the meta tag above. The baked-in literals
       in the individual pages are now harmless: whatever they say, this
       replaces it. They were left in place deliberately rather than edited out
       across seventeen call sites, because a page that is somehow served
       without this partial still degrades to the old behaviour instead of
       sending no token at all.

    2. NO META TAG EXISTED. admin/home.blade.php already read

           document.querySelector('meta[name="csrf-token"]').content

       for the manual-order voucher preview, but no page in this application
       ever rendered that meta tag. The querySelector returned null and the
       line threw a TypeError, so the Check button disabled itself and never
       re-enabled. The tag below is what that code was always looking for.

    WHY A RELOAD ON 419
    -------------------
    A 419 means the session is gone; there is no client-side recovery, because
    the new token can only come from the server. Reloading re-authenticates
    from the "Remember me" cookie where one exists and lands on the login page
    where it does not — either way the customer or the cashier sees a working
    page instead of a raw "Page Expired" card that loses where they were. The
    reload is latched so a burst of failing polls cannot loop it.

    Scope is deliberately same-origin only, so nothing here can leak a CSRF
    token to a third party.
--}}
<meta name="csrf-token" content="{{ csrf_token() }}">
<script>
(function () {
    'use strict';

    // Idempotent: a page that includes this twice must not double-wrap fetch.
    if (window.__peachySessionGuard) { return; }
    window.__peachySessionGuard = true;

    function currentToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // Exposed so page scripts can ask for the live token rather than a
    // server-rendered literal.
    window.csrfToken = currentToken;

    var reloadLatched = false;

    function sessionExpired() {
        if (reloadLatched) { return; }
        reloadLatched = true;
        window.location.reload();
    }

    window.handleExpiredSession = sessionExpired;

    function isSameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (e) {
            // Unparseable target: treat as foreign and leave it completely alone.
            return false;
        }
    }

    // ── fetch ──────────────────────────────────────────────────────────────
    if (typeof window.fetch === 'function' && typeof window.Headers === 'function') {
        var nativeFetch = window.fetch.bind(window);

        window.fetch = function (input, init) {
            var url = typeof input === 'string' ? input
                    : (input && input.url) ? input.url
                    : String(input);

            var options = init || {};

            if (isSameOrigin(url)) {
                // Headers.set() REPLACES, so a stale literal supplied by the
                // caller is overwritten rather than appended to.
                var headers = new Headers(
                    options.headers
                        || (typeof Request === 'function' && input instanceof Request
                                ? input.headers
                                : undefined)
                );

                headers.set('X-CSRF-TOKEN', currentToken());

                options = Object.assign({}, options, { headers: headers });
            }

            return nativeFetch(input, options).then(function (response) {
                if (response && response.status === 419) {
                    sessionExpired();
                }
                return response;
            });
        };
    }

    // ── XMLHttpRequest ─────────────────────────────────────────────────────
    //
    // Nothing in this app uses raw XHR today. This is here so that anything
    // added later (or any library dropped in) gets the same treatment without
    // having to remember to.
    if (window.XMLHttpRequest && XMLHttpRequest.prototype) {
        var proto = XMLHttpRequest.prototype;
        var nativeOpen = proto.open;
        var nativeSend = proto.send;
        var nativeSetHeader = proto.setRequestHeader;

        proto.open = function (method, url) {
            this.__peachySameOrigin = isSameOrigin(url);
            return nativeOpen.apply(this, arguments);
        };

        proto.setRequestHeader = function (name, value) {
            // setRequestHeader APPENDS to an existing header rather than
            // replacing it, so letting a caller's stale token through would
            // produce "old,new" and guarantee the 419 we are trying to avoid.
            // Drop it; send() puts the live one on instead.
            if (this.__peachySameOrigin && String(name).toLowerCase() === 'x-csrf-token') {
                return;
            }
            return nativeSetHeader.apply(this, arguments);
        };

        proto.send = function () {
            var xhr = this;

            if (xhr.__peachySameOrigin) {
                try {
                    nativeSetHeader.call(xhr, 'X-CSRF-TOKEN', currentToken());
                } catch (e) {
                    // Header already sent, or the request was aborted. Nothing
                    // useful to do, and throwing here would break the caller.
                }
            }

            xhr.addEventListener('load', function () {
                if (xhr.status === 419) {
                    sessionExpired();
                }
            });

            return nativeSend.apply(this, arguments);
        };
    }
})();
</script>

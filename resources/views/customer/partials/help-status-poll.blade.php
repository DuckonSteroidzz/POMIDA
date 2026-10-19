{{--
    Live "is my help request still open?" for a Dine-In customer (item E, Oct
    2026). Included by the Help page and the More page; both mark the block that
    shows the request with [data-help-region]:

        data-help-status-url      GET customer.help-request.status (JSON)
        data-help-open            "1" while a request is open, "0" otherwise
        data-help-text-pending    wording for a waiting request
        data-help-text-assisting  wording once staff are on their way
        [data-help-message]       the element holding that wording

    Only a region that starts OPEN is watched; a block showing the form has
    nothing to wait for. The poll runs about every 6 seconds, only while the tab
    is visible (it stops when hidden and asks once at once when the tab comes
    back), and stops for good the moment the request is resolved.

    When staff press Done the page does not decide anything itself: it asks the
    server for this same page again and takes the block's new body from it, so
    the form comes back with a fresh token exactly as a reload would show it,
    plus a short "Staff has helped you." note. Nothing needs refreshing by hand.
    A refused or failed poll just skips that tick. The endpoint answers about
    this session's own table only (see AuthController::helpRequestStatus()).
--}}
<script>
    (function () {
        var region = document.querySelector('[data-help-region]');
        if (!region || region.getAttribute('data-help-open') !== '1') return;

        var url = region.getAttribute('data-help-status-url');
        if (!url) return;

        var INTERVAL_MS = 6000;
        var timer = null;
        var inFlight = false;
        var finished = false;

        function stop() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        function start() {
            if (finished || timer || document.hidden) return;
            timer = setInterval(tick, INTERVAL_MS);
        }

        function showMessage(status) {
            var message = region.querySelector('[data-help-message]');
            var text = region.getAttribute('data-help-text-' + status);
            if (message && text && message.textContent.trim() !== text) message.textContent = text;
        }

        function thanks() {
            var note = document.createElement('p');
            note.setAttribute('role', 'status');
            note.setAttribute('data-help-done', '');
            note.className = 'mb-3 flex items-center gap-2 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800';
            note.innerHTML = '<i class="bi bi-check-circle" aria-hidden="true"></i> <span></span>';
            note.querySelector('span').textContent = 'Staff has helped you.';
            return note;
        }

        /* Resolved: swap in the server's current body for this block. */
        function resolved() {
            finished = true;
            stop();

            fetch(window.location.pathname, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
            })
                .then(function (r) { return r.ok ? r.text() : ''; })
                .then(function (html) {
                    var fresh = html
                        ? new DOMParser().parseFromString(html, 'text/html').querySelector('[data-help-region]')
                        : null;

                    if (!fresh) { window.location.reload(); return; }

                    /* The "Assistance requested!" / "already requested" banner above
                       is about the request that just ended. */
                    document.querySelectorAll('[data-help-flash]').forEach(function (el) { el.remove(); });

                    region.innerHTML = fresh.innerHTML;
                    region.setAttribute('data-help-open', fresh.getAttribute('data-help-open') || '0');
                    region.insertBefore(thanks(), region.firstChild);
                })
                .catch(function () { window.location.reload(); });
        }

        function tick() {
            if (finished || inFlight || document.hidden) return;
            inFlight = true;

            fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    inFlight = false;
                    if (!data) return;

                    if (data.open) {
                        showMessage(data.status);
                    } else {
                        resolved();
                    }
                })
                .catch(function () { inFlight = false; });
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stop();
            } else if (!finished) {
                tick();
                start();
            }
        });

        window.addEventListener('pagehide', stop);

        start();
    })();
</script>

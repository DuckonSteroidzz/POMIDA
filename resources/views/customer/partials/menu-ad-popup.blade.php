{{--
    Menu-page ad popup. The ad comes from App\Support\MenuAd — one live,
    in-branch ad with placement = 'menu', handed out at most once per session —
    or null, in which case this renders nothing at all. Included by
    customer/menu.blade.php only; it is resolved here, not in the controller,
    so the controller that owns login/registration is not involved.

    Dismiss: the close button, tapping the backdrop, or Esc. It never
    auto-closes and never traps focus off the page: removing it returns the
    menu exactly as it was.

    SEQUENCING against the other modals on this page (all fixed overlays):
        orderStatusNotice  z 10000   a completed-order notice
        welcomePopup       z 10001   the one-shot welcome, self-removes at 6s
        idleTimeoutModal   z 10002   "Are you still there?" after 10 minutes
        menuAdPopup        z  9990   this one — beneath all three
    The ad never opens on top of, or at the same time as, the welcome popup or
    an order notice: it waits (polling, up to 30s) until neither is on screen,
    so a fresh login sees "Welcome back" first and the ad a moment after it
    closes. If the idle prompt is ever up, the ad gives up rather than open —
    a session about to end is not the time for an advert. Even in a race the
    z-order above keeps every one of those three in front of the ad.

    Everything that comes from the ad row is escaped by Blade; the image path
    goes through Img::url() (the path was written by UploadedImageName from the
    file's content, never from the client's filename); the link is rendered
    only when it is a plain http(s) URL.
--}}
@php
    $menuAd = \App\Support\MenuAd::forCurrentVisit();
@endphp
@if($menuAd)
@php
    $menuAdLink = ($menuAd->link && preg_match('#^https?://#i', $menuAd->link)) ? $menuAd->link : null;
@endphp
<div id="menuAdPopup" role="dialog" aria-modal="true" aria-labelledby="menuAdTitle"
     style="display:none;position:fixed;inset:0;z-index:9990;background:rgba(59,35,32,0.55);align-items:center;justify-content:center;padding:1rem;">
    <div style="width:100%;max-width:340px;max-height:100%;overflow-y:auto;background:#FFFDF9;border:1px solid #F2DDD4;border-radius:16px;box-shadow:0 18px 45px rgba(59,35,32,0.22);position:relative;text-align:center;">
        <button type="button" id="menuAdClose" aria-label="Close"
                style="position:absolute;top:10px;right:10px;z-index:1;width:28px;height:28px;border-radius:50%;border:none;background:rgba(59,35,32,0.55);color:#fff;font-size:13px;cursor:pointer;line-height:1;">
            <i class="bi bi-x-lg"></i>
        </button>
        @if($menuAd->image)
            <img src="{{ \App\Support\Img::url($menuAd->image) }}" alt="{{ $menuAd->title }}"
                 style="display:block;width:100%;max-height:min(250px,40vh);object-fit:cover;border-radius:16px 16px 0 0;">
        @endif
        <div style="padding:18px 20px 20px;">
            <h3 id="menuAdTitle" style="margin:0 0 6px;color:#3b2320;font-family:'Fraunces',Georgia,serif;font-size:19px;line-height:1.25;font-weight:700;overflow-wrap:anywhere;">
                {{ $menuAd->title }}
            </h3>
            @if($menuAd->description)
                <p style="margin:0;color:#8A6A61;font-size:12px;line-height:1.55;overflow-wrap:anywhere;">{{ $menuAd->description }}</p>
            @endif
            @if($menuAdLink)
                <a href="{{ $menuAdLink }}" target="_blank" rel="noopener noreferrer"
                   style="display:inline-flex;align-items:center;justify-content:center;margin-top:14px;height:36px;padding:0 22px;border-radius:999px;background:#EF8585;color:#fff;border:1px solid #EF8585;font-size:12px;font-weight:700;text-decoration:none;">
                    Learn more
                </a>
            @endif
        </div>
    </div>
</div>
<script>
(function () {
    'use strict';

    var overlay = document.getElementById('menuAdPopup');
    if (!overlay) { return; }

    var POLL_MS = 500;
    var GIVE_UP_MS = 30 * 1000;
    var waited = 0;

    function close() {
        document.removeEventListener('keydown', onKey);
        if (overlay) { overlay.remove(); overlay = null; }
    }

    function onKey(event) {
        if (event.key === 'Escape') { close(); }
    }

    function shown(id) {
        var el = document.getElementById(id);
        return !!el && window.getComputedStyle(el).display !== 'none';
    }

    function attempt() {
        if (!overlay) { return; }

        // A session about to end is no time for an advert.
        var idle = document.getElementById('idleTimeoutModal');
        if (idle && !idle.hidden) { close(); return; }

        // Let the welcome popup and any order notice finish first.
        if (shown('welcomePopup') || shown('orderStatusNotice')) {
            waited += POLL_MS;
            if (waited >= GIVE_UP_MS) { close(); return; }
            setTimeout(attempt, POLL_MS);
            return;
        }

        overlay.style.display = 'flex';
        document.addEventListener('keydown', onKey);

        var closeBtn = document.getElementById('menuAdClose');
        if (closeBtn) { closeBtn.focus({ preventScroll: true }); }
    }

    document.getElementById('menuAdClose').addEventListener('click', close);
    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) { close(); }
    });

    // Let the menu paint before anything opens over it.
    setTimeout(attempt, 600);
})();
</script>
@endif

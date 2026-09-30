{{--
    Welcome popup — "Welcome back, {name}" for a logged-in customer who has
    ordered before, "Welcome to {store}" for everyone else (a fresh account,
    a guest Dine-In session, a first-time Pickup visit). $welcomeCustomer is
    set by AuthController::showMenu() only on the request right after a login,
    a registration, or a genuinely new QR table claim — never on ordinary
    browsing — so this never reappears on refresh or normal navigation.

    Dismissible three ways (close button, tapping the backdrop, or waiting it
    out) and never traps interaction — it removes itself from the DOM rather
    than just hiding, same as #orderStatusNotice below it on this page.
--}}
@if(!empty($welcomeCustomer))
@php
    $isReturning = ($welcomeCustomer['type'] ?? 'new') === 'returning';

    // StoreContact::forBranch(null) falls back to the main branch (it exists
    // to give the Store Information/contact screens SOME address to show).
    // That fallback is wrong here: a Pick-Up customer who has not chosen a
    // branch yet is not "at" the main branch, so this popup must not name
    // one. Only resolve a name when a branch is actually known — Pick-Up
    // after a branch pick, or Dine-In, which always sets branch_id from the
    // scanned QR before this popup fires.
    $selectedBranchId = session('branch_id');
    $storeName = $selectedBranchId
        ? \App\Support\StoreContact::forBranch($selectedBranchId)['business_name']
        : null;
@endphp
<div id="welcomePopup" role="dialog" aria-modal="true" aria-labelledby="welcomePopupTitle"
     style="position:fixed;inset:0;z-index:10001;background:rgba(59,35,32,0.55);display:flex;align-items:center;justify-content:center;padding:1rem;">
    <div style="width:100%;max-width:316px;background:#FFFDF9;border:1px solid #F2DDD4;border-radius:16px;padding:24px 20px 20px;text-align:center;box-shadow:0 18px 45px rgba(59,35,32,0.22);position:relative;">
        <button type="button" onclick="closeWelcomePopup()" aria-label="Close"
                style="position:absolute;top:10px;right:10px;width:28px;height:28px;border-radius:50%;border:none;background:#F6ECE6;color:#8A6A61;font-size:13px;cursor:pointer;line-height:1;">
            <i class="bi bi-x-lg"></i>
        </button>
        <div style="width:52px;height:52px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;background:#FDE8DE;font-size:24px;">
            {{ $isReturning ? '👋' : '🍑' }}
        </div>
        <h3 id="welcomePopupTitle" style="margin:0 0 8px;color:#3b2320;font-family:'Fraunces',Georgia,serif;font-size:19px;line-height:1.25;font-weight:700;">
            @if($isReturning)
                Welcome back, {{ $welcomeCustomer['name'] }}!
            @elseif($storeName)
                Welcome to {{ $storeName }}!
            @else
                Welcome!
            @endif
        </h3>
        <p style="max-width:260px;margin:0 auto;color:#8A6A61;font-size:12px;line-height:1.55;">
            @if($isReturning)
                Great to see you again — pick up right where you left off.
            @else
                We're glad you're here. Browse the menu and place your order whenever you're ready.
            @endif
        </p>
        <button type="button" onclick="closeWelcomePopup()"
                style="margin-top:16px;height:36px;padding:0 22px;border-radius:999px;background:#EF8585;color:#fff;border:1px solid #EF8585;font-size:12px;font-weight:700;cursor:pointer;">
            Let's go
        </button>
    </div>
</div>
<script>
(function () {
    window.closeWelcomePopup = function () {
        var overlay = document.getElementById('welcomePopup');
        if (overlay) overlay.remove();
    };

    var overlay = document.getElementById('welcomePopup');
    if (overlay) {
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) window.closeWelcomePopup();
        });
    }

    setTimeout(function () {
        window.closeWelcomePopup();
    }, 6000);
})();
</script>
@endif

{{--
    THE "Order Completed" pop-up: one copy, on every customer page that has the
    notification bell, for every branch, Dine-In and Pick-Up, guest and account.
    It always offers "How was your meal?" with 1-5 stars, Submit Rating, Not now
    and View Receipt.

    Which orders it asks about comes from the server on the page's one poll
    (`completed_popups` on customer.live-status, App\Support\OrderCompletedPopup),
    so it does not matter which page the customer was on, or whether the tab was
    hidden, when staff completed the order. Several completed orders are asked
    about one at a time, oldest first.

    NOTHING IS MARKED WHEN THIS OPENS. Only an answer ends it: Submit Rating (a
    rating row exists) or Not now (orders.rating_prompt_dismissed_at). A reload or
    a page change simply asks again. View Receipt is not an answer either: the
    pop-up leaves that one order alone on its own receipt page and asks again on
    the next page.

    NEVER STACKED: while another overlay is on screen this waits, and if one
    opens over it, it steps aside until that one closes. The reward pop-ups and
    the menu ad wait for this one in the same way.

    Plain CSS on purpose, like the reward pop-ups: nothing here depends on the
    Tailwind build noticing a class. Moved to <body> on load because the bell
    lives inside a header whose backdrop-filter would otherwise trap position:fixed.
--}}
@once
@php
    // The order whose receipt is on screen right now, if any.
    $completedPopupSkip = request()->routeIs('customer.receipt') ? (int) request()->route('id') : 0;
@endphp
<style>
    .ocp {
        position: fixed;
        inset: 0;
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        background: rgba(59, 35, 32, .55);
        overscroll-behavior: contain;
    }
    .ocp[hidden] { display: none; }

    .ocp__card {
        box-sizing: border-box;
        width: min(100%, 360px);
        max-height: calc(100dvh - 2rem);
        overflow-y: auto;
        padding: 22px 20px 20px;
        border: 1px solid #F2DDD4;
        border-radius: 16px;
        background: #FFFDF9;
        box-shadow: 0 18px 45px rgba(59, 35, 32, .22);
        text-align: center;
        overflow-wrap: anywhere;
    }

    .ocp__icon {
        display: grid;
        place-items: center;
        width: 46px;
        height: 46px;
        margin: 0 auto 12px;
        border-radius: 50%;
        background: #E4F2EA;
        color: #2E7D5B;
        font-size: 22px;
    }

    .ocp__title {
        margin: 0 0 8px;
        color: #2E7D5B;
        font-family: 'Fraunces', Georgia, serif;
        font-size: 1.375rem;
        font-weight: 700;
        line-height: 1.2;
    }

    .ocp__text {
        margin: 0 auto;
        color: #6B4038;
        font-size: .875rem;
        line-height: 1.5;
    }

    .ocp__rating {
        margin-top: 14px;
        padding-top: 14px;
        border-top: 1px solid #F2DDD4;
    }

    .ocp__prompt {
        margin: 0 0 8px;
        color: #6B4038;
        font-size: 1rem;
        font-weight: 700;
    }

    .ocp__stars {
        display: flex;
        justify-content: center;
        gap: 6px;
    }

    .ocp__star {
        width: 44px;
        height: 44px;
        padding: 0;
        border: 1px solid #ead8d2;
        border-radius: 10px;
        background: #fff;
        font-size: 1.125rem;
        line-height: 1;
        cursor: pointer;
        opacity: .45;
    }
    .ocp__star.is-on {
        border-color: #C0392B;
        background: #FDE8DE;
        opacity: 1;
    }

    .ocp__btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        box-sizing: border-box;
        min-height: 44px;
        padding: 0 14px;
        border: 1px solid #F2DDD4;
        border-radius: 999px;
        background: #fff;
        color: #8B1A1A;
        font-size: .875rem;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
    }
    .ocp__btn--submit {
        width: 100%;
        margin-top: 10px;
        border-color: #C0392B;
        background: #C0392B;
        color: #fff;
        font-size: 1rem;
    }
    .ocp__btn--submit:disabled {
        opacity: .5;
        cursor: not-allowed;
    }
    .ocp__btn--receipt {
        border-color: #2E7D5B;
        background: #2E7D5B;
        color: #fff;
    }

    .ocp__message {
        margin: 8px 0 0;
        color: #B3261E;
        font-size: .875rem;
        font-weight: 700;
        line-height: 1.4;
    }
    .ocp__message.is-thanks { color: #2E7D5B; }
    .ocp__message:empty { display: none; }

    .ocp__actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }
    .ocp__actions .ocp__btn { flex: 1 1 0; white-space: nowrap; }

    .ocp__star:focus-visible,
    .ocp__btn:focus-visible {
        outline: 3px solid #F4845F;
        outline-offset: 2px;
    }

    @media print { .ocp { display: none !important; } }
</style>

<div id="orderCompletedPopup" class="ocp" hidden data-testid="order-completed-popup" data-skip-order="{{ $completedPopupSkip }}">
    <div class="ocp__card" role="dialog" aria-modal="true" aria-labelledby="ocpTitle" aria-describedby="ocpText">
        <div class="ocp__icon" aria-hidden="true"><i class="bi bi-check-circle"></i></div>
        <h2 id="ocpTitle" class="ocp__title">Order Completed</h2>
        <p id="ocpText" class="ocp__text"></p>

        <div class="ocp__rating">
            <p id="ocpPrompt" class="ocp__prompt">How was your meal?</p>
            <div id="ocpStars" class="ocp__stars" role="group" aria-labelledby="ocpPrompt">
                @for($i = 1; $i <= 5; $i++)
                    <button type="button" class="ocp__star" data-ocp-star="{{ $i }}" aria-label="{{ $i }} out of 5">⭐</button>
                @endfor
            </div>
            <button type="button" id="ocpSubmit" class="ocp__btn ocp__btn--submit" disabled>Submit Rating</button>
            <p id="ocpMessage" class="ocp__message" role="status"></p>
        </div>

        <div class="ocp__actions">
            <a id="ocpReceipt" class="ocp__btn ocp__btn--receipt" href="#"><i class="bi bi-receipt" aria-hidden="true"></i> View Receipt</a>
            <button type="button" id="ocpLater" class="ocp__btn">Not now</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var root = document.getElementById('orderCompletedPopup');
    if (!root || !window.PomidaLive) { return; }

    document.body.appendChild(root);

    var RATING_URL = @json(url('/customer/orders')) + '/';
    var CSRF_TOKEN = @json(csrf_token());
    var SKIP_ORDER = Number(root.getAttribute('data-skip-order')) || 0;

    // Every other overlay a customer page can put on screen. This pop-up waits
    // for them; the reward pop-ups and menu ad list this one in return.
    var OTHERS = [
        'spinPromptModal', 'orderStatusNotice', 'discountVerificationNotice',
        'ratingModal', 'dessertRatingModal', 'welcomePopup', 'menuAdPopup',
        'idleTimeoutModal', 'tableChangeNotice', 'tableChangeConfirm',
        'dineInRewardsPopup', 'ongoingOrderPrompt', 'accountPrompt',
        'logoutConfirmModal', 'helpModal', 'orderConfirmOverlay',
        'discountCheckingOverlay', 'voucherWinModal', 'spinResultModal', 'adOverlay'
    ];

    var textEl = document.getElementById('ocpText');
    var promptEl = document.getElementById('ocpPrompt');
    var starsEl = document.getElementById('ocpStars');
    var submitEl = document.getElementById('ocpSubmit');
    var messageEl = document.getElementById('ocpMessage');
    var receiptEl = document.getElementById('ocpReceipt');
    var laterEl = document.getElementById('ocpLater');
    var stars = root.querySelectorAll('[data-ocp-star]');

    var current = null;    // the order being asked about
    var chosen = 0;
    var answered = false;  // rated: now only the Close button is left
    var saving = false;
    var onScreen = false;
    var heartbeat = null;
    var finished = {};     // answered on this page; a poll sent earlier must not bring it back
    var opener = null;
    var scrollLocked = false;
    var prevHtmlOverflow = '';
    var prevBodyOverflow = '';

    function visible(el) {
        if (!el || el.hidden) { return false; }
        var style = window.getComputedStyle(el);
        return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    }

    function othersShowing() {
        for (var i = 0; i < OTHERS.length; i++) {
            if (visible(document.getElementById(OTHERS[i]))) { return true; }
        }
        return false;
    }

    function paintStars(value) {
        stars.forEach(function (star) {
            star.classList.toggle('is-on', Number(star.getAttribute('data-ocp-star')) <= value);
            star.setAttribute('aria-pressed', Number(star.getAttribute('data-ocp-star')) === value ? 'true' : 'false');
        });
    }

    function prepare(order) {
        current = order;
        chosen = 0;
        answered = false;
        saving = false;

        textEl.textContent = 'Order #' + order.order_number + ' has been completed. Thank you for ordering from Peachy!';
        receiptEl.href = order.receipt_url;
        promptEl.textContent = 'How was your meal?';
        starsEl.hidden = false;
        starsEl.style.display = '';
        submitEl.style.display = '';
        submitEl.disabled = true;
        submitEl.textContent = 'Submit Rating';
        messageEl.textContent = '';
        messageEl.classList.remove('is-thanks');
        laterEl.textContent = 'Not now';
        paintStars(0);
    }

    function focusFirst() {
        var target = answered ? laterEl : stars[0];
        if (target && target.focus) { target.focus({ preventScroll: true }); }
    }

    function present() {
        if (!scrollLocked) {
            opener = document.activeElement;
            prevHtmlOverflow = document.documentElement.style.overflow;
            prevBodyOverflow = document.body.style.overflow;
            scrollLocked = true;
        }
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';

        root.hidden = false;
        onScreen = true;
        document.addEventListener('keydown', onKeydown, true);
        focusFirst();
    }

    // Another overlay needs the screen: hide and keep this order for later.
    function stepAside() {
        root.hidden = true;
        onScreen = false;
        document.removeEventListener('keydown', onKeydown, true);
    }

    function tick() {
        if (current) {
            var busy = othersShowing();
            if (onScreen && busy) { stepAside(); }
            else if (!onScreen && !busy) { present(); }
        }

        if (current && !heartbeat) { heartbeat = setInterval(tick, 500); }
        if (!current && heartbeat) { clearInterval(heartbeat); heartbeat = null; }
    }

    function hide() {
        stepAside();
        current = null;

        if (scrollLocked) {
            scrollLocked = false;
            document.documentElement.style.overflow = prevHtmlOverflow;
            document.body.style.overflow = prevBodyOverflow;
            if (opener && opener !== document.body && document.contains(opener) && opener.focus) {
                opener.focus({ preventScroll: true });
            }
        }

        tick();
    }

    function nextPlease() {
        hide();
        // The next order, if there is one, comes on this answer rather than
        // whenever the back-off next gets round to asking.
        window.PomidaLive.refresh();
    }

    function showRated(rating) {
        answered = true;
        chosen = rating;
        paintStars(rating);
        promptEl.textContent = 'You rated this meal';
        starsEl.style.display = 'none';
        submitEl.style.display = 'none';
        messageEl.textContent = rating + ' / 5 — thanks for the feedback!';
        messageEl.classList.add('is-thanks');
        laterEl.textContent = 'Close';
        laterEl.focus({ preventScroll: true });

        // The Orders page repaints that order's row in its History list.
        document.dispatchEvent(new CustomEvent('pomida:order-rated', {
            detail: { orderId: current.order_id, rating: rating }
        }));
    }

    function submitRating() {
        if (!current || chosen < 1 || saving) { return; }

        var order = current;
        saving = true;
        submitEl.disabled = true;
        submitEl.textContent = 'Saving…';
        messageEl.textContent = '';

        fetch(RATING_URL + encodeURIComponent(order.order_id) + '/rating', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CSRF_TOKEN
            },
            credentials: 'same-origin',
            body: JSON.stringify({ rating: chosen, rating_type: 'stars' })
        })
            .then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (data) {
                    return { res: res, data: data };
                });
            })
            .then(function (result) {
                saving = false;

                // Saved now, or already rated elsewhere (another tab, History).
                if ((result.res.ok && result.data.ok) || (result.data.rated && result.data.rating)) {
                    finished[order.order_id] = true;
                    showRated(Number(result.data.rating));
                    return;
                }

                messageEl.textContent = result.data.message || 'Sorry, that rating could not be saved.';
                submitEl.disabled = false;
                submitEl.textContent = 'Submit Rating';
            })
            .catch(function () {
                saving = false;
                messageEl.textContent = 'Sorry, that rating could not be saved.';
                submitEl.disabled = false;
                submitEl.textContent = 'Submit Rating';
            });
    }

    function notNow() {
        if (!current) { return; }

        if (answered) { nextPlease(); return; }

        var order = current;
        finished[order.order_id] = true;

        // Hide at once; a failed save only means it is asked again later.
        fetch(RATING_URL + encodeURIComponent(order.order_id) + '/rating-prompt/dismiss', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CSRF_TOKEN
            },
            credentials: 'same-origin'
        })
            .then(function (res) { if (!res.ok) { delete finished[order.order_id]; } })
            .catch(function () { delete finished[order.order_id]; })
            .then(function () { window.PomidaLive.refresh(); });

        hide();
    }

    function onKeydown(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            notNow();
            return;
        }
        if (e.key !== 'Tab') { return; }

        var focusable = [].filter.call(
            root.querySelectorAll('button:not(:disabled), a[href]'),
            function (el) { return el.offsetParent !== null; }
        );
        if (!focusable.length) { return; }

        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (!root.contains(document.activeElement)) {
            e.preventDefault();
            first.focus();
        } else if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    function onLive(live) {
        var queue = (Array.isArray(live.completed_popups) ? live.completed_popups : []).filter(function (o) {
            return !finished[o.order_id] && Number(o.order_id) !== SKIP_ORDER;
        });

        // Answered somewhere else in the meantime (another tab or device).
        if (current && !answered && !saving && !queue.some(function (o) { return o.order_id === current.order_id; })) {
            hide();
        }

        if (!current && queue.length) {
            prepare(queue[0]);
            tick();
        }
    }

    stars.forEach(function (star) {
        star.addEventListener('click', function () {
            chosen = Number(star.getAttribute('data-ocp-star'));
            paintStars(chosen);
            submitEl.disabled = false;
            messageEl.textContent = '';
        });
    });

    submitEl.addEventListener('click', submitRating);
    laterEl.addEventListener('click', notNow);

    window.PomidaLive.subscribe({ onData: onLive });
})();
</script>
@endonce

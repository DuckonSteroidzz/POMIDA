{{--
    "You have an ongoing order." — the small in-page prompt a Dine-In guest gets
    in place of Log in / Create account while their own order is still in
    progress (item H, Oct 2026). See App\Support\DineInJoin::ongoingOrder().

    Two ways in, one text:
      1. Any link or button marked data-ongoing-order-block (the page was drawn
         while an order was ongoing) opens it instead of navigating.
      2. The server flash DineInJoin::NOTICE_KEY, set by
         BlockGuestAuthWhileOrdering when a request reached the login/register
         routes anyway (a stale page, a typed address): it opens on arrival.
    The server guard is the real protection; this is the explanation.

    Only drawn for a guest who is blocked or who was just sent back, so every
    other visitor (signed in, pick-up, nothing ongoing) gets no markup at all.

    Plain CSS on the theme's own variables, like the Orders card: no new
    Tailwind utilities. Text >= 16px, tap targets >= 44px.
--}}
@php
    $ongoingNotice = session(\App\Support\DineInJoin::NOTICE_KEY);
    $ongoingBlocked = \App\Support\DineInJoin::blocksGuestAuth();
@endphp
@if($ongoingBlocked || $ongoingNotice)
<style>
    .ongoing-prompt {
        position: fixed;
        inset: 0;
        z-index: 200;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        background: rgb(0 0 0 / 0.5);
    }
    .ongoing-prompt.is-open { display: flex; }
    .ongoing-prompt__box {
        width: 100%;
        max-width: 22rem;
        border-radius: 1.5rem;
        background: #fff;
        padding: 1.25rem;
        text-align: center;
        box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.35);
    }
    .ongoing-prompt__icon { font-size: 1.75rem; color: var(--color-peach-red, #C0392B); }
    .ongoing-prompt__text {
        margin: 0.5rem 0 1rem;
        font-size: 1rem;
        font-weight: 600;
        line-height: 1.5;
        color: var(--color-peach-deep, #8B1A1A);
    }
    .ongoing-prompt__ok {
        min-height: 2.75rem;
        width: 100%;
        border: 0;
        border-radius: 9999px;
        background: var(--color-peach-red, #C0392B);
        color: #fff;
        font-size: 1rem;
        font-weight: 700;
        cursor: pointer;
    }
    .ongoing-prompt__ok:hover,
    .ongoing-prompt__ok:focus-visible { background: var(--color-peach-deep, #8B1A1A); }
</style>
<div id="ongoingOrderPrompt" class="ongoing-prompt" role="alertdialog" aria-modal="true"
     aria-labelledby="ongoingOrderPromptText" aria-hidden="true" data-open-on-load="{{ $ongoingNotice ? '1' : '0' }}">
    <div class="ongoing-prompt__box">
        <i class="bi bi-hourglass-split ongoing-prompt__icon" aria-hidden="true"></i>
        <p id="ongoingOrderPromptText" class="ongoing-prompt__text">{{ \App\Support\DineInJoin::MSG_ONGOING_ORDER }}</p>
        <button type="button" class="ongoing-prompt__ok" data-ongoing-order-ok>OK</button>
    </div>
</div>
<script>
    (function () {
        var box = document.getElementById('ongoingOrderPrompt');
        if (!box) return;
        var ok = box.querySelector('[data-ongoing-order-ok]');
        var opener = null;

        function open(from) {
            opener = from || document.activeElement;
            box.classList.add('is-open');
            box.setAttribute('aria-hidden', 'false');
            if (ok) ok.focus();
        }
        function close() {
            box.classList.remove('is-open');
            box.setAttribute('aria-hidden', 'true');
            if (opener && typeof opener.focus === 'function') opener.focus();
            opener = null;
        }

        /* Capture phase: this must win over any handler on the link itself
           (the rewards pop-up buttons, for one). */
        document.addEventListener('click', function (e) {
            var el = e.target.closest ? e.target.closest('[data-ongoing-order-block]') : null;
            if (!el) return;
            e.preventDefault();
            e.stopPropagation();
            open(el);
        }, true);

        if (ok) ok.addEventListener('click', close);
        box.addEventListener('click', function (e) { if (e.target === box) close(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && box.classList.contains('is-open')) close();
            if (e.key === 'Tab' && box.classList.contains('is-open')) { e.preventDefault(); if (ok) ok.focus(); }
        });

        if (box.getAttribute('data-open-on-load') === '1') open(null);
    })();
</script>
@endif

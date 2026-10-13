{{--
    One-time "play Spin & Win while you wait" modal for the Orders page.

    Rendered only when App\Support\SpinPrompt::forOrders() said so, and that call
    has ALREADY stamped orders.spin_prompted_at — so this markup is the one and
    only time the prompt is shown for these orders, whatever the browser does
    next (refresh, second tab, another phone). Nothing here is state the server
    trusts, and nothing here grants a spin: "Play now" is a plain link to the
    existing game page, which does all of its own counting and checking.

    Expects $spinPrompt = ['order_number' => string, 'spins_remaining' => int].

    Sizing follows the Oct 2026 customer type scale: interactive text >= 16px,
    helper text >= 14px, tap targets >= 44px (Play now is 56px).
--}}
<style>
    .spin-prompt {
        position: fixed;
        inset: 0;
        z-index: 5200;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        background: rgba(45, 24, 20, .6);
        overscroll-behavior: contain;
    }
    .spin-prompt[hidden] { display: none; }

    .spin-prompt__card {
        position: relative;
        box-sizing: border-box;
        width: min(100%, 360px);
        max-height: calc(100dvh - 2rem);
        overflow-y: auto;
        padding: 2.25rem 1.25rem 1.25rem;
        border-radius: 20px;
        background: #FFFDF9;
        box-shadow: 0 24px 70px rgba(45, 24, 20, .32);
        text-align: center;
        overflow-wrap: anywhere;
    }

    .spin-prompt__close {
        position: absolute;
        top: .35rem;
        right: .35rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 44px;
        height: 44px;
        padding: 0;
        border: 0;
        border-radius: 999px;
        background: transparent;
        color: #8B1A1A;
        font-size: 1.25rem;
        line-height: 1;
        cursor: pointer;
    }
    .spin-prompt__close:hover { background: #FDE8DE; }

    .spin-prompt__emoji { font-size: 2.5rem; line-height: 1; }

    .spin-prompt__title {
        margin: .6rem 0 0;
        font-family: var(--font-display);
        font-size: 1.375rem;
        font-weight: 800;
        line-height: 1.2;
        color: #8B1A1A;
    }

    .spin-prompt__text {
        margin: .6rem 0 0;
        font-size: 1rem;
        line-height: 1.45;
        color: #3b2320;
    }

    .spin-prompt__note {
        margin: .5rem 0 0;
        font-size: .875rem;
        line-height: 1.4;
        color: #8A6A61;
    }

    .spin-prompt__play {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        box-sizing: border-box;
        width: 100%;
        min-height: 56px;
        margin-top: 1.1rem;
        padding: .75rem 1.25rem;
        border-radius: 999px;
        background: #C0392B;
        color: #fff;
        font-size: 1.125rem;
        font-weight: 800;
        text-decoration: none;
        box-shadow: 0 10px 24px -12px rgba(139, 26, 26, .8);
    }
    .spin-prompt__play:hover { background: #8B1A1A; }

    .spin-prompt__close:focus-visible,
    .spin-prompt__play:focus-visible {
        outline: 3px solid #F4845F;
        outline-offset: 2px;
    }
</style>

<div id="spinPromptModal" class="spin-prompt" hidden>
    <div class="spin-prompt__card" role="dialog" aria-modal="true"
         aria-labelledby="spinPromptTitle" aria-describedby="spinPromptDesc">

        <button type="button" class="spin-prompt__close" data-spin-prompt-close aria-label="Close">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>

        <div class="spin-prompt__emoji" aria-hidden="true">🎰</div>
        <h2 id="spinPromptTitle" class="spin-prompt__title">Play Spin &amp; Win while you wait!</h2>

        @php $spinCount = (int) $spinPrompt['spins_remaining']; @endphp
        <p id="spinPromptDesc" class="spin-prompt__text">
            You have <strong>{{ $spinCount }}</strong> {{ $spinCount === 1 ? 'spin' : 'spins' }} for order #{{ $spinPrompt['order_number'] }}. Win points and vouchers.
        </p>

        <a href="{{ route('customer.game') }}" class="spin-prompt__play" data-spin-prompt-play>
            <i class="bi bi-dice-5" aria-hidden="true"></i> Play now
        </a>

        <p class="spin-prompt__note">Your order status stays on this page.</p>
    </div>
</div>

<script>
    (function () {
        var modal = document.getElementById('spinPromptModal');
        if (!modal) return;

        var closeBtn = modal.querySelector('[data-spin-prompt-close]');
        var playBtn = modal.querySelector('[data-spin-prompt-play]');
        var opener = null;
        var prevHtmlOverflow = '';
        var prevBodyOverflow = '';

        function onKeydown(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                close();
                return;
            }
            if (e.key !== 'Tab') return;

            // Two controls only: keep Tab inside the dialog.
            var first = closeBtn, last = playBtn;
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            } else if (!modal.contains(document.activeElement)) {
                e.preventDefault();
                playBtn.focus();
            }
        }

        function open() {
            opener = document.activeElement;
            prevHtmlOverflow = document.documentElement.style.overflow;
            prevBodyOverflow = document.body.style.overflow;
            document.documentElement.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';

            modal.hidden = false;
            document.addEventListener('keydown', onKeydown, true);
            playBtn.focus();
        }

        function close() {
            modal.hidden = true;
            document.removeEventListener('keydown', onKeydown, true);
            document.documentElement.style.overflow = prevHtmlOverflow;
            document.body.style.overflow = prevBodyOverflow;

            // Back to where focus was. On a fresh page load that is <body>,
            // which is not a useful target, so fall back to the page's main
            // region (focusable programmatically, not a tab stop).
            var target = opener;
            if (!target || target === document.body || !document.contains(target)) {
                target = document.querySelector('main');
                if (target) target.setAttribute('tabindex', '-1');
            }
            if (target && target.focus) target.focus({ preventScroll: true });
        }

        closeBtn.addEventListener('click', close);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) close();
        });

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', open);
        } else {
            open();
        }
    })();
</script>

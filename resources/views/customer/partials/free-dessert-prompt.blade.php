{{--
    One-time "You earned a reward!" pop-up — Orders and Menu pages
    (Oct 2026). Rules in App\Support\FreeDessertPrompt. Its one button opens
    More → Vouchers & Rewards, where the reward's code and "Use now" are.

    Rendered only for a signed-in customer who can earn the stamp card reward
    (pageState() !== null); a guest gets no markup and no script. Nothing is
    claimed while the page renders: data-pending says a reward is waiting,
    and the script below asks the server (POST customer.free-dessert.prompt)
    when the pop-up's turn actually comes. The server marks the rewards as
    announced in that same request and answers "show" to exactly one asker,
    so a refresh, a second tab or another phone never shows it again.

    LIVE: the page's order poll calls
    window.PomidaFreeDessertPrompt.orderCompleted(orderId) when it sees an
    order complete; the script then asks the same endpoint (and once more
    four seconds later, in case staff's completion has not finished granting
    the reward yet).

    NEVER STACKED: while any other overlay on these pages is on screen (Spin
    prompt, order-completed notice, discount notice, rating modals, welcome
    popup, menu ad, idle prompt, Change table) the pop-up waits, and if one
    opens over it, it steps aside until that one closes. A MutationObserver
    does this before the next paint; a slow timer backs it up.

    Sizing matches the Spin & Win prompt: interactive text >= 16px, the
    button 56px tall with 18px text, close target 44px, card at most 360px wide.
--}}
@php $freeDessertPending = \App\Support\FreeDessertPrompt::pageState(); @endphp
@if($freeDessertPending !== null)
<style>
    .fd-prompt {
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
    .fd-prompt[hidden] { display: none; }

    .fd-prompt__card {
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

    .fd-prompt__close {
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
    .fd-prompt__close:hover { background: #FDE8DE; }

    .fd-prompt__icon {
        display: grid;
        place-items: center;
        width: 64px;
        height: 64px;
        margin: 0 auto;
        border-radius: 999px;
        background: #FDE8DE;
        color: #C0392B;
        font-size: 2rem;
        line-height: 1;
    }

    .fd-prompt__title {
        margin: .75rem 0 0;
        font-family: var(--font-display);
        font-size: 1.375rem;
        font-weight: 800;
        line-height: 1.2;
        color: #8B1A1A;
    }

    .fd-prompt__text {
        margin: .6rem 0 0;
        font-size: 1rem;
        line-height: 1.45;
        color: #3b2320;
    }

    .fd-prompt__claim {
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
    .fd-prompt__claim:hover { background: #8B1A1A; }

    .fd-prompt__close:focus-visible,
    .fd-prompt__claim:focus-visible {
        outline: 3px solid #F4845F;
        outline-offset: 2px;
    }
</style>

<div id="freeDessertPrompt" class="fd-prompt" hidden data-pending="{{ $freeDessertPending ? '1' : '0' }}">
    <div class="fd-prompt__card" role="dialog" aria-modal="true"
         aria-labelledby="freeDessertPromptTitle" aria-describedby="freeDessertPromptDesc">

        <button type="button" class="fd-prompt__close" data-fd-prompt-close aria-label="Close">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>

        <div class="fd-prompt__icon" aria-hidden="true"><i class="bi bi-gift-fill"></i></div>
        <h2 id="freeDessertPromptTitle" class="fd-prompt__title">You earned a reward!</h2>
        <p id="freeDessertPromptDesc" class="fd-prompt__text">Your reward code is waiting in Vouchers &amp; Rewards.</p>

        <a href="{{ route('customer.vouchers') }}#rewards" class="fd-prompt__claim" data-fd-prompt-claim>
            <i class="bi bi-ticket-perforated" aria-hidden="true"></i> Open Vouchers &amp; Rewards
        </a>
    </div>
</div>

<script>
    (function () {
        var root = document.getElementById('freeDessertPrompt');
        if (!root || root.getAttribute('data-ready') === '1') return;
        root.setAttribute('data-ready', '1');

        var ANNOUNCE_URL = @json(route('customer.free-dessert.prompt'));
        var CSRF_TOKEN = @json(csrf_token());
        var RETRY_MS = 4000;

        // Every other overlay the Orders and Menu pages can put on screen.
        var OTHERS = [
            'spinPromptModal', 'orderStatusNotice', 'discountVerificationNotice',
            'ratingModal', 'dessertRatingModal', 'welcomePopup', 'menuAdPopup',
            'idleTimeoutModal', 'tableChangeNotice', 'tableChangeConfirm'
        ];

        var titleEl = document.getElementById('freeDessertPromptTitle');
        var textEl = document.getElementById('freeDessertPromptDesc');
        var closeBtn = root.querySelector('[data-fd-prompt-close]');
        var claimBtn = root.querySelector('[data-fd-prompt-claim]');

        var wanted = false;    // asked for, not yet sent
        var asking = false;    // request in flight
        var isOpen = false;    // has a reward to show (on screen or stepped aside)
        var onScreen = false;
        var heartbeat = null;
        var seenOrders = {};

        var opener = null;
        var scrollLocked = false;
        var prevHtmlOverflow = '';
        var prevBodyOverflow = '';

        function visible(el) {
            if (!el || el.hidden) return false;
            var style = window.getComputedStyle(el);
            return style.display !== 'none' && style.visibility !== 'hidden';
        }

        function othersShowing() {
            for (var i = 0; i < OTHERS.length; i++) {
                if (visible(document.getElementById(OTHERS[i]))) return true;
            }
            return false;
        }

        function tick() {
            var busy = othersShowing();

            if (isOpen) {
                if (onScreen && busy) stepAside();
                else if (!onScreen && !busy) present();
            } else if (wanted && !asking && !busy) {
                ask();
            }

            var work = isOpen || wanted || asking;
            if (work && !heartbeat) heartbeat = setInterval(tick, 500);
            if (!work && heartbeat) { clearInterval(heartbeat); heartbeat = null; }
        }

        function request() {
            wanted = true;
            tick();
        }

        function ask() {
            wanted = false;
            asking = true;

            fetch(ANNOUNCE_URL, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': CSRF_TOKEN
                },
                credentials: 'same-origin',
                cache: 'no-store'
            })
                .then(function (res) { return res.ok ? res.json() : null; })
                .then(function (data) {
                    asking = false;
                    if (data && data.show) {
                        titleEl.textContent = data.title;
                        textEl.textContent = data.message;
                        isOpen = true;
                    }
                    tick();
                })
                .catch(function () {
                    asking = false;
                    tick();
                });
        }

        function onKeydown(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                close();
                return;
            }
            if (e.key !== 'Tab') return;

            // Two controls only: keep Tab inside the dialog.
            if (e.shiftKey && document.activeElement === closeBtn) {
                e.preventDefault();
                claimBtn.focus();
            } else if (!e.shiftKey && document.activeElement === claimBtn) {
                e.preventDefault();
                closeBtn.focus();
            } else if (!root.contains(document.activeElement)) {
                e.preventDefault();
                claimBtn.focus();
            }
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
            claimBtn.focus();
        }

        // Another overlay opened over it: hide, keep the reward, come back later.
        function stepAside() {
            root.hidden = true;
            onScreen = false;
            document.removeEventListener('keydown', onKeydown, true);
        }

        function close() {
            stepAside();
            isOpen = false;

            if (scrollLocked) {
                scrollLocked = false;
                document.documentElement.style.overflow = prevHtmlOverflow;
                document.body.style.overflow = prevBodyOverflow;

                // Back to where focus was; on a fresh page load that is
                // <body>, so fall back to the page's main region.
                var target = opener;
                if (!target || target === document.body || !document.contains(target)) {
                    target = document.querySelector('main');
                    if (target) target.setAttribute('tabindex', '-1');
                }
                if (target && target.focus) target.focus({ preventScroll: true });
            }

            tick();
        }

        closeBtn.addEventListener('click', close);
        root.addEventListener('click', function (e) {
            if (e.target === root) close();
        });

        if (window.MutationObserver) {
            new MutationObserver(function () {
                if (isOpen || wanted) tick();
            }).observe(document.body, {
                subtree: true,
                childList: true,
                attributes: true,
                attributeFilter: ['style', 'class', 'hidden']
            });
        }

        window.PomidaFreeDessertPrompt = {
            // A poll on this page saw one of the customer's orders complete.
            orderCompleted: function (orderId) {
                var key = String(orderId);
                if (seenOrders[key]) return;
                seenOrders[key] = true;

                request();
                setTimeout(request, RETRY_MS);
            }
        };

        // A reward was already waiting when the page loaded. DOMContentLoaded
        // runs after the Spin prompt's own handler, so that one goes first.
        if (root.getAttribute('data-pending') === '1') {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', request);
            } else {
                request();
            }
        }
    })();
</script>
@endif

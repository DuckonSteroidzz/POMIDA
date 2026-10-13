{{--
    Dine-In rewards pop-up (Oct 2026) — replaces the small "Sign up free to
    collect stamps and earn rewards" line on the Dine-In menu.

    WHO / WHEN: App\Support\DineInJoin::claimMenuPopup() decides, in
    AuthController::showMenu(), and hands this partial $dineInJoinPopup, or
    null for nobody. Only a signed-out guest seated at a table, at a branch
    where stamps can be earned, ever gets it, and only ONCE per table session.
    Building the page does NOT use up that showing (the pop-up waits for the
    welcome popup, ~3s, so a reload in that window would lose it unseen): the
    script below POSTs to customer.dinein-popup-seen the first time it really
    opens, and that writes the "already shown" record to the server-side
    session. From then on a refresh or another page load cannot bring it back.
    Menu page only; there is deliberately no re-open button and no form here.

    LINKS: the same two routes the old line used (customer.dinein-join), so
    DineInJoin::start() pins this session's order exactly as before. The
    links carry no order id and no table.

    TIMING: the welcome popup fires 'welcomepopup:closed' when it goes (Let's
    go, close, backdrop or its 3s auto-close) and this pop-up checks right
    then, so it follows at once; the 500ms poll is only the fallback for the
    other overlays, which have no such event.

    NEVER STACKED: it waits (polling) while any other overlay on the menu is
    on screen (welcome popup, menu ad, order notice, reward prompt, idle
    prompt, Change table, Spin prompt...), and steps aside if one opens over
    it, as the reward prompt does.

    Plain CSS on the theme's own variables, no new fonts: the customer
    Tailwind bundle is pre-built and prefixed classes below are this
    partial's own. Sizing follows the customer type scale: text >= 14px
    (body 16px), buttons and links >= 44px high.
--}}
@if(!empty($dineInJoinPopup))
@php
    $dinrStamps = max(2, (int) ($dineInJoinPopup['stamps'] ?? \App\Support\DineInJoin::POPUP_DEFAULT_STAMPS));
    // Filled stamps, then hearts, then the gift as the last circle.
    $dinrFilled = min(2, $dinrStamps - 1);
@endphp
<style>
    .dinr {
        position: fixed;
        inset: 0;
        z-index: 5200;
        display: flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
        padding: 1rem;
        background: color-mix(in srgb, var(--color-peach-deep) 48%, transparent);
        -webkit-backdrop-filter: blur(4px);
        backdrop-filter: blur(4px);
        overscroll-behavior: contain;
    }
    .dinr[hidden] { display: none; }

    .dinr__card {
        position: relative;
        box-sizing: border-box;
        width: 100%;
        max-width: 410px;
        max-height: calc(100dvh - 2rem);
        overflow-y: auto;
        overscroll-behavior: contain;
        border: 1px solid var(--color-peach-soft);
        border-radius: 1.5rem;
        background: var(--color-peach-cream);
        color: var(--color-peach-deep);
        text-align: center;
        box-shadow: 0 24px 60px -16px color-mix(in srgb, var(--color-peach-deep) 45%, transparent);
        overflow-wrap: anywhere;
    }
    .dinr__card:focus { outline: none; }

    .dinr__body { padding: 3rem 1.5rem 1.25rem; }
    @media (max-width: 400px) { .dinr__body { padding-inline: 1.25rem; } }

    .dinr__close {
        position: absolute;
        top: .25rem;
        right: .25rem;
        z-index: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 44px;
        height: 44px;
        padding: 0;
        border: 0;
        border-radius: 999px;
        background: transparent;
        color: var(--color-peach-deep);
        font-size: 1.125rem;
        line-height: 1;
        cursor: pointer;
    }
    .dinr__close:hover { background: var(--color-peach-soft); }

    .dinr__eyebrow {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .4rem;
        margin: 0;
        color: var(--color-peach-red);
        font-size: .875rem;
        font-weight: 800;
        letter-spacing: .12em;
        line-height: 1.3;
        text-transform: uppercase;
    }

    .dinr__badge {
        position: relative;
        display: grid;
        place-items: center;
        width: 5rem;
        height: 5rem;
        margin: 1rem auto 0;
        border-radius: 999px;
        background: var(--color-peach-soft);
        color: var(--color-peach-red);
        font-size: 2.25rem;
        line-height: 1;
    }
    .dinr__badge-spark {
        position: absolute;
        top: -.1rem;
        right: -.2rem;
        font-size: 1.25rem;
        color: var(--color-peach);
    }

    .dinr__title {
        margin: 1rem 0 0;
        font-family: var(--font-display);
        font-size: clamp(1.375rem, 6.2vw, 1.875rem);
        font-weight: 800;
        line-height: 1.2;
        color: var(--color-peach-deep);
        text-wrap: balance;
    }
    .dinr__title span { display: block; }

    .dinr__text {
        margin: .75rem 0 0;
        font-size: 1rem;
        line-height: 1.5;
        text-wrap: pretty;
        color: color-mix(in srgb, var(--color-peach-deep) 78%, var(--color-peach-cream));
    }

    .dinr__stamps {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        margin: 1.25rem 0 0;
        padding: 0;
        list-style: none;
    }
    .dinr__stamp {
        display: grid;
        place-items: center;
        box-sizing: border-box;
        width: 2.75rem;
        height: 2.75rem;
        border: 2px dashed var(--color-peach-rose);
        border-radius: 999px;
        color: var(--color-peach-rose);
        font-size: 1.125rem;
        line-height: 1;
    }
    .dinr__stamps--six .dinr__stamp { width: 2.5rem; height: 2.5rem; }
    .dinr__stamp--done {
        border: 2px solid var(--color-peach-red);
        background: var(--color-peach-red);
        color: #fff;
    }
    .dinr__stamp--gift {
        border-color: var(--color-peach-red);
        background: var(--color-peach-soft);
        color: var(--color-peach-red);
    }

    .dinr__cta {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        box-sizing: border-box;
        width: 100%;
        min-height: 3.25rem;
        margin-top: 1.25rem;
        padding: .75rem 1.25rem;
        border-radius: 999px;
        background: var(--color-peach-red);
        color: #fff;
        font-size: 1.0625rem;
        font-weight: 800;
        text-decoration: none;
        transition: background-color .18s ease;
    }
    .dinr__cta:hover { background: var(--color-peach-deep); color: #fff; }

    .dinr__login {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        column-gap: .5rem;
        margin: .5rem 0 0;
        font-size: 1rem;
        line-height: 1.4;
    }
    .dinr__login a {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        min-height: 44px;
        color: var(--color-peach-red);
        font-weight: 800;
        line-height: 1.6;
        text-decoration: underline;
        text-decoration-skip-ink: auto;
        text-underline-offset: .2em;
    }
    .dinr__login a:hover { color: var(--color-peach-deep); }

    .dinr__foot {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        padding: .85rem 1.25rem;
        border-top: 1px solid var(--color-peach-rose);
        background: color-mix(in srgb, var(--color-peach-soft) 75%, var(--color-peach-cream));
        color: var(--color-peach-deep);
        font-size: .875rem;
        font-weight: 700;
        line-height: 1.35;
    }
    .dinr__foot i { color: var(--color-peach-red); }

    .dinr__close:focus-visible,
    .dinr__cta:focus-visible,
    .dinr__login a:focus-visible {
        outline: 3px solid var(--color-peach);
        outline-offset: 2px;
    }

    @media (prefers-reduced-motion: no-preference) {
        .dinr:not([hidden]) { animation: dinr-fade .22s ease-out; }
        .dinr:not([hidden]) .dinr__card { animation: dinr-rise .26s ease-out; }
    }
    @keyframes dinr-fade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes dinr-rise { from { opacity: 0; transform: translateY(12px) scale(.97); } to { opacity: 1; transform: none; } }
</style>

<div id="dineInRewardsPopup" class="dinr" hidden data-dinein-join-popup>
    <div class="dinr__card" role="dialog" aria-modal="true"
         aria-labelledby="dineInRewardsTitle" aria-describedby="dineInRewardsText" tabindex="-1">

        <button type="button" class="dinr__close" data-dinr-close aria-label="Close">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>

        <div class="dinr__body">
            <p class="dinr__eyebrow"><i class="bi bi-stars" aria-hidden="true"></i> A little something for you</p>

            <div class="dinr__badge" aria-hidden="true">
                <i class="bi bi-gift"></i>
                <i class="bi bi-stars dinr__badge-spark"></i>
            </div>

            <h2 id="dineInRewardsTitle" class="dinr__title"><span>Sweet visits.</span> <span>Even sweeter rewards.</span></h2>

            <p id="dineInRewardsText" class="dinr__text">
                Your favourite treats, with a little extra.<br>
                Join Peachy to collect stamps and earn rewards.
            </p>

            <div class="dinr__stamps {{ $dinrStamps > 5 ? 'dinr__stamps--six' : '' }}" aria-hidden="true">
                @for($i = 1; $i <= $dinrStamps; $i++)
                    @if($i <= $dinrFilled)
                        <span class="dinr__stamp dinr__stamp--done"><i class="bi bi-check-lg"></i></span>
                    @elseif($i === $dinrStamps)
                        <span class="dinr__stamp dinr__stamp--gift"><i class="bi bi-gift"></i></span>
                    @else
                        <span class="dinr__stamp"><i class="bi bi-heart-fill"></i></span>
                    @endif
                @endfor
            </div>

            <a href="{{ route('customer.dinein-join', ['mode' => \App\Support\DineInJoin::MODE_SIGNUP]) }}"
               class="dinr__cta" data-dinr-signup>
                Sign up for free <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>

            <p class="dinr__login">
                <span>Already part of the family?</span>
                <a href="{{ route('customer.dinein-join', ['mode' => \App\Support\DineInJoin::MODE_LOGIN]) }}" data-dinr-login>
                    Log in <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </p>
        </div>

        <div class="dinr__foot">
            <i class="bi bi-heart-fill" aria-hidden="true"></i>
            <span>A little thank-you for every visit</span>
        </div>
    </div>
</div>

<script>
    (function () {
        var root = document.getElementById('dineInRewardsPopup');
        if (!root || root.getAttribute('data-ready') === '1') return;
        root.setAttribute('data-ready', '1');

        var card = root.querySelector('.dinr__card');
        var closeBtn = root.querySelector('[data-dinr-close]');
        var loginLink = root.querySelector('[data-dinr-login]');
        var POLL_MS = 500;

        // Every other overlay the menu page can put on screen.
        var OTHERS = [
            'spinPromptModal', 'orderStatusNotice', 'discountVerificationNotice',
            'ratingModal', 'dessertRatingModal', 'welcomePopup', 'menuAdPopup',
            'freeDessertPrompt', 'idleTimeoutModal', 'tableChangeNotice', 'tableChangeConfirm'
        ];

        var finished = false;      // closed for good
        var onScreen = false;
        var locked = false;
        var opener = null;
        var prevHtml = '';
        var prevBody = '';
        var timer = null;
        var reported = false;

        // Tell the server it really opened: that is what uses up its one
        // showing. A failed report only means it may show once more.
        function reportSeen() {
            if (reported) return;
            reported = true;
            try {
                fetch(@json(route('customer.dinein-popup-seen')), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': @json(csrf_token())
                    },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    keepalive: true
                }).catch(function () {});
            } catch (e) {}
        }

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

        function lock() {
            if (locked) return;
            prevHtml = document.documentElement.style.overflow;
            prevBody = document.body.style.overflow;
            locked = true;
            document.documentElement.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';
        }

        function unlock() {
            if (!locked) return;
            locked = false;
            document.documentElement.style.overflow = prevHtml;
            document.body.style.overflow = prevBody;
        }

        function onKeydown(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                close();
                return;
            }
            if (e.key !== 'Tab') return;

            var first = closeBtn;
            var last = loginLink;
            if (e.shiftKey && (document.activeElement === first || document.activeElement === card)) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            } else if (!root.contains(document.activeElement)) {
                e.preventDefault();
                first.focus();
            }
        }

        function present() {
            if (!opener) opener = document.activeElement;
            lock();
            root.hidden = false;
            onScreen = true;
            document.addEventListener('keydown', onKeydown, true);
            card.focus({ preventScroll: true });
            reportSeen();
        }

        // Another overlay opened over it: hide, come back when it is gone.
        function stepAside() {
            root.hidden = true;
            onScreen = false;
            unlock();
            document.removeEventListener('keydown', onKeydown, true);
        }

        function close() {
            finished = true;
            stepAside();
            if (timer) { clearInterval(timer); timer = null; }

            var target = opener;
            if (!target || target === document.body || !document.contains(target)) {
                target = document.querySelector('main');
                if (target && !target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
            }
            if (target && target.focus) target.focus({ preventScroll: true });
        }

        function tick() {
            if (finished) return;
            var busy = othersShowing();
            if (onScreen && busy) stepAside();
            else if (!onScreen && !busy) present();
        }

        // The welcome popup just went: open now, do not wait for the next poll.
        document.addEventListener('welcomepopup:closed', tick);

        closeBtn.addEventListener('click', close);
        root.addEventListener('click', function (e) {
            if (e.target === root) close();
        });

        // Let the menu paint before anything opens over it.
        setTimeout(function () {
            tick();
            if (!finished) timer = setInterval(tick, POLL_MS);
        }, 700);
    })();
</script>
@endif

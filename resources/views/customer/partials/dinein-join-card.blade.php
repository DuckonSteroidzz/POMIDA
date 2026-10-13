{{--
    "Want a stamp for this order?" — Dine-In guests only, on the Orders page
    (Oct 2026). Rendered only when App\Support\DineInJoin::cardOrder() found an
    open, ownerless Dine-In order this session placed, at a branch where stamps
    can be earned.

    Inline, never a modal: it sits above the order cards, so the one-time Spin
    & Win prompt still opens on top of the page exactly as before.

    Sign up and Log in look the same on purpose. A returning customer must see
    Log in as clearly as Sign up, or they make a second account.

    The buttons carry no order id. /customer/join/{mode} picks the order from
    this session (App\Support\DineInJoin::start()).

    Plain CSS on the theme's own variables: the customer Tailwind bundle is
    pre-built, so new utility classes would not exist in it. Sizing follows
    the customer type scale: text >= 16px, helper >= 14px, taps >= 44px.
--}}
<style>
    .dinein-join {
        display: grid;
        gap: .75rem;
        margin-bottom: 1.25rem;
        padding: 1rem;
        border: 1px solid var(--color-peach-soft);
        border-radius: 1rem;
        background: var(--color-peach-cream);
    }

    .dinein-join__title {
        margin: 0;
        font-family: var(--font-display);
        font-size: 1.125rem;
        font-weight: 800;
        line-height: 1.3;
        color: var(--color-peach-deep);
    }

    .dinein-join__text {
        margin: .2rem 0 0;
        font-size: 1rem;
        line-height: 1.45;
        color: var(--color-peach-deep);
    }

    .dinein-join__actions {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: .6rem;
    }

    .dinein-join__btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: .4rem;
        min-height: 44px;
        padding: .5rem .75rem;
        border: 2px solid var(--color-peach-red);
        border-radius: 999px;
        background: #fff;
        color: var(--color-peach-red);
        font-size: 1rem;
        font-weight: 800;
        text-align: center;
        text-decoration: none;
    }

    .dinein-join__btn:hover,
    .dinein-join__btn:focus-visible {
        background: var(--color-peach-soft);
    }
</style>

<section class="dinein-join" data-dinein-join-card aria-labelledby="dineinJoinTitle">
    <div>
        <h2 id="dineinJoinTitle" class="dinein-join__title">
            <i class="bi bi-stars" aria-hidden="true"></i> Want a stamp for this order?
        </h2>
        <p class="dinein-join__text">Sign up free and confirm your email today to get a stamp for this order.</p>
    </div>

    <div class="dinein-join__actions">
        <a href="{{ route('customer.dinein-join', ['mode' => \App\Support\DineInJoin::MODE_SIGNUP]) }}" class="dinein-join__btn">
            Sign up
        </a>
        <a href="{{ route('customer.dinein-join', ['mode' => \App\Support\DineInJoin::MODE_LOGIN]) }}" class="dinein-join__btn">
            Log in
        </a>
    </div>
</section>

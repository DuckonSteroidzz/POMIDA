{{--
    Bonus points hero — top of the Account page's Overview tab.

    The bonus balance on the reference's gradient (docs/design-refs/peachy-
    account-settings.html). It used to sit inside the Account benefits card;
    it is the one number a customer comes here for, so it leads the Overview
    panel. The Spin & Win button is hidden while the owner has the game
    switched off. Split out of partials/account-header (which stays above the
    tabs, always visible) when the page got its four tabs.
--}}
<section class="acct-card relative overflow-hidden p-4 text-white after:pointer-events-none after:absolute after:-right-[60px] after:-top-[60px] after:h-60 after:w-60 after:rounded-full after:bg-white/10 sm:p-[22px]" style="background-image: linear-gradient(130deg, var(--color-peach-red), var(--color-peach) 55%, var(--color-peach-rose));" data-testid="bonus-banner">
    <div class="relative z-10 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm font-medium text-white/90">Bonus points</p>
            <p class="font-display text-[56px] font-bold leading-none" data-testid="bonus-points">{{ number_format($loyalty['bonus_points']) }} <span class="text-[22px]">pts</span></p>
            <p class="mt-2 max-w-md text-sm text-white/90">Count toward Spin &amp; Win vouchers.</p>
        </div>
        @if(\App\Models\Setting::gameEnabled())
            <a href="{{ route('customer.game') }}" class="inline-flex min-h-11 items-center gap-2 rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-peach-deep no-underline transition hover:bg-peach-cream">
                <i class="bi bi-dice-5" aria-hidden="true"></i> Go to Spin &amp; Win
            </a>
        @endif
    </div>
</section>

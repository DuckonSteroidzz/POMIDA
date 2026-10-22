{{--
    Bonus points card — top of the right-hand column of the Account page's
    Overview tab, above the Reward Stamp Card (Oct 2026, tester's updated
    docs/design-refs/peachy-account-settings.html, ".points-card"). It replaced
    the full-width banner above the grid and the Reward Challenge card; the
    file and the data-testid hooks keep their first names.

    The bonus balance on the reference's gradient. It is the one number a
    customer comes here for. The Spin & Win button is hidden while the owner
    has the game switched off. Always rendered (it does not depend on the
    streak or stamp switches), so a customer with both switched off or no
    branch picked yet still sees their balance.

    Compact on purpose: 160px tall on wide screens, natural height at 760px
    down, and under 640px the button drops below the number (left-aligned).
--}}
<section class="acct-card relative flex min-h-40 items-center overflow-hidden px-4 py-5 text-white after:pointer-events-none after:absolute after:-right-[60px] after:-top-[60px] after:h-60 after:w-60 after:rounded-full after:bg-white/10 sm:px-[22px] max-[761px]:min-h-0" style="background-image: linear-gradient(130deg, var(--color-peach-red), var(--color-peach) 55%, var(--color-peach-rose));" data-testid="bonus-banner">
    <div class="relative z-10 flex w-full flex-wrap items-center justify-between gap-4 max-[641px]:items-start">
        <div class="min-w-0">
            <p class="text-sm font-medium text-white/90">Bonus points</p>
            <p class="font-display text-[52px] font-bold leading-none" data-testid="bonus-points">{{ number_format($loyalty['bonus_points']) }} <span class="text-[22px]">pts</span></p>
            <p class="mt-[5px] text-xs text-white/90">Count toward Spin &amp; Win vouchers.</p>
        </div>
        @if(\App\Models\Setting::gameEnabled())
            <a href="{{ route('customer.game') }}" class="inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-full bg-white px-3 py-[9px] text-xs font-semibold text-peach-deep no-underline transition hover:bg-peach-cream max-[641px]:mt-2">
                <i class="bi bi-dice-5" aria-hidden="true"></i> Go to Spin &amp; Win
            </a>
        @endif
    </div>
</section>

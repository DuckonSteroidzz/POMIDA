{{--
    Where the Streak Calendar goes at a branch whose own switch is off, so the
    Account page keeps the same two columns at every branch. Orders are not
    touched: the days show again as soon as the branch is switched back on.
--}}
<section id="streak" class="acct-card min-w-0 scroll-mt-24 p-4 sm:p-[22px] col-span-12 min-[761px]:col-span-7" aria-labelledby="streak-title" data-testid="streak-unavailable" data-branch="{{ $streakBranch?->id }}">
    <div class="mb-3 flex items-start gap-3">
        <span class="acct-icon text-lg" aria-hidden="true">
            <i class="bi bi-calendar-check"></i>
        </span>
        <div class="min-w-0">
            <h2 id="streak-title" class="font-display text-lg font-bold leading-snug text-peach-deep">Streak Calendar</h2>
            <p class="inline-flex items-center gap-1 rounded-full border border-peach-soft bg-peach-soft/50 px-2 py-0.5 text-xs font-semibold text-peach-deep" data-testid="streak-branch">
                <i class="bi bi-shop" aria-hidden="true"></i> {{ $streakBranch?->name }}
            </p>
        </div>
    </div>
    <p class="rounded-2xl bg-peach-soft/60 p-3 text-sm font-medium text-peach-deep" data-testid="streak-unavailable-text">The streak calendar isn't available at this branch yet.</p>
</section>

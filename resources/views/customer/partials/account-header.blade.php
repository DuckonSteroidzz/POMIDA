{{--
    Account page profile card (Oct 2026): who this is. It sits ABOVE the four
    tabs and is always visible, whichever tab is open.

    Avatar (the first letter of the name), name, email, an email
    status badge, and three mini stats: Points, Streak, Stamps. Everything is
    real data for the branch the customer is ordering from, the same figures
    the cards below it show: $customer->wheelPoints() (spin + bonus points, as
    Vouchers & Rewards shows), $streak['current']['length'] and
    $freeDessert['progress']. A stat whose feature is off or has no branch yet
    shows a dash rather than a made-up zero. Read-only; the details are edited
    in the Profile tab.

    The bonus-balance hero is partials/account-bonus-banner (Overview tab).
--}}
@php
    $headerPoints = $customer?->wheelPoints() ?? 0;
    $headerStreak = $streak ? (int) $streak['current']['length'] : null;
    $headerStamps = ($freeDessert['enabled'] ?? false)
        ? $freeDessert['progress'] . '/' . $freeDessert['visit_days']
        : null;
    $headerVerified = $customer?->hasVerifiedEmail() ?? false;
    $headerInitial = mb_strtoupper(mb_substr(trim((string) ($customer?->name ?? '')) ?: 'P', 0, 1));
@endphp
<section class="acct-card flex flex-wrap items-center gap-3 border-l-[6px] border-peach p-3.5 sm:gap-4 sm:px-5 sm:py-[18px]" data-testid="account-header" aria-label="Your account">
    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-gradient-to-br from-peach-rose to-peach font-display text-lg font-bold text-white shadow-[0_6px_14px_-6px_var(--color-peach-red)] sm:h-[58px] sm:w-[58px] sm:text-[22px]" aria-hidden="true">{{ $headerInitial }}</span>
    <div class="min-w-0 flex-1">
        <h2 class="truncate font-display text-xl font-bold leading-tight text-peach-deep" data-testid="account-header-name">{{ $customer?->name }}</h2>
        <p class="truncate text-sm text-peach-deep/60" data-testid="account-header-email">{{ $customer?->email }}</p>
        @if($headerVerified)
            <span class="mt-1.5 inline-flex items-center gap-1.5 rounded-full bg-[#e4f5ea] px-2.5 py-0.5 text-xs font-semibold text-[#2f8a5b]" data-testid="account-header-verified">
                <i class="bi bi-patch-check-fill" aria-hidden="true"></i> Verified
            </span>
        @else
            <span class="mt-1.5 inline-flex items-center gap-1.5 rounded-full bg-peach-soft px-2.5 py-0.5 text-xs font-semibold text-peach-deep" data-testid="account-header-unverified">
                <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i> Not verified
            </span>
        @endif
    </div>

    <div class="flex w-full gap-2 sm:ml-auto sm:w-auto" data-testid="account-header-chips">
        <div class="min-w-0 flex-1 rounded-[14px] bg-peach-soft/60 px-1 py-2 text-center sm:min-w-[72px] sm:flex-none sm:px-3.5">
            <p class="font-display text-lg font-bold leading-tight text-peach-red" data-testid="chip-points">{{ number_format($headerPoints) }}</p>
            <p class="text-xs text-peach-deep/70">Points</p>
        </div>
        <div class="min-w-0 flex-1 rounded-[14px] bg-peach-soft/60 px-1 py-2 text-center sm:min-w-[72px] sm:flex-none sm:px-3.5">
            <p class="font-display text-lg font-bold leading-tight text-peach-red" data-testid="chip-streak">{{ $headerStreak !== null ? $headerStreak . ($headerStreak === 1 ? ' day' : ' days') : '—' }}</p>
            <p class="text-xs text-peach-deep/70">Streak</p>
        </div>
        <div class="min-w-0 flex-1 rounded-[14px] bg-peach-soft/60 px-1 py-2 text-center sm:min-w-[72px] sm:flex-none sm:px-3.5">
            <p class="font-display text-lg font-bold leading-tight text-peach-red" data-testid="chip-stamps">{{ $headerStamps ?? '—' }}</p>
            <p class="text-xs text-peach-deep/70">Stamps</p>
        </div>
    </div>
</section>

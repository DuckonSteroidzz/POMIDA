{{--
    Reward challenge bar — Account page, right under the Streak Calendar
    (Oct 2026; the file keeps its first name, from when the reward was always
    a free dessert). Registered customers only.

    The same $freeDessert as the stamp card (App\Services\FreeDesserts::
    summaryFor) — PER BRANCH, labeled with the branch the customer is
    ordering from. "Day D of X" counts the stamps on the current card: days
    with a completed order at that branch, any days, not in a row (D mod X,
    X from that branch's App\Services\StreakRewardSettings). While a reward
    usable there is waiting the bar gives way to a "Reward ready" state that
    links to its code on Vouchers & Rewards.

    Layout (Oct 2026 redesign): the top card of the right-hand column of the
    Account page's Overview grid (the stamp card is under it); the card, icon
    tile and gradient bar follow docs/design-refs/peachy-account-settings.html.

    Phone sizing: 16px text, 14px (text-xs) helper line only; the bar is a
    full-width track, so it shrinks at 360px instead of pushing the page
    sideways.
--}}
@if($freeDessert['enabled'] ?? false)
@php
    $challengeDays = $freeDessert['visit_days'];
    $challengeDay = $freeDessert['progress'];
    $challengeLeft = $challengeDays - $challengeDay;
    $challengeReady = $freeDessert['available'];
    $challengeBranch = $freeDessert['branch_name'] ?? 'this branch';
@endphp
<section class="acct-card p-4 sm:p-[22px]" aria-labelledby="free-dessert-challenge-title" data-testid="free-dessert-challenge" data-branch="{{ $freeDessert['branch_id'] ?? '' }}">
    <div class="mb-4 flex items-start gap-3">
        <span class="acct-icon text-lg" aria-hidden="true"><i class="bi bi-star"></i></span>
        <div class="min-w-0">
            <p class="inline-flex items-center gap-1 rounded-full border border-peach-soft bg-peach-soft/50 px-2 py-0.5 text-xs font-semibold text-peach-deep" data-testid="free-dessert-challenge-branch">
                <i class="bi bi-shop" aria-hidden="true"></i> {{ $challengeBranch }}
            </p>
            <h2 id="free-dessert-challenge-title" class="mt-1 font-display text-lg font-bold leading-snug text-peach-deep">{{ $challengeReady->isNotEmpty() ? ($challengeReady->count() === 1 ? 'Reward ready' : $challengeReady->count() . ' rewards ready') : 'Reward Challenge' }}</h2>
        </div>
    </div>
    @if($challengeReady->isNotEmpty())
        <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-green-200 bg-green-50 p-3" data-testid="free-dessert-ready">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-green-700 text-lg text-white" aria-hidden="true">
                <i class="bi bi-gift-fill"></i>
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm text-peach-deep/70">
                    {{ $challengeReady->first()->description() }}.
                    @if($challengeReady->first()->expires_at)
                        Use it by {{ \App\Support\ShopDay::format($challengeReady->first()->expires_at, 'M j, Y') }}.
                    @endif
                </p>
            </div>
            <a href="{{ route('customer.vouchers') }}#rewards"
                class="inline-flex min-h-11 items-center gap-1.5 rounded-full bg-peach-red px-4 py-2 text-sm font-bold text-white no-underline transition hover:bg-peach-deep">
                See code <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    @else
        <div class="flex items-baseline justify-between gap-x-3">
            <span class="text-sm text-peach-deep/70">Progress</span>
            <p class="font-display text-xl font-bold text-peach-red" data-testid="free-dessert-day">Day {{ $challengeDay }} of {{ $challengeDays }}</p>
        </div>
        <div class="mb-2 mt-3 h-3 overflow-hidden rounded-full bg-peach-soft" role="progressbar"
             aria-valuemin="0" aria-valuemax="{{ $challengeDays }}" aria-valuenow="{{ $challengeDay }}"
             aria-label="Days toward your next reward">
            <div class="h-full rounded-full bg-gradient-to-r from-peach-red to-peach-rose" style="width: {{ round($challengeDay / $challengeDays * 100) }}%"></div>
        </div>
        <p class="text-xs text-peach-deep/70" data-testid="free-dessert-day-text">
            {{ $challengeLeft }} more {{ $challengeLeft === 1 ? 'day' : 'days' }} to a reward.
        </p>
    @endif
</section>
@endif

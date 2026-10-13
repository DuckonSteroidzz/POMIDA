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

    Phone sizing: 16px text, 14px (text-xs) helper line only; the bar is a
    flex-1 min-w-0 track beside a fixed icon, so it shrinks at 360px instead
    of pushing the page sideways.
--}}
@if($freeDessert['enabled'] ?? false)
@php
    $challengeDays = $freeDessert['visit_days'];
    $challengeDay = $freeDessert['progress'];
    $challengeLeft = $challengeDays - $challengeDay;
    $challengeReady = $freeDessert['available'];
    $challengeBranch = $freeDessert['branch_name'] ?? 'this branch';
@endphp
<section class="card-surface w-full max-w-3xl p-3 sm:p-6" aria-labelledby="free-dessert-challenge-title" data-testid="free-dessert-challenge" data-branch="{{ $freeDessert['branch_id'] ?? '' }}">
    <p class="mb-2 inline-flex items-center gap-1 text-sm font-bold text-peach-red" data-testid="free-dessert-challenge-branch">
        <i class="bi bi-shop" aria-hidden="true"></i> {{ $challengeBranch }}
    </p>
    @if($challengeReady->isNotEmpty())
        <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-green-200 bg-green-50 p-3" data-testid="free-dessert-ready">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-green-700 text-lg text-white" aria-hidden="true">
                <i class="bi bi-gift-fill"></i>
            </span>
            <div class="min-w-0 flex-1">
                <h2 id="free-dessert-challenge-title" class="font-display text-lg font-bold text-peach-deep">
                    {{ $challengeReady->count() === 1 ? 'Reward ready' : $challengeReady->count() . ' rewards ready' }}
                </h2>
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
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <h2 id="free-dessert-challenge-title" class="font-display text-lg font-bold text-peach-deep">Reward challenge</h2>
            <p class="font-display text-xl font-black text-peach-red" data-testid="free-dessert-day">Day {{ $challengeDay }} of {{ $challengeDays }}</p>
        </div>
        <div class="mt-3 flex items-center gap-2">
            <div class="h-3 min-w-0 flex-1 overflow-hidden rounded-full bg-peach-soft" role="progressbar"
                 aria-valuemin="0" aria-valuemax="{{ $challengeDays }}" aria-valuenow="{{ $challengeDay }}"
                 aria-label="Days toward your next reward">
                <div class="h-full rounded-full bg-peach-red" style="width: {{ round($challengeDay / $challengeDays * 100) }}%"></div>
            </div>
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-peach-soft text-base text-peach-red" aria-hidden="true">
                <i class="bi bi-gift-fill"></i>
            </span>
        </div>
        <p class="mt-2 text-xs text-peach-deep/70" data-testid="free-dessert-day-text">
            1 stamp per day you completed an order at {{ $challengeBranch }}, any days.
            {{ $challengeLeft }} more {{ $challengeLeft === 1 ? 'day' : 'days' }} to a reward.
        </p>
    @endif
</section>
@endif

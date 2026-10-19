{{--
    Account benefits + Recent bonuses — Account page, registered customers only.

    Two cards that sit side by side from 1100px up (7 + 5 of 12 columns) and
    stack below it. They are direct children of the page's 12-column grid
    (account-settings.blade.php), so the spans live here.

    Every number in the benefits card is read from config/loyalty.php (bonuses)
    or the Spin & Win constants in AuthController (spins), passed in as $loyalty
    by AuthController::showAccount(). Nothing is hard-coded, so this card cannot
    advertise a figure the server does not pay. A bonus set to 0 in the config
    is simply not listed.

    Recent bonuses: $recentAwards (points bonuses only; spin reversals and
    stamp-card rewards are filtered out by the controller). With none yet the
    card says so, so the benefits card never sits beside a hole.
--}}
@php
    $milestones = $loyalty['milestones'];
@endphp
<section id="benefits" class="acct-card col-span-12 min-w-0 p-4 sm:p-[22px] min-[1100px]:col-span-7" aria-labelledby="benefits-title">
    <div class="mb-4 flex items-start gap-3">
        <span class="acct-icon text-lg">
            <i class="bi bi-gift"></i>
        </span>
        <div class="min-w-0">
            <h2 id="benefits-title" class="font-display text-lg font-bold leading-snug text-peach-deep">Account Benefits</h2>
            <p class="text-sm text-peach-deep/60">What you earn with an account.</p>
        </div>
    </div>

    {{-- The bonus balance is the hero at the top of the page (partials/account-header). --}}
    <ul class="grid grid-cols-[repeat(auto-fill,minmax(220px,1fr))] gap-3" role="list">
        <li class="min-w-0">
            <a href="{{ route('customer.vouchers') }}" class="flex h-full items-start gap-3 rounded-2xl border border-peach-soft bg-white p-4 no-underline transition hover:-translate-y-0.5 hover:border-peach-rose">
                <span class="acct-icon text-lg"><i class="bi bi-ticket-perforated"></i></span>
                <span class="min-w-0">
                    <span class="block text-base font-semibold text-peach-deep">Vouchers &amp; Rewards</span>
                    <span class="block text-sm text-peach-deep/70">Saved to your account.</span>
                </span>
            </a>
        </li>

        @if($milestones)
        <li class="flex min-w-0 items-start gap-3 rounded-2xl border border-peach-soft bg-white p-4">
            <span class="acct-icon text-lg"><i class="bi bi-fire"></i></span>
            <span class="min-w-0">
                <span class="block text-base font-semibold text-peach-deep">Streak bonuses</span>
                <span class="block text-sm text-peach-deep/70" data-testid="benefit-streak">
                    @foreach($milestones as $days => $points)
                        +{{ $points }} pts at {{ $days }} days{{ $loop->last ? '.' : ',' }}
                    @endforeach
                    Once per streak.
                </span>
            </span>
        </li>
        @endif

        {{-- $milestones above is empty while the owner's Streak Calendar
             switch is off. The stamp card is per branch: this line is the
             branch the customer is ordering from, shown while that branch's
             card is on (both switches). --}}
        @php $benefitBranch = \App\Services\FreeDesserts::sessionBranch(); @endphp
        @if($benefitBranch && \App\Services\FreeDesserts::active($benefitBranch->id))
        @php $benefitReward = \App\Services\StreakRewardSettings::current($benefitBranch->id)['description']; @endphp
        <li class="min-w-0">
            <a href="#free-dessert" class="flex h-full items-start gap-3 rounded-2xl border border-peach-soft bg-white p-4 no-underline transition hover:-translate-y-0.5 hover:border-peach-rose">
                <span class="acct-icon text-lg"><i class="bi bi-gift"></i></span>
                <span class="min-w-0">
                    <span class="block text-base font-semibold text-peach-deep">Stamp card reward</span>
                    <span class="block text-sm text-peach-deep/70" data-testid="benefit-free-dessert">A reward{{ $benefitReward ? ' (' . $benefitReward . ')' : '' }} every {{ \App\Services\FreeDesserts::visitDays($benefitBranch->id) }} stamps.</span>
                </span>
            </a>
        </li>
        @endif

        @if($loyalty['welcome_points'] > 0)
        <li class="flex min-w-0 items-start gap-3 rounded-2xl border border-peach-soft bg-white p-4">
            <span class="acct-icon text-lg"><i class="bi bi-stars"></i></span>
            <span class="min-w-0">
                <span class="block text-base font-semibold text-peach-deep">Welcome bonus</span>
                <span class="block text-sm text-peach-deep/70" data-testid="benefit-welcome">
                    @if($loyalty['welcome_paid'])
                        <i class="bi bi-check-circle-fill text-green-700"></i> Received: +{{ $loyalty['welcome_points'] }} points.
                    @else
                        +{{ $loyalty['welcome_points'] }} points when you verify your email.
                    @endif
                </span>
            </span>
        </li>
        @endif

        @if($loyalty['order_points'] > 0)
        <li class="flex min-w-0 items-start gap-3 rounded-2xl border border-peach-soft bg-white p-4">
            <span class="acct-icon text-lg"><i class="bi bi-bag-check"></i></span>
            <span class="min-w-0">
                <span class="block text-base font-semibold text-peach-deep">Order bonus</span>
                <span class="block text-sm text-peach-deep/70" data-testid="benefit-order">+{{ $loyalty['order_points'] }} points per completed order.</span>
            </span>
        </li>
        @endif

        <li class="flex min-w-0 items-start gap-3 rounded-2xl border border-peach-soft bg-white p-4">
            <span class="acct-icon text-lg"><i class="bi bi-dice-5"></i></span>
            <span class="min-w-0">
                <span class="block text-base font-semibold text-peach-deep">Spin &amp; Win</span>
                <span class="block text-sm text-peach-deep/70" data-testid="benefit-spins">
                    {{ $loyalty['spins_per_order'] }} spins per order, up to {{ $loyalty['spins_per_day'] }} a day.
                </span>
            </span>
        </li>

        <li class="min-w-0">
            <a href="{{ route('customer.orders', ['tab' => 'history']) }}" class="flex h-full items-start gap-3 rounded-2xl border border-peach-soft bg-white p-4 no-underline transition hover:-translate-y-0.5 hover:border-peach-rose">
                <span class="acct-icon text-lg"><i class="bi bi-receipt"></i></span>
                <span class="min-w-0">
                    <span class="block text-base font-semibold text-peach-deep">Order history</span>
                    <span class="block text-sm text-peach-deep/70">Past orders and receipts.</span>
                </span>
            </a>
        </li>
    </ul>
</section>

<section class="acct-card col-span-12 min-w-0 p-4 sm:p-[22px] min-[1100px]:col-span-5" aria-labelledby="recent-bonuses-title">
    <div class="mb-3 flex items-start gap-3">
        <span class="acct-icon text-lg">
            <i class="bi bi-graph-up-arrow"></i>
        </span>
        <div class="min-w-0">
            <h2 id="recent-bonuses-title" class="font-display text-lg font-bold leading-snug text-peach-deep">Recent Bonuses</h2>
            <p class="text-sm text-peach-deep/60">Your latest points activity.</p>
        </div>
    </div>

    @if($recentAwards->isNotEmpty())
        <ul class="flex flex-col divide-y divide-peach-soft" role="list" data-testid="recent-bonuses">
            @foreach($recentAwards as $award)
                <li class="flex items-center justify-between gap-3 px-1 py-3">
                    <span class="flex min-w-0 items-center gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-peach-soft/60 text-peach-red" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
                        <span class="min-w-0 text-base text-peach-deep">
                            {{ $award->label() }}
                            <span class="block text-sm text-peach-deep/60">{{ \App\Support\ShopDay::format($award->created_at, 'M j, Y') }}</span>
                        </span>
                    </span>
                    <span class="shrink-0 rounded-full bg-[#e4f5ea] px-2.5 py-0.5 text-sm font-bold text-green-700">+{{ $award->points }}</span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="py-3 text-sm text-peach-deep/60" data-testid="recent-bonuses-empty">No bonuses yet.</p>
    @endif
</section>

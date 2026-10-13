{{--
    Account benefits — Account page, registered customers only.

    Every number here is read from config/loyalty.php (bonuses) or the Spin &
    Win constants in AuthController (spins), passed in as $loyalty by
    AuthController::showAccount(). Nothing is hard-coded, so this card cannot
    advertise a figure the server does not pay. A bonus set to 0 in the config
    is simply not listed.
--}}
@php
    $milestones = $loyalty['milestones'];
@endphp
<section id="benefits" class="card-surface w-full max-w-3xl p-3 sm:p-6" aria-labelledby="benefits-title">
    <div class="mb-4 flex items-center gap-3">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-peach-soft text-lg text-peach-red">
            <i class="bi bi-gift"></i>
        </span>
        <div class="min-w-0">
            <h2 id="benefits-title" class="font-display text-lg font-bold text-peach-deep">Account benefits</h2>
            <p class="text-sm text-peach-deep/60">What you get for ordering with an account.</p>
        </div>
    </div>

    @unless($loyalty['verified'])
        <p class="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800" data-testid="verify-to-earn">
            <i class="bi bi-envelope-exclamation"></i>
            Verify your email address to start earning bonus points. Use the Resend button under Account Details below.
        </p>
    @endunless

    {{-- Balance --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-2xl bg-peach-soft/60 px-3 py-3">
        <p class="text-sm font-semibold text-peach-deep/80">Bonus points</p>
        <p class="font-display text-xl font-black text-peach-red" data-testid="bonus-points">{{ number_format($loyalty['bonus_points']) }} pts</p>
        <p class="w-full text-sm text-peach-deep/60">
            Bonus points stay on your account and count toward Spin &amp; Win vouchers
            together with the points you spin.
        </p>
    </div>

    <ul class="grid gap-2 sm:grid-cols-2" role="list">
        <li>
            <a href="{{ route('customer.vouchers') }}" class="flex min-h-11 items-start gap-3 rounded-2xl border border-peach-soft bg-white p-3 no-underline transition hover:border-peach">
                <i class="bi bi-ticket-perforated mt-0.5 text-lg text-peach-red"></i>
                <span class="min-w-0">
                    <span class="block text-base font-bold text-peach-deep">Vouchers &amp; Rewards</span>
                    <span class="block text-sm text-peach-deep/70">Vouchers you win and rewards you earn are saved to your account.</span>
                </span>
            </a>
        </li>

        @if($milestones)
        <li class="flex items-start gap-3 rounded-2xl border border-peach-soft bg-white p-3">
            <i class="bi bi-fire mt-0.5 text-lg text-peach-red"></i>
            <span class="min-w-0">
                <span class="block text-base font-bold text-peach-deep">Streak bonuses</span>
                <span class="block text-sm text-peach-deep/70" data-testid="benefit-streak">
                    @foreach($milestones as $days => $points)
                        +{{ $points }} points at {{ $days }} days in a row{{ $loop->last ? '.' : ',' }}
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
        <li>
            <a href="#free-dessert" class="flex min-h-11 items-start gap-3 rounded-2xl border border-peach-soft bg-white p-3 no-underline transition hover:border-peach">
                <i class="bi bi-gift mt-0.5 text-lg text-peach-red"></i>
                <span class="min-w-0">
                    <span class="block text-base font-bold text-peach-deep">Stamp card reward</span>
                    <span class="block text-sm text-peach-deep/70" data-testid="benefit-free-dessert">A reward{{ $benefitReward ? ' (' . $benefitReward . ')' : '' }} for every {{ \App\Services\FreeDesserts::visitDays($benefitBranch->id) }} stamps at {{ $benefitBranch->name }}. 1 stamp per day you completed an order there.</span>
                </span>
            </a>
        </li>
        @endif

        @if($loyalty['welcome_points'] > 0)
        <li class="flex items-start gap-3 rounded-2xl border border-peach-soft bg-white p-3">
            <i class="bi bi-stars mt-0.5 text-lg text-peach-red"></i>
            <span class="min-w-0">
                <span class="block text-base font-bold text-peach-deep">Welcome bonus</span>
                <span class="block text-sm text-peach-deep/70" data-testid="benefit-welcome">
                    @if($loyalty['welcome_paid'])
                        <i class="bi bi-check-circle-fill text-green-700"></i> Received: +{{ $loyalty['welcome_points'] }} points.
                    @elseif(! $loyalty['verified'])
                        +{{ $loyalty['welcome_points'] }} points once you verify your email.
                    @else
                        +{{ $loyalty['welcome_points'] }} points for new accounts when they verify their email.
                    @endif
                </span>
            </span>
        </li>
        @endif

        @if($loyalty['order_points'] > 0)
        <li class="flex items-start gap-3 rounded-2xl border border-peach-soft bg-white p-3">
            <i class="bi bi-bag-check mt-0.5 text-lg text-peach-red"></i>
            <span class="min-w-0">
                <span class="block text-base font-bold text-peach-deep">Order bonus</span>
                <span class="block text-sm text-peach-deep/70" data-testid="benefit-order">+{{ $loyalty['order_points'] }} points for every completed order.</span>
            </span>
        </li>
        @endif

        <li class="flex items-start gap-3 rounded-2xl border border-peach-soft bg-white p-3">
            <i class="bi bi-dice-5 mt-0.5 text-lg text-peach-red"></i>
            <span class="min-w-0">
                <span class="block text-base font-bold text-peach-deep">Spin &amp; Win</span>
                <span class="block text-sm text-peach-deep/70" data-testid="benefit-spins">
                    {{ $loyalty['spins_per_order'] }} spins with every order, up to {{ $loyalty['spins_per_day'] }} a day.
                </span>
            </span>
        </li>

        <li>
            <a href="{{ route('customer.orders', ['tab' => 'history']) }}" class="flex min-h-11 items-start gap-3 rounded-2xl border border-peach-soft bg-white p-3 no-underline transition hover:border-peach">
                <i class="bi bi-receipt mt-0.5 text-lg text-peach-red"></i>
                <span class="min-w-0">
                    <span class="block text-base font-bold text-peach-deep">Order history</span>
                    <span class="block text-sm text-peach-deep/70">Every past order and receipt, on any device.</span>
                </span>
            </a>
        </li>
    </ul>

    @if($recentAwards->isNotEmpty())
        <div class="mt-4">
            <h3 class="mb-2 text-base font-bold text-peach-deep">Recent bonuses</h3>
            <ul class="divide-y divide-peach-soft rounded-2xl border border-peach-soft bg-white" role="list" data-testid="recent-bonuses">
                @foreach($recentAwards as $award)
                    <li class="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
                        <span class="min-w-0 text-peach-deep">
                            {{ $award->label() }}
                            <span class="block text-peach-deep/60">{{ \App\Support\ShopDay::format($award->created_at, 'M j, Y') }}</span>
                        </span>
                        <span class="shrink-0 font-bold text-green-700">+{{ $award->points }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>

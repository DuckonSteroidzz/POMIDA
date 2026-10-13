<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vouchers &amp; Rewards - Peachy</title>

    @include('partials.icon-stability')
    <link href="/vendor/bootstrap-icons.css" rel="stylesheet">
    <link href="/vendor/gfonts.css" rel="stylesheet">
    @include('partials.typography-stability')
    @include('customer.partials.click-sound')

    @vite(['resources/css/app.css'])
    <style>
        @layer base {
            html { -webkit-text-size-adjust: 100%; }
            body {
                font-family: var(--font-body);
                background:
                linear-gradient(
                    135deg,
                    #F8D7B0 0%,
                    #F6B49B 50%,
                    #EF8585 100%
                );
                color: #3b2320;
            }
            h1, h2, h3, .font-display { font-family: var(--font-display); }
            select, input, button, a { font-family: inherit; }
            button:not(:disabled), [onclick] { cursor: pointer; }
        }
    </style>
    <style>
        /* Warm bakery paper backdrop */
        .paper-bg {
            background-image:
                radial-gradient(circle at 12% 8%, rgba(248, 215, 176, 0.55), transparent 42%),
                radial-gradient(circle at 88% 0%, rgba(246, 180, 155, 0.4), transparent 38%);
        }

        /* Ticket perforation: dashed line with punched side notches */
        .perf-line {
            position: relative;
            height: 1px;
            background: transparent;
            border-top: 2px dashed #FDE8DE;
        }
        .perf-line::before,
        .perf-line::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 18px;
            height: 18px;
            border-radius: 999px;
            background: var(--color-peach-cream);
            transform: translateY(-50%);
        }
        .perf-line::before { left: -9px; }
        .perf-line::after  { right: -9px; }

        /* Dotted leader for value lines */
        .leader {
            flex: 1;
            border-bottom: 1px dotted rgba(139, 26, 26, 0.22);
            transform: translateY(-3px);
        }

        /* Entrance animation — subtle, no layout shift */
        @keyframes riseIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: none; }
        }
        .rise { animation: riseIn 0.5s cubic-bezier(0.22, 0.9, 0.3, 1) both; }
        .rise-1 { animation-delay: 0.05s; }
        .rise-2 { animation-delay: 0.12s; }
        .rise-3 { animation-delay: 0.2s; }

        @media (prefers-reduced-motion: reduce) {
            .rise { animation: none; }
        }

        /* Soft pulse for the "valid now" accent */
        @keyframes glowPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(192, 57, 43, 0.0); }
            50% { box-shadow: 0 0 0 6px rgba(192, 57, 43, 0.10); }
        }
        .valid-glow { animation: glowPulse 2.4s ease-in-out infinite; }

        @media (prefers-reduced-motion: reduce) {
            .valid-glow { animation: none; }
        }
    </style>
    @include('partials.session-guard')

    @include('customer.partials.readable-text')

</head>

<body class="min-h-screen bg-peach-cream">

    {{-- ================= HEADER ================= --}}
    <header class="sticky top-0 z-40 border-b border-peach-soft bg-peach-cream/95 backdrop-blur">
        <div class="mx-auto w-full max-w-6xl px-4 sm:px-6">
            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 py-3 sm:py-4">

                <div class="flex min-w-0 items-center gap-2">
                    @include('customer.partials.header-back', ['href' => route('customer.more'), 'label' => 'More'])
                    <a href="{{ route('customer.menu') }}" class="flex min-w-0 items-center gap-3 no-underline">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-peach-soft text-xl sm:h-12 sm:w-12 sm:text-2xl">🍑</span>
    
                        <span class="min-w-0">
                            <span class="block truncate font-display text-xl font-black tracking-tight text-peach-deep sm:text-2xl">Peachy</span>
                            <span class="block truncate text-[0.8125rem] uppercase tracking-[0.18em] text-peach-red/70 sm:text-[0.875rem]">Cakes &amp; Deli Cafe</span>
                        </span>
                    </a>
                </div>

                @include('customer.partials.desktop-nav')
            </div>
        </div>
    </header>

    {{-- ================= VOUCHERS ================= --}}
    <main class="paper-bg mx-auto w-full max-w-6xl px-4 pb-28 pt-5 sm:px-6 sm:pt-8 md:pb-16">

        {{-- Page title --}}
        <div class="rise rise-1 mb-5 grid gap-1 sm:mb-6">
            <h1 class="font-display text-2xl font-black leading-tight tracking-tight text-peach-deep sm:text-3xl">
                Vouchers &amp; Rewards
            </h1>
            <p class="mt-1 text-sm text-peach-deep/55">
                Every slice of savings, kept right here. Tap a code to copy it at checkout.
            </p>
        </div>

        @if(session('error'))
        <div class="mb-4 flex items-start gap-2 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700" role="alert">
            <i class="bi bi-exclamation-circle mt-0.5 shrink-0" aria-hidden="true"></i>
            <span>{{ session('error') }}</span>
        </div>
        @endif

        {{-- Points hint --}}
        <div class="rise rise-2 mb-5 overflow-hidden rounded-2xl border border-peach-soft bg-white p-4 shadow-[0_1px_2px_rgb(139_26_26_/_0.04),0_8px_24px_-18px_rgb(139_26_26_/_0.35)] sm:p-5">
            <div class="flex flex-wrap items-center gap-4">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-white/85 text-2xl shadow-sm">
                    <i class="bi bi-star-fill text-peach-red"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-display text-lg font-black tracking-tight text-peach-deep sm:text-xl">
                        You have <span class="text-peach-red">{{ Auth::guard('customer')->user()?->wheelPoints() ?? 0 }}</span> pts
                    </p>
                    <p class="text-xs font-medium text-peach-deep/60">Spin the wheel to earn more vouchers — luck loves a sweet tooth.</p>
                </div>
                <a href="{{ route('customer.game') }}" class="inline-flex min-h-11 items-center gap-2 rounded-full bg-peach-red px-4 py-2.5 text-sm font-bold text-white no-underline transition hover:bg-peach-deep">
                    <i class="bi bi-dice-5"></i> Spin
                </a>
            </div>
        </div>

        {{-- ================= REWARDS (Oct 2026) =================
             Every stamp card reward with its own code, FROM EVERY BRANCH
             (App\Services\FreeDesserts::rewardsFor), each labeled with the
             branch that issued it — the only branch it can be used at:
             ready ones first, with Copy and "Use now"; then any on an open
             order; then used and expired. Listed even while the owner's
             switch is off (only the usable ones then), because a reward
             already earned can always be used. "Use now" posts
             customer.rewards.use, which hands the code to the cart's code
             box — the same path as typing it — and is offered only when the
             customer is ordering from the reward's branch ($rewardBranch);
             otherwise the card says where to use it. The "You earned a
             reward!" pop-up lands on #rewards. --}}
        @php $loyaltyRewards = $loyaltyRewards ?? collect(); @endphp
        @if($loyaltyRewards->isNotEmpty() || ($freeDessert['enabled'] ?? false))
        <section id="rewards" class="rise rise-2 mb-6 scroll-mt-24" aria-labelledby="rewardsTitle" data-testid="loyalty-rewards">
            <h2 id="rewardsTitle" class="mb-3 font-display text-xl font-black tracking-tight text-peach-deep">My rewards</h2>

            @if($loyaltyRewards->isEmpty())
                <p class="card-surface p-4 text-sm font-medium text-peach-deep/70" data-testid="loyalty-rewards-empty">
                    No rewards yet. Fill your stamp card below to earn one.
                </p>
            @else
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" role="list">
                    @foreach($loyaltyRewards as $reward)
                        @php
                            $rewardKey = $reward->statusKey();
                            $rewardReady = $rewardKey === \App\Models\FreeDessertReward::KEY_READY;
                            $rewardUsableHere = $reward->branchErrorFor(isset($rewardBranch) ? $rewardBranch?->id : null) === null;
                            $rewardBadge = match ($rewardKey) {
                                \App\Models\FreeDessertReward::KEY_READY    => 'bg-peach-red text-white',
                                \App\Models\FreeDessertReward::KEY_ON_ORDER => 'bg-amber-100 text-amber-800',
                                \App\Models\FreeDessertReward::KEY_NOT_SET  => 'bg-peach-sand text-peach-deep/70',
                                default                                     => 'bg-peach-deep/10 text-peach-deep/60',
                            };
                        @endphp
                        <li class="flex flex-col overflow-hidden rounded-[1.25rem] bg-white shadow-[0_1px_2px_rgb(139_26_26_/_0.04),0_18px_40px_-26px_rgb(139_26_26_/_0.40)] {{ $rewardReady ? 'ring-1 ring-peach-red/30' : 'opacity-80' }}"
                            data-testid="loyalty-reward" data-reward-status="{{ $rewardKey }}">
                            <div class="h-1.5 w-full {{ $rewardReady ? 'bg-gradient-to-r from-peach-red via-peach-blush to-peach' : 'bg-peach-deep/15' }}"></div>

                            <div class="flex flex-1 flex-col gap-2 p-4 sm:p-5">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl text-xl {{ $rewardReady ? 'bg-gradient-to-br from-peach-red to-peach-blush text-white' : 'bg-peach-soft text-peach-red' }}" aria-hidden="true">
                                            <i class="bi {{ $reward->isVoucherReward() ? 'bi-ticket-perforated-fill' : 'bi-gift-fill' }}"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-xs font-black uppercase tracking-[0.14em] text-peach-red/70">Loyalty reward</p>
                                            <p class="font-display text-lg font-black leading-snug text-peach-deep" data-testid="loyalty-reward-what">{{ $reward->description() }}</p>
                                        </div>
                                    </div>
                                    <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-black uppercase tracking-wider {{ $rewardBadge }}" data-testid="loyalty-reward-status">
                                        {{ $reward->statusLabel() }}
                                    </span>
                                </div>

                                <p class="inline-flex items-center gap-1.5 text-sm font-bold text-peach-deep/80" data-testid="loyalty-reward-branch">
                                    <i class="bi bi-shop text-peach-red" aria-hidden="true"></i>
                                    {{ $reward->branch_id === null ? 'Any branch' : 'Only at ' . $reward->branchName() }}
                                </p>

                                <p class="text-sm text-peach-deep/70" data-testid="loyalty-reward-expiry">
                                    @switch($rewardKey)
                                        @case(\App\Models\FreeDessertReward::KEY_READY)
                                            @if($reward->expires_at)
                                                Use by {{ \App\Support\ShopDay::format($reward->expires_at, 'M j, Y') }}.
                                            @else
                                                It does not expire.
                                            @endif
                                            @break
                                        @case(\App\Models\FreeDessertReward::KEY_ON_ORDER)
                                            On order {{ $reward->order?->order_number }}. If that order is cancelled, the reward comes back with the same code.
                                            @break
                                        @case(\App\Models\FreeDessertReward::KEY_USED)
                                            Used{{ $reward->used_at ? ' on ' . \App\Support\ShopDay::format($reward->used_at, 'M j, Y') : '' }}{{ $reward->order ? ', order ' . $reward->order->order_number : '' }}.
                                            @break
                                        @case(\App\Models\FreeDessertReward::KEY_EXPIRED)
                                            Expired {{ $reward->expires_at ? \App\Support\ShopDay::format($reward->expires_at, 'M j, Y') : '' }}.
                                            @break
                                        @default
                                            We are still setting this reward up. It will be ready to use soon.
                                    @endswitch
                                </p>
                            </div>

                            <div class="px-5"><div class="perf-line"></div></div>

                            <div class="grid gap-3 bg-peach-cream/70 px-4 py-4 sm:px-5">
                                <div class="min-w-0">
                                    <p class="text-xs font-black uppercase tracking-[0.18em] text-peach-deep/40">Your reward code</p>
                                    <p class="font-display text-2xl font-black tracking-[0.12em] [overflow-wrap:anywhere] {{ $rewardReady ? 'text-peach-red' : 'text-peach-deep/40 line-through' }}" data-testid="loyalty-reward-code">{{ $reward->displayCode() }}</p>
                                    @if($rewardReady)
                                        <p class="mt-0.5 text-xs font-semibold text-peach-deep/55">Works once, on your account only.</p>
                                    @endif
                                </div>

                                @if($rewardReady)
                                    <div class="grid grid-cols-2 gap-2">
                                        <button type="button" class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-full border border-peach-soft bg-white px-3 py-2 text-sm font-bold text-peach-red transition hover:bg-peach-soft active:scale-95"
                                            onclick="copyVoucher(@js($reward->displayCode()), this)">
                                            <i class="bi bi-clipboard" aria-hidden="true"></i> Copy
                                        </button>
                                        @if($rewardUsableHere)
                                        <form method="POST" action="{{ route('customer.rewards.use', $reward->id) }}" class="contents">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center gap-1.5 rounded-full bg-peach-red px-3 py-2 text-sm font-bold text-white transition hover:bg-peach-deep active:scale-95" data-testid="loyalty-reward-use">
                                                <i class="bi bi-bag-check" aria-hidden="true"></i> Use now
                                            </button>
                                        </form>
                                        @else
                                        <p class="flex min-h-11 items-center justify-center rounded-full bg-peach-soft/70 px-3 py-2 text-center text-xs font-bold text-peach-deep/75" data-testid="loyalty-reward-elsewhere">
                                            Order from {{ $reward->branchName() }} to use it
                                        </p>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-peach-deep/60">Tap <strong>Use now</strong>, or type the code in the voucher box at checkout. One reward per order.</p>
            @endif
        </section>
        @endif

        {{-- The stamp card: the same partial as the Account page. --}}
        @if($freeDessert['enabled'] ?? false)
        <div class="rise rise-2 mb-5">
            @include('customer.partials.free-dessert-stamp-card', ['rewardListHere' => true])
        </div>
        @endif

        <h2 class="mb-3 font-display text-xl font-black tracking-tight text-peach-deep">My vouchers</h2>

        @if(isset($userVouchers) && count($userVouchers) > 0)
        {{-- Voucher grid --}}
        <div class="grid gap-4 sm:grid-cols-2 sm:gap-5 lg:grid-cols-3">
            @foreach($userVouchers as $uv)
            @php
            $voucher = $uv->voucher;

            /*
             * Ask the server what it will actually accept, rather than
             * re-deriving it here from the wrong columns.
             *
             * This block used to compute "valid now" from $voucher->valid_from —
             * the SHARED column — while the cart and checkout read the
             * customer's own $uv->valid_from (item 41). It also ignored
             * is_active, expires_at and max_uses. The result was a card with a
             * green "Valid now" badge and a working Copy button for a voucher
             * the server would always refuse: the reported "sits in my account
             * and can never be used".
             */
            $status = $uv->statusKey();
            $blockedBecause = $uv->blockingReason();

            $isUsed = $status === 'used';
            $isExpired = $status === 'expired';
            $isReady = $status === 'ready';
            // Kept for the existing styling below, which distinguishes only
            // "usable" from "not usable yet".
            $isValidNow = $isReady;

            $cardClass = $isUsed ? 'used' : ($isReady ? 'valid-now' : 'not-yet-valid');
            $iconClass = $isUsed ? 'used-icon' : ($isReady ? 'valid' : 'pending');
            $iconEmoji = $isUsed ? '✓' : ($isReady ? '🎟️' : '⏳');

            $discountText = $voucher->discount_type === 'percent'
            ? $voucher->discount_value . '% OFF'
            : '₱' . number_format($voucher->discount_value, 2) . ' OFF';
            @endphp

            <article class="rise rise-3 group relative flex flex-col overflow-hidden rounded-[1.25rem] bg-white shadow-[0_1px_2px_rgb(139_26_26_/_0.04),0_18px_40px_-26px_rgb(139_26_26_/_0.40)] transition duration-300 hover:-translate-y-1 hover:shadow-[0_1px_2px_rgb(139_26_26_/_0.04),0_28px_55px_-24px_rgb(139_26_26_/_0.50)]
                {{ $isUsed ? 'opacity-70 grayscale-[0.35]' : '' }}
                {{ $isValidNow && !$isExpired ? 'valid-glow ring-1 ring-peach-red/30' : '' }}
                {{ (!$isValidNow && !$isUsed) ? 'ring-1 ring-peach/40' : '' }}">

                {{-- Colored top accent strip by status --}}
                <div class="h-1.5 w-full
                    {{ $isUsed ? 'bg-peach-deep/20' : ($isValidNow && !$isExpired ? 'bg-gradient-to-r from-peach-red via-peach-blush to-peach' : 'bg-gradient-to-r from-peach-sand via-peach-rose to-peach') }}"></div>

                {{-- Status badge. One badge per state, all five of them named by
                     UserVoucher::statusKey() so the wording cannot drift from
                     what the server will actually do. --}}
                @php
                    $badge = match($status) {
                        'used'          => ['bi-check2-all', 'Used', 'bg-peach-deep/10 text-peach-deep/55'],
                        'expired'       => ['bi-clock-history', 'Expired', 'bg-peach-deep/10 text-peach-deep/55'],
                        'ready'         => ['bi-lightning-charge-fill', 'Valid now', 'bg-peach-red text-white shadow-sm'],
                        'not_yet_valid' => ['bi-hourglass-split', 'Soon', 'bg-peach-sand text-peach-deep/70'],
                        default         => ['bi-slash-circle', 'Unavailable', 'bg-peach-deep/10 text-peach-deep/55'],
                    };
                @endphp
                <span class="absolute right-3 top-4 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[0.8125rem] font-black uppercase tracking-wider {{ $badge[2] }}">
                    <i class="bi {{ $badge[0] }}"></i> {{ $badge[1] }}
                </span>

                {{-- Body --}}
                <div class="flex flex-1 flex-col gap-3 p-5 pt-5">
                    <div class="flex items-center gap-3.5">
                        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl text-xl shadow-sm
                            {{ $isUsed ? 'bg-peach-deep/5 text-peach-deep/40' : ($isValidNow && !$isExpired ? 'bg-gradient-to-br from-peach-red to-peach-blush text-white' : 'bg-peach-soft text-peach-red') }}">
                            {{ $iconEmoji }}
                        </span>
                        <div class="min-w-0">
                            <p class="font-display text-2xl font-black leading-none tracking-tight text-peach-deep sm:text-[1.875rem]">{{ $discountText }}</p>
                            <p class="mt-1 text-[0.8125rem] font-bold uppercase tracking-[0.14em] text-peach-red/70">Peachy Voucher</p>
                        </div>
                    </div>

                    <p class="text-sm font-medium leading-snug text-peach-deep/70">{{ $voucher->description ?? 'Peachy Voucher' }}</p>

                    {{-- Meta lines with dotted leaders --}}
                    <div class="mt-auto space-y-2 pt-1 text-[0.875rem]">
                        <div class="flex items-baseline gap-2">
                            <span class="font-bold text-peach-deep/45">Min. order</span>
                            <span class="leader"></span>
                            <span class="font-bold text-peach-deep">₱{{ number_format($voucher->minimum_order, 2) }}</span>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span class="font-bold text-peach-deep/45">Valid</span>
                            <span class="leader"></span>
                            <span class="text-right font-semibold text-peach-deep/80">
                                {{-- THIS claim's window, not the shared voucher
                                     column: the two are different values, and
                                     the server reads this one. --}}
                                {{-- Year included on both ends. It used to read
                                     "Sep 02 – Sep 01, 2027", where the opening
                                     date looked like it belonged to the same
                                     year as the expiry. --}}
                                @if($uv->valid_from)
                                {{ $uv->valid_from->format('M d, Y') }}
                                @else
                                Now
                                @endif
                                @if($voucher->expires_at)
                                – {{ \App\Support\ShopDay::formatExpiry($voucher->expires_at) }}
                                @else
                                – <span class="text-green-600">No expiry</span>
                                @endif
                            </span>
                        </div>
                    </div>

                    {{-- The real reason, whenever the server will not take this
                         voucher. Without it the card said "Soon" or nothing at
                         all, and the refusal only surfaced at the cart. --}}
                    @if($blockedBecause && !$isUsed)
                    <p class="mt-1 flex items-start gap-1.5 rounded-xl bg-peach-soft/60 px-3 py-2 text-[0.875rem] font-semibold leading-snug text-peach-deep/70">
                        <i class="bi bi-info-circle mt-0.5 shrink-0"></i>
                        <span>{{ $blockedBecause }}</span>
                    </p>
                    @endif
                </div>

                {{-- Perforation --}}
                <div class="px-5"><div class="perf-line"></div></div>

                {{-- Code bar --}}
                @php
                    /*
                     * Show the CLAIM code when this claim has one, not the
                     * shared voucher code.
                     *
                     * Since 2026-09-01 a claim is a bearer instrument: whoever
                     * holds its code may spend it, once. That is what lets the
                     * winner hand a prize to a Dine-In customer, who has no
                     * account and for whom the shared code would be refused
                     * outright.
                     *
                     * Older account claims won before that change have no
                     * claim_code, so they fall back to the shared code, which
                     * still works for the signed-in owner.
                     */
                    $displayCode = $uv->claim_code
                        ? \App\Services\VoucherClaims::display($uv->claim_code)
                        : $voucher->code;
                @endphp
                <div class="flex items-center justify-between gap-3 bg-peach-cream/70 px-5 py-4">
                    <div class="min-w-0">
                        <p class="text-[0.8125rem] font-black uppercase tracking-[0.18em] text-peach-deep/40">
                            {{ $uv->claim_code ? 'Your claim code' : 'Code' }}
                        </p>
                        <span class="block truncate font-display text-base font-black tracking-[0.18em] text-peach-red
                            {{ $isUsed ? 'text-peach-deep/40 line-through' : '' }}">
                            {{ $displayCode }}
                        </span>
                        @if($uv->claim_code && !$isUsed)
                        <p class="mt-0.5 text-xs font-semibold leading-snug text-peach-deep/45">
                            Anyone can use this code once — you can pass it on.
                        </p>
                        @endif
                    </div>
                    {{-- Copy is offered only when the server would actually
                         accept the code right now. --}}
                    @if($isReady)
                    <button class="inline-flex min-h-11 shrink-0 cursor-pointer items-center gap-1.5 rounded-full bg-peach-red px-4 py-2 text-sm font-bold text-white no-underline transition hover:bg-peach-deep active:scale-95"
                        onclick="copyVoucher('{{ $displayCode }}', this)">
                        <i class="bi bi-clipboard"></i> Copy
                    </button>
                    @else
                    <button class="inline-flex shrink-0 cursor-not-allowed items-center gap-1.5 rounded-full bg-peach-deep/10 px-3.5 py-2 text-xs font-bold text-peach-deep/45" disabled>
                        <i class="bi bi-slash-circle"></i> {{ $uv->statusLabel() }}
                    </button>
                    @endif
                </div>
            </article>
            @endforeach
        </div>
        @else
        {{-- Empty state --}}
        <div class="rise rise-3 overflow-hidden rounded-[1.5rem] border border-peach-soft bg-white p-10 text-center shadow-[0_1px_2px_rgb(139_26_26_/_0.04),0_18px_40px_-26px_rgb(139_26_26_/_0.40)] sm:p-14">
            <span class="mx-auto grid h-20 w-20 place-items-center rounded-full bg-peach-soft text-4xl">
                <i class="bi bi-ticket-perforated text-peach-red"></i>
            </span>
            <h2 class="mt-4 font-display text-2xl font-black tracking-tight text-peach-deep">No vouchers yet</h2>
            <p class="mx-auto mt-2 max-w-sm text-sm font-medium text-peach-deep/55">Your wallet is empty for now. Give the wheel a spin — every turn is a chance to bake up a sweet deal.</p>
            <a href="{{ route('customer.game') }}" class="mt-6 inline-flex items-center gap-2 rounded-full bg-peach-red px-6 py-3 text-sm font-bold text-white no-underline transition hover:bg-peach-deep">
                <i class="bi bi-dice-5"></i> Go to Spin Wheel
            </a>
        </div>
        @endif
    </main>

    {{-- ================= BOTTOM NAV (mobile) =================
         The shared bar, so the tabs keep the same order on every page
         (More is lit: Vouchers & Rewards lives under More). --}}
    @include('customer.partials.navbar')

    <script>
        function copyVoucher(code, btn) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(code).then(function() {
                    btn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
                    btn.classList.add('bg-green-600');
                    setTimeout(function() {
                        btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
                        btn.classList.remove('bg-green-600');
                    }, 2000);
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = code;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                btn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
                btn.classList.add('bg-green-600');
                setTimeout(function() {
                    btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
                    btn.classList.remove('bg-green-600');
                }, 2000);
            }
        }
    </script>

    @include('customer.partials.idle-timeout')
</body>

</html>

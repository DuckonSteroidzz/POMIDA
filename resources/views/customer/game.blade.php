<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spin & Win - Peachy</title>

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
                background: linear-gradient(135deg,#F8D7B0 0%,#F6B49B 50%,#EF8585 100%);
                color: #3b2320;
            }

            h1, h2, h3, .font-display {
                font-family: var(--font-display);
            }

            button, input, select, a {
                font-family: inherit;
            }

            button:not(:disabled), [onclick] { cursor: pointer; }
        }
    </style>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
        }

        .game-shell,
        .points-card {
            background: #fff;
            border: 1px solid #FDE8DE;
            border-radius: 1rem;
            box-shadow:
                0 1px 2px rgba(139,26,26,.04),
                0 8px 24px -18px rgba(139,26,26,.35);
        }

        .game-shell {
            width: 100%;
            padding: 2rem 1.25rem 2.5rem;
        }

        .game-layout {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2rem;
            width: 100%;
        }

        .game-play {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
        }

        .game-legend {
            width: 100%;
            max-width: 420px;
        }

        .legend-title {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.05rem;
            font-weight: 800;
            color: #8B1A1A;
            margin: 0 0 .85rem;
            text-align: center;
        }

        .legend-list {
            display: flex;
            flex-direction: column;
            gap: .6rem;
        }

        .legend-item {
            display: flex;
            align-items: flex-start;
            gap: .65rem;
            background: #FFFBF7;
            border: 1px solid #FDE8DE;
            border-radius: 12px;
            padding: .65rem .8rem;
        }

        .legend-badge {
            flex-shrink: 0;
            background: linear-gradient(135deg,#F4845F,#C0392B);
            color: #fff;
            font-size: .68rem;
            font-weight: 800;
            padding: .3rem .55rem;
            border-radius: 999px;
            white-space: nowrap;
        }

        .legend-badge.voucher {
            background: linear-gradient(135deg,#2E7D5B,#1f5c40);
        }

        /* Timing badge on the voucher-win modal — states plainly when a
           wheel-won voucher actually becomes usable, since AuthController
           always mints these with valid_from = tomorrow (see
           AuthController::addPoints / VoucherClaims::mintForGuest), never
           usable on the order that won it. */
        .voucher-timing-badge {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            background: linear-gradient(135deg,#2E7D5B,#1f5c40);
            color: #fff;
            font-size: .72rem;
            font-weight: 800;
            padding: .35rem .75rem;
            border-radius: 999px;
            margin: 0 0 .65rem;
        }

        /* Confetti overlay for a spin win. Fixed, full-viewport, and inert to
           clicks so it never steals a tap meant for the modal underneath. */
        #confettiCanvas {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 9999;
        }

        .legend-text {
            font-size: .8rem;
            color: #5b4740;
            line-height: 1.35;
        }

        .legend-text strong {
            color: #8B1A1A;
        }

        .legend-empty {
            font-size: .8rem;
            color: #8A6A61;
            text-align: center;
            padding: .5rem 0 0;
        }

        @media (min-width: 900px) {
            .game-layout {
                flex-direction: row;
                align-items: flex-start;
                justify-content: center;
                gap: 3rem;
            }

            .game-play {
                width: auto;
                flex-shrink: 0;
            }

            .game-legend {
                margin-top: .25rem;
            }

            .legend-title {
                text-align: left;
            }
        }

        .game-title {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.65rem;
            font-weight: 900;
            color: #8B1A1A;
            margin: 0 0 .25rem;
            text-align: center;
        }

        .game-subtitle {
            font-size: .82rem;
            color: rgba(139,26,26,.5);
            margin: 0 0 1.5rem;
            text-align: center;
        }

        .wheel-container {
            position: relative;
            width: 280px;
            height: 280px;
            margin-bottom: 1.25rem;
        }

        canvas {
            border-radius: 50%;
            box-shadow: 0 10px 30px rgba(192,57,43,.2);
        }

        .wheel-pointer {
            position: absolute;
            top: -18px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 2rem;
            z-index: 10;
            color: #8B1A1A;
            filter: drop-shadow(0 2px 4px rgba(59,35,32,.2));
        }

        .spin-meter {
            margin: 0 auto .75rem;
            max-width: 20rem;
            border-radius: 1rem;
            border: 1px solid rgba(244,132,95,.28);
            background: rgba(244,132,95,.08);
            padding: .55rem .9rem;
            text-align: center;
        }

        .spin-meter.is-empty {
            border-color: rgba(139,26,26,.22);
            background: rgba(139,26,26,.06);
        }

        .spin-meter-count {
            margin: 0;
            font-size: .95rem;
            font-weight: 800;
            color: #C0392B;
        }

        .spin-meter-note {
            margin: .15rem 0 0;
            font-size: .74rem;
            font-weight: 500;
            line-height: 1.35;
            color: rgba(59,35,32,.62);
        }

        .spin-cta {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            border-radius: 999px;
            border: 1px solid rgba(244,132,95,.5);
            padding: .5rem 1.4rem;
            font-size: .82rem;
            font-weight: 700;
            color: #C0392B;
            text-decoration: none;
            margin-bottom: .75rem;
        }

        .spin-cta:hover { background: rgba(244,132,95,.1); }

        .spin-btn {
            background: linear-gradient(135deg,#F4845F,#C0392B);
            color: #fff;
            border: 0;
            border-radius: 999px;
            padding: .85rem 3rem;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 8px 20px -10px rgba(139,26,26,.7);
            transition: transform .2s;
            margin-bottom: .75rem;
        }

        .spin-btn:hover { transform: translateY(-1px); }

        .spin-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .skip-btn {
            background: none;
            border: 0;
            color: rgba(139,26,26,.5);
            font-size: .78rem;
            cursor: pointer;
            text-decoration: underline;
            margin-bottom: .5rem;
        }

        .result-box {
            display: none;
            background: linear-gradient(135deg,#fff9f6,#FDE8DE);
            border: 1px solid #F4845F;
            border-radius: 16px;
            padding: 1.25rem;
            text-align: center;
            width: min(100%,520px);
            margin-top: .5rem;
        }

        .result-box.win {
            border-color: #2E7D5B;
            background: #E4F2EA;
        }

        .result-box.lose {
            border-color: #ead8d2;
            background: #fff;
        }

        .result-emoji {
            font-size: 2.5rem;
            display: block;
            margin-bottom: .5rem;
        }

        .result-title {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.1rem;
            font-weight: 900;
            color: #8B1A1A;
            margin: 0 0 .25rem;
        }

        .result-code {
            font-size: 1.4rem;
            font-weight: 900;
            color: #C0392B;
            letter-spacing: 2px;
            background: #fff;
            border: 2px dashed #F4845F;
            border-radius: 8px;
            padding: .5rem 1rem;
            display: inline-block;
            margin: .5rem 0;
        }

        /*
           The guest "keep this code" block.

           A guest has no account for the prize to live in, so this code is the
           only thing that will redeem it later — losing it loses the voucher.
           It therefore gets its own framed panel rather than sitting inline as
           one more line of small print. Plain CSS, like the toast styles, so it
           cannot depend on the in-browser Tailwind build noticing markup that
           JS injected after load.
        */
        .claim-keep {
            margin: .6rem 0 .35rem;
            padding: .75rem .8rem .8rem;
            border: 2px solid #F4845F;
            border-radius: 12px;
            background: #FFF7F3;
            text-align: center;
        }

        .claim-keep-label {
            margin: 0;
            font-size: .68rem;
            font-weight: 900;
            letter-spacing: .09em;
            text-transform: uppercase;
            color: #C0392B;
        }

        .claim-keep .result-code {
            margin: .45rem 0 .35rem;
            font-size: 1.25rem;
            letter-spacing: 3px;
            word-break: break-all;
        }

        .claim-keep-note {
            margin: 0;
            font-size: .68rem;
            line-height: 1.45;
            color: #8A6A61;
        }

        .result-desc {
            font-size: .78rem;
            color: #666;
        }

        .copy-btn {
            background: #F4845F;
            color: #fff;
            border: 0;
            border-radius: 999px;
            padding: .45rem 1rem;
            font-size: .78rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: .5rem;
        }

        .disabled-game {
            text-align: center;
            padding: 3.5rem 1rem;
            color: #8A6A61;
        }

        .disabled-game i {
            font-size: 3rem;
            display: block;
            margin-bottom: .75rem;
            color: #F4845F;
        }

        @media (min-width: 768px) {
            .game-section {
                padding: 2.25rem 2rem 3rem;
            }

            .game-title {
                font-size: 2rem;
            }
        }
    </style>
    @include('partials.session-guard')

    @include('customer.partials.readable-text')

</head>

<body class="min-h-screen bg-peach-cream">

    <header class="sticky top-0 z-40 border-b border-peach-soft bg-peach-cream/95 backdrop-blur">
        <div class="mx-auto w-full max-w-6xl px-4 sm:px-6">
            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 py-3 sm:py-4">

                <a href="{{ route('customer.menu') }}" class="flex min-w-0 items-center gap-3 no-underline">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-peach-soft text-xl sm:h-12 sm:w-12 sm:text-2xl">🍑</span>

                    <span class="min-w-0">
                        <span class="block truncate font-display text-xl font-black tracking-tight text-peach-deep sm:text-2xl">Peachy</span>
                        <span class="block truncate text-[0.68rem] uppercase tracking-[0.18em] text-peach-red/70 sm:text-[0.72rem]">Cakes &amp; Deli Cafe</span>
                    </span>
                </a>

                @include('customer.partials.desktop-nav')
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-6xl px-4 pb-24 pt-4 sm:px-6 sm:pt-6 md:pb-16">

        @if(session('order_placed'))
        <div class="mb-4 rounded-2xl border border-peach-soft bg-white px-4 py-3 text-center shadow-sm">
            <p class="m-0 text-sm font-bold text-peach-red">🎉 {{ session('order_placed') }}</p>
            <span class="mt-0.5 block text-xs font-medium text-peach-deep/50">Order #{{ session('order_number') }}</span>
        </div>
        @endif

        <div class="mb-5 grid gap-1">
            <h1 class="font-display text-2xl font-black leading-tight tracking-tight text-peach-deep sm:text-3xl">Spin &amp; Win</h1>
            <p class="mt-1 text-sm text-peach-deep/55">Play while you wait and earn points or vouchers.</p>
        </div>

        {{-- ══════════ PRIZES THIS GUEST IS HOLDING ══════════
             A guest has no account and no Vouchers page, so this panel is the
             only place their claim code can be found again after the spin
             result has faded. Losing the code loses the prize, so it is pinned
             to the top of the page rather than left to a transient banner. --}}
        @if(!empty($guestClaims))
        <div class="mb-5 rounded-2xl border-2 border-peach bg-white p-4 shadow-sm">
            <p class="m-0 flex items-center gap-2 text-sm font-black text-peach-red">
                <i class="bi bi-ticket-perforated-fill"></i>
                Your voucher{{ count($guestClaims) === 1 ? '' : 's' }} — save the code
            </p>
            <p class="mt-1 mb-3 text-[0.72rem] leading-snug text-peach-deep/60">
                You are not signed in, so these codes are the only way to use these vouchers.
                Type one into the voucher box in your cart. Each works once.
            </p>

            @foreach($guestClaims as $claim)
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-peach-cream/70 px-3 py-2.5">
                <div class="min-w-0">
                    <span class="block font-display text-base font-black tracking-[0.14em] text-peach-red {{ $claim['is_used'] ? 'line-through opacity-50' : '' }}">
                        {{ $claim['claim_code'] }}
                    </span>
                    <span class="block text-[0.68rem] font-semibold text-peach-deep/60">
                        {{ $claim['description'] ?? 'Peachy voucher' }}
                        @if($claim['is_used'])
                            · already used
                        @elseif($claim['valid_from'])
                            · usable from {{ $claim['valid_from'] }}
                        @endif
                    </span>
                </div>
                @unless($claim['is_used'])
                <button type="button"
                        class="copy-btn shrink-0"
                        data-claim-code="{{ $claim['claim_code'] }}">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
                @endunless
            </div>
            @endforeach
        </div>
        @endif

        {{-- Single points badge. The old page repeated the points figure in a
             star-decorated card here AND again in the wheel result panel; the
             overhaul keeps just this one header badge. --}}
        <div class="points-card mb-5 flex items-center justify-between gap-3 px-4 py-3 sm:px-5">
            <span class="text-sm font-semibold text-peach-deep/55">Your Points</span>
            {{-- $pointsBalance is resolved through the 'customer' guard in
                 AuthController::showGame(). The old inline Auth::check() read
                 the default 'web' guard, so a logged-in customer always saw
                 the guest total (0 pts) instead of their real balance. --}}
            <span class="font-display text-base font-black text-peach-red" id="pointsDisplay">{{ $pointsBalance }} pts</span>
        </div>

        <section class="game-shell">

            {{-- The one global switch row the owner's toggle flips and the spin
                 endpoint enforces — see Setting::gameEnabled(). --}}
            @if(\App\Models\Setting::gameEnabled())
            @php $gameVouchers = $vouchers->where('points_required', '>', 0)->sortBy('points_required'); @endphp
            <div class="game-layout">

                <div class="game-play">
                    <p class="game-title">🎰 Spin & Win!</p>
                    <p class="game-subtitle">Spin for a chance to earn points & win vouchers!</p>

                    <div class="wheel-container">
                        <div class="wheel-pointer">▼</div>
                        <canvas id="wheelCanvas" width="280" height="280"></canvas>
                    </div>

                    {{-- Spins remaining. Rendered server-side so it is already
                         right on first paint and after a refresh, then kept up
                         to date from the spin response. Display only — the
                         server re-checks the cap on every spin regardless of
                         what this says. --}}
                    <div class="spin-meter" id="spinMeter">
                        <p class="spin-meter-count" id="spinCount">
                            @if($spinState['can_spin'])
                                {{ $spinState['spins_remaining'] }}
                                {{ $spinState['spins_remaining'] === 1 ? 'spin' : 'spins' }} remaining
                            @else
                                No spins available
                            @endif
                        </p>
                        <p class="spin-meter-note" id="spinNote">{{ $spinState['message'] }}</p>
                    </div>

                    <button class="spin-btn" id="spinBtn" onclick="spinWheel()" @unless($spinState['can_spin']) disabled @endunless>
                        <i class="bi bi-arrow-repeat"></i> SPIN!
                    </button>

                    @unless($spinState['can_spin'])
                    <a href="{{ route('customer.menu') }}" class="spin-cta">
                        <i class="bi bi-bag-plus"></i>
                        {{ $spinState['blocked'] === 'no_active_order' ? 'Browse the menu' : 'Order again' }}
                    </a>
                    @endunless
                </div>

                <div class="game-legend">
                    <p class="legend-title">🏆 What you can win</p>
                    <div class="legend-list">
                        <div class="legend-item">
                            <span class="legend-badge">Every spin</span>
                            <span class="legend-text"><strong>3, 5, or 8 points</strong> — or Try Again</span>
                        </div>

                        @forelse($gameVouchers as $v)
                        <div class="legend-item">
                            <span class="legend-badge voucher">{{ $v->points_required }} pts</span>
                            <span class="legend-text">
                                <strong>{{ $v->code }}</strong> —
                                {{ $v->discount_type === 'percent' ? number_format($v->discount_value, 0) . '% off' : '₱' . number_format($v->discount_value, 2) . ' off' }}
                                @if($v->description)
                                    <br><span style="color:#8A6A61;">{{ $v->description }}</span>
                                @endif
                            </span>
                        </div>
                        @empty
                        <p class="legend-empty">No bonus vouchers available right now — just spin for points!</p>
                        @endforelse
                    </div>
                </div>

            </div>
            @else
            <div class="disabled-game">
                <i class="bi bi-dice-5"></i>
                <p style="font-size:0.95rem;font-weight:600;color:#555;">Game is currently unavailable</p>
                <p style="font-size:0.78rem;">Check back later!</p>
            </div>
            @endif



        </section>
    </main>

    {{-- Voucher-win modal. When a spin awards a voucher (or a guest redeems
         one), the unique 1-time code is shown here front-and-centre rather
         than only inline in the result panel, so it cannot be scrolled past. --}}
    <div id="voucherWinModal" style="display:none;position:fixed;inset:0;background:rgba(74,59,54,.55);z-index:9998;align-items:center;justify-content:center;padding:1rem;">
        <div style="background:#fff;border-radius:18px;padding:1.5rem 1.25rem;max-width:380px;width:100%;text-align:center;box-shadow:0 20px 50px -20px rgba(139,26,26,.5);">
            <p style="font-family:'Fraunces',Georgia,serif;font-size:1.15rem;font-weight:900;color:#8B1A1A;margin:0 0 .25rem;">🎉 You won a voucher!</p>
            {{-- States plainly when this can actually be used. The server
                 always mints wheel-won vouchers as valid starting the next
                 day (never on the order that won them) — this shows that
                 exact date rather than leaving it to be assumed. --}}
            <span id="voucherWinTiming" class="voucher-timing-badge" style="display:none;"></span>
            <p id="voucherWinDesc" style="font-size:.8rem;color:#8A6A61;margin:0 0 .75rem;"></p>
            <div id="voucherWinCode" style="font-size:1.5rem;font-weight:900;letter-spacing:2px;color:#C0392B;background:#FFF7F3;border:2px dashed #F4845F;border-radius:10px;padding:.6rem .5rem;margin:0 0 .6rem;word-break:break-all;"></div>
            <p id="voucherWinNote" style="font-size:.72rem;color:#8A6A61;margin:0 0 1rem;line-height:1.45;"></p>
            <button type="button" id="voucherWinCopy" class="copy-btn" style="width:100%;margin:0 0 .5rem;"><i class="bi bi-clipboard"></i> Copy Code</button>
            <button type="button" onclick="document.getElementById('voucherWinModal').style.display='none'" style="background:none;border:0;color:#8A6A61;font-size:.78rem;text-decoration:underline;cursor:pointer;">Close</button>
        </div>
    </div>

    {{-- Lightweight centre popup for a points win or a "Try Again". Replaces
         the old persistent green result card that used to sit under the wheel;
         the area under the wheel is now kept clean. Auto-dismisses, and can be
         dismissed by tapping anywhere or the button. --}}
    <div id="spinResultModal" onclick="hideSpinToast()" style="display:none;position:fixed;inset:0;background:rgba(74,59,54,.5);z-index:9997;align-items:center;justify-content:center;padding:1rem;">
        <div style="background:#fff;border-radius:18px;padding:1.4rem 1.25rem;max-width:320px;width:100%;text-align:center;box-shadow:0 20px 50px -20px rgba(139,26,26,.5);">
            <span id="spinResultEmoji" style="font-size:2.4rem;display:block;margin-bottom:.35rem;">⭐</span>
            <p id="spinResultTitle" style="font-family:'Fraunces',Georgia,serif;font-size:1.15rem;font-weight:900;color:#8B1A1A;margin:0 0 .3rem;"></p>
            <p id="spinResultDesc" style="font-size:.82rem;color:#8A6A61;margin:0 0 1rem;line-height:1.45;"></p>
            <button type="button" onclick="hideSpinToast()" style="background:linear-gradient(135deg,#F4845F,#C0392B);color:#fff;border:0;border-radius:999px;padding:.55rem 2rem;font-size:.85rem;font-weight:800;cursor:pointer;">Nice!</button>
        </div>
    </div>

    {{-- Confetti burst on a win. One canvas, reused for every celebration
         instead of spawning DOM nodes per particle. --}}
    <canvas id="confettiCanvas" aria-hidden="true"></canvas>

    {{-- Shared customer navigation --}}
    @include('customer.partials.navbar')

    <script>
        var gameAds = @json($gameAds);

        /* Server-rendered spin state. The browser renders this for the customer;
           it is NEVER what decides whether a spin is allowed. Every spin is
           re-checked against the games_played ledger in
           AuthController::addPoints(), and a spin the server refuses comes back
           422 with the corrected state below. */
        var spinState = @json($spinState);

        function renderSpinState(s) {
            if (!s) return;
            spinState = s;

            var meter = document.getElementById('spinMeter');
            var count = document.getElementById('spinCount');
            var note = document.getElementById('spinNote');
            var btn = document.getElementById('spinBtn');

            if (count) {
                count.textContent = s.can_spin ?
                    s.spins_remaining + (s.spins_remaining === 1 ? ' spin remaining' : ' spins remaining') :
                    'No spins available';
            }
            if (note) note.textContent = s.message || '';
            if (meter) meter.className = s.can_spin ? 'spin-meter' : 'spin-meter is-empty';
            if (btn) btn.disabled = !s.can_spin;
        }

        /* Re-enable the button only when the server still says there are spins
           left. Every place the old code did `spinBtn.disabled = false` went
           through here instead, otherwise finishing the 5th spin would hand the
           button straight back. */
        function releaseSpinButton() {
            var btn = document.getElementById('spinBtn');
            if (btn) btn.disabled = !(spinState && spinState.can_spin);
        }

        /* The segments and their odds live on the server, in
           App\Http\Controllers\Customer\AuthController::WHEEL_SEGMENTS, and
           since hardening pass F3 so does the SPIN ITSELF. This page does not
           choose where the wheel lands. It asks /customer/add-points, which
           picks a segment, credits it and replies with outcome.segment (the
           segment's index in that server list). The page then animates the
           wheel to land on that segment. It sends no prize and has no say in
           the result.

           Each segment is tagged with its server index BEFORE the shuffle
           below, so the page can find the server's segment wherever the
           shuffle happened to draw it. The shuffle is looks only. */
        var segments = @json($wheelSegments).map(function(s, i) {
            s.segment = i;
            return s;
        });

        for (var i = segments.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var temp = segments[i];
            segments[i] = segments[j];
            segments[j] = temp;
        }

        var colors = ['#F4845F', '#C0392B', '#F6B49B', '#8B1A1A', '#EF8585', '#D96B4D', '#E7A38E', '#A52A2A'];
        var canvas = document.getElementById('wheelCanvas');
        var ctx = canvas ? canvas.getContext('2d') : null;
        var numSegments = segments.length;
        var arc = (2 * Math.PI) / numSegments;
        var currentAngle = 0;
        var isSpinning = false;
        var spinCount = 0;

        function drawWheel(angle) {
            if (!ctx) return;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            var cx = canvas.width / 2;
            var cy = canvas.height / 2;
            var radius = cx - 5;

            for (var i = 0; i < segments.length; i++) {
                var sa = angle + i * arc;
                var ea = sa + arc;
                ctx.beginPath();
                ctx.moveTo(cx, cy);
                ctx.arc(cx, cy, radius, sa, ea);
                ctx.closePath();
                ctx.fillStyle = colors[i % colors.length];
                ctx.fill();
                ctx.strokeStyle = 'white';
                ctx.lineWidth = 2;
                ctx.stroke();
                ctx.save();
                ctx.translate(cx, cy);
                ctx.rotate(sa + arc / 2);
                ctx.textAlign = 'right';
                ctx.fillStyle = 'white';
                ctx.font = 'bold 12px Poppins';
                ctx.fillText(segments[i].label, radius - 10, 4);
                ctx.restore();
            }

            ctx.beginPath();
            ctx.arc(cx, cy, 22, 0, 2 * Math.PI);
            ctx.fillStyle = 'white';
            ctx.fill();
            ctx.strokeStyle = '#F4845F';
            ctx.lineWidth = 3;
            ctx.stroke();
            ctx.font = '16px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('🍑', cx, cy + 6);
        }

        window.addEventListener('load', function() {
            setTimeout(function() {
                drawWheel(currentAngle);
            }, 150);
        });

        function spinWheel() {
            if (isSpinning) return;

            // UX gate only. The authoritative refusal is the 422 from
            // /customer/add-points, which is handled just below.
            if (!spinState || !spinState.can_spin) {
                renderSpinState(spinState);
                return;
            }

            isSpinning = true;
            spinCount++;
            document.getElementById('spinBtn').disabled = true;
            hideSpinToast();

            // Create/unlock the celebration AudioContext right on this tap
            // (a real user gesture) so it is ready to play ~4s later when
            // the spin lands, instead of being blocked by autoplay policy.
            if (window.primeCelebrationAudio) window.primeCelebrationAudio();

            /* Ask first, then animate. The server decides the outcome, so
               there is nothing to spin towards until it answers. A refused
               spin (cap reached, order cancelled, game switched off) is
               therefore reported straight away instead of after a 4-second
               spin that won nothing. EVERY spin is sent, "Try Again"
               included, because the server's ledger is what counts spins.
               The body is empty on purpose: the server ignores any prize a
               client names. */
            fetch('{{ route('customer.add-points') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: '{}'
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    /* Server refused this spin — cap reached, or the order
                       finished mid-session. Nothing was awarded, so say so
                       plainly. The reply still carries the corrected counter. */
                    if (data && data.success === false) {
                        isSpinning = false;
                        renderSpinState(data);
                        showSpinToast('🚫', 'Spin not counted',
                            esc(data.message || 'You have no spins left right now.'));
                        releaseSpinButton();
                        return;
                    }

                    var idx = drawnIndexOf(data && data.outcome);

                    if (idx === -1) {
                        /* The spin WAS recorded, but this page's wheel does
                           not match the server's any more (the segments
                           changed after the page loaded). Showing the wrong
                           prize would be worse than showing none. */
                        isSpinning = false;
                        renderSpinState(data);
                        updatePointsDisplay(data);
                        showSpinToast('🔄', 'Spin recorded',
                            'Refresh the page to see your result.');
                        releaseSpinButton();
                        return;
                    }

                    landOn(idx, function() {
                        isSpinning = false;
                        showResult(data, segments[idx]);
                    });
                })
                .catch(function() {
                    /* Network/parse failure — we do NOT know whether the spin
                       was recorded, so claim nothing was won and let the next
                       page load resync the counter from the server. */
                    isSpinning = false;
                    showSpinToast('⚠️', 'Connection problem',
                        'Could not reach the server. Refresh to see your spins.');
                    releaseSpinButton();
                });
        }

        /* Where the server's segment sits on THIS page's shuffled wheel, or
           -1. Matched on the server index, and the points must agree too, so
           a wheel that changed after the page loaded is never shown landing
           on the wrong prize. */
        function drawnIndexOf(outcome) {
            if (!outcome) return -1;
            for (var i = 0; i < segments.length; i++) {
                if (segments[i].segment === outcome.segment && segments[i].points === outcome.points) {
                    return i;
                }
            }
            return -1;
        }

        /* Spin the wheel so the pointer stops inside drawn segment `idx`.
           Same feel as before: 5-9 full turns plus the remainder, over 4s
           with a quartic ease-out.

           Geometry, from drawWheel(): segment i covers
           [angle + i*arc, angle + (i+1)*arc], and the pointer sits at the top
           (3π/2 on the canvas). The pointer is inside segment i when
           (3π/2 - angle) mod 2π lands in [i*arc, (i+1)*arc). So the wheel
           must stop at an angle equal to 3π/2 - (i*arc + offset), mod 2π.
           The offset keeps clear of the segment's edges (15%-85% of its
           width), so the result never looks like a borderline call. */
        function landOn(idx, done) {
            var TAU = 2 * Math.PI;
            var pointer = 3 * Math.PI / 2;
            var offset = arc * (0.15 + Math.random() * 0.7);
            var target = pointer - (idx * arc + offset);

            var startAngle = currentAngle;
            var remainder = (((target - startAngle) % TAU) + TAU) % TAU;
            var extra = (Math.floor(Math.random() * 5) + 5) * TAU;
            var total = extra + remainder;
            var duration = 4000;
            var startTime = performance.now();

            function animate(now) {
                var elapsed = now - startTime;
                var progress = Math.min(elapsed / duration, 1);
                currentAngle = startAngle + total * (1 - Math.pow(1 - progress, 4));
                drawWheel(currentAngle);
                if (progress < 1) {
                    requestAnimationFrame(animate);
                } else {
                    currentAngle = startAngle + total;
                    drawWheel(currentAngle);
                    done();
                }
            }
            requestAnimationFrame(animate);
        }

        function updatePointsDisplay(data) {
            var pointsEl = document.getElementById('pointsDisplay');
            if (pointsEl && data && typeof data.total_points !== 'undefined') {
                pointsEl.textContent = data.total_points + ' pts';
            }
        }

        /* Centre popup for a points win / Try Again / blocked spin. Replaces the
           persistent result card that used to sit under the wheel. Auto-hides. */
        var spinToastTimer = null;

        function showSpinToast(emoji, title, descHtml) {
            document.getElementById('spinResultEmoji').textContent = emoji || '⭐';
            document.getElementById('spinResultTitle').textContent = title || '';
            document.getElementById('spinResultDesc').innerHTML = descHtml || '';
            document.getElementById('spinResultModal').style.display = 'flex';

            if (spinToastTimer) clearTimeout(spinToastTimer);
            spinToastTimer = setTimeout(hideSpinToast, 3500);
        }

        function hideSpinToast() {
            if (spinToastTimer) {
                clearTimeout(spinToastTimer);
                spinToastTimer = null;
            }
            document.getElementById('spinResultModal').style.display = 'none';
        }

        /* The spin has landed on the server's segment (`won`); now show what
           the server credited. Runs only after the animation, so the counter
           and the points badge do not give the result away mid-spin. */
        function showResult(data, won) {
            renderSpinState(data);
            updatePointsDisplay(data);

            if (won.type === 'points' && data.voucher) {
                /* Won a voucher — the claim code is the whole point, so
                   it gets the dedicated centre modal, not a toast. A
                   guest win carries claim_code (the only way they can
                   ever redeem it); a signed-in win also gets one now so
                   the prize can be handed to a Dine-In friend. */
                var isGuestPrize = !!data.voucher.claim_code;
                var shownCode = data.voucher.claim_code || data.voucher.code;

                showVoucherWinModal(
                    shownCode,
                    data.voucher.description || '',
                    isGuestPrize
                        ? 'You have no account, so this code is the only way to use this voucher. Type it into the voucher box in your cart. It works once.'
                        : 'Type this 1-time code into the voucher box at checkout. It works once.',
                    data.voucher.message || ''
                );
                if (window.celebrateWin) window.celebrateWin();

                setTimeout(releaseSpinButton, 3000);
            } else if (won.type === 'points') {
                var line = 'Total Points: ' + data.total_points;
                if (data.next_voucher) {
                    line += '<br>' + data.points_needed +
                        ' more pts to win: ' + esc(data.next_voucher) + '!';
                }
                showSpinToast('⭐', '+' + won.points + ' Points!', line);
                if (window.celebrateWin) window.celebrateWin();
                releaseSpinButton();
            } else {
                showSpinToast('😅', 'Try Again!', 'Better luck next time!');
                releaseSpinButton();
            }

            /* Ad popup. Driven by the server's spin_number, not by a
               page-local counter: show_ad is true on the last spin of
               each order window, so it fires exactly once per window
               and survives a page refresh mid-window. */
            if (data.show_ad) {
                setTimeout(function() {
                    showAdPopup();
                }, won.type === 'points' ? 2500 : 2000);
            }
        }

        /* The win panel builds HTML from a server response, so anything from it
           is escaped before it goes in. */
        function esc(v) {
            var d = document.createElement('div');
            d.textContent = v == null ? '' : String(v);
            return d.innerHTML;
        }

        /* Voucher-win modal — the unique 1-time code, shown centre-screen. */
        function showVoucherWinModal(code, desc, note, timingMessage) {
            document.getElementById('voucherWinCode').textContent = code || '';
            document.getElementById('voucherWinDesc').textContent = desc || '';
            document.getElementById('voucherWinNote').textContent = note || '';

            var timing = document.getElementById('voucherWinTiming');
            if (timingMessage) {
                timing.textContent = '⏳ ' + timingMessage;
                timing.style.display = 'inline-flex';
            } else {
                timing.style.display = 'none';
            }

            document.getElementById('voucherWinModal').style.display = 'flex';
        }

        /* ══════════ Win celebration: confetti + chime ══════════
           Deliberately dependency-free — a canvas burst and a couple of
           Web Audio oscillator notes cover this without pulling in a
           confetti/sound library for two seconds of animation. Fired for
           every real win (points or voucher); a "Try Again" gets neither. */
        (function () {
            var confettiCanvas = document.getElementById('confettiCanvas');
            var cctx = confettiCanvas ? confettiCanvas.getContext('2d') : null;
            var confettiColors = ['#F4845F', '#C0392B', '#F6B49B', '#2E7D5B', '#FFD166', '#8B1A1A'];
            var confettiRAF = null;

            function resizeConfettiCanvas() {
                if (!confettiCanvas) return;
                confettiCanvas.width = window.innerWidth;
                confettiCanvas.height = window.innerHeight;
            }
            window.addEventListener('resize', resizeConfettiCanvas);
            resizeConfettiCanvas();

            window.fireConfetti = function () {
                if (!cctx) return;
                resizeConfettiCanvas();

                var count = 70;
                var particles = [];
                for (var i = 0; i < count; i++) {
                    particles.push({
                        x: confettiCanvas.width / 2,
                        y: confettiCanvas.height * 0.35,
                        vx: (Math.random() - 0.5) * 11,
                        vy: Math.random() * -9 - 3,
                        size: Math.random() * 6 + 4,
                        color: confettiColors[Math.floor(Math.random() * confettiColors.length)],
                        rotation: Math.random() * 360,
                        spin: (Math.random() - 0.5) * 18,
                        gravity: 0.32 + Math.random() * 0.12
                    });
                }

                var start = performance.now();
                var duration = 1600;

                if (confettiRAF) cancelAnimationFrame(confettiRAF);

                function step(now) {
                    var elapsed = now - start;
                    cctx.clearRect(0, 0, confettiCanvas.width, confettiCanvas.height);

                    for (var i = 0; i < particles.length; i++) {
                        var p = particles[i];
                        p.x += p.vx;
                        p.y += p.vy;
                        p.vy += p.gravity;
                        p.rotation += p.spin;

                        cctx.save();
                        cctx.translate(p.x, p.y);
                        cctx.rotate(p.rotation * Math.PI / 180);
                        cctx.globalAlpha = Math.max(0, 1 - elapsed / duration);
                        cctx.fillStyle = p.color;
                        cctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
                        cctx.restore();
                    }

                    if (elapsed < duration) {
                        confettiRAF = requestAnimationFrame(step);
                    } else {
                        cctx.clearRect(0, 0, confettiCanvas.width, confettiCanvas.height);
                        confettiRAF = null;
                    }
                }
                confettiRAF = requestAnimationFrame(step);
            };

            /* Short two-note chime via Web Audio — no sound asset to ship or
               license. The AudioContext is created lazily on the first spin
               tap (a user gesture) so browsers' autoplay policies don't
               silently swallow it later inside the async fetch response. */
            var audioCtx = null;

            window.primeCelebrationAudio = function () {
                if (audioCtx) return;
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                try {
                    audioCtx = new Ctx();
                } catch (e) {
                    audioCtx = null;
                }
            };

            window.playCelebrationChime = function () {
                if (!audioCtx) return;
                if (audioCtx.state === 'suspended') audioCtx.resume();

                var notes = [880, 1318.5]; // A5 then E6 — a bright little "ta-da"
                var t = audioCtx.currentTime;

                notes.forEach(function (freq, i) {
                    var osc = audioCtx.createOscillator();
                    var gain = audioCtx.createGain();
                    osc.type = 'triangle';
                    osc.frequency.value = freq;
                    var noteStart = t + i * 0.11;
                    gain.gain.setValueAtTime(0, noteStart);
                    gain.gain.linearRampToValueAtTime(0.16, noteStart + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.001, noteStart + 0.32);
                    osc.connect(gain);
                    gain.connect(audioCtx.destination);
                    osc.start(noteStart);
                    osc.stop(noteStart + 0.34);
                });
            };

            window.celebrateWin = function () {
                window.fireConfetti();
                window.playCelebrationChime();
            };
        })();

        (function () {
            var btn = document.getElementById('voucherWinCopy');
            if (!btn) return;
            btn.addEventListener('click', function () {
                var code = document.getElementById('voucherWinCode').textContent;
                var done = function () {
                    btn.innerHTML = '✓ Copied!';
                    setTimeout(function () { btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy Code'; }, 1800);
                };
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(code).then(done, done);
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = code;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); } catch (err) {}
                    document.body.removeChild(ta);
                    done();
                }
            });
        })();

        /* Copy buttons on the "prizes you are holding" panel above the wheel. */
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-claim-code]');
            if (!btn) return;

            var done = function () {
                var original = btn.innerHTML;
                btn.innerHTML = '✓ Copied!';
                setTimeout(function () { btn.innerHTML = original; }, 1800);
            };

            if (navigator.clipboard) {
                navigator.clipboard.writeText(btn.dataset.claimCode).then(done, done);
            } else {
                var ta = document.createElement('textarea');
                ta.value = btn.dataset.claimCode;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (err) {}
                document.body.removeChild(ta);
                done();
            }
        });

        function showAdPopup() {
            if (gameAds.length === 0) return;
            var currentSlide = 0;

            var overlay = document.createElement('div');
            overlay.id = 'adOverlay';
            overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;';

            function buildSlide(slideIdx) {
                var ad = gameAds[slideIdx];
                var dots = '';
                for (var i = 0; i < gameAds.length; i++) {
                    dots += '<span style="width:8px;height:8px;border-radius:50%;display:inline-block;background:' + (i === slideIdx ? '#F4845F' : '#ddd') + ';"></span>';
                }
                return '<div style="background:white;border-radius:12px;max-width:340px;width:90%;position:relative;overflow:hidden;">' +
                    '<button onclick="closeAd()" style="position:absolute;top:8px;right:8px;background:rgba(0,0,0,0.5);color:white;border:none;border-radius:50%;width:28px;height:28px;font-size:1rem;cursor:pointer;z-index:10;display:flex;align-items:center;justify-content:center;">✕</button>' +
                    (ad.image ? '<img src="{{ asset('') }}' + String(ad.image).replace(/^\/+/, '') + '" style="width:100%;max-height:250px;object-fit:cover;border-radius:12px 12px 0 0;">' : '') +
                    '<div style="padding:1rem;text-align:center;">' +
                    '<p style="font-size:0.95rem;font-weight:700;color:#F4845F;margin:0 0 0.25rem;">' + ad.title + '</p>' +
                    '<p style="font-size:0.78rem;color:#666;margin:0;">' + (ad.description || '') + '</p>' +
                    '</div>' +
                    (gameAds.length > 1 ? '<div style="display:flex;justify-content:center;gap:6px;padding:0 1rem 0.75rem;">' + dots + '</div>' : '') +
                    '</div>';
            }

            overlay.innerHTML = buildSlide(0);
            document.body.appendChild(overlay);

            var slideInterval = null;
            if (gameAds.length > 1) {
                slideInterval = setInterval(function() {
                    currentSlide = (currentSlide + 1) % gameAds.length;
                    var container = document.getElementById('adOverlay');
                    if (container) {
                        container.innerHTML = buildSlide(currentSlide);
                    } else {
                        clearInterval(slideInterval);
                    }
                }, 4000);
            }

            var totalTime = gameAds.length > 1 ? (gameAds.length * 4000 + 1000) : 5000;
            setTimeout(function() {
                if (slideInterval) clearInterval(slideInterval);
                closeAd();
            }, totalTime);
        }

        function closeAd() {
            var overlay = document.getElementById('adOverlay');
            if (overlay) overlay.remove();
        }
    </script>

    @include('customer.partials.idle-timeout')
</body>

</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help - Peachy</title>

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
                    linear-gradient(135deg, #F8D7B0 0%, #F6B49B 50%, #EF8585 100%);
                color: #3b2320;
            }
            h1, h2, h3, .font-display { font-family: var(--font-display); }
            a, button { font-family: inherit; }
            button:not(:disabled), [onclick], summary { cursor: pointer; }
        }

        /* Native <details>: no JavaScript, so it works the same for every
           customer type. The summary is the 44px+ tap target. */
        .help-topic > summary {
            list-style: none;
            min-height: 3.5rem;
        }
        .help-topic > summary::-webkit-details-marker { display: none; }
        .help-topic[open] > summary .help-chevron { transform: rotate(180deg); }
        .help-chevron { transition: transform .15s ease; }

        .help-steps { counter-reset: step; }
        .help-steps > li { counter-increment: step; position: relative; padding-left: 2.25rem; }
        .help-steps > li::before {
            content: counter(step);
            position: absolute; left: 0; top: .125rem;
            display: grid; place-items: center;
            width: 1.625rem; height: 1.625rem; border-radius: 9999px;
            background: #FDE8DE; color: #A32D22;
            font-size: .875rem; font-weight: 800;
        }

        .help-option:has(input:checked) { border-color: #EF8585; background: #FDE8DE; }
        .help-option input { width: 1.25rem; height: 1.25rem; accent-color: #A32D22; }
    </style>
    @include('partials.session-guard')

    @include('customer.partials.readable-text')

</head>

<body class="min-h-screen bg-peach-cream">
    @php
        $isDineIn = session('order_type') === 'dine_in';
    @endphp

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

    <main class="mx-auto w-full max-w-2xl px-4 pb-28 pt-5 sm:px-6 sm:pt-7 md:pb-16">
        <div class="mb-5">
            {{-- Back to More is the header back button (partials/header-back). --}}
            <h1 class="font-display text-3xl font-black tracking-tight text-peach-deep">Help</h1>
            <p class="mt-1 text-sm text-peach-deep/55">
                How to order, pay and use your rewards, and how to reach us.
            </p>
        </div>

        {{-- Result of the assistance form (submitHelpRequest redirects back here). --}}
        @if(session('success'))
            <div role="status" data-help-flash class="mb-4 flex items-start gap-3 rounded-2xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">
                <i class="bi bi-check-circle mt-0.5"></i>
                <span class="min-w-0 flex-1">{{ session('success') }}</span>
            </div>
        @endif
        @if(session('error'))
            <div role="alert" data-help-flash class="mb-4 flex items-start gap-3 rounded-2xl border border-peach-soft bg-white p-4 text-sm font-semibold text-peach-deep">
                <i class="bi bi-exclamation-circle mt-0.5 text-peach-red"></i>
                <span class="min-w-0 flex-1">{{ session('error') }}</span>
            </div>
        @endif
        @if($errors->any())
            <div role="alert" class="mb-4 flex items-start gap-3 rounded-2xl border border-peach-soft bg-white p-4 text-sm font-semibold text-peach-deep">
                <i class="bi bi-exclamation-circle mt-0.5 text-peach-red"></i>
                <span class="min-w-0 flex-1">{{ $errors->first() }}</span>
            </div>
        @endif

        {{-- ================= ASK STAFF (dine-in with a table only) ================= --}}
        @if($canRequestAssistance)
            <section id="assistance" class="card-surface mb-4 overflow-hidden">
                <div class="border-b border-peach-soft bg-peach-soft/50 px-5 py-4 sm:px-6">
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-white text-peach-red">
                            <i class="bi bi-person-raised-hand"></i>
                        </span>
                        <div class="min-w-0">
                            <h2 class="font-display text-lg font-bold text-peach-deep">Need a hand?</h2>
                            <p class="text-xs text-peach-deep/55">Table {{ $tableNumber }} &middot; staff will come to you.</p>
                        </div>
                    </div>
                </div>

                {{-- data-help-region: while a request is open the page polls
                     customer.help-request.status and, once staff press Done, swaps this
                     body for the server's fresh one (the form) with a short thank-you. --}}
                <div class="p-5 sm:p-6" data-help-region
                     data-help-status-url="{{ route('customer.help-request.status') }}"
                     data-help-open="{{ $openRequest ? '1' : '0' }}"
                     data-help-text-pending="Your request was sent. Please wait &mdash; staff will be with you shortly."
                     data-help-text-assisting="Staff are on their way to Table {{ $tableNumber }}.">
                    @if($openRequest)
                        <div class="flex items-start gap-3 rounded-2xl border border-peach-soft bg-peach-cream p-4">
                            <i class="bi bi-bell-fill mt-0.5 text-peach-red"></i>
                            <p class="min-w-0 flex-1 text-sm font-semibold text-peach-deep" data-help-message>
                                @if($openRequest->status === 'assisting')
                                    Staff are on their way to Table {{ $tableNumber }}.
                                @else
                                    Your request was sent. Please wait &mdash; staff will be with you shortly.
                                @endif
                            </p>
                        </div>
                    @else
                        <form action="{{ route('customer.help-request') }}" method="POST">
                            @csrf
                            <fieldset class="grid gap-2.5">
                                <legend class="mb-2 text-sm font-bold text-peach-deep">What do you need?</legend>

                                <label class="help-option flex min-h-12 cursor-pointer items-center gap-3 rounded-2xl border border-peach-soft p-3 hover:bg-peach-soft/40">
                                    <input type="radio" name="message" value="I need help with my order" required>
                                    <span class="text-sm font-semibold text-peach-deep">Help with my order</span>
                                </label>
                                <label class="help-option flex min-h-12 cursor-pointer items-center gap-3 rounded-2xl border border-peach-soft p-3 hover:bg-peach-soft/40">
                                    <input type="radio" name="message" value="I need payment assistance">
                                    <span class="text-sm font-semibold text-peach-deep">Payment assistance</span>
                                </label>
                                <label class="help-option flex min-h-12 cursor-pointer items-center gap-3 rounded-2xl border border-peach-soft p-3 hover:bg-peach-soft/40">
                                    <input type="radio" name="message" value="I need staff assistance">
                                    <span class="text-sm font-semibold text-peach-deep">I need staff assistance</span>
                                </label>
                            </fieldset>

                            <button type="submit"
                                    class="mt-4 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-full bg-peach-red px-5 py-3 text-base font-bold text-white transition hover:bg-peach-deep">
                                <i class="bi bi-bell"></i>
                                Request Assistance
                            </button>
                        </form>
                    @endif
                </div>
            </section>
        @endif

        {{-- ================= HOW-TO TOPICS ================= --}}
        <section class="card-surface overflow-hidden" aria-labelledby="howto-heading">
            <h2 id="howto-heading" class="sr-only">How-to guides</h2>

            <details class="help-topic border-b border-peach-soft" @if(!$canRequestAssistance) open @endif>
                <summary class="flex items-center gap-3 px-5 py-3 sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red"><i class="bi bi-qr-code-scan"></i></span>
                    <span class="min-w-0 flex-1 text-base font-bold text-peach-deep">Ordering dine-in</span>
                    <i class="bi bi-chevron-down help-chevron text-peach-red/60"></i>
                </summary>
                <ol class="help-steps grid gap-3 px-5 pb-5 text-sm leading-relaxed text-peach-deep/70 sm:px-6">
                    <li>Scan the QR code on your table, or type the 4-character code printed under it on the Dine-In page.</li>
                    <li>Open the <strong>Menu</strong> and add what you want to your cart.</li>
                    <li>Open the cart (top right), choose <strong>Cash</strong> or <strong>GCash</strong>, and place the order. Tick <strong>Take Out</strong> if you want it to go.</li>
                    <li>Follow your order on the <strong>Orders</strong> tab until it is served.</li>
                </ol>
            </details>

            <details class="help-topic border-b border-peach-soft">
                <summary class="flex items-center gap-3 px-5 py-3 sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red"><i class="bi bi-bag-check"></i></span>
                    <span class="min-w-0 flex-1 text-base font-bold text-peach-deep">Ordering for pick-up</span>
                    <i class="bi bi-chevron-down help-chevron text-peach-red/60"></i>
                </summary>
                <ol class="help-steps grid gap-3 px-5 pb-5 text-sm leading-relaxed text-peach-deep/70 sm:px-6">
                    <li>Log in, or create an account and confirm your email, then choose your pick-up branch on the <strong>Menu</strong>.</li>
                    <li>Add items from the <strong>Menu</strong> to your cart.</li>
                    <li>In the cart, choose <strong>Cash on Pick Up</strong> or <strong>GCash</strong> and place the order.</li>
                    <li>Watch the <strong>Orders</strong> tab. When it says <strong>Ready for Pick-up</strong>, come to the shop to collect it.</li>
                </ol>
            </details>

            <details class="help-topic border-b border-peach-soft">
                <summary class="flex items-center gap-3 px-5 py-3 sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red"><i class="bi bi-wallet2"></i></span>
                    <span class="min-w-0 flex-1 text-base font-bold text-peach-deep">Paying with GCash</span>
                    <i class="bi bi-chevron-down help-chevron text-peach-red/60"></i>
                </summary>
                <ol class="help-steps grid gap-3 px-5 pb-5 text-sm leading-relaxed text-peach-deep/70 sm:px-6">
                    <li>In the cart, choose <strong>GCash</strong> and place your order.</li>
                    <li>The GCash page shows our QR code. Scan it in the GCash app and pay the exact amount shown.</li>
                    <li>Come back and tap <strong>I Have Paid</strong>.</li>
                    <li>Staff check the payment, and the page updates once it is confirmed. If it is not confirmed, ask our staff.</li>
                </ol>
            </details>

            <details class="help-topic border-b border-peach-soft">
                <summary class="flex items-center gap-3 px-5 py-3 sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red"><i class="bi bi-ticket-perforated"></i></span>
                    <span class="min-w-0 flex-1 text-base font-bold text-peach-deep">Using a voucher</span>
                    <i class="bi bi-chevron-down help-chevron text-peach-red/60"></i>
                </summary>
                <ol class="help-steps grid gap-3 px-5 pb-5 text-sm leading-relaxed text-peach-deep/70 sm:px-6">
                    <li>Win one from <strong>Spin &amp; Win</strong>, or get a code from our staff. Account holders can see theirs under <strong>More &rarr; Vouchers &amp; Rewards</strong>.</li>
                    <li>Add your items and open the cart.</li>
                    <li>Type the code into <strong>Voucher or reward code</strong> and tap <strong>Apply</strong> before you place the order.</li>
                </ol>
            </details>

            <details class="help-topic border-b border-peach-soft">
                <summary class="flex items-center gap-3 px-5 py-3 sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red"><i class="bi bi-dice-5"></i></span>
                    <span class="min-w-0 flex-1 text-base font-bold text-peach-deep">Spin &amp; Win</span>
                    <i class="bi bi-chevron-down help-chevron text-peach-red/60"></i>
                </summary>
                <ol class="help-steps grid gap-3 px-5 pb-5 text-sm leading-relaxed text-peach-deep/70 sm:px-6">
                    <li>Place an order first. Spinning is for while you wait, and each order gives you up to 7 spins.</li>
                    <li>Open the <strong>Spin &amp; Win</strong> tab and tap Spin to win points or vouchers.</li>
                    <li>Points and vouchers are saved to your account. Without an account, your prizes are not kept, so create one to keep what you win.</li>
                </ol>
            </details>

            {{-- Reward stamp card (Oct 2026): per branch. The number and the
                 reward are those of the branch the customer is ordering from,
                 like every other line that names them; with no branch picked
                 the topic explains the rule without numbers. Hidden while the
                 owner's master switch is off or that branch's card is off. --}}
            @php
                $helpBranch = \App\Services\FreeDesserts::sessionBranch();
                $helpShown = $helpBranch
                    ? \App\Services\FreeDesserts::active($helpBranch->id)
                    : \App\Services\FreeDesserts::masterOn();
            @endphp
            @if($helpShown)
            @php $helpReward = $helpBranch ? \App\Services\StreakRewardSettings::current($helpBranch->id)['description'] : null; @endphp
            <details class="help-topic border-b border-peach-soft" data-testid="help-free-dessert">
                <summary class="flex items-center gap-3 px-5 py-3 sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red"><i class="bi bi-gift"></i></span>
                    <span class="min-w-0 flex-1 text-base font-bold text-peach-deep">Reward stamp card</span>
                    <i class="bi bi-chevron-down help-chevron text-peach-red/60"></i>
                </summary>
                <ol class="help-steps grid gap-3 px-5 pb-5 text-sm leading-relaxed text-peach-deep/70 sm:px-6">
                    <li>With a customer account you earn 1 stamp per day you completed an order. Each branch has its own stamp card: only orders at that branch count toward it. Any days count, not only days in a row, and several orders on one day are still 1 stamp.</li>
                    @if($helpBranch)
                        <li>At {{ $helpBranch->name }}, collect {{ \App\Services\FreeDesserts::visitDays($helpBranch->id) }} stamps and a reward{{ $helpReward ? ' (' . $helpReward . ')' : '' }} is added to your account with its own code. It can be used only at {{ $helpBranch->name }}.</li>
                    @else
                        <li>Fill a branch's card and a reward is added to your account with its own code. It can be used only at that branch. Choose a branch to see its card on your Account page.</li>
                    @endif
                    <li>Find it under <strong>More &rarr; Vouchers &amp; Rewards</strong>. Tap <strong>Use now</strong>, or type the code in the voucher box in your cart. Each code works once, on your account only.</li>
                </ol>
            </details>
            @endif

            <details class="help-topic">
                <summary class="flex items-center gap-3 px-5 py-3 sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red"><i class="bi bi-telephone"></i></span>
                    <span class="min-w-0 flex-1 text-base font-bold text-peach-deep">Contact the shop</span>
                    <i class="bi bi-chevron-down help-chevron text-peach-red/60"></i>
                </summary>
                <div class="grid gap-3 px-5 pb-5 sm:px-6">
                    <p class="text-sm leading-relaxed text-peach-deep/70">
                        @if($canRequestAssistance)
                            You can also ask any staff member in the shop.
                        @else
                            Questions about an order or payment? Reach {{ $storeInfo['business_name'] ?? 'the shop' }} here.
                        @endif
                    </p>

                    @if(!empty($storeInfo['address']))
                        <div class="flex items-start gap-3 rounded-xl border border-peach-soft bg-peach-cream p-3">
                            <i class="bi bi-geo-alt mt-0.5 text-peach-red"></i>
                            <p class="min-w-0 flex-1 text-sm font-semibold text-peach-deep">{{ $storeInfo['address'] }}</p>
                        </div>
                    @endif

                    @if(!empty($storeInfo['contact_number']))
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $storeInfo['contact_number']) }}"
                           class="flex min-h-12 items-center gap-3 rounded-xl border border-peach-soft bg-white p-3 no-underline transition hover:bg-peach-soft/40">
                            <i class="bi bi-telephone text-peach-red"></i>
                            <span class="min-w-0 flex-1 text-sm font-bold text-peach-deep">{{ $storeInfo['contact_number'] }}</span>
                            <i class="bi bi-chevron-right text-peach-red/40"></i>
                        </a>
                    @endif

                    @if(!empty($storeInfo['email']))
                        <a href="mailto:{{ $storeInfo['email'] }}"
                           class="flex min-h-12 items-center gap-3 rounded-xl border border-peach-soft bg-white p-3 no-underline transition hover:bg-peach-soft/40">
                            <i class="bi bi-envelope text-peach-red"></i>
                            <span class="min-w-0 flex-1 break-all text-sm font-bold text-peach-deep">{{ $storeInfo['email'] }}</span>
                            <i class="bi bi-chevron-right text-peach-red/40"></i>
                        </a>
                    @endif

                    @if(empty($storeInfo['address']) && empty($storeInfo['contact_number']) && empty($storeInfo['email']))
                        <p class="text-sm font-semibold text-peach-deep">Please ask our staff at the counter.</p>
                    @endif
                </div>
            </details>
        </section>
    </main>

    @include('customer.partials.navbar')

    @if($canRequestAssistance)
        @include('customer.partials.help-status-poll')
    @endif

    @include('customer.partials.idle-timeout')

</body>
</html>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - Peachy</title>

    @include('partials.icon-stability')
    <link href="/vendor/bootstrap-icons.css" rel="stylesheet">
    <link href="/vendor/gfonts.css" rel="stylesheet">
    @include('partials.typography-stability')

    <script src="/vendor/tailwindcss-browser-4.js"></script>
    <style type="text/tailwindcss">
        
        @theme {
            --color-peach-deep: #8B1A1A;
            --color-peach-red: #C0392B;
            --color-peach: #F4845F;
            --color-peach-blush: #EF8585;
            --color-peach-rose: #F6B49B;
            --color-peach-sand: #F8D7B0;
            --color-peach-soft: #FDE8DE;
            --color-peach-cream: #FFFDF9;
            --font-display: "Fraunces", ui-serif, Georgia, serif;
            --font-body: "Karla", ui-sans-serif, system-ui, sans-serif;
        }

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

        @utility card-surface {
            background-color: #fff;
            border: 1px solid var(--color-peach-soft);
            border-radius: 1.25rem;
            box-shadow: 0 1px 2px rgb(139 26 26 / 0.04), 0 8px 24px -18px rgb(139 26 26 / 0.35);
        }
    </style>
    <style>
        /* Warm bakery paper backdrop */
        .paper-bg {
            background-image:
                radial-gradient(circle at 12% 8%, rgba(248, 215, 176, 0.55), transparent 42%),
                radial-gradient(circle at 88% 0%, rgba(246, 180, 155, 0.4), transparent 38%);
        }

        /* Torn / perforated receipt edges */
        .ticket-notch {
            height: 14px;
            background:
                radial-gradient(circle at 10px -4px, transparent 9px, #fff 9px) repeat-x;
            background-size: 20px 14px;
        }

        .ticket-notch-top {
            transform: rotate(180deg);
        }

        .perf {
            border: none;
            border-top: 2px dashed #FDE8DE;
            margin: 0;
        }

        .receipt-line {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .receipt-line .dots {
            flex: 1;
            border-bottom: 1px dotted rgba(139, 26, 26, 0.18);
            transform: translateY(-3px);
        }

        /* Entrance animation — subtle, no layout shift */
        @keyframes riseIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: none; }
        }

        .rise { animation: riseIn 0.5s cubic-bezier(0.22, 0.9, 0.3, 1) both; }
        .rise-1 { animation-delay: 0.05s; }
        .rise-2 { animation-delay: 0.12s; }
        .rise-3 { animation-delay: 0.2s; }

        @media (prefers-reduced-motion: reduce) {
            .rise { animation: none; }
        }

@media print {
    @page {
        size: auto;
        margin: 10mm;
    }

    html,
    body {
        width: 100% !important;
        height: auto !important;
        min-height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
    }

    /* Hide chrome that should never appear on the printed receipt:
       navbars, the "Freshly baked..." intro title, the Print button,
       and the "Back to Orders" link. */
    nav,
    header,
    .no-print,
    button {
        display: none !important;
    }

    /* Collapse layout to a single centered column */
    main {
        padding: 0 !important;
        margin: 0 !important;
        max-width: 100% !important;
    }

    .paper-bg {
        background: #ffffff !important;
    }

    /* Force the two-column grid into one column so nothing is clipped */
    .grid {
        display: block !important;
    }

    /* Keep each receipt block intact on one page */
    section,
    aside,
    aside > div,
    .card-surface,
    li {
        break-inside: avoid !important;
        page-break-inside: avoid !important;
        box-shadow: none !important;
    }

    aside {
        margin-top: 5mm !important;
    }

    /* Disable entrance animations for print */
    .rise {
        animation: none !important;
        opacity: 1 !important;
        transform: none !important;
    }

    /* Badges read as plain words on paper — no rounded outline, no fill, no
       icon. The Status pill also carries an inline background colour, so the
       override has to be !important. Screen appearance is unchanged. */
    .card-surface dd span,
    main ul li span[class*="rounded-full"],
    main ul li span[class*="ring-"] {
        background: transparent !important;
        color: #000 !important;
        padding: 0 !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        font-weight: 600 !important;
    }

    .card-surface dd span i { display: none !important; }

    /* The itemised list as a paper table: hairline rows, no cards, money
       right-aligned with lining figures. Each <li> already carries
       break-inside: avoid above. */
    main ul li {
        border: 0 !important;
        border-bottom: 1px solid #ccc !important;
        border-radius: 0 !important;
        background: transparent !important;
        padding: 0.25rem 0 !important;
    }

    main ul li .font-display { font-variant-numeric: tabular-nums; }

    /* The total must stand out AND stay readable: print engines routinely drop
       background fills, which would leave white text on white. Render it as a
       bold line under a heavy rule instead — the same treatment the Summary
       report's totals row uses. */
    main .bg-peach-deep {
        background: transparent !important;
        color: #000 !important;
        border-top: 2px solid #000 !important;
        border-radius: 0 !important;
        padding: 0.4rem 0 !important;
    }

    main .bg-peach-deep * { color: #000 !important; }
}
    </style>
    @include('partials.session-guard')

    @include('customer.partials.readable-text')

</head>

<body class="min-h-screen bg-peach-cream">

    @if(!isset($isAdminView) || !$isAdminView)
    {{-- ================= HEADER (unchanged customer navbar) ================= --}}
    <header class="no-print sticky top-0 z-40 border-b border-peach-soft bg-peach-cream/95 backdrop-blur">
        <div class="mx-auto w-full max-w-6xl px-4 sm:px-6">
            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 py-3 sm:py-4">
                <a href="{{ route('customer.menu') }}" class="flex min-w-0 items-center gap-3 no-underline">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-peach-soft text-xl sm:h-12 sm:w-12 sm:text-2xl">🍑</span>
                    <span class="min-w-0">
                        <span class="block truncate font-display text-xl font-black tracking-tight text-peach-deep sm:text-2xl">Peachy</span>
                        <span class="block truncate text-[0.68rem] uppercase tracking-[0.18em] text-peach-red/70 sm:text-[0.72rem]">Cakes &amp; Deli Cafe</span>
                    </span>
                </a>

                {{-- Header actions: kept in ONE grid cell so the 2-column
                     header grid does not push the cart onto a second row.
                     Mirrors customer/partials/desktop-nav, with the extra
                     dine-in Help control this page needs. --}}
                @php $desktopCartCount = collect(session('cart', []))->sum('quantity'); @endphp
                <div class="flex items-center justify-end gap-3 md:gap-4">
                <nav class="hidden items-center gap-1 md:flex">
                    <a href="{{ route('customer.orders') }}" class="rounded-full px-3 py-2 text-[0.95rem] font-semibold no-underline transition {{ request()->routeIs('customer.orders') ? 'bg-peach-soft text-peach-red' : 'text-peach-deep/70 hover:bg-peach-soft hover:text-peach-red' }}">
                        <i class="bi bi-receipt"></i> Orders
                    </a>
                    <a href="{{ route('customer.menu') }}" class="rounded-full px-3 py-2 text-[0.95rem] font-semibold no-underline transition {{ request()->routeIs('customer.menu') ? 'bg-peach-soft text-peach-red' : 'text-peach-deep/70 hover:bg-peach-soft hover:text-peach-red' }}">
                        <i class="bi bi-grid"></i> Menu
                    </a>
                    <a href="{{ route('customer.game') }}" class="rounded-full px-3 py-2 text-[0.95rem] font-semibold no-underline transition {{ request()->routeIs('customer.game') ? 'bg-peach-soft text-peach-red' : 'text-peach-deep/70 hover:bg-peach-soft hover:text-peach-red' }}">
                        <i class="bi bi-dice-5"></i> Spin &amp; Win
                    </a>
                    <a href="{{ route('customer.more') }}" class="rounded-full px-3 py-2 text-[0.95rem] font-semibold no-underline transition {{ request()->routeIs('customer.more') ? 'bg-peach-soft text-peach-red' : 'text-peach-deep/70 hover:bg-peach-soft hover:text-peach-red' }}">
                        <i class="bi bi-three-dots"></i> More
                    </a>
                    @if(session('order_type') === 'dine_in' && session('table_number'))
                    <form action="{{ route('customer.help-request') }}" method="POST" class="contents">
                        @csrf
                        <button type="submit" class="cursor-pointer rounded-full border-none bg-transparent px-3 py-2 text-[0.95rem] font-semibold text-peach-deep/70 transition hover:bg-peach-soft hover:text-peach-red">
                            <i class="bi bi-question-circle"></i> Help
                        </button>
                    </form>
                    @endif
                    <a href="{{ route('customer.cart') }}" class="relative ml-2 inline-flex items-center gap-2 rounded-full bg-peach-red px-4 py-2 text-[0.95rem] font-bold text-white no-underline transition hover:bg-peach-deep">
                        <i class="bi bi-cart"></i> Cart
                        @if($desktopCartCount > 0)
                        <span class="grid h-5 min-w-5 place-items-center rounded-full bg-white px-1 text-[0.65rem] font-black text-peach-red">{{ $desktopCartCount }}</span>
                        @endif
                    </a>
                </nav>

                {{-- Notifications --}}
                @include('customer.partials.notification-bell')

                {{-- Mobile cart shortcut --}}
                <a href="{{ route('customer.cart') }}" class="relative grid h-10 w-10 shrink-0 place-items-center rounded-full bg-peach-red text-white no-underline md:hidden">
                    <i class="bi bi-cart text-lg"></i>
                    @if($desktopCartCount > 0)
                    <span class="absolute -right-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full border-2 border-peach-cream bg-peach-deep px-1 text-[0.6rem] font-black text-white">{{ $desktopCartCount }}</span>
                    @endif
                </a>
                </div>
            </div>
        </div>
    </header>
    @endif

    {{-- ================= RECEIPT ================= --}}
    <main class="paper-bg mx-auto w-full max-w-5xl px-4 pb-28 pt-5 sm:px-6 sm:pt-8 md:pb-16">

        {{-- Page intro --}}
        <div class="no-print rise rise-1 mb-5 flex flex-wrap items-end justify-between gap-3 sm:mb-7">
            <div class="min-w-0">
                <p class="text-[0.68rem] font-bold uppercase tracking-[0.22em] text-peach-red/70">Order Receipt</p>
                <h1 class="mt-1 font-display text-3xl font-black leading-tight tracking-tight text-peach-deep sm:text-4xl">Freshly baked, all settled.</h1>
            </div>
            <div class="no-print flex items-center gap-2">
                <button type="button" onclick="window.print()" class="inline-flex cursor-pointer items-center gap-2 rounded-full border border-peach-soft bg-white px-4 py-2.5 text-sm font-bold text-peach-deep transition hover:border-peach hover:text-peach-red">
                    <i class="bi bi-printer"></i> Print / Save PDF
                </button>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)] lg:items-start lg:gap-6">

            {{-- ===== Left: the ticket ===== --}}
            <section class="rise rise-2 overflow-hidden rounded-[1.5rem] bg-white shadow-[0_1px_2px_rgb(139_26_26_/_0.04),0_24px_50px_-30px_rgb(139_26_26_/_0.45)]">
                <div class="ticket-notch ticket-notch-top"></div>

                {{-- Ticket head --}}
                <div class="bg-gradient-to-br from-peach-sand/60 via-peach-soft to-white px-5 pb-6 pt-5 text-center sm:px-8">
                    <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-white text-2xl shadow-sm">🍑</span>
                    <h2 class="mt-3 font-display text-2xl font-black tracking-tight text-peach-deep sm:text-3xl">Peachy</h2>
                    <p class="text-[0.68rem] font-semibold uppercase tracking-[0.2em] text-peach-red/75">Cakes &amp; Deli Cafe</p>

                    <div class="mt-4 inline-flex items-center gap-2 rounded-full bg-white/90 px-4 py-1.5 shadow-sm">
                        <i class="bi bi-receipt-cutoff text-peach-red"></i>
                        <span class="font-display text-base font-black tracking-tight text-peach-deep sm:text-lg">{{ $order->order_number }}</span>
                    </div>
                    <p class="mt-2 text-xs font-medium text-peach-deep/55">{{ $order->created_at->format('M d, Y h:i A') }}</p>
                </div>

                <hr class="perf">

                {{-- Items --}}
                <div class="px-5 py-5 sm:px-8 sm:py-6">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="font-display text-base font-black tracking-tight text-peach-deep sm:text-lg">Your Items</h3>
                        <span class="text-[0.68rem] font-bold uppercase tracking-[0.16em] text-peach-deep/40">Amount</span>
                    </div>

                    <ul class="m-0 list-none space-y-2.5 p-0">
                        @foreach($order->items as $item)
                        <li class="rounded-2xl border border-peach-soft/80 bg-peach-cream/60 px-3.5 py-3 transition hover:border-peach/60">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-start gap-2.5">
                                    <span class="mt-0.5 grid h-6 min-w-6 shrink-0 place-items-center rounded-full bg-peach-soft px-1 text-[0.68rem] font-black text-peach-red">{{ $item->quantity }}</span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold leading-snug text-peach-deep sm:text-[0.95rem]">{{ $item->item_name }}</p>
                                        @if($item->options && $item->options->count() > 0)
                                        <div class="mt-1.5 flex flex-wrap gap-1">
                                            @foreach($item->options as $option)
                                            <span class="rounded-full bg-white px-2 py-0.5 text-[0.64rem] font-semibold text-peach-red/85 ring-1 ring-peach-soft">{{ $option->name }}</span>
                                            @endforeach
                                        </div>
                                        @endif
                                    </div>
                                </div>
                                <span class="shrink-0 font-display text-sm font-black text-peach-deep sm:text-base">₱{{ number_format($item->subtotal, 2) }}</span>
                            </div>
                        </li>
                        @endforeach
                    </ul>
                </div>

                <hr class="perf">

                {{-- Summary --}}
                <div class="px-5 py-5 sm:px-8 sm:py-6">
                    <div class="space-y-2.5">
                        <div class="receipt-line text-sm">
                            <span class="font-medium text-peach-deep/60">Subtotal</span>
                            <span class="dots"></span>
                            <span class="font-semibold text-peach-deep">₱{{ number_format($order->subtotal, 2) }}</span>
                        </div>

                        @if($order->discount_amount > 0)
                        <div class="receipt-line text-sm">
                            <span class="inline-flex items-center gap-1.5 font-semibold text-green-600">
                                <i class="bi bi-tag"></i>
                                {{ $order->discountDisplayLabel() ?: 'Discount' }}
                            </span>
                            <span class="dots"></span>
                            <span class="font-bold text-green-600">-₱{{ number_format($order->discount_amount, 2) }}</span>
                        </div>
                        @endif
                    </div>

                    <div class="mt-4 flex items-end justify-between gap-3 rounded-2xl bg-peach-deep px-4 py-4 sm:px-5">
                        <div>
                            <p class="text-[0.62rem] font-bold uppercase tracking-[0.2em] text-white/60">Total Paid</p>
                            <p class="mt-0.5 text-[0.7rem] font-medium text-white/60">{{ ucfirst($order->payment_method ?? 'Cash') }}</p>
                        </div>
                        <span class="font-display text-3xl font-black leading-none tracking-tight text-white sm:text-4xl">₱{{ number_format($order->total, 2) }}</span>
                    </div>
                </div>

                <div class="ticket-notch"></div>
            </section>

            {{-- ===== Right: details + thanks ===== --}}
            <aside class="rise rise-3 space-y-5 lg:sticky lg:top-24">

                <div class="card-surface p-5 sm:p-6">
                    <h3 class="mb-4 font-display text-base font-black tracking-tight text-peach-deep sm:text-lg">Order Details</h3>

                    <dl class="m-0 space-y-3.5">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-[0.7rem] font-bold uppercase tracking-[0.14em] text-peach-deep/45">Order #</dt>
                            <dd class="m-0 min-w-0 truncate text-right text-sm font-bold text-peach-deep">{{ $order->order_number }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-[0.7rem] font-bold uppercase tracking-[0.14em] text-peach-deep/45">Date</dt>
                            <dd class="m-0 text-right text-sm font-semibold text-peach-deep">{{ $order->created_at->format('M d, Y h:i A') }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-[0.7rem] font-bold uppercase tracking-[0.14em] text-peach-deep/45">Type</dt>
                            <dd class="m-0 text-right text-sm font-semibold text-peach-deep">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-peach-soft px-2.5 py-1 text-[0.72rem] font-bold text-peach-red">
                                    <i class="bi {{ $order->type === 'dine_in' ? 'bi-cup-hot' : 'bi-bag' }}"></i>
                                    {{ $order->type === 'dine_in' ? 'Dine-in (Table ' . $order->table_number . ')' : 'Pickup' }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-[0.7rem] font-bold uppercase tracking-[0.14em] text-peach-deep/45">Payment</dt>
                            <dd class="m-0 text-right text-sm font-semibold text-peach-deep">{{ ucfirst($order->payment_method ?? 'Cash') }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-t border-peach-soft pt-3.5">
                            <dt class="text-[0.7rem] font-bold uppercase tracking-[0.14em] text-peach-deep/45">Status</dt>
                            <dd class="m-0">
                                <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[0.7rem] font-black tracking-wide text-white" style="background:{{ $order->status === 'completed' ? '#4CAF50' : '#C0392B' }};">
                                    <i class="bi {{ $order->status === 'completed' ? 'bi-check-circle-fill' : 'bi-clock-fill' }}"></i>
                                    {{ strtoupper($order->status) }}
                                </span>
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="overflow-hidden rounded-[1.25rem] border border-peach-soft bg-gradient-to-br from-white via-peach-cream to-peach-soft/70 p-5 text-center sm:p-6">
                    <span class="text-2xl">🧁</span>
                    <p class="mt-2 font-display text-lg font-black leading-snug tracking-tight text-peach-deep">{{ $order->type === 'dine_in' ? 'Thank you for dining with us!' : 'Thank you for ordering with us!' }}</p>
                    <p class="mt-1.5 text-xs font-medium leading-relaxed text-peach-deep/55">We hope every bite felt like home. Come back soon for something warm from the oven. 🍑</p>
                </div>

                @if((!isset($isAdminView) || !$isAdminView) && $order->type !== 'dine_in')
                <div class="no-print">
                    @include('partials.pickup-store-contact')
                </div>
                @endif

                <div class="no-print">
                    @if(!isset($isAdminView) || !$isAdminView)
                    <a href="{{ route('customer.orders') }}" class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-peach-red px-5 py-3.5 text-sm font-bold text-white no-underline transition hover:bg-peach-deep">
                        <i class="bi bi-arrow-left"></i> Back to Orders
                    </a>
                    @else
                    <a href="{{ route('admin.completed-orders') }}" class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-peach-red px-5 py-3.5 text-sm font-bold text-white no-underline transition hover:bg-peach-deep">
                        <i class="bi bi-arrow-left"></i> Back to Completed Orders
                    </a>
                    @endif
                </div>
            </aside>
        </div>
    </main>

    @if(!isset($isAdminView) || !$isAdminView)
    {{-- ================= BOTTOM NAV (mobile, unchanged) ================= --}}
    @php $cartCountNav = count(session('cart', [])); @endphp
    <nav class="no-print fixed inset-x-0 bottom-0 z-40 border-t border-peach-soft bg-white/95 backdrop-blur md:hidden">
        <div class="mx-auto grid max-w-md grid-cols-5">
            <a href="{{ route('customer.orders') }}" class="flex flex-col items-center gap-1 py-2.5 text-[0.62rem] font-bold text-peach-red no-underline">
                <i class="bi bi-receipt text-lg"></i><span>Orders</span>
            </a>
            <a href="{{ route('customer.menu') }}" class="flex flex-col items-center gap-1 py-2.5 text-[0.62rem] font-bold text-peach-deep/50 no-underline">
                <i class="bi bi-grid text-lg"></i><span>Menu</span>
            </a>
            <a href="{{ route('customer.cart') }}" class="relative flex flex-col items-center gap-1 py-2.5 text-[0.62rem] font-bold text-peach-deep/50 no-underline">
                <span class="relative">
                    <i class="bi bi-cart text-lg"></i>
                    @if($cartCountNav > 0)
                    <span class="absolute -right-2.5 -top-1.5 grid h-4 min-w-4 place-items-center rounded-full bg-peach-red px-1 text-[0.55rem] font-black text-white">{{ $cartCountNav }}</span>
                    @endif
                </span>
                <span>Cart</span>
            </a>
            <a href="{{ route('customer.more') }}" class="flex flex-col items-center gap-1 py-2.5 text-[0.62rem] font-bold text-peach-deep/50 no-underline">
                <i class="bi bi-three-dots text-lg"></i><span>More</span>
            </a>
            @if(session('order_type') === 'dine_in' && session('table_number'))
            <form action="{{ route('customer.help-request') }}" method="POST" class="contents">
                @csrf
                <button type="submit" class="flex cursor-pointer flex-col items-center gap-1 border-none bg-transparent py-2.5 text-[0.62rem] font-bold text-peach no-underline">
                    <i class="bi bi-question-circle text-lg"></i><span>Help</span>
                </button>
            </form>
            @else
            <span class="flex flex-col items-center gap-1 py-2.5 text-[0.62rem] font-bold text-peach-deep/25">
                <i class="bi bi-question-circle text-lg"></i><span>Help</span>
            </span>
            @endif
        </div>
    </nav>
    @endif
</body>

</html>

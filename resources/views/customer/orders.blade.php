<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders - Peachy</title>

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
            border-radius: 1rem;
            box-shadow: 0 1px 2px rgb(139 26 26 / 0.04), 0 8px 24px -18px rgb(139 26 26 / 0.35);
        }
    </style>
    <style>
        /* Tab pills — JS toggles only the .active class, styling lives here */
        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border: 1px solid #FDE8DE;
            background: #fff;
            color: rgba(139, 26, 26, 0.6);
            border-radius: 999px;
            padding: 0.55rem 1.1rem;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.18s ease;
        }

        .tab-btn:hover { border-color: #F4845F; color: #C0392B; }

        .tab-btn.active {
            background: #C0392B;
            border-color: #C0392B;
            color: #fff;
            box-shadow: 0 8px 20px -12px rgba(139, 26, 26, 0.7);
        }

        /* Progress tracker */
        .step-circle {
            width: 26px;
            height: 26px;
            border-radius: 999px;
            border: 2px solid #FDE8DE;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .step-circle.done {
            background: #C0392B;
            border-color: #C0392B;
        }

        .step-circle.done::after {
            content: '✓';
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .step-circle.active {
            background: #F4845F;
            border-color: #F4845F;
            animation: peachPulse 1.6s infinite;
        }

        .step-circle.active::after {
            content: '';
            width: 8px;
            height: 8px;
            background: #fff;
            border-radius: 999px;
        }

        @keyframes peachPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(244, 132, 95, 0.45); }
            50% { box-shadow: 0 0 0 8px rgba(244, 132, 95, 0); }
        }

        .step-label {
            font-size: 0.68rem;
            font-weight: 600;
            color: rgba(139, 26, 26, 0.4);
            margin-top: 6px;
            text-align: center;
        }

        .step-label.active { color: #F4845F; font-weight: 800; }
        .step-label.done { color: #C0392B; font-weight: 700; }

        .progress-line {
            flex: 1;
            height: 3px;
            background: #FDE8DE;
            border-radius: 999px;
            margin: 0 0.35rem 22px;
        }

        .progress-line.done { background: #C0392B; }

        /* Step icon — shown while the step is still upcoming; the ::after
           tick / pulse dot takes over once it is done / active. */
        .step-circle > i {
            font-size: 0.72rem;
            line-height: 1;
            color: rgba(139, 26, 26, 0.35);
        }

        .step-circle.done > i,
        .step-circle.active > i { display: none; }

        /* Dynamic helper text under the tracker. */
        .order-helper {
            margin-top: 0.9rem;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            border-radius: 0.85rem;
            background: #FFF7F3;
            border: 1px solid #FDE8DE;
            padding: 0.6rem 0.75rem;
            font-size: 0.78rem;
            font-weight: 600;
            line-height: 1.4;
            color: #8B1A1A;
        }

        .order-helper i {
            flex-shrink: 0;
            margin-top: 0.05rem;
            color: #F4845F;
        }
    </style>
    <style>
        .rating-needed{display:inline-flex;align-items:center;gap:.3rem;margin-top:.35rem;padding:.22rem .5rem;border-radius:999px;background:#fff4ef;color:#C0392B;font-size:.62rem;font-weight:800}
        .history-rating-btn{border:1px solid #F4845F;background:#F4845F;color:#fff;border-radius:8px;padding:.35rem .55rem;font-size:.62rem;font-weight:800;cursor:pointer;white-space:nowrap}
        .rating-scale-btn{border:1px solid #ead8d2;background:#fff;color:#8A6A61;border-radius:9px;padding:.5rem;font-size:.7rem;font-weight:800;cursor:pointer}
        .rating-scale-btn.active{background:#FDE8DE;border-color:#F4845F;color:#8B1A1A}
        .rating-option.selected{background:#FDE8DE !important;border-color:#F4845F !important}
        .rating-success{margin-bottom:.8rem;padding:.65rem .75rem;border-radius:10px;background:#E4F2EA;color:#2E7D5B;font-size:.72rem;font-weight:800}
    </style>
    @include('partials.session-guard')

    @include('customer.partials.readable-text')

</head>

<body class="min-h-screen bg-peach-cream">

    {{-- ================= HEADER ================= --}}
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

    {{-- ================= FLASH MESSAGES (floating toast — no layout shift) ================= --}}
    <div class="pointer-events-none fixed inset-x-0 top-3 z-[100] flex flex-col items-center gap-2 px-4 sm:top-4">
        @if(session('success'))
        <div data-toast class="pointer-events-auto flex w-full max-w-sm items-center gap-2 rounded-full border border-green-200/80 bg-green-50/95 px-3.5 py-2 text-xs font-medium text-green-700 shadow-sm backdrop-blur-sm">
            <i class="bi bi-check-circle text-sm text-green-600"></i><span class="min-w-0 flex-1">{{ session('success') }}</span>
        </div>
        @endif
        @if($errors->any())
        <div data-toast class="pointer-events-auto flex w-full max-w-sm items-center gap-2 rounded-full border border-peach-soft/70 bg-white/95 px-3.5 py-2 text-xs font-medium text-peach-deep shadow-sm backdrop-blur-sm">
            <i class="bi bi-exclamation-circle text-sm text-peach-red"></i><span class="min-w-0 flex-1">{{ $errors->first() }}</span>
        </div>
        @endif
    </div>

    <main class="mx-auto w-full max-w-6xl px-4 pb-24 pt-4 sm:px-6 sm:pt-6 md:pb-16">
            @if(session('rating_success'))
            <div class="rating-success"><i class="bi bi-check-circle-fill"></i> {{ session('rating_success') }}</div>
            @endif


        @php
            /*
             * Which tab this page LOADS on, added 2026-09-02.
             *
             * The More page's "Order History" card used to link here with no
             * way to say which tab it meant, so it always landed on Current
             * Order (tab 0's hard-coded 'active' class and #statusTab's lack
             * of an initial display:none) regardless of what the card
             * promised. It now sends ?tab=history and this decides the
             * initial state server-side, so the correct tab renders active
             * even before the page's JS runs — switchTab() still owns
             * everything AFTER load, this only picks where load starts.
             *
             * Gated on the SAME condition #historyTab itself uses below
             * (signed-in customer), not the stricter one the History BUTTON
             * uses (also excludes dine-in) — a request for a tab that exists
             * but currently has no button pointing at it should still open
             * it; a request for a tab that does not exist at all (guest) must
             * fall back to Current Order rather than switching to a panel
             * that was never rendered.
             */
            $initialTab = request()->query('tab') === 'history' && Auth::guard('customer')->check()
                ? 'history'
                : 'current';
        @endphp

        {{-- Page title + tabs --}}
        <div class="mb-5 grid gap-4 sm:flex sm:items-end sm:justify-between">
            <div class="min-w-0">
                <h1 class="font-display text-2xl font-black leading-tight tracking-tight text-peach-deep sm:text-3xl">Your Orders</h1>
                <p class="mt-1 text-sm text-peach-deep/55">Track what's cooking and revisit past visits.</p>
            </div>

            {{-- Tab Buttons --}}
            <div class="tab-header flex shrink-0 items-center gap-2">
                <button class="tab-btn {{ $initialTab === 'current' ? 'active' : '' }}" data-tab-index="0" onclick="switchTab(0, this)"><i class="bi bi-bag"></i> Current Order</button>
                {{--
                    The History button must be gated on EXACTLY the same
                    condition as the #historyTab panel it reveals. Guests have
                    no order history, so that panel is never rendered for them;
                    showing the button anyway pointed it at an element that
                    does not exist. switchTab() tolerates a missing panel now
                    too, but the button should not be offered in the first
                    place.
                --}}
                @if(Auth::guard('customer')->check() && session('order_type') !== 'dine_in')
                <button class="tab-btn {{ $initialTab === 'history' ? 'active' : '' }}" data-tab-index="1" onclick="switchTab(1, this)"><i class="bi bi-clock-history"></i> History</button>
                @endif
            </div>
        </div>

        {{-- ══════════ TAB 1: Current Order ══════════ --}}
        <div id="statusTab" @if($initialTab === 'history') style="display:none;" @endif>
            @php
            // A party can have more than one order in flight at once (dine-in
            // second round, pick-up follow-up), so this tab lists all of them
            // newest first instead of only showing the most recent.
            $currentOrders = $currentOrders ?? collect(array_filter([$currentOrder ?? null]));
            @endphp

            @if(count($currentOrders))

            @if(count($currentOrders) > 1)
            <p class="mb-4 flex items-center gap-2 rounded-2xl border border-peach-soft bg-peach-cream/60 px-4 py-2.5 text-[0.8rem] font-semibold text-peach-deep/70">
                <i class="bi bi-layers"></i>
                You have {{ count($currentOrders) }} orders in progress — newest first.
            </p>
            @endif

            @foreach($currentOrders as $currentOrder)
            @php
            $status = $currentOrder->status;
            $isPending = in_array($status, ['pending', 'preparing', 'serving']);
            $isPreparing = in_array($status, ['preparing', 'serving']);
            $isServing = $status === 'serving';

            // The one status the app has for "ready" reads differently to a
            // pick-up customer than a dine-in one. Same step, different words.
            $isPickup = $currentOrder->type !== 'dine_in';
            $serveLabel = $isPickup ? 'Ready for Pick-up' : 'Served';

            // Dynamic helper text keyed on the current status (+ order type).
            $helperText = match (true) {
                $status === 'pending'   => "We've got your order — waiting for the kitchen to start.",
                $status === 'preparing' => 'Your food is being prepared. Hang tight!',
                $status === 'serving' && $isPickup => 'Your order is ready! Please proceed to the counter for pick-up.',
                $status === 'serving'   => 'Your order has been served. Enjoy your meal!',
                default                 => 'Your order is complete. Thank you!',
            };
            @endphp

            {{--
                Every card carries its OWN order id on both the wrapper and the
                tracker elements below (id="...-{{ $currentOrder->id }}") — a
                visitor can have more than one order open at once (item 43,
                Round 3A), and the old markup only ever put ids on the FIRST
                card, so the live poll could only ever repaint one of them no
                matter how many were on the page. The wrapper id additionally
                lets JS remove exactly this card (and only this one) if the
                order is cancelled, instead of wiping the whole tab.
            --}}
            <div id="orderCard-{{ $currentOrder->id }}" class="grid gap-5 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:gap-8 lg:items-start {{ $loop->first ? '' : 'mt-6 border-t border-peach-soft pt-6' }}">

                {{-- LEFT: status + items --}}
                <div class="grid gap-5">

                    {{-- Order Number + Type + Progress --}}
                    <section class="card-surface p-4 sm:p-6">
                        <div class="order-number-bar grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3">
                            <span class="order-number min-w-0 truncate font-display text-xl font-black tracking-tight text-peach-deep sm:text-2xl">{{ $currentOrder->order_number }}</span>
                            <span class="order-type-badge inline-flex shrink-0 items-center gap-1.5 rounded-full bg-peach-soft px-3 py-1.5 text-[0.7rem] font-bold text-peach-red sm:text-xs">
                                <i class="bi {{ $currentOrder->type === 'dine_in' ? 'bi-shop' : 'bi-bag' }}"></i>
                                {{ $currentOrder->type === 'dine_in' ? 'Dine-in • Table ' . $currentOrder->table_number : 'Pickup' }}
                            </span>
                        </div>

                        {{-- Progress Bar — 4 steps, type-aware.

                             Pick-up : Pending → Preparing → Ready for Pick-up → Completed
                             Dine-in : Pending → Preparing → Served          → Completed

                             The app has ONE 'serving' status; the third step is
                             just labelled for the audience. The fourth step
                             ('Completed') is always an upcoming/dim step on this
                             tab — a completed order moves to History and the
                             existing completion popup fires — but it is drawn so
                             the customer can see the whole journey ahead.

                             Every id still carries this order's own id, and the
                             first three keep their original ids/classes so the
                             live poll (updateOrderProgress) and its tests keep
                             working. --}}
                        <div class="progress-track mt-5 flex items-center sm:mt-6">
                            <div class="progress-step flex flex-1 flex-col items-center">
                                <div id="orderStepPending-{{ $currentOrder->id }}" class="step-circle {{ $isPending ? ($isPreparing ? 'done' : 'active') : '' }}"><i class="bi bi-hourglass-split"></i></div>
                                <span id="orderLabelPending-{{ $currentOrder->id }}" class="step-label {{ $isPending ? ($isPreparing ? 'done' : 'active') : '' }}">Pending</span>
                            </div>
                            <div id="orderLinePreparing-{{ $currentOrder->id }}" class="progress-line {{ $isPreparing ? 'done' : '' }}"></div>
                            <div class="progress-step flex flex-1 flex-col items-center">
                                <div id="orderStepPreparing-{{ $currentOrder->id }}" class="step-circle {{ $isPreparing ? ($isServing ? 'done' : 'active') : '' }}"><i class="bi bi-fire"></i></div>
                                <span id="orderLabelPreparing-{{ $currentOrder->id }}" class="step-label {{ $isPreparing ? ($isServing ? 'done' : 'active') : '' }}">Preparing</span>
                            </div>
                            <div id="orderLineServing-{{ $currentOrder->id }}" class="progress-line {{ $isServing ? 'done' : '' }}"></div>
                            <div class="progress-step flex flex-1 flex-col items-center">
                                <div id="orderStepServing-{{ $currentOrder->id }}" class="step-circle {{ $isServing ? 'active' : '' }}"><i class="bi {{ $isPickup ? 'bi-bag-check' : 'bi-cup-hot' }}"></i></div>
                                <span id="orderLabelServing-{{ $currentOrder->id }}" class="step-label {{ $isServing ? 'active' : '' }}">{{ $serveLabel }}</span>
                            </div>
                            <div id="orderLineCompleted-{{ $currentOrder->id }}" class="progress-line"></div>
                            <div class="progress-step flex flex-1 flex-col items-center">
                                <div id="orderStepCompleted-{{ $currentOrder->id }}" class="step-circle"><i class="bi bi-check2-circle"></i></div>
                                <span id="orderLabelCompleted-{{ $currentOrder->id }}" class="step-label">Completed</span>
                            </div>
                        </div>

                        <p id="orderHelper-{{ $currentOrder->id }}" class="order-helper" data-order-type="{{ $currentOrder->type }}">
                            <i class="bi bi-info-circle-fill"></i>
                            <span data-helper-text>{{ $helperText }}</span>
                        </p>
                    </section>

                    {{-- Order Items --}}
                    <section class="card-surface p-4 sm:p-5">
                        <h2 class="mb-3 font-display text-base font-black tracking-tight text-peach-deep sm:text-lg">Items in this order</h2>
                        <ul class="grid list-none gap-2.5 p-0">
                            @foreach($currentOrder->items as $item)
                            <li class="order-item-card grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 rounded-2xl border border-peach-soft bg-peach-cream/60 p-2.5 sm:p-3">
                                <div class="item-img grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-xl bg-peach-soft/70 sm:h-16 sm:w-16">
                                    @if($item->menuItem && $item->menuItem->image)
                                    <img src="{{ asset($item->menuItem->image) }}" alt="{{ $item->item_name }}" class="h-full w-full object-cover">
                                    @else
                                    <i class="bi bi-image text-lg text-peach/60"></i>
                                    @endif
                                </div>
                                <div class="item-details min-w-0">
                                    <h6 class="truncate text-sm font-bold text-peach-deep sm:text-[0.95rem]">
                                        {{ $item->item_name }}
                                        <span class="font-medium text-peach-deep/45">x{{ $item->quantity }}</span>
                                    </h6>
                                    @if($item->options && $item->options->count() > 0)
                                    <p class="item-options mt-0.5 truncate text-[0.7rem] font-medium text-peach-red/80">{{ $item->options->pluck('option_name')->implode(', ') }}</p>
                                    @endif
                                    <span class="item-price mt-0.5 block text-[0.72rem] text-peach-deep/45">₱{{ number_format($item->item_price, 2) }} each</span>
                                </div>
                                <span class="item-subtotal shrink-0 font-display text-sm font-black text-peach-deep sm:text-base">₱{{ number_format($item->subtotal, 2) }}</span>
                            </li>
                            @endforeach
                        </ul>
                    </section>
                </div>

                {{-- RIGHT: summary --}}
                <div class="grid gap-4 lg:sticky lg:top-28">
                    <section class="order-summary card-surface p-4 sm:p-6">
                        <h2 class="mb-3 font-display text-base font-black tracking-tight text-peach-deep sm:text-lg">Order Summary</h2>

                        <div class="summary-row flex items-center justify-between gap-3 py-1.5 text-sm">
                            <span class="label text-peach-deep/50">Payment</span>
                            <span class="value font-semibold text-peach-deep">{{ ucfirst($currentOrder->payment_method ?? 'Cash') }}</span>
                        </div>
                        <div class="summary-row flex items-center justify-between gap-3 py-1.5 text-sm">
                            <span class="label text-peach-deep/50">Subtotal ({{ $currentOrder->items->count() }} items)</span>
                            <span class="value font-semibold text-peach-deep">₱{{ number_format($currentOrder->subtotal, 2) }}</span>
                        </div>
                        @if($currentOrder->discount_amount > 0)
                        <div class="summary-row discount flex items-center justify-between gap-3 py-1.5 text-sm">
                            <span class="label text-green-600">{{ $currentOrder->discountDisplayLabel() ?: 'Discount' }}</span>
                            <span class="value font-semibold text-green-600">-₱{{ number_format($currentOrder->discount_amount, 2) }}</span>
                        </div>
                        @endif
                        <div class="summary-row total mt-2 flex items-center justify-between gap-3 border-t border-peach-soft pt-3">
                            <span class="label font-display text-base font-black text-peach-deep">Total</span>
                            <span class="value font-display text-xl font-black text-peach-red">₱{{ number_format($currentOrder->total, 2) }}</span>
                        </div>

                        {{--
                            Self-service cancellation, added 2026-09-02.

                            Shown ONLY while this order is still 'pending'. Once
                            the kitchen has started (preparing/serving) or the
                            order is closed, the customer no longer gets to
                            withdraw it on their own — food and staff time have
                            been spent by then, so it becomes a conversation with
                            the counter instead. Note this is a per-order check,
                            not a per-page one: a visitor can hold several open
                            orders at once, and only the pending ones may be
                            cancelled.

                            Hiding the button is convenience, not the control —
                            cancelCustomerOrder() re-checks ownership and the
                            pending window server-side, because this route is one
                            guessable integer away from a direct POST.

                            Plain form + confirm(), matching the cart's Remove
                            button, rather than the fetch/JSON path the
                            discount-rejection popup uses: there is no live state
                            to keep in sync here, so a normal POST and redirect
                            is the simpler, harder-to-break option.
                        --}}
                        @php
                            $cancelIsPending = $currentOrder->status === 'pending';

                            /*
                             * PICK-UP ONLY (2026-09-02). The control used to be
                             * removed from the page entirely once an order left
                             * pending — see updateOrderProgress() in the script
                             * below for why that existed and what replaces it
                             * here. The owner has since decided the control
                             * should stay VISIBLE but become disabled, with a
                             * reason, so the customer understands why rather
                             * than watching a button vanish. Dine-in and every
                             * other type keep the exact old behaviour: nothing
                             * renders once the order leaves pending, and the
                             * poll still removes the form outright.
                             *
                             * This does not change WHICH statuses may be
                             * cancelled or touch cancelCustomerOrder() at all —
                             * the server still refuses a non-pending cancel on
                             * its own; hiding or disabling this control is
                             * convenience, never the control.
                             */
                            $cancelIsPickup = $currentOrder->type === 'pick_up';

                            $cancelShowDisabled = $cancelIsPickup
                                && in_array($currentOrder->status, ['preparing', 'serving'], true);
                        @endphp

                        @if($cancelIsPending || $cancelShowDisabled)
                            @php
                                // The customer already tapped "I have paid", so
                                // money may really have moved and a refund has to
                                // be arranged by hand. Warn before they commit,
                                // not after — see cancelCustomerOrder(). Only
                                // relevant while the cancel is actually still
                                // possible — once disabled, the reason below
                                // replaces it.
                                $cancelNeedsRefund = $cancelIsPending
                                    && $currentOrder->payment_method === 'gcash'
                                    && $currentOrder->payment_status === 'awaiting_verification';

                                $cancelPrompt = $cancelNeedsRefund
                                    ? 'Cancel order #' . $currentOrder->order_number . '? You marked this as paid, '
                                        . 'so our staff will need to refund you manually. Continue?'
                                    : 'Cancel order #' . $currentOrder->order_number . '? This cannot be undone.';

                                // Mirrors cancelReasonFor() in the script below —
                                // same wording, same per-status logic, so the
                                // server-rendered reason and whatever the live
                                // poll swaps in later can never disagree.
                                $cancelDisabledReason = match ($currentOrder->status) {
                                    'preparing' => 'Being prepared',
                                    'serving'   => 'Serving',
                                    default     => 'In progress',
                                } . ' — this order can no longer be cancelled.';
                            @endphp

                            {{-- data-cancel-form lets the poll find this control by
                                 order id. data-cancel-pickup tells it whether a
                                 non-pending transition should disable the control
                                 (pick-up) or remove it outright (every other
                                 type, unchanged from before). --}}
                            <form action="{{ route('customer.orders.cancel', $currentOrder->id) }}"
                                  method="POST"
                                  data-cancel-form="{{ $currentOrder->id }}"
                                  data-cancel-pickup="{{ $cancelIsPickup ? '1' : '0' }}"
                                  data-cancel-disabled="{{ $cancelShowDisabled ? '1' : '0' }}"
                                  data-cancel-status="{{ $currentOrder->status }}"
                                  class="mt-4 border-t border-peach-soft pt-4"
                                  @if(!$cancelShowDisabled)
                                  onsubmit="return confirm('{{ addslashes($cancelPrompt) }}')"
                                  @endif>
                                @csrf

                                <button type="submit"
                                        @if($cancelShowDisabled) disabled aria-disabled="true" @endif
                                        class="inline-flex w-full items-center justify-center gap-2 rounded-full border border-peach-soft px-5 py-2.5 text-sm font-bold transition
                                               {{ $cancelShowDisabled
                                                    ? 'bg-peach-soft/40 text-peach-deep/35 cursor-not-allowed'
                                                    : 'bg-white text-peach-deep/60 hover:border-peach-red hover:text-peach-red' }}"
                                        aria-label="Cancel order {{ $currentOrder->order_number }}">
                                    <i class="bi bi-x-circle" aria-hidden="true"></i>
                                    Cancel Order
                                </button>

                                @if($cancelShowDisabled)
                                    <p data-cancel-reason class="mt-2 text-center text-[0.7rem] font-medium text-peach-deep/45">
                                        {{ $cancelDisabledReason }}
                                    </p>
                                @elseif($cancelNeedsRefund)
                                    <p class="mt-2 text-center text-[0.7rem] font-medium text-peach-deep/45">
                                        You marked this as paid — staff will arrange your refund.
                                    </p>
                                @endif
                            </form>
                        @endif
                    </section>

                    @if($loop->last)
                    <a href="{{ route('customer.game') }}" class="game-btn inline-flex items-center justify-center gap-2 rounded-full bg-peach px-5 py-3 text-sm font-bold text-white no-underline transition hover:bg-peach-red">
                        <i class="bi bi-dice-5"></i> Play Game While You Wait
                    </a>

                    <p class="text-center text-[0.7rem] font-medium text-peach-deep/40">
                        <i class="bi bi-arrow-repeat"></i> This page refreshes automatically
                    </p>
                    @endif
                </div>
            </div>
            @endforeach

            {{-- Pick-up customers can't use "Request Assistance" (it calls a
                 server to a table), so give them the shop's real contact
                 details here instead. Shown once if any active order is pick-up. --}}
            @if(collect($currentOrders)->contains(fn($o) => $o->type !== 'dine_in'))
                @include('partials.pickup-store-contact')
            @endif

            @else
            <div class="empty-state card-surface grid place-items-center px-6 py-14 text-center">
                <i class="bi bi-bag-check block text-5xl text-peach/40"></i>
                <p class="mt-3 text-base font-semibold text-peach-deep/55">No active order right now</p>
                <a href="{{ route('customer.menu') }}" class="mt-4 inline-flex items-center gap-2 rounded-full bg-peach-red px-5 py-2.5 text-sm font-bold text-white no-underline transition hover:bg-peach-deep">
                    <i class="bi bi-grid"></i> Browse Menu
                </a>
            </div>
            @endif
        </div>

        {{-- ══════════ TAB 2: Order History ══════════ --}}
        @if(Auth::guard('customer')->check())
<div id="historyTab" @if($initialTab !== 'history') style="display:none;" @endif>
            @if(isset($orderHistory) && count($orderHistory) > 0)
            @php
            $grouped = $orderHistory->groupBy(function($order) {
            if ($order->created_at->isToday()) return 'Today';
            if ($order->created_at->isYesterday()) return 'Yesterday';
            return $order->created_at->format('M d, Y');
            });
            @endphp

            @foreach($grouped as $date => $orders)
            <p class="date-group mb-2.5 mt-5 text-[0.68rem] font-bold uppercase tracking-[0.16em] text-peach-deep/45 first:mt-0">{{ $date }}</p>
            <div class="grid gap-2.5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($orders as $order)
                @php
                    $orderRating = $orderRatings[$order->id] ?? null;
                    $canRate = $order->status === 'completed'
                        && in_array($order->type, ['dine_in', 'pick_up'], true);
                @endphp
                <div class="history-card card-surface grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 p-3 transition hover:border-peach hover:shadow-md sm:p-4">
                    <div class="history-icon {{ $order->status === 'cancelled' ? 'cancelled' : 'completed' }} grid h-10 w-10 shrink-0 place-items-center rounded-xl {{ $order->status === 'cancelled' ? 'bg-peach-red' : 'bg-green-500' }}">
                        <i class="bi {{ $order->status === 'cancelled' ? 'bi-x-lg' : 'bi-check2' }} text-base text-white"></i>
                    </div>
                    <div class="history-info min-w-0">
                        <a href="{{ route('customer.receipt', $order->id) }}" class="block no-underline">
                            <span class="history-order-num block truncate text-sm font-bold text-peach-deep">{{ $order->order_number }}</span>
                            <span class="history-meta block truncate text-[0.7rem] font-medium text-peach-deep/50">{{ $order->items_count }} item(s) • {{ strtoupper($order->status) }}</span>
                            @if($order->discount_amount > 0)
                            <span class="history-discount block truncate text-[0.66rem] font-semibold text-green-600">Discount: -₱{{ number_format($order->discount_amount, 2) }}</span>
                            @endif
                            <span class="history-time block text-[0.64rem] text-peach-deep/35">{{ $order->created_at->format('h:i A') }}</span>
                        </a>
                        @if($canRate && !$orderRating)
                        <span class="rating-needed"><i class="bi bi-star-fill"></i> Meal not rated yet</span>
                        @elseif($orderRating)
                        <span class="rating-needed" style="background:#E4F2EA;color:#2E7D5B;">
                            <i class="bi bi-check-circle-fill"></i> Rated {{ $orderRating->rating }}/5
                        </span>
                        @endif
                    </div>
                    <div class="history-right shrink-0 text-right">
                        <span class="history-amount block font-display text-base font-black text-peach-deep">₱{{ number_format($order->total, 2) }}</span>
                        <a href="{{ route('customer.receipt', $order->id) }}" class="history-receipt block text-[0.64rem] font-bold text-peach-red">Receipt →</a>
                        @if($canRate && !$orderRating)
                        <button type="button" class="history-rating-btn mt-1"
                            onclick="openRatingModal({{ $order->id }}, @js($order->order_number))">
                            Rate Meal
                        </button>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
            @endforeach
            @else
            <div class="empty-state card-surface grid place-items-center px-6 py-14 text-center">
                <i class="bi bi-clock-history block text-5xl text-peach/40"></i>
                <p class="mt-3 text-base font-semibold text-peach-deep/55">No order history yet</p>
                <a href="{{ route('customer.menu') }}" class="mt-4 inline-flex items-center gap-2 rounded-full bg-peach-red px-5 py-2.5 text-sm font-bold text-white no-underline transition hover:bg-peach-deep">
                    Start your first order
                </a>
            </div>
            @endif
        </div>
@endif
    </main>


    {{-- ================= MEAL RATING MODAL ================= --}}
    <div id="ratingModal" aria-hidden="true"
         style="position:fixed;inset:0;z-index:5000;display:none;align-items:center;justify-content:center;padding:1rem;background:rgba(45,24,20,.55);">
        <div role="dialog" aria-modal="true"
             style="width:min(100%,390px);background:#FFFDF9;border-radius:20px;overflow:hidden;box-shadow:0 24px 70px rgba(45,24,20,.28);">
            <div style="padding:1.15rem;background:#FDE8DE;text-align:center;">
                <div style="font-size:2rem;">⭐</div>
                <h3 style="margin:.45rem 0 0;color:#8B1A1A;font-size:1.15rem;">How was your meal?</h3>
                <p id="ratingModalOrder" style="margin:.25rem 0 0;color:#8A6A61;font-size:.7rem;"></p>
            </div>
            <div style="padding:1.1rem;">
                <form id="ratingForm" method="POST">
                    @csrf
                    <input type="hidden" name="rating_type" id="ratingType" value="stars">
                    <input type="hidden" name="rating" id="ratingValue" value="">

                    <div style="display:flex;justify-content:center;gap:.35rem;margin:.85rem 0 1rem;">
                        @for($i = 1; $i <= 5; $i++)
                        <button type="button" class="rating-option" data-value="{{ $i }}"
                                onclick="selectRating({{ $i }})"
                                style="width:52px;height:52px;border:1px solid #ead8d2;background:#fff;border-radius:14px;cursor:pointer;font-size:1.65rem;">
                            <span class="rating-symbol">⭐</span>
                        </button>
                        @endfor
                    </div>

                    <div id="ratingSelectedText" style="text-align:center;font-size:.7rem;color:#8A6A61;min-height:1rem;">
                        Choose a rating
                    </div>

                    <button type="submit" id="ratingSubmit" disabled
                            style="width:100%;margin-top:1rem;border:0;border-radius:10px;padding:.7rem;background:#C0392B;color:#fff;font-size:.78rem;font-weight:800;">
                        Submit Rating
                    </button>
                    <button type="button" onclick="closeRatingModal()"
                            style="width:100%;margin-top:.45rem;border:1px solid #ead8d2;border-radius:10px;padding:.65rem;background:#fff;color:#777;font-size:.72rem;font-weight:700;">
                        Not now
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        let selectedRatingScale = 'stars';

        function openRatingModal(orderId, orderNumber, autoOpen = false) {
            const modal = document.getElementById('ratingModal');
            const form = document.getElementById('ratingForm');
            if (!modal || !form) return;

            form.action = "{{ url('/customer/orders') }}/" + orderId + "/rating";
            document.getElementById('ratingModalOrder').textContent = 'Order #' + orderNumber;
            document.getElementById('ratingValue').value = '';
            document.getElementById('ratingSubmit').disabled = true;
            document.getElementById('ratingSelectedText').textContent = 'Choose a rating';
            setRatingScale('stars', false);
            document.querySelectorAll('.rating-option').forEach(o => o.classList.remove('selected'));

            modal.classList.add('is-open');
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            if (autoOpen) localStorage.setItem('pomida_rating_prompt_' + orderId, 'shown');
        }

        function closeRatingModal() {
            const modal = document.getElementById('ratingModal');
            if (!modal) return;
            modal.classList.remove('is-open');
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        function setRatingScale(reset = true) {
            document.getElementById('ratingType').value = 'stars';

            document.querySelectorAll('.rating-symbol').forEach(s => {
                s.textContent = '⭐';
            });

            if (reset) {
                document.getElementById('ratingValue').value = '';
                document.getElementById('ratingSubmit').disabled = true;
                document.getElementById('ratingSelectedText').textContent = 'Choose a rating';
                document.querySelectorAll('.rating-option').forEach(o => {
                    o.classList.remove('selected');
                });
            }
        }

        function selectRating(value) {
            document.getElementById('ratingValue').value = value;
            document.getElementById('ratingSubmit').disabled = false;
            document.querySelectorAll('.rating-option').forEach(o => o.classList.toggle('selected', Number(o.dataset.value) <= value));
            document.getElementById('ratingSelectedText').textContent = value + ' / 5 stars';
        }

        document.getElementById('ratingModal')?.addEventListener('click', function(event) {
            if (event.target === this) closeRatingModal();
        });

    let lastDiscountStatus = null;
    let discountDecisionCompleted = false;

    function showDiscountVerification(status, orderNumber, orderId) {
        // Hard stop: once the customer has chosen Continue Transaction,
        // this page must never show the rejection popup again.
        if (discountDecisionCompleted) {
            const blockedOverlay =
                document.getElementById('discountVerificationNotice');
            if (blockedOverlay) {
                blockedOverlay.style.display = 'none';
            }
            document.body.style.overflow = '';
            return;
        }

        const overlay = document.getElementById('discountVerificationNotice');
        const icon = document.getElementById('discountVerificationIcon');
        const title = document.getElementById('discountVerificationTitle');
        const message = document.getElementById('discountVerificationMessage');
        const actions = document.getElementById('discountVerificationActions');

        if (!overlay || !title || !message) return;

        if (status === 'pending') {
            icon.style.background = '#FDE8DE';
            icon.style.color = '#C0392B';
            icon.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            title.style.color = '#8F2118';
            title.textContent = 'Checking Your Discount Card';
            message.textContent = 'Your PWD or Senior Citizen discount is being checked by our staff. Please wait.';
            actions.style.display = 'none';
            overlay.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            return;
        }

        if (status === 'approved') {
            // The approval acknowledgement is already shown on the Cart page.
            // Do not show the same "Discount Card Accepted" prompt again on Orders.
            if (orderId) {
                try {
                    localStorage.setItem(
                        'peachy_discount_decision_' + orderId + '_approved',
                        '1'
                    );
                } catch (e) {}
            }

            overlay.style.display = 'none';
            document.body.style.overflow = '';
            return;
        }

        if (status === 'rejected') {
            if (discountDecisionCompleted) {
                overlay.style.display = 'none';
                document.body.style.overflow = '';
                return;
            }

            try {
                if (
                    orderId &&
                    localStorage.getItem(
                        'peachy_discount_decision_' +
                        orderId + '_continued'
                    ) === '1'
                ) {
                    overlay.style.display = 'none';
                    document.body.style.overflow = '';
                    return;
                }
            } catch (e) {}

            icon.style.background = '#FDE8DE';
            icon.style.color = '#C0392B';
            icon.innerHTML = '<i class="bi bi-x-circle"></i>';
            title.style.color = '#C0392B';
            title.textContent = 'Discount Card Rejected';
            message.textContent = 'Our staff could not verify the submitted discount card. The discount has been removed and the order is now at the regular price. Would you like to continue your transaction?';
            actions.style.display = 'flex';
            overlay.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }

    let continueRequestInProgress = false;

    window.continueAfterDiscountDecision = async function () {
        const overlay = document.getElementById('discountVerificationNotice');

        if (!trackedOrderId || continueRequestInProgress) return;

        continueRequestInProgress = true;

        const orderIdBeingContinued = trackedOrderId;

        // Lock the popup immediately. The polling request runs every 3 seconds,
        // so waiting for the POST response allows the old "rejected" response
        // to reopen the modal.
        discountDecisionCompleted = true;

        if (overlay) {
            overlay.style.display = 'none';
        }
        document.body.style.overflow = '';

        const actions = document.getElementById('discountVerificationActions');

        // Stop the 3-second poll while changing the server state.
        if (statusPollTimer) {
            clearInterval(statusPollTimer);
            statusPollTimer = null;
        }

        requestInProgress = true;

        if (actions) {
            actions.style.pointerEvents = 'none';
            actions.style.opacity = '0.6';
        }

        try {
            const response = await fetch(
                '{{ url('/customer/orders') }}/' +
                encodeURIComponent(orderIdBeingContinued) +
                '/continue-without-discount',
                {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    credentials: 'same-origin',
                    cache: 'no-store'
                }
            );

            let data = {};
            try {
                data = await response.json();
            } catch (e) {
                throw new Error('The server returned an invalid response.');
            }

            if (!response.ok || !data.success ||
                data.discount_status !== 'approved') {
                throw new Error(
                    data.message || 'Unable to continue the order.'
                );
            }

            // THIS is the important part:
            // remember that this exact order has already been acknowledged.
            // Even if another stale polling response says "rejected", the
            // rejected popup will not be shown again for this order.
            try {
                localStorage.setItem(
                    'peachy_discount_decision_' +
                    orderIdBeingContinued + '_continued',
                    '1'
                );

                localStorage.removeItem(
                    'peachy_discount_decision_' +
                    orderIdBeingContinued + '_rejected'
                );
            } catch (e) {}

            lastDiscountStatus = 'approved';

            if (overlay) {
                overlay.style.display = 'none';
            }

            document.body.style.overflow = '';

            requestInProgress = false;
            continueRequestInProgress = false;

            // Resume polling. The server/session now reports approved,
            // and the local acknowledgement prevents any stale rejected
            // response from reopening the popup.
            if (!statusPollTimer) {
                statusPollTimer = setInterval(
                    pollCustomerOrderStatus,
                    3000
                );
            }

        } catch (error) {
            discountDecisionCompleted = false;

            requestInProgress = false;
            continueRequestInProgress = false;

            if (actions) {
                actions.style.pointerEvents = '';
                actions.style.opacity = '';
            }

            if (!statusPollTimer) {
                statusPollTimer = setInterval(
                    pollCustomerOrderStatus,
                    3000
                );
            }

            alert(error.message || 'Unable to continue the order.');
        }
    };

    let cancelRequestInProgress = false;

    window.cancelAfterDiscountDecision = async function () {
        if (!trackedOrderId || cancelRequestInProgress) return;

        cancelRequestInProgress = true;

        // Stop polling BEFORE the cancellation request. This prevents a
        // rejected response from immediately reopening the popup while the
        // cancellation request is being processed.
        if (statusPollTimer) {
            clearInterval(statusPollTimer);
            statusPollTimer = null;
        }

        requestInProgress = true;

        const orderIdBeingCancelled = trackedOrderId;
        const actions = document.getElementById('discountVerificationActions');
        const overlay = document.getElementById('discountVerificationNotice');

        /*
         * The control is deliberately NOT dimmed or made unclickable here.
         *
         * It used to be, before the request was sent — so the cancel control
         * visibly "reacted" to a cancellation the server had not agreed to
         * and might well refuse, which is exactly how a customer ends up
         * believing an order was cancelled when it was not. Nothing about the
         * control's state may change until the server has actually confirmed.
         *
         * Double-submission is still prevented, by the cancelRequestInProgress
         * flag checked at the top of this function — a guard on the ACTION,
         * which is correct, rather than on the UI, which was not.
         */

        try {
            const response = await fetch(
                '{{ url('/customer/orders') }}/' + encodeURIComponent(orderIdBeingCancelled) + '/cancel',
                {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    credentials: 'same-origin',
                    cache: 'no-store'
                }
            );

            let data = {};
            try {
                data = await response.json();
            } catch (e) {
                throw new Error('The server returned an invalid cancellation response.');
            }

            if (!response.ok || !data.success || data.status !== 'cancelled') {
                throw new Error(data.message || 'Unable to cancel the order.');
            }

            // The server confirmed that THIS order was cancelled. Do not let
            // the browser continue tracking it.
            trackedOrderId = null;

            try {
                localStorage.removeItem(
                    'peachy_discount_decision_' + orderIdBeingCancelled + '_rejected'
                );
            } catch (e) {}

            if (overlay) overlay.style.display = 'none';
            document.body.style.overflow = '';

            // Replace the history entry so Back does not immediately return
            // to the rejected-order page and restart its polling state.
            window.location.replace('{{ route('customer.menu') }}');
        } catch (error) {
            // If cancellation failed, resume polling so the page can recover.
            requestInProgress = false;
            cancelRequestInProgress = false;

            if (actions) {
                actions.style.pointerEvents = '';
                actions.style.opacity = '';
            }

            if (!statusPollTimer) {
                statusPollTimer = setInterval(pollCustomerOrderStatus, 3000);
            }

            alert(error.message || 'Unable to cancel the order.');
        }
    };


        /*
         * Tab panels are rendered CONDITIONALLY, so a panel can legitimately
         * be absent: #historyTab only exists for a signed-in customer, since
         * a guest has no order history to show. The old version dereferenced
         * both panels unconditionally and threw
         *   TypeError: Cannot read properties of null (reading 'style')
         * for a guest, which broke BOTH tabs -- clicking "Current Order" blew
         * up on the #historyTab line before the switch had completed.
         *
         * Resolve the panels first, fall back to the first panel that was
         * actually rendered when the requested one is missing, and only ever
         * touch panels that exist.
         */
        function switchTab(index, btn) {
            const panels = [
                document.getElementById('statusTab'),
                document.getElementById('historyTab'),
            ];

            let target = index;
            if (!panels[target]) {
                target = panels.findIndex(panel => panel);
                if (target === -1) {
                    return; // No panel rendered at all -- nothing to switch to.
                }
            }

            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));

            // Highlight the button for the tab we actually landed on, which is
            // not necessarily the clicked one if we fell back above.
            const activeBtn = target === index
                ? btn
                : document.querySelector('.tab-btn[data-tab-index="' + target + '"]');
            if (activeBtn) {
                activeBtn.classList.add('active');
            }

            panels.forEach((panel, i) => {
                if (panel) {
                    panel.style.display = i === target ? 'block' : 'none';
                }
            });
        }

        // Order status is checked by the AJAX poller below.
        // Do not reload the whole page here: a reload at the same moment
        // staff completes/cancels the order could cause the status change
        // to be missed.

        // Auto-dismiss floating flash toasts so they don't linger
        document.querySelectorAll('[data-toast]').forEach((toast, i) => {
            setTimeout(() => {
                toast.style.transition = 'opacity .4s ease, transform .4s ease';
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-6px)';
                setTimeout(() => toast.remove(), 400);
            }, 4000 + i * 600);
        });
    </script>

{{-- ================= DISCOUNT VERIFICATION ================= --}}
<div id="discountVerificationNotice"
     style="display:none;position:fixed;inset:0;z-index:10001;background:rgba(59,35,32,0.55);align-items:center;justify-content:center;padding:1rem;">
    <div style="width:100%;max-width:330px;background:#FFFDF9;border:1px solid #F2DDD4;border-radius:16px;padding:22px 20px 20px;text-align:center;box-shadow:0 18px 45px rgba(59,35,32,0.22);">
        <div id="discountVerificationIcon"
             style="width:50px;height:50px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;background:#FDE8DE;color:#C0392B;font-size:21px;">
            <i class="bi bi-person-vcard"></i>
        </div>
        <h3 id="discountVerificationTitle"
            style="margin:0 0 9px;color:#8F2118;font-family:'Fraunces',Georgia,serif;font-size:20px;line-height:1.2;font-weight:700;">
            Checking Your Discount Card
        </h3>
        <p id="discountVerificationMessage"
           style="max-width:285px;margin:0 auto;color:#8A6A61;font-size:11px;line-height:1.55;">
            Please wait while our staff verifies your PWD or Senior Citizen discount card.
        </p>
        <div id="discountVerificationActions" style="display:none;gap:8px;margin-top:16px;">
            <button type="button" onclick="continueAfterDiscountDecision()"
                    style="flex:1;height:38px;border-radius:999px;background:#C0392B;color:#fff;border:1px solid #C0392B;font-size:11px;font-weight:700;cursor:pointer;">
                Continue Transaction
            </button>
            <button type="button" onclick="cancelAfterDiscountDecision()"
                    style="flex:1;height:38px;border-radius:999px;background:#FFFDF9;color:#C0392B;border:1px solid #F3BDAE;font-size:11px;font-weight:700;cursor:pointer;">
                Cancel Order
            </button>
        </div>
    </div>
</div>

{{-- ================= ORDER STATUS NOTIFICATION ================= --}}
<div id="orderStatusNotice"
     style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(59,35,32,0.55);align-items:center;justify-content:center;padding:1rem;">
    <div style="width:100%;max-width:316px;background:#FFFDF9;border:1px solid #F2DDD4;border-radius:16px;padding:22px 20px 20px;text-align:center;box-shadow:0 18px 45px rgba(59,35,32,0.22);">
        <div id="orderStatusIcon"
             style="width:46px;height:46px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;background:#E4F2EA;color:#2E7D5B;font-size:20px;">
            <i class="bi bi-check-circle"></i>
        </div>
        <h3 id="orderStatusTitle"
            style="margin:0 0 9px;color:#2E7D5B;font-family:'Fraunces',Georgia,serif;font-size:20px;line-height:1.2;font-weight:700;">
            Order Completed
        </h3>
        <p id="orderStatusMessage"
           style="max-width:275px;margin:0 auto;color:#8A6A61;font-size:10px;line-height:1.5;">
            Your order has been completed.
        </p>
            {{-- In-place rating. The customer can rate right here without
                 going to Order History, and this is the only rating entry a
                 guest has, since guests have no Order History list at all.
                 It posts to the same endpoint the Order History modal uses. --}}
            <div id="popupRatingBlock" style="display:none;margin-top:16px;padding-top:14px;border-top:1px solid #F2DDD4;">
                <p id="popupRatingPrompt" style="margin:0 0 8px;color:#6B4038;font-size:10px;font-weight:700;">
                    How was your meal?
                </p>

                <div id="popupRatingStars" style="display:flex;justify-content:center;gap:5px;">
                    @for($i = 1; $i <= 5; $i++)
                        <button type="button"
                                class="popup-rating-star"
                                data-value="{{ $i }}"
                                aria-label="{{ $i }} out of 5"
                                style="width:34px;height:34px;border:1px solid #ead8d2;background:#fff;border-radius:9px;cursor:pointer;font-size:15px;line-height:1;padding:0;opacity:.45;">
                            ⭐
                        </button>
                    @endfor
                </div>

                <button type="button"
                        id="popupRatingSubmit"
                        onclick="submitPopupRating()"
                        disabled
                        style="width:100%;margin-top:10px;height:34px;border:0;border-radius:999px;background:#C0392B;color:#fff;font-size:10px;font-weight:700;cursor:pointer;opacity:.5;">
                    Submit Rating
                </button>

                <p id="popupRatingMessage" style="margin:8px 0 0;font-size:10px;font-weight:700;line-height:1.4;"></p>
            </div>

            <div id="completedOrderActions" style="display:none;gap:8px;margin-top:16px;">
                <a id="viewCompletedReceipt"
                   href="#"
                   style="flex:1;height:36px;display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:999px;background:#2E7D5B;color:#fff;border:1px solid #2E7D5B;font-size:10px;font-weight:700;text-decoration:none;">
                    <i class="bi bi-receipt"></i> View Receipt
                </a>
                <button type="button"
                        onclick="closeOrderStatusNotice()"
                        style="flex:0 0 auto;height:36px;padding:0 15px;border-radius:999px;background:#fff;color:#8B1A1A;border:1px solid #F2DDD4;font-size:10px;font-weight:700;cursor:pointer;">
                    Close
                </button>
            </div>

            <div id="cancelledOrderActions" style="display:none;margin-top:16px;">
                <button type="button"
                        onclick="closeOrderStatusNotice()"
                        style="width:100%;height:36px;border-radius:999px;background:#C0392B;color:#fff;border:1px solid #C0392B;font-size:10px;font-weight:700;cursor:pointer;">
                    OK
                </button>
            </div>
    </div>
</div>

<script>
(function () {
    let trackedOrderId = @json(session('customer_order_id') ?: session('guest_order_id'));
    let statusPollTimer = null;
    let requestInProgress = false;

    // Second, independent poller — see pollAllOrderStatuses() below for why
    // this exists alongside the single-order one above rather than replacing
    // it: the discount-verification flow above is wired tightly to ONE
    // tracked order and is left completely alone; this one's only job is
    // keeping every card's own tracker live and firing the completion popup
    // for whichever order it belongs to.
    let allOrdersPollTimer = null;
    let allOrdersRequestInProgress = false;

    // The exact set of orders this page rendered tracker cards for. Kept as a
    // fixed list for the life of this page view — see customerOrdersStatus()
    // for why asking about a STATUS FILTER instead of these specific ids would
    // miss the exact moment an order finishes.
    let trackedOrderIds = @json(collect($pollableOrderIds ?? $currentOrders->pluck('id'))->values());

    function notificationKey(orderId, status) {
        return 'peachy_order_status_notified_v2_' + orderId + '_' + status;
    }

    function alreadyNotified(orderId, status) {
        try {
            return localStorage.getItem(notificationKey(orderId, status)) === '1';
        } catch (e) {
            return false;
        }
    }

    function markNotified(orderId, status) {
        try {
            localStorage.setItem(notificationKey(orderId, status), '1');
        } catch (e) {}
    }

    /* ================= IN-PLACE RATING IN THE COMPLETED POPUP =================
     *
     * Posts to the same route as the Order History modal
     * (POST /customer/orders/{id}/rating). All of "may this be rated, and by
     * whom" is decided server-side by CustomerOrderAccess, so this control
     * cannot grant a rating the other entry point would refuse.
     */
    let popupRatingOrderId = null;
    let popupRatingValue = 0;

    function popupRatingEls() {
        return {
            block: document.getElementById('popupRatingBlock'),
            prompt: document.getElementById('popupRatingPrompt'),
            submit: document.getElementById('popupRatingSubmit'),
            message: document.getElementById('popupRatingMessage'),
        };
    }

    function paintPopupStars(value) {
        document.querySelectorAll('.popup-rating-star').forEach(function (star) {
            const on = Number(star.dataset.value) <= value;
            star.style.opacity = on ? '1' : '.45';
            star.style.borderColor = on ? '#C0392B' : '#ead8d2';
            star.style.background = on ? '#FDE8DE' : '#fff';
        });
    }

    function setPopupRating(value) {
        popupRatingValue = value;
        paintPopupStars(value);

        const els = popupRatingEls();
        if (els.submit) {
            els.submit.disabled = false;
            els.submit.style.opacity = '1';
        }
    }

    /** Replace the control with the rating that is already on record. */
    function showPopupRatingAsRated(value) {
        const els = popupRatingEls();
        if (!els.block) return;

        popupRatingValue = value;
        paintPopupStars(value);

        document.querySelectorAll('.popup-rating-star').forEach(function (star) {
            star.disabled = true;
            star.style.cursor = 'default';
        });

        if (els.prompt) els.prompt.textContent = 'You rated this meal';
        if (els.submit) els.submit.style.display = 'none';
        if (els.message) {
            els.message.textContent = value + ' / 5 — thanks for the feedback!';
            els.message.style.color = '#2E7D5B';
        }

        els.block.style.display = 'block';
    }

    function resetPopupRatingControl() {
        const els = popupRatingEls();
        if (!els.block) return;

        popupRatingValue = 0;
        paintPopupStars(0);

        document.querySelectorAll('.popup-rating-star').forEach(function (star) {
            star.disabled = false;
            star.style.cursor = 'pointer';
        });

        if (els.prompt) els.prompt.textContent = 'How was your meal?';
        if (els.submit) {
            els.submit.style.display = '';
            els.submit.disabled = true;
            els.submit.style.opacity = '.5';
            els.submit.textContent = 'Submit Rating';
        }
        if (els.message) els.message.textContent = '';
    }

    /**
     * Ask the server whether this order already carries a rating, then show
     * either the live control or the recorded rating. Asking rather than
     * assuming is what makes reopening the popup behave correctly.
     */
    async function preparePopupRating(orderId) {
        const els = popupRatingEls();
        if (!els.block || !orderId) return;

        popupRatingOrderId = orderId;
        resetPopupRatingControl();
        els.block.style.display = 'none';

        try {
            const res = await fetch(
                '{{ url('/customer/orders') }}/' + encodeURIComponent(orderId) + '/rating',
                {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store'
                }
            );

            if (!res.ok) return;

            const data = await res.json();

            if (data.rated) {
                showPopupRatingAsRated(data.rating);
            } else {
                els.block.style.display = 'block';
            }
        } catch (e) {
            // Offline or blocked: leave the control hidden rather than
            // offering a rating that cannot be submitted.
        }
    }

    document.addEventListener('click', function (event) {
        const star = event.target.closest('.popup-rating-star');
        if (star && !star.disabled) {
            setPopupRating(Number(star.dataset.value));
        }
    });

    window.submitPopupRating = async function () {
        const els = popupRatingEls();

        if (!popupRatingOrderId || popupRatingValue < 1) return;

        els.submit.disabled = true;
        els.submit.textContent = 'Saving…';
        els.message.textContent = '';

        try {
            const res = await fetch(
                '{{ url('/customer/orders') }}/' + encodeURIComponent(popupRatingOrderId) + '/rating',
                {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ rating: popupRatingValue, rating_type: 'stars' })
                }
            );

            const data = await res.json().catch(function () { return {}; });

            if (res.ok && data.ok) {
                showPopupRatingAsRated(data.rating);
                return;
            }

            // Already rated elsewhere (for example via Order History in another
            // tab): show the rating on record instead of an error.
            if (data.rated && data.rating) {
                showPopupRatingAsRated(data.rating);
                return;
            }

            els.message.textContent = data.message || 'Sorry, that rating could not be saved.';
            els.message.style.color = '#B3261E';
            els.submit.disabled = false;
            els.submit.textContent = 'Submit Rating';
        } catch (e) {
            els.message.textContent = 'Sorry, that rating could not be saved.';
            els.message.style.color = '#B3261E';
            els.submit.disabled = false;
            els.submit.textContent = 'Submit Rating';
        }
    };

    window.closeOrderStatusNotice = function () {
        const overlay = document.getElementById('orderStatusNotice');
        if (overlay) overlay.style.display = 'none';
        document.body.style.overflow = '';

        // Keep the guest on the Orders page. A completed order now has a
        // dedicated View Receipt action, so the customer can open/print it
        // without needing an account.
    };

    function showOrderStatusNotice(status, orderNumber, orderId) {
        const overlay = document.getElementById('orderStatusNotice');
        const icon = document.getElementById('orderStatusIcon');
        const title = document.getElementById('orderStatusTitle');
        const message = document.getElementById('orderStatusMessage');
        const completedActions = document.getElementById('completedOrderActions');
        const cancelledActions = document.getElementById('cancelledOrderActions');
        const receiptLink = document.getElementById('viewCompletedReceipt');

        if (!overlay) return;

        if (completedActions) completedActions.style.display = 'none';
        if (cancelledActions) cancelledActions.style.display = 'none';

        const ratingBlock = document.getElementById('popupRatingBlock');
        if (ratingBlock) ratingBlock.style.display = 'none';

        if (status === 'completed') {
            icon.style.background = '#E4F2EA';
            icon.style.color = '#2E7D5B';
            icon.innerHTML = '<i class="bi bi-check-circle"></i>';
            title.style.color = '#2E7D5B';
            title.textContent = 'Order Completed';
            message.textContent = 'Order ' + (orderNumber ? '#' + orderNumber + ' ' : '') +
                'has been completed. Your receipt is ready.';

            if (receiptLink && orderId) {
                receiptLink.href = '{{ url('/customer/receipt') }}/' + encodeURIComponent(orderId);
            }
            if (completedActions) completedActions.style.display = 'flex';

            // Show the star control (or the rating already on record) for the
            // order that just completed.
            preparePopupRating(orderId);
        } else if (status === 'cancelled') {
            icon.style.background = '#FDE8DE';
            icon.style.color = '#C0392B';
            icon.innerHTML = '<i class="bi bi-x-circle"></i>';
            title.style.color = '#C0392B';
            title.textContent = 'Order Cancelled';
            message.textContent = 'Order ' + (orderNumber ? '#' + orderNumber + ' ' : '') +
                'has been cancelled by staff.';
            if (cancelledActions) cancelledActions.style.display = 'block';

            /*
             * Let the customer read the cancellation message before the card
             * goes. Only THIS order's card is removed — an earlier version
             * wiped the entire #statusTab and stopped the poll outright, on
             * the unstated assumption that a visitor could only ever have one
             * order, which deleted a second still-live order's card and
             * silently froze its tracker.
             *
             * The removal itself now lives in removeFinishedOrderCard(), so
             * completed and cancelled orders leave the tab by exactly the
             * same route, and so removal no longer depends on this popup
             * being shown at all — this popup is deduped in localStorage, and
             * an order's card must not stay in Current Order merely because
             * the customer has seen its notification once before.
             */
            setTimeout(function () {
                removeFinishedOrderCard(orderId);
            }, 1800);
        } else {
            return;
        }

        if (orderId) markNotified(orderId, status);
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    // orderId is now required — every card carries its own suffixed ids
    // (see the Blade comment on the wrapper div), so this repaints exactly
    // the one card asked for and leaves every other card alone.
    /**
     * Take a finished order's card out of the Current Order tab.
     *
     * THE BUG THIS FIXES (2026-09-02). Card removal used to live INSIDE
     * showOrderStatusNotice(), and only inside its `cancelled` branch — the
     * `completed` branch showed the popup and prepared the rating control but
     * never touched the card. Worse, that popup is fired only when
     * `!alreadyNotified(...)`, a localStorage dedupe, so whether a completed
     * order left the Current Order tab depended on whether the customer had
     * happened to see a popup before. Removal is a consequence of the
     * order's STATE, not of a notification, so it lives here and is driven by
     * the poll.
     *
     * Reported live: a pick-up order marked completed by admin stayed under
     * Current Order with its tracker frozen on Preparing, and never appeared
     * under History, while the very same poll response drove the completion
     * popup correctly — which is why rating the order worked while the card
     * behind it did not update.
     */
    function removeFinishedOrderCard(orderId) {
        if (orderId === trackedOrderId) {
            trackedOrderId = null;
        }

        // Stop asking about an order that has reached a terminal state.
        trackedOrderIds = trackedOrderIds.filter(
            id => Number(id) !== Number(orderId)
        );

        const card = document.getElementById('orderCard-' + orderId);
        if (card) card.remove();

        const statusTab = document.getElementById('statusTab');
        const anyCardsLeft = statusTab
            ? statusTab.querySelector('[id^="orderCard-"]')
            : null;

        if (anyCardsLeft) return;

        // Nothing left in flight: stop both pollers and show the empty state,
        // exactly as the cancelled path already did.
        if (statusPollTimer) {
            clearInterval(statusPollTimer);
            statusPollTimer = null;
        }

        if (allOrdersPollTimer) {
            clearInterval(allOrdersPollTimer);
            allOrdersPollTimer = null;
        }

        if (statusTab) {
            statusTab.innerHTML = `
                <div class="empty-state card-surface grid place-items-center px-6 py-14 text-center">
                    <i class="bi bi-bag-check block text-5xl text-peach/40"></i>
                    <p class="mt-3 text-base font-semibold text-peach-deep/55">No active order right now</p>
                    <a href="{{ route('customer.menu') }}" class="mt-4 inline-flex items-center gap-2 rounded-full bg-peach-red px-5 py-2.5 text-sm font-bold text-white no-underline transition hover:bg-peach-deep">
                        <i class="bi bi-grid"></i> Browse Menu
                    </a>
                </div>
            `;
        }
    }

    /**
     * Same per-status wording as $cancelDisabledReason in the Blade above —
     * kept in one matching pair rather than one shared source, the same way
     * this file already mirrors other server rules in JS (see
     * discountCardExpirationError() mirroring the server's expiry check).
     * Whichever one paints the "disabled" state first, the text must read
     * the same either way.
     */
    function cancelReasonFor(status) {
        var reason = status === 'preparing'
            ? 'Being prepared'
            : (status === 'serving' ? 'Serving' : 'In progress');

        return reason + ' — this order can no longer be cancelled.';
    }

    /**
     * Switch a pick-up order's cancel control from working to disabled, IN
     * PLACE — this replaces the old cancelForm.remove() for pick-up orders.
     * The control stays visible so the customer sees why they can no longer
     * cancel instead of watching a button vanish.
     *
     * Idempotent per status: re-running this for a status it has already
     * rendered is a no-op, but a further transition (preparing -> serving)
     * still updates the reason text — the control does not go stale while
     * the page stays open.
     */
    function disableCancelForm(form, status) {
        if (form.dataset.cancelDisabled === '1' && form.dataset.cancelStatus === status) {
            return;
        }

        form.dataset.cancelDisabled = '1';
        form.dataset.cancelStatus = status;

        // A disabled button cannot submit at all, natively — this is what
        // makes "not a working submit" true regardless of the confirm()
        // handler below, not an additional safeguard on top of it.
        form.onsubmit = function () { return false; };

        var btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.setAttribute('aria-disabled', 'true');
            btn.classList.remove('bg-white', 'text-peach-deep/60', 'hover:border-peach-red', 'hover:text-peach-red');
            btn.classList.add('bg-peach-soft/40', 'text-peach-deep/35', 'cursor-not-allowed');
        }

        var reasonEl = form.querySelector('[data-cancel-reason]');
        if (!reasonEl) {
            reasonEl = document.createElement('p');
            reasonEl.setAttribute('data-cancel-reason', '');
            reasonEl.className = 'mt-2 text-center text-[0.7rem] font-medium text-peach-deep/45';
            form.appendChild(reasonEl);
        }
        reasonEl.textContent = cancelReasonFor(status);
    }

    /**
     * The helper line under the tracker — mirrors the PHP match() in the Blade
     * above so a live status change reads the same as a fresh page load.
     * `type` is 'pick_up' / 'dine_in' / 'walk_in'; anything not dine-in is
     * treated as pick-up wording.
     */
    function orderHelperTextFor(status, type) {
        var isPickup = type !== 'dine_in';

        if (status === 'pending')   return "We've got your order — waiting for the kitchen to start.";
        if (status === 'preparing') return 'Your food is being prepared. Hang tight!';
        if (status === 'serving') {
            return isPickup
                ? 'Your order is ready! Please proceed to the counter for pick-up.'
                : 'Your order has been served. Enjoy your meal!';
        }
        return 'Your order is complete. Thank you!';
    }

    function updateOrderHelper(orderId, status, type) {
        var el = document.getElementById('orderHelper-' + orderId);
        if (!el) return;

        var resolvedType = type || el.dataset.orderType || 'pick_up';
        var span = el.querySelector('[data-helper-text]');
        if (span) span.textContent = orderHelperTextFor(status, resolvedType);
    }

    function updateOrderProgress(orderId, status, type) {
        /*
         * Terminal states first, BEFORE the tracker-element guard below.
         *
         * This function used to end at `if (!['pending','preparing','serving']
         * .includes(status)) return;` — so a poll reporting 'completed' or
         * 'cancelled' was silently discarded and the card was left exactly as
         * the server had rendered it, frozen mid-tracker, in a tab it no
         * longer belonged in. The status was never unrecognised on the
         * server: /customer/orders-status reports it correctly and a fresh
         * page load files the order under History correctly. Only this
         * consumer ignored it.
         */
        if (status === 'completed' || status === 'cancelled') {
            removeFinishedOrderCard(orderId);
            return;
        }

        /*
         * Still in flight, but possibly no longer cancellable. The Cancel
         * Order control is rendered once, server-side, for a 'pending' order
         * and was never re-evaluated afterwards — so a page left open while
         * staff started the order kept offering a cancel the server could
         * only refuse. This does not change WHICH statuses may be
         * cancelled; the server remains the only authority and still
         * refuses on its own.
         *
         * PICK-UP (2026-09-02): the control used to be removed outright here
         * — data-cancel-pickup is how this branch tells the two apart, set
         * server-side from the same $currentOrder->type the Blade above
         * decided with. The owner wants a pick-up customer to see WHY they
         * can no longer cancel rather than watch the button disappear, so a
         * pick-up order is disabled in place instead.
         *
         * Every other order type — dine-in included — keeps the exact old
         * behaviour: removed outright, nothing left to explain.
         */
        if (status !== 'pending') {
            const cancelForm = document.querySelector(
                '[data-cancel-form="' + orderId + '"]'
            );

            if (cancelForm) {
                if (cancelForm.dataset.cancelPickup === '1') {
                    disableCancelForm(cancelForm, status);
                } else {
                    cancelForm.remove();
                }
            }
        }

        const steps = {
            pending: document.getElementById('orderStepPending-' + orderId),
            preparing: document.getElementById('orderStepPreparing-' + orderId),
            serving: document.getElementById('orderStepServing-' + orderId)
        };

        const labels = {
            pending: document.getElementById('orderLabelPending-' + orderId),
            preparing: document.getElementById('orderLabelPreparing-' + orderId),
            serving: document.getElementById('orderLabelServing-' + orderId)
        };

        const lines = {
            preparing: document.getElementById('orderLinePreparing-' + orderId),
            serving: document.getElementById('orderLineServing-' + orderId)
        };

        // No elements for this id — the order is not one of the visible
        // tracker cards (e.g. it already completed and dropped off the
        // Current Order tab). Nothing to repaint; not an error.
        if (!steps.pending || !steps.preparing || !steps.serving) return;
        if (!['pending', 'preparing', 'serving'].includes(status)) return;

        const levels = { pending: 1, preparing: 2, serving: 3 };
        const level = levels[status];

        Object.values(steps).forEach(el => {
            el.classList.remove('active', 'done');
        });
        Object.values(labels).forEach(el => {
            el.classList.remove('active', 'done');
        });
        Object.values(lines).forEach(el => {
            el.classList.remove('done');
        });

        if (level >= 1) {
            steps.pending.classList.add(level > 1 ? 'done' : 'active');
            labels.pending.classList.add(level > 1 ? 'done' : 'active');
        }
        if (level >= 2) {
            lines.preparing.classList.add('done');
            steps.preparing.classList.add(level > 2 ? 'done' : 'active');
            labels.preparing.classList.add(level > 2 ? 'done' : 'active');
        }
        if (level >= 3) {
            lines.serving.classList.add('done');
            steps.serving.classList.add('active');
            labels.serving.classList.add('active');
        }

        updateOrderHelper(orderId, status, type);
    }

    async function pollCustomerOrderStatus() {
        if (requestInProgress || cancelRequestInProgress) return;
        requestInProgress = true;

        try {
            let url = '{{ route("customer.order-status") }}';
            if (trackedOrderId) {
                url += '?order_id=' + encodeURIComponent(trackedOrderId);
            }

            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                cache: 'no-store'
            });

            if (!response.ok) return;

            const data = await response.json();
            if (!data.has_order) return;

            trackedOrderId = data.order_id;

            // Update the visible Pending -> Preparing -> Ready/Served tracker
            // and its helper text immediately when staff changes the order
            // status. Only ever the one order this single-order poll is
            // tracking — pollAllOrderStatuses() below keeps every OTHER card live.
            updateOrderProgress(data.order_id, data.status, data.type);

            // Restore the PWD/Senior discount verification prompt.
            // If Continue Transaction was already clicked on this page,
            // NEVER evaluate/reopen the discount popup again.
            if (discountDecisionCompleted) {
                const lockedOverlay =
                    document.getElementById('discountVerificationNotice');

                if (lockedOverlay) {
                    lockedOverlay.style.display = 'none';
                }

                document.body.style.overflow = '';
            } else if (
                data.status !== 'completed' &&
                data.status !== 'cancelled'
            ) {

                const discountStatus = data.discount_status || 'approved';
                const hasDiscount = Number(data.discount_amount || 0) > 0 ||
                    ['pending', 'rejected'].includes(discountStatus);

                let customerAlreadyContinued = false;

                try {
                    customerAlreadyContinued =
                        localStorage.getItem(
                            'peachy_discount_decision_' +
                            data.order_id + '_continued'
                        ) === '1';
                } catch (e) {}

                // Never reopen the rejected popup after the customer has
                // already chosen Continue Transaction for this order.
                if (customerAlreadyContinued) {
                    lastDiscountStatus = 'approved';

                    const discountOverlay =
                        document.getElementById(
                            'discountVerificationNotice'
                        );

                    if (discountOverlay) {
                        discountOverlay.style.display = 'none';
                    }

                    document.body.style.overflow = '';
                } else if (
                    hasDiscount &&
                    discountStatus !== lastDiscountStatus
                ) {
                    lastDiscountStatus = discountStatus;

                    let alreadyAcknowledged = false;

                    if (discountStatus !== 'pending') {
                        try {
                            alreadyAcknowledged =
                                localStorage.getItem(
                                    'peachy_discount_decision_' +
                                    data.order_id + '_' +
                                    discountStatus
                                ) === '1';
                        } catch (e) {}
                    }

                    if (!alreadyAcknowledged) {
                        showDiscountVerification(
                            discountStatus,
                            data.order_number,
                            data.order_id
                        );
                    }
                }

            } else {

                // Order is finished/cancelled, so make sure the
                // discount popup cannot remain visible.
                const discountOverlay =
                    document.getElementById('discountVerificationNotice');

                if (discountOverlay) {
                    discountOverlay.style.display = 'none';
                }

                document.body.style.overflow = '';
            }

            if ((data.status === 'completed' || data.status === 'cancelled') &&
                !alreadyNotified(data.order_id, data.status)) {
                showOrderStatusNotice(data.status, data.order_number, data.order_id);
            }
        } catch (error) {
            // Retry on the next interval.
        } finally {
            requestInProgress = false;
        }
    }

    /**
     * Every order the visitor currently has open, kept in sync.
     *
     * THE BUG THIS FIXES
     * -------------------
     * pollCustomerOrderStatus() above answers for exactly ONE order — whichever
     * id session('customer_order_id') holds, which is silently overwritten to
     * the JUST-PLACED order every time the customer checks out again. Reported
     * live: a customer's SECOND order sat frozen on "Pending" on this page
     * while the admin board correctly showed it moving through Preparing and
     * Serving — the first order (now the "tracked" one) updated fine; the
     * second was never even asked about, because nothing on this page knew it
     * existed once the session pointer moved on.
     *
     * This calls the new bulk endpoint (customer.orders-status), which answers
     * for the visitor's WHOLE set — every in-flight order for a logged-in
     * customer, or every order this guest session has ever placed — and
     * repaints EVERY card whose id it recognises via updateOrderProgress(),
     * then fires the existing one-time completion popup
     * (showOrderStatusNotice + alreadyNotified/markNotified, unchanged, still
     * keyed per order id + status) for any order that just finished.
     *
     * Deliberately a SEPARATE poller from pollCustomerOrderStatus() above,
     * not a replacement for it: that one is wired tightly into the PWD/Senior
     * discount-verification flow for one specific order, and this task is not
     * touching that logic. The two run independently; showOrderStatusNotice's
     * own per-order dedupe means it is harmless if both happen to notice the
     * same order finishing on the same tick.
     */
    async function pollAllOrderStatuses() {
        if (allOrdersRequestInProgress) return;
        if (!trackedOrderIds.length) return;
        allOrdersRequestInProgress = true;

        try {
            // Ask about these EXACT ids, not "whatever is in flight right
            // now" — see the note on customerOrdersStatus() for why a status
            // filter would miss the one poll that needed to see the
            // transition to completed/cancelled.
            const params = trackedOrderIds
                .map(id => 'order_ids[]=' + encodeURIComponent(id))
                .join('&');

            const response = await fetch(
                '{{ route("customer.orders-status") }}?' + params,
                {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-store'
                }
            );

            if (!response.ok) return;

            const data = await response.json();
            const orders = Array.isArray(data.orders) ? data.orders : [];

            orders.forEach(function (order) {
                updateOrderProgress(order.order_id, order.status, order.type);

                if ((order.status === 'completed' || order.status === 'cancelled') &&
                    !alreadyNotified(order.order_id, order.status)) {
                    showOrderStatusNotice(order.status, order.order_number, order.order_id);
                }
            });
        } catch (error) {
            // Retry on the next interval.
        } finally {
            allOrdersRequestInProgress = false;
        }
    }

    // Check immediately, then every 3 seconds. This works even if the
    // order was completed/cancelled before this page was opened.
    pollCustomerOrderStatus();
    statusPollTimer = setInterval(pollCustomerOrderStatus, 3000);

    pollAllOrderStatuses();
    allOrdersPollTimer = setInterval(pollAllOrderStatuses, 3000);

    window.addEventListener('beforeunload', function () {
        if (statusPollTimer) clearInterval(statusPollTimer);
        if (allOrdersPollTimer) clearInterval(allOrdersPollTimer);
    });

    // Restore scrolling after the popup is acknowledged.
    const originalClose = window.closeOrderStatusNotice;
    window.closeOrderStatusNotice = function () {
        originalClose();
        document.body.style.overflow = '';
    };
})();
</script>

    @include('customer.partials.navbar')
</body>

</html>

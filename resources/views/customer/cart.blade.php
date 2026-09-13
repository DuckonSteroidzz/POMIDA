<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cart - Peachy</title>

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
            [hidden] { display: none !important; }
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
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type=number] { -moz-appearance: textfield; }

        /* ================= ORDER CONFIRMATION ================= */
        .order-confirm-overlay {
            position: fixed;
            inset: 0;
            background: rgba(59, 35, 32, 0.50);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            padding: 1rem;
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
        }

        .order-confirm-modal {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border: 1px solid #FDE8DE;
            border-radius: 1.5rem;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 20px 60px rgba(139, 26, 26, 0.22);
            animation: orderModalIn 0.22s ease-out;
        }

        @keyframes orderModalIn {
            from {
                opacity: 0;
                transform: translateY(10px) scale(0.97);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .order-confirm-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 1rem;
            border-radius: 999px;
            display: grid;
            place-items: center;
            background: #FDE8DE;
            color: #C0392B;
            font-size: 1.6rem;
        }

        .order-confirm-modal h3 {
            color: #8B1A1A;
            font-family: 'Fraunces', serif;
            font-size: 1.35rem;
            font-weight: 900;
            margin: 0;
        }

        .order-confirm-modal p {
            color: rgba(59, 35, 32, 0.65);
            font-size: 0.875rem;
            line-height: 1.5;
            margin: 0.5rem 0 0;
        }

        /* ===== Real order contents inside the confirm modal ===== */

        .order-review {
            margin-top: 0.9rem;
            border: 1px solid #FDE8DE;
            border-radius: 0.9rem;
            background: #FFFDF9;
            text-align: left;
            overflow: hidden;
        }

        .order-review-items {
            list-style: none;
            margin: 0;
            /* A long order must not push the confirm/cancel buttons off screen. */
            max-height: 34vh;
            overflow-y: auto;
            padding: 0.35rem 0.75rem;
        }

        .order-review-item {
            padding: 0.55rem 0;
            border-bottom: 1px dashed #FDE8DE;
        }

        .order-review-item:last-child {
            border-bottom: 0;
        }

        /*
         * Thumbnail + text, added 2026-09-02. Every other place that lists
         * order items (Orders page "Items in this order") already shows one;
         * this modal was text-only. Same visual language as that page's
         * .item-img — rounded box, object-cover photo, bi-image fallback for
         * an item with no photo on file — just sized down to fit this modal's
         * denser rows instead of copying its larger 14-16 unit box.
         */
        .order-review-item-row {
            display: flex;
            align-items: flex-start;
            gap: 0.55rem;
        }

        .order-review-img {
            flex: none;
            width: 2.3rem;
            height: 2.3rem;
            border-radius: 0.6rem;
            overflow: hidden;
            display: grid;
            place-items: center;
            background: #FDE8DE;
        }

        .order-review-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .order-review-img i {
            font-size: 0.95rem;
            color: rgba(244, 132, 95, 0.6);
        }

        .order-review-item-body {
            min-width: 0;
            flex: 1 1 auto;
        }

        .order-review-item-main {
            display: flex;
            align-items: baseline;
            gap: 0.45rem;
        }

        .order-review-qty {
            flex: none;
            min-width: 1.9rem;
            font-weight: 900;
            font-size: 0.82rem;
            color: #C0392B;
            font-variant-numeric: tabular-nums;
        }

        .order-review-name {
            flex: 1 1 auto;
            font-weight: 700;
            font-size: 0.86rem;
            color: #5A2920;
            line-height: 1.3;
        }

        .order-review-line {
            flex: none;
            font-weight: 800;
            font-size: 0.86rem;
            color: #5A2920;
            font-variant-numeric: tabular-nums;
        }

        .order-confirm-modal .order-review-options {
            margin: 0.2rem 0 0 2.35rem;
            font-size: 0.74rem;
            font-weight: 600;
            color: #9C4A2E;
            line-height: 1.35;
        }

        .order-confirm-modal .order-review-unit {
            margin: 0.15rem 0 0 2.35rem;
            font-size: 0.72rem;
            color: #7F5148;
        }

        .order-review-totals {
            border-top: 1px solid #FDE8DE;
            background: #FFF6F1;
            padding: 0.6rem 0.85rem;
        }

        .order-review-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            font-size: 0.8rem;
            color: #6B4038;
            padding: 0.12rem 0;
            font-variant-numeric: tabular-nums;
        }

        .order-review-row.order-review-total {
            margin-top: 0.3rem;
            padding-top: 0.4rem;
            border-top: 1px solid #FDE8DE;
            font-size: 0.95rem;
            font-weight: 900;
            color: #5A2920;
        }

        /* Take Out sits between the item list and the totals, in its own
           section styled like .order-review-totals (same border tone, tinted
           background, padding). The inner row is a soft card reusing the
           thumbnail radius (0.6rem) and the same #FDE8DE border tone. */
        .order-review-takeout {
            border-top: 1px solid #FDE8DE;
            background: #FFF6F1;
            padding: 0.6rem 0.85rem;
        }

        .order-review-takeout .order-review-row {
            padding: 0.7rem 0.75rem;
            border: 1px solid #FDE8DE;
            border-radius: 0.6rem;
            background: #FFFDF9;
            cursor: pointer;
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }

        /* Checked "selected" state — same #FDE8DE tint as the PWD/Senior
           .discount-selected buttons, with the #C0392B accent as the border. */
        .order-review-takeout .order-review-row:has(#isTakeoutCheckbox:checked) {
            background: #FDE8DE;
            border-color: #C0392B;
        }

        /* Match the Place Order button background (.order-confirm-btn, #C0392B)
           instead of the browser's default blue checkbox tick. Enlarged from
           the native ~13px so it is comfortably tappable on mobile. */
        #isTakeoutCheckbox {
            accent-color: #C0392B;
            flex: none;
            width: 1.4rem;
            height: 1.4rem;
        }

        #reviewDiscount {
            color: #1E7A3C;
            font-weight: 700;
        }

        .order-confirm-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-top: 1.25rem;
        }

        .order-confirm-actions button {
            min-height: 46px;
            border-radius: 999px;
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.2s ease, border-color 0.2s ease,
                        color 0.2s ease, opacity 0.2s ease, transform 0.2s ease;
        }

        .order-confirm-actions button:active {
            transform: scale(0.98);
        }

        .order-cancel-btn {
            background: #ffffff;
            color: #C0392B;
            border: 1px solid #FDE8DE;
        }

        .order-cancel-btn:hover {
            background: #FDE8DE;
        }

        .order-confirm-btn {
            background: #C0392B;
            color: #ffffff;
            border: 1px solid #C0392B;
        }

        .order-confirm-btn:hover:not(:disabled) {
            background: #8B1A1A;
            border-color: #8B1A1A;
        }

        .order-confirm-btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        @media (max-width: 420px) {
            .order-confirm-modal {
                max-width: 100%;
                padding: 1.35rem;
                border-radius: 1.4rem;
            }

            .order-confirm-actions {
                gap: 0.6rem;
            }

            .order-confirm-actions button {
                min-height: 44px;
                padding-inline: 0.75rem;
            }
        }
    </style>
    <style>
        /* PWD / Senior selected state — same visual language as the nav hover */
        #pwdDiscountBtn.discount-selected,
        #seniorDiscountBtn.discount-selected {
            background-color: #FDE8DE !important;
            border-color: #FDE8DE !important;
            color: #8B1A1A !important;
        }

        #pwdDiscountBtn.discount-selected:hover,
        #seniorDiscountBtn.discount-selected:hover {
            background-color: #FDE8DE !important;
            border-color: #F4845F !important;
            color: #8B1A1A !important;
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

    {{-- ================= FLASH MESSAGES (floating toast) ================= --}}
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

    <main class="mx-auto w-full max-w-6xl px-4 pb-[19rem] pt-4 sm:px-6 sm:pt-6 md:pb-16">

        {{-- Breadcrumb + Back --}}
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-2 text-xs font-semibold text-peach-deep/55">
                <a href="{{ route('customer.menu') }}" class="no-underline transition hover:text-peach-red">Menu</a>
                <i class="bi bi-chevron-right shrink-0 text-[0.6rem]"></i>
                <span class="truncate text-peach-deep">Cart</span>
            </div>

            <button type="button" onclick="window.history.back()"
                class="w-full shrink-0 rounded-full border border-peach-soft bg-white px-5 py-2.5 text-center text-sm font-bold text-peach-red shadow-sm transition hover:bg-peach-soft sm:w-auto">
                <i class="bi bi-arrow-left"></i> Back
            </button>
        </div>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)] lg:gap-8 lg:items-start">

            {{-- ============ LEFT: CART ITEMS ============ --}}
            <section class="grid min-w-0 gap-4 @if(!isset($cart) || count($cart) === 0) lg:col-span-2 lg:mx-auto lg:w-full lg:max-w-2xl @endif">
                <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3">
                    <h1 class="min-w-0 font-display text-2xl font-black leading-tight tracking-tight text-peach-deep sm:text-3xl">Your Cart</h1>
                    @php $cartItemsCount = isset($cart) ? count($cart) : 0; @endphp
                    <span class="shrink-0 rounded-full bg-peach-soft px-3 py-1.5 text-xs font-bold text-peach-red">
                        {{ $cartItemsCount }} {{ $cartItemsCount === 1 ? 'item' : 'items' }}
                    </span>
                </div>

                {{-- The cart is priced from the live menu (App\Support\CartPricing),
                     the same source checkout uses. When that differs from what was
                     cached when the item was added, say so instead of quietly
                     showing a total that will not be the one charged. --}}
                @if(!empty($cartRepriced))
                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-semibold text-amber-800">
                    <i class="bi bi-exclamation-triangle"></i>
                    Some prices changed since you added these items. The amounts below are
                    the current ones, and they are exactly what you will be charged.
                </div>
                @endif

                @if(!empty($cartHasUnavailableItem))
                <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-semibold text-red-700">
                    <i class="bi bi-x-circle"></i>
                    One of the items in your cart is no longer on the menu. Please remove it
                    before placing your order.
                </div>
                @endif

                @if(!empty($cartHasOutOfStockItem))
                <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-semibold text-red-700">
                    <i class="bi bi-x-circle"></i>
                    An item in your cart is currently
                    <span class="whitespace-nowrap">out of stock due to ingredient availability</span>.
                    Please remove it before placing your order.
                </div>
                @endif

                @if(!empty($cartHasNoRecipeItem))
                <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-semibold text-red-700">
                    <i class="bi bi-x-circle"></i>
                    An item in your cart is
                    <span class="whitespace-nowrap">unavailable — no recipe has been set for it</span>.
                    Please remove it before placing your order.
                </div>
                @endif

                @if(isset($cart) && count($cart) > 0)
                @foreach($cart as $itemId => $item)
                @php
                    $lineItemId = (int) ($item['menu_item_id'] ?? $itemId);
                    $lineOutOfStock = in_array($lineItemId, $outOfStockItemIds ?? [], true);
                    $lineNoRecipe = in_array($lineItemId, $noRecipeItemIds ?? [], true);
                    $lineBlocked = $lineOutOfStock || $lineNoRecipe;
                @endphp
                <article class="card-surface p-3.5 sm:p-4">
                    <div class="flex items-start gap-3.5 sm:gap-4">
                        <div class="grid h-20 w-20 shrink-0 place-items-center overflow-hidden rounded-xl bg-peach-soft/60 sm:h-24 sm:w-24">
                            @if(!empty($item['image']))
                            <img src="{{ asset($item['image']) }}" alt="{{ $item['name'] }}" class="h-full w-full object-cover {{ $lineBlocked ? 'opacity-60 grayscale' : '' }}">
                            @else
                            <i class="bi bi-image text-2xl text-peach/60"></i>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3">
                                <h2 class="min-w-0 font-display text-base font-bold leading-snug text-peach-deep sm:text-lg">
                                    {{ $item['name'] }}
                                    @if($lineNoRecipe)
                                    <span class="mt-1 block text-[0.68rem] font-black uppercase tracking-wide text-peach-red">
                                        <i class="bi bi-x-circle"></i> Unavailable — no recipe set — remove to check out
                                    </span>
                                    @elseif($lineOutOfStock)
                                    <span class="mt-1 block text-[0.68rem] font-black uppercase tracking-wide text-peach-red">
                                        <i class="bi bi-x-circle"></i> Out of stock — remove to check out
                                    </span>
                                    @endif
                                </h2>
                                <span class="shrink-0 font-display text-base font-black text-peach-deep sm:text-lg"
                                    data-line-total="{{ $itemId }}">
                                    ₱{{ number_format($item['price'] * $item['quantity'], 2) }}
                                </span>
                            </div>

                            @if(!empty($item['options']))
                            <p class="mt-1 truncate text-xs text-peach-deep/50">
                                {{ implode(', ', array_column($item['options'], 'name')) }}
                            </p>
                            @endif

                            <p class="mt-1 text-xs font-bold text-peach-red">₱{{ number_format($item['price'], 2) }} each</p>

                            {{-- Quantity: updated on screen instantly and synced
                                 to the server in the background (debounced) — no
                                 page navigation, matching the item-details modal's
                                 changeQuantity(). The server still re-validates
                                 stock on Place Order. cartChangeQty()/cartTypeQty()
                                 live in the script at the foot of this file. --}}
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <div class="flex items-center rounded-full border border-peach-soft bg-white p-1">
                                    <button type="button" onclick="cartChangeQty('{{ $itemId }}', -1)"
                                        class="grid h-8 w-8 place-items-center rounded-full text-lg font-bold text-peach-red transition hover:bg-peach-soft" aria-label="Decrease quantity">−</button>
                                    <input type="number" inputmode="numeric" min="1" max="999"
                                        value="{{ $item['quantity'] }}"
                                        data-cart-qty="{{ $itemId }}"
                                        aria-label="Quantity for {{ $item['name'] }}"
                                        class="w-11 rounded-full border-0 bg-transparent px-1 py-1 text-center text-sm font-bold text-peach-deep outline-none focus:bg-peach-soft/60"
                                        oninput="cartTypeQty('{{ $itemId }}', this)"
                                        onchange="cartCommitQty('{{ $itemId }}', this)">
                                    <button type="button" onclick="cartChangeQty('{{ $itemId }}', 1)"
                                        class="grid h-8 w-8 place-items-center rounded-full text-lg font-bold text-peach-red transition hover:bg-peach-soft" aria-label="Increase quantity">+</button>
                                </div>

                                {{-- Remove this item outright.

                                     The route already existed (customer.cart.remove)
                                     but nothing in the UI reached it, so the only way
                                     to get rid of a line was to tap "−" down to zero.
                                     This is one action instead of however many units
                                     happen to be in the cart. --}}
                                <form action="{{ route('customer.cart.remove', $itemId) }}" method="POST" style="display:inline;"
                                    onsubmit="return confirm('Remove {{ addslashes($item['name']) }} from your cart?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                        class="inline-flex items-center gap-1.5 rounded-full border border-peach-soft bg-white px-3 py-1.5 text-xs font-bold text-peach-deep/60 transition hover:border-peach-red hover:text-peach-red"
                                        aria-label="Remove {{ $item['name'] }} from cart">
                                        <i class="bi bi-trash3" aria-hidden="true"></i>
                                        Remove
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </article>
                @endforeach
                @else
                <div class="card-surface grid w-full place-items-center gap-2 px-5 py-12 text-center sm:px-6 sm:py-14">
                    <i class="bi bi-cart-x text-4xl text-peach/60"></i>
                    <p class="font-display text-lg font-bold text-peach-deep">Your cart is empty.</p>
                    <p class="text-sm text-peach-deep/50">Browse the menu and add something sweet.</p>
                    <a href="{{ route('customer.menu') }}" class="mt-2 inline-flex items-center gap-2 rounded-full bg-peach-red px-5 py-2.5 text-sm font-bold text-white no-underline transition hover:bg-peach-deep">
                        <i class="bi bi-grid"></i> Go to menu
                    </a>
                </div>
                @endif
            </section>

            {{-- ============ RIGHT: SUMMARY ============ --}}
            @if(isset($cart) && count($cart) > 0)
            <aside class="card-surface p-4 md:p-6 lg:sticky lg:top-28">
                <form action="{{ route('customer.place-order') }}" method="POST" id="mainOrderForm"
                    enctype="multipart/form-data">
                    @csrf

                    @php $idx = 0; @endphp
                    @foreach($cart as $itemId => $item)
                    <input type="hidden" name="items[{{ $idx }}][menu_item_id]" value="{{ $item['menu_item_id'] ?? $itemId }}">
                    <input type="hidden" name="items[{{ $idx }}][quantity]" value="{{ $item['quantity'] }}" data-order-qty="{{ $itemId }}">
                    @php $idx++; @endphp
                    @endforeach

                    @php
                    $sessionOrderType = session('order_type', Auth::check() ? 'pick_up' : 'dine_in');
                    $sessionBranchId = session('branch_id');
                    $sessionBranch = $sessionBranchId ? \App\Models\Branch::find($sessionBranchId) : null;
                    $sessionTable = session('table_number');
                    @endphp

                    <input type="hidden" name="order_type" value="{{ $sessionOrderType }}">
                    <input type="hidden" name="payment_method" id="paymentMethod" value="">
                    <input type="hidden" name="branch_id" value="{{ $sessionBranchId }}">

                    @if($sessionOrderType === 'dine_in')
                    <input type="hidden" name="table_number" value="{{ $sessionTable }}">
                    @endif

                    <h2 class="mb-3 hidden font-display text-lg font-bold text-peach-deep md:block">Order summary</h2>

                    <div class="mb-3 flex items-center justify-center gap-2 rounded-full bg-peach-soft px-3 py-2 text-center text-xs font-bold text-peach-red sm:text-sm">
                        @if($sessionOrderType === 'dine_in')
                        <i class="bi bi-shop"></i> <span class="min-w-0">Dine-in
                        @if($sessionTable) • Table {{ $sessionTable }}@endif
                        @if($sessionBranch) • {{ $sessionBranch->name }}@endif</span>
                        @else
                        <i class="bi bi-bag"></i> <span class="min-w-0">Pickup
                        @if($sessionBranch) • {{ $sessionBranch->name }}@endif</span>
                        @endif
                    </div>

                    @if(!$sessionBranchId)
                    <div class="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-center text-xs font-medium text-amber-800">
                        <i class="bi bi-exclamation-triangle"></i>
                        No branch selected!
                        <a href="{{ route('customer.menu') }}" class="font-bold text-peach-red no-underline hover:underline">Go to menu to select branch →</a>
                    </div>
                    @endif


                    <div class="mb-3">
                        <div class="flex items-center gap-2">
                            {{-- Accepts a promo code AND the PCH-… claim code a
                                 guest is given when they win on the wheel; the
                                 server tells them apart in
                                 VoucherClaims::resolveTypedCode().

                                 The box used to carry nothing but an aria-label,
                                 so on screen it was an unlabelled rounded
                                 rectangle — nothing said it wanted a voucher
                                 code. The placeholder says it, and the icon is
                                 bi-ticket-perforated, already THE voucher icon
                                 across customer/vouchers and admin/vouchers.
                                 The wrapper is `relative` and the input carries
                                 pl-10 so the icon sits inside the field rather
                                 than stealing a third column from a 375px
                                 row. --}}
                            <div class="relative min-w-0 flex-1">
                                <i class="bi bi-ticket-perforated pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-peach-deep/35"
                                   aria-hidden="true"></i>
                                <input type="text" id="voucherInput" aria-label="Voucher or claim code" autocomplete="off"
                                    placeholder="Enter voucher code"
                                    class="w-full rounded-full border border-peach-soft bg-white py-2.5 pl-10 pr-4 text-sm outline-none transition placeholder:text-peach-deep/35 focus:border-peach focus:ring-4 focus:ring-peach/20">
                            </div>
                            <button type="button" onclick="applyVoucher()"
                                class="shrink-0 rounded-full border border-peach-soft bg-white px-4 py-2.5 text-sm font-bold text-peach-red transition hover:bg-peach-soft">Apply</button>
                        </div>
                        {{-- Painted by setVoucherMessage() in the same alert
                             shape showDiscountCardMessage() already uses for the
                             PWD/Senior card, so the two refusals on this page
                             look like one system. --}}
                        <div id="voucherMsg" class="mt-1.5 hidden"></div>
                    </div>

                    <input type="hidden" name="voucher_code_confirmed" id="voucher_code_hidden" value="">
                    <input type="hidden" name="discount_amount" id="discountAmount" value="0">

                    {{-- DISCOUNT CARD --}}
                    <div class="mb-3 rounded-2xl border border-peach-soft bg-peach-soft/30 p-4">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-black text-peach-deep">
                                    <i class="bi bi-person-vcard"></i>
                                    PWD / Senior Citizen Discount
                                </div>
                                <div class="mt-0.5 text-xs text-peach-deep/55">
                                    Have a voucher too? We'll automatically apply whichever
                                    saves you more — they can't be combined.
                                </div>
                            </div>

                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                id="pwdDiscountBtn"
                                onclick="selectDiscountType('pwd')"
                                class="rounded-xl border border-peach-soft bg-white px-4 py-2.5 text-sm font-bold text-peach-red transition hover:bg-peach-soft"
                            >
                                PWD
                            </button>

                            <button
                                type="button"
                                id="seniorDiscountBtn"
                                onclick="selectDiscountType('senior')"
                                class="rounded-xl border border-peach-soft bg-white px-4 py-2.5 text-sm font-bold text-peach-red transition hover:bg-peach-soft"
                            >
                                Senior Citizen
                            </button>
                        </div>

                        <input type="hidden" name="discount_type" id="discountType" value="">

                        <div id="discountCardFields" class="mt-3 hidden space-y-3">
                            <div>
                                <label class="mb-1 block text-xs font-bold text-peach-deep/65">
                                    Beneficiary Name *
                                </label>
                                <input
                                    type="text"
                                    name="discount_beneficiary_name"
                                    id="discountBeneficiaryName"
                                    maxlength="255"
                                    autocomplete="off"
                                    class="w-full rounded-xl border border-peach-soft bg-white px-4 py-2.5 text-sm outline-none focus:border-peach focus:ring-4 focus:ring-peach/20"
                                >
                            </div>

                            <div>
                                <label class="mb-1 block text-xs font-bold text-peach-deep/65">
                                    Discount Card / ID Number *
                                </label>
                                <input
                                    type="text"
                                    name="discount_beneficiary_id"
                                    id="discountBeneficiaryId"
                                    maxlength="100"
                                    autocomplete="off"
                                    class="w-full rounded-xl border border-peach-soft bg-white px-4 py-2.5 text-sm outline-none focus:border-peach focus:ring-4 focus:ring-peach/20"
                                >
                            </div>

                            <div>
                                <label class="mb-1 block text-xs font-bold text-peach-deep/65">
                                    Expiration Date *
                                </label>
                                <input
                                    type="date"
                                    name="discount_beneficiary_expiration"
                                    id="discountBeneficiaryExpiration"
                                    min="{{ now()->format('Y-m-d') }}"
                                    class="w-full rounded-xl border border-peach-soft bg-white px-4 py-2.5 text-sm outline-none focus:border-peach focus:ring-4 focus:ring-peach/20"
                                >
                            </div>

                            <div>
                                <label class="mb-1 block text-xs font-bold text-peach-deep/65">
                                    ID / Discount Card Picture *
                                </label>
                                <input
                                    type="file"
                                    name="discount_beneficiary_image"
                                    id="discountBeneficiaryImage"
                                    accept="image/jpeg,image/png,image/jpg,image/webp"
                                    class="block w-full rounded-xl border border-peach-soft bg-white px-3 py-2 text-xs text-peach-deep"
                                >
                                <p class="mt-1 text-[0.68rem] text-peach-deep/50">
                                    JPG, PNG, or WEBP. Maximum 5 MB.
                                </p>
                            </div>

                            <div id="discountCardMsg" class="hidden rounded-xl px-3 py-2 text-xs font-semibold"></div>
                        </div>
                    </div>


                    <div class="grid gap-1.5 border-t border-peach-soft pt-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-peach-deep/60">Subtotal</span>
                            <span class="text-sm font-bold text-peach-deep" id="summarySubtotal">₱{{ number_format($total, 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3" id="discountRow" style="display:none;">
                            <span class="text-sm text-peach-deep/60">Discount</span>
                            <span class="text-sm font-bold text-green-600" id="discountDisplay">-₱0.00</span>
                        </div>
                        <div class="mt-1 flex items-center justify-between gap-3 border-t border-peach-soft pt-2.5">
                            <span class="text-[0.68rem] font-bold uppercase tracking-[0.16em] text-peach-deep/45">Total</span>
                            <span class="font-display text-xl font-black text-peach-deep sm:text-2xl" id="finalTotal">₱{{ number_format($total, 2) }}</span>
                        </div>
                    </div>

                    <div class="mt-4">
    <div class="mb-2 text-sm font-black text-peach-deep">
        Payment Method
    </div>

    <div class="grid grid-cols-2 gap-2.5">

        <button
            type="button"
            id="cashPaymentBtn"
            onclick="selectPaymentMethod('cash')"
            class="payment-method-btn rounded-2xl border border-peach-soft bg-white px-4 py-3 text-left transition hover:bg-peach-soft"
        >
            <div class="flex items-center gap-2.5">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-green-50 text-green-600">
                    <i class="bi bi-cash-coin"></i>
                </span>

                @php
                /*
                 * The cash option is labelled by WHEN and WHERE the money
                 * changes hands, because "Cash / Pay at the store" was
                 * ambiguous for a pick-up order — it read as though payment
                 * happened before collection.
                 *
                 * There was no separate dine-in cash label to match: one
                 * button served both order types. The existing convention is
                 * simply "bold name + a sub-label saying where you pay", so
                 * that is what is kept, now varying by order type. Only the
                 * pick-up wording gets the acronym the owner asked for; the
                 * dine-in side is left as plain "Cash" because inventing a
                 * second acronym nobody asked for would be worse, not better.
                 *
                 * Recomputed here rather than reusing $sessionOrderType from
                 * the summary block above, so this stays correct if either
                 * block moves. Same expression, same default.
                 */
                $cashOrderType = session('order_type', Auth::check() ? 'pick_up' : 'dine_in');
                @endphp

                <span class="min-w-0">
                    <span class="block text-sm font-bold text-peach-deep">
                        {{ $cashOrderType === 'dine_in' ? 'Cash' : 'Cash on Pick Up (COP)' }}
                    </span>
                    <span class="block text-[0.68rem] text-peach-deep/50">
                        {{ $cashOrderType === 'dine_in' ? 'Pay at your table' : 'Pay when you collect your order' }}
                    </span>
                </span>
            </div>
        </button>

        <button
            type="button"
            id="gcashPaymentBtn"
            onclick="selectPaymentMethod('gcash')"
            class="payment-method-btn rounded-2xl border border-peach-soft bg-white px-4 py-3 text-left transition hover:bg-peach-soft"
        >
            <div class="flex items-center gap-2.5">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600">
                    <i class="bi bi-phone"></i>
                </span>

                <span class="min-w-0">
                    <span class="block text-sm font-bold text-peach-deep">
                        GCash
                    </span>
                    <span class="block text-[0.68rem] text-peach-deep/50">
                        Pay using GCash
                    </span>
                </span>
            </div>
        </button>

    </div>

    <div id="paymentMethodMsg" class="mt-2 hidden rounded-xl bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">
        Please select a payment method.
    </div>
</div>
                    <div class="mt-4 flex items-center gap-2.5">
                        <a href="{{ route('customer.menu') }}"
                            class="shrink-0 rounded-full border border-peach-soft bg-white px-5 py-3 text-sm font-bold text-peach-red no-underline transition hover:bg-peach-soft">Menu</a>
                    <button
                        type="button"
                        onclick="openOrderConfirmation()"
                        @disabled(!empty($cartHasOutOfStockItem) || !empty($cartHasUnavailableItem) || !empty($cartHasNoRecipeItem))
                        class="inline-flex flex-1 items-center justify-center gap-2 rounded-full bg-peach-red px-6 py-3 text-sm font-bold text-white transition hover:bg-peach-deep disabled:cursor-not-allowed disabled:bg-peach-deep/30 disabled:hover:bg-peach-deep/30"
                    >
                        <i class="bi bi-check-circle"></i>
                        Place Order
                    </button>
                    </div>
                </form>
            </aside>
            @endif
        </div>
    </main>



    @include('customer.partials.navbar')

    <div class="order-confirm-overlay" id="orderConfirmOverlay">
        <div class="order-confirm-modal">

            <div class="order-confirm-icon">
                <i class="bi bi-cart-check"></i>
            </div>

            <h3>Confirm Your Order</h3>

            <p>
                Please review your items and quantity before placing your order.
            </p>

            {{-- The heading above told customers to "review your items" while the
                 modal showed nothing but a countdown. The lines below are rendered
                 from the same $cart array the cart page itself renders, in the same
                 request, so the two cannot disagree. Quantity changes go through a
                 form POST and reload the page, which re-renders this list too. --}}
            <div class="order-review" id="orderReview">
                <ul class="order-review-items">
                    @foreach($cart as $itemId => $item)
                        <li class="order-review-item" data-menu-item-id="{{ $item['menu_item_id'] ?? $itemId }}" data-cart-key="{{ $itemId }}">
                            <div class="order-review-item-row">
                                {{-- Same field CartPricing already carries for the cart
                                     page itself (re-derived from the live MenuItem, same
                                     as price) — no new image field or lookup needed. --}}
                                <div class="order-review-img">
                                    @if(!empty($item['image']))
                                        <img src="{{ asset($item['image']) }}" alt="{{ $item['name'] }}">
                                    @else
                                        <i class="bi bi-image" aria-hidden="true"></i>
                                    @endif
                                </div>

                                <div class="order-review-item-body">
                                    <div class="order-review-item-main">
                                        <span class="order-review-qty" data-review-qty="{{ $itemId }}">{{ $item['quantity'] }}&times;</span>
                                        <span class="order-review-name">{{ $item['name'] }}</span>
                                        <span class="order-review-line" data-review-line="{{ $itemId }}">₱{{ number_format($item['price'] * $item['quantity'], 2) }}</span>
                                    </div>

                                    @if(!empty($item['options']))
                                        <p class="order-review-options">
                                            + {{ implode(', ', array_column($item['options'], 'name')) }}
                                        </p>
                                    @endif

                                    <p class="order-review-unit">₱{{ number_format($item['price'], 2) }} each</p>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>

                {{-- Take Out is a Dine-In-only choice: a Pick-Up order is already
                     takeout, so the checkbox is never rendered for it. The input is
                     form-associated (form="mainOrderForm") because this modal sits
                     outside that form; confirmOrderNow() submits mainOrderForm and
                     an unticked checkbox simply sends nothing, which the server
                     reads as false. Placed directly below the item list and above
                     Subtotal, in a card matching the .order-review-totals section.

                     This modal renders even for an empty cart, i.e. outside the
                     summary block that defines $sessionOrderType, so fall back to
                     the session directly if the controller ever stops passing it. --}}
                @if(($sessionOrderType ?? session('order_type', Auth::check() ? 'pick_up' : 'dine_in')) === 'dine_in')
                <div class="order-review-takeout">
                    <label class="order-review-row" for="isTakeoutCheckbox">
                        <span>Take Out</span>
                        <input type="checkbox" form="mainOrderForm" name="is_takeout" value="1" id="isTakeoutCheckbox">
                    </label>
                </div>
                @endif

                <div class="order-review-totals">
                    <div class="order-review-row">
                        <span>Subtotal</span>
                        <span id="reviewSubtotal">₱{{ number_format($total, 2) }}</span>
                    </div>

                    {{-- Vouchers and PWD/Senior discounts are applied on this page
                         without a reload, so these two are mirrored from the live
                         summary when the modal opens rather than rendered here. --}}
                    <div class="order-review-row" id="reviewDiscountRow" style="display:none;">
                        <span>Discount</span>
                        <span id="reviewDiscount">-₱0.00</span>
                    </div>

                    <div class="order-review-row order-review-total">
                        <span>Total</span>
                        <span id="reviewTotal">₱{{ number_format($total, 2) }}</span>
                    </div>
                </div>
            </div>

            {{-- The forced 5-second countdown was removed 2026-09-01: it added a
                 delay with no security purpose (the server was never protected
                 by it) and read as though the order review above was fake -
                 "review your items" while a timer, not the review, decided
                 when the customer could act. The button is enabled the moment
                 the modal opens. The double-submit protection that actually
                 matters is the disable-on-click in confirmOrderNow() below,
                 backed by a server-side lock in
                 OrderController::placeOrder() so a request that somehow
                 reaches the server twice cannot create two orders. --}}
            <div class="order-confirm-actions">
                <button type="button" class="order-cancel-btn" onclick="cancelOrderConfirmation()">
                    Cancel
                </button>

                <button type="button" class="order-confirm-btn" id="confirmOrderNow" onclick="confirmOrderNow()">
                    Place Order
                </button>
            </div>

        </div>
    </div>

    {{-- ================= DISCOUNT CARD VERIFICATION ================= --}}
    <div
        id="discountCheckingOverlay"
        class="fixed inset-0 z-[10000] hidden items-center justify-center bg-black/50 px-4"
    >
        <div class="w-full max-w-md rounded-3xl bg-white p-6 text-center shadow-2xl">
            <div id="discountCheckingContent">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-peach-soft">
                    <i class="bi bi-person-vcard text-3xl text-peach-red"></i>
                </div>

                <h3 class="font-display text-xl font-black text-peach-deep">
                    Checking Your Discount Card
                </h3>

                <p class="mt-2 text-sm text-peach-deep/65">
                    Please wait while our staff verifies the information on your discount card.
                </p>

                <div class="mt-5 flex justify-center">
                    <div class="h-8 w-8 animate-spin rounded-full border-4 border-peach-soft border-t-peach-red"></div>
                </div>

                <p class="mt-4 text-xs font-semibold text-peach-deep/50">
                    Please do not close this page.
                </p>
            </div>

            <div id="discountApprovedContent" class="hidden">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-green-100">
                    <i class="bi bi-check-circle-fill text-3xl text-green-600"></i>
                </div>

                <h3 class="font-display text-xl font-black text-green-700">
                    Discount Card Accepted
                </h3>

                <p class="mt-2 text-sm text-peach-deep/65">
                    Your discount card has been verified and accepted by our staff.
                </p>

                <button
                    type="button"
                    onclick="continueToOrdersAfterDiscount()"
                    class="mt-5 w-full rounded-full bg-peach-red px-5 py-3 text-sm font-bold text-white transition hover:bg-peach-deep"
                >
                    Continue
                </button>
            </div>

            <div id="discountRejectedContent" class="hidden">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-red-100">
                    <i class="bi bi-x-circle-fill text-3xl text-red-600"></i>
                </div>

                <h3 class="font-display text-xl font-black text-red-700">
                    Discount Card Rejected
                </h3>

                <p class="mt-2 text-sm text-peach-deep/65">
                    Our staff could not verify your discount card. Would you like to continue your transaction without the discount?
                </p>

                <div class="mt-5 grid grid-cols-2 gap-3">
                    <button
                        type="button"
                        onclick="cancelRejectedDiscountOrder()"
                        class="rounded-full border border-peach-soft bg-white px-4 py-3 text-sm font-bold text-peach-red transition hover:bg-peach-soft"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        onclick="continueWithoutDiscount()"
                        class="rounded-full bg-peach-red px-4 py-3 text-sm font-bold text-white transition hover:bg-peach-deep"
                    >
                        Continue
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        var currentSubtotal = @json($total);

        /* ================= CART QUANTITY (instant + debounced sync) =================
         *
         * Tapping '+' / '-' (or typing) updates the quantity, the line total and
         * the Subtotal/Total on screen immediately — no page navigation, so the
         * browser's page-load indicator never appears. The real cart update is
         * sent to customer.cart.update (a background JSON PUT), debounced so a
         * burst of taps produces ONE request after the user stops.
         *
         * CRITICAL: placeOrder() rebuilds the order from the SESSION cart, so a
         * pending sync must reach the server before the order is submitted.
         * confirmOrderNow() awaits flushCartSync() first — see the foot of this
         * script.
         */
        var CART_SYNC_DEBOUNCE_MS = 400;   // within the required 300–500ms
        var CART_MAX_QTY = 999;            // matches the historic max on this field

        var cartLines = @json(collect($cart)->map(fn ($it) => [
            'unitPrice' => (float) $it['price'],
            'quantity'  => (int) $it['quantity'],
        ]));

        var cartSyncTimer = null;
        var cartDirtyKeys = {};                 // keys awaiting a debounced send
        var cartInFlight = new Set();           // fetch promises currently running
        var cartUpdateUrlBase = @json(url('/customer/cart/update'));

        function cartNormalizeQty(raw) {
            var n = parseInt(raw, 10);
            if (!Number.isFinite(n) || n < 1) return 1;
            if (n > CART_MAX_QTY) return CART_MAX_QTY;
            return n;
        }

        function cartRound2(n) {
            return Math.round(n * 100) / 100;
        }

        // Sum every line the same way the server does: round each line, then add.
        function cartRecomputeSubtotal() {
            var sum = 0;
            Object.keys(cartLines).forEach(function (key) {
                var line = cartLines[key];
                sum += cartRound2(line.unitPrice * line.quantity);
            });
            currentSubtotal = cartRound2(sum);
            return currentSubtotal;
        }

        function peso2(n) {
            return '₱' + Number(n).toLocaleString('en-PH', {
                minimumFractionDigits: 2, maximumFractionDigits: 2
            });
        }

        // Repaint everything that shows this line's quantity or price, plus the
        // shared Subtotal/Total (via refreshDiscountSummary, which also re-weighs
        // any applied voucher / PWD-Senior discount against the new subtotal).
        function cartRenderLine(key) {
            var line = cartLines[key];
            if (!line) return;

            var lineTotal = cartRound2(line.unitPrice * line.quantity);

            var field = document.querySelector('[data-cart-qty="' + key + '"]');
            if (field && document.activeElement !== field) field.value = line.quantity;

            var totalEl = document.querySelector('[data-line-total="' + key + '"]');
            if (totalEl) totalEl.textContent = peso2(lineTotal);

            var orderQty = document.querySelector('[data-order-qty="' + key + '"]');
            if (orderQty) orderQty.value = line.quantity;

            var reviewQty = document.querySelector('[data-review-qty="' + key + '"]');
            if (reviewQty) reviewQty.innerHTML = line.quantity + '&times;';

            var reviewLine = document.querySelector('[data-review-line="' + key + '"]');
            if (reviewLine) reviewLine.textContent = peso2(lineTotal);

            cartRecomputeSubtotal();

            var subEl = document.getElementById('summarySubtotal');
            if (subEl) subEl.textContent = peso2(currentSubtotal);

            var reviewSub = document.getElementById('reviewSubtotal');
            if (reviewSub) reviewSub.textContent = peso2(currentSubtotal);

            // Repaints Discount + Total against the new subtotal. When it is not
            // present (no discount machinery), keep #finalTotal / #reviewTotal
            // in step directly.
            if (typeof refreshDiscountSummary === 'function') {
                refreshDiscountSummary();
            } else {
                var ft = document.getElementById('finalTotal');
                if (ft) ft.textContent = peso2(currentSubtotal);
            }
            if (typeof syncOrderReviewTotals === 'function') {
                syncOrderReviewTotals();
            }
        }

        function cartScheduleSync(key) {
            cartDirtyKeys[key] = true;
            if (cartSyncTimer) clearTimeout(cartSyncTimer);
            cartSyncTimer = setTimeout(cartFlushDirty, CART_SYNC_DEBOUNCE_MS);
        }

        // Fire one request per dirty key. Returns a promise resolved when they
        // all settle — flushCartSync() awaits this before Place Order.
        function cartFlushDirty() {
            if (cartSyncTimer) { clearTimeout(cartSyncTimer); cartSyncTimer = null; }

            var keys = Object.keys(cartDirtyKeys);
            cartDirtyKeys = {};

            var sends = keys.map(function (key) {
                var line = cartLines[key];
                if (!line) return Promise.resolve();

                var p = fetch(cartUpdateUrlBase + '/' + encodeURIComponent(key), {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': (window.csrfToken ? window.csrfToken() : '{{ csrf_token() }}')
                    },
                    body: JSON.stringify({ quantity: line.quantity })
                }).then(function (res) {
                    return res.ok ? res.json() : null;
                }).then(function (data) {
                    // Reconcile against the server's authoritative figure.
                    if (data && data.success && data.lines && data.lines[key]) {
                        cartLines[key].unitPrice = data.lines[key].unit_price;
                        cartRenderLine(key);
                    }
                }).catch(function () {
                    /* offline / transient — the next tap reschedules */
                }).finally(function () {
                    cartInFlight.delete(p);
                });

                cartInFlight.add(p);
                return p;
            });

            return Promise.all(sends);
        }

        // Everything Place Order must wait for: the pending debounce AND any
        // request already in flight.
        function flushCartSync() {
            var pending = cartFlushDirty();
            return Promise.all([pending, Promise.all(Array.from(cartInFlight))]);
        }

        function cartChangeQty(key, delta) {
            var line = cartLines[key];
            if (!line) return;
            line.quantity = cartNormalizeQty(line.quantity + delta);
            cartRenderLine(key);
            cartScheduleSync(key);
        }

        // Live typing: keep the model + totals in step without fighting the caret.
        function cartTypeQty(key, el) {
            var line = cartLines[key];
            if (!line) return;
            if (el.value.trim() === '') return;
            line.quantity = cartNormalizeQty(el.value);
            cartRenderLine(key);
            cartScheduleSync(key);
        }

        // Blur / Enter commits — an invalid value snaps back to a valid one.
        function cartCommitQty(key, el) {
            var line = cartLines[key];
            if (!line) return;
            line.quantity = cartNormalizeQty(el.value);
            el.value = line.quantity;
            cartRenderLine(key);
            cartScheduleSync(key);
        }
        /* ================= end cart quantity ================= */

        /*
         * The peso value of the currently applied voucher, or null when none
         * is applied. Written by applyVoucher() when the server confirms a
         * code, cleared when the code is removed or rejected.
         *
         * Kept as its own variable because #discountAmount now holds whichever
         * discount WON, so it can no longer be read back as "the voucher's
         * amount" the way it used to be.
         */
        var appliedVoucherDiscount = null;

        /*
         * The discount rate and the expiry messages are rendered from the
         * server's own constants, so this preview cannot drift away from what
         * OrderController::placeOrder() will actually do.
         */
        var PWD_SENIOR_DISCOUNT_RATE = @json(\App\Models\Order::PWD_SENIOR_DISCOUNT_RATE);

        var DISCOUNT_CARD_MESSAGES = {
            missing: @json(\App\Models\DiscountCard::ERROR_EXPIRATION_MISSING),
            invalid: @json(\App\Models\DiscountCard::ERROR_EXPIRATION_INVALID),
            expired: @json(\App\Models\DiscountCard::ERROR_EXPIRED)
        };

        /**
         * The browser-side twin of DiscountCard::expirationErrorFor().
         *
         * Returns the reason the entered expiration date makes the card
         * unusable, or null when it is fine. Everything on this page that
         * cares about the expiry — showing the discount, hiding the discount,
         * and blocking the confirm modal — asks THIS function, which is why
         * the page can no longer print "already expired" next to a live
         * -20% discount the way it did before.
         */
        function discountCardExpirationError() {
            var field = document.getElementById('discountBeneficiaryExpiration');
            var value = field ? field.value.trim() : '';

            if (!value) {
                return DISCOUNT_CARD_MESSAGES.missing;
            }

            var entered = new Date(value + 'T00:00:00');

            if (isNaN(entered.getTime())) {
                return DISCOUNT_CARD_MESSAGES.invalid;
            }

            var today = new Date();
            today.setHours(0, 0, 0, 0);

            return entered < today ? DISCOUNT_CARD_MESSAGES.expired : null;
        }

        window.addEventListener('load', function() {
            /*
             * A voucher applied to one cart must not survive onto a different
             * cart built after this one emptied out. The only way this page's
             * cart reaches zero items is the remove form's full page reload
             * (quantity can never be stepped down to 0 — cartNormalizeQty
             * floors it at 1), so an empty cart on load is exactly the signal
             * that whatever 'peachy_voucher' still holds belongs to a cart
             * that no longer exists. Clearing it here — rather than only when
             * applyVoucher() itself fails or is cleared by hand — is what
             * stops the stale code from being silently reapplied once a new
             * item is added and #voucherInput exists again.
             */
            if (!@json(isset($cart) && count($cart) > 0)) {
                localStorage.removeItem('peachy_voucher');
                return;
            }

            var savedVoucher = localStorage.getItem('peachy_voucher');
            if (savedVoucher && document.getElementById('voucherInput')) {
                document.getElementById('voucherInput').value = savedVoucher;
                applyVoucher();
            }
        });

        /**
         * The one place that paints the voucher box's message.
         *
         * THE BUG THIS EXISTS FOR
         * -----------------------
         * applyVoucher() used to hand-set msg.style.color at four separate
         * call sites — '#16a34a' here, '#C0392B' there, '#802323' for a
         * cleared code — so a refusal arrived as a bare 12px line under the
         * field in a colour that appears nowhere else in the design. Reported
         * as "no confirmed visible error message", and fairly: it did not look
         * like the page's other refusals.
         *
         * The classes below are showDiscountCardMessage()'s, verbatim — the
         * PWD/Senior card a few hundred lines down this same file already had
         * this page's message pattern. Nothing new is invented here; the two
         * controls now refuse in the same voice.
         *
         * `kind` is 'error' | 'success' | 'neutral'; anything falsy hides the
         * box entirely rather than leaving an empty coloured strip.
         */
        function setVoucherMessage(message, kind) {
            var msg = document.getElementById('voucherMsg');

            if (!msg) return;

            if (!message) {
                msg.textContent = '';
                msg.className = 'mt-1.5 hidden';
                return;
            }

            var tone = kind === 'success'
                ? 'bg-green-50 text-green-700'
                : (kind === 'neutral'
                    ? 'bg-peach-soft/60 text-peach-deep/70'
                    : 'bg-red-50 text-red-700');

            var icon = kind === 'success'
                ? 'bi-check-circle'
                : (kind === 'neutral' ? 'bi-info-circle' : 'bi-exclamation-circle');

            msg.className = 'mt-1.5 flex items-start gap-1.5 rounded-xl px-3 py-2 text-xs font-semibold ' + tone;
            msg.innerHTML = '';

            var i = document.createElement('i');
            i.className = 'bi ' + icon + ' mt-px shrink-0';
            i.setAttribute('aria-hidden', 'true');

            var span = document.createElement('span');
            span.className = 'min-w-0';
            // textContent, never innerHTML: `message` is the server's sentence
            // and may quote a code the customer typed.
            span.textContent = message;

            msg.appendChild(i);
            msg.appendChild(span);
        }

        function applyVoucher() {
            var code = document.getElementById('voucherInput').value.trim().toUpperCase();
            var discountType = document.getElementById('discountType');

            /*
             * The mirror of the guard removed from selectDiscountType():
             * applying a voucher while a discount card was selected used to
             * be refused. Both may now be present, and the bigger one is
             * applied automatically. See refreshDiscountSummary().
             */

            if (!code) {
                document.getElementById('voucher_code_hidden').value = '';

                // Any PWD/Senior card that is still selected keeps its
                // discount — the summary is re-decided rather than blanked.
                appliedVoucherDiscount = null;
                refreshDiscountSummary();

                localStorage.removeItem('peachy_voucher');

                setVoucherMessage('Voucher cleared.', 'neutral');
                return;
            }

            localStorage.setItem('peachy_voucher', code);

            fetch('/customer/apply-voucher', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        code: code,
                        subtotal: currentSubtotal
                    })
                })
                .then(function(res) {
                    return res.json();
                })
                .then(function(data) {
                    if (data.success) {
                        setVoucherMessage('Voucher applied.', 'success');
                        document.getElementById('voucher_code_hidden').value = code;

                        /*
                         * Record the voucher's own value, then let
                         * refreshDiscountSummary() decide whether it or a
                         * selected PWD/Senior card actually applies. The
                         * summary is no longer painted straight from
                         * data.discount, because that figure ignores the card.
                         */
                        appliedVoucherDiscount = data.discount;
                        refreshDiscountSummary();
                    } else {
                        setVoucherMessage(data.message, 'error');
                        document.getElementById('voucher_code_hidden').value = '';

                        /*
                         * RESET THE FIELD. The rejected code used to be left
                         * sitting in the box, so the customer's next attempt
                         * began by clearing it by hand — and, because the window
                         * load handler above re-applies whatever is in
                         * localStorage, a bad code could be re-typed into the
                         * box and re-refused on every later visit to the cart.
                         * The refusal itself stays on screen; only the dead
                         * input is cleared.
                         */
                        var field = document.getElementById('voucherInput');
                        if (field) {
                            field.value = '';
                        }

                        // Rejected, so it is worth nothing — but a card that is
                        // still selected keeps its own discount.
                        appliedVoucherDiscount = null;
                        refreshDiscountSummary();

                        localStorage.removeItem('peachy_voucher');
                    }
                })
                .catch(function() {
                    // The code is NOT cleared here: nothing said it was wrong,
                    // only that the check could not be made, so the customer
                    // keeps what they typed and can press Apply again.
                    setVoucherMessage(
                        'We could not check that code just now. Please try again.',
                        'error'
                    );
                });
        }

        function selectDiscountType(type) {
            /*
             * THE CLICK BUG, fixed 2026-09-02.
             *
             * This used to start by refusing outright when a voucher was
             * applied, and that refusal was invisible: showDiscountCardMessage()
             * writes into #discountCardMsg, which lives INSIDE
             * #discountCardFields — still carrying `hidden` at this point,
             * because the early return happened BEFORE
             * fields.classList.remove('hidden') below. So the message was
             * placed into a hidden container, the button never got its
             * selected class (that code is further down), and no discount was
             * applied: clicking PWD or Senior did nothing at all, visibly.
             *
             * Worse, it was near-permanent. applyVoucher() writes the code to
             * localStorage under 'peachy_voucher' and the window load handler
             * near the top of this file silently re-applies it on EVERY later
             * visit to the cart, so once a customer had ever used a voucher,
             * the PWD/Senior buttons stayed dead across sessions with no
             * indication why.
             *
             * The refusal is gone entirely now: a voucher and a PWD/Senior
             * discount may both be present, and the bigger one is applied
             * automatically — refreshDiscountSummary() below mirrors the
             * server rule in OrderController::placeOrder().
             */
            var discountType = document.getElementById('discountType');
            var fields = document.getElementById('discountCardFields');
            var pwdBtn = document.getElementById('pwdDiscountBtn');
            var seniorBtn = document.getElementById('seniorDiscountBtn');

            if (!discountType || !fields || !pwdBtn || !seniorBtn) return;

            // Clicking the currently selected discount again unselects it.
            if (discountType.value === type) {
                clearDiscountCard();
                return;
            }

            discountType.value = type;
            fields.classList.remove('hidden');

            // Show the PWD/Senior discount straight away, the same way an
            // applied voucher does. Without this the cart showed the full
            // undiscounted total while checkout actually charged 20% less.
            applyDiscountCardPreview();

            pwdBtn.classList.remove(
                'bg-peach-red', 'text-white', 'bg-peach-soft',
                'text-peach-deep', 'discount-selected'
            );
            seniorBtn.classList.remove(
                'bg-peach-red', 'text-white', 'bg-peach-soft',
                'text-peach-deep', 'discount-selected'
            );

            if (type === 'pwd') {
                pwdBtn.classList.add('discount-selected');
            } else {
                seniorBtn.classList.add('discount-selected');
            }

            // See clearDiscountCard(): this element does not exist in the
            // markup, and the unguarded call threw on every discount selection.
            var clearButton = document.getElementById('clearDiscountCard');
            if (clearButton) {
                clearButton.classList.remove('hidden');
            }

            var toggleButton = document.getElementById('toggleDiscountCardFields');
            if (toggleButton) {
                toggleButton.classList.remove('hidden');
                toggleButton.textContent = 'Hide';
            }
        }

        /*
         * PWD / Senior Citizen discount preview.
         *
         * Mirrors the server rule in OrderController::placeOrder():
         *     discount = min(subtotal * PWD_SENIOR_DISCOUNT_RATE, subtotal)
         * rounded to centavos, with the total derived from that ROUNDED
         * discount so the figures shown here match the saved order exactly.
         *
         * A discount is only previewed for a card the server would actually
         * honour. The reported bug was the opposite: picking PWD applied -20%
         * immediately, and typing an expiration of 01/01/1940 printed "This
         * discount card has already expired." while LEAVING the -₱80.00 on
         * screen — two bits of JS with two different ideas of validity. The
         * server always refused that order, so no money was mis-charged, but
         * the customer was shown a discount they could never have.
         *
         * This is display only — the server recomputes the discount itself and
         * still stores discount_status = 'pending' until staff verify the ID.
         */
        /**
         * The PWD/Senior discount this cart would get right now, or null when
         * no usable card is selected.
         *
         * Only a card the SERVER would honour counts — same expiry rule, via
         * discountCardExpirationError() — so the preview can never advertise a
         * discount checkout would refuse.
         */
        function currentCardDiscount() {
            var discountType = document.getElementById('discountType');

            if (!discountType || discountType.value.trim() === '') {
                return null;
            }

            if (discountCardExpirationError() !== null) {
                return null;
            }

            return Math.round(
                Math.min(currentSubtotal * PWD_SENIOR_DISCOUNT_RATE, currentSubtotal) * 100
            ) / 100;
        }

        /**
         * Decide which discount applies and repaint the summary.
         *
         * MIRRORS THE SERVER RULE in OrderController::placeOrder(): both
         * discounts are priced against the same subtotal and only the LARGER
         * is applied — never both added together. Strictly greater-than, so a
         * PWD/Senior card WINS A TIE and the voucher is kept for another
         * order. If these two ever disagree the customer sees a figure they
         * are not charged, so the comparison is written the same way in both
         * places on purpose.
         *
         * The customer is told WHICH one won and why whenever both are
         * present, rather than the smaller one silently vanishing.
         */
        function refreshDiscountSummary() {
            var cardDiscount = currentCardDiscount();
            var voucherDiscount = appliedVoucherDiscount;

            var haveCard = cardDiscount !== null;
            var haveVoucher = voucherDiscount !== null;

            var applied = 0;

            if (haveCard && haveVoucher) {
                // Tie goes to the card, exactly as the server decides it.
                applied = voucherDiscount > cardDiscount ? voucherDiscount : cardDiscount;
            } else if (haveCard) {
                applied = cardDiscount;
            } else if (haveVoucher) {
                applied = voucherDiscount;
            }

            /*
             * Cap at the subtotal, the same way both server-side formulas do
             * (Voucher::discountFor() and Order::pwdSeniorDiscountFor() each
             * apply min($discount, $subtotal)). /customer/apply-voucher
             * already returns a capped figure today, so this cannot currently
             * differ — it is here so the displayed discount stays equal to the
             * one the order will record even if that endpoint ever stops
             * capping, rather than showing "−₱9,999.00" against a ₱400 order
             * that gets recorded as −₱400.00.
             */
            if (applied > currentSubtotal) applied = currentSubtotal;

            var finalTotal = Math.round((currentSubtotal - applied) * 100) / 100;

            // Never below zero, whichever discount won.
            if (finalTotal < 0) finalTotal = 0;

            var amountField = document.getElementById('discountAmount');
            if (amountField) amountField.value = applied;

            var row = document.getElementById('discountRow');
            var display = document.getElementById('discountDisplay');
            var totalEl = document.getElementById('finalTotal');

            if (row) row.style.display = (haveCard || haveVoucher) ? 'flex' : 'none';
            if (display) display.textContent = '-₱' + applied.toFixed(2);
            if (totalEl) totalEl.textContent = '₱' + finalTotal.toFixed(2);

            // Say which one is being used, and why, when there is a choice.
            if (haveCard && haveVoucher) {
                var cardWon = !(voucherDiscount > cardDiscount);

                if (cardWon) {
                    showDiscountCardMessage(
                        Math.round(PWD_SENIOR_DISCOUNT_RATE * 100) + '% PWD/Senior discount applied (−₱'
                        + cardDiscount.toFixed(2) + ') — it is bigger than your voucher (−₱'
                        + voucherDiscount.toFixed(2) + '), so your voucher is saved for next time. '
                        + 'Staff will verify your ID before the order is prepared.',
                        true
                    );
                } else {
                    showDiscountCardMessage(
                        'Your voucher (−₱' + voucherDiscount.toFixed(2) + ') is bigger than the '
                        + Math.round(PWD_SENIOR_DISCOUNT_RATE * 100) + '% PWD/Senior discount (−₱'
                        + cardDiscount.toFixed(2) + '), so the voucher was applied instead.',
                        true
                    );
                }
            }

            return applied;
        }

        function applyDiscountCardPreview() {
            var expirationError = discountCardExpirationError();

            if (expirationError) {
                // Nothing to preview: show why, and make sure no stale
                // discount is left behind on the summary.
                clearDiscountPreview();

                // A blank field is simply "not filled in yet", not an error to
                // scold the customer with the moment they pick PWD.
                if (expirationError !== DISCOUNT_CARD_MESSAGES.missing) {
                    showDiscountCardMessage(expirationError, false);
                } else {
                    showDiscountCardMessage(
                        'Enter the card details, including a valid expiration date, to apply the '
                        + Math.round(PWD_SENIOR_DISCOUNT_RATE * 100) + '% discount.',
                        'neutral'
                    );
                }

                return;
            }

            /*
             * The summary is now painted by refreshDiscountSummary(), which
             * also weighs this card against any applied voucher and keeps only
             * the bigger. It returns the discount that actually won.
             */
            var applied = refreshDiscountSummary();

            // When a voucher is also present, refreshDiscountSummary() has
            // already explained which one won and why — do not overwrite that
            // with the card-only wording.
            if (appliedVoucherDiscount === null) {
                showDiscountCardMessage(
                    Math.round(PWD_SENIOR_DISCOUNT_RATE * 100)
                    + '% discount applied (−₱' + applied.toFixed(2)
                    + '). Staff will verify your ID before the order is prepared.',
                    true
                );
            }
        }

        /**
         * Drop the discount from the summary WITHOUT clearing the card fields
         * the customer is still filling in. Used whenever the entered card
         * stops being one the server would honour.
         */
        function clearDiscountPreview() {
            /*
             * The card has stopped being usable (blank or expired date). The
             * two are no longer mutually exclusive, so this can no longer just
             * blank the row: an applied voucher may still be entitled to it.
             * refreshDiscountSummary() re-decides from whatever is left —
             * currentCardDiscount() now returns null for this card, so the
             * voucher takes the row if there is one, and the summary falls
             * back to the plain subtotal if there is not.
             */
            refreshDiscountSummary();
        }

        /*
         * Re-run the preview whenever the expiration date changes, so entering
         * an expired date removes the discount there and then rather than only
         * at the moment the customer tries to place the order.
         */
        document.addEventListener('DOMContentLoaded', function () {
            var expirationField = document.getElementById('discountBeneficiaryExpiration');
            var discountTypeField = document.getElementById('discountType');

            if (!expirationField) return;

            ['change', 'input'].forEach(function (event) {
                expirationField.addEventListener(event, function () {
                    if (discountTypeField && discountTypeField.value.trim() !== '') {
                        applyDiscountCardPreview();
                    }
                });
            });
        });

        function toggleDiscountCardFields() {
            var fields = document.getElementById('discountCardFields');
            var toggleButton = document.getElementById('toggleDiscountCardFields');

            if (!fields || !toggleButton) return;

            var isHidden = fields.classList.contains('hidden');

            if (isHidden) {
                fields.classList.remove('hidden');
                toggleButton.textContent = 'Hide';
            } else {
                fields.classList.add('hidden');
                toggleButton.textContent = 'Show';
            }
        }

        function clearDiscountCard() {
            var type = document.getElementById('discountType');
            var fields = document.getElementById('discountCardFields');

            if (type) type.value = '';

            document.getElementById('discountBeneficiaryName').value = '';
            document.getElementById('discountBeneficiaryId').value = '';
            document.getElementById('discountBeneficiaryExpiration').value = '';

            var image = document.getElementById('discountBeneficiaryImage');
            if (image) image.value = '';

            document.getElementById('pwdDiscountBtn').classList.remove('bg-peach-red', 'text-white', 'bg-peach-soft', 'text-peach-deep', 'discount-selected');
            document.getElementById('seniorDiscountBtn').classList.remove('bg-peach-red', 'text-white', 'bg-peach-soft', 'text-peach-deep', 'discount-selected');

            if (fields) fields.classList.add('hidden');

            /*
             * There is no element with id "clearDiscountCard" in this page's
             * markup, so this lookup returns null and the unguarded
             * .classList call threw — aborting the rest of this function.
             *
             * That is why deselecting a PWD/Senior discount left the discounted
             * figure on screen: discountType was cleared (so the server charged
             * full price) but the reset of #discountRow and #finalTotal at the
             * bottom of this function never ran. The customer saw 40.00 and was
             * charged 50.00. The confirm modal mirrors #finalTotal, so it
             * inherited the same wrong number.
             */
            var clearButton = document.getElementById('clearDiscountCard');
            if (clearButton) {
                clearButton.classList.add('hidden');
            }

            var toggleButton = document.getElementById('toggleDiscountCardFields');
            if (toggleButton) {
                toggleButton.classList.add('hidden');
                toggleButton.textContent = 'Hide';
            }

            var msg = document.getElementById('discountCardMsg');

            if (msg) {
                msg.textContent = '';
                msg.classList.add('hidden');
            }

            // The card is deselected by this point, so refreshDiscountSummary()
            // (via clearDiscountPreview) hands the summary back to an applied
            // voucher if there is one, or to the plain subtotal if there is
            // not. The expiry path uses the same call, so the two can never
            // disagree about what "no card discount" looks like.
            clearDiscountPreview();
        }

        function showDiscountCardMessage(message, success) {
            var msg = document.getElementById('discountCardMsg');

            if (!msg) return;

            msg.textContent = message;
            msg.classList.remove('hidden');

            // 'neutral' is for "you have not filled this in yet", which is not
            // an error and should not be shouted at the customer in red.
            if (success === 'neutral') {
                msg.className =
                    'rounded-xl bg-peach-soft/60 px-3 py-2 text-xs font-semibold text-peach-deep/70';
            } else if (success) {
                msg.className =
                    'rounded-xl bg-green-50 px-3 py-2 text-xs font-semibold text-green-700';
            } else {
                msg.className =
                    'rounded-xl bg-red-50 px-3 py-2 text-xs font-semibold text-red-700';
            }
        }


        // Auto-dismiss floating flash toasts so they don't linger
        document.querySelectorAll('[data-toast]').forEach(function(toast, i) {
            setTimeout(function() {
                toast.style.transition = 'opacity .4s ease, transform .4s ease';
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-6px)';
                setTimeout(function() { toast.remove(); }, 400);
            }, 4000 + i * 600);
        });

function openOrderConfirmation() {
    const paymentMethod = document.getElementById('paymentMethod');

if (!paymentMethod || !paymentMethod.value) {
    const message = document.getElementById('paymentMethodMsg');

    if (message) {
        message.classList.remove('hidden');
    }

    return;
}
    var discountType = document.getElementById('discountType');

    if (discountType && discountType.value.trim() !== '') {
        var name = document.getElementById('discountBeneficiaryName').value.trim();
        var idNumber = document.getElementById('discountBeneficiaryId').value.trim();
        var image = document.getElementById('discountBeneficiaryImage');

        if (!name) {
            showDiscountCardMessage('Please enter the beneficiary name.', false);
            return;
        }

        if (!idNumber) {
            showDiscountCardMessage('Please enter the discount card ID number.', false);
            return;
        }

        // Same rule the preview uses, so the modal can never be blocked for a
        // card the summary is still showing a discount for.
        var expirationError = discountCardExpirationError();

        if (expirationError) {
            clearDiscountPreview();
            showDiscountCardMessage(expirationError, false);
            return;
        }

        if (!image || !image.files || image.files.length === 0) {
            showDiscountCardMessage(
                'Please upload a clear picture of the discount card or ID.',
                false
            );
            return;
        }
    }

    var overlay = document.getElementById('orderConfirmOverlay');
    var confirmButton = document.getElementById('confirmOrderNow');

    if (!overlay) {
        return;
    }

    // Copy the totals the customer can actually see right now. Vouchers and
    // PWD/Senior discounts are applied on this page without a reload, so
    // reading them off the live summary is what keeps the modal honest —
    // re-rendering them server-side would show a pre-discount total.
    syncOrderReviewTotals();

    // No forced wait: the button is usable the instant the modal opens, and
    // the full order review above (items, quantities, subtotal, total) is
    // what the customer is actually confirming. See confirmOrderNow() for the
    // double-submit guard that replaces the old countdown.
    confirmButton.disabled = false;
    confirmButton.textContent = 'Place Order';

    overlay.style.display = 'flex';
}

/**
 * Mirror the live order summary into the confirm modal.
 *
 * Reads the same elements the customer is looking at (#finalTotal,
 * #discountDisplay, #discountRow) rather than recomputing anything, so the
 * modal can never quote a different figure from the summary beside it.
 */
function syncOrderReviewTotals() {
    var finalTotal = document.getElementById('finalTotal');
    var reviewTotal = document.getElementById('reviewTotal');

    if (finalTotal && reviewTotal) {
        reviewTotal.textContent = finalTotal.textContent.trim();
    }

    var discountRow = document.getElementById('discountRow');
    var discountDisplay = document.getElementById('discountDisplay');
    var reviewDiscountRow = document.getElementById('reviewDiscountRow');
    var reviewDiscount = document.getElementById('reviewDiscount');

    if (!reviewDiscountRow || !reviewDiscount) {
        return;
    }

    var discountVisible =
        discountRow &&
        discountRow.style.display !== 'none' &&
        discountDisplay;

    if (discountVisible) {
        reviewDiscount.textContent = discountDisplay.textContent.trim();
        reviewDiscountRow.style.display = 'flex';
    } else {
        reviewDiscountRow.style.display = 'none';
    }
}

function cancelOrderConfirmation() {
    var overlay = document.getElementById('orderConfirmOverlay');

    if (overlay) {
        overlay.style.display = 'none';
    }
}

var discountStatusTimer = null;
var pendingDiscountOrderId = null;

function showDiscountCheckingModal(orderId) {
    pendingDiscountOrderId = orderId;

    var overlay = document.getElementById('discountCheckingOverlay');
    if (!overlay) return;

    document.getElementById('discountCheckingContent').classList.remove('hidden');
    document.getElementById('discountApprovedContent').classList.add('hidden');
    document.getElementById('discountRejectedContent').classList.add('hidden');

    overlay.classList.remove('hidden');
    overlay.classList.add('flex');

    startDiscountStatusPolling();
}

function startDiscountStatusPolling() {
    if (!pendingDiscountOrderId) return;

    if (discountStatusTimer) {
        clearInterval(discountStatusTimer);
    }

    checkDiscountStatus();

    discountStatusTimer = setInterval(function () {
        checkDiscountStatus();
    }, 3000);
}

async function checkDiscountStatus() {
    if (!pendingDiscountOrderId) return;

    try {
        var url =
            '{{ route("customer.order-status") }}' +
            '?order_id=' +
            encodeURIComponent(pendingDiscountOrderId);

        var response = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            cache: 'no-store'
        });

        if (!response.ok) return;

        var data = await response.json();

        var status =
            data.discount_status ||
            (data.order && data.order.discount_status);

        if (status === 'approved') {
            clearInterval(discountStatusTimer);

            document.getElementById('discountCheckingContent').classList.add('hidden');
            document.getElementById('discountApprovedContent').classList.remove('hidden');
            return;
        }

        if (status === 'rejected') {
            clearInterval(discountStatusTimer);

            document.getElementById('discountCheckingContent').classList.add('hidden');
            document.getElementById('discountRejectedContent').classList.remove('hidden');
        }

    } catch (error) {
        console.error('Unable to check discount status:', error);
    }
}

async function continueToOrdersAfterDiscount() {
    if (!pendingDiscountOrderId) {
        window.location.href = '{{ route("customer.orders") }}';
        return;
    }

    var orderId = pendingDiscountOrderId;

    // Prevent the Orders page from repeating the Accepted prompt.
    localStorage.setItem(
        'peachy_discount_decision_' + orderId,
        'approved'
    );

    clearInterval(discountStatusTimer);
    pendingDiscountOrderId = null;

    try {
        var response = await fetch(
            '{{ route("customer.order-status") }}' +
            '?order_id=' + encodeURIComponent(orderId),
            {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                cache: 'no-store'
            }
        );

        if (!response.ok) {
            throw new Error('Unable to check the order payment method.');
        }

        var data = await response.json();

        if (data.payment_method === 'gcash') {
            window.location.href =
                '{{ url("/customer/gcash-payment") }}/' + orderId;
            return;
        }

        window.location.href = '{{ route("customer.orders") }}';

    } catch (error) {
        console.error('Unable to determine payment method:', error);

        // Safe fallback for Cash / unexpected status.
        window.location.href = '{{ route("customer.orders") }}';
    }
}
async function continueWithoutDiscount() {
    if (!pendingDiscountOrderId) {
        window.location.href = '{{ route("customer.orders") }}';
        return;
    }

    var overlay = document.getElementById('discountCheckingOverlay');
    var buttons = overlay ? overlay.querySelectorAll('button') : [];
    buttons.forEach(function (button) {
        button.disabled = true;
        button.style.opacity = '0.6';
        button.style.pointerEvents = 'none';
    });

    // Hide immediately so the 3-second status polling cannot visually
    // keep the rejected modal open while the server request is processing.
    if (overlay) {
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
    }

    clearInterval(discountStatusTimer);

    try {
        var response = await fetch(
            '{{ url("/customer/orders") }}/' +
            encodeURIComponent(pendingDiscountOrderId) +
            '/continue-without-discount',
            {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({})
            }
        );

        var data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Unable to continue the transaction.');
        }

        var orderId = pendingDiscountOrderId;
        var paymentMethod = String(data.payment_method || '').toLowerCase();

        pendingDiscountOrderId = null;

        // The controller has changed the order to regular pricing.
        // Cash goes to Orders; GCash must continue to the GCash payment page.
        if (paymentMethod === 'gcash') {
            window.location.href =
                '{{ url("/customer/gcash-payment") }}/' + orderId;
            return;
        }

        window.location.href = '{{ route("customer.orders") }}';

    } catch (error) {
        console.error('Unable to continue without discount:', error);

        if (overlay) {
            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
        }

        buttons.forEach(function (button) {
            button.disabled = false;
            button.style.opacity = '';
            button.style.pointerEvents = '';
        });

        alert(error.message || 'Unable to continue the transaction. Please try again.');
    }
}

async function cancelRejectedDiscountOrder() {
    if (!pendingDiscountOrderId) {
        window.location.href = '{{ route("customer.cart") }}';
        return;
    }

    var overlay = document.getElementById('discountCheckingOverlay');
    var buttons = overlay ? overlay.querySelectorAll('button') : [];
    buttons.forEach(function (button) {
        button.disabled = true;
        button.style.opacity = '0.6';
        button.style.pointerEvents = 'none';
    });

    clearInterval(discountStatusTimer);

    try {
        var response = await fetch(
            '{{ url("/customer/orders") }}/' +
            encodeURIComponent(pendingDiscountOrderId) +
            '/cancel',
            {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({})
            }
        );

        var data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Unable to cancel the order.');
        }

        pendingDiscountOrderId = null;

        if (overlay) {
            overlay.classList.add('hidden');
            overlay.classList.remove('flex');
        }

        // Reload the cart after the order is actually cancelled.
        // AuthController::showCart will clear any stale discount session state.
        window.location.href = '{{ route("customer.cart") }}';

    } catch (error) {
        console.error('Unable to cancel rejected discount order:', error);

        buttons.forEach(function (button) {
            button.disabled = false;
            button.style.opacity = '';
            button.style.pointerEvents = '';
        });

        alert(error.message || 'Unable to cancel the order. Please try again.');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    @if(session('discount_pending') && session('pending_order_id'))
        // A stale flash/session value must not reopen the discount modal.
        // Verify that the referenced order is still an active order first.
        (async function () {
            const pendingOrderId = {{ session('pending_order_id') }};

            try {
                const response = await fetch(
                    '{{ route("customer.order-status") }}?order_id=' +
                    encodeURIComponent(pendingOrderId),
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

                // Only show the discount verification modal when the order
                // still exists and is in an active state. If the order was
                // cancelled/completed or no longer exists, do nothing.
                const activeStatuses = ['pending', 'preparing', 'serving'];
                const isActiveOrder = data.has_order === true &&
                    activeStatuses.includes(data.status);

                if (!isActiveOrder) {
                    return;
                }

                const discountStatus =
                    data.discount_status ||
                    (data.order && data.order.discount_status);

                // Only open this modal while the discount is genuinely
                // pending/rejected. Once Continue Transaction changes the
                // order to approved/regular pricing, never reopen it.
                if (discountStatus === 'pending' || discountStatus === 'rejected') {
                    showDiscountCheckingModal(pendingOrderId);
                }
            } catch (error) {
                console.error('Unable to verify pending discount order:', error);
            }
        })();
    @endif
});
function selectPaymentMethod(method) {
    const paymentInput = document.getElementById('paymentMethod');
    const cashButton = document.getElementById('cashPaymentBtn');
    const gcashButton = document.getElementById('gcashPaymentBtn');
    const message = document.getElementById('paymentMethodMsg');

    if (!paymentInput || !cashButton || !gcashButton) {
        return;
    }

    paymentInput.value = method;

    cashButton.classList.remove(
        'border-peach-red',
        'bg-peach-soft',
        'ring-2',
        'ring-peach-red/20'
    );

    gcashButton.classList.remove(
        'border-peach-red',
        'bg-peach-soft',
        'ring-2',
        'ring-peach-red/20'
    );

    const selectedButton =
        method === 'cash' ? cashButton : gcashButton;

    selectedButton.classList.add(
        'border-peach-red',
        'bg-peach-soft',
        'ring-2',
        'ring-peach-red/20'
    );

    if (message) {
        message.classList.add('hidden');
    }
}
async function confirmOrderNow() {
    var confirmButton = document.getElementById('confirmOrderNow');

    /*
     * The double-submit guard. This is what actually prevents a duplicate
     * order now that the countdown is gone: `disabled` is checked and set
     * synchronously, before the form ever submits, so a second click (or a
     * second call to this function by any means) sees the button already
     * disabled and returns immediately. JS runs single-threaded, so there is
     * no window where two clicks can both pass this check.
     */
    if (!confirmButton || confirmButton.disabled) {
        return;
    }

    confirmButton.disabled = true;
    confirmButton.textContent = 'Placing Order...';

    var form = document.getElementById('mainOrderForm');

    if (form) {
        /*
         * FLUSH BEFORE SUBMIT. placeOrder() rebuilds the order from the
         * SESSION cart, so any debounced quantity change still sitting in the
         * browser must reach the server first — otherwise the order is placed
         * against a stale quantity. Awaited unconditionally; it is a no-op
         * when nothing is pending.
         */
        try {
            await flushCartSync();
        } catch (e) {
            /* a failed sync must not strand the customer on a dead button */
        }
        form.submit();
    } else {
        confirmButton.disabled = false;
        confirmButton.textContent = 'Place Order';
        alert('Order form could not be found.');
    }
}
    </script>
</body>

</html>

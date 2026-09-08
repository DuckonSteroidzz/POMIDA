<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ isset($item) ? $item->name : 'Item' }} - Peachy</title>

    @include('partials.icon-stability')
    <link href="/vendor/bootstrap-icons.css" rel="stylesheet">
    <link href="/vendor/gfonts.css" rel="stylesheet">
    @include('partials.typography-stability')

    <script src="/vendor/tailwindcss-browser-4.js"></script>

    @php
    $orderType = session('order_type', 'pick_up');
    @endphp
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

        /* Custom checkbox chip for item options */
        @utility option-chip {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            border: 1px solid var(--color-peach-soft);
            border-radius: 0.875rem;
            background-color: #fff;
            padding: 0.7rem 0.85rem;
            cursor: pointer;
            transition: border-color .18s ease, background-color .18s ease, box-shadow .18s ease;
        }
        @utility option-chip-hover {
            &:hover { border-color: var(--color-peach); }
        }
    </style>
    <style>
        /* Editable quantity field: drop the native spinners so it matches the
           pill control it sits in (same treatment as the cart page). */
        input[type=number].qty-input::-webkit-inner-spin-button,
        input[type=number].qty-input::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type=number].qty-input { -moz-appearance: textfield; appearance: textfield; }

        /* Selected state for option chips (peer-checked styling, framework-free) */
        .option-input:checked + .option-chip-label {
            border-color: #F4845F;
            background-color: #FDE8DE;
            box-shadow: 0 0 0 3px rgb(244 132 95 / .18);
        }
        .option-input:focus-visible + .option-chip-label {
            box-shadow: 0 0 0 3px rgb(244 132 95 / .35);
        }
        .option-input:checked + .option-chip-label .option-tick {
            background-color: #C0392B;
            border-color: #C0392B;
            color: #fff;
        }
        .option-input:checked + .option-chip-label .option-tick i { opacity: 1; }
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

    <main class="mx-auto w-full max-w-6xl px-4 pb-36 pt-4 sm:px-6 sm:pt-6 md:pb-16">

        {{-- Breadcrumb --}}
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2 text-xs font-semibold text-peach-deep/55">
                <a href="{{ route('customer.menu') }}" class="no-underline transition hover:text-peach-red">Menu</a>
                <i class="bi bi-chevron-right shrink-0 text-[0.6rem]"></i>
                <span class="truncate text-peach-deep">{{ isset($item) ? $item->name : 'Item' }}</span>
                </div>
            </div>
        </div>

        @php
            // Two reasons an item cannot be ordered: no recipe has been set for
            // it, or its recipe cannot be covered by current inventory.
            $itemMissingRecipe = isset($item) && $item->isMissingRecipe();
            $itemIngredientOOS = isset($item) && ! $itemMissingRecipe && $item->isIngredientOutOfStock();
            // $itemOutOfStock stays the single "disable the add button" flag.
            $itemOutOfStock = $itemMissingRecipe || $itemIngredientOOS;
            $itemBlockedLabel = $itemMissingRecipe ? 'Unavailable' : 'Out of stock';
        @endphp

        <form action="{{ route('customer.cart.add') }}" method="POST" id="addToCartForm">
            @csrf
            <input type="hidden" name="item_id" value="{{ isset($item) ? $item->id : '' }}">
            <input type="hidden" name="quantity" id="quantityInput" value="1">

            <div class="grid gap-5 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)] lg:gap-8 lg:items-start">

                {{-- ============ LEFT: IMAGE ============ --}}
                <section class="card-surface overflow-hidden lg:sticky lg:top-32">
                    <div class="aspect-[4/3] w-full overflow-hidden bg-peach-soft/50 sm:aspect-[16/10] lg:aspect-[4/3]">
                        @if(isset($item) && $item->image)
                        <img src="{{ asset($item->image) }}" alt="{{ $item->name }}" class="h-full w-full object-cover">
                        @else
                        <div class="grid h-full w-full place-items-center text-5xl text-peach/50"><i class="bi bi-image"></i></div>
                        @endif
                    </div>
                </section>

                {{-- ============ RIGHT: DETAILS ============ --}}
                <div class="grid gap-5">

                    {{-- Item info --}}
                    <section class="card-surface p-4 sm:p-6">
                        <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3">
                            <h1 class="min-w-0 font-display text-2xl font-black leading-tight tracking-tight text-peach-deep sm:text-3xl">
                                {{ isset($item) ? $item->name : 'Item Name' }}
                            </h1>
                            <span class="shrink-0 rounded-full bg-peach-soft px-3 py-1.5 font-display text-base font-black text-peach-red sm:text-lg">
                                ₱{{ isset($item) ? number_format($item->price, 2) : '0.00' }}
                            </span>
                        </div>
                        <p class="mt-3 text-sm leading-relaxed text-peach-deep/60">
                            {{ isset($item) ? $item->description : 'Item description here.' }}
                        </p>

                        @if($itemOutOfStock)
                        <div class="mt-4 flex items-start gap-2 rounded-xl border border-peach-soft bg-peach-soft/40 px-3 py-2.5">
                            <i class="bi bi-x-circle-fill mt-0.5 shrink-0 text-peach-red"></i>
                            <p class="text-xs font-semibold text-peach-deep">
                                @if($itemMissingRecipe)
                                <span class="font-black uppercase tracking-wide text-peach-red">Unavailable — No Recipe Set</span><br>
                                This item cannot be ordered yet because the kitchen has no recipe
                                set for it. Please check back later.
                                @else
                                <span class="font-black uppercase tracking-wide text-peach-red">Out of Stock</span><br>
                                This item is temporarily unavailable because one or more of its
                                ingredients is out of stock. Please check back later.
                                @endif
                            </p>
                        </div>
                        @endif
                    </section>

                    {{-- Customize --}}
                    @if(isset($item) && $item->options->count() > 0)
                    <section class="card-surface p-4 sm:p-6">
                        <div class="mb-4 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3">
                            <div class="min-w-0">
                                <h2 class="font-display text-lg font-bold text-peach-deep">Customize</h2>
                                <p class="text-xs text-peach-deep/50">Optional add-ons for your order.</p>
                            </div>
                            <span class="shrink-0 rounded-full border border-peach-soft px-3 py-1 text-[0.68rem] font-bold text-peach-red">Optional</span>
                        </div>

                        <div class="grid gap-2.5 sm:grid-cols-2">
                            @foreach($item->options as $option)
                            <div class="min-w-0">
                                <input type="checkbox" name="options[]" value="{{ $option->id }}" id="option{{ $option->id }}"
                                    class="option-input sr-only" data-price="{{ $option->additional_price }}">
                                <label for="option{{ $option->id }}" class="option-chip option-chip-hover option-chip-label">
                                    <span class="option-tick grid h-5 w-5 shrink-0 place-items-center rounded-md border border-peach-soft bg-peach-cream text-[0.65rem] transition">
                                        <i class="bi bi-check-lg opacity-0 transition"></i>
                                    </span>
                                    <span class="min-w-0 flex-1 truncate text-sm font-semibold text-peach-deep">{{ $option->name }}</span>
                                    @if($option->additional_price > 0)
                                    <span class="shrink-0 text-xs font-bold text-peach-red">+₱{{ number_format($option->additional_price, 0) }}</span>
                                    @else
                                    <span class="shrink-0 text-xs font-bold text-green-600">Free</span>
                                    @endif
                                </label>
                            </div>
                            @endforeach
                        </div>
                    </section>
                    @endif

                    {{-- Desktop actions + running total --}}
                    <section class="card-surface hidden p-4 sm:p-6 md:block">
                        <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-4">
                            <div class="min-w-0">
                                <p class="text-[0.68rem] font-bold uppercase tracking-[0.16em] text-peach-deep/45">Total</p>
                                <p class="font-display text-2xl font-black text-peach-deep" id="totalPriceDesktop">
                                    ₱{{ isset($item) ? number_format($item->price, 2) : '0.00' }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2.5">
                                <div class="flex items-center rounded-full border border-peach-soft bg-white p-1">
                                    <button type="button" onclick="changeQuantity(-1)" class="grid h-9 w-9 place-items-center rounded-full text-lg font-bold text-peach-red transition hover:bg-peach-soft" aria-label="Decrease quantity">−</button>
                                    <input type="number" id="quantityDesktop" inputmode="numeric" min="1" max="99" value="1"
                                        aria-label="Quantity"
                                        class="qty-input w-10 rounded-full border-0 bg-transparent px-1 py-1 text-center text-sm font-bold text-peach-deep outline-none focus:bg-peach-soft/60">
                                    <button type="button" onclick="changeQuantity(1)" class="grid h-9 w-9 place-items-center rounded-full text-lg font-bold text-peach-red transition hover:bg-peach-soft" aria-label="Increase quantity">+</button>
                                </div>
                                <button type="submit" @disabled($itemOutOfStock)
                                    class="inline-flex items-center gap-2 rounded-full bg-peach-red px-6 py-3 text-sm font-bold text-white transition hover:bg-peach-deep disabled:cursor-not-allowed disabled:bg-peach-deep/30 disabled:hover:bg-peach-deep/30">
                                    <i class="bi {{ $itemOutOfStock ? 'bi-x-circle' : 'bi-cart-plus' }}"></i>
                                    {{ $itemOutOfStock ? $itemBlockedLabel : 'Add to cart' }}
                                </button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            {{-- ============ MOBILE STICKY ACTION BAR ============ --}}
            <div class="fixed inset-x-0 bottom-[3.75rem] z-40 border-t border-peach-soft bg-white/95 px-4 py-3 backdrop-blur md:hidden">
                <div class="mx-auto flex max-w-md items-center gap-3">
                    <div class="min-w-0">
                        <p class="text-[0.6rem] font-bold uppercase tracking-[0.14em] text-peach-deep/45">Total</p>
                        <p class="font-display text-lg font-black leading-tight text-peach-deep" id="totalPriceMobile">
                            ₱{{ isset($item) ? number_format($item->price, 2) : '0.00' }}
                        </p>
                    </div>
                    <div class="ml-auto flex shrink-0 items-center rounded-full border border-peach-soft bg-white p-1">
                        <button type="button" onclick="changeQuantity(-1)" class="grid h-9 w-9 place-items-center rounded-full text-lg font-bold text-peach-red transition hover:bg-peach-soft" aria-label="Decrease quantity">−</button>
                        <input type="number" id="quantityMobile" inputmode="numeric" min="1" max="99" value="1"
                            aria-label="Quantity"
                            class="qty-input w-9 rounded-full border-0 bg-transparent px-0.5 py-1 text-center text-sm font-bold text-peach-deep outline-none focus:bg-peach-soft/60">
                        <button type="button" onclick="changeQuantity(1)" class="grid h-9 w-9 place-items-center rounded-full text-lg font-bold text-peach-red transition hover:bg-peach-soft" aria-label="Increase quantity">+</button>
                    </div>
                    <button type="submit" @disabled($itemOutOfStock)
                        class="shrink-0 inline-flex items-center gap-2 rounded-full bg-peach-red px-5 py-3 text-sm font-bold text-white transition hover:bg-peach-deep disabled:cursor-not-allowed disabled:bg-peach-deep/30 disabled:hover:bg-peach-deep/30">
                        <i class="bi {{ $itemOutOfStock ? 'bi-x-circle' : 'bi-cart-plus' }}"></i>
                        {{ $itemOutOfStock ? $itemBlockedLabel : 'Add' }}
                    </button>
                </div>
            </div>
        </form>
    </main>

    {{-- ================= BOTTOM NAV (mobile) ================= --}}
    <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-peach-soft bg-white/95 backdrop-blur md:hidden">
        <div class="mx-auto grid max-w-md {{ $orderType === 'dine_in' ? 'grid-cols-4' : 'grid-cols-3' }}">
            <a href="{{ route('customer.orders') }}" class="flex flex-col items-center gap-1 py-2.5 text-[0.62rem] font-bold text-peach-deep/50 no-underline">
                <i class="bi bi-receipt text-lg"></i><span>Orders</span>
            </a>
            <a href="{{ route('customer.menu') }}" class="flex flex-col items-center gap-1 py-2.5 text-[0.62rem] font-bold text-peach-red no-underline">
                <i class="bi bi-grid text-lg"></i><span>Menu</span>
            </a>
            <a href="{{ route('customer.more') }}" class="flex flex-col items-center gap-1 py-2.5 text-[0.62rem] font-bold text-peach-deep/50 no-underline">
                <i class="bi bi-three-dots text-lg"></i><span>More</span>
            </a>
            @if($orderType === 'dine_in')
            <a href="#" class="flex flex-col items-center gap-1 py-2.5 text-[0.62rem] font-bold text-peach-deep/50 no-underline">
                <i class="bi bi-question-circle text-lg"></i>
                <span>Help</span>
            </a>
            @endif
        </div>
    </nav>

    <script>
        // Live total preview only — the server still computes the authoritative price.
        const BASE_PRICE = {{ isset($item) ? (float) $item->price : 0 }};

        function peso(n) {
            return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // Client ceiling only — the server still re-checks recipe + ingredient
        // stock on Add to cart and rejects an over-limit quantity there.
        const MAX_QTY = 99;
        let quantity = 1;

        const qtyHidden = document.getElementById('quantityInput');
        const qtyDesktop = document.getElementById('quantityDesktop');
        const qtyMobile = document.getElementById('quantityMobile');

        // Write the current quantity to every field that shows it.
        function renderQuantity() {
            if (qtyHidden) qtyHidden.value = quantity;
            if (qtyDesktop) qtyDesktop.value = quantity;
            if (qtyMobile) qtyMobile.value = quantity;
            updateTotal();
        }

        // Coerce whatever is on screen to a whole number in [1, MAX_QTY].
        // Invalid text, 0 or empty all fall back to 1.
        function normalizeQuantity(raw) {
            const n = parseInt(raw, 10);
            if (!Number.isFinite(n) || n < 1) return 1;
            if (n > MAX_QTY) return MAX_QTY;
            return n;
        }

        function changeQuantity(amount) {
            quantity = normalizeQuantity(quantity + amount);
            renderQuantity();
        }

        // Live typing: keep `quantity` and the running total in step, but do
        // NOT write back into the field being edited (that would fight the
        // caret). An empty field is left alone until blur.
        function onQuantityTyped(el) {
            if (el.value.trim() === '') return;
            quantity = normalizeQuantity(el.value);
            if (qtyHidden) qtyHidden.value = quantity;
            if (el !== qtyDesktop && qtyDesktop) qtyDesktop.value = quantity;
            if (el !== qtyMobile && qtyMobile) qtyMobile.value = quantity;
            updateTotal();
        }

        // Leaving the field commits a valid value — anything invalid becomes 1.
        function onQuantityBlur(el) {
            quantity = normalizeQuantity(el.value);
            renderQuantity();
        }

        [qtyDesktop, qtyMobile].forEach(function (el) {
            if (!el) return;
            el.addEventListener('input', function () { onQuantityTyped(el); });
            el.addEventListener('blur', function () { onQuantityBlur(el); });
        });

        // Last line of defence: never submit a bad quantity.
        const addToCartForm = document.getElementById('addToCartForm');
        if (addToCartForm) {
            addToCartForm.addEventListener('submit', function () {
                quantity = normalizeQuantity(quantity);
                if (qtyHidden) qtyHidden.value = quantity;
            });
        }

        function updateTotal() {
            let unitTotal = BASE_PRICE;
            document.querySelectorAll('.option-input:checked').forEach(cb => {
                unitTotal += parseFloat(cb.dataset.price || 0) || 0;
            });
            const total = unitTotal * quantity;
            const text = peso(total);
            const d = document.getElementById('totalPriceDesktop');
            const m = document.getElementById('totalPriceMobile');
            if (d) d.textContent = text;
            if (m) m.textContent = text;
        }

        document.querySelectorAll('.option-input').forEach(cb => cb.addEventListener('change', updateTotal));
        updateTotal();

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
</body>

</html>

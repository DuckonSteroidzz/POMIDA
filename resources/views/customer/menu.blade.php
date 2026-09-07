<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Main Menu - Peachy</title>

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

        @utility no-scrollbar {
            scrollbar-width: none;
            -ms-overflow-style: none;
            &::-webkit-scrollbar { display: none; }
        }

        @utility card-surface {
            background-color: #fff;
            border: 1px solid var(--color-peach-soft);
            border-radius: 1rem;
            box-shadow: 0 1px 2px rgb(139 26 26 / 0.04), 0 8px 24px -18px rgb(139 26 26 / 0.35);
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

            {{-- Search — the input and the Cancel button share one height (h-11)
                 and matching horizontal padding so they align cleanly. --}}
            <div class="flex items-center gap-2 pb-3 sm:pb-4">
                <div class="relative min-w-0 flex-1">
                    <i class="bi bi-search pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-peach-red/50"></i>
                    <input type="text" aria-label="Search the menu" autocomplete="off" id="searchInput"
                        class="h-11 w-full rounded-full border border-peach-soft bg-white pl-10 pr-4 text-base outline-none transition placeholder:text-peach-deep/35 focus:border-peach focus:ring-4 focus:ring-peach/20">
                </div>
                <button type="button" onclick="clearSearch()"
                    class="inline-flex h-11 shrink-0 items-center rounded-full border border-peach-soft bg-white px-4 text-sm font-semibold text-peach-red transition hover:bg-peach-soft">
                    Cancel
                </button>
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-6xl px-4 pb-28 pt-4 sm:px-6 sm:pt-6 md:pb-16">

{{-- ================= FLASH MESSAGES (floating toast — no layout shift) ================= --}}
<div id="flashToast"
     class="pointer-events-none fixed inset-x-0 top-3 z-[100] flex flex-col items-center gap-2 px-4 sm:top-4">

    @if(session('success'))
    <div data-toast
         class="toast-bar pointer-events-auto flex w-full max-w-sm items-center gap-2 rounded-full border border-green-200/80 bg-green-50/95 px-3.5 py-2 text-xs font-medium text-green-700 shadow-sm backdrop-blur-sm">
        <i class="bi bi-check-circle text-sm text-green-600"></i>
        <span class="min-w-0 flex-1">{{ session('success') }}</span>
    </div>
    @endif

    @if($errors->any())
    <div data-toast
         class="toast-bar pointer-events-auto flex w-full max-w-sm items-center gap-2 rounded-full border border-peach-soft/70 bg-white/95 px-3.5 py-2 text-xs font-medium text-peach-deep shadow-sm backdrop-blur-sm">
        <i class="bi bi-exclamation-circle text-sm text-peach-red"></i>
        <span class="min-w-0 flex-1">{{ $errors->first() }}</span>
    </div>
    @endif

    {{-- Active order warning --}}
    @if(session('active_order_warning'))
    <div data-toast
         class="toast-bar pointer-events-auto flex w-full max-w-sm items-center gap-2 rounded-full border border-amber-200 bg-amber-50/95 px-3.5 py-2 text-xs font-medium text-amber-800 shadow-sm backdrop-blur-sm">
        <i class="bi bi-exclamation-triangle-fill text-sm text-amber-600"></i>
        <span class="min-w-0 flex-1">
            {{ session('active_order_message') }}
        </span>
    </div>
    @endif

</div>

        {{-- Branch Selector — pickup customers only --}}
        @php
        $orderType = session('order_type');
        $selectedBranchId = session('branch_id');
        @endphp

        @if($orderType !== 'dine_in')
        <section class="card-surface mb-5 p-4 sm:p-5">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                <form action="{{ route('customer.select-branch') }}" method="POST" class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-2.5">
                    @csrf
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red">
                        <i class="bi bi-building"></i>
                    </span>
                    <select name="branch_id" onchange="this.form.submit()"
                        class="w-full min-w-0 rounded-xl border border-peach-soft bg-peach-cream px-3 py-2.5 text-sm font-semibold text-peach-deep outline-none transition focus:border-peach focus:ring-4 focus:ring-peach/20">
                        <option value="">-- Select Pick-up Branch --</option>
                        @foreach(isset($branches) ? $branches : \App\Models\Branch::where('is_active',true)->orderBy('id')->get() as $branch)
                        <option value="{{ $branch->id }}" {{ $selectedBranchId == $branch->id ? 'selected' : '' }}>
                            {{ $branch->name }}{{ $branch->address ? ' — ' . $branch->address : '' }}
                        </option>
                        @endforeach
                    </select>
                </form>

                @if(!$selectedBranchId)
                <p class="flex items-center gap-2 text-xs font-bold text-peach-red sm:justify-end">
                    <i class="bi bi-exclamation-circle"></i> Select a pick-up branch to view the menu.
                </p>
                @else
                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold text-peach-deep/70 sm:justify-end">
                    <i class="bi bi-check-circle text-green-600"></i>
                    Pick-up at: <strong>{{ \App\Models\Branch::find($selectedBranchId)?->name }}</strong>
                </p>
                @endif
            </div>
        </section>
        @endif

        {{-- Dine-in banner --}}
        @if($orderType === 'dine_in' && $selectedBranchId)
        <section class="card-surface mb-5 p-4 sm:p-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

                <div class="flex min-w-0 items-center gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red">
                        <i class="bi bi-shop"></i>
                    </span>

                    <p class="min-w-0 text-sm font-bold text-peach-deep">
                        Dine-in • Table {{ session('table_number') }} •
                        {{ \App\Models\Branch::find($selectedBranchId)?->name }}
                    </p>
                </div>

                <p class="flex shrink-0 items-center gap-2 text-xs font-semibold text-peach-deep/70 sm:justify-end">
                    <i class="bi bi-check-circle text-green-600"></i>
                    Dine-in at:
                    <strong class="text-peach-deep">
                        {{ \App\Models\Branch::find($selectedBranchId)?->name }}
                    </strong>
                </p>

            </div>
        </section>
@endif
        @if(isset($items))
        {{-- ================= ITEMS LIST VIEW ================= --}}
        <section>
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <h1 class="w-full min-w-0 truncate font-display text-2xl font-black tracking-tight text-peach-deep sm:w-auto sm:flex-1 sm:text-3xl">
                    {{ $category->name ?? 'Items' }}
                </h1>

                <div class="flex w-full items-center justify-end gap-2 sm:w-auto">
                    <span class="inline-flex h-10 shrink-0 items-center rounded-full bg-peach-soft px-3 text-xs font-bold text-peach-red">
                        {{ count($items) }} {{ count($items) === 1 ? 'item' : 'items' }}
                    </span>

                    <a href="{{ route('customer.menu') }}"
                        class="inline-flex h-10 shrink-0 items-center gap-2 rounded-full border border-peach-soft bg-white px-4 text-sm font-semibold text-peach-red no-underline transition hover:bg-peach-soft">
                        <i class="bi bi-arrow-left"></i> Back to Categories
                    </a>
                </div>
            </div>

            {{-- Subcategory Tabs --}}
            @if(isset($subcategories) && $subcategories->count() > 0)
            <div class="no-scrollbar -mx-4 mb-5 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0">
                <button class="subcategory-tab is-active shrink-0 whitespace-nowrap rounded-full border-2 border-peach-red bg-peach-red px-4 py-2 text-xs font-bold text-white transition"
                    onclick="filterSubcategory(this, 'all')">All</button>
                @foreach($subcategories as $sub)
                <button class="subcategory-tab shrink-0 whitespace-nowrap rounded-full border-2 border-peach-soft bg-white px-4 py-2 text-xs font-bold text-peach-red transition hover:border-peach"
                    onclick="filterSubcategory(this, '{{ $sub->id }}')">{{ $sub->name }}</button>
                @endforeach
            </div>
            @endif

            {{-- Items Grid --}}
            @if(count($items) > 0)
            <div id="itemsGrid" class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                @foreach($items as $item)
                @php
                    $itemMissingRecipe = $item->isMissingRecipe();
                    $itemOutOfStock = ! $itemMissingRecipe && ! $item->hasIngredientStock();
                    $itemUnavailable = $itemMissingRecipe || $itemOutOfStock;
                    $itemUnavailableLabel = $itemMissingRecipe ? 'No Recipe Set' : 'Out of Stock';
                @endphp
                <a href="{{ route('customer.item', $item->id) }}"
                    class="item-grid-card card-surface group relative flex flex-col overflow-hidden no-underline transition duration-200 hover:-translate-y-0.5 hover:border-peach"
                    data-subcategory="{{ $item->subcategory_id ?? 'none' }}"
                    data-name="{{ strtolower($item->name) }}">
                    <div class="relative aspect-[1/0.85] w-full overflow-hidden bg-peach-soft/50">
                        @if($item->image)
                        <img src="{{ asset($item->image) }}" alt="{{ $item->name }}" loading="lazy"
                            class="h-full w-full object-cover transition duration-300 group-hover:scale-105 {{ $itemUnavailable ? 'opacity-60 grayscale' : '' }}">
                        @else
                        <div class="grid h-full w-full place-items-center text-2xl text-peach/60"><i class="bi bi-image"></i></div>
                        @endif
                        @if($itemUnavailable)
                        <span class="pointer-events-none absolute inset-0 bg-white/45"></span>
                        <span class="absolute left-2 top-2 rounded-full bg-peach-deep/90 px-2.5 py-1 text-[0.62rem] font-black uppercase tracking-wide text-white shadow-sm">
                            {{ $itemUnavailableLabel }}
                        </span>
                        @endif
                    </div>
                    <div class="flex flex-1 flex-col gap-1 p-3">
                        <p class="line-clamp-2 text-sm font-bold leading-snug text-peach-deep">{{ $item->name }}</p>
                        <p class="mt-auto font-display text-base font-black text-peach-red">₱{{ number_format($item->price, 2) }}</p>
                        @if($itemMissingRecipe)
                        <p class="text-[0.66rem] font-bold text-peach-red">Unavailable — no recipe set</p>
                        @elseif($itemOutOfStock)
                        <p class="text-[0.66rem] font-bold text-peach-red">Currently unavailable — ingredients out of stock</p>
                        @endif
                    </div>
                </a>
                @endforeach
            </div>
            <p id="noResults" hidden class="card-surface mt-4 px-6 py-10 text-center text-sm font-semibold text-peach-deep/50">
                No items match your search.
            </p>
            @else
            <div class="card-surface px-6 py-12 text-center">
                <i class="bi bi-basket text-3xl text-peach/60"></i>
                <p class="mt-3 text-sm font-semibold text-peach-deep/60">No items available in this category yet.</p>
            </div>
            @endif
        </section>

        @else
        {{-- ================= CATEGORIES GRID VIEW ================= --}}
        <section>
            <h1 class="mb-1 font-display text-2xl font-black tracking-tight text-peach-deep sm:text-3xl">
                Menu Categories
            </h1>
            <p class="mb-5 text-base text-peach-deep/55">
                Freshly baked cakes, deli plates and café favourites.
            </p>

            @if($orderType !== 'dine_in' && !$selectedBranchId)
                <div class="card-surface px-6 py-12 text-center">
                    <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-peach-soft text-2xl text-peach-red">
                        <i class="bi bi-geo-alt"></i>
                    </span>
                    <p class="mt-4 font-display text-lg font-bold text-peach-deep">Select a Branch First</p>
                    <p class="mx-auto mt-1 max-w-sm text-sm text-peach-deep/55">
                        Please select your pick-up branch above to see available menu items.
                    </p>
                </div>
            @else
                <div id="categoryView">
                    <div id="categoryGrid" class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                        @if(isset($categories) && count($categories) > 0)
                            @foreach($categories as $category)
                                @php
                                    $categoryItems = isset($menuItems)
                                        ? $menuItems->where('category_id', $category->id)
                                        : collect();

                                    $searchParts = [$category->name];

                                    foreach ($categoryItems as $categoryItem) {
                                        $searchParts[] = $categoryItem->name;

                                        if ($categoryItem->subcategory) {
                                            $searchParts[] = $categoryItem->subcategory->name;
                                        }
                                    }

                                    $categorySearchText = strtolower(implode(' ', $searchParts));
                                @endphp

                                <div class="category-card card-surface group relative cursor-pointer overflow-hidden transition duration-200 hover:-translate-y-0.5 hover:border-peach"
                                    data-category-name="{{ strtolower($category->name) }}"
                                    data-search="{{ $categorySearchText }}"
                                    onclick="window.location.href=`{{ route('customer.items', $category->id) }}`">

                                    <div class="aspect-[1/0.9] w-full overflow-hidden bg-peach-soft/50">
                                        @if($category->image)
                                            <img src="{{ asset($category->image) }}" alt="{{ $category->name }}" loading="lazy"
                                                class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
                                        @else
                                            <div class="grid h-full w-full place-items-center text-2xl text-peach/60">
                                                <i class="bi bi-image"></i>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-peach-deep/85 via-peach-deep/35 to-transparent px-3 pb-3 pt-10">
                                        <p class="font-display text-sm font-bold leading-tight text-white sm:text-base">
                                            {{ $category->name }}
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        @else
                            <div class="card-surface col-span-full px-6 py-12 text-center text-sm font-semibold text-peach-deep/55">
                                No categories available yet.
                            </div>
                        @endif
                    </div>

                    <p id="noResultsCat" hidden class="card-surface mt-4 px-6 py-10 text-center text-sm font-semibold text-peach-deep/50">
                        No categories or menu items match your search.
                    </p>
                </div>

                @if(isset($menuItems))
                    <div id="menuSearchResults" hidden class="mt-4">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h2 class="font-display text-lg font-bold text-peach-deep">Menu Items</h2>
                            <span id="searchResultCount" class="rounded-full bg-peach-soft px-3 py-1 text-xs font-bold text-peach-red">0 items</span>
                        </div>

                        <div id="menuSearchGrid" class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                            @foreach($menuItems as $item)
                                @php
                                    $itemSearchParts = [
                                        $item->name,
                                        $item->category?->name ?? '',
                                        $item->subcategory?->name ?? '',
                                        $item->description ?? ''
                                    ];
                                    $itemSearchText = strtolower(implode(' ', $itemSearchParts));
                                @endphp

                                @php
                                    $itemMissingRecipe = $item->isMissingRecipe();
                                    $itemOutOfStock = ! $itemMissingRecipe && ! $item->hasIngredientStock();
                                    $itemUnavailable = $itemMissingRecipe || $itemOutOfStock;
                                    $itemUnavailableLabel = $itemMissingRecipe ? 'No Recipe Set' : 'Out of Stock';
                                @endphp
                                <a href="{{ route('customer.item', $item->id) }}"
                                    class="menu-search-card card-surface group relative flex flex-col overflow-hidden no-underline transition duration-200 hover:-translate-y-0.5 hover:border-peach"
                                    data-search="{{ $itemSearchText }}">

                                    <div class="relative aspect-[1/0.85] w-full overflow-hidden bg-peach-soft/50">
                                        @if($item->image)
                                            <img src="{{ asset($item->image) }}" alt="{{ $item->name }}" loading="lazy"
                                                class="h-full w-full object-cover transition duration-300 group-hover:scale-105 {{ $itemUnavailable ? 'opacity-60 grayscale' : '' }}">
                                        @else
                                            <div class="grid h-full w-full place-items-center text-2xl text-peach/60">
                                                <i class="bi bi-image"></i>
                                            </div>
                                        @endif
                                        @if($itemUnavailable)
                                        <span class="pointer-events-none absolute inset-0 bg-white/45"></span>
                                        <span class="absolute left-2 top-2 rounded-full bg-peach-deep/90 px-2.5 py-1 text-[0.62rem] font-black uppercase tracking-wide text-white shadow-sm">
                                            {{ $itemUnavailableLabel }}
                                        </span>
                                        @endif
                                    </div>

                                    <div class="flex flex-1 flex-col gap-1 p-3">
                                        <p class="line-clamp-2 text-sm font-bold leading-snug text-peach-deep">
                                            {{ $item->name }}
                                        </p>

                                        @if($item->category)
                                            <p class="text-[0.68rem] text-peach-deep/45">
                                                {{ $item->category->name }}
                                                @if($item->subcategory)
                                                    • {{ $item->subcategory->name }}
                                                @endif
                                            </p>
                                        @endif

                                        <p class="mt-auto font-display text-base font-black text-peach-red">
                                            ₱{{ number_format($item->price, 2) }}
                                        </p>

                                        @if($itemMissingRecipe)
                                        <p class="text-[0.66rem] font-bold text-peach-red">Unavailable — no recipe set</p>
                                        @elseif($itemOutOfStock)
                                        <p class="text-[0.66rem] font-bold text-peach-red">Currently unavailable — ingredients out of stock</p>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>

                        <p id="noMenuItemResults" hidden class="card-surface mt-4 px-6 py-10 text-center text-sm font-semibold text-peach-deep/50">
                            No menu items match your search.
                        </p>
                    </div>
                @endif
            @endif
        </section>
        @endif
    </main>




    <script>
    function filterSubcategory(btn, subcatId) {
        document.querySelectorAll('.subcategory-tab').forEach(function (tab) {
            tab.classList.remove(
                'is-active',
                'bg-peach-red',
                'text-white',
                'border-peach-red'
            );

            tab.classList.add(
                'bg-white',
                'text-peach-red',
                'border-peach-soft'
            );
        });

        btn.classList.remove(
            'bg-white',
            'text-peach-red',
            'border-peach-soft'
        );

        btn.classList.add(
            'is-active',
            'bg-peach-red',
            'text-white',
            'border-peach-red'
        );

        document.querySelectorAll('.item-grid-card').forEach(function (card) {
            card.dataset.subFiltered =
                (subcatId === 'all' ||
                 card.dataset.subcategory === subcatId)
                    ? 'no'
                    : 'yes';
        });

        applyVisibility();
    }

    function applyVisibility() {
        var input = document.getElementById('searchInput');

        if (!input) {
            return;
        }

        var term = input.value.trim().toLowerCase();

        var categoryGrid = document.getElementById('categoryGrid');
        var categoryCards = document.querySelectorAll('.category-card');
        var noResultsCat = document.getElementById('noResultsCat');

        var menuSearchResults =
            document.getElementById('menuSearchResults');

        var menuSearchCards =
            document.querySelectorAll('.menu-search-card');

        var noMenuItemResults =
            document.getElementById('noMenuItemResults');

        var searchResultCount =
            document.getElementById('searchResultCount');

        if (term === '') {
            categoryCards.forEach(function (card) {
                card.hidden = false;
            });

            menuSearchCards.forEach(function (card) {
                card.hidden = false;
            });

            if (categoryGrid) {
                categoryGrid.hidden = false;
            }

            if (menuSearchResults) {
                menuSearchResults.hidden = true;
            }

            if (noResultsCat) {
                noResultsCat.hidden = true;
            }

            if (noMenuItemResults) {
                noMenuItemResults.hidden = true;
            }

            document.querySelectorAll('.item-grid-card').forEach(function (card) {
                card.hidden = card.dataset.subFiltered === 'yes';
            });

            return;
        }

        var categoryMatches = 0;

        categoryCards.forEach(function (card) {
            var searchText =
                (card.dataset.search || '').toLowerCase();

            var matches =
                searchText.indexOf(term) !== -1;

            card.hidden = !matches;

            if (matches) {
                categoryMatches++;
            }
        });

        var menuItemMatches = 0;

        menuSearchCards.forEach(function (card) {
            var searchText =
                (card.dataset.search || '').toLowerCase();

            var matches =
                searchText.indexOf(term) !== -1;

            card.hidden = !matches;

            if (matches) {
                menuItemMatches++;
            }
        });

        if (categoryGrid) {
            categoryGrid.hidden = categoryMatches === 0;
        }

        if (menuSearchResults) {
            menuSearchResults.hidden = menuItemMatches === 0;
        }

        if (searchResultCount) {
            searchResultCount.textContent =
                menuItemMatches +
                (menuItemMatches === 1 ? ' item' : ' items');
        }

        if (noMenuItemResults) {
            noMenuItemResults.hidden = menuItemMatches !== 0;
        }

        if (noResultsCat) {
            noResultsCat.hidden =
                categoryMatches !== 0 ||
                menuItemMatches !== 0;
        }
    }

    function clearSearch() {
        var input = document.getElementById('searchInput');

        if (input) {
            input.value = '';
            input.focus();
        }

        applyVisibility();
    }

    function clearBranch() {
        if (!confirm('Changing branch will clear your cart. Continue?')) {
            return;
        }

        fetch('{{ route("customer.select-branch") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({
                branch_id: ''
            })
        })
        .then(function () {
            window.location.reload();
        })
        .catch(function (error) {
            console.error('Error changing branch:', error);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var searchInput =
            document.getElementById('searchInput');

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                applyVisibility();
            });

            searchInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    applyVisibility();
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    clearSearch();
                }
            });
        }

        document.querySelectorAll('[data-toast]').forEach(function (toast, index) {
            setTimeout(function () {
                toast.style.transition =
                    'opacity .4s ease, transform .4s ease';

                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-6px)';

                setTimeout(function () {
                    toast.remove();
                }, 400);
            }, 4000 + (index * 600));
        });

        applyVisibility();
    });
</script>

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
        <div style="display:flex;gap:8px;margin-top:16px;">
            <button type="button" onclick="closeOrderStatusNotice()"
                    style="flex:1;height:36px;border-radius:999px;background:#2E7D5B;color:#fff;border:1px solid #2E7D5B;font-size:10px;font-weight:700;cursor:pointer;">
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

    window.closeOrderStatusNotice = function () {
        const overlay = document.getElementById('orderStatusNotice');
        if (overlay) overlay.style.display = 'none';
    };

    function showOrderStatusNotice(status, orderNumber, orderId) {
        const overlay = document.getElementById('orderStatusNotice');
        const icon = document.getElementById('orderStatusIcon');
        const title = document.getElementById('orderStatusTitle');
        const message = document.getElementById('orderStatusMessage');

        if (!overlay) return;

        if (status === 'completed') {
            icon.style.background = '#E4F2EA';
            icon.style.color = '#2E7D5B';
            icon.innerHTML = '<i class="bi bi-check-circle"></i>';
            title.style.color = '#2E7D5B';
            title.textContent = 'Order Completed';
            message.textContent = 'Order ' + (orderNumber ? '#' + orderNumber + ' ' : '') +
                'has been completed. Thank you for ordering from Peachy!';
        } else if (status === 'cancelled') {
            icon.style.background = '#FDE8DE';
            icon.style.color = '#C0392B';
            icon.innerHTML = '<i class="bi bi-x-circle"></i>';
            title.style.color = '#C0392B';
            title.textContent = 'Order Cancelled';
            message.textContent = 'Order ' + (orderNumber ? '#' + orderNumber + ' ' : '') +
                'has been cancelled by staff.';
        } else {
            return;
        }

        if (orderId) markNotified(orderId, status);
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    async function pollCustomerOrderStatus() {
        if (requestInProgress) return;
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

    // Check immediately, then every 3 seconds. This works even if the
    // order was completed/cancelled before this page was opened.
    pollCustomerOrderStatus();
    statusPollTimer = setInterval(pollCustomerOrderStatus, 3000);

    window.addEventListener('beforeunload', function () {
        if (statusPollTimer) clearInterval(statusPollTimer);
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

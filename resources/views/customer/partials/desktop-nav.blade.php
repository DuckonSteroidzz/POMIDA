{{--
    Shared customer header actions: desktop nav links, the notification bell,
    and the mobile cart shortcut — all in ONE flex wrapper.

    WHY ONE WRAPPER (load-bearing): the header's outer container is a 2-column
    CSS grid (grid-cols-[minmax(0,1fr)_auto]) — logo in column one, this whole
    cluster in column two. If the <nav>, the bell, and the mobile cart icon are
    separate direct children of that grid, auto-placement wraps the extras onto
    an implicit second row and the bell lands alone on its own line. Keeping
    them inside this wrapper is the fix. See notification-bell.blade.php.

    Every customer page with the standard header includes this partial, so the
    nav is identical everywhere and the active state is derived from the current
    route rather than hand-set per page.
--}}
@php
    $desktopCartCount = collect(session('cart', []))->sum('quantity');

    // Inactive: muted dark text, transparent background, soft hover.
    // Active: light peach pill + dark red text — lightweight enough not to
    // fight the solid Cart button next to it, and matches the mobile nav.
    // Section-aware (App\Support\CustomerNav), so a category, a receipt or
    // Vouchers & Rewards lights up the tab it belongs to.
    $navLink = fn (string $section) => \App\Support\CustomerNav::isActive($section)
        ? 'bg-peach-soft text-peach-red'
        : 'text-peach-deep/70 hover:bg-peach-soft hover:text-peach-red';
@endphp

<div class="flex items-center justify-end gap-3 md:gap-1 lg:gap-4">

    {{-- Tighter padding/gap/text between md and lg only: at exactly 768px the
         logo column (minmax(0,1fr), free to shrink to 0) was losing a width
         fight against this nav's fixed footprint and truncating "Peachy" to
         "Pea…". Full size returns at lg where there's room for both. --}}
    <nav class="hidden items-center gap-1 md:flex">
        <a href="{{ route('customer.menu') }}"
           class="rounded-full px-2 py-2 text-[1rem] font-semibold no-underline transition lg:px-3 lg:text-[1rem] {{ $navLink('menu') }}">
            <i class="bi bi-grid"></i> Menu
        </a>

        <a href="{{ route('customer.orders') }}"
           class="rounded-full px-2 py-2 text-[1rem] font-semibold no-underline transition lg:px-3 lg:text-[1rem] {{ $navLink('orders') }}">
            <i class="bi bi-receipt"></i> Orders
        </a>

        <a href="{{ route('customer.game') }}"
           class="rounded-full px-2 py-2 text-[1rem] font-semibold no-underline transition lg:px-3 lg:text-[1rem] {{ $navLink('game') }}">
            <i class="bi bi-dice-5"></i> Spin &amp; Win
        </a>

        <a href="{{ route('customer.more') }}"
           class="rounded-full px-2 py-2 text-[1rem] font-semibold no-underline transition lg:px-3 lg:text-[1rem] {{ $navLink('more') }}">
            <i class="bi bi-three-dots"></i> More
        </a>

        {{-- Cart: an action button, visually separated from the links above. --}}
        <a href="{{ route('customer.cart') }}"
           class="relative ml-1 inline-flex items-center gap-2 rounded-full bg-peach-red px-3 py-2 text-[1rem] font-bold text-white no-underline transition hover:bg-peach-deep lg:ml-2 lg:px-4 lg:text-[1rem]">
            <i class="bi bi-cart"></i> Cart
            @if($desktopCartCount > 0)
                <span class="grid h-5 min-w-5 place-items-center rounded-full bg-white px-1 text-[0.8125rem] font-black text-peach-red">
                    {{ $desktopCartCount }}
                </span>
            @endif
        </a>
    </nav>

    {{-- Notifications — must stay inside this wrapper, beside the nav. --}}
    @include('customer.partials.notification-bell')

    {{-- Mobile cart shortcut (the nav's Cart button is hidden below md). --}}
    <a href="{{ route('customer.cart') }}"
       class="relative grid h-11 w-11 shrink-0 place-items-center rounded-full bg-peach-red text-white no-underline md:hidden">
        <i class="bi bi-cart text-lg"></i>
        @if($desktopCartCount > 0)
            <span class="absolute -right-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full border-2 border-peach-cream bg-peach-deep px-1 text-[0.8125rem] font-black text-white">
                {{ $desktopCartCount }}
            </span>
        @endif
    </a>
</div>

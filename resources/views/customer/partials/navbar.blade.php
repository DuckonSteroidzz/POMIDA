{{-- Shared customer navigation: mobile --}}
@php
    // Which tab this page belongs to — including pages that are not tabs
    // themselves (a category, an item, a receipt, Vouchers, Account, Help).
    // See App\Support\CustomerNav.
    $navActive = fn (string $section) => \App\Support\CustomerNav::isActive($section);
@endphp

<nav
    class="fixed inset-x-0 bottom-0 z-40 border-t border-peach-soft bg-white/95 shadow-[0_-4px_20px_rgba(139,26,26,0.06)] backdrop-blur-md md:hidden"
    style="padding-bottom: env(safe-area-inset-bottom);"
    aria-label="Customer navigation"
>
    <div class="mx-auto flex w-full max-w-md">

        {{-- Menu --}}
        <a
            href="{{ route('customer.menu') }}"
            @if($navActive('menu')) aria-current="page" @endif
            class="flex min-w-0 flex-1 flex-col items-center justify-center gap-1 py-2 text-sm font-bold no-underline transition
                {{ $navActive('menu')
                    ? 'bg-peach-soft text-peach-red'
                    : 'text-peach-deep/50 hover:bg-peach-soft hover:text-peach-red' }}"
        >
            <i class="bi bi-grid text-lg leading-none"></i>
            <span>Menu</span>
        </a>

        {{-- Orders --}}
        <a
            href="{{ route('customer.orders') }}"
            @if($navActive('orders')) aria-current="page" @endif
            class="flex min-w-0 flex-1 flex-col items-center justify-center gap-1 py-2 text-sm font-bold no-underline transition
                {{ $navActive('orders')
                    ? 'bg-peach-soft text-peach-red'
                    : 'text-peach-deep/50 hover:bg-peach-soft hover:text-peach-red' }}"
        >
            <i class="bi bi-receipt text-lg leading-none"></i>
            <span>Orders</span>
        </a>

        {{-- Spin & Win --}}
        <a
            href="{{ route('customer.game') }}"
            @if($navActive('game')) aria-current="page" @endif
            class="flex min-w-0 flex-1 flex-col items-center justify-center gap-1 py-2 text-sm font-bold no-underline transition
                {{ $navActive('game')
                    ? 'bg-peach-soft text-peach-red'
                    : 'text-peach-deep/50 hover:bg-peach-soft hover:text-peach-red' }}"
        >
            <i class="bi bi-dice-5 text-lg leading-none"></i>
            <span>Spin &amp; Win</span>
        </a>

        {{-- More: available to Guest, Account, and Pickup --}}
        <a
            href="{{ route('customer.more') }}"
            @if($navActive('more')) aria-current="page" @endif
            class="flex min-w-0 flex-1 flex-col items-center justify-center gap-1 py-2 text-sm font-bold no-underline transition
                {{ $navActive('more')
                    ? 'bg-peach-soft text-peach-red'
                    : 'text-peach-deep/50 hover:bg-peach-soft hover:text-peach-red' }}"
        >
            <i class="bi bi-three-dots text-lg leading-none"></i>
            <span>More</span>
        </a>

    </div>
</nav>

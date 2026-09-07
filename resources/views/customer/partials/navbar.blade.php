{{-- Shared customer navigation: mobile --}}
@php
    $navCartCount = collect(session('cart', []))->sum('quantity');
    $navActive = fn (string $route) => request()->routeIs($route);
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
            class="flex min-w-0 flex-1 flex-col items-center justify-center gap-1 py-2 text-[0.7rem] font-bold no-underline transition
                {{ $navActive('customer.menu')
                    ? 'bg-peach-soft text-peach-red'
                    : 'text-peach-deep/50 hover:bg-peach-soft hover:text-peach-red' }}"
        >
            <i class="bi bi-grid text-lg leading-none"></i>
            <span>Menu</span>
        </a>

        {{-- Orders --}}
        <a
            href="{{ route('customer.orders') }}"
            class="flex min-w-0 flex-1 flex-col items-center justify-center gap-1 py-2 text-[0.7rem] font-bold no-underline transition
                {{ $navActive('customer.orders')
                    ? 'bg-peach-soft text-peach-red'
                    : 'text-peach-deep/50 hover:bg-peach-soft hover:text-peach-red' }}"
        >
            <i class="bi bi-receipt text-lg leading-none"></i>
            <span>Orders</span>
        </a>

        {{-- Spin & Win --}}
        <a
            href="{{ route('customer.game') }}"
            class="flex min-w-0 flex-1 flex-col items-center justify-center gap-1 py-2 text-[0.7rem] font-bold no-underline transition
                {{ $navActive('customer.game')
                    ? 'bg-peach-soft text-peach-red'
                    : 'text-peach-deep/50 hover:bg-peach-soft hover:text-peach-red' }}"
        >
            <i class="bi bi-dice-5 text-lg leading-none"></i>
            <span>Spin &amp; Win</span>
        </a>

        {{-- More: available to Guest, Account, and Pickup --}}
        <a
            href="{{ route('customer.more') }}"
            class="flex min-w-0 flex-1 flex-col items-center justify-center gap-1 py-2 text-[0.7rem] font-bold no-underline transition
                {{ $navActive('customer.more')
                    ? 'bg-peach-soft text-peach-red'
                    : 'text-peach-deep/50 hover:bg-peach-soft hover:text-peach-red' }}"
        >
            <i class="bi bi-three-dots text-lg leading-none"></i>
            <span>More</span>
        </a>

        {{-- Cart --}}
        <a
            href="{{ route('customer.cart') }}"
            class="relative flex min-w-0 flex-1 flex-col items-center justify-center gap-1 py-2 text-[0.7rem] font-bold no-underline transition
                {{ $navActive('customer.cart')
                    ? 'bg-peach-soft text-peach-red'
                    : 'text-peach-deep/50 hover:bg-peach-soft hover:text-peach-red' }}"
        >
            <span class="relative inline-flex leading-none">
                <i class="bi bi-cart text-lg"></i>

                @if($navCartCount > 0)
                    <span class="absolute -right-2.5 -top-2 grid h-4 min-w-4 place-items-center rounded-full bg-peach-red px-1 text-[0.55rem] font-black leading-none text-white">
                        {{ $navCartCount }}
                    </span>
                @endif
            </span>

            <span>Cart</span>
        </a>

    </div>
</nav>

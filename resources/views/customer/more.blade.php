<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>More - Peachy</title>

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
                    linear-gradient(135deg, #F8D7B0 0%, #F6B49B 50%, #EF8585 100%);
                color: #3b2320;
            }
            h1, h2, h3, .font-display { font-family: var(--font-display); }
            a, button { font-family: inherit; }
            button:not(:disabled), [onclick] { cursor: pointer; }
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
    @php
        $isLoggedIn = Auth::guard('customer')->check();
        $customer = $isLoggedIn ? Auth::guard('customer')->user() : null;
        $isDineIn = session('order_type') === 'dine_in';
    @endphp

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

    <main class="mx-auto w-full max-w-2xl px-4 pb-28 pt-5 sm:px-6 sm:pt-7 md:pb-16">
        <div class="mb-5">
            <p class="mb-1 text-xs font-black uppercase tracking-[0.16em] text-peach-red/70">Peachy</p>
            <h1 class="font-display text-3xl font-black tracking-tight text-peach-deep">More</h1>
            <p class="mt-1 text-sm text-peach-deep/55">
                @if($isLoggedIn)
                    Manage your account and find useful information.
                @else
                    Sign in for more features or view our store information.
                @endif
            </p>
        </div>

        @if(!$isLoggedIn)
            {{-- Guest account access --}}
            <section class="card-surface overflow-hidden">
                <div class="bg-gradient-to-br from-peach-soft to-white p-5 sm:p-6">
                    <div class="flex items-start gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-white text-xl text-peach-red shadow-sm">
                            <i class="bi bi-person-circle"></i>
                        </span>
                        <div class="min-w-0">
                            <h2 class="font-display text-xl font-bold text-peach-deep">Get more from your orders</h2>
                            <p class="mt-1 text-sm leading-relaxed text-peach-deep/60">
                                Create an account or log in to keep your order history and earn voucher points.
                            </p>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-2.5 sm:grid-cols-2">
                        <a href="{{ route('customer.login') }}"
                           class="inline-flex items-center justify-center gap-2 rounded-full bg-peach-red px-5 py-3 text-sm font-bold text-white no-underline transition hover:bg-peach-deep">
                            <i class="bi bi-box-arrow-in-right"></i>
                            Log In
                        </a>
                        <a href="{{ route('customer.register') }}"
                           class="inline-flex items-center justify-center gap-2 rounded-full border border-peach-soft bg-white px-5 py-3 text-sm font-bold text-peach-red no-underline transition hover:bg-peach-soft">
                            <i class="bi bi-person-plus"></i>
                            Create Account
                        </a>
                    </div>

                    @if($isDineIn && session('table_number'))
                        <p class="mt-3 text-center text-xs font-semibold text-peach-deep/50">
                            <i class="bi bi-shop"></i>
                            You're ordering for Table {{ session('table_number') }}.
                        </p>
                    @endif
                </div>
            </section>
        @else
            {{-- Logged-in customer features --}}
            <section class="mb-4 grid gap-3 sm:grid-cols-2">
                <a href="{{ route('customer.account') }}" class="card-surface group flex items-center gap-3 p-4 no-underline transition hover:-translate-y-0.5 hover:border-peach">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red">
                        <i class="bi bi-person"></i>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-display font-bold text-peach-deep">My Account</span>
                        <span class="block text-xs text-peach-deep/50">Manage your details</span>
                    </span>
                    <i class="bi bi-chevron-right text-peach-red/50"></i>
                </a>

                {{--
                    ?tab=history, added 2026-09-02: this card promised
                    "previous orders" but landed on the Orders page's default
                    Current Order tab (only Pending/Preparing/Serving), not the
                    History tab (Completed/Cancelled) the card describes. The
                    Orders page reads this query param server-side to decide
                    which tab is initially active — see the $initialTab block
                    at the top of orders.blade.php.
                --}}
                <a href="{{ route('customer.orders', ['tab' => 'history']) }}" class="card-surface group flex items-center gap-3 p-4 no-underline transition hover:-translate-y-0.5 hover:border-peach">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red">
                        <i class="bi bi-receipt"></i>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-display font-bold text-peach-deep">Order History</span>
                        <span class="block text-xs text-peach-deep/50">View your previous orders</span>
                    </span>
                    <i class="bi bi-chevron-right text-peach-red/50"></i>
                </a>

                <a href="{{ route('customer.vouchers') }}" class="card-surface group flex items-center gap-3 p-4 no-underline transition hover:-translate-y-0.5 hover:border-peach">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red">
                        <i class="bi bi-ticket-perforated"></i>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-display font-bold text-peach-deep">Vouchers &amp; Points</span>
                        <span class="block text-xs text-peach-deep/50">{{ number_format((int)($customer->points ?? 0)) }} points</span>
                    </span>
                    <i class="bi bi-chevron-right text-peach-red/50"></i>
                </a>

                <form action="{{ route('customer.logout') }}" method="POST" class="contents">
                @csrf

                <button
                    type="submit"
                    class="card-surface group flex w-full items-center gap-3 p-4 text-left transition hover:-translate-y-0.5 hover:border-peach"
                >
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red">
                        <i class="bi bi-box-arrow-right"></i>
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block font-display font-bold text-peach-deep">
                            Log Out
                        </span>

                        <span class="block text-xs text-peach-deep/50">
                            Sign out of your account
                        </span>
                    </span>

                    <i class="bi bi-box-arrow-right text-peach-red/50"></i>
                </button>
            </form>

            </section>
        @endif

        {{-- Spin & Win: also surfaced here as a full-width card, so it stays
             one tap away for every customer type (dine-in, pick-up, guest)
             even alongside the bottom-bar slot. --}}
        <section class="mb-4">
            <a href="{{ route('customer.game') }}" class="card-surface group flex items-center gap-3 p-4 no-underline transition hover:-translate-y-0.5 hover:border-peach">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red">
                    <i class="bi bi-dice-5"></i>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block font-display font-bold text-peach-deep">Spin &amp; Win</span>
                    <span class="block text-xs text-peach-deep/50">Play while you wait</span>
                </span>
                <i class="bi bi-chevron-right text-peach-red/50"></i>
            </a>
        </section>

        @if($isDineIn)
            {{-- Need Assistance: Dine-In guests and accounts only --}}
            <section class="card-surface mt-4 overflow-hidden">
                <div class="border-b border-peach-soft bg-peach-soft/50 px-5 py-4 sm:px-6">
                    <div class="flex items-center gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white text-peach-red">
                            <i class="bi bi-person-raised-hand"></i>
                        </span>
                        <div class="min-w-0">
                            <h2 class="font-display text-lg font-bold text-peach-deep">Need Assistance?</h2>
                            <p class="text-xs text-peach-deep/50">Ask our staff for help with your dine-in order.</p>
                        </div>
                    </div>
                </div>

                <div class="p-5 sm:p-6">
                    <button
                        type="button"
                        onclick="openHelpModal()"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-peach-red px-5 py-3 text-sm font-bold text-white transition hover:bg-peach-deep"
                    >
                        <i class="bi bi-bell"></i>
                        Request Assistance
                    </button>
                </div>
            </section>
        @endif

        {{-- Store information --}}
        <section class="card-surface mt-4 overflow-hidden">
            <div class="border-b border-peach-soft bg-peach-soft/50 px-5 py-4 sm:px-6">
                <div class="flex items-center gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white text-peach-red">
                        <i class="bi bi-shop"></i>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-display text-lg font-bold text-peach-deep">Store Information</h2>
                        <p class="text-xs text-peach-deep/50">Contact {{ $storeInfo['business_name'] ?? 'Peachy Cakes & Deli Cafe' }}.</p>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 p-5 sm:p-6">
                <div class="flex items-start gap-3 rounded-xl border border-peach-soft bg-peach-cream p-3">
                    <i class="bi bi-geo-alt mt-0.5 text-peach-red"></i>
                    <div class="min-w-0">
                        <p class="text-[0.68rem] font-black uppercase tracking-[0.12em] text-peach-deep/45">Store</p>
                        <p class="mt-0.5 text-sm font-bold text-peach-deep">{{ $storeInfo['business_name'] ?? 'Peachy Cakes & Deli Cafe' }}</p>
                        @if(!empty($storeInfo['address']))
                            <p class="mt-0.5 text-xs text-peach-deep/55">{{ $storeInfo['address'] }}</p>
                        @endif
                    </div>
                </div>

                @if(!empty($storeInfo['contact_number']))
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $storeInfo['contact_number']) }}" class="flex items-center gap-3 rounded-xl border border-peach-soft bg-white p-3 no-underline transition hover:bg-peach-soft/40">
                    <i class="bi bi-telephone text-peach-red"></i>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[0.68rem] font-black uppercase tracking-[0.12em] text-peach-deep/45">Phone</span>
                        <span class="block text-sm font-bold text-peach-deep">{{ $storeInfo['contact_number'] }}</span>
                    </span>
                    <i class="bi bi-chevron-right text-peach-red/40"></i>
                </a>
                @endif

                @if(!empty($storeInfo['email']))
                <a href="mailto:{{ $storeInfo['email'] }}" class="flex items-center gap-3 rounded-xl border border-peach-soft bg-white p-3 no-underline transition hover:bg-peach-soft/40">
                    <i class="bi bi-envelope text-peach-red"></i>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[0.68rem] font-black uppercase tracking-[0.12em] text-peach-deep/45">Email</span>
                        <span class="block break-all text-sm font-bold text-peach-deep">{{ $storeInfo['email'] }}</span>
                    </span>
                    <i class="bi bi-chevron-right text-peach-red/40"></i>
                </a>
                @endif

                @php
                    $socials = [
                        ['url' => $storeInfo['facebook_url'] ?? '', 'label' => 'Facebook', 'icon' => 'bi-facebook'],
                        ['url' => $storeInfo['instagram_url'] ?? '', 'label' => 'Instagram', 'icon' => 'bi-instagram'],
                        ['url' => $storeInfo['tiktok_url'] ?? '', 'label' => 'TikTok', 'icon' => 'bi-tiktok'],
                        ['url' => $storeInfo['other_social_url'] ?? '', 'label' => 'Other', 'icon' => 'bi-share'],
                    ];
                    $socials = array_values(array_filter($socials, fn($social) => !empty($social['url'])));
                @endphp

                @if(count($socials))
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach($socials as $social)
                            <a href="{{ $social['url'] }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-3 rounded-xl border border-peach-soft bg-white p-3 no-underline transition hover:bg-peach-soft/40">
                                <i class="bi {{ $social['icon'] }} text-peach-red"></i>
                                <span class="min-w-0 flex-1 text-sm font-bold text-peach-deep">{{ $social['label'] }}</span>
                                <i class="bi bi-box-arrow-up-right text-peach-red/40"></i>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    
        @if($isDineIn)
            <div id="helpModal"
                 class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/50 p-4"
                 aria-hidden="true">
                <div class="w-full max-w-md rounded-3xl bg-white p-5 shadow-2xl sm:p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="font-display text-xl font-black text-peach-deep">Need Assistance</h2>
                            <p class="mt-1 text-sm text-peach-deep/55">Let our staff know what you need.</p>
                        </div>
                        <button type="button"
                                onclick="closeHelpModal()"
                                class="grid h-9 w-9 place-items-center rounded-full bg-peach-soft text-peach-red">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <form action="{{ route('customer.help-request') }}" method="POST" class="mt-5">
                        @csrf
                        <div class="grid gap-2.5">
                            <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-peach-soft p-3 hover:bg-peach-soft/40">
                                <input type="radio" name="message" value="I need help with my order" required>
                                <span class="text-sm font-semibold text-peach-deep">Help with my order</span>
                            </label>
                            <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-peach-soft p-3 hover:bg-peach-soft/40">
                                <input type="radio" name="message" value="I need payment assistance">
                                <span class="text-sm font-semibold text-peach-deep">Payment assistance</span>
                            </label>
                            <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-peach-soft p-3 hover:bg-peach-soft/40">
                                <input type="radio" name="message" value="I need staff assistance">
                                <span class="text-sm font-semibold text-peach-deep">I need staff assistance</span>
                            </label>
                        </div>

                        <button type="submit"
                                class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-full bg-peach-red px-5 py-3 text-sm font-bold text-white hover:bg-peach-deep">
                            <i class="bi bi-bell"></i>
                            Request Assistance
                        </button>
                    </form>
                </div>
            </div>
        @endif

    </main>

    @include('customer.partials.navbar')

    @if($isDineIn)
    <script>
        function openHelpModal() {
            const modal = document.getElementById('helpModal');
            if (!modal) return;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeHelpModal() {
            const modal = document.getElementById('helpModal');
            if (!modal) return;
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }
    </script>
    @endif

</body>
</html>

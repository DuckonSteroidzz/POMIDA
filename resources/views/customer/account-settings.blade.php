<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - Peachy</title>

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
            }
            h1, h2, h3, .font-display { font-family: var(--font-display); }
            select, input, button, a, label { font-family: inherit; }
            [hidden] { display: none !important; }
            button:not(:disabled), [onclick] { cursor: pointer; }
        }

        @utility card-surface {
            background-color: #fff;
            border: 1px solid var(--color-peach-soft);
            border-radius: 1rem;
            box-shadow: 0 1px 2px rgb(139 26 26 / 0.04), 0 8px 24px -18px rgb(139 26 26 / 0.35);
        }

        @utility field-input {
            width: 100%;
            border-radius: 0.75rem;
            border: 1px solid var(--color-peach-soft);
            background-color: var(--color-peach-cream);
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            color: #3b2320;
            outline: none;
            transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
        }
        @utility field-input-focus {
            border-color: var(--color-peach);
            background-color: #fff;
            box-shadow: 0 0 0 4px rgb(244 132 95 / 0.2);
        }
        .field-input::placeholder { color: rgb(139 26 26 / 0.35); }
        .field-input:focus {
            border-color: var(--color-peach);
            background-color: #fff;
            box-shadow: 0 0 0 4px rgb(244 132 95 / 0.2);
        }
    </style>
    @include('partials.session-guard')

    @include('customer.partials.readable-text')

</head>

<body class="min-h-screen bg-peach-cream">
    @php
        $customer = Auth::guard('customer')->user();
    @endphp

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

                {{-- Header actions: kept in ONE grid cell so the 2-column
                     header grid does not push the cart onto a second row.
                     Mirrors customer/partials/desktop-nav. --}}
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
                    @if(session('order_type') !== 'dine_in')
                    <a href="{{ route('customer.more') }}" class="rounded-full px-3 py-2 text-[0.95rem] font-semibold no-underline transition {{ request()->routeIs('customer.more') ? 'bg-peach-soft text-peach-red' : 'text-peach-deep/70 hover:bg-peach-soft hover:text-peach-red' }}">
                        <i class="bi bi-three-dots"></i> More
                    </a>
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

    <main class="mx-auto w-full max-w-6xl px-4 pb-28 pt-4 sm:px-6 sm:pt-6 md:pb-16">

        {{-- ================= FLASH MESSAGES ================= --}}
        <div id="flashToast" class="pointer-events-none fixed inset-x-0 top-3 z-[100] flex flex-col items-center gap-2 px-4 sm:top-4">
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

        {{-- ================= PAGE TITLE ================= --}}
        <div class="mb-5">
            <h1 class="font-display text-2xl font-black tracking-tight text-peach-deep sm:text-3xl">Account Settings</h1>
            <p class="mt-1 text-sm text-peach-deep/55">Manage your details, discount IDs and account preferences.</p>
        </div>

        <form action="{{ route('customer.account.update') }}" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="flex flex-col items-center gap-5">

                {{-- ---------- ACCOUNT DETAILS ---------- --}}
                <section class="card-surface w-full max-w-3xl p-4 sm:p-6">
                    <div class="mb-4 flex items-center gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-peach-soft text-peach-red">
                            <i class="bi bi-person"></i>
                        </span>
                        <div class="min-w-0">
                            <h2 class="font-display text-lg font-bold text-peach-deep">Account Details</h2>
                            <p class="text-xs text-peach-deep/50">Your personal and contact information.</p>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="acc-name" class="mb-1.5 block text-xs font-bold uppercase tracking-[0.12em] text-peach-deep/60">Name</label>
                            <input id="acc-name" class="field-input" type="text" name="name" value="{{ $customer?->name ?? '' }}" autocomplete="name">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="acc-address" class="mb-1.5 block text-xs font-bold uppercase tracking-[0.12em] text-peach-deep/60">Address</label>
                            <input id="acc-address" class="field-input" type="text" name="address" value="{{ $customer?->address ?? '' }}" autocomplete="street-address">
                        </div>
                        <div>
                            <label for="acc-contact" class="mb-1.5 block text-xs font-bold uppercase tracking-[0.12em] text-peach-deep/60">Contact</label>
                            <input id="acc-contact" class="field-input" type="text" name="contact_number" value="{{ $customer?->contact_number ?? '' }}" inputmode="numeric" autocomplete="tel">
                        </div>
                        <div>
                            <label for="acc-email" class="mb-1.5 block text-xs font-bold uppercase tracking-[0.12em] text-peach-deep/60">Email Address</label>
                            <input id="acc-email" class="field-input" type="email" name="email" value="{{ $customer?->email ?? '' }}" autocomplete="email">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="acc-password" class="mb-1.5 block text-xs font-bold uppercase tracking-[0.12em] text-peach-deep/60">
                                Password
                                <span class="ml-1 font-medium normal-case tracking-normal text-peach-deep/40">(leave blank to keep current)</span>
                            </label>
                            <input id="acc-password" class="field-input" type="password" name="password" autocomplete="new-password">
                        </div>
                    </div>
                </section>

                {{-- Discount IDs are intentionally not stored in the customer account. --}}


                {{-- ---------- ACTIONS ---------- --}}
                <div class="w-full max-w-3xl">
                    <div class="card-surface flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                        <p class="text-xs text-peach-deep/55">
                            Review your changes before saving. Deleting your account is permanent.
                        </p>
                        <div class="flex flex-col gap-2 sm:flex-row sm:shrink-0">
                            <button type="button" onclick="confirmDelete()"
                                class="inline-flex items-center justify-center gap-2 rounded-full border border-peach-red/30 bg-white px-5 py-2.5 text-sm font-bold text-peach-red transition hover:bg-peach-soft">
                                <i class="bi bi-trash"></i> Delete Account
                            </button>
                            <button type="submit"
                                class="inline-flex items-center justify-center gap-2 rounded-full bg-peach-red px-6 py-2.5 text-sm font-bold text-white transition hover:bg-peach-deep">
                                <i class="bi bi-check2"></i> Save Changes
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    @include('customer.partials.navbar')

    <script>
        function confirmDelete() {
            if (confirm('Are you sure you want to delete your account? This cannot be undone.')) {
                fetch('{{ route("customer.account.delete") }}', {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                }).then(() => {
                    window.location.href = '{{ route("customer.login") }}';
                });
            }
        }
// Auto-dismiss flash toasts
        setTimeout(function() {
            document.querySelectorAll('[data-toast]').forEach(function(el) {
                el.style.transition = 'opacity .4s ease';
                el.style.opacity = '0';
                setTimeout(function() { el.remove(); }, 400);
            });
        }, 4000);
    </script>
</body>

</html>
